<?php

use App\Http\Controllers\Admin\ShiftBikerController;
use App\Http\Controllers\Admin\ShiftController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\RestaurantManager\ShiftEntryController;
use App\Http\Controllers\RestaurantManager\ShiftTrackingController;
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

// Live Tick Tracking — Restaurant Managers and Admins
Route::middleware(['auth', 'role:restaurant_manager,admin'])->group(function () {
    Route::get('/tracking', [ShiftTrackingController::class, 'dashboard'])->name('tracking.dashboard');
    Route::post('/tracking/{shift}/tick', [ShiftTrackingController::class, 'tick'])->name('tracking.tick');

    // Manual Entry — End-of-Shift Trip Entry (Phase 2E)
    Route::get('/entry/{shift}', [ShiftEntryController::class, 'show'])->name('entry.show');
    Route::post('/entry/{shift}', [ShiftEntryController::class, 'store'])->name('entry.store');
});

// Admin shift management
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::resource('shifts', ShiftController::class);
    Route::post('shifts/{shift}/close', [ShiftController::class, 'close'])->name('shifts.close');

    // Shift-Biker assignment management (Phase 2C)
    Route::get('shifts/{shift}/bikers', [ShiftBikerController::class, 'index'])->name('shifts.bikers.index');
    Route::post('shifts/{shift}/bikers', [ShiftBikerController::class, 'store'])->name('shifts.bikers.store');
    Route::patch('shifts/{shift}/bikers/{biker}', [ShiftBikerController::class, 'update'])->name('shifts.bikers.update');
    Route::delete('shifts/{shift}/bikers/{biker}', [ShiftBikerController::class, 'destroy'])->name('shifts.bikers.destroy');
});
