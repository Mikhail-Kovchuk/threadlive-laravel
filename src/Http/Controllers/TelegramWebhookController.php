<?php

declare(strict_types=1);

namespace Threadlive\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Threadlive\Telegram\UpdateHandler;
use Throwable;

/**
 * Where Telegram posts updates (setWebhook with secret_token, done by
 * threadlive:setup). A request without the matching secret header is not
 * from Telegram.
 */
final class TelegramWebhookController
{
    public function __invoke(Request $request, UpdateHandler $handler): Response
    {
        $secret = config('threadlive.webhook_secret');
        $given = $request->header('X-Telegram-Bot-Api-Secret-Token');

        abort_unless(
            is_string($secret) && $secret !== '' && is_string($given) && hash_equals($secret, $given),
            403,
        );

        try {
            $handler->handle($request->json()->all());
        } catch (Throwable $e) {
            // Reported, and still 200: Telegram retries a failed update before
            // delivering the next ones, so one bad update would hold up every
            // reply after it.
            report($e);
        }

        return response('', 200);
    }
}
