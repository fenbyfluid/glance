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
        DB::table('indexed_files')->update(['extracted_text_status' => 0]);

        Schema::drop('indexed_file_texts');

        Schema::create('indexed_file_texts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->index();
            $table->binary('text');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('indexed_files')->update(['extracted_text_status' => 0]);

        Schema::drop('indexed_file_texts');

        Schema::create('indexed_file_texts', function (Blueprint $table) {
            $table->foreignId('id')->unique();
            $table->longText('text');
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
