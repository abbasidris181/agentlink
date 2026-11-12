<?php

use App\Http\Controllers\BvnVerificationResultController;
use App\Http\Controllers\NinVerificationResultController;
use App\Http\Controllers\PersonalizationSlipController;
use App\Http\Controllers\ServiceRequestProofController;
use App\Http\Controllers\VerifyEmailController;
use App\Http\Middleware\EnsureServiceIsAvailable;
use App\Http\Middleware\EnsureSignupEmailIsVerified;
use App\Livewire\Admin\Transactions;
use App\Models\BvnVerificationRecord;
use App\Models\NinVerificationRecord;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route(auth()->user()->needsEmailVerification() ? 'verification.notice' : 'dashboard');
    }

    return view('welcome');
})->name('home');

Route::view('/register', 'register')->middleware('guest')->name('register');

Route::view('/email/verify', 'verify-email')->middleware('auth')->name('verification.notice');
Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)->middleware(['auth', 'signed', 'throttle:6,1'])->name('verification.verify');

Route::middleware([EnsureSignupEmailIsVerified::class, EnsureServiceIsAvailable::class])->group(function (): void {
    Route::view('/dashboard', 'dashboard')->middleware('auth')->name('dashboard');

    Route::view('/personalization', 'personalization')->middleware('auth')->name('personalization');
    Route::get('/personalization/{personalization:reference}/slips/{slip}', PersonalizationSlipController::class)->middleware('auth')->whereIn('slip', array_keys(NinVerificationRecord::SLIPS))->name('personalization.slip');
    Route::post('/personalization/{personalization:reference}/slips/{slip}', PersonalizationSlipController::class)->middleware('auth')->whereIn('slip', array_keys(NinVerificationRecord::SLIPS))->name('personalization.slip.purchase');
    Route::view('/nin/modification', 'nin-modification')->middleware('auth')->name('nin.modification');
    Route::view('/nin/modification/{stage}', 'nin-modification')->middleware('auth')->whereIn('stage', ['first', 'second'])->name('nin.modification.form');
    Route::view('/nin/v1', 'nin-v1')->middleware('auth')->name('nin.v1');
    Route::get('/nin/v1/results/{verification:reference}', [NinVerificationResultController::class, 'show'])->middleware('auth')->name('nin.v1.result');
    Route::get('/nin/v1/results/{verification:reference}/slips/{slip}', [NinVerificationResultController::class, 'download'])->middleware('auth')->whereIn('slip', array_keys(NinVerificationRecord::SLIPS))->name('nin.v1.slip');
    Route::post('/nin/v1/results/{verification:reference}/slips/{slip}', [NinVerificationResultController::class, 'download'])->middleware('auth')->whereIn('slip', array_keys(NinVerificationRecord::SLIPS))->name('nin.v1.slip.purchase');
    Route::view('/nin/v2', 'nin-v2')->middleware('auth')->name('nin.v2');
    Route::get('/nin/v2/results/{verification:reference}', [NinVerificationResultController::class, 'show'])->middleware('auth')->name('nin.v2.result');
    Route::get('/nin/v2/results/{verification:reference}/slips/{slip}', [NinVerificationResultController::class, 'download'])->middleware('auth')->whereIn('slip', array_keys(NinVerificationRecord::SLIPS))->name('nin.v2.slip');
    Route::post('/nin/v2/results/{verification:reference}/slips/{slip}', [NinVerificationResultController::class, 'download'])->middleware('auth')->whereIn('slip', array_keys(NinVerificationRecord::SLIPS))->name('nin.v2.slip.purchase');
    Route::view('/nin/v3', 'nin-v3')->middleware('auth')->name('nin.v3');
    Route::get('/nin/v3/results/{verification:reference}', [NinVerificationResultController::class, 'show'])->middleware('auth')->name('nin.v3.result');
    Route::get('/nin/v3/results/{verification:reference}/slips/{slip}', [NinVerificationResultController::class, 'download'])->middleware('auth')->whereIn('slip', array_keys(NinVerificationRecord::SLIPS))->name('nin.v3.slip');
    Route::post('/nin/v3/results/{verification:reference}/slips/{slip}', [NinVerificationResultController::class, 'download'])->middleware('auth')->whereIn('slip', array_keys(NinVerificationRecord::SLIPS))->name('nin.v3.slip.purchase');
    Route::view('/bvn/license-onboarding', 'bvn-license-onboarding')->middleware('auth')->name('bvn.onboarding');
    Route::view('/bvn/modification', 'bvn-modification')->middleware('auth')->name('bvn.modification');
    Route::view('/bvn/verification', 'bvn-verification')->middleware('auth')->name('bvn.verify');
    Route::get('/bvn/verification/results/{verification:reference}', [BvnVerificationResultController::class, 'show'])->middleware('auth')->name('bvn.result');
    Route::get('/bvn/verification/results/{verification:reference}/slips/{slip}', [BvnVerificationResultController::class, 'download'])->middleware('auth')->whereIn('slip', array_keys(BvnVerificationRecord::SLIPS))->name('bvn.slip');
    Route::post('/bvn/verification/results/{verification:reference}/slips/{slip}', [BvnVerificationResultController::class, 'download'])->middleware('auth')->whereIn('slip', array_keys(BvnVerificationRecord::SLIPS))->name('bvn.slip.purchase');

    Route::view('/ipe/clearing', 'ipe-clearing')->middleware('auth')->name('ipe.clearing');
    Route::view('/ipe/mod', 'mod-ipe')->middleware('auth')->name('ipe.mod');

    Route::view('/bvn/retrieval', 'bvn-retrieval')->middleware('auth')->name('bvn.retrieval');

    Route::view('/validations', 'validations')->middleware('auth')->name('validations');

    Route::view('/delinking', 'delinking')->middleware('auth')->name('delinking');

    Route::view('/ipe/instant', 'instant-ipe')->middleware('auth')->name('ipe.instant');

    Route::view('/transactions', 'transactions')->middleware('auth')->name('transactions');
    Route::view('/complaints', 'complaints')->middleware('auth')->name('complaints');

    Route::get('/service-requests/{serviceRequest}/proof', ServiceRequestProofController::class)->middleware('auth')->name('service-requests.proof');

    Route::view('/wallet/transfer', 'wallet-transfer')->middleware(['auth', 'can:wallet.transfer'])->name('wallet.transfer');

    Route::middleware(['auth', 'can:access-admin'])->prefix('admin')->name('admin.')->group(function (): void {
        Route::view('/', 'admin', ['page' => 'overview', 'title' => 'Admin dashboard'])->name('dashboard');
        Route::view('/requests', 'admin', ['page' => 'requests', 'title' => 'Service requests'])->middleware('can:requests.view')->name('requests');
        Route::view('/requests/service/{service}', 'admin', ['page' => 'requests', 'title' => 'Service requests'])->whereIn('service', ServiceRequest::ADMIN_SERVICES)->middleware('can:requests.view')->name('requests.service');
        Route::view('/requests/{requestId}', 'admin', ['page' => 'requests', 'title' => 'Manage service request'])->whereNumber('requestId')->middleware('can:requests.view')->name('requests.show');
        Route::view('/transactions/{transactionGroup?}', 'admin', ['page' => 'transactions', 'title' => 'Transactions'])->whereIn('transactionGroup', array_keys(Transactions::GROUPS))->middleware('can:transactions.view')->name('transactions');
        Route::view('/prices', 'admin', ['page' => 'prices', 'title' => 'Service prices'])->middleware('can:prices.view')->name('prices');
        Route::view('/settings', 'admin', ['page' => 'settings', 'title' => 'Site settings'])->middleware('can:settings.view')->name('settings');
        Route::view('/users', 'admin', ['page' => 'users', 'title' => 'User management'])->middleware('can:users.view')->name('users');
        Route::view('/users/{userId}', 'admin', ['page' => 'users', 'title' => 'Manage user'])->whereNumber('userId')->middleware('can:users.view')->name('users.show');
    });

});
