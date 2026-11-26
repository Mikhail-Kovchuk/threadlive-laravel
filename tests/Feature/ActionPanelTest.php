<?php

use Threadlive\Enums\TopicState;
use Threadlive\Models\Conversation;
use Threadlive\Telegram\Commands;
use Threadlive\Tests\Fixtures\FakeTranslator;
use Threadlive\Tests\Fixtures\LookupCommand;

/** A conversation in topic 300, written in Spanish. */
function panelConversation(): Conversation
{
    sendMessage('Hola, mi pedido sigue pendiente.');
    $conversation = Conversation::query()->firstOrFail();
    $conversation->forceFill(['telegram_thread_id' => 300, 'topic_state' => TopicState::Open])->save();

    return $conversation;
}

/** A button pressed under message $messageId (the card is 10). */
function pressButton(string $data, int $messageId = 10): void
{
    telegramUpdate(['callback_query' => [
        'id' => 'cb-'.$data,
        'data' => $data,
        'from' => ['id' => 77, 'is_bot' => false, 'first_name' => 'Maria'],
        'message' => [
            'message_id' => $messageId,
            'message_thread_id' => 300,
            'is_topic_message' => true,
            'chat' => ['id' => -1001234567890],
        ],
    ]])->assertOk();
}

/**
 * The texts of a keyboard, row by row.
 *
 * @return list<list<string>>
 */
function buttonTexts(array $keyboard): array
{
    return array_map(fn (array $row) => array_column($row, 'text'), $keyboard);
}

/** The keyboard of the last editMessageReplyMarkup. */
function lastKeyboard(): array
{
    return collect(telegramCalls('editMessageReplyMarkup'))->last()['reply_markup']['inline_keyboard'];
}

beforeEach(function () {
    FakeTranslator::reset();
    config()->set('threadlive.translation.driver', FakeTranslator::class);
    config()->set('threadlive.translation.languages', ['en', 'ru']);
    config()->set('threadlive.commands', [LookupCommand::class]);
});

it('puts ⚙️ Actions on the card, which opens the menu in place and closes it again', function () {
    fakeTelegram();
    $conversation = panelConversation();

    pressButton("tl:menu:{$conversation->id}");

    expect(buttonTexts(lastKeyboard()))->toBe([
        ['🌐 EN', '🌐 RU', '🚫 Off ✓'],
        ['/find', '/lookup'],
        ['← Back'],
    ])
        ->and(telegramCalls('editMessageReplyMarkup')[0]['message_id'])->toBe(10)
        ->and(telegramCalls('answerCallbackQuery')[0])->not->toHaveKey('text');

    pressButton("tl:back:{$conversation->id}");

    expect(buttonTexts(lastKeyboard()))->toBe([['🙋 Take', '✅ Close', '⚙️ Actions']]);
});

it('leaves translation out of the menu when it is not set up', function () {
    fakeTelegram();
    config()->set('threadlive.translation.driver', null);
    $conversation = panelConversation();

    pressButton("tl:menu:{$conversation->id}");

    expect(buttonTexts(lastKeyboard()))->toBe([['/find', '/lookup'], ['← Back']]);
});

it('turns translation on and off from the menu, posting in the topic and moving the tick', function () {
    fakeTelegram();
    $conversation = panelConversation();

    pressButton("tl:tr:{$conversation->id}:en:c");

    expect($conversation->refresh()->translate_to)->toBe('en')
        ->and($conversation->visitor_language)->toBe('es')
        ->and(telegramCalls('answerCallbackQuery')[0]['text'])->toBe('Translation into EN.')
        ->and(buttonTexts(lastKeyboard())[0])->toBe(['🌐 EN ✓', '🌐 RU', '🚫 Off']);

    $post = telegramCalls('sendMessage')[0];
    expect($post['message_thread_id'])->toBe(300)
        ->and($post)->not->toHaveKey('reply_parameters')
        ->and($post['text'])->toContain('Translation on');

    pressButton("tl:tr:{$conversation->id}:en:c");
    expect(telegramCalls('answerCallbackQuery')[1]['text'])->toBe('Already translating into EN.');

    pressButton("tl:tr:{$conversation->id}:off:c");
    expect($conversation->refresh()->translate_to)->toBeNull()
        ->and(buttonTexts(lastKeyboard())[0])->toBe(['🌐 EN', '🌐 RU', '🚫 Off ✓']);
});

it('asks for a command\'s argument from its button, and the reply runs it', function () {
    fakeTelegram();
    $conversation = panelConversation();

    pressButton("tl:cmd:{$conversation->id}:lookup:c");

    $question = telegramCalls('sendMessage')[0];
    expect($question['text'])->toContain('Look up a record by its code')
        ->and($question['message_thread_id'])->toBe(300)
        ->and($question['reply_markup'])->toMatchArray(['force_reply' => true, 'selective' => false]);

    $answer = managerMessage(300, 'A-1', 5001);
    $answer['reply_to_message'] = ['message_id' => 1001, 'from' => ['id' => 123, 'is_bot' => true]];
    telegramUpdate(['message' => $answer])->assertOk();

    expect(telegramCalls('sendMessage')[1]['text'])->toContain('Record A-1')
        ->and($conversation->messages()->where('direction', 'agent')->exists())->toBeFalse();
});

it('says so for a command that is no longer set up', function () {
    fakeTelegram();
    $conversation = panelConversation();
    config()->set('threadlive.commands', []);

    pressButton("tl:cmd:{$conversation->id}:lookup:c");

    expect(telegramCalls('answerCallbackQuery')[0]['text'])->toBe('This command is no longer set up.')
        ->and(telegramCalls('sendMessage'))->toBeEmpty();
});

it('posts the panel at the bottom of the topic on /menu, keeping one', function () {
    fakeTelegram();
    $conversation = panelConversation();

    telegramUpdate(['message' => managerMessage(300, '/menu', 5001)])->assertOk();

    $panel = telegramCalls('sendMessage')[0];
    expect($panel['message_thread_id'])->toBe(300)
        ->and($panel['text'])->toContain("#{$conversation->id}")
        ->and(buttonTexts($panel['reply_markup']['inline_keyboard']))->toBe([
            ['🌐 EN', '🌐 RU', '🚫 Off ✓'],
            ['/find', '/lookup'],
            ['🙋 Take', '✅ Close'],
        ])
        ->and(array_column(telegramCalls('deleteMessage'), 'message_id'))->toBe([5001])
        ->and($conversation->messages()->where('direction', 'agent')->exists())->toBeFalse();

    telegramUpdate(['message' => managerMessage(300, '/menu', 5002)])->assertOk();

    // The /menu message, then the previous panel (1001).
    expect(array_column(telegramCalls('deleteMessage'), 'message_id'))->toBe([5001, 5002, 1001]);

    // A tick moved on the panel stays a panel: Take/Close, no Back.
    pressButton("tl:tr:{$conversation->id}:ru:p", 1002);
    expect(buttonTexts(lastKeyboard())[2])->toBe(['🙋 Take', '✅ Close']);
});

it('does not let an app command take the name menu', function () {
    expect(Commands::RESERVED)->toContain('menu');
});

it('says where /menu, /close, /translate and /ai work when sent in General', function (string $command, string $name) {
    fakeTelegram();
    $message = managerMessage(1, $command);
    unset($message['message_thread_id'], $message['is_topic_message']);

    telegramUpdate(['message' => $message])->assertOk();

    $reply = telegramCalls('sendMessage')[0];
    expect($reply['text'])->toContain("/{$name} works in the topic of a conversation")
        ->and($reply['message_thread_id'] ?? null)->toBeNull();
})->with([
    ['/menu@threadlive_bot', 'menu'],
    ['/close', 'close'],
    ['/translate en', 'translate'],
    ['/ai', 'ai'],
]);
