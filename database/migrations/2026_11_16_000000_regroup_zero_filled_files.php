<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string BLANK_HASH = '0000000000000000';

    /**
     * Files containing nothing but zeros now share the blank (all zero) fingerprint with empty files, rather than
     * having their size as their OSHash. This moves the ones we've already indexed over, without having to read
     * them all again: a hash that's equal to the file's size means everything that was hashed was zero.
     */
    public function up(): void
    {
        $blankId = null;

        // Only hashes that fit in the low 4 bytes, to keep the size comparison simple. A file of over 4GB of zeros
        // will just have to wait until it's next indexed.
        $candidates = DB::table('file_fingerprints')
            ->where('oshash', '<', hex2bin('0000000100000000'))
            ->where('oshash', '!=', hex2bin(self::BLANK_HASH))
            ->get(['id', 'oshash']);

        foreach ($candidates as $candidate) {
            $size = hexdec(bin2hex($candidate->oshash));

            // Files too short to have a whole word to sum also have their size as their hash, whatever is in them.
            if ($size < 8) {
                continue;
            }

            $files = DB::table('indexed_files')->where('fingerprint_id', '=', $candidate->id);

            if (!$files->clone()->exists() || $files->clone()->where('size', '!=', $size)->exists()) {
                continue;
            }

            $files->update(['fingerprint_id' => $blankId ??= $this->blankFingerprintId()]);

            // Nothing useful can have been learnt from these, but be careful all the same.
            DB::table('file_fingerprints')
                ->where('id', '=', $candidate->id)
                ->whereNull('phash')
                ->whereNull('stash_scene_id')
                ->delete();
        }
    }

    private function blankFingerprintId(): int
    {
        $now = now();

        DB::table('file_fingerprints')->insertOrIgnore([
            'oshash' => hex2bin(self::BLANK_HASH),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('file_fingerprints')->where('oshash', '=', hex2bin(self::BLANK_HASH))->value('id');
    }

    public function down(): void
    {
        // Nothing to undo: indexing the files again with the old hasher would give them their old fingerprints back.
    }
};
