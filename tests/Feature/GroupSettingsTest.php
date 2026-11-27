<?php

use Threadlive\Assistant\AssistantService;
use Threadlive\Enums\Direction;
use Threadlive\Enums\TopicState;
use Threadlive\Models\Conversation;
use Threadlive\Support\GroupSettings;
use Threadlive\Support\WorkingHours;
use Threadlive\Telegram\Mentions;
use Threadlive\Tests\Fixtures\FakeAssistant;

/** A message in General from user $userId (77: an admin, 88: not). */
function settingsMessage(string $text, int $messageId, int $userId = 77, ?int $replyTo = null): array
{
    return [
        'message_id' => $messageId,
        'chat' => ['id' => -1001234567890, 'type' => 'supergroup'],
        'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => $userId === 77 ? 'Maria' : 'Oleh'],
        'text' => $text,
        ...($replyTo !== null ? ['reply_to_message' => ['message_id' => $replyTo]] : []),
    ];
}

function settingsButton(string $data, int $userId = 77): void
{
    telegramUpdate(['callback_query' => [
        'id' => 'cb-'.$data.'-'.$userId,
        'data' => $data,
        'from' => ['id' => $userId, 'first_name' => 'Maria'],
        'message' => ['message_id' => 900, 'chat' => ['id' => -1001234567890]],
    ]])->assertOk();
}

function adminsAre77(): void
{
    fakeTelegram(['getChatMember' => fn (array $params) => ['status' => ($params['user_id'] ?? 0) === 77 ? 'administrator' : 'member']]);
}

beforeEach(function () {
    config()->set('threadlive.assistant.about', 'From the config.');
    config()->set('threadlive.hours', ['from' => '09:00', 'to' => '18:00', 'tz' => 'Europe/Kyiv']);
});

it('reads hours as an admin types them', function (string $text, ?array $expected) {
    $hours = WorkingHours::parse($text, 'UTC');

    expect($hours?->toArray())->toBe($expected);
})->with([
    ['09:00-18:00 Europe/Kyiv', ['from' => '09:00', 'to' => '18:00', 'tz' => 'Europe/Kyiv']],
    ['9:00 – 18:30', ['from' => '09:00', 'to' => '18:30', 'tz' => 'UTC']],
    ['22:00-02:00 UTC', ['from' => '22:00', 'to' => '02:00', 'tz' => 'UTC']],
    ['24/7', ['from' => '00:00', 'to' => '00:00', 'tz' => 'UTC']],
    ['25:00-18:00', null],
    ['9-18', null],
    ['09:00-18:00 Mars/Olympus', null],
]);

it('shows the company text to anyone, with where it comes from', function () {
    adminsAre77();
    settingsButton('tl:set:about', 88);

    $post = telegramCalls('sendMessage')[0];
    expect($post['text'])->toContain('From the config.')
        ->and($post['text'])->toContain('from the config')
        ->and(array_column($post['reply_markup']['inline_keyboard'][0], 'text'))->toBe(['✏️ Change']);
});

it('lets only an admin change it', function () {
    adminsAre77();
    settingsButton('tl:set:about:edit', 88);

    expect(telegramCalls('sendMessage'))->toBeEmpty()
        ->and(telegramCalls('answerCallbackQuery')[0]['text'])->toContain('Only the group\'s admins');
});

it('sets the company text from an admin\'s reply; the assistant uses it; reset brings the config\'s back', function () {
    adminsAre77();
    settingsButton('tl:set:about:edit');

    $prompt = telegramCalls('sendMessage')[0];
    expect($prompt['reply_markup']['force_reply'])->toBeTrue();
    $promptId = 1001; // the fake's first message id

    // Someone else's reply is not taken.
    telegramUpdate(['message' => settingsMessage('Hacked.', 6000, 88, $promptId)]);
    expect(GroupSettings::about())->toBeNull();

    telegramUpdate(['message' => settingsMessage("We test payment systems.\nIn 40 countries.", 6001, 77, $promptId)]);
    expect(GroupSettings::about())->toBe("We test payment systems.\nIn 40 countries.")
        ->and(app(AssistantService::class)->about())->toBe("We test payment systems.\nIn 40 countries.")
        ->and(collect(telegramCalls('sendMessage'))->last()['text'])->toContain('Company text saved');

    settingsButton('tl:set:about');
    $shown = collect(telegramCalls('sendMessage'))->last();
    expect($shown['text'])->toContain('(set here)')
        ->and(array_column($shown['reply_markup']['inline_keyboard'][0], 'text'))->toBe(['✏️ Change', '↩️ The config\'s']);

    settingsButton('tl:set:about:reset');
    expect(GroupSettings::about())->toBeNull()
        ->and(app(AssistantService::class)->about())->toBe('From the config.');
});

it('takes the admin\'s next message there as the value, no reply needed — not someone else\'s, not a command', function () {
    adminsAre77();
    settingsButton('tl:set:about:edit');

    telegramUpdate(['message' => settingsMessage('Not mine to set.', 6000, 88)]);
    telegramUpdate(['message' => settingsMessage('/find 12', 6001)]);
    expect(GroupSettings::about())->toBeNull();

    telegramUpdate(['message' => settingsMessage('We test payments.', 6002)]);
    expect(GroupSettings::about())->toBe('We test payments.');

    // Saved: the admin's next message is just a message again.
    telegramUpdate(['message' => settingsMessage('Thanks!', 6003)]);
    expect(GroupSettings::about())->toBe('We test payments.');
});

it('shows the config\'s text unwrapped, paragraphs kept', function () {
    adminsAre77();
    config()->set('threadlive.assistant.about', "We test payment\nsystems.\n\nAsk us.");
    settingsButton('tl:set:about');

    expect(telegramCalls('sendMessage')[0]['text'])->toContain("We test payment systems.\n\nAsk us.");
});

it('sets the hours, refusing what it cannot read, and the widget follows them', function () {
    adminsAre77();
    settingsButton('tl:set:hours:edit');

    telegramUpdate(['message' => settingsMessage('nine to six', 6000, 77, 1001)]);
    expect(collect(telegramCalls('sendMessage'))->last()['text'])->toContain('Not understood')
        ->and(GroupSettings::hours())->toBeNull();

    telegramUpdate(['message' => settingsMessage('10:00-19:00', 6001, 77, 1001)]);
    expect(GroupSettings::hours())->toBe(['from' => '10:00', 'to' => '19:00', 'tz' => 'Europe/Kyiv'])
        ->and(WorkingHours::fromConfig()->from)->toBe('10:00');

    $this->getJson('/threadlive/bootstrap')->assertJsonPath('hours', ['from' => '10:00', 'to' => '19:00', 'tz' => 'Europe/Kyiv']);
});

describe('🤖 Assistant', function () {
    beforeEach(function () {
        config()->set('threadlive.assistant.enabled', false);
        config()->set('threadlive.assistant.driver', FakeAssistant::class);
        config()->set('threadlive.assistant.first', 'always');
    });

    it('shows its state to anyone; only an admin switches it', function () {
        adminsAre77();
        settingsButton('tl:set:assistant', 88);

        $post = telegramCalls('sendMessage')[0];
        expect($post['text'])->toContain('<b>AI assistant</b>: off')
            ->and($post['text'])->toContain('(from the config)')
            ->and(array_column($post['reply_markup']['inline_keyboard'][0], 'callback_data'))
            ->toBe(['tl:set:assistant:on', 'tl:set:assistant:off_hours']);

        settingsButton('tl:set:assistant:on', 88);
        expect(GroupSettings::assistantEnabled())->toBeNull()
            ->and(collect(telegramCalls('answerCallbackQuery'))->last()['text'])->toContain('Only the group\'s admins');
    });

    it('switches it on and off over the config, sets when it answers, and edits the post in place', function () {
        adminsAre77();
        $assistant = app(AssistantService::class);

        settingsButton('tl:set:assistant:on');
        expect($assistant->enabled())->toBeTrue()
            ->and($assistant->answersNew())->toBeTrue();
        $edit = collect(telegramCalls('editMessageText'))->last();
        expect($edit['message_id'])->toBe(900)
            ->and($edit['text'])->toContain('<b>AI assistant</b>: on')
            ->and($edit['text'])->toContain('(set here)')
            ->and(array_column($edit['reply_markup']['inline_keyboard'][1], 'callback_data'))->toBe(['tl:set:assistant:reset']);

        // Only outside the hours: inside them (round the clock here), the managers.
        config()->set('threadlive.hours', ['from' => '00:00', 'to' => '00:00', 'tz' => 'UTC']);
        settingsButton('tl:set:assistant:off_hours');
        expect($assistant->first())->toBe('off_hours')
            ->and($assistant->answersNew())->toBeFalse();

        settingsButton('tl:set:assistant:off');
        expect($assistant->enabled())->toBeFalse();

        // A new conversation goes to the managers.
        sendMessage('Hello');
        expect(Conversation::query()->latest('id')->firstOrFail()->handler)->toBe(Conversation::HUMAN);

        settingsButton('tl:set:assistant:reset');
        expect(GroupSettings::assistantEnabled())->toBeNull()
            ->and($assistant->first())->toBe('always')
            ->and($assistant->switchedOn())->toBeFalse();
    });

    it('will not switch on without a key', function () {
        adminsAre77();
        config()->set('threadlive.assistant.driver', 'gemini');
        config()->set('threadlive.gemini.key', '');

        settingsButton('tl:set:assistant:on');

        expect(GroupSettings::assistantEnabled())->toBeNull()
            ->and(collect(telegramCalls('answerCallbackQuery'))->last()['text'])->toContain('THREADLIVE_GEMINI_KEY');
    });
});

describe('👥 Mentions', function () {
    it('reads usernames as an admin types them', function (string $text, array $names, array $wrong) {
        expect(Mentions::parse($text))->toBe(['names' => $names, 'wrong' => $wrong]);
    })->with([
        ['@maria @oleh', ['@maria', '@oleh'], []],
        ['maria, oleh_2 @maria', ['@maria', '@oleh_2'], []],
        ['none', [], []],
        ['@maria Олег @x', ['@maria'], ['Олег', '@x']],
    ]);

    it('sets who is called over the config — the handoff in General calls them — and resets', function () {
        adminsAre77();
        config()->set('threadlive.mention', ['@fromconfig']);

        settingsButton('tl:set:mention');
        expect(telegramCalls('sendMessage')[0]['text'])->toContain('@fromconfig (from the config, mention)');

        settingsButton('tl:set:mention:edit');
        telegramUpdate(['message' => settingsMessage('@maria Олег', 6000)]);
        expect(collect(telegramCalls('sendMessage'))->last()['text'])->toContain('Not usernames: Олег')
            ->and(GroupSettings::mentions())->toBeNull();

        telegramUpdate(['message' => settingsMessage('@maria oleh', 6001)]);
        expect(GroupSettings::mentions())->toBe(['@maria', '@oleh'])
            ->and(Mentions::managers())->toBe('@maria @oleh');

        settingsButton('tl:set:mention:edit');
        telegramUpdate(['message' => settingsMessage('none', 6002)]);
        expect(GroupSettings::mentions())->toBe([])
            ->and(Mentions::managers())->toBe('')
            ->and(collect(telegramCalls('sendMessage'))->last()['text'])->toContain('Nobody is called');

        settingsButton('tl:set:mention:reset');
        expect(Mentions::managers())->toBe('@fromconfig');
    });
});

it('posts the report of the last seven days on 📊 Report', function () {
    adminsAre77();
    sendMessage('Hello');

    settingsButton('tl:set:report', 88);

    expect(telegramCalls('sendMessage')[0]['text'])->toContain('📊 <b>Report</b>')
        ->and(telegramCalls('sendMessage')[0]['text'])->toContain('Conversations: 1 new');
});

it('never passes the answer to a setting on to a visitor, even in a conversation\'s topic', function () {
    adminsAre77();
    sendMessage('Hello');
    Conversation::query()->firstOrFail()->forceFill(['telegram_thread_id' => 300, 'topic_state' => TopicState::Open])->save();

    telegramUpdate(['callback_query' => [
        'id' => 'cb-topic',
        'data' => 'tl:set:about:edit',
        'from' => ['id' => 77, 'first_name' => 'Maria'],
        'message' => ['message_id' => 900, 'message_thread_id' => 300, 'is_topic_message' => true, 'chat' => ['id' => -1001234567890]],
    ]]);

    $reply = managerMessage(300, 'We test payments.', 6002);
    $reply['reply_to_message'] = ['message_id' => 1001];
    telegramUpdate(['message' => $reply]);

    expect(GroupSettings::about())->toBe('We test payments.')
        ->and(Conversation::query()->firstOrFail()->messages()->where('direction', Direction::Agent)->count())->toBe(0);
});
