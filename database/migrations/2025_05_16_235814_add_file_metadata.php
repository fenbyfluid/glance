<?php

use App\Media\MediaContentKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('indexed_files', function (Blueprint $table) {
            $table->string('kind', 191)->after('size');
            $table->json('media_info')->nullable()->after('mime_type');
        });

        DB::table('indexed_files')
            ->where('kind', '=', '')
            ->update(['kind' => MediaContentKind::Other]);
    }

    public function down(): void
    {
        Schema::table('indexed_files', function (Blueprint $table) {
            $table->dropColumn('kind');
            $table->dropColumn('media_info');
        });
    }
};
