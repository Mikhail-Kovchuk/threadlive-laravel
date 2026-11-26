<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Threadlive\Enums\TopicState;
use Threadlive\Gemini\GeminiClient;
use Threadlive\Gemini\GeminiException;
use Threadlive\Jobs\DeliverVisitorMessages;
use Threadlive\Models\Conversation;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\Topics;
use Threadlive\Tests\Fixtures\FakeTranslator;
use Threadlive\Translation\DeepLTranslator;
use Threadlive\Translation\GeminiTranslator;
use Threadlive\Translation\TranslationService;

/** A guest conversation in topic 300, written in Spanish; returns the token. */
function spanishConversation(): array
{
    $token = sendMessage('Hola, mi pedido sigue pendiente.')->json('visitor_token');
    $conversation = Conversation::query()->firstOrFail();
    $conversation->forceFill(['telegram_thread_id' => 300, 'topic_state' => TopicState::Open])->save();

    return [$conversation, $token];
}

function deliverNow(Conversation $conversation): void
{
    (new DeliverVisitorMessages($conversation->id))->handle(app(Topics::class), app(TelegramClient::class));
}

beforeEach(function () {
    FakeTranslator::reset();
    config()->set('threadlive.translation.driver', FakeTranslator::class);
    config()->set('threadlive.translation.languages', ['en', 'ru']);
});

it('turns translation on for one conversation with /translate, showing the last message', function () {
    fakeTelegram();
    [$conversation] = spanishConversation();

    telegramUpdate(['message' => managerMessage(300, '/translate')])->assertOk();

    $conversation->refresh();
    expect($conversation->translate_to)->toBe('en')
        ->and($conversation->visitor_language)->toBe('es');

    $answer = telegramCalls('sendMessage')[0]['text'];
    expect($answer)->toContain('translation into <b>EN</b>')
        ->and($answer)->toContain('Your replies go out in <b>ES</b>')
        ->and($answer)->toContain('[en] Hola, mi pedido sigue pendiente.');
});

it('switches language with /translate ru, refuses one not configured, and turns off', function () {
    fakeTelegram();
    [$conversation] = spanishConversation();

    telegramUpdate(['message' => managerMessage(300, '/translate ru', 5001)]);
    expect($conversation->refresh()->translate_to)->toBe('ru');

    telegramUpdate(['message' => managerMessage(300, '/translate de', 5002)]);
    expect($conversation->refresh()->translate_to)->toBe('ru')
        ->and(telegramCalls('sendMessage')[1]['text'])->toBe('Choose one of: en, ru — or /translate off.');

    telegramUpdate(['message' => managerMessage(300, '/translate', 5003)]);
    expect($conversation->refresh()->translate_to)->toBeNull();
});

it('says translation is not set up when there is no driver', function () {
    fakeTelegram();
    config()->set('threadlive.translation.driver', null);
    [$conversation] = spanishConversation();

    telegramUpdate(['message' => managerMessage(300, '/translate')]);

    expect($conversation->refresh()->translate_to)->toBeNull()
        ->and(telegramCalls('sendMessage')[0]['text'])->toContain('Translation is not set up');
});

it('posts the visitor\'s message with its translation, once', function () {
    fakeTelegram();
    [$conversation, $token] = spanishConversation();
    $conversation->forceFill(['translate_to' => 'en'])->save();

    sendMessage('Hola otra vez', $token);
    deliverNow($conversation);
    deliverNow($conversation); // nothing pending: nothing translated again

    $posted = collect(telegramCalls('sendMessage'))->last()['text'];
    expect($posted)->toBe("<blockquote>Hola, mi pedido sigue pendiente.\n\nHola otra vez</blockquote>\n🌐 <b>ES → EN</b>\n"
        ."[en] Hola, mi pedido sigue pendiente.\n\n[en] Hola otra vez")
        ->and(FakeTranslator::$calls)->toHaveCount(2)
        ->and($conversation->refresh()->visitor_language)->toBe('es');
});

describe('translation.auto', function () {
    it('stays off by default', function () {
        fakeTelegram();
        [$conversation] = spanishConversation();

        deliverNow($conversation);

        expect($conversation->refresh()->translate_to)->toBeNull()
            ->and(FakeTranslator::$calls)->toBe([]);
    });

    it('turns translation on for a visitor writing in another language, and says so in the topic', function () {
        fakeTelegram();
        config()->set('threadlive.translation.auto', true);
        [$conversation] = spanishConversation();

        deliverNow($conversation);

        $conversation->refresh();
        expect($conversation->translate_to)->toBe('en')
            ->and($conversation->visitor_language)->toBe('es')
            ->and(FakeTranslator::$calls)->toHaveCount(1);

        [$message, $notice] = array_slice(telegramCalls('sendMessage'), -2);
        expect($message['text'])->toContain("🌐 <b>ES → EN</b>\n[en] Hola, mi pedido sigue pendiente.")
            ->and($notice['text'])->toStartWith('🌐 Translation on automatically: <b>ES → EN</b>. Your replies go out in <b>ES</b>.')
            ->and($notice['message_thread_id'])->toBe(300)
            ->and($notice['disable_notification'])->toBeTrue();
    });

    it('notes a visitor writing in the managers\' language, and asks no more', function () {
        fakeTelegram();
        config()->set('threadlive.translation.auto', true);
        $token = sendMessage('My order is still pending')->json('visitor_token');
        $conversation = Conversation::query()->firstOrFail();
        $conversation->forceFill(['telegram_thread_id' => 300, 'topic_state' => TopicState::Open])->save();

        deliverNow($conversation);
        sendMessage('Hola, ¿hay alguien?', $token);
        deliverNow($conversation);

        expect($conversation->refresh()->translate_to)->toBeNull()
            ->and($conversation->visitor_language)->toBe('en')
            ->and(FakeTranslator::$calls)->toHaveCount(1)
            ->and(collect(telegramCalls('sendMessage'))->pluck('text')->implode("\n"))->not->toContain('automatically');
    });

    it('leaves translation off once a manager turned it off', function () {
        fakeTelegram();
        config()->set('threadlive.translation.auto', true);
        [$conversation, $token] = spanishConversation();
        deliverNow($conversation);

        telegramUpdate(['message' => managerMessage(300, '/translate off', 5001)]);
        sendMessage('Hola otra vez', $token);
        deliverNow($conversation);

        expect($conversation->refresh()->translate_to)->toBeNull()
            ->and(collect(telegramCalls('sendMessage'))->last()['text'])->toBe('Hola otra vez');
    });

    it('waits for a message long enough to tell, or for the translator to answer', function () {
        fakeTelegram();
        config()->set('threadlive.translation.auto', true);
        $token = sendMessage('ok')->json('visitor_token');
        $conversation = Conversation::query()->firstOrFail();
        $conversation->forceFill(['telegram_thread_id' => 300, 'topic_state' => TopicState::Open])->save();

        deliverNow($conversation);
        expect(FakeTranslator::$calls)->toBe([]);

        FakeTranslator::$down = true;
        sendMessage('Hola, mi pedido sigue pendiente.', $token);
        deliverNow($conversation);
        expect($conversation->refresh()->visitor_language)->toBeNull();

        FakeTranslator::$down = false;
        sendMessage('Hola otra vez', $token);
        deliverNow($conversation);
        expect($conversation->refresh()->translate_to)->toBe('en');
    });
});

it('collapses a long original, so the translation stays in view', function () {
    fakeTelegram();
    [$conversation, $token] = spanishConversation();
    $conversation->forceFill(['translate_to' => 'en'])->save();
    $conversation->messages()->update(['delivery' => 'sent']);

    sendMessage('Hola '.str_repeat('muy largo ', 40), $token);
    deliverNow($conversation);

    expect(collect(telegramCalls('sendMessage'))->last()['text'])->toStartWith('<blockquote expandable>Hola muy largo');
});

it('adds no translation to a message already in the managers\' language', function () {
    fakeTelegram();
    [$conversation, $token] = spanishConversation();
    $conversation->forceFill(['translate_to' => 'en'])->save();
    $conversation->messages()->update(['delivery' => 'sent']);

    sendMessage('Thanks, all good now', $token);
    deliverNow($conversation);

    expect(collect(telegramCalls('sendMessage'))->last()['text'])->toBe('Thanks, all good now');
});

it('sends the visitor a manager\'s reply in their language, and shows the manager what went out', function () {
    fakeTelegram();
    [$conversation, $token] = spanishConversation();
    $conversation->forceFill(['translate_to' => 'en', 'visitor_language' => 'es'])->save();

    telegramUpdate(['message' => managerMessage(300, 'Your order is being checked', 5100)])->assertOk();

    $reply = collect($this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->json('messages'))
        ->firstWhere('direction', 'agent');
    expect($reply['body'])->toBe('[es] Your order is being checked');

    $note = collect(telegramCalls('sendMessage'))->last();
    expect($note['text'])->toBe("🌐 <b>→ ES</b>\n<blockquote>[es] Your order is being checked</blockquote>\n")
        ->and($note['reply_parameters']['message_id'])->toBe(5100)
        ->and($note['disable_notification'])->toBeTrue();

    // Edited: the visitor gets the new text, translated.
    telegramUpdate(['edited_message' => managerMessage(300, 'Your order was approved', 5100)]);
    $reply = collect($this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->json('messages'))
        ->firstWhere('direction', 'agent');
    expect($reply['body'])->toBe('[es] Your order was approved');
});

it('never shows the visitor a reply before its translation', function () {
    fakeTelegram();
    [$conversation, $token] = spanishConversation();
    $conversation->forceFill(['translate_to' => 'en', 'visitor_language' => 'es'])->save();
    $seen = null;
    // What a poll would find while the translator is still working.
    FakeTranslator::$before = function () use (&$seen, $conversation) {
        $seen = $conversation->messages()->where('direction', 'agent')->pluck('body')->all();
    };

    telegramUpdate(['message' => managerMessage(300, 'Checking it now', 5100)]);

    expect($seen)->toBe([])
        ->and($conversation->messages()->where('direction', 'agent')->firstOrFail()->translation['text'] ?? null)
        ->toBe('[es] Checking it now');
});

it('keeps DeepL off until there is a key', function () {
    config()->set('threadlive.translation.driver', 'deepl');
    config()->set('threadlive.translation.key', '');

    expect(app(TranslationService::class)->enabled())->toBeFalse();

    config()->set('threadlive.translation.key', 'abc:fx');
    expect(app(TranslationService::class)->enabled())->toBeTrue();
});

it('sends the reply as written when the translator fails', function () {
    fakeTelegram();
    [$conversation, $token] = spanishConversation();
    $conversation->forceFill(['translate_to' => 'en', 'visitor_language' => 'es'])->save();
    FakeTranslator::$down = true;

    telegramUpdate(['message' => managerMessage(300, 'Your order is being checked', 5100)])->assertOk();

    $reply = collect($this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->json('messages'))
        ->firstWhere('direction', 'agent');
    expect($reply['body'])->toBe('Your order is being checked')
        ->and(collect(telegramCalls('sendMessage'))->filter(fn ($m) => str_contains($m['text'], '🌐')))->toBeEmpty();
});

it('lists /translate in the group\'s menu when translation is set up', function () {
    url()->forceRootUrl('http://127.0.0.1:8020');
    fakeTelegram([
        'getMe' => ['id' => 123, 'is_bot' => true, 'username' => 'support_test_bot'],
        'getChat' => ['id' => -1001234567890, 'title' => 'Support', 'type' => 'supergroup', 'is_forum' => true],
        'getChatMember' => ['status' => 'administrator', 'can_manage_topics' => true, 'can_delete_messages' => true, 'can_pin_messages' => true],
    ]);

    $this->artisan('threadlive:setup', ['--local' => true])->assertSuccessful();

    expect(collect(telegramCalls('setMyCommands')[0]['commands'])->pluck('command')->all())->toBe(['menu', 'close', 'translate', 'faq', 'find']);
});

describe('DeepL', function () {
    it('calls the free API for a :fx key, and reads the detected language', function () {
        Http::fake(['api-free.deepl.com/*' => Http::response([
            'translations' => [['detected_source_language' => 'ES', 'text' => 'Hello, my order is still pending.']],
        ])]);

        $translation = (new DeepLTranslator(app(Factory::class), 'key-123:fx'))
            ->translate('Hola, mi pedido sigue pendiente.', 'en');

        expect($translation?->text)->toBe('Hello, my order is still pending.')
            ->and($translation?->source)->toBe('es')
            ->and($translation?->target)->toBe('en');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api-free.deepl.com/v2/translate'
            && $request->header('Authorization')[0] === 'DeepL-Auth-Key key-123:fx'
            && $request['target_lang'] === 'EN-US'
            && $request['text'] === ['Hola, mi pedido sigue pendiente.']);
    });

    it('calls the Pro API for a Pro key', function () {
        Http::fake(['api.deepl.com/*' => Http::response(['translations' => [['detected_source_language' => 'EN', 'text' => 'Hola']]])]);

        (new DeepLTranslator(app(Factory::class), 'pro-key'))->translate('Hello', 'es');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.deepl.com/v2/translate'
            && $request['target_lang'] === 'ES');
    });

    it('gives no translation, not an error, when the month\'s quota is used up', function () {
        Http::fake(['api-free.deepl.com/*' => Http::response(['message' => 'Quota exceeded'], 456)]);

        expect((new DeepLTranslator(app(Factory::class), 'k:fx'))->translate('Hola', 'en'))->toBeNull();
    });
});

/** Gemini's answer: $json as the text of one candidate. */
function geminiAnswer(array $json, array $extraParts = []): array
{
    return ['candidates' => [['content' => ['role' => 'model', 'parts' => [
        ...$extraParts,
        ['text' => json_encode($json)],
    ]]]]];
}

function geminiTranslator(string $key = 'g-key', ?string $thinking = 'low'): GeminiTranslator
{
    return new GeminiTranslator(new GeminiClient(app(Factory::class), $key, 'gemini-3-flash-preview', $thinking));
}

describe('Gemini', function () {
    it('translates, reads the detected language, and keeps the text out of the instructions', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiAnswer([
            'source' => 'ES', 'text' => 'Hello, my order is still pending.',
        ]))]);

        $translation = geminiTranslator()->translate('Hola, mi pedido sigue pendiente.', 'EN-US');

        expect($translation?->text)->toBe('Hello, my order is still pending.')
            ->and($translation?->source)->toBe('es')
            ->and($translation?->target)->toBe('en');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent'
            && $request->header('x-goog-api-key')[0] === 'g-key'
            && ! str_contains($request->url(), 'key=')
            && $request['contents'][0]['parts'][0]['text'] === 'Hola, mi pedido sigue pendiente.'
            && str_contains($request['systemInstruction']['parts'][0]['text'], '"en"')
            && ! str_contains($request['systemInstruction']['parts'][0]['text'], 'pedido')
            && $request['generationConfig']['responseMimeType'] === 'application/json'
            && $request['generationConfig']['thinkingConfig'] === ['thinkingLevel' => 'low']);
    });

    it('sends no thinking level when none is set', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiAnswer(['source' => 'en', 'text' => 'Hola']))]);

        geminiTranslator(thinking: null)->translate('Hello', 'es');

        Http::assertSent(fn (Request $request) => ! isset($request['generationConfig']['thinkingConfig']));
    });

    it('skips thought parts', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiAnswer(
            ['source' => 'es', 'text' => 'Hello'],
            [['text' => 'Thinking about it…', 'thought' => true]],
        ))]);

        expect(geminiTranslator()->translate('Hola', 'en')?->text)->toBe('Hello');
    });

    it('gives no translation, not an error, when rate-limited or out of quota', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 429, 'message' => 'Resource exhausted']], 429)]);

        expect(geminiTranslator()->translate('Hola', 'en'))->toBeNull();
    });

    it('throws on other errors and on a blocked or malformed answer, without the text in the message', function (int $status, array $body) {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body, $status)]);

        try {
            geminiTranslator()->translate('Mi contraseña es secreta', 'en');
            $this->fail('No exception');
        } catch (GeminiException $e) {
            expect($e->getMessage())->not->toContain('contraseña');
        }
    })->with([
        'bad key' => [400, ['error' => ['code' => 400, 'message' => 'API key not valid.']]],
        'blocked' => [200, ['promptFeedback' => ['blockReason' => 'SAFETY']]],
        'not JSON' => [200, ['candidates' => [['content' => ['parts' => [['text' => 'Sure! Here it is']]]]]]],
        'empty' => [200, geminiAnswer(['source' => 'es', 'text' => ' '])],
    ]);

    it('is the translator for driver gemini, off until there is a key', function () {
        config()->set('threadlive.translation.driver', 'gemini');
        config()->set('threadlive.gemini.key', '');

        expect(app(TranslationService::class)->enabled())->toBeFalse();

        config()->set('threadlive.gemini.key', 'g-key');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiAnswer(['source' => 'es', 'text' => 'Hello']))]);

        expect(app(TranslationService::class)->enabled())->toBeTrue()
            ->and(app(TranslationService::class)->translate('Hola', 'en')?->text)->toBe('Hello');
    });

    it('leaves the message untranslated when Gemini fails', function () {
        config()->set('threadlive.translation.driver', 'gemini');
        config()->set('threadlive.gemini.key', 'g-key');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Internal']], 500)]);

        expect(app(TranslationService::class)->translate('Hola', 'en'))->toBeNull();
    });
});
