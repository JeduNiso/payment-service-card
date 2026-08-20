<?php

use App\Http\Controllers\Api\CybersourceController;
use App\Http\Controllers\PaymentCheckoutController;
use App\Http\Controllers\PaymentTokenController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/api/payments/session', [PaymentTokenController::class, 'issue'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('payment.token.issue');

Route::post('/api/payments/search', [PaymentTokenController::class, 'search'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('payment.customer.search');

Route::get('/payments/{token}', [PaymentCheckoutController::class, 'show'])->name('payment.checkout');
Route::post('/payments/{token}', [PaymentCheckoutController::class, 'submit'])->name('payment.checkout.submit');

Route::get('/api/payments/{code}/enrollment-callback', [CybersourceController::class, 'enrollment_callback'])
    ->name('payment.cybersource.enrollment.callback');
Route::post('/api/payments/{code}/enrollment-callback', [CybersourceController::class, 'enrollment_callback'])
    ->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    ])
    ->name('payment.cybersource.enrollment.callback.post');
Route::get('/api/payments/{code}/enrollment-result', [CybersourceController::class, 'enrollment_result'])
    ->name('payment.cybersource.enrollment.result');
Route::post('/api/payments/{code}/enrollment-result', [CybersourceController::class, 'enrollment_result'])
    ->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    ])
    ->name('payment.cybersource.enrollment.result.post');

Route::get('/pay/{token}', [PaymentCheckoutController::class, 'show'])->name('payment.checkout.legacy');
Route::post('/pay/{token}', [PaymentCheckoutController::class, 'submit'])->name('payment.checkout.submit.legacy');

Route::get('/debug/cache-status', function () {
    return [
        'cache_store' => config('cache.default'),
        'cache_table_exists' => \Illuminate\Support\Facades\Schema::hasTable('cache'),
        'cache_table_count' => \Illuminate\Support\Facades\DB::table('cache')->count(),
        'test_write' => \Illuminate\Support\Facades\Cache::put('debug_test', 'test_value', 60),
        'test_read' => \Illuminate\Support\Facades\Cache::get('debug_test'),
        'cache_prefix' => config('cache.prefix'),
    ];
});
