/* AutoLightbox plugin - statický JS engine (cachovateľný prehliadačom)
 * Dynamická konfigurácia (nastavenia z administrácie Joomly) sa číta
 * z window.FG_AUTOLIGHTBOX_CONFIG - malý inline blok vkladaný pluginom
 * osobitne, aby tento súbor mohol zostať čisto statický. */
(function() {
    // Poistka proti dvojitej inicializácii (napr. ak by sa tento súbor
    // omylom vložil na stránku dvakrát).
    if (window.ALB_INITIALIZED) return;
    window.ALB_INITIALIZED = true;

    var ALB_SELECTOR = "a.alb-link";

    var DEFAULTS = {
        linkClass: "",
        galleryGroup: "autolightbox-gallery",
        excludeClasses: [],
        showCaption: "alt",
        captionMobile: false,
        watchDynamic: false,
        watchContainer: "",
        showNavigation: true,
        preloadAdjacent: true,
        allowedExtensions: ["jpg", "jpeg", "png", "gif", "webp", "avif"],
        preferSrcset: false,
        // Anglická záloha pre prípad, že by config z PHP labels neposlal
        // vôbec (napr. veľmi stará verzia zabudnutá v prehliadačovej cache) -
        // reálne sa vždy prepíšu hodnotami z Joomla jazykových reťazcov
        // zodpovedajúcich jazyku danej stránky (viď Fgautolightbox::handle()).
        labels: {
            dialog: "Image gallery",
            close: "Close",
            previous: "Previous image",
            next: "Next image",
            error: "Failed to load image"
        }
    };

    // Bezpečné vloženie textu (napr. jazykového reťazca) do HTML atribútu v
    // rámci innerHTML zostavovania - labels pochádzajú z Joomla jazykových
    // súborov, ktoré sú v princípe upraviteľné, takže sa neuvádzajú do
    // innerHTML surovo bez ošetrenia.
    function escapeHtmlAttr(value) {
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/"/g, "&quot;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;");
    }

    function albInit() {
        var userConfig = (typeof window.FG_AUTOLIGHTBOX_CONFIG === "object" && window.FG_AUTOLIGHTBOX_CONFIG) || {};
        var ALB_CONFIG = {};
        for (var k in DEFAULTS) {
            ALB_CONFIG[k] = (k in userConfig) ? userConfig[k] : DEFAULTS[k];
        }
        // labels sa zlučuje po jednotlivých kľúčoch (nie plytko na úrovni
        // celého objektu) - ak by userConfig.labels existoval, ale bol len
        // čiastočne vyplnený (napr. stará cachovaná verzia s inou sadou
        // kľúčov), chýbajúce popisky by inak zostali "undefined" namiesto
        // anglickej zálohy.
        var userLabels = (userConfig && typeof userConfig.labels === "object" && userConfig.labels) || {};
        ALB_CONFIG.labels = {
            dialog: userLabels.dialog || DEFAULTS.labels.dialog,
            close: userLabels.close || DEFAULTS.labels.close,
            previous: userLabels.previous || DEFAULTS.labels.previous,
            next: userLabels.next || DEFAULTS.labels.next,
            error: userLabels.error || DEFAULTS.labels.error
        };

        var overlay = document.createElement("div");
        overlay.id = "alb-overlay";
        if (ALB_CONFIG.captionMobile) {
            overlay.classList.add("alb-caption-mobile");
        }
        overlay.setAttribute("role", "dialog");
        overlay.setAttribute("aria-modal", "true");
        overlay.setAttribute("aria-label", ALB_CONFIG.labels.dialog);
        overlay.innerHTML =
            '<button type="button" id="alb-close" aria-label="' + escapeHtmlAttr(ALB_CONFIG.labels.close) + '">&times;</button>' +
            '<button type="button" id="alb-prev" aria-label="' + escapeHtmlAttr(ALB_CONFIG.labels.previous) + '">&#8249;</button>' +
            '<div id="alb-wrap"><img id="alb-img" src="" alt="" decoding="async"/><div id="alb-error"></div><div id="alb-caption" aria-live="polite" aria-atomic="true"></div></div>' +
            '<button type="button" id="alb-next" aria-label="' + escapeHtmlAttr(ALB_CONFIG.labels.next) + '">&#8250;</button>' +
            '<span id="alb-counter" aria-live="polite" aria-atomic="true"></span>';
        document.body.appendChild(overlay);

        var items = [], current = 0;
        var loadGeneration = 0;
        var ovEl = overlay, imgEl = document.getElementById("alb-img");
        var capEl = document.getElementById("alb-caption");
        var errEl = document.getElementById("alb-error");
        var cntEl = document.getElementById("alb-counter");
        var closeBtn = document.getElementById("alb-close");
        var prevBtn = document.getElementById("alb-prev");
        var nextBtn = document.getElementById("alb-next");
        var lastFocusedEl = null;
        var scrollLockY = 0;
        var previousBodyOverflow = "";
        var previousBodyPosition = "";
        var previousBodyTop = "";
        var previousBodyWidth = "";
        var previousHtmlOverflow = "";

        // Samotné document.body.style.overflow = "hidden" na iOS Safari
        // často nezabráni scrollovaniu pozadia ("podtečenie" stránky pod
        // lightboxom) - iOS má vlastný viewport scrolling model, ktorý toto
        // pravidlo bežne ignoruje. Overený spôsob je namiesto toho zamknúť
        // aj <html>, a <body> dočasne prepnúť na position:fixed s top
        // posunutým o aktuálnu scroll pozíciu - po zatvorení sa všetko
        // vráti do pôvodného stavu vrátane presnej scroll pozície.
        function lockBodyScroll() {
            scrollLockY = window.scrollY || window.pageYOffset || 0;

            // Ulož pôvodné hodnoty namiesto natvrdo nastaveného "" pri
            // odomknutí - šablóna alebo iný plugin mohli mať vlastné CSS
            // pravidlá na týchto vlastnostiach, ktoré by sa inak potichu
            // zmazali (rovnaký princíp ako pri pôvodnom overflow-only
            // riešení).
            previousBodyOverflow = document.body.style.overflow;
            previousBodyPosition = document.body.style.position;
            previousBodyTop = document.body.style.top;
            previousBodyWidth = document.body.style.width;
            previousHtmlOverflow = document.documentElement.style.overflow;

            document.documentElement.style.overflow = "hidden";
            document.body.style.overflow = "hidden";
            document.body.style.position = "fixed";
            document.body.style.top = "-" + scrollLockY + "px";
            document.body.style.width = "100%";
        }

        function unlockBodyScroll() {
            document.documentElement.style.overflow = previousHtmlOverflow;
            document.body.style.overflow = previousBodyOverflow;
            document.body.style.position = previousBodyPosition;
            document.body.style.top = previousBodyTop;
            document.body.style.width = previousBodyWidth;
            // position:fixed odstránilo stránku zo scroll toku, takže
            // prehliadač si scroll pozíciu sám nepamätá - treba ju vrátiť
            // ručne na presne tú istú hodnotu, na akej bol návštevník pred
            // otvorením lightboxu.
            window.scrollTo(0, scrollLockY);
        }

        // Doplnková ochrana k Tab-cyklovaniu nižšie: to samo osebe rieši len
        // klávesnicovú navigáciu (a len ak sa focus už nachádza medzi troma
        // tlačidlami). Čítačka obrazovky v "browse móde" (šípkami prechádza
        // celý DOM nezávisle od Tab poradia) alebo klik myšou na obsah
        // pozadia by ju úplne obišli. "inert" na priamych potomkoch <body>
        // (okrem nášho vlastného overlay) robí pozadie skutočne nedosiahnuteľné
        // - žiadnym spôsobom interakcie, nielen Tabom.
        var inertedBodyChildren = [];
        function setBackgroundInert(makeInert) {
            if (makeInert) {
                inertedBodyChildren = [];
                Array.prototype.forEach.call(document.body.children, function(child) {
                    if (child === ovEl) return;
                    if (!child.hasAttribute("inert")) {
                        child.setAttribute("inert", "");
                        inertedBodyChildren.push(child);
                    }
                });
            } else {
                inertedBodyChildren.forEach(function(child) {
                    child.removeAttribute("inert");
                });
                inertedBodyChildren = [];
            }
        }

        function open(clickedEl) {
            // Zoskup iba obrázky so ZHODNÝM "data-alb-group" atribútom ako
            // kliknutý odkaz (napr. len obrázky z toho istého článku, nie z
            // celej stránky). Zámerne nie "rel" - ten je vyhradený pre
            // skutočné HTML5 link types (nofollow, noopener...), nie pre
            // vlastné aplikačné dáta.
            var group = clickedEl.getAttribute("data-alb-group") || "";
            var all = Array.prototype.filter.call(document.querySelectorAll(ALB_SELECTOR), function(a) {
                return (a.getAttribute("data-alb-group") || "") === group;
            });

            items = [];
            all.forEach(function(a) {
                items.push({
                    src: a.getAttribute("href"),
                    // Ak je nastavený, prehliadač si sám vyberie vhodnú
                    // veľkosť podľa skutočnej šírky viewportu namiesto
                    // vždy najväčšieho kandidáta (viď show()).
                    srcset: a.getAttribute("data-alb-srcset") || "",
                    title: a.getAttribute("title") || "",
                    // Prístupný "alt" text pre obrázok v lightboxe - nezávislý od
                    // nastavenia show_caption (to ovplyvňuje len VIDITEĽNÝ popisok).
                    alt: a.getAttribute("data-alb-alt") || a.getAttribute("title") || ""
                });
            });
            current = all.indexOf(clickedEl);

            lastFocusedEl = document.activeElement;
            show();
            ovEl.classList.add("alb-open");
            lockBodyScroll();
            setBackgroundInert(true);
            setTimeout(function() {
                ovEl.classList.add("alb-visible");
                closeBtn.focus();
            }, 10);
        }

        function close() {
            ovEl.classList.remove("alb-visible");
            unlockBodyScroll();
            setBackgroundInert(false);
            setTimeout(function() { ovEl.classList.remove("alb-open"); imgEl.src = ""; }, 320);
            if (lastFocusedEl && typeof lastFocusedEl.focus === "function") {
                lastFocusedEl.focus();
            }
        }

        function show() {
            // Generation counter proti race condition pri rýchlom prechádzaní
            // (napr. viacero stlačení šípky skôr, než sa stihne predchádzajúci
            // obrázok načítať/zlyhať). Každé volanie show() zvýši counter a
            // handler si "svoju" hodnotu zapamätá - ak sa medzitým spustilo
            // ďalšie show() (napr. používateľ klikol ešte raz), starý
            // onload/onerror sa už nemá čo diať (je pre obrázok, ktorý už
            // nie je aktuálny).
            loadGeneration++;
            var myGeneration = loadGeneration;

            imgEl.classList.remove("alb-error");
            imgEl.classList.add("alb-loading");
            errEl.classList.remove("alb-visible-error");
            errEl.textContent = "";

            imgEl.onload = function() {
                if (myGeneration !== loadGeneration) return;
                imgEl.classList.remove("alb-loading");
            };
            imgEl.onerror = function() {
                if (myGeneration !== loadGeneration) return;
                imgEl.classList.remove("alb-loading");
                imgEl.classList.add("alb-error");
                errEl.textContent = ALB_CONFIG.labels.error;
                errEl.classList.add("alb-visible-error");
            };

            // src sa nastavuje VŽDY (aj keď je aj srcset) - slúži ako záloha
            // pre prehliadače bez podpory srcset a je to hodnota, ktorú by
            // dostal aj používateľ so zakázaným JS (href na <a>). Keď je
            // srcset prítomný, moderné prehliadače podľa neho aj tak
            // prepíšu skutočne zobrazenú verziu obrázka.
            imgEl.src = items[current].src;
            if (items[current].srcset) {
                imgEl.srcset = items[current].srcset;
                // 100vw je zjednodušenie (na desktope je obrázok reálne cez
                // CSS obmedzený max-width:88vw, nie presne 100vw) - v praxi
                // to znamená, že prehliadač si v hraničných prípadoch môže
                // vybrať mierne väčšieho kandidáta, než je nutné. To je
                // prijateľný, bežne používaný kompromis oproti presnému
                // ladeniu sizes na každý CSS breakpoint.
                imgEl.sizes = "100vw";
            } else {
                imgEl.removeAttribute("srcset");
                imgEl.removeAttribute("sizes");
            }
            imgEl.alt = items[current].alt;

            var hasCaption = items[current].title !== "";
            capEl.textContent = items[current].title;
            capEl.style.display = hasCaption ? "" : "none";

            // Prepojí obrázok s jeho popiskom pre čítačky obrazovky - len keď
            // je popisok skutočne zobrazený (nemá zmysel odkazovať na prázdny/
            // skrytý element). Nezávisí od imgEl.alt - alt je krátky
            // "accessible name" obrázka, aria-describedby dopĺňa dlhší
            // kontextový popis, ktorý sa mení pri každej navigácii v galérii.
            if (hasCaption) {
                imgEl.setAttribute("aria-describedby", "alb-caption");
            } else {
                imgEl.removeAttribute("aria-describedby");
            }

            // showNavigation=false -> lightbox funguje ako jednoduchý prehliadač
            // jedného obrázka (žiadne šípky, počítadlo, klávesnica ani swipe
            // medzi obrázkami - viď aj keydown a touchend nižšie).
            var hasMultiple = items.length > 1 && ALB_CONFIG.showNavigation;
            prevBtn.style.display = hasMultiple ? "" : "none";
            nextBtn.style.display = hasMultiple ? "" : "none";
            cntEl.textContent = hasMultiple ? (current + 1) + " / " + items.length : "";
            cntEl.style.display = hasMultiple ? "" : "none";

            preloadNeighbors();
        }

        // Predbežne načíta susedné obrázky (predchádzajúci a nasledujúci), aby
        // navigácia šípkami pôsobila plynulejšie, bez viditeľného načítavania.
        function preloadNeighbors() {
            if (!ALB_CONFIG.preloadAdjacent) return;
            if (items.length < 2) return;
            [
                (current + 1) % items.length,
                (current - 1 + items.length) % items.length
            ].forEach(function(idx) {
                var preloadImg = new Image();
                // Rovnaký srcset/sizes ako v show() - inak by preload vždy
                // stiahol najväčšieho kandidáta (fallback src), aj keď by
                // samotné zobrazenie cez srcset vybralo menšiu, pre viewport
                // vhodnejšiu verziu. Bez tohto by preload zbytočne
                // zdvojnásobil práve ten prenos dát, ktorý má srcset riešiť.
                if (items[idx].srcset) {
                    preloadImg.sizes = "100vw";
                    preloadImg.srcset = items[idx].srcset;
                } else {
                    preloadImg.src = items[idx].src;
                }
            });
        }

        function prev() { current = (current - 1 + items.length) % items.length; show(); }
        function next() { current = (current + 1) % items.length; show(); }

        document.addEventListener("click", function(e) {
            var a = e.target.closest ? e.target.closest(ALB_SELECTOR) : null;
            if (!a) return;
            e.preventDefault();
            open(a);
        });
        closeBtn.addEventListener("click", close);
        ovEl.addEventListener("click", function(e) { if (e.target === ovEl) close(); });
        prevBtn.addEventListener("click", function(e) { e.stopPropagation(); prev(); });
        nextBtn.addEventListener("click", function(e) { e.stopPropagation(); next(); });

        document.addEventListener("keydown", function(e) {
            if (!ovEl.classList.contains("alb-open")) return;
            if (e.key === "Escape") { close(); return; }
            if (ALB_CONFIG.showNavigation) {
                if (e.key === "ArrowLeft") { prev(); return; }
                if (e.key === "ArrowRight") { next(); return; }
            }
            if (e.key === "Tab") {
                // Jednoduchá "focus trap" - Tab neopustí overlay, kým je otvorený
                var focusable = [closeBtn, prevBtn, nextBtn].filter(function(el) {
                    return el.style.display !== "none";
                });
                if (!focusable.length) return;
                var first = focusable[0], last = focusable[focusable.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault(); last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault(); first.focus();
                }
            }
        });

        // Swipe - preventDefault len keď gesto smeruje prevažne horizontálne
        // A prekročilo minimálny prah pohybu, aby sa neblokovalo prípadné
        // vertikálne scrollovanie ani pinch-zoom. Dôležité: pinch-zoom je
        // DVOJPRSTOVÉ gesto (e.touches.length > 1) - pri ňom sa prvý prst
        // (e.touches[0]) bežne pohybuje aj mierne horizontálne, takže bez
        // tejto kontroly by preventDefault() natívne priblíženie zablokoval.
        var SWIPE_THRESHOLD = 10;
        var sx = 0, sy = 0, sa = false;
        ovEl.addEventListener("touchstart", function(e) {
            if (e.touches.length > 1) { sa = false; return; } // pinch-zoom start - nezasahovat
            var t = e.touches[0]; sx = t.clientX; sy = t.clientY; sa = true;
        });
        ovEl.addEventListener("touchmove", function(e) {
            if (!sa || e.touches.length > 1) return; // druhy prst sa pridal medzitym - nezasahovat
            var t = e.touches[0], dx = t.clientX - sx, dy = t.clientY - sy;
            if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > SWIPE_THRESHOLD) {
                e.preventDefault();
            }
        }, { passive: false });
        ovEl.addEventListener("touchend", function(e) {
            if (!sa) return; sa = false;
            if (!ALB_CONFIG.showNavigation) return;
            var t = e.changedTouches[0], dx = t.clientX - sx, dy = t.clientY - sy;
            if (Math.abs(dy) > Math.abs(dx) || Math.abs(dx) < 50) return;
            if (items.length > 1) { if (dx < 0) next(); else prev(); }
        });

        // --- Podpora dynamicky pridaných obrázkov (AJAX / infinite scroll) ---

        // Rovnaký bezpečný predvolený zoznam ako v PHP ExtensionFilter -
        // použije sa, ak by ALB_CONFIG.allowedExtensions bol prázdny.
        var DEFAULT_ALLOWED_EXTENSIONS = ["jpg", "jpeg", "png", "gif", "webp", "avif"];
        var SAFE_SCHEMES = ["http:", "https:"];

        // Zamietne nebezpečné URL schémy (javascript:, data:, vbscript:...).
        // Relatívna cesta bez schémy (bežný prípad, napr. "/images/x.jpg")
        // je vždy v poriadku - rovnaká logika ako PHP
        // ExtensionFilter::hasSafeScheme().
        function hasSafeScheme(srcVal) {
            var m = srcVal.match(/^([a-zA-Z][a-zA-Z0-9+.-]*:)/);
            if (!m) return true;
            return SAFE_SCHEMES.indexOf(m[1].toLowerCase()) !== -1;
        }

        function hasAllowedExtension(srcVal) {
            if (!hasSafeScheme(srcVal)) return false;
            // Prázdny zoznam sa NECHÁPE ako "povoliť všetko" (bezpečnostné
            // riziko - pustilo by aj SVG so vloženými skriptami) - spadne na
            // rovnaký bezpečný default ako PHP strana pri fromCsv('').
            var allowed = ALB_CONFIG.allowedExtensions.length ? ALB_CONFIG.allowedExtensions : DEFAULT_ALLOWED_EXTENSIONS;
            var clean = srcVal.split("?")[0].split("#")[0];
            var dot = clean.lastIndexOf(".");
            if (dot === -1) return false;
            var ext = clean.substring(dot + 1).toLowerCase();
            return allowed.indexOf(ext) !== -1;
        }

        // WeakSet sledovania spracovaných elementov - odolnejšie než len
        // atribút data-alb-done, ktorý môže "prežiť" na uzle v nekonzistentnom
        // stave (napr. atribút prítomný, ale obrázok mimo <a> obalu - presne
        // scenár produkčnej chyby vyriešenej vo verzii 1.0.10).
        //
        // Kompromis: ak nejaká INÁ knižnica na stránke fyzicky presunie <img>
        // von z nášho <a> obalu (nie len raz pri prvom vložení do DOM, ale
        // opakovane), WeakSet už tento konkrétny objekt nebude spracúvať
        // druhýkrát - zámerne, aby sa predišlo prípadnému nekonečnému cyklu
        // "obaľ → niekto vytrhne → obaľ znova". Pre bežné použitie (obrázok
        // sa objaví v DOM raz) je toto správanie presne to, čo treba.
        var processedImages = (typeof WeakSet !== "undefined") ? new WeakSet() : null;

        // Z hodnoty atribútu srcset vyberie URL s najväčším rozlíšením ("w"
        // alebo "x" deskriptor). Rovnaká logika ako PHP parseLargestFromSrcset().
        // "w" (šírka v pixeloch) a "x" (hustota pixelov) nie sú navzájom
        // porovnateľné jednotky na tej istej škále - "2x" a "1200w" sa nedajú
        // zmysluplne porovnať ako čísla 2 vs 1200. Ak existuje aspoň jeden
        // "w" kandidát, porovnávajú sa len "w" kandidáti (x sa ignorujú).
        // Inak (len "x" kandidáti) sa porovnáva medzi sebou. Rovnaká logika
        // ako PHP SrcSetResolver::parseLargestFromSrcset().
        function parseLargestFromSrcset(srcset) {
            var candidates = srcset.split(",").map(function(s) { return s.trim(); }).filter(Boolean);
            var first = "";
            var parsed = candidates.map(function(candidate) {
                var parts = candidate.split(/\s+/);
                var url = parts[0];
                if (!first) first = url;
                var type = null, value = 0;
                if (parts[1]) {
                    var m = parts[1].match(/^([\d.]+)([wx])$/i);
                    if (m) { type = m[2].toLowerCase(); value = parseFloat(m[1]); }
                }
                return { url: url, type: type, value: value };
            });

            var wCandidates = parsed.filter(function(c) { return c.type === "w"; });
            var xCandidates = parsed.filter(function(c) { return c.type === "x"; });
            var pool = wCandidates.length ? wCandidates : xCandidates;

            if (!pool.length) return first;

            var best = null, bestValue = -1;
            pool.forEach(function(c) {
                if (c.value > bestValue) { bestValue = c.value; best = c.url; }
            });
            return best !== null ? best : first;
        }

        // Vyber najlepšiu dostupnú URL: data-full/data-highres > data-src >
        // najväčšie rozlíšenie zo srcset > src. Rovnaká priorita ako PHP
        // pickBestSrc(), aby dynamicky pridané obrázky (AJAX) dostali
        // identické správanie ako tie spracované serverom.
        // Ak je <img> vnútri <picture>, zloží kombinovaný srcset zo samotného
        // <img> aj zo VŠETKÝCH <source> elementov v tom istom <picture> - na
        // rozdiel od PHP DOMDocument tu žiadny problém s vnorením nehrozí,
        // keďže ide o skutočný živý DOM prehliadača.
        function getCombinedSrcset(img) {
            var parts = [];
            var ownSrcset = img.getAttribute("srcset");
            if (ownSrcset) parts.push(ownSrcset);

            var picture = img.closest("picture");
            if (picture) {
                picture.querySelectorAll("source").forEach(function(source) {
                    var s = source.getAttribute("srcset");
                    if (s) parts.push(s);
                });
            }
            return parts.join(", ");
        }

        function pickBestSrc(img) {
            var dataFull = img.getAttribute("data-full") || img.getAttribute("data-highres");
            if (dataFull) return dataFull;

            var dataSrc = img.getAttribute("data-src");
            var srcset = getCombinedSrcset(img);

            // Rovnaké nastavenie ako na PHP strane (ALB_CONFIG.preferSrcset) -
            // data-src je v praxi nejednoznačný, niektoré lazy-load knižnice
            // ho používajú ako plnohodnotný obrázok, iné len ako malý
            // placeholder popri plnohodnotnom responzívnom srcset.
            if (ALB_CONFIG.preferSrcset) {
                if (srcset) {
                    var fromSrcsetPreferred = parseLargestFromSrcset(srcset);
                    if (fromSrcsetPreferred) return fromSrcsetPreferred;
                }
                if (dataSrc) return dataSrc;
                return img.getAttribute("src") || "";
            }

            if (dataSrc) return dataSrc;

            if (srcset) {
                var fromSrcset = parseLargestFromSrcset(srcset);
                if (fromSrcset) return fromSrcset;
            }

            return img.getAttribute("src") || "";
        }

        // Rovnaký princíp ako na PHP strane (viď getInstanceKey()) - k základnej
        // gallery_group hodnote pridá "soľ", aby sa galéria nezliala naprieč
        // rôznymi kontajnermi obsahu na jednej stránke (napr. viacero
        // dynamicky načítaných položiek v zozname). Keďže JS nemá prístup k
        // Joomla ID článku, hľadá najbližšieho predka s vlastným "id"
        // atribútom - ak nič nenájde, použije sa pôvodná (nezoskupená)
        // hodnota ako záloha.
        function scopedGalleryGroup(img) {
            var el = img.parentElement;
            while (el && el !== document.body) {
                if (el.id) {
                    return ALB_CONFIG.galleryGroup + ":" + el.id;
                }
                el = el.parentElement;
            }
            return ALB_CONFIG.galleryGroup;
        }

        // Aktualizuje href (a odvodené title/data-alb-alt) na existujúcom
        // odkaze, ktorý sme sami vytvorili skôr - používa sa, keď sa zmenia
        // zdrojové atribúty obrázka PO tom, čo bol už raz obalený (typický
        // lazy-load vzor: placeholder src pri vložení, skutočný src o chvíľu
        // neskôr). Nevytvára nový wrapper, len upraví ten existujúci.
        function updateExistingWrapper(img, link) {
            var srcVal = pickBestSrc(img);
            if (!srcVal) return;
            if (!hasAllowedExtension(srcVal)) return;
            if (link.getAttribute("href") === srcVal) return; // bez zmeny

            link.setAttribute("href", srcVal);

            var rawAlt = img.getAttribute("alt") || img.getAttribute("data-alt") || "";
            var title = "";
            if (ALB_CONFIG.showCaption === "alt") {
                title = rawAlt;
            } else if (ALB_CONFIG.showCaption === "filename") {
                var filename = srcVal.split("/").pop().split("?")[0];
                try { filename = decodeURIComponent(filename); } catch (e) {}
                filename = filename.replace(/\.[^.]+$/, "").replace(/[_-]/g, " ");
                title = filename;
            }
            if (title) { link.setAttribute("title", title); } else { link.removeAttribute("title"); }
            if (rawAlt) { link.setAttribute("data-alb-alt", rawAlt); } else { link.removeAttribute("data-alb-alt"); }
        }

        // Ak <img> už je v CUDZOM odkaze (nie našom "alb-link") - typicky
        // TinyMCE/JCE vzor "prepojiť na plnú veľkosť obrázka" v editore
        // (<a href="full.jpg"><img src="thumb.jpg"></a>) - "upgraduje" ho na
        // lightbox namiesto úplného ignorovania. Bezpečné pravidlo: len ak
        // href ukazuje na povolenú príponu obrázka. Existujúce triedy/title
        // sa zachovávajú/rozširujú, nie prepisujú. Skupinové dáta idú do
        // "data-alb-group", nie "rel" - preto sa (na rozdiel od staršej
        // verzie) už nemusíme obávať prepísania cudzieho rel (napr.
        // nofollow) a upgrade prebehne aj pre takéto odkazy, bez toho, aby
        // sme sa ich vlastného rel čo i len dotkli.
        function tryUpgradeForeignLink(img, anchor) {
            var href = anchor.getAttribute("href");
            if (!href || !hasAllowedExtension(href)) return false;
            if (hasExcludedClass(img)) return false;

            var rawAlt = img.getAttribute("alt") || img.getAttribute("data-alt") || "";
            var title = "";
            if (ALB_CONFIG.showCaption === "alt") {
                title = rawAlt;
            } else if (ALB_CONFIG.showCaption === "filename") {
                var filename = href.split("/").pop().split("?")[0];
                try { filename = decodeURIComponent(filename); } catch (e) {}
                title = filename.replace(/\.[^.]+$/, "").replace(/[_-]/g, " ");
            }

            var newClass = ALB_CONFIG.linkClass ? "alb-link " + ALB_CONFIG.linkClass : "alb-link";
            var existingClass = (anchor.getAttribute("class") || "").trim();
            anchor.setAttribute("class", existingClass ? existingClass + " " + newClass : newClass);
            anchor.setAttribute("data-alb-group", ALB_CONFIG.galleryGroup);

            if (title && !anchor.getAttribute("title")) {
                anchor.setAttribute("title", title);
            }
            if (rawAlt && !anchor.hasAttribute("data-alb-alt")) {
                anchor.setAttribute("data-alb-alt", rawAlt);
            }

            return true;
        }

        // Kontroluje excludeClasses nielen na samotnom obrázku, ale aj na
        // jeho predkoch (do 4 úrovní) - rovnaká logika a hĺbka ako na PHP
        // strane (viď HtmlProcessor::hasExcludedClass()). Redaktori
        // prirodzene očakávajú, že obalenie CELÉHO bloku vylučujúcou
        // triedou (napr. <figure class="no-lightbox">) stačí.
        function hasExcludedClass(el) {
            if (!ALB_CONFIG.excludeClasses.length) return false;
            var node = el;
            var depth = 0;
            while (node && node.nodeType === 1 && depth <= 4) {
                var classes = (node.getAttribute("class") || "").split(/\s+/);
                for (var i = 0; i < ALB_CONFIG.excludeClasses.length; i++) {
                    if (classes.indexOf(ALB_CONFIG.excludeClasses[i]) !== -1) return true;
                }
                node = node.parentElement;
                depth++;
            }
            return false;
        }

        function wrapNewImage(img) {
            var existingLink = img.closest("a");
            if (existingLink) {
                // Ak je to NÁŠ vlastný odkaz (nie cudzí, ktorý mal obrázok
                // obalený už v pôvodnom obsahu článku), aktualizuj ho podľa
                // aktuálnych atribútov - lazy-load knižnice bežne najprv
                // vložia <img> s placeholder src (ktorý sa hneď obalí), a až
                // neskôr ho prepíšu na skutočný obrázok. Bez tejto aktualizácie
                // by lightbox navždy otváral zastaraný placeholder namiesto
                // reálneho obrázka.
                if (existingLink.classList.contains("alb-link")) {
                    updateExistingWrapper(img, existingLink);
                } else {
                    tryUpgradeForeignLink(img, existingLink);
                }
                return;
            }

            // WeakSet je presnejší signál "toto som už spracoval" než atribút
            // data-alb-done - viaže sa na skutočnú referenciu objektu, takže
            // ho nezmätie stav, keď atribút zostane na obrázku, ktorý sa
            // (napr. kvôli reflow inej knižnice) ocitol mimo <a> obalu.
            // Atribút data-alb-done sa aj naďalej nastavuje (pre prípadné
            // vlastné CSS/JS hooky), ale už sa nepoužíva na rozhodovanie.
            if (processedImages && processedImages.has(img)) return;

            if (hasExcludedClass(img)) return;

            var srcVal = pickBestSrc(img);
            if (!srcVal) return;
            if (!hasAllowedExtension(srcVal)) return;

            // Skutočný alt (pre prístupnosť v lightboxe) - vždy zachytený,
            // bez ohľadu na nastavenie show_caption.
            var rawAlt = img.getAttribute("alt") || img.getAttribute("data-alt") || "";

            var title = "";
            if (ALB_CONFIG.showCaption === "alt") {
                title = rawAlt;
            } else if (ALB_CONFIG.showCaption === "filename") {
                var filename = srcVal.split("/").pop().split("?")[0];
                try { filename = decodeURIComponent(filename); } catch (e) {}
                filename = filename.replace(/\.[^.]+$/, "").replace(/[_-]/g, " ");
                title = filename;
            }

            var link = document.createElement("a");
            link.setAttribute("href", srcVal);
            link.setAttribute("class", ALB_CONFIG.linkClass ? "alb-link " + ALB_CONFIG.linkClass : "alb-link");
            link.setAttribute("data-alb-group", scopedGalleryGroup(img));
            // Rovnaká logika ako na PHP strane: ak nešlo o explicitný "chcem
            // originál" override (data-full/data-highres), pošli aj celý
            // srcset - JS lightbox nech si sám necha prehliadač vybrať
            // vhodnú veľkosť namiesto natvrdo najväčšieho kandidáta.
            var hasExplicitOverride = !!(img.getAttribute("data-full") || img.getAttribute("data-highres"));
            var combinedSrcsetForLink = getCombinedSrcset(img);
            if (!hasExplicitOverride && combinedSrcsetForLink) {
                link.setAttribute("data-alb-srcset", combinedSrcsetForLink);
            }
            if (title) link.setAttribute("title", title);
            if (rawAlt) link.setAttribute("data-alb-alt", rawAlt);
            img.setAttribute("data-alb-done", "1");
            if (processedImages) processedImages.add(img);

            // Ak je obrázok vnútri <picture>, obaľ CELÝ <picture> element,
            // nie len <img> - inak by <img> prestal byť priamym potomkom
            // <picture> a natívne prepínanie <source> podľa HTML špecifikácie
            // by prestalo fungovať pre bežné (nie lightbox) zobrazenie.
            var wrapTarget = img.closest("picture") || img;

            wrapTarget.parentNode.insertBefore(link, wrapTarget);
            link.appendChild(wrapTarget);
        }

        function scanForNewImages(node) {
            if (!node || node.nodeType !== 1) return;
            if (node.id === "alb-overlay" || (node.closest && node.closest("#alb-overlay"))) return;

            if (node.tagName === "IMG") {
                wrapNewImage(node);
            } else if (node.querySelectorAll) {
                var imgs = node.querySelectorAll("img");
                for (var i = 0; i < imgs.length; i++) {
                    wrapNewImage(imgs[i]);
                }
            }
        }

        // Nájde <img>, ktorý súvisí s uzlom, na ktorom nastala zmena atribútu.
        // Ak sa zmenil atribút priamo na <img>, vráti ho. Ak sa zmenil na
        // <source> (napr. lazy-load knižnica dopĺňa srcset do <picture>
        // neskôr), nájde <img> vo vnútri toho istého <picture>.
        function resolveImageForAttributeChange(target) {
            if (!target || target.nodeType !== 1) return null;
            if (target.tagName === "IMG") return target;
            if (target.tagName === "SOURCE") {
                var picture = target.closest("picture");
                return picture ? picture.querySelector("img") : null;
            }
            return null;
        }

        if (ALB_CONFIG.watchDynamic && window.MutationObserver) {
            var albObserver = new MutationObserver(function(mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    var mutation = mutations[i];

                    if (mutation.type === "childList") {
                        var added = mutation.addedNodes;
                        for (var j = 0; j < added.length; j++) {
                            scanForNewImages(added[j]);
                        }
                    } else if (mutation.type === "attributes") {
                        // Zachytáva prípad, keď lazy-load knižnica nepridáva
                        // nový <img> do DOM, ale len DOPĹŇA src/data-src/
                        // srcset na už existujúcom (napr. IntersectionObserver
                        // vlastnej knižnice, ktorá si počká, kým sa obrázok
                        // priblíži k viewportu, a až vtedy nastaví atribút).
                        var img = resolveImageForAttributeChange(mutation.target);
                        if (img) wrapNewImage(img);
                    }
                }
            });

            var observerOptions = {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ["src", "data-src", "srcset", "data-full", "data-highres"]
            };

            // Ak je nastavený CSS selektor kontajnera, sleduj len jeho výskyty
            // (výkonovo lacnejšie na stránkach s "živým" DOM mimo obsahu
            // článku - animácie, chat widgety a pod.). Toto obmedzenie platí
            // VŽDY, keď je watchContainer nastavený - aj keď pri inicializácii
            // ešte žiadny zodpovedajúci kontajner neexistuje (napr. AJAX ho
            // vytvorí až neskôr). Skoršia verzia v takom prípade potichu
            // spadla na sledovanie celého document.body s plnými možnosťami,
            // čím sa scoping reálne úplne zrušil - opravené nižšie.
            var containers = [];
            if (ALB_CONFIG.watchContainer) {
                try {
                    containers = Array.prototype.slice.call(
                        document.querySelectorAll(ALB_CONFIG.watchContainer)
                    );
                } catch (e) {
                    containers = []; // neplatný selektor - objavovací observer nižšie to prípadne dobehne
                }
            } else {
                containers = [document.body]; // watchContainer vôbec nenastavený - sleduj celú stránku
            }

            var observedContainers = [];
            function attachMainObserver(container) {
                if (observedContainers.indexOf(container) !== -1) return;
                observedContainers.push(container);
                albObserver.observe(container, observerOptions);
            }
            containers.forEach(attachMainObserver);

            // Ak je nastavený watchContainer, doplnkový "objavovací" observer
            // sleduje CELÚ stránku (len na úrovni pridávania uzlov, BEZ
            // sledovania atribútov a bez prehľadávania obrázkov) a keď sa
            // objaví nový kontajner zodpovedajúci selektoru (napr. AJAX pridá
            // ".gallery" div, ktorý pri inicializácii ešte neexistoval),
            // pripojí naň hlavný observer. Beží nezávisle od toho, či niečo
            // zodpovedalo selektoru už na začiatku - scoping tak platí
            // konzistentne v oboch prípadoch. Zámerne odľahčené - kontroluje
            // len zhodu CSS selektora pri pridaní uzla, nerobí nič výkonovo
            // náročné pri každej mutácii ako hlavný observer.
            //
            // Poznámka: ak selektor nikdy nič nenájde (napr. preklep v
            // nastavení), dynamicky pridané obrázky sa nikdy nezačnú
            // sledovať - toto je zámerný kompromis v prospech spoľahlivého
            // scopingu namiesto tichého úplného vypnutia obmedzenia. Obrázky
            // prítomné už pri vykreslení stránky týmto nie sú dotknuté (tie
            // spracúva PHP strana nezávisle od tohto nastavenia).
            if (ALB_CONFIG.watchContainer) {
                var discoveryObserver = new MutationObserver(function(mutations) {
                    for (var i = 0; i < mutations.length; i++) {
                        var added = mutations[i].addedNodes;
                        for (var j = 0; j < added.length; j++) {
                            var node = added[j];
                            if (node.nodeType !== 1) continue;

                            var found = [];
                            try {
                                if (node.matches && node.matches(ALB_CONFIG.watchContainer)) {
                                    found.push(node);
                                }
                                if (node.querySelectorAll) {
                                    found = found.concat(
                                        Array.prototype.slice.call(node.querySelectorAll(ALB_CONFIG.watchContainer))
                                    );
                                }
                            } catch (e) {
                                continue;
                            }

                            found.forEach(function(foundContainer) {
                                attachMainObserver(foundContainer);
                                // Ak nový kontajner prišiel s obrázkami už vo vnútri
                                // (bežné pri AJAX, kde sa celý blok vloží naraz),
                                // observer pripojený TERAZ by ich sám o sebe nezachytil -
                                // observe() hlási len budúce zmeny, nie spätne existujúci
                                // obsah. Preto treba počiatočné prehľadanie navyše.
                                scanForNewImages(foundContainer);
                            });
                        }
                    }
                });
                discoveryObserver.observe(document.body, { childList: true, subtree: true });
            }
        }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", albInit);
    } else {
        albInit();
    }
})();

