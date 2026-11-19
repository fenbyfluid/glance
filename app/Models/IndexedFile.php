<?php

namespace App\Models;

use App\Enums\ExtractedTextStatus;
use App\Jobs\ExtractTextJob;
use App\Media\MediaContentKind;
use App\Media\MediaInfo;
use App\Media\OpenSubtitlesHasher;
use App\Search\FilterableSqliteEngine;
use App\Utilities\Path;
use App\Utilities\ScopeHavingAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Searchable;
use TeamTNT\TNTSearch\Tokenizer\NGramTokenizer;

class IndexedFile extends Model
{
    use ScopeHavingAccess, Searchable, SoftDeletes;

    protected $attributes = [
        'extracted_text_status' => ExtractedTextStatus::Pending->value,
    ];

    protected $fillable = [
        'directory_id',
        'name',
        'inode',
        'mtime',
        'size',
        'kind',
        'mime_type',
        'media_info',
        'fingerprint_id',
        'thumbnail',
        'filmstrip',
        'extracted_text_status',
    ];

    public function shouldBeSearchable(): true
    {
        // TODO: Exclude files with known generated names from search (e.g. Telegram, Tumblr)
        //       We probably need to store this as part of the record and set it during creation, as the directory relationship is unlikely to be populated.
        return true;
    }

    public function searchIndexShouldBeUpdated(): bool
    {
        // The directory and kind are stored in the index for filtering, see toSearchableArray().
        return $this->wasRecentlyCreated || $this->wasChanged(['name', 'directory_id', 'kind']) || !$this->shouldBeSearchable();
    }

    public function toSearchableArray(): array
    {
        $tokenizer = new NGramTokenizer(3, 3);

        return [
            'id' => $this->id,
            'name' => implode(' ', $tokenizer->tokenize($this->name)),
            // Stored alongside the index for filtering searches, see FilterableSqliteEngine.
            FilterableSqliteEngine::META_DIRECTORY_ID => $this->directory_id,
            FilterableSqliteEngine::META_KIND => $this->kind?->value,
        ];
    }

    public function syncWithSearchUsingQueue(): string
    {
        return 'search';
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(IndexedDirectory::class, 'directory_id');
    }

    public function extractedText(): HasMany
    {
        return $this->hasMany(IndexedFileText::class, 'file_id');
    }

    public function fingerprint(): BelongsTo
    {
        return $this->belongsTo(FileFingerprint::class, 'fingerprint_id');
    }

    /**
     * Replaces the file's searchable text with the description of the scene it has been matched to.
     */
    public function writeSceneText(StashScene $scene): void
    {
        // Safety check in case we ever implement text extraction for videos.
        if ($this->extracted_text_status !== ExtractedTextStatus::Pending) {
            Log::warning(sprintf('File %s already has extracted text', $this->getKey()));

            return;
        }

        foreach ($this->extractedText()->get() as $extractedText) {
            $extractedText->forceDelete();
        }

        $this->extractedText()->createMany(array_map(fn (string $chunk) => ['text' => $chunk], $scene->textChunks()));
    }

    public function scopeAtPath(Builder $query, string $path): void
    {
        $query->whereRelation('directory', 'path', str_contains($path, '/') ? dirname($path) : '')
            ->where('name', basename($path));
    }

    /**
     * The file's path under the media root. Needs the directory loaded.
     */
    public function getPath(): string
    {
        return ltrim($this->directory->path.'/'.$this->name, '/');
    }

    public function isIndexOutdated(\SplFileInfo $info): bool
    {
        return ($info->getMTime() != $this->mtime) ||
            ($info->getSize() != $this->size) ||
            ($info->getInode() != $this->inode);
    }

    public function updateWithInfo(\SplFileInfo $info): bool
    {
        $this->fill(self::attributesForInfo($info));

        // If the file kind or contents have changed, clear anything based on them. What we know from the contents
        // alone belongs to the fingerprint, so has changed along with it.
        if ($this->isDirty(['kind', 'fingerprint_id'])) {
            $this->unsetRelation('fingerprint');

            // TODO: It probably makes sense for us to dispatch an event after save() if we've done this.
            //       It'll let us decouple the regeneration job dispatch from IndexFileJob.
            //       We should also dispatch that same event on created.

            $this->fill([
                'media_info' => null,
                'thumbnail' => null,
                'filmstrip' => null,
                'extracted_text_status' => ExtractedTextStatus::Pending,
            ]);

            foreach ($this->extractedText()->get() as $extractedText) {
                $extractedText->forceDelete();
            }
        }

        return $this->save();
    }

    public function requiresGeneration(): bool
    {
        if ($this->fingerprint->isBlank()) {
            return false;
        }

        return ($this->kind->canProbeMediaInfo() && $this->media_info === null) ||
            ($this->kind->canThumbnail() && $this->thumbnail === null) ||
            ($this->kind === MediaContentKind::Video && $this->filmstrip === null) ||
            ($this->kind->canPerceptualHash() && $this->fingerprint->phash === null) ||
            (ExtractTextJob::canExtractText($this->kind) && $this->extracted_text_status === ExtractedTextStatus::Pending);
    }

    protected function casts(): array
    {
        return [
            'kind' => MediaContentKind::class,
            'media_info' => MediaInfo::class,
            'extracted_text_status' => ExtractedTextStatus::class,
        ];
    }

    public static function createWithInfo(array $attributes, \SplFileInfo $info): self
    {
        return self::create([
            ...$attributes,
            ...self::attributesForInfo($info),
        ]);
    }

    private static function attributesForInfo(\SplFileInfo $info): array
    {
        $filePath = $info->getPathname();
        $mimeType = mime_content_type($filePath);
        $mediaContentKind = MediaContentKind::guessForFile($mimeType, $info->getExtension());
        $osHash = (new OpenSubtitlesHasher)->hash($filePath);

        return [
            'name' => $info->getFilename(),
            'inode' => $info->getInode(),
            'mtime' => $info->getMTime(),
            'size' => $info->getSize(),
            'kind' => $mediaContentKind,
            'mime_type' => $mimeType,
            'fingerprint_id' => FileFingerprint::findOrCreateForHash($osHash)->getKey(),
        ];
    }

    public static function booted(): void
    {
        static::updating(function (self $file) {
            if ($file->isDirty('thumbnail')) {
                $originalThumbnail = $file->getOriginal('thumbnail');
                if ($originalThumbnail !== null) {
                    self::deleteThumbnail($originalThumbnail);
                }
            }

            if ($file->isDirty('filmstrip')) {
                $originalFilmstrip = $file->getOriginal('filmstrip');
                if ($originalFilmstrip !== null) {
                    self::deleteThumbnail($originalFilmstrip);
                }
            }
        });

        static::deleting(function (self $file) {
            $extractedText = $file->extractedText()->withTrashed()->get();

            foreach ($extractedText as $extractedTextChunk) {
                if ($file->forceDeleting) {
                    $extractedTextChunk->forceDelete();
                } elseif (!$extractedTextChunk->trashed()) {
                    $extractedTextChunk->delete();
                }
            }

            if (!$file->forceDeleting) {
                return;
            }

            if ($file->thumbnail) {
                self::deleteThumbnail($file->thumbnail);
            }

            if ($file->filmstrip) {
                self::deleteThumbnail($file->filmstrip);
            }
        });

        static::restoring(function (self $file) {
            $extractedText = $file->extractedText()->withTrashed()->get();

            foreach ($extractedText as $extractedTextChunk) {
                $extractedTextChunk->restore();
            }
        });
    }

    private static function deleteThumbnail(string $id): void
    {
        $path = Path::makeThumbnailPath($id);

        try {
            if (!unlink($path)) {
                throw new \RuntimeException('Unable to delete thumbnail '.$path);
            }
        } catch (\ErrorException $exception) {
            // PHP does not expose unlink's errno, so only suppress the exact ENOENT warning.
            $notFound = function_exists('posix_strerror') ? posix_strerror(2) : 'No such file or directory';
            if ($exception->getMessage() !== 'unlink('.$path.'): '.$notFound) {
                throw $exception;
            }
        }
    }
}
