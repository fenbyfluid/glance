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
        Schema::create('stash_studios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('parent_id')->nullable()->constrained($table->getTable());
            $table->string('name', 191);
            $table->timestamps();
        });

        Schema::create('stash_scenes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 191)->nullable();
            $table->string('title', 191)->nullable();
            $table->text('details')->nullable();
            $table->unsignedSmallInteger('production_date_year')->nullable();
            $table->unsignedTinyInteger('production_date_month')->nullable();
            $table->unsignedTinyInteger('production_date_day')->nullable();
            $table->unsignedSmallInteger('release_date_year')->nullable();
            $table->unsignedTinyInteger('release_date_month')->nullable();
            $table->unsignedTinyInteger('release_date_day')->nullable();
            $table->foreignUuid('studio_id')->nullable()->constrained('stash_studios');
            $table->timestamps();
        });

        Schema::create('stash_tag_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 191);
            $table->string('group', 191);
            $table->timestamps();
        });

        Schema::create('stash_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->nullable()->constrained('stash_tag_categories');
            $table->string('name', 191);
            $table->timestamps();
        });

        Schema::create('stash_scene_tag', function (Blueprint $table) {
            $table->foreignUuid('scene_id')->constrained('stash_scenes');
            $table->foreignUuid('tag_id')->constrained('stash_tags');

            $table->primary(['scene_id', 'tag_id']);
        });

        Schema::create('stash_performers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 191);
            $table->string('disambiguation', 191)->nullable();
            $table->unsignedSmallInteger('birth_date_year')->nullable();
            $table->unsignedTinyInteger('birth_date_month')->nullable();
            $table->unsignedTinyInteger('birth_date_day')->nullable();
            $table->string('gender', 191)->nullable();
            $table->timestamps();
        });

        Schema::create('stash_scene_performer', function (Blueprint $table) {
            $table->foreignUuid('scene_id')->constrained('stash_scenes');
            $table->foreignUuid('performer_id')->constrained('stash_performers');
            $table->string('as', 191)->nullable();

            $table->primary(['scene_id', 'performer_id']);
        });

        Schema::table('indexed_files', function (Blueprint $table) {
            $table->foreignUuid('stash_scene_id')->after('filmstrip')->nullable()->constrained();
        });

        // Placeholder "scene" to mark files that have been searched for but not found.
        DB::table('stash_scenes')->insert([
            'id' => '00000000-0000-0000-0000-000000000000',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('indexed_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stash_scene_id');
        });

        Schema::dropIfExists('stash_scene_performer');
        Schema::dropIfExists('stash_scene_tag');
        Schema::dropIfExists('stash_performers');
        Schema::dropIfExists('stash_tags');
        Schema::dropIfExists('stash_tag_categories');
        Schema::dropIfExists('stash_scenes');
        Schema::dropIfExists('stash_studios');
    }
};
