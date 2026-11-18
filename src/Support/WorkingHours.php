<?php

declare(strict_types=1);

namespace Threadlive\Support;

use DateTimeInterface;
use DateTimeZone;

/** Support hours, "HH:MM" to "HH:MM" in a time zone; past midnight allowed. */
final readonly class WorkingHours
{
    public function __construct(
        public string $from,
        public string $to,
        public string $tz,
    ) {}

    public static function fromConfig(): self
    {
        $hours = config('threadlive.hours');
        $hours = is_array($hours) ? $hours : [];

        return new self(
            from: self::time($hours['from'] ?? '00:00'),
            to: self::time($hours['to'] ?? '00:00'),
            tz: is_string($hours['tz'] ?? null) ? $hours['tz'] : 'UTC',
        );
    }

    public function isOpen(DateTimeInterface $at): bool
    {
        // Equal ends: around the clock.
        if ($this->from === $this->to) {
            return true;
        }

        $local = (new \DateTimeImmutable('@'.$at->getTimestamp()))
            ->setTimezone(new DateTimeZone($this->tz))
            ->format('H:i');

        return $this->from < $this->to
            ? $local >= $this->from && $local < $this->to
            // 18:00–02:00: open after the start or before the end.
            : $local >= $this->from || $local < $this->to;
    }

    /**
     * @return array{from: string, to: string, tz: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'tz' => $this->tz];
    }

    /** "9:00" → "09:00", so times compare as strings. */
    private static function time(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m) !== 1) {
            return '00:00';
        }

        return sprintf('%02d:%s', (int) $m[1], $m[2]);
    }
}
