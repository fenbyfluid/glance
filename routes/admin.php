<?php

use App\Http\Controllers\Admin\UserController;

Route::prefix('admin')->name('admin.')->middleware(['auth', 'activated', 'can:admin'])->group(function () {
    Route::resource('users', UserController::class)->only([
        'index', 'create', 'store', 'edit', 'update', 'destroy',
    ]);
});
