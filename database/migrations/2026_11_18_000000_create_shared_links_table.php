<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Public links to a file (or later a directory), which let anyone holding them in through the creator's access.
     */
    public function up(): void
    {
        Schema::create('shared_links', function (Blueprint $table) {
            $table->id();
            $table->string('token', 32)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Not indexed: links are only ever looked up by token, or listed per user / for a file in small numbers.
            $table->text('path');
            $table->boolean('is_directory')->default(false);
            $table->string('note')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_links');
    }
};
