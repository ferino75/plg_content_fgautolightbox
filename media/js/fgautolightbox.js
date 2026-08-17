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
        watchDynamic: true,
        watchContainer: "",
        allowedExtensions: ["jpg", "jpeg", "png", "gif", "webp", "avif"]
    };

    function albInit() {
        var userConfig = (typeof window.FG_AUTOLIGHTBOX_CONFIG === "object" && window.FG_AUTOLIGHTBOX_CONFIG) || {};
        var ALB_CONFIG = {};
        for (var k in DEFAULTS) {
            ALB_CONFIG[k] = (k in userConfig) ? userConfig[k] : DEFAULTS[k];
        }

        var overlay = document.createElement("div");
        overlay.id = "alb-overlay";
        if (ALB_CONFIG.captionMobile) {
            overlay.classList.add("alb-caption-mobile");
        }
        overlay.setAttribute("role", "dialog");
        overlay.setAttribute("aria-modal", "true");
        overlay.setAttribute("aria-label", "Galéria obrázkov");
        overlay.innerHTML =
            '<button type="button" id="alb-close" aria-label="Zatvoriť">&times;</button>' +
            '<button type="button" id="alb-prev" aria-label="Predchádzajúci obrázok">&#8249;</button>' +
            '<div id="alb-wrap"><img id="alb-img" src="" alt=""/><div id="alb-caption"></div></div>' +
            '<button type="button" id="alb-next" aria-label="Ďalší obrázok">&#8250;</button>' +
            '<span id="alb-counter"></span>';
        document.body.appendChild(overlay);

        var items = [], current = 0;
        var ovEl = overlay, imgEl = document.getElementById("alb-img");
        var capEl = document.getElementById("alb-caption");
        var cntEl = document.getElementById("alb-counter");
        var closeBtn = document.getElementById("alb-close");
        var prevBtn = document.getElementById("alb-prev");
        var nextBtn = document.getElementById("alb-next");
        var lastFocusedEl = null;

        function open(clickedEl) {
            // Zoskup iba obrázky so ZHODNÝM "rel" atribútom ako kliknutý odkaz
            // (napr. len obrázky z toho istého článku, nie z celej stránky)
            var group = clickedEl.getAttribute("rel") || "";
            var all = Array.prototype.filter.call(document.querySelectorAll(ALB_SELECTOR), function(a) {
                return (a.getAttribute("rel") || "") === group;
            });

            items = [];
            all.forEach(function(a) {
                items.push({
                    src: a.getAttribute("href"),
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
            document.body.style.overflow = "hidden";
            setTimeout(function() {
                ovEl.classList.add("alb-visible");
                closeBtn.focus();
            }, 10);
        }

        function close() {
            ovEl.classList.remove("alb-visible");
            document.body.style.overflow = "";
            setTimeout(function() { ovEl.classList.remove("alb-open"); imgEl.src = ""; }, 320);
            if (lastFocusedEl && typeof lastFocusedEl.focus === "function") {
                lastFocusedEl.focus();
            }
        }

        function show() {
            imgEl.classList.add("alb-loading");
            imgEl.onload = function() { imgEl.classList.remove("alb-loading"); };
            imgEl.src = items[current].src;
            imgEl.alt = items[current].alt;

            var hasCaption = items[current].title !== "";
            capEl.textContent = items[current].title;
            capEl.style.display = hasCaption ? "" : "none";

            var hasMultiple = items.length > 1;
            prevBtn.style.display = hasMultiple ? "" : "none";
            nextBtn.style.display = hasMultiple ? "" : "none";
            cntEl.textContent = hasMultiple ? (current + 1) + " / " + items.length : "";
            cntEl.style.display = hasMultiple ? "" : "none";

            preloadNeighbors();
        }

        // Predbežne načíta susedné obrázky (predchádzajúci a nasledujúci), aby
        // navigácia šípkami pôsobila plynulejšie, bez viditeľného načítavania.
        function preloadNeighbors() {
            if (items.length < 2) return;
            [
                (current + 1) % items.length,
                (current - 1 + items.length) % items.length
            ].forEach(function(idx) {
                var preloadImg = new Image();
                preloadImg.src = items[idx].src;
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
            if (e.key === "ArrowLeft") { prev(); return; }
            if (e.key === "ArrowRight") { next(); return; }
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

        // Swipe - preventDefault len keď gesto smeruje prevažne horizontálne,
        // aby sa neblokovalo prípadné vertikálne scrollovanie/pinch-zoom.
        var sx = 0, sy = 0, sa = false;
        ovEl.addEventListener("touchstart", function(e) { var t = e.touches[0]; sx = t.clientX; sy = t.clientY; sa = true; });
        ovEl.addEventListener("touchmove", function(e) {
            if (!sa) return;
            var t = e.touches[0], dx = t.clientX - sx, dy = t.clientY - sy;
            if (Math.abs(dx) > Math.abs(dy)) {
                e.preventDefault();
            }
        }, { passive: false });
        ovEl.addEventListener("touchend", function(e) {
            if (!sa) return; sa = false;
            var t = e.changedTouches[0], dx = t.clientX - sx, dy = t.clientY - sy;
            if (Math.abs(dy) > Math.abs(dx) || Math.abs(dx) < 50) return;
            if (items.length > 1) { if (dx < 0) next(); else prev(); }
        });

        // --- Podpora dynamicky pridaných obrázkov (AJAX / infinite scroll) ---

        function hasAllowedExtension(srcVal) {
            if (!ALB_CONFIG.allowedExtensions.length) return true; // prázdny zoznam = bez obmedzenia
            var clean = srcVal.split("?")[0].split("#")[0];
            var dot = clean.lastIndexOf(".");
            if (dot === -1) return false;
            var ext = clean.substring(dot + 1).toLowerCase();
            return ALB_CONFIG.allowedExtensions.indexOf(ext) !== -1;
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
        function parseLargestFromSrcset(srcset) {
            var candidates = srcset.split(",").map(function(s) { return s.trim(); });
            var best = "", bestScore = -1, first = "";
            candidates.forEach(function(candidate) {
                if (!candidate) return;
                var parts = candidate.split(/\s+/);
                var url = parts[0];
                if (!first) first = url;
                var score = 0;
                if (parts[1]) {
                    var m = parts[1].match(/^([\d.]+)([wx])$/i);
                    if (m) score = parseFloat(m[1]);
                }
                if (score > bestScore) { bestScore = score; best = url; }
            });
            return best || first;
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
            if (dataSrc) return dataSrc;

            var srcset = getCombinedSrcset(img);
            if (srcset) {
                var fromSrcset = parseLargestFromSrcset(srcset);
                if (fromSrcset) return fromSrcset;
            }

            return img.getAttribute("src") || "";
        }

        function wrapNewImage(img) {
            if (img.closest("a")) return; // je skutočne zabalený - hotovo

            // WeakSet je presnejší signál "toto som už spracoval" než atribút
            // data-alb-done - viaže sa na skutočnú referenciu objektu, takže
            // ho nezmätie stav, keď atribút zostane na obrázku, ktorý sa
            // (napr. kvôli reflow inej knižnice) ocitol mimo <a> obalu.
            // Atribút data-alb-done sa aj naďalej nastavuje (pre prípadné
            // vlastné CSS/JS hooky), ale už sa nepoužíva na rozhodovanie.
            if (processedImages && processedImages.has(img)) return;

            var imgClasses = (img.getAttribute("class") || "").split(/\s+/);
            for (var i = 0; i < ALB_CONFIG.excludeClasses.length; i++) {
                if (imgClasses.indexOf(ALB_CONFIG.excludeClasses[i]) !== -1) return;
            }

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
            link.setAttribute("rel", ALB_CONFIG.galleryGroup);
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

        if (ALB_CONFIG.watchDynamic && window.MutationObserver) {
            var albObserver = new MutationObserver(function(mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    var added = mutations[i].addedNodes;
                    for (var j = 0; j < added.length; j++) {
                        scanForNewImages(added[j]);
                    }
                }
            });

            // Ak je nastavený CSS selektor kontajnera, sleduj len jeho výskyty
            // (výkonovo lacnejšie na stránkach s "živým" DOM mimo obsahu
            // článku - animácie, chat widgety a pod.). Ak selektor nič
            // nenájde (napr. preklep v nastavení), použije sa document.body,
            // aby sledovanie dynamických obrázkov nezostalo potichu vypnuté.
            var containers = [];
            if (ALB_CONFIG.watchContainer) {
                try {
                    containers = Array.prototype.slice.call(
                        document.querySelectorAll(ALB_CONFIG.watchContainer)
                    );
                } catch (e) {
                    containers = []; // neplatný selektor - použije sa fallback nižšie
                }
            }
            if (!containers.length) {
                containers = [document.body];
            }
            containers.forEach(function(container) {
                albObserver.observe(container, { childList: true, subtree: true });
            });
        }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", albInit);
    } else {
        albInit();
    }
})();

