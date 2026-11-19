<?php

declare(strict_types=1);

namespace Threadlive\Http\Controllers;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Threadlive\Contracts\ChatHost;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Delivery;
use Threadlive\Enums\Direction;
use Threadlive\Events\ConversationStarted;
use Threadlive\Events\VisitorMessageReceived;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;
use Threadlive\Support\Attachments;
use Threadlive\Support\Visitor;
use Threadlive\Support\WorkingHours;

/**
 * The widget's API. Every message here is shown to visitors as written, so
 * each is a sentence.
 */
final class VisitorController
{
    /** New conversations one IP may start per window: the stricter limit on creation. */
    private const STARTS_PER_WINDOW = 5;

    private const START_WINDOW_SECONDS = 600;

    /** The most a full-history request returns. */
    private const HISTORY_LIMIT = 500;

    public function __construct(private readonly ChatHost $host) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $hours = WorkingHours::fromConfig();
        $visitor = Visitor::resolve($request, $this->host);
        $pollMs = (array) config('threadlive.poll_ms');

        return response()->json([
            'enabled' => (bool) config('threadlive.enabled'),
            'online' => $hours->isOpen(now()),
            'hours' => $hours->toArray(),
            'can_write' => $visitor->canWrite(),
            'poll_ms' => [
                'open' => (int) ($pollMs['open'] ?? 3000),
                'closed' => (int) ($pollMs['closed'] ?? 30000),
                'idle' => (int) ($pollMs['idle'] ?? 15000),
                'idle_after' => (int) ($pollMs['idle_after'] ?? 300000),
            ],
            'topics' => array_values(array_filter(
                (array) config('threadlive.topics', []),
                fn ($topic) => is_string($topic) && trim($topic) !== '',
            )),
            'max_message_length' => $this->maxLength(),
            'attachments' => [
                'enabled' => Attachments::enabled(),
                'max_kb' => Attachments::maxKb(),
                'max_files' => Attachments::maxFiles(),
                'accept' => Attachments::accept(),
            ],
            'agent_display_name' => (string) config('threadlive.agent_display_name', 'Support'),
            'conversation' => $visitor->conversation ? $this->conversationJson($visitor->conversation) : null,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $conversation = $this->conversationOr404($request);
        $after = $request->query('after');

        $query = $conversation->messages()->where('direction', '!=', Direction::Note);

        if (is_numeric($after)) {
            $messages = $query->where('id', '>', (int) $after)->orderBy('id')->get();
        } else {
            $messages = $query->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get()->reverse()->values();
        }

        return response()->json([
            'messages' => $messages->map(fn (Message $m) => $this->messageJson($m))->all(),
            'conversation' => $this->conversationJson($conversation),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // With files the widget posts multipart, and the context comes as JSON text.
        if (is_string($request->input('context'))) {
            $request->merge(['context' => json_decode((string) $request->input('context'), true)]);
        }

        if ($request->hasFile('files') && ! Attachments::enabled()) {
            abort(422, 'Files cannot be sent here.');
        }

        $max = $this->maxLength();
        $maxMb = round(Attachments::maxKb() / 1024, 1);
        $data = $request->validate([
            'body' => ['nullable', 'required_without:files', 'string', 'max:'.$max],
            'client_msg_id' => ['required', 'uuid'],
            'context' => ['nullable', 'array'],
            'context.url' => ['nullable', 'string', 'max:2048'],
            'context.title' => ['nullable', 'string', 'max:300'],
            'context.attributes' => ['nullable', 'array', 'max:20'],
            'files' => ['nullable', 'array', 'max:'.Attachments::maxFiles()],
            // mimes: judged by the content, not by the name.
            'files.*' => ['file', 'max:'.Attachments::maxKb(), 'mimes:'.implode(',', Attachments::types())],
        ], [
            'body.required_without' => 'Write a message first.',
            'body.max' => 'The message may not be longer than :max characters.',
            'client_msg_id.*' => 'The message could not be sent. Reload the page and try again.',
            'context.*' => 'The message could not be sent. Reload the page and try again.',
            'files.max' => 'Up to :max files in one message.',
            'files.*.max' => "Each file may be up to {$maxMb} MB.",
            'files.*.mimes' => 'Send '.Attachments::typesForPeople().' files.',
            'files.*.file' => 'The file did not arrive. Try again.',
        ]);

        /** @var list<UploadedFile> $files */
        $files = array_values(array_filter((array) $request->file('files', []), fn ($f) => $f instanceof UploadedFile));

        $visitor = Visitor::resolve($request, $this->host);

        if (! $visitor->canWrite()) {
            abort(403, 'Sign in to write to us.');
        }

        $clientMsgId = (string) $data['client_msg_id'];
        $body = (string) ($data['body'] ?? '');
        $client = $this->clientContext(is_array($data['context'] ?? null) ? $data['context'] : []);

        // A retry of a send that did arrive: answer with what is stored.
        if ($visitor->conversation !== null) {
            $existing = $this->existing($visitor->conversation, $clientMsgId);
            if ($existing !== null) {
                return $this->stored($visitor->conversation, $existing, null, null, 200);
            }
        }

        if ($visitor->conversation === null) {
            $key = 'threadlive:start:'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, self::STARTS_PER_WINDOW)) {
                abort(429, 'Too many new conversations from here. Try again in a few minutes.');
            }
            RateLimiter::hit($key, self::START_WINDOW_SECONDS);
        }

        // Asked here, while there is a request: the topic is posted to later,
        // from the queue, where there is none.
        $line = $this->host->contextLine($request, $visitor->user, $client);
        $managerCard = [
            'lines' => $this->host->cardLines($request, $visitor->user, $client),
            'admin_url' => $this->host->adminUrl($visitor->user),
        ];

        try {
            [$conversation, $card, $message, $token, $started] = DB::transaction(
                fn () => $this->record($request, $visitor, $body, $clientMsgId, $client, $line, $managerCard, $files),
            );
        } catch (UniqueConstraintViolationException) {
            // The same retry arrived twice at once; the other one stored it.
            $conversation = $visitor->conversation ?? Visitor::resolve($request, $this->host)->conversation;
            $existing = $conversation ? $this->existing($conversation, $clientMsgId) : null;
            abort_if($conversation === null || $existing === null, 409, 'The message could not be sent. Try again.');

            return $this->stored($conversation, $existing, null, null, 200);
        }

        if ($started) {
            event(new ConversationStarted($conversation));
        }
        event(new VisitorMessageReceived($conversation, $message, $card));

        return $this->stored($conversation, $message, $card, $token, 201);
    }

    public function read(Request $request): Response
    {
        $data = $request->validate(['message_id' => ['required', 'integer', 'min:1']]);
        $conversation = $this->conversationOr404($request);
        $id = (int) $data['message_id'];

        if ($id > ($conversation->visitor_read_message_id ?? 0) && $conversation->messages()->whereKey($id)->exists()) {
            $conversation->forceFill(['visitor_read_message_id' => $id])->save();
        }

        return response()->noContent();
    }

    public function email(Request $request): Response
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'name' => ['nullable', 'string', 'max:100'],
        ], [
            'email.*' => 'Enter a valid e-mail address.',
            'name.*' => 'The name may not be longer than 100 characters.',
        ]);

        $conversation = $this->conversationOr404($request);
        $conversation->forceFill([
            'email' => $data['email'],
            'name' => $data['name'] ?? $conversation->name,
        ])->save();

        return response()->noContent();
    }

    /**
     * @param  array{url?: string, title?: string, attributes?: array<string, string>}  $client
     * @param  array{lines: list<array{label: string, value: string, url?: string}>, admin_url: string|null}  $managerCard
     * @param  list<UploadedFile>  $files
     * @return array{Conversation, ?Message, Message, ?string, bool}
     */
    private function record(Request $request, Visitor $visitor, string $body, string $clientMsgId, array $client, ?string $line, array $managerCard, array $files): array
    {
        $token = null;
        $started = false;
        $conversation = $visitor->conversation;

        if ($conversation === null) {
            $started = true;
            // A guest gets a token, returned this once; only its hash is kept.
            $token = $visitor->user === null ? Str::random(48) : null;

            $conversation = Conversation::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_ref' => $visitor->user?->id,
                'visitor_token_hash' => $token === null ? null : hash('sha256', $token),
                'name' => $visitor->user?->name,
                'email' => $visitor->user?->email,
                'locale' => $request->getPreferredLanguage(),
            ]);
        }

        // A card when what the conversation is about changes — before the
        // message, so the history reads in order.
        $card = null;
        $line = $line === null ? null : trim($line);
        if ($line !== null && $line !== '') {
            $lastLine = $conversation->messages()->where('direction', Direction::Context)->latest('id')->value('body');
            if ($lastLine !== $line) {
                $card = $conversation->messages()->create([
                    'direction' => Direction::Context,
                    'body' => $line,
                    'delivery' => Delivery::Pending, // the topic shows it too
                ]);
            }
        }

        $message = $conversation->messages()->create([
            'direction' => Direction::Visitor,
            'body' => $body,
            'client_msg_id' => $clientMsgId,
            'delivery' => Delivery::Pending,
            'attachments' => $files === []
                ? null
                : array_map(fn (UploadedFile $file) => Attachments::storeUpload($file, $conversation), $files),
        ]);

        $wasWaiting = ! $started && $conversation->status === ConversationStatus::Waiting;
        $now = now();

        $conversation->forceFill([
            'status' => ConversationStatus::Waiting,
            'closed_at' => null,
            'closed_by' => null,
            'last_visitor_message_at' => $now,
            'last_activity_at' => $now,
            'context' => $client + [
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'card' => $managerCard,
            ],
        ]);

        // A new wait starts the waiting alert's clock again.
        if (! $wasWaiting) {
            $conversation->waiting_alert_sent_at = null;
        }

        $conversation->save();

        return [$conversation, $card, $message, $token, $started];
    }

    private function stored(Conversation $conversation, Message $message, ?Message $card, ?string $token, int $status): JsonResponse
    {
        $payload = [
            'message' => $this->messageJson($message),
            'context' => $card ? $this->messageJson($card) : null,
            'conversation' => $this->conversationJson($conversation),
        ];

        if ($token !== null) {
            $payload['visitor_token'] = $token;
        }

        return response()->json($payload, $status);
    }

    private function existing(Conversation $conversation, string $clientMsgId): ?Message
    {
        return $conversation->messages()->where('client_msg_id', $clientMsgId)->first();
    }

    /** 404, not 403: a token or user with no conversation learns nothing. */
    private function conversationOr404(Request $request): Conversation
    {
        $conversation = Visitor::resolve($request, $this->host)->conversation;
        abort_if($conversation === null, 404, 'There is no conversation yet.');

        return $conversation;
    }

    /**
     * Only what the widget sends, strings only, bounded: the host reads it
     * and it is stored.
     *
     * @param  array<mixed>  $context
     * @return array{url?: string, title?: string, attributes?: array<string, string>}
     */
    private function clientContext(array $context): array
    {
        $client = [];

        foreach (['url', 'title'] as $key) {
            if (is_string($context[$key] ?? null) && $context[$key] !== '') {
                $client[$key] = $context[$key];
            }
        }

        $attributes = [];
        foreach ((array) ($context['attributes'] ?? []) as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $attributes[Str::limit($name, 64, '')] = Str::limit((string) $value, 500, '');
            }
        }

        if ($attributes !== []) {
            $client['attributes'] = $attributes;
        }

        return $client;
    }

    private function maxLength(): int
    {
        return max(1, (int) config('threadlive.max_message_length', 2000));
    }

    /**
     * @return array{status: string, unread: int, email: string|null, active: bool}
     */
    private function conversationJson(Conversation $conversation): array
    {
        return [
            'status' => $conversation->status->value,
            'unread' => $conversation->unreadCount(),
            'email' => $conversation->email,
            'active' => $this->isLive($conversation),
        ];
    }

    /** Worth polling for with the panel closed: not closed, and recent. */
    private function isLive(Conversation $conversation): bool
    {
        $hours = max(0, (int) config('threadlive.live_hours', 24));

        return $conversation->status !== ConversationStatus::Closed
            && $conversation->last_activity_at !== null
            && $conversation->last_activity_at->gt(now()->subHours($hours));
    }

    /**
     * @return array{id: int, direction: string, body: string, created_at: string|null, author: string|null, client_msg_id?: string|null, attachments?: list<array<string, mixed>>}
     */
    private function messageJson(Message $message): array
    {
        // A reply translated for the visitor (/translate) reaches them in
        // their language; the manager's original stays in the topic.
        $body = $message->direction === Direction::Agent && is_string($message->translation['text'] ?? null)
            ? $message->translation['text']
            : $message->body;

        $json = [
            'id' => $message->id,
            'direction' => $message->direction->value,
            'body' => $body,
            'created_at' => $message->created_at?->toIso8601ZuluString('millisecond'),
            'author' => $message->direction === Direction::Agent && config('threadlive.show_agent_name', true)
                ? $message->agent_name
                : null,
        ];

        if ($message->direction === Direction::Visitor) {
            $json['client_msg_id'] = $message->client_msg_id;
        }

        if ($message->attachments !== null && $message->attachments !== []) {
            $json['attachments'] = array_map(
                fn (array $file) => Attachments::forVisitor($message, $file),
                array_values($message->attachments),
            );
        }

        return $json;
    }
}
