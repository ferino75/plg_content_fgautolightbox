<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Content.fgautolightbox
 * @copyright   Copyright (C) 2026 FG. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace FG\Plugin\Content\Fgautolightbox\Support;

\defined('_JEXEC') or die;

/**
 * Kontroluje, či má obrázok povolenú príponu súboru A bezpečnú URL schému.
 * SVG je v predvolenom zozname zámerne vynechané, keďže môže obsahovať
 * vložené skripty.
 */
final class ExtensionFilter
{
    /**
     * Rovnaký zoznam ako predvolená hodnota poľa allowed_extensions v
     * manifeste - použije sa, ak administrátor pole v nastaveniach úplne
     * vyprázdni. Prázdny zoznam sa ZÁMERNE nechápe ako "povoliť všetko" -
     * to by okrem SVG (viď vyššie) otvorilo dvere aj nebezpečným URL
     * schémam ako javascript: alebo data:, keby sa niekde v reťazci
     * spracovania objavili ako hodnota src/data-full atribútu.
     *
     * @var string[]
     */
    private const array DEFAULT_ALLOWED = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    /**
     * @var string[] Povolené URL schémy (malými písmenami). Žiadna schéma
     *               (relatívna cesta ako "/images/x.jpg" alebo "x.jpg")
     *               je vždy v poriadku - rieši sa samostatne v
     *               hasSafeScheme().
     */
    private const array SAFE_SCHEMES = ['http', 'https'];

    /**
     * @param string[] $allowed Zoznam povolených prípon (malými písmenami,
     *                          bez bodky). Prázdny zoznam pri priamej
     *                          konštrukcii (mimo fromCsv()) sa správa
     *                          "fail closed" - nič nepovolí - keďže cez
     *                          fromCsv() sa sem prázdne pole už nikdy
     *                          nedostane (viď nižšie).
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
        $allowed = array_values($allowed);

        return new self($allowed !== [] ? $allowed : self::DEFAULT_ALLOWED);
    }

    public function isAllowed(string $src): bool
    {
        if (!$this->hasSafeScheme($src)) {
            return false;
        }

        if ($this->allowed === []) {
            return false;
        }

        $path = parse_url($src, PHP_URL_PATH);
        $path = \is_string($path) ? $path : $src;

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $ext !== '' && \in_array($ext, $this->allowed, true);
    }

    /**
     * Zamietne nebezpečné URL schémy (javascript:, data:, vbscript:...),
     * ktoré by sa teoreticky mohli objaviť ako hodnota src/data-full/
     * data-highres atribútu (napr. pri kompromitovanom alebo nesprávne
     * použitom editore). Relatívne URL bez schémy (bežný prípad) sú vždy
     * v poriadku.
     */
    private function hasSafeScheme(string $src): bool
    {
        $scheme = parse_url($src, PHP_URL_SCHEME);

        if ($scheme === null || $scheme === false || $scheme === '') {
            return true;
        }

        return \in_array(strtolower($scheme), self::SAFE_SCHEMES, true);
    }
}
