<?php

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
    public function pickBestSrc(array $attrs): string
    {
        foreach (['data-full', 'data-highres'] as $key) {
            if (!empty($attrs[$key])) {
                return $attrs[$key];
            }
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
     */
    public function parseLargestFromSrcset(string $srcset): string
    {
        $best = '';
        $bestScore = -1.0;
        $first = '';

        foreach (array_filter(array_map('trim', explode(',', $srcset))) as $candidate) {
            $parts = preg_split('/\s+/', $candidate);
            $url = $parts[0];
            $first = $first === '' ? $url : $first;

            $score = 0.0;
            if (isset($parts[1]) && preg_match('/^([\d.]+)([wx])$/i', $parts[1], $m)) {
                $score = (float) $m[1]; // "w" aj "x" - vyššie číslo = väčšie rozlíšenie
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $url;
            }
        }

        return $best !== '' ? $best : $first;
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
