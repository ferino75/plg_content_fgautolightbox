<?php

declare(strict_types=1);

namespace FG\Plugin\Content\Fgautolightbox\Support;

\defined('_JEXEC') or die;

/**
 * Kontroluje, či má obrázok povolenú príponu súboru. SVG je v predvolenom
 * zozname zámerne vynechané, keďže môže obsahovať vložené skripty.
 */
final class ExtensionFilter
{
    /**
     * @param string[] $allowed Zoznam povolených prípon (malými písmenami,
     *                          bez bodky). Prázdny zoznam = bez obmedzenia.
     */
    public function __construct(private readonly array $allowed)
    {
    }

    public static function fromCsv(string $csv): self
    {
        $allowed = array_map(
            'strtolower',
            array_filter(array_map('trim', explode(',', $csv)))
        );

        return new self(array_values($allowed));
    }

    public function isAllowed(string $src): bool
    {
        if ($this->allowed === []) {
            return true;
        }

        $path = parse_url($src, PHP_URL_PATH);
        $path = \is_string($path) ? $path : $src;

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $ext !== '' && \in_array($ext, $this->allowed, true);
    }
}
