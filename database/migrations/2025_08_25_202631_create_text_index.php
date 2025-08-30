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
        Schema::create('indexed_file_texts', function (Blueprint $table) {
            $table->foreignId('id')->unique();
            $table->longText('text');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('indexed_files', function (Blueprint $table) {
            $table->integer('extracted_text_status')->default(0)->after('filmstrip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('indexed_file_texts');

        Schema::table('indexed_files', function (Blueprint $table) {
            $table->dropColumn('extracted_text_status');
        });
    }
};
