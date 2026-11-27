<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use Illuminate\Contracts\Cache\Repository as Cache;
use InvalidArgumentException;
use Threadlive\Assistant\AssistantService;
use Threadlive\Translation\TranslationService;

/**
 * The buttons under the message field in the support group
 * (config('threadlive.keyboard')). Telegram keeps one keyboard per topic;
 * the app lists its own in `general` and `topic`, or takes the package's:
 *
 *   General             none (the "/" menu has /settings)
 *   a conversation      /menu · /close · /ai · /translate (what is on)
 *
 * A button sends its command as the manager's message, in the topic it is
 * pressed in — so it acts on that conversation, as if typed.
 */
final class Keyboards
{
    /** Cache key of the General keyboard's message, replaced on the next setup. */
    public const GENERAL_KEY = 'threadlive:keyboard:general';

    /** Cache key: this topic has its keyboard. */
    private const TOPIC_KEY = 'threadlive:keyboard:topic:';

    public function __construct(
        private readonly Commands $commands,
        private readonly TranslationService $translation,
        private readonly AssistantService $assistant,
        private readonly Cache $cache,
    ) {}

    public static function enabled(): bool
    {
        // A nested key: an older published config may lack it.
        return config('threadlive.keyboard.enabled', true) !== false;
    }

    /**
     * General's buttons: config keyboard.general — none unless listed
     * (General has /settings in the "/" menu instead).
     *
     * @return array<string, mixed>|null reply_markup; null when turned off or empty
     */
    public function general(): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        $names = $this->configured('general') ?? [];

        return self::markup(array_chunk($this->known(array_values(array_unique($names))), 3));
    }

    /**
     * A topic's buttons: config keyboard.topic, or the package's set — menu,
     * close, and ai and translate when they are on.
     *
     * @return array<string, mixed>|null reply_markup; null when turned off or empty
     */
    public function topic(): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        $names = $this->configured('topic');
        if ($names === null) {
            $names = ['menu', 'close'];
            if ($this->assistant->enabled()) {
                $names[] = 'ai';
            }
            if ($this->translation->enabled()) {
                $names[] = 'translate';
            }
        }

        return self::markup(array_chunk($this->known(array_values(array_unique($names))), 2));
    }

    /**
     * Names in the config that are not a command of the package's or of the
     * app's — a button for them would answer "Unknown command".
     *
     * @return list<string>
     */
    public function unknown(): array
    {
        $configured = [...$this->configured('general') ?? [], ...$this->configured('topic') ?? []];

        return array_values(array_diff($configured, $this->known($configured)));
    }

    /**
     * @return list<string>|null command names without "/"; null: the package's set
     */
    private function configured(string $where): ?array
    {
        $names = config("threadlive.keyboard.{$where}");

        if (! is_array($names)) {
            return null;
        }

        return array_values(array_map(
            fn (string $name) => strtolower(ltrim(trim($name), '/')),
            array_filter($names, 'is_string'),
        ));
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function known(array $names): array
    {
        $all = [...Commands::RESERVED, ...$this->appCommands()];

        return array_values(array_filter($names, fn (string $name) => in_array($name, $all, true)));
    }

    /** @return list<string> */
    private function appCommands(): array
    {
        try {
            return array_keys($this->commands->all());
        } catch (InvalidArgumentException) {
            return []; // a broken entry: setup names it
        }
    }

    /**
     * The topic's keyboard, once: for the first post of the package's in a
     * topic, null after. A lost cache only means it is given again.
     *
     * @return array<string, mixed>|null
     */
    public function forNewTopic(int $thread): ?array
    {
        $markup = $this->topic();

        if ($markup === null || ! $this->cache->add(self::TOPIC_KEY.$thread, true, now()->addDays(90))) {
            return null;
        }

        return $markup;
    }

    /** Given again to that topic on its next post (the post failed). */
    public function forget(int $thread): void
    {
        $this->cache->forget(self::TOPIC_KEY.$thread);
    }

    /**
     * @param  list<list<string>>  $rows  command names
     * @return array<string, mixed>|null null for no buttons at all
     */
    private static function markup(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        return [
            'keyboard' => array_map(fn (array $row) => array_map(fn (string $name) => ['text' => '/'.$name], $row), $rows),
            'is_persistent' => true,
            'resize_keyboard' => true,
        ];
    }
}
