<?php

namespace App\Models;

use App\Casts\BinaryToHex;
use App\Enums\ExtractedTextStatus;
use App\Jobs\ExtractTextJob;
use App\Media\MediaContentKind;
use App\Media\MediaInfo;
use App\Media\OpenSubtitlesHasher;
use App\Utilities\Path;
use App\Utilities\ScopeHavingAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
        'oshash',
        'phash',
        'thumbnail',
        'filmstrip',
        'stash_scene_id',
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
        return $this->wasRecentlyCreated || $this->wasChanged('name') || !$this->shouldBeSearchable();
    }

    public function toSearchableArray(): array
    {
        $tokenizer = new NGramTokenizer(3, 3);

        return [
            'id' => $this->id,
            'name' => implode(' ', $tokenizer->tokenize($this->name)),
        ];
    }

    public function syncWithSearchUsingQueue(): string
    {
        return 'index';
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(IndexedDirectory::class, 'directory_id');
    }

    public function extractedText(): HasMany
    {
        return $this->hasMany(IndexedFileText::class, 'file_id');
    }

    public function stashScene(): BelongsTo
    {
        return $this->belongsTo(StashScene::class);
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

        // If the file kind or hash has changed, clear anything based on the contents.
        if ($this->isDirty(['kind', 'oshash'])) {
            // TODO: It probably makes sense for us to dispatch an event after save() if we've done this.
            //       It'll let us decouple the regeneration job dispatch from IndexFileJob.
            //       We should also dispatch that same event on created.

            $this->fill([
                'media_info' => null,
                'phash' => null,
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
        return ($this->kind->canProbeMediaInfo() && $this->media_info === null) ||
            ($this->kind->canThumbnail() && $this->thumbnail === null) ||
            ($this->kind === MediaContentKind::Video && $this->filmstrip === null) ||
            ($this->kind->canPerceptualHash() && $this->phash === null) ||
            (ExtractTextJob::canExtractText($this->kind) && $this->extracted_text_status === ExtractedTextStatus::Pending);
    }

    protected function casts(): array
    {
        return [
            'kind' => MediaContentKind::class,
            'media_info' => MediaInfo::class,
            'oshash' => BinaryToHex::withLength(8),
            'phash' => BinaryToHex::withLength(8),
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
            'oshash' => $osHash,
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
