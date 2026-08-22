<?php

declare(strict_types=1);

namespace FG\Plugin\Content\Fgautolightbox\Support;

\defined('_JEXEC') or die;

/**
 * Jadro spracovania - nájde obrázky v HTML a obalí ich lightbox odkazom.
 * Zámerne bez závislosti na Joomla API (žiadne $this->params, žiadne
 * Factory:: volania), aby sa dala testovať úplne izolovane.
 *
 * Poznámka k architektúre: klasický (J3-kompatibilný) build mal aj regex
 * fallback pre prípad, že by DOMDocument nebolo na serveri dostupné. Tento
 * natívny J6-only build ho zámerne nemá - rozšírenie "dom" je súčasťou
 * povinných PHP modulov, ktoré Joomla 6 samotná vyžaduje, takže scenár
 * "DOMDocument chýba" tu reálne nemôže nastať.
 */
final class HtmlProcessor
{
    public function __construct(
        private readonly SrcSetResolver $srcSetResolver,
        private readonly LinkAttributes $linkAttributes,
        private readonly ExtensionFilter $extensionFilter,
    ) {
    }

    /**
     * @param string[] $excludeClasses
     * @param int      $wrappedCount Voliteľný výstupný parameter (odovzdaný
     *                               referenciou) - počet skutočne obalených
     *                               obrázkov. Umožňuje volajúcemu kódu
     *                               rozhodnúť, či má zmysel načítať CSS/JS
     *                               assety (viď Fgautolightbox::handle()).
     */
    public function wrapImages(
        string $html,
        string $instanceKey,
        string $galleryGroupBase,
        string $linkClass,
        CaptionMode $captionMode,
        array $excludeClasses,
        int &$wrappedCount = 0,
    ): string {
        $wrappedCount = 0;
        $galleryGroup = $instanceKey !== '' ? $galleryGroupBase . ':' . $instanceKey : $galleryGroupBase;

        $dom = new \DOMDocument('1.0', 'UTF-8');

        // Ulož predchádzajúci stav libxml error handlingu a po skončení ho
        // korektne obnov (nie len vyčisti chyby) - inak by sa natrvalo
        // zmenil globálny PHP stav pre zvyšok requestu.
        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            // Plný <html><body> obal je zámerný: skúšaný zápis bez neho
            // (LIBXML_HTML_NOIMPLIED) poškodzoval HTML štruktúru pri
            // viacerých susediacich <p> elementoch (bežná štruktúra
            // Joomla článku) - zlieval prvé dva <p> do seba.
            $wrapped = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE html><html>'
                . '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>'
                . '<body>' . $html . '</body></html>';
            $loaded = $dom->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }

        if (!$loaded) {
            return $html;
        }

        $xpath = new \DOMXPath($dom);
        $images = $xpath->query('//img[not(ancestor::a)]');

        foreach ($images as $img) {
            if (!$img instanceof \DOMElement) {
                continue;
            }

            $srcVal = $this->srcSetResolver->pickBestSrc([
                'data-full' => $img->getAttribute('data-full'),
                'data-highres' => $img->getAttribute('data-highres'),
                'data-src' => $img->getAttribute('data-src'),
                'srcset' => $this->srcSetResolver->getCombinedSrcset($img),
                'src' => $img->getAttribute('src'),
            ]);

            if ($srcVal === '' || !$this->extensionFilter->isAllowed($srcVal)) {
                continue;
            }

            if ($this->hasExcludedClass($img, $excludeClasses)) {
                continue;
            }

            $altValue = $img->getAttribute('alt');
            if ($altValue === '' && $img->hasAttribute('data-alt')) {
                $altValue = $img->getAttribute('data-alt');
            }

            $title = $this->linkAttributes->buildTitle($srcVal, $altValue, $captionMode);
            $cssClass = $this->linkAttributes->buildLinkClass($linkClass);

            $a = $dom->createElement('a');
            $a->setAttribute('href', $srcVal);
            $a->setAttribute('class', $cssClass);
            $a->setAttribute('rel', $galleryGroup);
            if ($title !== '') {
                $a->setAttribute('title', $title);
            }
            // Prístupný "alt" pre lightbox - vždy, nezávisle od show_caption
            // (to ovplyvňuje len viditeľný popisok, nie čítačku obrazovky).
            if ($altValue !== '') {
                $a->setAttribute('data-alb-alt', $altValue);
            }

            // Ak je obrázok vnútri <picture>, obaľ CELÝ <picture> element,
            // nie len <img> v ňom - inak by prestalo fungovať natívne
            // prepínanie <source> na stránke (vyžaduje priame potomstvo).
            $wrapTarget = $this->srcSetResolver->findPictureAncestor($img) ?? $img;
            $wrapTarget->parentNode?->insertBefore($a, $wrapTarget);
            $a->appendChild($wrapTarget);
            ++$wrappedCount;
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return $html;
        }

        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }

        return $result;
    }

    /**
     * @param string[] $excludeClasses
     */
    private function hasExcludedClass(\DOMElement $img, array $excludeClasses): bool
    {
        if ($excludeClasses === []) {
            return false;
        }

        $imgClasses = preg_split('/\s+/', trim($img->getAttribute('class'))) ?: [];

        return array_intersect($excludeClasses, $imgClasses) !== [];
    }
}
