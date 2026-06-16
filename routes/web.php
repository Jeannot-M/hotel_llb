<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

use App\Http\Controllers\ReportController;

Route::get('/dashboard', function () {
    return redirect('/');
});

Route::middleware(['auth'])->group(function () {
    // Reports
    Route::get('/rapport-journalier', [ReportController::class, 'daily'])->name('report.daily');

    // Hotel Dashboard: Access for Receptionists and Admins
    Volt::route('/', 'hotel-dashboard')->name('dashboard');

    // Accounting: Access for Accountants and Admins
    Volt::route('/comptabilite', 'accounting-dashboard')
        ->name('accounting')
        ->middleware('can:access-accounting');

    // Team Management: Access for Admins only
    Volt::route('/equipe', 'team-management')
        ->name('team')
        ->middleware('can:access-admin');

    Volt::route('/vue-audit', 'owner-dashboard')
        ->name('audit')
        ->middleware('can:access-admin');

    Volt::route('/utilisateurs', 'users-management')
        ->name('users')
        ->middleware('can:access-admin');

    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';

