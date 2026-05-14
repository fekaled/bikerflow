<?php

use App\Http\Controllers\Auth\MagicLinkController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Auth routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [MagicLinkController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [MagicLinkController::class, 'sendMagicLink']);
    Route::get('/auth/magic-link/verify/{user}/{hash}', [MagicLinkController::class, 'verifyMagicLink'])
        ->name('auth.magic-link.verify');
});

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::post('/logout', function () {
        Auth::logout();

        return redirect('/login');
    })->name('logout');
});
