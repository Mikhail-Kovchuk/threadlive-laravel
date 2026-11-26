<?php

declare(strict_types=1);

namespace Threadlive\Assistant;

use Threadlive\Models\Conversation;

/**
 * What a prompt may use, worked out for the conversation being answered.
 * Never the ChatHost's card lines or admin links.
 */
final readonly class PromptContext
{
    public function __construct(
        /** The assistant's name, as the visitor sees it (config assistant.name). */
        public string $name,
        /** Who we are, from config assistant.about; '' when not given. */
        public string $about,
        /** "Thursday, 26 November 2026, 14:05 (Europe/Kyiv)" — in the support hours' zone. */
        public string $now,
        /** "09:00–18:00 (Europe/Kyiv)", or "around the clock". */
        public string $hours,
        /** The managers are working now. */
        public bool $managersAreIn,
        /** When they are back, "09:00 (Europe/Kyiv)"; '' while they are in. */
        public string $managersBack,
        /** The whole FAQ — `show: false` entries too — "Q: …\nA: …", in its default language; '' for none. */
        public string $faq,
        /** The line about the page the visitor wrote from (ChatHost::contextLine); ''. */
        public string $page,
        /** The visitor's language when known ("Spanish"); ''. */
        public string $visitorLanguage,
        /** Their browser's language ("Ukrainian") — for a message that does not tell ("test", "ok"); ''. */
        public string $browserLanguage,
        /** The managers' language ("English"): reply_for_managers, the reason and the summary are in it. */
        public string $managersLanguage,
        public Conversation $conversation,
    ) {}
}
