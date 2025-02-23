<?php

use App\Http\Controllers\MediaController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'activated'])->group(function () {
    Route::get('/_profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/_profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/_profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

require __DIR__.'/admin.php';

Route::get('/{path?}', [MediaController::class, 'index'])
    ->where('path', '.*')
    ->middleware(['auth', 'activated'])
    ->name('dashboard');
