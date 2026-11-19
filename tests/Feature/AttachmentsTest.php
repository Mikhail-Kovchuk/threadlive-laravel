<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Threadlive\Enums\Direction;
use Threadlive\Enums\TopicState;
use Threadlive\Jobs\DeliverVisitorMessages;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\Topics;

/**
 * POST a message with files, multipart, as the widget does.
 *
 * @param  list<UploadedFile>  $files
 */
function sendFiles(array $files, string $body = '', ?string $token = null)
{
    return test()->post('/threadlive/messages', [
        'body' => $body,
        'client_msg_id' => (string) Str::uuid(),
        'context' => json_encode(['url' => 'https://example.com/', 'title' => 'Home', 'attributes' => []]),
        'files' => $files,
    ], ['Accept' => 'application/json'] + ($token ? ['X-Threadlive-Token' => $token] : []));
}

function deliverFiles(): void
{
    $conversation = Conversation::query()->firstOrFail();
    (new DeliverVisitorMessages($conversation->id))->handle(app(Topics::class), app(TelegramClient::class));
}

beforeEach(fn () => Storage::fake('local'));

describe('a visitor sending files', function () {
    it('stores them privately and gives the widget signed links to them', function () {
        $response = sendFiles([UploadedFile::fake()->image('cashier.png', 400, 300)], 'Here is the cashier screen')
            ->assertCreated()
            ->assertJsonPath('message.body', 'Here is the cashier screen')
            ->assertJsonPath('message.attachments.0.name', 'cashier.png')
            ->assertJsonPath('message.attachments.0.kind', 'image')
            ->assertJsonPath('message.attachments.0.mime', 'image/png');

        $stored = Message::query()->firstOrFail()->attachments[0] ?? [];
        expect($stored['path'])->toStartWith('threadlive/')
            ->and(Storage::disk('local')->exists($stored['path']))->toBeTrue();

        $url = $response->json('message.attachments.0.url');
        expect($url)->toStartWith('/threadlive/files/');

        $this->get($url)->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->get($url.'x')->assertForbidden();
        $this->get(Str::before($url, '?'))->assertForbidden();
    });

    it('sends a file without any text', function () {
        sendFiles([UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf')])
            ->assertCreated()
            ->assertJsonPath('message.body', '')
            ->assertJsonPath('message.attachments.0.kind', 'file');
    });

    it('refuses what is not allowed, in words for the visitor', function () {
        sendFiles(array_map(fn ($i) => UploadedFile::fake()->image("s{$i}.png"), range(1, 6)))
            ->assertUnprocessable()->assertJsonPath('message', 'Up to 5 files in one message.');

        sendFiles([UploadedFile::fake()->create('setup.exe', 10, 'application/x-msdownload')])
            ->assertUnprocessable()->assertJsonPath('message', 'Send PNG, JPG, WEBP, GIF or PDF files.');

        sendFiles([UploadedFile::fake()->create('huge.pdf', 11000, 'application/pdf')])
            ->assertUnprocessable()->assertJsonPath('message', 'Each file may be up to 10 MB.');

        sendFiles([])->assertUnprocessable()->assertJsonPath('message', 'Write a message first.');
    });

    it('takes no files when they are turned off', function () {
        config()->set('threadlive.attachments.enabled', false);

        $this->getJson('/threadlive/bootstrap')->assertJsonPath('attachments.enabled', false);
        sendFiles([UploadedFile::fake()->image('a.png')])->assertUnprocessable()->assertJsonPath('message', 'Files cannot be sent here.');
    });

    it('tells the widget what it may attach', function () {
        $this->getJson('/threadlive/bootstrap')->assertJsonPath('attachments', [
            'enabled' => true,
            'max_kb' => 10240,
            'max_files' => 5,
            'accept' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'application/pdf'],
        ]);
    });
});

describe('files in the topic', function () {
    it('posts the text, then a photo, silently after the text', function () {
        fakeTelegram();
        sendFiles([UploadedFile::fake()->image('cashier.png')], 'Here is the screen');

        deliverFiles();

        expect(collect(telegramCalls('sendMessage'))->last()['text'])->toBe('Here is the screen');
        $photo = multipart(telegramCalls('sendPhoto')[0]);
        expect($photo['photo']['filename'])->toBe('cashier.png')
            ->and($photo['message_thread_id'])->toBe('101')
            ->and($photo['disable_notification'])->toBe('true');
    });

    it('sends a PDF as a document, with the sound when there is no text', function () {
        fakeTelegram();
        sendFiles([UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf')]);

        deliverFiles();

        $document = multipart(telegramCalls('sendDocument')[0]);
        expect($document['document']['filename'])->toBe('invoice.pdf')
            ->and($document['disable_notification'])->toBe('false')
            ->and(Message::query()->where('direction', Direction::Visitor)->first()?->delivery?->value)->toBe('sent');
    });

    it('sends a photo Telegram refuses as a document instead', function () {
        fakeTelegram(['sendPhoto' => ['error' => 'Bad Request: PHOTO_INVALID_DIMENSIONS']]);
        sendFiles([UploadedFile::fake()->image('panorama.png', 4000, 100)]);

        deliverFiles();

        expect(multipart(telegramCalls('sendDocument')[0])['document']['filename'])->toBe('panorama.png');
    });
});

describe('a manager sending files', function () {
    function topicConversation(): array
    {
        $token = sendMessage('Hello')->json('visitor_token');
        $conversation = Conversation::query()->firstOrFail();
        $conversation->forceFill(['telegram_thread_id' => 300, 'topic_state' => TopicState::Open])->save();

        return [$conversation, $token];
    }

    it('passes a photo on to the visitor, with its caption', function () {
        fakeTelegram();
        [$conversation, $token] = topicConversation();

        $update = managerMessage(300, '', 5200);
        unset($update['text']);
        $update['caption'] = 'This is the right screen';
        $update['photo'] = [
            ['file_id' => 'small', 'file_size' => 900, 'width' => 90, 'height' => 60],
            ['file_id' => 'large', 'file_size' => 90000, 'width' => 1280, 'height' => 853],
        ];

        telegramUpdate(['message' => $update])->assertOk();

        expect(telegramCalls('getFile')[0]['file_id'])->toBe('large');

        $reply = collect($this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->json('messages'))
            ->firstWhere('direction', 'agent');
        expect($reply['body'])->toBe('This is the right screen')
            ->and($reply['attachments'][0]['kind'])->toBe('image');

        $this->get($reply['attachments'][0]['url'])->assertOk();
        expect($conversation->messages()->where('direction', Direction::Agent)->first()?->attachments[0]['size'])->toBe(strlen('fake-file-bytes'));
    });

    it('keeps a file type the visitor may not get out of the conversation, and says why', function () {
        fakeTelegram();
        [$conversation] = topicConversation();

        $update = managerMessage(300, '', 5201);
        unset($update['text']);
        $update['document'] = ['file_id' => 'zip', 'file_name' => 'logs.zip', 'mime_type' => 'application/zip', 'file_size' => 2000];

        telegramUpdate(['message' => $update])->assertOk();

        expect($conversation->messages()->where('direction', Direction::Agent)->count())->toBe(0)
            ->and(telegramCalls('getFile'))->toBeEmpty()
            ->and(telegramCalls('sendMessage')[0]['text'])->toBe('Not sent to the visitor: send PNG, JPG, WEBP, GIF or PDF files.');
    });
});
