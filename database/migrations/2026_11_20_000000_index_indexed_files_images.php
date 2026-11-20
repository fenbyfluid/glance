<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Images are served by their ID, and a guest (with shared links) may only have those of the files it can see, so
     * each image request looks its file up by them (MediaController::image()). Without these, that scanned every file.
     */
    public function up(): void
    {
        Schema::table('indexed_files', function (Blueprint $table) {
            $table->index('thumbnail');
            $table->index('filmstrip');
        });
    }

    public function down(): void
    {
        Schema::table('indexed_files', function (Blueprint $table) {
            $table->dropIndex(['thumbnail']);
            $table->dropIndex(['filmstrip']);
        });
    }
};
