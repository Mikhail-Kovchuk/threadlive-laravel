<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use InvalidArgumentException;
use Threadlive\Assistant\AssistantService;
use Threadlive\Models\Conversation;
use Threadlive\Translation\Languages;
use Threadlive\Translation\TranslationService;

/**
 * The buttons a manager uses instead of typing commands: ⚙️ Actions on the
 * visitor card opens them in place, /menu posts them at the bottom of the
 * topic. Translation (one button per language, the current one ticked) and
 * the app's commands; a command that needs an argument asks for it.
 *
 * Callback data stays under Telegram's 64 bytes:
 *   tl:menu:12        open the menu on the card     tl:back:12  close it
 *   tl:tr:12:en:c     translation into EN           tl:tr:12:off:p
 *   tl:cmd:12:order:c run /order (asks for its argument)
 *   tl:ai:12:on:c     the assistant answers it      tl:ai:12:off:c  the managers do
 * The last part says where the buttons are: c = the card, p = a /menu panel.
 */
final class ActionPanel
{
    public const ON_CARD = 'c';

    public const ON_PANEL = 'p';

    public function __construct(
        private readonly TranslationService $translation,
        private readonly Commands $commands,
        private readonly AssistantService $assistant,
    ) {}

    /**
     * @param  self::ON_*  $where
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public function keyboard(Conversation $conversation, string $where): array
    {
        $id = $conversation->id;
        $rows = [];

        if ($this->translation->enabled()) {
            $buttons = [];
            foreach ($this->translation->languages() as $language) {
                $on = $conversation->translate_to === $language;
                $buttons[] = [
                    'text' => '🌐 '.Languages::label($language).($on ? ' ✓' : ''),
                    'callback_data' => "tl:tr:{$id}:{$language}:{$where}",
                ];
            }
            $buttons[] = [
                'text' => $conversation->translate_to === null ? '🚫 Off ✓' : '🚫 Off',
                'callback_data' => "tl:tr:{$id}:off:{$where}",
            ];
            array_push($rows, ...array_chunk($buttons, 4));
        }

        // Who answers: the assistant, or the managers (the same as /ai on|off).
        if ($this->assistant->enabled()) {
            $ai = $conversation->withAssistant();
            $rows[] = [
                ['text' => '🤖 AI answers'.($ai ? ' ✓' : ''), 'callback_data' => "tl:ai:{$id}:on:{$where}"],
                ['text' => '🙋 Managers answer'.($ai ? '' : ' ✓'), 'callback_data' => "tl:ai:{$id}:off:{$where}"],
            ];
        }

        $commands = [];
        foreach ($this->commandNames() as $name) {
            $commands[] = ['text' => '/'.$name, 'callback_data' => "tl:cmd:{$id}:{$name}:{$where}"];
        }
        array_push($rows, ...array_chunk($commands, 3));

        if ($where === self::ON_CARD) {
            $rows[] = [['text' => '← Back', 'callback_data' => "tl:back:{$id}"]];
        } else {
            $last = [];
            if (config('threadlive.claim_button', true)) {
                $last[] = ['text' => '🙋 Take', 'callback_data' => "tl:claim:{$id}"];
            }
            $last[] = ['text' => '✅ Close', 'callback_data' => "tl:close:{$id}"];
            $rows[] = $last;
        }

        return $rows;
    }

    /**
     * The app's commands and /find. A broken entry in the config leaves the
     * buttons out; threadlive:setup names it.
     *
     * @return list<string>
     */
    private function commandNames(): array
    {
        try {
            return array_keys($this->commands->all());
        } catch (InvalidArgumentException $e) {
            report($e);

            return [];
        }
    }
}
