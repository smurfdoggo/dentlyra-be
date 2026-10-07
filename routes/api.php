<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::prefix('users')->name('users.')->group(__DIR__.'/api/users.php');
    Route::prefix('admins')->name('admins.')->group(__DIR__.'/api/admins.php');
});
