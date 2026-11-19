<?php

declare(strict_types=1);

namespace Threadlive\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Threadlive\Enums\Direction;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;

/**
 * The replies a visitor has not read in the widget, sent to their e-mail
 * by threadlive:maintain. The view is publishable (threadlive-views).
 */
final class UnreadReplies extends Mailable
{
    /**
     * @param  Collection<int, Message>  $replies  agent messages, oldest first
     */
    public function __construct(
        public readonly Conversation $conversation,
        public readonly Collection $replies,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('A reply from :name', ['name' => self::teamName()]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'threadlive::mail.unread-replies',
            text: 'threadlive::mail.unread-replies-text',
            with: [
                'team' => self::teamName(),
                'messages' => $this->replies->map(fn (Message $m) => $this->reply($m))->all(),
                'link' => $this->link(),
            ],
        );
    }

    private static function teamName(): string
    {
        $name = config('threadlive.agent_display_name');

        return is_string($name) && $name !== '' ? $name : 'Support';
    }

    /**
     * As the widget shows it: a translated reply in the visitor's language,
     * files as links (signed, like the widget's).
     *
     * @return array{author: string|null, body: string, files: list<array{name: string, url: string}>}
     */
    private function reply(Message $message): array
    {
        $body = $message->direction === Direction::Agent && is_string($message->translation['text'] ?? null)
            ? $message->translation['text']
            : $message->body;

        $files = [];
        foreach (array_values($message->attachments ?? []) as $file) {
            $files[] = [
                'name' => is_string($file['name'] ?? null) ? $file['name'] : 'file',
                // Signed as relative — what the files route checks — then made absolute.
                'url' => url(URL::temporarySignedRoute(
                    'threadlive.files.show',
                    now()->addDays(7),
                    ['message' => $message->id, 'file' => (string) ($file['id'] ?? '')],
                    absolute: false,
                )),
            ];
        }

        return [
            'author' => config('threadlive.show_agent_name', true) ? $message->agent_name : null,
            'body' => $body,
            'files' => $files,
        ];
    }

    /**
     * config('threadlive.email_link'), or the page the visitor wrote from
     * when it is on the same host. Never any other page a visitor names: the
     * e-mail goes to an address nobody confirmed.
     */
    private function link(): ?string
    {
        $site = config('threadlive.email_link');
        if (! is_string($site) || ! preg_match('#^https?://#i', $site)) {
            return null;
        }

        $page = $this->conversation->context['url'] ?? null;
        $siteHost = parse_url($site, PHP_URL_HOST);

        if (is_string($page) && preg_match('#^https?://#i', $page) && is_string($siteHost)
            && strcasecmp((string) parse_url($page, PHP_URL_HOST), $siteHost) === 0) {
            return $page;
        }

        return $site;
    }
}
