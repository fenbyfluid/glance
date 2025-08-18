<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('indexed_directories', function (Blueprint $table) {
            $table->unsignedInteger('video_thumbnail_time')->nullable()->after('mtime');
        });

        Schema::table('indexed_files', function (Blueprint $table) {
            $table->uuid('thumbnail')->nullable()->after('phash');
            $table->uuid('filmstrip')->nullable()->after('thumbnail');
        });
    }

    public function down(): void
    {
        Schema::table('indexed_directories', function (Blueprint $table) {
            $table->dropColumn('video_thumbnail_time');
        });

        Schema::table('indexed_files', function (Blueprint $table) {
            $table->dropColumn('thumbnail');
            $table->dropColumn('filmstrip');
        });
    }
};
