<?php

use Illuminate\Support\Facades\Route;
use Threadlive\Http\Controllers\VisitorController;
use Threadlive\Http\Middleware\EnsureEnabled;

/*
 * The widget's routes. Prefix and middleware come from config/threadlive.php.
 * Every route carries its own throttle: a host's `api` group may have none.
 */

$prefix = config('threadlive.route_prefix', 'threadlive');
$middleware = config('threadlive.middleware', ['api']);

Route::prefix(is_string($prefix) ? $prefix : 'threadlive')
    ->middleware(is_array($middleware) ? $middleware : ['api'])
    ->name('threadlive.')
    ->group(function () {
        Route::get('bootstrap', [VisitorController::class, 'bootstrap'])
            ->middleware('throttle:threadlive')
            ->name('bootstrap');

        Route::middleware([EnsureEnabled::class, 'throttle:threadlive'])->group(function () {
            Route::get('messages', [VisitorController::class, 'index'])->name('messages.index');
            Route::post('messages', [VisitorController::class, 'store'])->name('messages.store');
            Route::post('read', [VisitorController::class, 'read'])->name('read');
            Route::post('email', [VisitorController::class, 'email'])->name('email');
        });
    });
