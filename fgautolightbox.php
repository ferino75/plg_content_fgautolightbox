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
        if (!in_array($context, $allowedContexts)) {
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

        $article->text = $this->wrapImages($article->text);

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
                'allowedExtensions' => $this->getAllowedExtensionsList(),
            );

            $doc = Factory::getDocument();
            $doc->addScriptDeclaration('window.FG_AUTOLIGHTBOX_CONFIG = ' . json_encode($config) . ';');

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

    private function wrapImages($html)
    {
        if (!class_exists('DOMDocument')) {
            return $this->wrapImagesRegexFallback($html);
        }

        $excludeClasses = $this->getExcludeClasses();

        $showCaption  = $this->params->get('show_caption', 'alt');
        $linkClass    = $this->params->get('link_class', 'autolightbox');
        $galleryGroup = $this->params->get('gallery_group', 'autolightbox-gallery');

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);

        // Poznámka: skúšal som modernejší zápis bez plného <html><body> obalu
        // (LIBXML_HTML_NOIMPLIED), ale pri viacerých susediacich <p> elementoch
        // (bežná štruktúra Joomla článku) poškodzoval HTML štruktúru - zlial
        // prvé dva <p> do seba a pridal nadbytočnú </p> na koniec. Tento
        // plnohodnotný obal je overene bezpečný pre reálny obsah článkov.
        $wrapped = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>';
        $loaded = $dom->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        if (!$loaded) {
            return $this->wrapImagesRegexFallback($html);
        }

        $xpath = new DOMXPath($dom);
        $images = $xpath->query('//img[not(ancestor::a)]');

        foreach ($images as $img) {
            // Preferuj data-src (bežná konvencia lazy-load knižníc, napr. tried
            // "lazy") pred src - tá pri lazy-loade často obsahuje len placeholder.
            $srcVal = $img->getAttribute('data-src');
            if ($srcVal === '') {
                $srcVal = $img->getAttribute('src');
            }
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

            $img->parentNode->insertBefore($a, $img);
            $a->appendChild($img);
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

    private function wrapImagesRegexFallback($html)
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

        $html = preg_replace_callback(
            '/<img(\s[^>]*)>/iU',
            function($matches) use ($excludeClasses, $showCaption, $linkClass, $galleryGroup) {
                $imgTag  = $matches[0];
                $imgAttr = $matches[1];

                // Preferuj data-src (lazy-load knižnice) pred src
                if (preg_match('/data-src=["\']([^"\']+)["\']/', $imgAttr, $dataSrc)) {
                    $srcVal = $dataSrc[1];
                } elseif (preg_match('/src=["\']([^"\']+)["\']/', $imgAttr, $src)) {
                    $srcVal = $src[1];
                } else {
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
