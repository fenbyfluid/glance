<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('failed_jobs')->select('connection', 'queue')->cursor() as $job) {
            foreach (['connection', 'queue'] as $column) {
                if (mb_strlen($job->{$column}) > 255) {
                    throw new RuntimeException("Cannot narrow failed_jobs.{$column} while values exceed 255 characters.");
                }
            }
        }

        foreach (['cache', 'cache_locks'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->bigInteger('expiration')->change();
                $table->index('expiration');
            });
        }

        Schema::table('jobs', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->change();
        });

        Schema::table('failed_jobs', function (Blueprint $table) {
            $table->string('connection')->change();
            $table->string('queue')->change();
            $table->index(['connection', 'queue', 'failed_at']);
        });
    }

    public function down(): void
    {
        foreach (['cache', 'cache_locks'] as $name) {
            $outOfRange = DB::table($name)
                ->where('expiration', '>', 2147483647)
                ->orWhere('expiration', '<', -2147483648)
                ->exists();

            if ($outOfRange) {
                throw new RuntimeException("Cannot narrow {$name}.expiration while values exceed the 32-bit range.");
            }
        }

        if (DB::table('jobs')->where('attempts', '>', 255)->orWhere('attempts', '<', 0)->exists()) {
            throw new RuntimeException('Cannot narrow jobs.attempts while values exceed the unsigned 8-bit range.');
        }

        foreach (['cache', 'cache_locks'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['expiration']);
                $table->integer('expiration')->change();
            });
        }

        Schema::table('jobs', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->change();
        });

        Schema::table('failed_jobs', function (Blueprint $table) {
            $table->dropIndex(['connection', 'queue', 'failed_at']);
            $table->text('connection')->change();
            $table->text('queue')->change();
        });
    }
};
