<?php

declare(strict_types=1);

namespace FG\Plugin\Content\Fgautolightbox\Support;

\defined('_JEXEC') or die;

/**
 * Rozhoduje, či a AKO má byť daný obsah spracovaný:
 *  - je kontext (napr. "com_content.article") v povolenom zozname?
 *  - aký je "component" prefix daného kontextu?
 *  - aký jedinečný kľúč identifikuje tento konkrétny obsah (pre zoskupovanie
 *    galérie - viď rel atribút, oprava P1 chyby zdieľaného zoskupenia
 *    naprieč viacerými článkami na jednej stránke)?
 */
final class ContentScopeResolver
{
    /** @var string[] */
    private const array DEFAULT_CONTEXTS = [
        'com_content.article',
        'com_content.featured',
        'com_content.category',
        'com_contact.contact',
        'com_newsfeeds.newsfeed',
    ];

    /**
     * @param string $extraContextsCsv Voliteľné rozšírenie z nastavení pluginu.
     *                                 Podporuje presný kontext ("com_k2.item")
     *                                 aj celý komponent naraz ("com_k2" alebo
     *                                 "com_k2.*").
     */
    public function isAllowed(string $context, string $extraContextsCsv): bool
    {
        [$extraContexts, $extraComponents] = $this->parseExtraContexts($extraContextsCsv);

        $contexts = [...self::DEFAULT_CONTEXTS, ...$extraContexts];

        return \in_array($context, $contexts, true)
            || \in_array($this->componentOf($context), $extraComponents, true);
    }

    public function componentOf(string $context): string
    {
        return explode('.', $context, 2)[0];
    }

    /**
     * Jedinečný "kľúč" tohto konkrétneho spracovávaného obsahu (článku/
     * kontaktu/položky) - zabezpečí, že sa galéria (rel atribút) zoskupuje
     * len v rámci TOHTO obsahu, nie naprieč celou stránkou (napr. zoznam
     * viacerých článkov na kategórii).
     *
     * Preferuje ->id (existuje pri com_content, com_contact, com_newsfeeds,
     * K2, Zoo aj väčšine ďalších komponentov), so spl_object_hash() ako
     * univerzálnou zálohou pre prípad, že by daný typ obsahu vlastnosť "id"
     * nemal.
     */
    public function instanceKeyFor(object $content): string
    {
        $id = $content->id ?? null;

        return $id !== null && $id !== '' ? (string) $id : spl_object_hash($content);
    }

    /**
     * @return array{0: string[], 1: string[]} [presné kontexty, komponenty-wildcard]
     */
    private function parseExtraContexts(string $csv): array
    {
        $contexts = [];
        $components = [];

        foreach (array_filter(array_map('trim', explode(',', $csv))) as $entry) {
            match (true) {
                str_ends_with($entry, '.*') => $components[] = substr($entry, 0, -2),
                !str_contains($entry, '.') => $components[] = $entry,
                default => $contexts[] = $entry,
            };
        }

        return [$contexts, $components];
    }
}
