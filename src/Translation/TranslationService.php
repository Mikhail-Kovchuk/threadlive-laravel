<?php

declare(strict_types=1);

namespace Threadlive\Translation;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Threadlive\Contracts\Translator;
use Throwable;

/**
 * The configured translator, behind one rule: a translation that fails is
 * reported and skipped. A message is never held up or lost for it.
 */
final class TranslationService
{
    public function __construct(private readonly Container $container) {}

    public function enabled(): bool
    {
        return $this->translator() !== null;
    }

    /** The managers' language, unless /translate names another. */
    public function defaultTarget(): string
    {
        $target = config('threadlive.translation.target', 'en');

        return Languages::base(is_string($target) && $target !== '' ? $target : 'en');
    }

    /**
     * What /translate <language> may choose.
     *
     * @return list<string>
     */
    public function languages(): array
    {
        $languages = config('threadlive.translation.languages', []);
        $languages = array_map(
            fn ($code) => Languages::base((string) $code),
            array_filter(is_array($languages) ? $languages : [], 'is_string'),
        );

        return array_values(array_unique([$this->defaultTarget(), ...$languages]));
    }

    public function translate(string $text, string $target, ?string $source = null): ?Translation
    {
        $translator = $this->translator();

        if ($translator === null || trim($text) === '') {
            return null;
        }

        try {
            return $translator->translate($text, $target, $source);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function translator(): ?Translator
    {
        $driver = config('threadlive.translation.driver');

        if ($driver === 'deepl') {
            return new DeepLTranslator(
                $this->container->make(HttpFactory::class),
                (string) config('threadlive.translation.key'),
            );
        }

        if (is_string($driver) && class_exists($driver)) {
            $translator = $this->container->make($driver);

            return $translator instanceof Translator ? $translator : null;
        }

        return null;
    }
}
