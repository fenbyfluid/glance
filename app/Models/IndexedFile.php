<?php

namespace App\Models;

use App\Casts\BinaryToHex;
use App\Media\MediaContentKind;
use App\Media\MediaInfo;
use App\Media\OpenSubtitlesHasher;
use App\Utilities\Path;
use App\Utilities\ScopeHavingAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class IndexedFile extends Model
{
    use ScopeHavingAccess, SoftDeletes;

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
    ];

    public function directory(): BelongsTo
    {
        return $this->belongsTo(IndexedDirectory::class, 'directory_id');
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
            ]);
        }

        return $this->save();
    }

    public function requiresGeneration(): bool
    {
        return ($this->kind->canProbeMediaInfo() && $this->media_info === null) ||
            ($this->kind->canThumbnail() && $this->thumbnail === null) ||
            ($this->kind === MediaContentKind::Video && $this->filmstrip === null) ||
            ($this->kind->canPerceptualHash() && $this->phash === null);
    }

    protected function casts(): array
    {
        return [
            'kind' => MediaContentKind::class,
            'media_info' => MediaInfo::class,
            'oshash' => BinaryToHex::withLength(8),
            'phash' => BinaryToHex::withLength(8),
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
                    unlink(Path::makeThumbnailPath($originalThumbnail));
                }
            }

            if ($file->isDirty('filmstrip')) {
                $originalFilmstrip = $file->getOriginal('filmstrip');
                if ($originalFilmstrip !== null) {
                    unlink(Path::makeThumbnailPath($originalFilmstrip));
                }
            }
        });

        static::deleting(function (self $file) {
            if (!$file->forceDeleting) {
                return;
            }

            if ($file->thumbnail) {
                unlink(Path::makeThumbnailPath($file->thumbnail));
            }

            if ($file->filmstrip) {
                unlink(Path::makeThumbnailPath($file->filmstrip));
            }
        });
    }
}
