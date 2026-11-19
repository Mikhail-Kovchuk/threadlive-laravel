<?php

declare(strict_types=1);

namespace Threadlive\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Turnstile on a guest's first message: a bot that cannot pass
 * it cannot open topics in the support group. Signed-in users, and anyone
 * already in a conversation, never see it.
 *
 * On when both THREADLIVE_TURNSTILE_SITE_KEY and _SECRET are set.
 */
final class Turnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function enabled(): bool
    {
        return self::siteKey() !== null && self::secret() !== null;
    }

    public static function siteKey(): ?string
    {
        $key = config('threadlive.turnstile_site_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /**
     * True when Cloudflare says the token is good. When Cloudflare cannot be
     * reached the message goes through (and the outage is reported): an
     * outage there must not take the chat down with it — the per-IP limit on
     * new conversations still holds.
     */
    public static function verify(?string $token, ?string $ip): bool
    {
        $secret = self::secret();

        if ($secret === null) {
            return true;
        }

        if ($token === null || $token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]));
        } catch (ConnectionException $e) {
            report($e);

            return true;
        }

        if ($response->serverError()) {
            report(new \RuntimeException('Turnstile siteverify answered '.$response->status().'.'));

            return true;
        }

        return $response->json('success') === true;
    }

    private static function secret(): ?string
    {
        $secret = config('threadlive.turnstile_secret');

        return is_string($secret) && trim($secret) !== '' ? trim($secret) : null;
    }
}
