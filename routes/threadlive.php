<?php

use Illuminate\Support\Facades\Route;
use Threadlive\Http\Controllers\FileController;
use Threadlive\Http\Controllers\TelegramWebhookController;
use Threadlive\Http\Controllers\VisitorController;
use Threadlive\Http\Middleware\EnsureEnabled;

/*
 * The widget's routes. Prefix and middleware come from config/threadlive.php.
 * Every route carries its own throttle: a host's `api` group may have none.
 */

$prefix = config('threadlive.route_prefix', 'threadlive');
$prefix = is_string($prefix) ? $prefix : 'threadlive';
$middleware = config('threadlive.middleware', ['api']);

Route::prefix($prefix)
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
            Route::post('handoff', [VisitorController::class, 'handoff'])->name('handoff');
            Route::post('rating', [VisitorController::class, 'rate'])->name('rating');
        });

        // Opened by <img> and links in the widget, which cannot send the
        // visitor's token: the signed link is the permission.
        Route::get('files/{message}/{file}', [FileController::class, 'show'])
            ->middleware([EnsureEnabled::class, 'signed:relative'])
            ->whereNumber('message')
            ->name('files.show');
    });

// Telegram's own route: none of the host's middleware (its auth means
// nothing to Telegram), no throttle (a busy group sends bursts). The secret
// header is the check.
Route::post($prefix.'/telegram/webhook', TelegramWebhookController::class)->name('threadlive.webhook');
