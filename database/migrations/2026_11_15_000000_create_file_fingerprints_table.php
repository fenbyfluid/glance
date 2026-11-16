<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The placeholder "scene" that used to mark files that had been searched for but not found.
    private const string PLACEHOLDER_SCENE_ID = '00000000-0000-0000-0000-000000000000';

    /**
     * Everything we know about a file purely from its contents (its hashes, and the scene they match) moves out of
     * indexed_files and into a table keyed by OSHash. The indexer deletes and recreates the entry for a moved file,
     * which used to throw all of that away, whereas a fingerprint is found again by any file with the same contents.
     */
    public function up(): void
    {
        Schema::create('file_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->binary('oshash', 8, true)->unique();
            $table->binary('phash', 8, true)->nullable();
            $table->foreignUuid('stash_scene_id')->nullable()->constrained();
            // Set once the hashes have been searched for (or claimed for searching), whether a scene was found or not.
            $table->timestamp('stash_searched_at')->nullable();
            $table->timestamps();
        });

        $now = now();

        // One fingerprint per distinct hash. Where files with the same hash disagree, a real scene wins over
        // "not found", which wins over never having been searched for.
        DB::table('file_fingerprints')->insertUsing(
            ['oshash', 'phash', 'stash_scene_id', 'stash_searched_at', 'created_at', 'updated_at'],
            DB::table('indexed_files')
                ->select('oshash')
                ->selectRaw('MAX(phash)')
                ->selectRaw('MAX(CASE WHEN stash_scene_id != ? THEN stash_scene_id END)', [self::PLACEHOLDER_SCENE_ID])
                ->selectRaw('MAX(CASE WHEN stash_scene_id IS NOT NULL THEN ? END)', [$now])
                ->selectRaw('?, ?', [$now, $now])
                ->groupBy('oshash'),
        );

        Schema::table('indexed_files', function (Blueprint $table) {
            $fingerprintId = $table->foreignId('fingerprint_id')->nullable()->after('mime_type');

            // We look up the files with a fingerprint, so index it ourselves rather than relying on the database
            // creating one for the foreign key (which MariaDB does, but SQLite doesn't).
            $table->index('fingerprint_id');
            $fingerprintId->constrained('file_fingerprints');
        });

        DB::table('indexed_files')->update([
            'fingerprint_id' => DB::raw('(SELECT id FROM file_fingerprints WHERE file_fingerprints.oshash = indexed_files.oshash)'),
        ]);

        Schema::table('indexed_files', function (Blueprint $table) {
            $table->unsignedBigInteger('fingerprint_id')->nullable(false)->change();
        });

        Schema::table('indexed_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stash_scene_id');
        });

        Schema::table('indexed_files', function (Blueprint $table) {
            $table->dropIndex(['oshash']);
        });

        Schema::table('indexed_files', function (Blueprint $table) {
            $table->dropColumn(['oshash', 'phash']);
        });

        DB::table('stash_scenes')->where('id', '=', self::PLACEHOLDER_SCENE_ID)->delete();
    }

    public function down(): void
    {
        DB::table('stash_scenes')->insert([
            'id' => self::PLACEHOLDER_SCENE_ID,
        ]);

        Schema::table('indexed_files', function (Blueprint $table) {
            $table->binary('oshash', 8, true)->nullable()->after('mime_type');
            $table->binary('phash', 8, true)->nullable()->after('oshash');
            $table->foreignUuid('stash_scene_id')->after('filmstrip')->nullable()->constrained();
        });

        $fingerprintColumn = fn (string $expression) => DB::raw(
            '(SELECT '.$expression.' FROM file_fingerprints WHERE file_fingerprints.id = indexed_files.fingerprint_id)'
        );

        DB::table('indexed_files')->update([
            'oshash' => $fingerprintColumn('oshash'),
            'phash' => $fingerprintColumn('phash'),
            'stash_scene_id' => $fingerprintColumn(
                "CASE WHEN stash_scene_id IS NULL AND stash_searched_at IS NOT NULL THEN '".self::PLACEHOLDER_SCENE_ID."' ELSE stash_scene_id END"
            ),
        ]);

        Schema::table('indexed_files', function (Blueprint $table) {
            $table->binary('oshash', 8, true)->nullable(false)->change();
            $table->index('oshash');
        });

        Schema::table('indexed_files', function (Blueprint $table) {
            $table->dropForeign(['fingerprint_id']);
        });

        Schema::table('indexed_files', function (Blueprint $table) {
            $table->dropIndex(['fingerprint_id']);
        });

        Schema::table('indexed_files', function (Blueprint $table) {
            $table->dropColumn('fingerprint_id');
        });

        Schema::dropIfExists('file_fingerprints');
    }
};
