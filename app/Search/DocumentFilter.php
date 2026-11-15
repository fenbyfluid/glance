<?php

namespace App\Search;

/**
 * Restricts a FilterableSqliteEngine search to a set of documents, evaluated inside the index query.
 *
 * A document is allowed if it matches every restriction given. Directories and kinds are matched against the metadata
 * stored with each document (see FilterableSqliteEngine::META_DIRECTORY_ID / META_KIND), so only indexes that store it
 * can use them.
 */
final readonly class DocumentFilter
{
    /**
     * @param  list<int>|null  $documentIds  Allowed documents, or null for any.
     * @param  list<int>|null  $directoryIds  Directories whose documents are allowed, or null for any.
     * @param  list<string>|null  $kinds  Allowed document kinds, or null for any.
     */
    public function __construct(
        public ?array $documentIds = null,
        public ?array $directoryIds = null,
        public ?array $kinds = null,
    ) {}

    /**
     * Whether no document can possibly be allowed.
     */
    public function isEmpty(): bool
    {
        return ($this->documentIds !== null && count($this->documentIds) === 0)
            || ($this->directoryIds !== null && count($this->directoryIds) === 0)
            || ($this->kinds !== null && count($this->kinds) === 0);
    }

    public function needsMetadata(): bool
    {
        return $this->directoryIds !== null || $this->kinds !== null;
    }
}
