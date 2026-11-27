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

    /** The hours the group's admins set (/settings), else config('threadlive.hours'). */
    public static function fromConfig(): self
    {
        $hours = GroupSettings::hours() ?? config('threadlive.hours');
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

    /**
     * "09:00-18:00 Europe/Kyiv", "9:00 – 18:00", "24/7": what an admin
     * types in /settings. The zone is optional ($tz when left out). Null
     * when it is not that.
     */
    public static function parse(string $text, string $tz): ?self
    {
        $text = trim($text);

        if (preg_match('/^(24\/7|always|around the clock)$/i', $text) === 1) {
            return new self('00:00', '00:00', $tz);
        }

        if (preg_match('/^(\d{1,2}:\d{2})\s*[-–—]\s*(\d{1,2}:\d{2})(?:\s+(\S+))?$/u', $text, $m) !== 1) {
            return null;
        }

        $zone = $m[3] ?? $tz;
        if (! in_array($zone, DateTimeZone::listIdentifiers(), true) && $zone !== 'UTC') {
            return null;
        }

        foreach ([$m[1], $m[2]] as $time) {
            [$hour, $minute] = array_map('intval', explode(':', $time));
            if ($hour > 23 || $minute > 59) {
                return null; // 25:00, 9:75
            }
        }

        return new self(self::time($m[1]), self::time($m[2]), $zone);
    }

    /** "09:00–18:00 (Europe/Kyiv)", or "around the clock". */
    public function label(): string
    {
        return $this->from === $this->to ? 'around the clock' : $this->from.'–'.$this->to.' ('.$this->tz.')';
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
