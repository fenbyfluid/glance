<?php

use App\Http\Controllers\Admin\AccessController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';

Route::middleware(['auth', 'activated'])->group(function () {
    Route::get('/_profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/_profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/_profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::prefix('_admin')->name('admin.')->middleware(['can:admin'])->group(function () {
        Route::post('/users/impersonate/{user?}', [UserController::class, 'impersonate'])
            ->name('users.impersonate');

        Route::resource('users', UserController::class)->only([
            'index', 'create', 'store', 'edit', 'update', 'destroy',
        ]);

        Route::resource('access', AccessController::class)->only([
            'index', 'store', 'destroy',
        ]);
    });

    Route::prefix('_search')->name('search.')->group(function () {
        Route::get('/name/{query?}', [SearchController::class, 'name'])->name('name');
        Route::get('/content/{query?}', [SearchController::class, 'content'])->name('content');
    });

    Route::get('/_stream/{path?}', [MediaController::class, 'stream'])
        ->where('path', '.*')
        ->name('media.stream');

    Route::get('/_image/{id}.avif', [MediaController::class, 'image'])
        ->name('media.image');

    Route::get('/{path?}', [MediaController::class, 'index'])
        ->where('path', '.*')
        ->name('dashboard');
});
