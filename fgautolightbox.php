<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Zdieľaná logika pluginu - nezávislá od toho, akým spôsobom Joomla event
 * doručí (starý pozičný spôsob v J3 vs. Event objekt v J4/5/6). Obe varianty
 * triedy nižšie túto logiku len volajú so správne extrahovanými argumentmi.
 */
trait FgautolightboxLogic
{
    private static $assetsLoaded = false;

    /**
     * @param string $context
     * @param object $article
     * @param object $params
     * @param int    $page    Nepoužíva sa - plugin nerozlišuje správanie podľa
     *                         stránkovania. Ponechané v signatúre kvôli zhode
     *                         s pozičnými argumentmi, ktoré Joomla posiela pre
     *                         event onContentPrepare (context, item, params, page).
     */
    private function handleContentPrepare($context, $article, $params, $page = 0)
    {
        // J3: isAdmin() | J4/J5/J6: isClient('administrator')
        $app = Factory::getApplication();
        $isAdmin = method_exists($app, 'isClient')
            ? $app->isClient('administrator')
            : $app->isAdmin();

        if ($isAdmin) {
            return true;
        }

        // Kontext má formát "com_xxxxx.yyyyy" - vytiahni názov komponentu
        $component = explode('.', $context)[0];

        // Predvolený zoznam kontextov, kde plugin spracúva obrázky
        $allowedContexts = array(
            'com_content.article', 'com_content.featured', 'com_content.category',
            'com_contact.contact', 'com_newsfeeds.newsfeed',
        );

        // Rozšírenie zoznamu o vlastné kontexty z nastavení (napr. K2, Zoo,
        // vlastné komponenty). Podporuje presný kontext ("com_k2.item") aj
        // celý komponent naraz ("com_k2" alebo "com_k2.*").
        $extraContextsRaw = trim($this->params->get('extra_contexts', ''));
        $extraComponents = array();
        if (!empty($extraContextsRaw)) {
            foreach (explode(',', $extraContextsRaw) as $entry) {
                $entry = trim($entry);
                if ($entry === '') {
                    continue;
                }
                if (substr($entry, -2) === '.*') {
                    $extraComponents[] = substr($entry, 0, -2);
                } elseif (strpos($entry, '.') === false) {
                    $extraComponents[] = $entry; // len nazov komponentu = cely wildcard
                } else {
                    $allowedContexts[] = $entry; // presny kontext
                }
            }
        }

        $isAllowed = in_array($context, $allowedContexts) || in_array($component, $extraComponents);
        if (!$isAllowed) {
            return true;
        }

        // Vylúčenie podľa komponentu (nastavenie v administrácii pluginu)
        $excludeComponentsRaw = trim($this->params->get('exclude_components', ''));
        if (!empty($excludeComponentsRaw)) {
            $excludeComponents = array_map('trim', explode(',', $excludeComponentsRaw));
            if (in_array($component, $excludeComponents)) {
                return true;
            }
        }

        // Vylúčenie podľa URL / cesty stránky
        $excludeUrlsRaw = trim($this->params->get('exclude_urls', ''));
        if (!empty($excludeUrlsRaw)) {
            $currentUri = $this->getCurrentUrl();
            $patterns = array_map('trim', explode(',', $excludeUrlsRaw));
            foreach ($patterns as $pattern) {
                if ($pattern === '') {
                    continue;
                }
                if (mb_stripos($currentUri, $pattern) !== false) {
                    return true;
                }
            }
        }

        if (empty($article->text)) {
            return true;
        }

        // Jedinečný "kľúč" tohto konkrétneho obsahu (článku/položky) - zabezpečí,
        // že galéria (šípky navigácie) zoskupuje obrázky len v rámci TOHTO
        // obsahu, nie naprieč celou stránkou (napr. zoznam viacerých článkov
        // na kategórii). Viď getInstanceKey().
        $instanceKey = $this->getInstanceKey($article);

        $article->text = $this->wrapImages($article->text, $instanceKey);

        if (!self::$assetsLoaded) {
            $linkClass    = $this->params->get('link_class', 'autolightbox');
            $galleryGroup = $this->params->get('gallery_group', 'autolightbox-gallery');
            $showCaption  = $this->params->get('show_caption', 'alt');

            $excludeClasses = $this->getExcludeClasses();

            $config = array(
                'linkClass'         => ($linkClass !== 'alb-link') ? $linkClass : '',
                'galleryGroup'      => $galleryGroup,
                'excludeClasses'    => $excludeClasses,
                'showCaption'       => $showCaption,
                'captionMobile'     => (bool) (int) $this->params->get('caption_mobile', 0),
                'watchDynamic'      => (bool) (int) $this->params->get('watch_dynamic', 1),
                'showNavigation'    => (bool) (int) $this->params->get('show_navigation', 1),
                'watchContainer'    => trim($this->params->get('watch_container', '')),
                'allowedExtensions' => $this->getAllowedExtensionsList(),
            );

            $doc = Factory::getDocument();
            // JSON_HEX_* flagy sú obranná vrstva navyše (config prichádza z
            // administrácie, ktorú upravuje dôveryhodný administrátor, nie z
            // nedôveryhodného vstupu) - zabránia tomu, aby hodnota z nastavení
            // (napr. exclude_urls, exclude_classes) mohla vytvoriť sekvenciu
            // ako </script> alebo iný znak nebezpečný v <script> kontexte.
            $configJson = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $doc->addScriptDeclaration('window.FG_AUTOLIGHTBOX_CONFIG = ' . $configJson . ';');

            $mediaBase = $this->getMediaBaseUri();
            $doc->addStyleSheet($mediaBase . '/css/fgautolightbox.css?v=' . $this->getAssetCacheBuster('css/fgautolightbox.css'));
            $doc->addScript($mediaBase . '/js/fgautolightbox.js?v=' . $this->getAssetCacheBuster('js/fgautolightbox.js'));

            self::$assetsLoaded = true;
        }

        return true;
    }

    private function getCurrentUrl()
    {
        if (class_exists('Joomla\\CMS\\Uri\\Uri')) {
            $uriClass = 'Joomla\\CMS\\Uri\\Uri';
            $uri = $uriClass::getInstance();
        } elseif (class_exists('JUri')) {
            $uri = JUri::getInstance();
        } else {
            return isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        }

        return $uri->toString(array('path', 'query'));
    }

    private function getMediaBaseUri()
    {
        if (class_exists('Joomla\\CMS\\Uri\\Uri')) {
            $uriClass = 'Joomla\\CMS\\Uri\\Uri';
            $root = $uriClass::root(true);
        } elseif (class_exists('JUri')) {
            $root = JUri::root(true);
        } else {
            $root = '';
        }

        return rtrim($root, '/') . '/media/plg_content_fgautolightbox';
    }

    /**
     * Cache-busting hodnota pre statický CSS/JS súbor, založená na čase jeho
     * poslednej zmeny (filemtime) namiesto ručne udržiavaného čísla verzie.
     * Rieši riziko desynchronizácie medzi verziou v XML a v PHP - hodnota sa
     * automaticky zmení zakaždým, keď sa súbor skutočne prepíše, bez potreby
     * ručne dvíhať číslo na dvoch miestach.
     */
    private function getAssetCacheBuster($relativePath)
    {
        $fullPath = JPATH_ROOT . '/media/plg_content_fgautolightbox/' . ltrim($relativePath, '/');
        $mtime = @filemtime($fullPath);
        return $mtime !== false ? $mtime : '1';
    }

    /**
     * Vráti reťazec jedinečne identifikujúci tento konkrétny spracovávaný
     * obsah (článok/kontakt/položka), použitý ako "sůl" pridaná ku
     * gallery_group nastaveniu - zabezpečí, že sa galéria (rel atribút)
     * nezlúči naprieč viacerými rôznymi článkami na tej istej stránke
     * (napr. zoznam v kategórii).
     *
     * Preferuje $article->id (existuje pri com_content, com_contact,
     * com_newsfeeds, K2, Zoo aj väčšine ďalších komponentov), so
     * spl_object_hash() ako univerzálnou zálohou pre prípad, že by daný
     * typ obsahu vlastnosť "id" nemal.
     */
    private function getInstanceKey($article)
    {
        if (isset($article->id) && $article->id !== '' && $article->id !== null) {
            return (string) $article->id;
        }
        return spl_object_hash($article);
    }

    private function getExcludeClasses()
    {
        $raw = trim($this->params->get('exclude_classes', ''));
        if ($raw === '') {
            return array();
        }
        $result = array();
        foreach (explode(',', $raw) as $cls) {
            $cls = trim($cls);
            if ($cls !== '') $result[] = $cls;
        }
        return $result;
    }

    /**
     * Zjednotená logika pre popisok pod obrázkom (alt text / názov súboru / žiadny).
     * $altValue je alt/data-alt hodnota, ktorú si volajúci kód (DOM alebo regex
     * vetva) už predtým vytiahol svojím vlastným spôsobom.
     */
    private function buildTitle($srcVal, $altValue, $showCaption)
    {
        if ($showCaption === 'alt') {
            return $altValue;
        }
        if ($showCaption === 'filename') {
            $filename = basename($srcVal);
            $filename = urldecode($filename);
            $filename = preg_replace('/\.[^.]+$/', '', $filename);
            $filename = str_replace(array('_', '-'), ' ', $filename);
            return $filename;
        }
        return '';
    }

    /**
     * Zjednotená logika pre výslednú CSS triedu odkazu - pevná funkčná trieda
     * "alb-link" plus voliteľná triedy z nastavenia link_class (bez duplicity).
     */
    private function buildLinkClass($linkClass)
    {
        if ($linkClass !== '' && $linkClass !== 'alb-link') {
            return trim('alb-link ' . $linkClass);
        }
        return 'alb-link';
    }

    /**
     * Vyberie najlepšiu dostupnú URL obrázka z jeho atribútov, v poradí:
     * 1. data-full / data-highres - explicitná autorská voľba plnohodnotného obrázka
     * 2. data-src - bežná lazy-load konvencia (placeholder v src, skutočný obrázok tu)
     * 3. najväčšie rozlíšenie zo srcset (napr. responzívne šablóny)
     * 4. src - posledná záloha
     *
     * $attrs je asociatívne pole atribútov (case-insensitive kľúče už z volajúceho).
     */
    private function pickBestSrc($attrs)
    {
        foreach (array('data-full', 'data-highres') as $key) {
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
        return !empty($attrs['src']) ? $attrs['src'] : '';
    }

    /**
     * Ak je <img> zabalený v <picture>, zloží jeden kombinovaný srcset reťazec
     * zo srcset atribútu samotného <img> a zo srcset atribútov všetkých
     * sesterských <source> elementov - parseLargestFromSrcset() potom z tejto
     * spoločnej množiny vyberie kandidáta s najväčším rozlíšením naprieč
     * všetkými <source> (nielen tým, ktorý by si aktuálne zvolil prehliadač
     * podľa media query, keďže pre lightbox chceme vždy tú najkvalitnejšiu
     * dostupnú verziu bez ohľadu na aktuálnu šírku obrazovky).
     */
    /**
     * Nájde najbližšieho predka <picture> (nie nutne priameho rodiča - viď
     * poznámka v getCombinedSrcset() o tom, prečo DOMDocument/libxml niekedy
     * vnorí <img> hlbšie, než by človek čakal). Vráti null, ak obrázok nie
     * je v žiadnom <picture>.
     */
    private function findPictureAncestor($img)
    {
        $ancestor = $img->parentNode;
        while ($ancestor !== null) {
            if ($ancestor->nodeType === XML_ELEMENT_NODE && strtolower($ancestor->nodeName) === 'picture') {
                return $ancestor;
            }
            $ancestor = $ancestor->parentNode;
        }
        return null;
    }

    private function getCombinedSrcset($img)
    {
        $parts = array();

        $ownSrcset = $img->getAttribute('srcset');
        if ($ownSrcset !== '') {
            $parts[] = $ownSrcset;
        }

        // Hľadaj najbližšieho predka <picture> - nie nutne priameho rodiča.
        // DOMDocument/libxml totiž <source> nespracúva ako "prázdny" (void)
        // element, takže <img> za ním sa mu často vnorí DOVNÚTRA <source>,
        // nie vedľa neho ako sused (<picture><source><img></source></picture>
        // namiesto očakávaného <picture><source><img></picture>). Preto
        // stúpame nahor, kým nenájdeme <picture>, a potom v ňom vyhľadáme
        // VŠETKY <source> potomky (getElementsByTagName ide do hĺbky), nie
        // len priamych súrodencov.
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

    /**
     * Z hodnoty atribútu srcset vyberie URL s najväčším rozlíšením (podľa "w"
     * alebo "x" deskriptora). Pri chýbajúcom/nejednoznačnom deskriptore sa
     * použije prvá položka ako záloha.
     */
    private function parseLargestFromSrcset($srcset)
    {
        $candidates = array_map('trim', explode(',', $srcset));
        $best = '';
        $bestScore = -1;
        $first = '';

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $candidate);
            $url = $parts[0];
            if ($first === '') {
                $first = $url;
            }

            $score = 0;
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

    private function hasAllowedExtension($src)
    {
        $allowed = $this->getAllowedExtensionsList();
        if (empty($allowed)) {
            return true; // prázdny zoznam = bez obmedzenia
        }

        $path = parse_url($src, PHP_URL_PATH);
        if ($path === null || $path === false) {
            $path = $src;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '') {
            return false;
        }

        return in_array($ext, $allowed);
    }

    private function getAllowedExtensionsList()
    {
        $allowedRaw = trim($this->params->get('allowed_extensions', 'jpg,jpeg,png,gif,webp,avif'));
        if ($allowedRaw === '') {
            return array();
        }
        return array_map('strtolower', array_map('trim', explode(',', $allowedRaw)));
    }

    private function wrapImages($html, $instanceKey = '')
    {
        if (!class_exists('DOMDocument')) {
            return $this->wrapImagesRegexFallback($html, $instanceKey);
        }

        $excludeClasses = $this->getExcludeClasses();

        $showCaption  = $this->params->get('show_caption', 'alt');
        $linkClass    = $this->params->get('link_class', 'autolightbox');
        $galleryGroup = $this->params->get('gallery_group', 'autolightbox-gallery');
        if ($instanceKey !== '') {
            $galleryGroup .= ':' . $instanceKey;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');

        // Ulož predchádzajúci stav libxml error handlingu a po skončení ho
        // korektne obnov (nie len vyčisti chyby) - inak by sme natrvalo
        // zmenili globálny PHP stav pre zvyšok requestu, čo by mohlo
        // ovplyvniť iné pluginy/kód bežiaci v tom istom requeste, ktorý sa
        // spolieha na predvolené (viditeľné) libxml chybové hlásenia.
        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            // Poznámka: skúšal som modernejší zápis bez plného <html><body> obalu
            // (LIBXML_HTML_NOIMPLIED), ale pri viacerých susediacich <p> elementoch
            // (bežná štruktúra Joomla článku) poškodzoval HTML štruktúru - zlial
            // prvé dva <p> do seba a pridal nadbytočnú </p> na koniec. Tento
            // plnohodnotný obal je overene bezpečný pre reálny obsah článkov.
            $wrapped = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>';
            $loaded = $dom->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }

        if (!$loaded) {
            return $this->wrapImagesRegexFallback($html, $instanceKey);
        }

        $xpath = new DOMXPath($dom);
        $images = $xpath->query('//img[not(ancestor::a)]');

        foreach ($images as $img) {
            // Vyber najlepšiu dostupnú URL: data-full/data-highres > data-src >
            // najväčšie rozlíšenie zo srcset (vrátane <picture><source> ak je
            // obrázok v ňom zabalený) > src (viď pickBestSrc()).
            $srcVal = $this->pickBestSrc(array(
                'data-full'     => $img->getAttribute('data-full'),
                'data-highres'  => $img->getAttribute('data-highres'),
                'data-src'      => $img->getAttribute('data-src'),
                'srcset'        => $this->getCombinedSrcset($img),
                'src'           => $img->getAttribute('src'),
            ));
            if ($srcVal === '') {
                continue;
            }

            if (!$this->hasAllowedExtension($srcVal)) {
                continue;
            }

            if (!empty($excludeClasses)) {
                $imgClasses = preg_split('/\s+/', trim($img->getAttribute('class')));
                $skip = false;
                foreach ($excludeClasses as $excluded) {
                    if (in_array($excluded, $imgClasses)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }
            }

            $altValue = $img->getAttribute('alt');
            if ($altValue === '' && $img->hasAttribute('data-alt')) {
                $altValue = $img->getAttribute('data-alt');
            }
            $title = $this->buildTitle($srcVal, $altValue, $showCaption);

            $cssClass = $this->buildLinkClass($linkClass);
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
            // nie len <img> v ňom. Podľa HTML špecifikácie funguje natívne
            // prepínanie <source> len keď je <img> PRIAMYM potomkom <picture>
            // - keby sme vložili <a> medzi ne, natívne responzívne/formátové
            // prepínanie na stránke by prestalo fungovať (overené testom).
            $wrapTarget = $this->findPictureAncestor($img);
            if ($wrapTarget === null) {
                $wrapTarget = $img;
            }

            $wrapTarget->parentNode->insertBefore($a, $wrapTarget);
            $a->appendChild($wrapTarget);
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return $this->wrapImagesRegexFallback($html);
        }

        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }

        return $result;
    }

    /**
     * Núdzová záloha, ak DOMDocument nie je na serveri dostupné (extrémne
     * zriedkavé). Známe obmedzenie oproti hlavnej DOM vetve: keďže táto
     * metóda spracúva každý <img> izolovane regexom, nevie o okolitej
     * <picture>/<source> štruktúre - obrázky vnútri <picture> tu dostanú
     * lightbox odkaz len na základe VLASTNÉHO srcset/src atribútu <img>,
     * bez zohľadnenia rozlíšení zo sesterských <source> elementov.
     */
    private function wrapImagesRegexFallback($html, $instanceKey = '')
    {
        $existingLinks = array();
        $html = preg_replace_callback(
            '/<a\b[^>]*>.*?<\/a>/is',
            function($m) use (&$existingLinks) {
                $token = '###ALB_LINK_' . count($existingLinks) . '###';
                $existingLinks[$token] = $m[0];
                return $token;
            },
            $html
        );

        $excludeClasses = $this->getExcludeClasses();

        $showCaption  = $this->params->get('show_caption', 'alt');
        $linkClass    = $this->params->get('link_class', 'autolightbox');
        $galleryGroup = $this->params->get('gallery_group', 'autolightbox-gallery');
        if ($instanceKey !== '') {
            $galleryGroup .= ':' . $instanceKey;
        }

        $html = preg_replace_callback(
            '/<img(\s[^>]*)>/iU',
            function($matches) use ($excludeClasses, $showCaption, $linkClass, $galleryGroup) {
                $imgTag  = $matches[0];
                $imgAttr = $matches[1];

                // Vyber najlepšiu dostupnú URL rovnakou logikou ako DOM vetva.
                // (?:^|\s) hranica pred názvom atribútu predchádza chybnému
                // zachyteniu napr. "src=" ako podreťazca vnútri "data-src=".
                $attrs = array();
                foreach (array('data-full', 'data-highres', 'data-src', 'srcset', 'src') as $attrName) {
                    if (preg_match('/(?:^|\s)' . preg_quote($attrName, '/') . '\s*=\s*["\']([^"\']+)["\']/', $imgAttr, $m)) {
                        $attrs[$attrName] = $m[1];
                    }
                }
                $srcVal = $this->pickBestSrc($attrs);
                if ($srcVal === '') {
                    return $imgTag;
                }

                if (!$this->hasAllowedExtension($srcVal)) {
                    return $imgTag;
                }

                if (!empty($excludeClasses)) {
                    $imgClasses = array();
                    if (preg_match('/class=["\']([^"\']*)["\']/', $imgAttr, $cls)) {
                        $imgClasses = preg_split('/\s+/', trim($cls[1]));
                    }
                    foreach ($excludeClasses as $excluded) {
                        if (in_array($excluded, $imgClasses)) {
                            return $imgTag;
                        }
                    }
                }

                $altValue = '';
                if (preg_match('/(?:alt|data-alt)=["\']([^"\']*)["\']/', $imgAttr, $alt)) {
                    $altValue = $alt[1];
                }
                $title = $this->buildTitle($srcVal, $altValue, $showCaption);

                $titleAttr = !empty($title) ? ' title="' . htmlspecialchars($title, ENT_QUOTES) . '"' : '';
                $altAttr   = !empty($altValue) ? ' data-alb-alt="' . htmlspecialchars($altValue, ENT_QUOTES) . '"' : '';
                $cssClass  = $this->buildLinkClass($linkClass);

                return '<a href="' . htmlspecialchars($srcVal, ENT_QUOTES) . '" class="' . htmlspecialchars($cssClass, ENT_QUOTES) . '" rel="' . htmlspecialchars($galleryGroup, ENT_QUOTES) . '"' . $titleAttr . $altAttr . '>' . $imgTag . '</a>';
            },
            $html
        );

        if (!empty($existingLinks)) {
            $html = strtr($html, $existingLinks);
        }

        return $html;
    }
}

if (interface_exists('Joomla\\Event\\SubscriberInterface')) {
    // Joomla 4 / 5 / 6 - moderný spôsob registrácie event handlera cez
    // SubscriberInterface. Odporúčaný Joomla tímom, nezávisí od spätne
    // kompatibilného "legacy" mechanizmu, ktorý môže byť v budúcnosti (J7+)
    // odstránený.
    class PlgContentFgautolightbox extends \Joomla\CMS\Plugin\CMSPlugin implements \Joomla\Event\SubscriberInterface
    {
        use FgautolightboxLogic;

        public static function getSubscribedEvents(): array
        {
            return array('onContentPrepare' => 'onContentPrepare');
        }

        public function onContentPrepare(\Joomla\Event\Event $event)
        {
            // Štandardný Joomla vzor extrakcie pôvodných pozičných argumentov
            // z Event objektu - funguje pre GenericEvent aj konkrétne triedy
            // udalostí ako ContentPrepareEvent.
            list($context, $article, $params, $page) = array_values($event->getArguments());
            $this->handleContentPrepare($context, $article, $params, $page);
        }
    }
} else {
    // Joomla 3 - starý, stále funkčný spôsob s priamymi pozičnými parametrami
    if (class_exists('CMSPlugin')) {
        class FgautolightboxLegacyBase extends CMSPlugin {}
    } else {
        class FgautolightboxLegacyBase extends JPlugin {}
    }

    class PlgContentFgautolightbox extends FgautolightboxLegacyBase
    {
        use FgautolightboxLogic;

        public function onContentPrepare($context, &$article, &$params, $page = 0)
        {
            return $this->handleContentPrepare($context, $article, $params, $page);
        }
    }
}
