<?php

namespace App\Search;

use PDO;
use TeamTNT\TNTSearch\Engines\SqliteEngine;
use TeamTNT\TNTSearch\Support\Collection;

/**
 * TNTSearch SQLite engine that can restrict a search to a subset of the documents inside the index query itself.
 *
 * Upstream only supports filtering the result IDs after the search has run, but the per-keyword doclist reads are
 * capped at `maxDocs` (ordered by hit count across the whole index), so matches in a small subset of the documents
 * are routinely cut off before any post-filter sees them. Here the filter is loaded into temp tables on the index
 * connection and joined against the doclist, so the `maxDocs` cap applies within the allowed set instead.
 *
 * To avoid enumerating every allowed document for each search, documents can carry metadata (their directory and
 * kind) in the searchable array under the META_* keys. It is stored in a `document_meta` table in the index rather
 * than being indexed as text, and lets a filter be expressed as a much smaller set of directories.
 *
 * Document frequencies (and therefore IDF) still come from the global wordlist, so scores remain comparable.
 */
class FilterableSqliteEngine extends SqliteEngine
{
    public const META_DIRECTORY_ID = '__directory_id';

    public const META_KIND = '__kind';

    protected ?DocumentFilter $documentFilter = null;

    /** Whether the temp tables on the current index connection hold the current filter. */
    protected bool $documentFilterLoaded = false;

    /** Whether the metadata table is known to exist on the current index connection. */
    protected bool $metaTableEnsured = false;

    /**
     * Restrict subsequent searches, or pass null to remove the restriction.
     *
     * The engine instance is shared (Scout caches it), so callers must always clear the filter afterwards.
     */
    public function setDocumentFilter(?DocumentFilter $filter): void
    {
        $this->documentFilter = $filter;
        $this->documentFilterLoaded = false;
    }

    public function hasDocumentFilter(): bool
    {
        return $this->documentFilter !== null;
    }

    protected function resetIndexState()
    {
        parent::resetIndexState();

        // selectIndex() opens a fresh connection, which won't have our temp tables.
        $this->documentFilterLoaded = false;
        $this->metaTableEnsured = false;

        // The default (FULL) syncs to disk on every commit, and the Scout driver commits per document, which dominated
        // indexing time. In WAL mode NORMAL can't corrupt the index, a power loss can only lose the latest commits, and
        // the index can always be rebuilt.
        $this->index->exec('PRAGMA synchronous = NORMAL');
    }

    public function processDocument(Collection $row)
    {
        $document = $row->toArray();

        $hasMeta = array_key_exists(self::META_DIRECTORY_ID, $document) || array_key_exists(self::META_KIND, $document);
        $directoryId = $document[self::META_DIRECTORY_ID] ?? null;
        $kind = $document[self::META_KIND] ?? null;
        $row->forget(self::META_DIRECTORY_ID);
        $row->forget(self::META_KIND);

        parent::processDocument($row);

        if ($hasMeta) {
            $this->ensureMetaTable();
            $stmt = $this->index->prepare('INSERT OR REPLACE INTO document_meta (doc_id, directory_id, kind) VALUES (?, ?, ?)');
            $stmt->execute([$document[$this->getPrimaryKey()], $directoryId, $kind]);
        }
    }

    public function insert(array $document)
    {
        $this->writeTransaction(fn () => parent::insert($document));
    }

    public function update(int $id, array $document)
    {
        // Upstream deletes then inserts as separate writes, so this also stops a failure leaving the document missing.
        $this->writeTransaction(fn () => parent::update($id, $document));
    }

    public function delete(int $documentId)
    {
        $this->writeTransaction(function () use ($documentId) {
            parent::delete($documentId);

            $this->ensureMetaTable();
            $this->prepareAndExecuteStatement('DELETE FROM document_meta WHERE doc_id = :documentId;', [
                ['key' => ':documentId', 'value' => $documentId],
            ]);
        });
    }

    /**
     * Run index writes in a transaction that takes the write lock up front.
     *
     * Upstream reads then writes, either in a deferred transaction or as separate statements. When another connection
     * is writing, SQLite fails the upgrade from reading to writing immediately with "database is locked" rather than
     * waiting on the busy timeout (it would risk a deadlock), so concurrent queue workers routinely fail. Starting with
     * BEGIN IMMEDIATE makes them wait for each other instead.
     */
    protected function writeTransaction(callable $callback): mixed
    {
        if ($this->index->inTransaction()) {
            return $callback();
        }

        $this->index->exec('BEGIN IMMEDIATE');

        try {
            $result = $callback();
            $this->releaseStatements();
            $this->index->exec('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            $this->releaseStatements();
            if ($this->index->inTransaction()) {
                $this->index->exec('ROLLBACK');
            }

            throw $e;
        }
    }

    /**
     * Reset upstream's cached statements, so none is left part-way through its results.
     *
     * saveWordlist() reads a single value from its cached SELECT with fetchColumn(), which leaves the statement active.
     * An active statement keeps a read transaction open on the connection after the write commits, and while any
     * reader holds an old snapshot SQLite can checkpoint but never restart the WAL from the beginning. The connection
     * lives as long as the queue worker, so the WAL grew without bound (to tens of GB).
     */
    protected function releaseStatements(): void
    {
        foreach (['insertWordlistStmt', 'selectWordlistStmt', 'updateWordlistStmt', 'insertDoclistStmt'] as $statement) {
            if (isset($this->{$statement})) {
                $this->{$statement}->closeCursor();
            }
        }
    }

    protected function ensureMetaTable(): void
    {
        if ($this->metaTableEnsured) {
            return;
        }

        // Created on demand, so indexes built before this existed keep working (with no metadata until re-imported).
        $this->metaTableEnsured = true;
        $this->index->exec('CREATE TABLE IF NOT EXISTS document_meta (doc_id INTEGER PRIMARY KEY, directory_id INTEGER, kind TEXT)');
    }

    /**
     * Make sure the temp tables on the current index connection hold the filter, and return the SQL to apply it.
     *
     * @return array{string, string} The join clause and the condition (both to be appended).
     */
    protected function documentFilterSql(): array
    {
        $filter = $this->documentFilter;

        if (!$this->documentFilterLoaded) {
            $load = function (string $table, string $type, array $values) {
                $this->index->exec("CREATE TEMP TABLE IF NOT EXISTS $table (value $type PRIMARY KEY)");
                $this->index->exec("DELETE FROM temp.$table");

                if (count($values) > 0) {
                    // A single statement via json_each() is much faster than inserting rows one by one.
                    $stmt = $this->index->prepare("INSERT OR IGNORE INTO temp.$table (value) SELECT value FROM json_each(?)");
                    $stmt->execute([json_encode(array_values($values))]);
                }
            };

            $load('filter_documents', 'INTEGER', array_map('intval', $filter->documentIds ?? []));
            $load('filter_directories', 'INTEGER', array_map('intval', $filter->directoryIds ?? []));
            $load('filter_extra_documents', 'INTEGER', array_map('intval', $filter->extraDocumentIds));
            $load('filter_kinds', 'TEXT', array_map('strval', $filter->kinds ?? []));

            if ($filter->needsMetadata()) {
                $this->ensureMetaTable();
            }

            $this->documentFilterLoaded = true;
        }

        $join = '';
        if ($filter->needsMetadata()) {
            $join = ' INNER JOIN document_meta ON document_meta.doc_id = doclist.doc_id';
        }

        $condition = '';
        if ($filter->documentIds !== null) {
            $condition .= ' AND doclist.doc_id IN (SELECT value FROM temp.filter_documents)';
        }
        if ($filter->directoryIds !== null) {
            if (count($filter->extraDocumentIds) > 0) {
                $condition .= ' AND (document_meta.directory_id IN (SELECT value FROM temp.filter_directories)'
                    .' OR doclist.doc_id IN (SELECT value FROM temp.filter_extra_documents))';
            } else {
                $condition .= ' AND document_meta.directory_id IN (SELECT value FROM temp.filter_directories)';
            }
        }
        if ($filter->kinds !== null) {
            $condition .= ' AND document_meta.kind IN (SELECT value FROM temp.filter_kinds)';
        }

        return [$join, $condition];
    }

    public function getAllDocumentsForStrictKeyword(array $word, bool $noLimit)
    {
        if (!$this->hasDocumentFilter()) {
            return parent::getAllDocumentsForStrictKeyword($word, $noLimit);
        }

        [$join, $condition] = $this->documentFilterSql();

        $query = 'SELECT doclist.* FROM doclist'.$join
            .' WHERE doclist.term_id = :id'.$condition.' ORDER BY doclist.hit_count DESC';
        if (!$noLimit) {
            $query .= " LIMIT {$this->maxDocs}";
        }

        $stmtDoc = $this->index->prepare($query);
        $stmtDoc->bindValue(':id', $word[0]['id']);
        $stmtDoc->execute();

        return new Collection($stmtDoc->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getAllDocumentsForFuzzyKeyword(array $words, bool $noLimit)
    {
        if (!$this->hasDocumentFilter()) {
            return parent::getAllDocumentsForFuzzyKeyword($words, $noLimit);
        }

        [$join, $condition] = $this->documentFilterSql();

        $ids = array_map(fn ($word) => (int) $word['id'], $words);
        $bindingParams = implode(',', array_fill(0, count($ids), '?'));

        $query = 'SELECT doclist.* FROM doclist'.$join
            ." WHERE doclist.term_id IN ($bindingParams)".$condition.' ORDER BY CASE doclist.term_id';
        foreach ($ids as $order => $id) {
            $query .= ' WHEN '.$id.' THEN '.($order + 1);
        }
        $query .= ' END';

        if (!$noLimit) {
            $query .= " LIMIT {$this->maxDocs}";
        }

        $stmtDoc = $this->index->prepare($query);
        $stmtDoc->execute($ids);

        return new Collection($stmtDoc->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getAllDocumentsForWhereKeywordNot(string $keyword, bool $noLimit = false)
    {
        if (!$this->hasDocumentFilter()) {
            return parent::getAllDocumentsForWhereKeywordNot($keyword, $noLimit);
        }

        $word = $this->getWordlistByKeyword($keyword);
        if (!isset($word[0])) {
            return new Collection([]);
        }

        [$join, $condition] = $this->documentFilterSql();

        $query = 'SELECT doclist.* FROM doclist'.$join
            .' WHERE doclist.doc_id NOT IN (SELECT doc_id FROM doclist WHERE term_id = :id)'.$condition
            .' GROUP BY doclist.doc_id ORDER BY doclist.hit_count DESC';
        if (!$noLimit) {
            $query .= " LIMIT {$this->maxDocs}";
        }

        $stmtDoc = $this->index->prepare($query);
        $stmtDoc->bindValue(':id', $word[0]['id']);
        $stmtDoc->execute();

        return new Collection($stmtDoc->fetchAll(PDO::FETCH_ASSOC));
    }
}
