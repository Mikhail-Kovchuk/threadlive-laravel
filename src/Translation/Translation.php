<?php

declare(strict_types=1);

namespace Threadlive\Translation;

final readonly class Translation
{
    public function __construct(
        public string $text,
        /** The language it was written in, e.g. 'es'. */
        public string $source,
        /** The language it was translated to, e.g. 'en'. */
        public string $target,
    ) {}

    /** Written in the target language already: nothing to show. */
    public function isSameLanguage(): bool
    {
        return Languages::base($this->source) === Languages::base($this->target);
    }

    /**
     * @return array{text: string, from: string, to: string}
     */
    public function toArray(): array
    {
        return ['text' => $this->text, 'from' => Languages::base($this->source), 'to' => Languages::base($this->target)];
    }
}
