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
 * Vyberie najlepšiu dostupnú URL obrázka a rieši prácu s <picture>/<source>.
 *
 * Priorita zdroja URL:
 *   1. data-full / data-highres - explicitná autorská voľba plnohodnotného obrázka
 *   2. data-src                 - bežná lazy-load konvencia
 *   3. najväčšie rozlíšenie zo srcset (vrátane <picture><source>)
 *   4. src                      - posledná záloha
 */
final class SrcSetResolver
{
    /**
     * @param array<string, string> $attrs Asociatívne pole atribútov obrázka.
     */
    /**
     * @param array<string, string> $attrs Asociatívne pole atribútov obrázka.
     * @param bool $preferSrcset Ak true, poradie sa mení na
     *                           data-full/data-highres > srcset > data-src > src
     *                           namiesto predvoleného
     *                           data-full/data-highres > data-src > srcset > src.
     *                           "data-src" je v praxi nejednoznačný - niektoré
     *                           lazy-load knižnice ho používajú ako skutočný
     *                           plnohodnotný obrázok (predvolené poradie tomu
     *                           zodpovedá, overené priamo na produkčnom bugu),
     *                           iné ho používajú ako malý placeholder popri
     *                           plnohodnotnom responzívnom srcset. Keďže oboje
     *                           reálne existuje, správne poradie závisí od
     *                           konkrétnej stránky - preto je to nastavenie,
     *                           nie natvrdo dané rozhodnutie.
     */
    public function pickBestSrc(array $attrs, bool $preferSrcset = false): string
    {
        foreach (['data-full', 'data-highres'] as $key) {
            if (!empty($attrs[$key])) {
                return $attrs[$key];
            }
        }

        if ($preferSrcset) {
            if (!empty($attrs['srcset'])) {
                $fromSrcset = $this->parseLargestFromSrcset($attrs['srcset']);
                if ($fromSrcset !== '') {
                    return $fromSrcset;
                }
            }

            return !empty($attrs['data-src']) ? $attrs['data-src'] : ($attrs['src'] ?? '');
        }

        if (!empty($attrs['data-src'])) {
            return $attrs['data-src'];
        }

        if (!empty($attrs['srcset'])) {
            $fromSrcset = $this->parseLargestFromSrcset($attrs['srcset']);
            if ($fromSrcset !== '') {
                return $fromSrcset;
            }
        }

        return $attrs['src'] ?? '';
    }

    /**
     * Z hodnoty atribútu srcset vyberie URL s najväčším rozlíšením (podľa "w"
     * alebo "x" deskriptora). Pri chýbajúcom/nejednoznačnom deskriptore sa
     * použije prvá položka ako záloha.
     *
     * Dôležité: "w" (šírka v pixeloch) a "x" (hustota pixelov) NIE SÚ
     * navzájom porovnateľné jednotky na tej istej škále - "2x" a "1200w"
     * nemožno zmysluplne porovnať ako čísla 2 vs 1200. Keďže táto metóda
     * môže dostať kombinovaný zoznam poskladaný z viacerých <source>
     * elementov (viď getCombinedSrcset()), z ktorých každý môže používať
     * iný typ deskriptora, postupuje sa takto:
     *   1. ak existuje aspoň jeden "w" kandidát, porovnávajú sa LEN "w"
     *      kandidáti (najväčšia šírka vyhráva, "x" kandidáti sa ignorujú)
     *   2. inak, ak existujú len "x" kandidáti, vyberie sa najväčší "x"
     *   3. inak (žiadne deskriptory) - záloha na prvú položku
     */
    public function parseLargestFromSrcset(string $srcset): string
    {
        $candidates = [];
        $first = '';

        foreach (array_filter(array_map('trim', explode(',', $srcset))) as $candidate) {
            $parts = preg_split('/\s+/', $candidate);
            $url = $parts[0];
            $first = $first === '' ? $url : $first;

            $type = null;
            $value = 0.0;
            if (isset($parts[1]) && preg_match('/^([\d.]+)([wx])$/i', $parts[1], $m)) {
                $type = strtolower($m[2]);
                $value = (float) $m[1];
            }

            $candidates[] = ['url' => $url, 'type' => $type, 'value' => $value];
        }

        $wCandidates = array_filter($candidates, static fn (array $c) => $c['type'] === 'w');
        $xCandidates = array_filter($candidates, static fn (array $c) => $c['type'] === 'x');

        $pool = $wCandidates !== [] ? $wCandidates : $xCandidates;

        if ($pool === []) {
            return $first;
        }

        $best = null;
        $bestValue = -1.0;
        foreach ($pool as $candidate) {
            if ($candidate['value'] > $bestValue) {
                $bestValue = $candidate['value'];
                $best = $candidate['url'];
            }
        }

        return $best ?? $first;
    }

    /**
     * Nájde najbližšieho predka <picture> (nie nutne priameho rodiča).
     *
     * DOMDocument/libxml totiž <source> nespracúva ako "prázdny" (void)
     * element podľa HTML5 špecifikácie, takže <img> za ním sa mu často vnorí
     * DOVNÚTRA <source>, nie vedľa neho ako sused. Preto sa stúpa nahor, kým
     * sa nenájde <picture> - skutočne servovaný HTML výstup sa napriek tomu
     * v reálnom prehliadači parsuje správne (overené priamo proti spec
     * kompatibilnému parseru), toto je len obchádzka internej libxml
     * reprezentácie počas serverového spracovania.
     */
    public function findPictureAncestor(\DOMElement $img): ?\DOMElement
    {
        $ancestor = $img->parentNode;
        while ($ancestor instanceof \DOMElement) {
            if (strtolower($ancestor->nodeName) === 'picture') {
                return $ancestor;
            }
            $ancestor = $ancestor->parentNode;
        }

        return null;
    }

    /**
     * Zloží kombinovaný srcset reťazec zo samotného <img> aj zo VŠETKÝCH
     * <source> potomkov najbližšieho <picture> predka (getElementsByTagName
     * ide do hĺbky, nie len priami súrodenci) - vyberie sa najväčšie
     * rozlíšenie naprieč všetkými zdrojmi, nezávisle od toho, ktorý by si
     * aktuálne zvolil prehliadač podľa media/type podmienok.
     */
    public function getCombinedSrcset(\DOMElement $img): string
    {
        $parts = [];

        $ownSrcset = $img->getAttribute('srcset');
        if ($ownSrcset !== '') {
            $parts[] = $ownSrcset;
        }

        $picture = $this->findPictureAncestor($img);
        if ($picture !== null) {
            foreach ($picture->getElementsByTagName('source') as $source) {
                $sourceSrcset = $source->getAttribute('srcset');
                if ($sourceSrcset !== '') {
                    $parts[] = $sourceSrcset;
                }
            }
        }

        return implode(', ', $parts);
    }
}
