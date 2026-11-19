<?php

declare(strict_types=1);

namespace Threadlive\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;

/**
 * Files on messages, as stored in `messages.attachments`:
 *
 *   {id, disk, path, name, mime, size}
 *
 * On a private disk, under threadlive/<conversation public id>/. The visitor
 * gets them only through signed, expiring links — an <img> cannot send the
 * visitor's token.
 */
final class Attachments
{
    /** How long a link in the widget works; history is fetched again on opening. */
    private const LINK_DAYS = 7;

    private const MIMES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf',
        'heic' => 'image/heic',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'zip' => 'application/zip',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public static function enabled(): bool
    {
        return (bool) config('threadlive.attachments.enabled', false);
    }

    public static function disk(): string
    {
        $disk = config('threadlive.attachments.disk', 'local');

        return is_string($disk) && $disk !== '' ? $disk : 'local';
    }

    public static function maxKb(): int
    {
        return max(1, (int) config('threadlive.attachments.max_kb', 10240));
    }

    public static function maxFiles(): int
    {
        return max(1, (int) config('threadlive.attachments.max_files', 5));
    }

    /**
     * @return list<string> lowercase extensions
     */
    public static function types(): array
    {
        $types = config('threadlive.attachments.types', []);

        return array_values(array_unique(array_map(
            fn (string $type) => strtolower(ltrim($type, '.')),
            array_filter(is_array($types) ? $types : [], 'is_string'),
        )));
    }

    /**
     * What the widget's file picker accepts.
     *
     * @return list<string>
     */
    public static function accept(): array
    {
        return array_values(array_unique(array_map(
            fn (string $type) => self::MIMES[$type] ?? '.'.$type,
            self::types(),
        )));
    }

    /** "PNG, JPG, WEBP, GIF or PDF", for messages people read. */
    public static function typesForPeople(): string
    {
        $types = array_values(array_diff(array_map('strtoupper', self::types()), ['JPEG']));
        $last = array_pop($types);

        return $types === [] ? (string) $last : implode(', ', $types).' or '.$last;
    }

    public static function allowsExtension(string $extension): bool
    {
        return in_array(strtolower($extension), self::types(), true);
    }

    /**
     * @return array{id: string, disk: string, path: string, name: string, mime: string, size: int}
     */
    public static function storeUpload(UploadedFile $file, Conversation $conversation): array
    {
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');

        return self::put(
            (string) $file->get(),
            self::cleanName($file->getClientOriginalName(), $extension),
            $file->getMimeType() ?: (self::MIMES[$extension] ?? 'application/octet-stream'),
            $extension,
            $conversation,
        );
    }

    /**
     * @return array{id: string, disk: string, path: string, name: string, mime: string, size: int}
     */
    public static function storeContents(string $contents, string $name, string $extension, Conversation $conversation, ?string $mime = null): array
    {
        $extension = strtolower($extension);

        return self::put($contents, self::cleanName($name, $extension), $mime ?: (self::MIMES[$extension] ?? 'application/octet-stream'), $extension, $conversation);
    }

    public static function isImage(string $mime): bool
    {
        return str_starts_with($mime, 'image/');
    }

    /**
     * What the widget gets for a stored file: no disk, no path — a link.
     *
     * @param  array<string, mixed>  $file
     * @return array{id: string, name: string, mime: string, size: int, kind: string, url: string}
     */
    public static function forVisitor(Message $message, array $file): array
    {
        $mime = is_string($file['mime'] ?? null) ? $file['mime'] : 'application/octet-stream';

        return [
            'id' => (string) ($file['id'] ?? ''),
            'name' => (string) ($file['name'] ?? 'file'),
            'mime' => $mime,
            'size' => (int) ($file['size'] ?? 0),
            'kind' => self::isImage($mime) ? 'image' : 'file',
            // Relative and signed as such: the widget puts the API's origin
            // in front, so a proxy or CDN in between cannot break the check.
            'url' => URL::temporarySignedRoute(
                'threadlive.files.show',
                now()->addDays(self::LINK_DAYS),
                ['message' => $message->id, 'file' => (string) ($file['id'] ?? '')],
                absolute: false,
            ),
        ];
    }

    /**
     * @return array{id: string, disk: string, path: string, name: string, mime: string, size: int}
     */
    private static function put(string $contents, string $name, string $mime, string $extension, Conversation $conversation): array
    {
        $id = (string) Str::uuid();
        $path = "threadlive/{$conversation->public_id}/{$id}.{$extension}";
        Storage::disk(self::disk())->put($path, $contents);

        return ['id' => $id, 'disk' => self::disk(), 'path' => $path, 'name' => $name, 'mime' => $mime, 'size' => strlen($contents)];
    }

    /** No paths, no control characters, a sane length — and the right extension. */
    private static function cleanName(string $name, string $extension): string
    {
        $base = pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[\x00-\x1F\x7F"<>|:*?]+/u', '', $base));

        return Str::limit($base !== '' ? $base : 'file', 100, '').'.'.$extension;
    }
}
