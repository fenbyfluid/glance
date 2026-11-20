<?php

use App\Http\Controllers\Admin\AccessController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SharedLinkController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';

Route::get('/_get_remote_ip', function (Request $request) {
    return response($request->ip(), 200, ['Content-Type' => 'text/plain']);
})->name('utility.discover_ip');

// Public: records the link in the session and forwards to the file's info page or the directory (see SharedLinkGuard).
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/_s/{token}', [SharedLinkController::class, 'show'])
        ->where('token', '[A-Za-z0-9]+')
        ->name('shared-link.show');

    Route::get('/_s/{token}/preview.jpg', [SharedLinkController::class, 'preview'])
        ->where('token', '[A-Za-z0-9]+')
        ->name('shared-link.preview');
});

Route::middleware(['auth', 'activated'])->group(function () {
    Route::get('/_profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/_profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/_profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/_profile/links', [SharedLinkController::class, 'mine'])
        ->middleware('can:create,App\Models\SharedLink')
        ->name('profile.links');

    Route::prefix('_links')->name('shared-links.')->group(function () {
        Route::get('/', [SharedLinkController::class, 'index'])->name('index');
        Route::post('/', [SharedLinkController::class, 'store'])->name('store');
        Route::patch('/{sharedLink}', [SharedLinkController::class, 'update'])->name('update');
        Route::delete('/{sharedLink}', [SharedLinkController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('_admin')->name('admin.')->middleware(['can:admin'])->group(function () {
        Route::get('/links', [SharedLinkController::class, 'all'])->name('links.index');

        Route::post('/users/impersonate/{user?}', [UserController::class, 'impersonate'])
            ->name('users.impersonate');

        Route::resource('users', UserController::class)->only([
            'index', 'create', 'store', 'edit', 'update', 'destroy',
        ]);

        Route::resource('access', AccessController::class)->only([
            'index', 'store', 'destroy',
        ]);
    });
});

// What shared files and directories need, so guests with shared links in their session get in too. The controllers
// check every path (PathAccess) and send a guest to the login page for anything its links don't cover.
Route::middleware(['auth:web,shared', 'activated'])->group(function () {
    // Guests can search what's shared with them, but are throttled (see AppServiceProvider).
    Route::prefix('_search')->name('search.')->middleware('throttle:search')->group(function () {
        Route::get('/', [SearchController::class, 'index'])->name('index');
        Route::get('/name/{query?}', [SearchController::class, 'name'])->name('name');
        Route::get('/content/{query?}', [SearchController::class, 'content'])->name('content');
    });

    Route::get('/_info/{path?}', [MediaController::class, 'info'])
        ->where('path', '.*')
        ->name('media.info');

    Route::get('/_stream/{path?}', [MediaController::class, 'stream'])
        ->where('path', '.*')
        ->name('media.stream');

    Route::get('/_image/{id}.avif', [MediaController::class, 'image'])
        ->name('media.image');

    Route::get('/{path?}', [MediaController::class, 'index'])
        ->where('path', '.*')
        ->name('dashboard');
});
