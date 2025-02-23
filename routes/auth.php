<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\InvitedUserController;
use App\Http\Controllers\Auth\PasswordController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('_login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('_login', [AuthenticatedSessionController::class, 'store']);

    Route::get('_invite/{user}', [InvitedUserController::class, 'login'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('invite.link');
});

Route::middleware('auth')->group(function () {
    Route::get('_invite', [InvitedUserController::class, 'create'])->name('invite');

    Route::post('_invite', [InvitedUserController::class, 'store']);

    Route::post('_logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});

Route::middleware(['auth', 'activated'])->group(function () {
    Route::put('_password', [PasswordController::class, 'update'])->name('password.update');
});
