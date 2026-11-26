<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Threadlive\Enums\TopicState;
use Threadlive\Jobs\DeliverVisitorMessages;
use Threadlive\Models\Conversation;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\Topics;
use Threadlive\Tests\Fixtures\FakeTranslator;
use Threadlive\Translation\DeepLTranslator;
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
    expect($posted)->toBe("<blockquote>Hola, mi pedido sigue pendiente.\n\nHola otra vez</blockquote>🌐 <b>ES → EN</b>\n"
        ."[en] Hola, mi pedido sigue pendiente.\n\n[en] Hola otra vez")
        ->and(FakeTranslator::$calls)->toHaveCount(2)
        ->and($conversation->refresh()->visitor_language)->toBe('es');
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
    expect($note['text'])->toBe("🌐 <b>→ ES</b>\n<blockquote>[es] Your order is being checked</blockquote>")
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

    expect(collect(telegramCalls('setMyCommands')[0]['commands'])->pluck('command')->all())->toBe(['close', 'translate', 'find']);
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
