<?php

declare(strict_types=1);

namespace Threadlive;

use Threadlive\Telegram\VisitorCard;

/**
 * What a manager command answers, built up and escaped for Telegram:
 *
 *   CommandReply::make('Invoice INV-1042')
 *       ->line('Status', 'Paid')
 *       ->line('Customer', 'anna@example.com', url('/admin/customers/7'))
 *       ->link(url('/admin/invoices/1042'));
 *
 * Plain text everywhere; links only http(s).
 */
final class CommandReply
{
    /** @var list<array{label: string, value: string, url?: string}> */
    private array $lines = [];

    private ?string $url = null;

    private string $linkLabel = 'Open in the admin panel';

    private function __construct(private readonly string $title) {}

    public static function make(string $title): self
    {
        return new self($title);
    }

    /** "• Label: value", the value linked when a URL is given. A null value leaves the line out. */
    public function line(string $label, string|int|float|null $value, ?string $url = null): self
    {
        if ($value === null || $value === '') {
            return $this;
        }

        $line = ['label' => $label, 'value' => (string) $value];
        if ($url !== null) {
            $line['url'] = $url;
        }
        $this->lines[] = $line;

        return $this;
    }

    /** One link at the end, e.g. to the record in the admin panel. */
    public function link(string $url, string $label = 'Open in the admin panel'): self
    {
        $this->url = $url;
        $this->linkLabel = $label;

        return $this;
    }

    public function toHtml(): string
    {
        $html = ['<b>'.e($this->title).'</b>'];

        foreach ($this->lines as $line) {
            $formatted = VisitorCard::line($line);
            if ($formatted !== null) {
                $html[] = $formatted;
            }
        }

        $url = VisitorCard::webUrl($this->url);
        if ($url !== null) {
            $html[] = '🛠 <a href="'.e($url).'">'.e($this->linkLabel).'</a>';
        }

        return implode("\n", $html);
    }
}
