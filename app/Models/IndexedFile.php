<?php

namespace App\Models;

use App\Casts\BinaryToHex;
use App\Media\OpenSubtitlesHasher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class IndexedFile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'directory_id',
        'name',
        'inode',
        'mtime',
        'size',
        'mime_type',
        'oshash',
        'phash',
    ];

    public function directory(): HasOne
    {
        return $this->hasOne(IndexedDirectory::class, 'directory_id');
    }

    public function isIndexOutdated(\SplFileInfo $info): bool
    {
        return ($info->getMTime() != $this->mtime) ||
            ($info->getSize() != $this->size) ||
            ($info->getInode() != $this->inode);
    }

    public function updateWithInfo(\SplFileInfo $info): bool
    {
        // TODO: We probably need some logic here to handle updating the name on moves too.
        $this->fill(self::attributesForInfo($info));

        // If the file hash has changed, clear anything based on the contents.
        if ($this->isDirty('oshash')) {
            // TODO: It probably makes sense for us to dispatch an event after save() if we've done this.
            //       It'll let us decouple the regeneration job dispatch from IndexFileJob.
            //       We should also dispatch that same event on created.

            $this->fill([
                'phash' => null,
            ]);
        }

        return $this->save();
    }

    public function requiresGeneration(): bool
    {
        // TODO: Check the MediaContentKind as well, otherwise we dispatch far too many IndexFileJobs.
        return $this->phash === null;
    }

    protected function casts(): array
    {
        return [
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
        $osHash = (new OpenSubtitlesHasher)->hash($filePath);

        return [
            'inode' => $info->getInode(),
            'mtime' => $info->getMTime(),
            'size' => $info->getSize(),
            'mime_type' => $mimeType,
            'oshash' => $osHash,
        ];
    }
}
