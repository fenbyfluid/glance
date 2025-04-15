<?php

use App\Http\Controllers\MediaController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';

Route::middleware(['auth', 'activated'])->group(function () {
    Route::get('/_profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/_profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/_profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/_stream/{path?}', [MediaController::class, 'stream'])
        ->where('path', '.*')
        ->name('media.stream');

    Route::get('/{path?}', [MediaController::class, 'index'])
        ->where('path', '.*')
        ->name('dashboard');
});
