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
        Schema::create('access_control_entries', function (Blueprint $table) {
            $table->id();
            $table->string('path', 768)->unique();
            $table->timestamps();
        });

        Schema::create('access_control_entry_user', function (Blueprint $table) {
            $table->foreignId('access_control_entry_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->unique(['access_control_entry_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_control_entry_user');
        Schema::dropIfExists('access_control_entries');
    }
};
