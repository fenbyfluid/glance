<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\InvitedUserController;
use App\Http\Controllers\Auth\PasswordController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('invite/{user}', [InvitedUserController::class, 'login'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('invite.link');
});

Route::middleware('auth')->group(function () {
    Route::get('invite', [InvitedUserController::class, 'create'])->name('invite');

    Route::post('invite', [InvitedUserController::class, 'store']);

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});

Route::middleware(['auth', 'activated'])->group(function () {
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');
});
