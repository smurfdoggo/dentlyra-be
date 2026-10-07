<?php

use App\Http\Controllers\Api\V1\Admin\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

Route::middleware('auth:admins-api')->group(function (): void {
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
});
