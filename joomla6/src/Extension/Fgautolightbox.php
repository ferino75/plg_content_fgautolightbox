<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Content.fgautolightbox
 * @copyright   Copyright (C) 2026 FG. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace FG\Plugin\Content\Fgautolightbox\Extension;

use FG\Plugin\Content\Fgautolightbox\Support\CaptionMode;
use FG\Plugin\Content\Fgautolightbox\Support\ContentScopeResolver;
use FG\Plugin\Content\Fgautolightbox\Support\ExtensionFilter;
use FG\Plugin\Content\Fgautolightbox\Support\HtmlProcessor;
use FG\Plugin\Content\Fgautolightbox\Support\LinkAttributes;
use FG\Plugin\Content\Fgautolightbox\Support\SrcSetResolver;
use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Content - FG AutoLightbox (natívny Joomla 6 build).
 *
 * Automaticky pridá lightbox ku všetkým obrázkom v článkoch, bez zásahu
 * redaktora. Táto vetva podporuje výhradne Joomla 6+ (PHP 8.3+) - pre
 * Joomla 3.10 existuje samostatná, ďalej nevyvíjaná "classic" vetva v
 * koreňovom priečinku repozitára.
 */
final class Fgautolightbox extends CMSPlugin implements SubscriberInterface
{
    /**
     * Musí sa ručne udržiavať synchronizovaná s <version> v
     * fgautolightbox.xml pri každom vydaní - používa sa ako cache-bust
     * hodnota pre statické CSS/JS assety namiesto filemtime() (viď
     * getAssetCacheBuster() nižšie).
     */
    private const string VERSION = '2.3.9';

    private static bool $assetsLoaded = false;

    private readonly SrcSetResolver $srcSetResolver;
    private readonly ContentScopeResolver $scopeResolver;
    private readonly HtmlProcessor $htmlProcessor;

    public function __construct(DispatcherInterface $dispatcher, array $config = [])
    {
        parent::__construct($dispatcher, $config);

        // Skladanie závislostí priamo v konštruktore (nie cez kontajner) je
        // zámerný, pragmatický kompromis - tieto triedy sú bezstavové
        // (žiadne Joomla API, žiadne I/O v konštruktore) a Joomla plugin
        // systém sám osebe nepodporuje autowiring pre bežné pluginy mimo
        // services/provider.php.
        //
        // Presnejšie k DI: samotná trieda Fgautolightbox túto výhodu
        // nezdedí - stále si SrcSetResolver/ContentScopeResolver/
        // HtmlProcessor vytvára sama cez `new`, nedostáva ich zvonka. Reálny
        // benefit sa týka výhradne POMOCNÝCH tried (HtmlProcessor a jeho
        // závislosti) - tie majú nulové Joomla API, takže sa dajú
        // inštanciovať a testovať úplne izolovane, bez Joomla bootstrapu
        // alebo stubovania (overované počas vývoja automatizovanými testami,
        // ktoré nie sú súčasťou tohto inštalovaného balíka). Plná
        // constructor DI aj pre samotný plugin (injektovanie cez
        // services/provider.php namiesto `new` volaní tu) by bola možná,
        // ale pre rozsah tohto pluginu zámerne netreba komplikovať
        // architektúru len kvôli tomu.
        //
        // Používa $this->params (Registry, korektne naparsovaný Joomla
        // rodičovským konštruktorom vyššie), nie surové $config['params'] -
        // to by mohlo byť podľa verzie/kontextu buď pole, JSON reťazec,
        // alebo už hotový Registry objekt.
        $this->srcSetResolver = new SrcSetResolver();
        $this->scopeResolver = new ContentScopeResolver();
        $this->htmlProcessor = new HtmlProcessor(
            $this->srcSetResolver,
            new LinkAttributes(),
            ExtensionFilter::fromCsv((string) $this->params->get('allowed_extensions', 'jpg,jpeg,png,gif,webp,avif')),
        );
    }

    public static function getSubscribedEvents(): array
    {
        return ['onContentPrepare' => 'onContentPrepare'];
    }

    public function onContentPrepare(ContentPrepareEvent $event): void
    {
        // Konkrétna typovaná trieda udalosti namiesto všeobecného Event +
        // array_values($event->getArguments()) - ten druhý prístup je
        // oficiálne odporúčaný len vtedy, keď plugin musí zostať kompatibilný
        // aj s prípadným starším GenericEvent (napr. classic build,
        // podporujúci J3-J6 naraz). Tento natívny build cieli výhradne na
        // Joomla 6, kde je ContentPrepareEvent garantovaná, takže menované
        // gettery (getContext()/getItem()) sú tu presnejšie a čitateľnejšie.
        $this->handle($event->getContext(), $event->getItem());
    }

    private function handle(string $context, object $article): void
    {
        // Whitelist ("iba site"), nie blacklist ("nie administrator") - plugin
        // manipuluje HTML výstup a WebAssetManager frontendového dokumentu,
        // čo dáva zmysel len v 'site' kliente. Joomla pozná aj ďalšie kliento
        // typy (napr. 'api' pre Web Services REST API, 'cli' pre konzolu),
        // kde by tieto operácie boli prinajmenšom zbytočné a v najhoršom
        // prípade by mohli zlyhať (Document tam nemusí mať WebAssetManager
        // v očakávanej podobe).
        if (!$this->getApplication()->isClient('site')) {
            return;
        }

        $extraContexts = trim((string) $this->params->get('extra_contexts', ''));
        if (!$this->scopeResolver->isAllowed($context, $extraContexts)) {
            return;
        }

        $component = $this->scopeResolver->componentOf($context);
        if (\in_array($component, $this->csvList((string) $this->params->get('exclude_components', '')), true)) {
            return;
        }

        $excludeUrls = $this->csvList((string) $this->params->get('exclude_urls', ''));
        if ($excludeUrls !== [] && $this->urlMatchesAny($excludeUrls)) {
            return;
        }

        if (empty($article->text)) {
            return;
        }

        $instanceKey = $this->scopeResolver->instanceKeyFor($article);

        $wrappedCount = 0;
        $article->text = $this->htmlProcessor->wrapImages(
            (string) $article->text,
            $instanceKey,
            (string) $this->params->get('gallery_group', 'autolightbox-gallery'),
            (string) $this->params->get('link_class', 'autolightbox'),
            CaptionMode::tryFrom((string) $this->params->get('show_caption', 'alt')) ?? CaptionMode::Alt,
            $this->csvList((string) $this->params->get('exclude_classes', '')),
            $wrappedCount,
            (bool) (int) $this->params->get('prefer_srcset', 0),
        );

        // Drobná výkonová optimalizácia: ak sa v tomto obsahu neobalil ani
        // jeden obrázok, CSS/JS assety nie sú potrebné - S JEDNOU výnimkou:
        // ak je watch_dynamic zapnuté, JS engine (MutationObserver) treba
        // načítať aj tak, keďže obrázky sa môžu objaviť neskôr dynamicky
        // (AJAX), aj keď pri prvom vykreslení stránky ešte žiadne nie sú.
        $watchDynamic = (bool) (int) $this->params->get('watch_dynamic', 0);
        if ($wrappedCount > 0 || $watchDynamic) {
            $this->ensureAssetsLoaded();
        }
    }

    private function ensureAssetsLoaded(): void
    {
        if (self::$assetsLoaded) {
            return;
        }

        // Nutné pred Text::_() volaním nižšie - na frontende nie je zaručené,
        // že Joomla jazykový súbor tohto pluginu už automaticky načítala
        // (na rozdiel od admin formulára nastavení, kde sa to deje samo).
        // Bez tohto by Text::_() vrátil surový názov konštanty namiesto
        // prekladu.
        $this->loadLanguage();

        $linkClass = (string) $this->params->get('link_class', 'autolightbox');

        $config = [
            'linkClass' => $linkClass !== 'alb-link' ? $linkClass : '',
            'galleryGroup' => (string) $this->params->get('gallery_group', 'autolightbox-gallery'),
            'excludeClasses' => $this->csvList((string) $this->params->get('exclude_classes', '')),
            'showCaption' => (string) $this->params->get('show_caption', 'alt'),
            'captionMobile' => (bool) (int) $this->params->get('caption_mobile', 0),
            'watchDynamic' => (bool) (int) $this->params->get('watch_dynamic', 0),
            'showNavigation' => (bool) (int) $this->params->get('show_navigation', 1),
            'preloadAdjacent' => (bool) (int) $this->params->get('preload_adjacent', 1),
            'watchContainer' => trim((string) $this->params->get('watch_container', '')),
            'allowedExtensions' => $this->csvList(
                (string) $this->params->get('allowed_extensions', 'jpg,jpeg,png,gif,webp,avif'),
                lowercase: true,
            ),
            'preferSrcset' => (bool) (int) $this->params->get('prefer_srcset', 0),
            // Prekladateľné texty pre čítačky obrazovky (aria-label/aria-modal
            // popisky) - predtým boli natvrdo po slovensky v JS, takže by ich
            // pri en-GB administrácii čítačka hlásila po slovensky bez ohľadu
            // na jazyk stránky. JS strana má aj vlastný anglický fallback pre
            // prípad staršieho cachovaného configu bez tohto poľa.
            'labels' => [
                'dialog' => Text::_('PLG_CONTENT_FGAUTOLIGHTBOX_JS_DIALOG_LABEL'),
                'close' => Text::_('PLG_CONTENT_FGAUTOLIGHTBOX_JS_CLOSE_LABEL'),
                'previous' => Text::_('PLG_CONTENT_FGAUTOLIGHTBOX_JS_PREV_LABEL'),
                'next' => Text::_('PLG_CONTENT_FGAUTOLIGHTBOX_JS_NEXT_LABEL'),
                'error' => Text::_('PLG_CONTENT_FGAUTOLIGHTBOX_JS_ERROR_LABEL'),
            ],
        ];

        $wa = $this->getApplication()->getDocument()->getWebAssetManager();
        $mediaBase = $this->getMediaBaseUri();
        $cacheBuster = $this->getAssetCacheBuster();

        // Priama registrácia cez registerAndUseStyle()/registerAndUseScript()
        // namiesto addRegistryFile('...joomla.asset.json') + useStyle()/
        // useScript(). Pôvodný prístup cez registry súbor sa na živom
        // Joomla 6.1.2 webe overil ako nefunkčný - inline config script sa
        // vyrenderoval správne, ale samotné <link>/<script src> pre CSS/JS
        // sa do stránky vôbec nedostali (potichu, bez chyby). Táto priamejšia
        // metóda nepotrebuje žiadny registry súbor a je to zdokumentovaný,
        // priamo volateľný spôsob pre jednorazovú registráciu vlastného assetu.
        $wa->registerAndUseStyle(
            'plg_content_fgautolightbox.style',
            $mediaBase . '/css/fgautolightbox.css?v=' . $cacheBuster,
        );
        $wa->registerAndUseScript(
            'plg_content_fgautolightbox.script',
            $mediaBase . '/js/fgautolightbox.js?v=' . $cacheBuster,
            [],
            ['defer' => true],
        );

        // JSON_HEX_* flagy sú obranná vrstva navyše (config prichádza z
        // administrácie, ktorú upravuje dôveryhodný administrátor, nie z
        // nedôveryhodného vstupu) - zabránia tomu, aby hodnota z nastavení
        // mohla vytvoriť sekvenciu ako </script> v <script> kontexte.
        //
        // JSON_THROW_ON_ERROR: $config obsahuje len jednoduché stringy/booly/
        // pole reťazcov poskladané z Joomla nastavení, takže zlyhanie
        // json_encode() je v praxi prakticky vylúčené - ide o čisto obrannú
        // vrstvu. Pri (extrémne nepravdepodobnom) zlyhaní sa použije prázdny
        // objekt namiesto vyrenderovania nedokončeného/poškodeného JS - JS
        // engine má vlastné predvolené hodnoty pre každú položku configu, takže
        // s prázdnym objektom naďalej funguje korektne (len s predvolenými
        // nastaveniami namiesto tých z administrácie).
        try {
            $configJson = json_encode(
                $config,
                \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            $configJson = '{}';
        }
        $wa->addInlineScript(
            'window.FG_AUTOLIGHTBOX_CONFIG = ' . $configJson . ';',
            [],
            [],
            ['plg_content_fgautolightbox.script'],
        );

        self::$assetsLoaded = true;
    }

    private function getMediaBaseUri(): string
    {
        return rtrim(Uri::root(true), '/') . '/media/plg_content_fgautolightbox';
    }

    /**
     * Cache-busting hodnota pre statický CSS/JS súbor, založená na čase jeho
     * poslednej zmeny - rovnaký, osvedčený mechanizmus ako v classic builde.
     */
    /**
     * Cache-bust hodnota pre statický CSS/JS súbor. Predtým filemtime() -
     * ale na load-balanced hostingu (viacero serverov za jedným webom) môže
     * mať ten istý súbor iný mtime na každom serveri (napr. mierne odlišný
     * čas nasadenia, artefakty synchronizácie súborového systému), čo môže
     * viesť k nekonzistentným URL medzi requestmi obsluhovanými rôznymi
     * servermi - a teda k zbytočnému znovunačítaniu z pohľadu prehliadača/
     * CDN cache, alebo v horšom prípade k nekonzistentnému stavu medzi
     * návštevníkmi. Verzia pluginu je naproti tomu identická všade,
     * nezávisle od súborového systému konkrétneho servera.
     */
    private function getAssetCacheBuster(): string
    {
        return self::VERSION;
    }

    /**
     * @return string[]
     */
    private function csvList(string $csv, bool $lowercase = false): array
    {
        $values = array_filter(array_map('trim', explode(',', $csv)));

        return $lowercase ? array_values(array_map('strtolower', $values)) : array_values($values);
    }

    /**
     * @param string[] $patterns
     */
    private function urlMatchesAny(array $patterns): bool
    {
        $currentUri = Uri::getInstance()->toString(['path', 'query']);

        foreach ($patterns as $pattern) {
            if ($this->urlPatternMatches($currentUri, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Predtým čistý "podreťazec kdekoľvek" (mb_stripos), čo malo problém:
     * vzor "/12" zachytil aj "/120", "/1129", alebo "?id=12X" - čokoľvek,
     * kde sa "12" vyskytlo ako súčasť dlhšieho tokenu, nie len samostatne.
     *
     * Predvolené správanie teraz rešpektuje hranice - vzor sa musí
     * vyskytovať tak, že bezprostredne pred/za ním NIE JE alfanumerický
     * znak (teda je ohraničený napr. "/", "?", "&", "=", "." alebo
     * začiatkom/koncom URL). To rieši presne uvedený prípad bez toho, aby
     * bolo treba čokoľvek meniť na existujúcich vzoroch, ktoré už
     * fungovali správne.
     *
     * Ak niekto zámerne chce pôvodné "substring kdekoľvek" správanie
     * (napr. zachytiť viacero podobných URL naraz), môže použiť "*" ako
     * jednoduchý glob wildcard (napr. "/kontakt*" zachytí aj "/kontakt-
     * nas-tim", "/kontakty" atď.) - plnohodnotný regex zámerne nie je
     * podporovaný, aby nastavenie zostalo jednoduché a bezpečné aj pre
     * netechnického administrátora.
     */
    private function urlPatternMatches(string $currentUri, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        if (str_contains($pattern, '*')) {
            $regex = '#' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '#iu';

            return preg_match($regex, $currentUri) === 1;
        }

        // Hraničná kontrola sa aplikuje LEN na tej strane vzoru, kde je jeho
        // vlastný okrajový znak alfanumerický. Ak vzor už sám začína/končí
        // oddeľovačom (napr. "/12" začína "/"), netreba vyžadovať ešte
        // JEDEN oddeľovač navyše pred ním - to by naopak nesprávne odmietlo
        // legitímne prípady ako ".../nieco/12" (kde "/" z "12" je tá istá
        // hranica, nie duplicitná).
        $quoted = preg_quote($pattern, '#');
        $startsAlnum = preg_match('/^[A-Za-z0-9_]/', $pattern) === 1;
        $endsAlnum = preg_match('/[A-Za-z0-9_]$/', $pattern) === 1;

        $regex = '#'
            . ($startsAlnum ? '(?<![A-Za-z0-9_])' : '')
            . $quoted
            . ($endsAlnum ? '(?![A-Za-z0-9_])' : '')
            . '#iu';

        return preg_match($regex, $currentUri) === 1;
    }
}
