<?php

declare(strict_types=1);

namespace Threadlive\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Threadlive\Enums\Direction;
use Threadlive\Models\Message;

/**
 * A file of a message, for the widget. The route is signed (the link came
 * with the message, for this visitor); this checks the file belongs to that
 * message, and never serves a note's.
 */
final class FileController
{
    public function show(string $message, string $file): StreamedResponse
    {
        $record = Message::query()->whereKey((int) $message)->where('direction', '!=', Direction::Note)->first();
        $stored = $record === null ? null : collect($record->attachments ?? [])->first(fn (array $f) => ($f['id'] ?? null) === $file);

        abort_if(! is_array($stored) || ! is_string($stored['disk'] ?? null) || ! is_string($stored['path'] ?? null), 404);

        $disk = Storage::disk($stored['disk']);
        abort_unless($disk->exists($stored['path']), 404);

        $mime = is_string($stored['mime'] ?? null) ? $stored['mime'] : 'application/octet-stream';
        $name = is_string($stored['name'] ?? null) ? $stored['name'] : 'file';

        // Images and PDFs open in the browser; anything else downloads. Never
        // sniffed into something else.
        $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf';

        return $disk->response($stored['path'], $name, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
        ], $inline ? 'inline' : 'attachment');
    }
}
