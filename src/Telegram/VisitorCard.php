<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use Threadlive\Models\Conversation;
use Threadlive\Support\Faq;

/**
 * The first post of a topic: who the visitor is, where they wrote from, the
 * host's own lines (ChatHost::cardLines), and the buttons.
 *
 * Everything that came from a visitor or a host goes through e(): the post
 * is sent with parse_mode=HTML.
 */
final class VisitorCard
{
    public static function html(Conversation $conversation): string
    {
        $context = $conversation->context ?? [];
        $card = is_array($context['card'] ?? null) ? $context['card'] : [];

        $who = '<b>'.e($conversation->name ?: 'Guest').'</b>';
        if ($conversation->email) {
            $who .= ' · '.e($conversation->email);
        }
        $who .= $conversation->user_ref !== null ? ' · signed in' : ' · guest';

        $lines = ['👤 '.$who];

        $url = $context['url'] ?? null;
        if (is_string($url) && self::isWebUrl($url)) {
            $title = is_string($context['title'] ?? null) && $context['title'] !== '' ? $context['title'] : $url;
            $lines[] = '🔗 <a href="'.e($url).'">'.e($title).'</a>';
        }

        foreach (is_array($card['lines'] ?? null) ? $card['lines'] : [] as $line) {
            $formatted = self::line($line);
            if ($formatted !== null) {
                $lines[] = $formatted;
            }
        }

        $read = Faq::questions(array_values(array_filter((array) ($context['faq'] ?? []), 'is_string')));
        if ($read !== []) {
            $lines[] = '📚 Read in the FAQ: '.e(implode('; ', $read));
        }

        $adminUrl = $card['admin_url'] ?? null;
        if (is_string($adminUrl) && self::isWebUrl($adminUrl)) {
            $lines[] = '🛠 <a href="'.e($adminUrl).'">Open in the admin panel</a>';
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public static function keyboard(Conversation $conversation): array
    {
        $row = [];

        if (config('threadlive.claim_button', true)) {
            $row[] = ['text' => '🙋 Take', 'callback_data' => "tl:claim:{$conversation->id}"];
        }

        $row[] = ['text' => '✅ Close', 'callback_data' => "tl:close:{$conversation->id}"];
        // Opens ActionPanel in place of this row.
        $row[] = ['text' => '⚙️ Actions', 'callback_data' => "tl:menu:{$conversation->id}"];

        return [$row];
    }

    /**
     * "• Label: value", the value linked when there is a web URL. Null for
     * anything that is not a label/value pair of strings.
     */
    public static function line(mixed $line): ?string
    {
        if (! is_array($line) || ! is_string($line['label'] ?? null) || ! is_string($line['value'] ?? null)) {
            return null;
        }

        $url = self::webUrl($line['url'] ?? null);
        $value = $url !== null ? '<a href="'.e($url).'">'.e($line['value']).'</a>' : e($line['value']);

        return '• '.e($line['label']).': '.$value;
    }

    /** The value when it is an http(s) URL — never a javascript: or tg: link. */
    public static function webUrl(mixed $url): ?string
    {
        return is_string($url) && self::isWebUrl($url) ? $url : null;
    }

    private static function isWebUrl(string $url): bool
    {
        return str_starts_with($url, 'https://') || str_starts_with($url, 'http://');
    }
}
