<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Threadlive\Jobs\DeliverVisitorMessages;
use Threadlive\Jobs\TranslateFaq;
use Threadlive\Models\Conversation;
use Threadlive\Support\Faq;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\Topics;
use Threadlive\Tests\Fixtures\FakeTranslator;
use Threadlive\Translation\TranslationService;

/** A faq.json as an editor writes it. */
function faqJson(array $entries): string
{
    return json_encode($entries, JSON_UNESCAPED_UNICODE);
}

function sampleFaq(): array
{
    return [
        ['id' => 'invoice', 'question' => 'Where is my invoice?', 'answer' => "Account → Invoices.\nEach has a PDF."],
        [
            'id' => 'hours',
            'question' => ['en' => 'When do you answer?', 'uk' => 'Коли ви відповідаєте?'],
            'answer' => ['en' => 'From 9 to 18.', 'uk' => 'З 9 до 18.'],
        ],
        ['id' => 'old', 'question' => 'Old question', 'answer' => 'Old answer', 'show' => false],
    ];
}

/** A manager sends $json as faq.json with the caption /faq, in General. */
function uploadFaq(string $json, int $messageId = 6000): void
{
    $GLOBALS['telegramFileContents'] = $json;
    telegramUpdate(['message' => [
        'message_id' => $messageId,
        'chat' => ['id' => -1001234567890, 'type' => 'supergroup'],
        'from' => ['id' => 77, 'is_bot' => false, 'first_name' => 'Maria'],
        'caption' => '/faq',
        'document' => ['file_id' => 'faq-file', 'file_name' => 'faq.json', 'file_size' => strlen($json)],
    ]])->assertOk();
}

beforeEach(function () {
    File::delete((string) config('threadlive.faq.path'));
    unset($GLOBALS['telegramFileContents']);
    FakeTranslator::reset();
});

afterEach(function () {
    File::delete((string) config('threadlive.faq.path'));
});

describe('the file', function () {
    it('reads plain texts as the default language, and several languages as given', function () {
        [$entries, $errors] = Faq::parse(faqJson(sampleFaq()));

        expect($errors)->toBe([])
            ->and($entries[0])->toBe([
                'id' => 'invoice',
                'question' => ['en' => 'Where is my invoice?'],
                'answer' => ['en' => "Account → Invoices.\nEach has a PDF."],
                'show' => true,
            ])
            ->and($entries[1]['question'])->toBe(['en' => 'When do you answer?', 'uk' => 'Коли ви відповідаєте?'])
            ->and($entries[2]['show'])->toBeFalse();
    });

    it('names every mistake by entry, and loads nothing then', function (string $json, string $error) {
        [$entries, $errors] = Faq::parse($json);

        expect($entries)->toBe([])
            ->and(implode("\n", $errors))->toContain($error);
    })->with([
        'not JSON' => ['[{"id": "a",}]', 'This is not valid JSON'],
        'not a list' => ['{"id": "a"}', 'The file must be a list'],
        'no answer' => [faqJson([['id' => 'a', 'question' => 'Q']]), 'Entry 1 (a): answer must be a text'],
        'bad id' => [faqJson([['id' => 'Has Space', 'question' => 'Q', 'answer' => 'A']]), 'Entry 1: id must be'],
        'same id' => [faqJson([['id' => 'a', 'question' => 'Q', 'answer' => 'A'], ['id' => 'a', 'question' => 'Q', 'answer' => 'A']]), 'Entry 2 (a): the id is used twice'],
        'languages differ' => [faqJson([['id' => 'a', 'question' => ['en' => 'Q', 'uk' => 'П'], 'answer' => ['en' => 'A']]]), 'same languages'],
        'bad language' => [faqJson([['id' => 'a', 'question' => ['english' => 'Q'], 'answer' => ['english' => 'A']]]), 'is not a language code'],
        'empty text' => [faqJson([['id' => 'a', 'question' => ' ', 'answer' => 'A']]), 'question (en) is empty'],
        'show not bool' => [faqJson([['id' => 'a', 'question' => 'Q', 'answer' => 'A', 'show' => 'no']]), 'show must be true or false'],
    ]);

    it('reads a file saved with a BOM, as Windows editors do', function () {
        [, $errors] = Faq::parse("\u{FEFF}".faqJson(sampleFaq()));

        expect($errors)->toBe([]);
    });
});

describe('the widget', function () {
    beforeEach(function () {
        Faq::save(Faq::parse(faqJson(sampleFaq()))[0]);
    });

    it('lists the shown entries in the browser\'s language, else the default', function () {
        $this->getJson('/threadlive/bootstrap', ['Accept-Language' => 'uk-UA,uk;q=0.9,en;q=0.8'])
            ->assertJsonPath('languages', ['uk', 'en'])
            ->assertJsonPath('faq', [
                ['id' => 'invoice', 'question' => 'Where is my invoice?', 'answer' => "Account → Invoices.\nEach has a PDF."],
                ['id' => 'hours', 'question' => 'Коли ви відповідаєте?', 'answer' => 'З 9 до 18.'],
            ]);

        $this->getJson('/threadlive/bootstrap', ['Accept-Language' => 'de-DE'])
            ->assertJsonPath('languages', ['de', 'en'])
            ->assertJsonPath('faq.1.question', 'When do you answer?');
    });

    it('speaks only the default language with browser off', function () {
        config()->set('threadlive.language', ['default' => 'uk', 'browser' => false]);

        $this->getJson('/threadlive/bootstrap', ['Accept-Language' => 'en-US'])
            ->assertJsonPath('languages', ['uk'])
            ->assertJsonPath('faq.1.question', 'Коли ви відповідаєте?')
            // No Ukrainian for this one: what it has.
            ->assertJsonPath('faq.0.question', 'Where is my invoice?');
    });

    it('shows the managers which answers the visitor read before writing', function () {
        fakeTelegram();
        sendMessage('Still need help', null, ['context' => ['faq' => ['hours', 'invoice', 'nope', 'Bad Id']]])->assertCreated();

        $conversation = Conversation::query()->firstOrFail();
        expect($conversation->context['faq'])->toBe(['hours', 'invoice', 'nope']);

        (new DeliverVisitorMessages($conversation->id))->handle(app(Topics::class), app(TelegramClient::class));

        expect(telegramCalls('sendMessage')[0]['text'])->toContain('📚 Read in the FAQ: Where is my invoice?; When do you answer?');
    });
});

describe('/faq in the group', function () {
    it('explains the format when there is no FAQ yet', function () {
        fakeTelegram();

        telegramUpdate(['message' => managerMessage(300, '/faq', 6001)])->assertOk();

        expect(telegramCalls('sendMessage')[0]['text'])->toContain('No FAQ yet')
            ->and(telegramCalls('sendDocument'))->toBeEmpty();
    });

    it('sends the current faq.json back', function () {
        fakeTelegram();
        Faq::save(Faq::parse(faqJson(sampleFaq()))[0]);

        telegramUpdate(['message' => managerMessage(300, '/faq@threadlive_bot', 6001)])->assertOk();

        $sent = multipart(Http::recorded()->map(fn ($pair) => $pair[0])
            ->first(fn ($request) => str_ends_with($request->url(), '/sendDocument'))->data());
        expect($sent['document']['filename'])->toBe('faq.json')
            ->and($sent['caption'])->toContain('3 questions (1 hidden) · en, uk')
            ->and($sent['message_thread_id'])->toBe('300')
            ->and(json_decode($sent['document']['contents'], true)[2])->toMatchArray(['id' => 'old', 'show' => false])
            ->and(json_decode($sent['document']['contents'], true)[0])->not->toHaveKey('show')
            ->and(Conversation::query()->count())->toBe(0);
    });

    it('replaces the FAQ with a file sent with the caption /faq', function () {
        fakeTelegram();

        uploadFaq(faqJson(sampleFaq()));

        expect(Faq::entries())->toHaveCount(3)
            ->and(telegramCalls('sendMessage')[0]['text'])->toBe('✅ FAQ updated: 3 questions (1 hidden) · en, uk.');
        Queue::assertNotPushed(TranslateFaq::class);
    });

    it('keeps the FAQ as it was when the file has mistakes, and lists them', function () {
        fakeTelegram();
        Faq::save(Faq::parse(faqJson(sampleFaq()))[0]);

        uploadFaq(faqJson([['id' => 'a', 'question' => 'Q'], ['id' => 'b', 'answer' => 'A']]));

        expect(Faq::entries())->toHaveCount(3)
            ->and(telegramCalls('sendMessage')[0]['text'])->toContain('❌ The FAQ was not changed:')
            ->and(telegramCalls('sendMessage')[0]['text'])->toContain('• Entry 1 (a): answer must be a text')
            ->and(telegramCalls('sendMessage')[0]['text'])->toContain('• Entry 2 (b): question must be a text');
    });

    it('never passes a /faq file in a conversation\'s topic on to the visitor', function () {
        fakeTelegram();
        sendMessage('Hi');
        Conversation::query()->firstOrFail()->forceFill(['telegram_thread_id' => 300])->save();

        $GLOBALS['telegramFileContents'] = faqJson(sampleFaq());
        telegramUpdate(['message' => [
            ...managerMessage(300, '', 6002),
            'caption' => '/faq',
            'document' => ['file_id' => 'faq-file', 'file_name' => 'faq.json', 'file_size' => 100],
        ]])->assertOk();

        expect(Conversation::query()->firstOrFail()->messages()->where('direction', 'agent')->exists())->toBeFalse()
            ->and(Faq::entries())->toHaveCount(3);
    });
});

describe('translation', function () {
    beforeEach(function () {
        config()->set('threadlive.translation.driver', FakeTranslator::class);
        config()->set('threadlive.faq.translate', true);
        config()->set('threadlive.faq.languages', ['uk', 'es']);
    });

    it('fills in the missing languages in the background, never replacing what the file has', function () {
        fakeTelegram();

        uploadFaq(faqJson(sampleFaq()));

        Queue::assertPushed(TranslateFaq::class, fn (TranslateFaq $job) => $job->reportTo === 0);
        expect(telegramCalls('sendMessage')[0]['text'])->toContain('Translating what is missing (uk, es)');

        (new TranslateFaq(0))->handle(app(TranslationService::class), app(TelegramClient::class));

        $hours = collect(Faq::entries())->firstWhere('id', 'hours');
        $invoice = collect(Faq::entries())->firstWhere('id', 'invoice');
        expect($hours['question']['uk'])->toBe('Коли ви відповідаєте?')
            ->and($hours['question']['es'])->toBe('[es] When do you answer?')
            ->and($invoice['answer']['uk'])->toBe("[uk] Account → Invoices.\nEach has a PDF.")
            // 3 entries × 2 languages, less the Ukrainian "hours" had.
            ->and(collect(telegramCalls('sendMessage'))->last()['text'])->toBe('🌐 FAQ translated: 5 answers added.');
    });

    it('leaves an entry reworded meanwhile as the new file has it', function () {
        Faq::save(Faq::parse(faqJson(sampleFaq()))[0]);
        FakeTranslator::$before = function () {
            static $done = false;
            if (! $done) {
                $done = true;
                $faq = sampleFaq();
                $faq[0]['question'] = 'Where are my invoices?';
                Faq::save(Faq::parse(faqJson($faq))[0]);
            }
        };

        (new TranslateFaq(null))->handle(app(TranslationService::class), app(TelegramClient::class));

        $invoice = collect(Faq::entries())->firstWhere('id', 'invoice');
        expect($invoice['question'])->toBe(['en' => 'Where are my invoices?'])
            ->and(collect(Faq::entries())->firstWhere('id', 'old')['question'])->toHaveKey('uk');
    });

    it('reports what it could not translate, and keeps the rest', function () {
        fakeTelegram();
        Faq::save(Faq::parse(faqJson([sampleFaq()[0]]))[0]);
        FakeTranslator::$down = true;

        (new TranslateFaq(0))->handle(app(TranslationService::class), app(TelegramClient::class));

        expect(Faq::entries()[0]['question'])->toBe(['en' => 'Where is my invoice?'])
            ->and(telegramCalls('sendMessage')[0]['text'])->toContain('Not translated (try loading the file again later): invoice → uk, invoice → es');
    });
});

describe('threadlive:faq', function () {
    it('imports a file, checked whole, and exports it again', function () {
        $file = tempnam(sys_get_temp_dir(), 'faq');
        file_put_contents($file, faqJson(sampleFaq()));

        $this->artisan('threadlive:faq', ['action' => 'import', 'file' => $file])
            ->expectsOutputToContain('FAQ updated: 3 questions (1 hidden) · en, uk.')
            ->assertSuccessful();

        file_put_contents($file, '[{"id": "a"}]');
        $this->artisan('threadlive:faq', ['action' => 'import', 'file' => $file])
            ->expectsOutputToContain('The FAQ was not changed')
            ->assertFailed();
        expect(Faq::entries())->toHaveCount(3);

        $this->artisan('threadlive:faq', ['action' => 'export', 'file' => $file])->assertSuccessful();
        expect(json_decode((string) file_get_contents($file), true))->toHaveCount(3);

        unlink($file);
    });
});
