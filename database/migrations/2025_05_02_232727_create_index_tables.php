<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('indexed_directories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained($table->getTable());
            $table->string('name', 768 - (8 / 4))->index();
            $table->string('path', 768)->unique();
            $table->unsignedBigInteger('inode');
            $table->unsignedBigInteger('mtime');
            $table->timestamps();
            $table->softDeletes();

            // We don't have the same (parent_id, name) index as the files table as we
            // instead keep the fully expanded value in the path column. This lets us
            // avoid joins when matching full paths, and avoid recursion when building
            // full file paths. If we end up not needing the latter, we could consider
            // switching to storing hashes instead of the full path to avoid the length
            // limitation. We do reserve the space in name in case we ever want to add
            // the composite index later though.
        });

        Schema::create('indexed_files', function (Blueprint $table) {
            $table->id();
            $directoryId = $table->foreignId('directory_id')->nullable();
            $table->string('name', 768 - (8 / 4))->index();
            $table->unsignedBigInteger('inode');
            $table->unsignedBigInteger('mtime');
            $table->unsignedBigInteger('size');
            $table->string('mime_type', 191);
            $table->binary('oshash', 8, true)->index();
            $table->binary('phash', 8, true)->nullable();
            $table->timestamps();
            $table->softDeletes();

            // We split this out so the foreign key uses our unique index rather than
            // creating its own and then silently dropping it later. Relatedly, we
            // reserve 8 bytes of the name (2 utf8mb4 characters) for the directory ID.
            $table->unique(['directory_id', 'name']);
            $directoryId->constrained('indexed_directories');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
        Schema::dropIfExists('directories');
    }
};
