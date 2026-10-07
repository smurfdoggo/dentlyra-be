<?php

use App\Http\Controllers\Api\V1\User\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('register', [AuthController::class, 'register'])->middleware('throttle:registration')->name('register');
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

Route::middleware('auth:users-api')->group(function (): void {
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
});
