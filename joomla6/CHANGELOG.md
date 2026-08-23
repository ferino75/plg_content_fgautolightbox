# Changelog — plg_content_fgautolightbox (Joomla 6 native build)

This changelog covers the **native Joomla 6 build** only (this `joomla6/`
folder). For the classic Joomla 3.10 build's history, see
[`../CHANGELOG.md`](../CHANGELOG.md) in the repository root.

## 2.1.5
Response to a ChatGPT code review observation (P2, clean small fix):

- **Body scroll lock now preserves the template's/another plugin's
  pre-existing `overflow` value** instead of unconditionally resetting
  it to an empty string on close. Previously, opening the lightbox set
  `document.body.style.overflow = "hidden"` and closing it always reset
  it to `""` - silently discarding whatever value was there before
  (e.g. a template with its own `body { overflow: hidden }` for
  unrelated reasons, or another plugin managing scroll state). The
  value present at the moment the lightbox opens is now saved and
  restored exactly on close, whatever it was.
- Verified with automated tests: a pre-existing `"auto"` value is
  correctly restored (not wiped to `""`), the original no-prior-value
  behavior (`""`) still works exactly as before, and repeated open/
  close cycles preserve a non-default value (`"scroll"`) consistently
  without drifting. Full regression across the aria-describedby,
  i18n-labels, and all 34 isolated Support-class tests confirms no
  other impact.

## 2.1.4
Response to a ChatGPT code review suggestion (accessibility, not
required for functionality):

- **`#alb-img` is now linked to `#alb-caption` via `aria-describedby`**
  whenever a caption is actually shown for the current image - lets
  screen readers announce the caption as a description of the image,
  in addition to its `alt` text (which was already set correctly).
  The attribute is added/removed dynamically as the visitor navigates
  between images with and without a caption, rather than pointing at
  a caption element that might be empty or hidden.
- `aria-labelledby` on the dialog itself was deliberately **not**
  added - the overlay already has a static, translated `aria-label`
  (from 2.1.3) providing its accessible name, and ARIA only needs one
  of the two; adding both would be redundant.
- Verified with automated tests: the attribute is present when the
  current image has a caption, absent when it doesn't, and correctly
  re-added when navigating back to a captioned image after visiting an
  uncaptioned one. Full regression across the label/i18n tests, the
  stale-wrapper-update test, and all 34 isolated Support-class tests
  confirms no other impact.

## 2.1.3
Response to a ChatGPT code review observation (P1 for accessibility):

- **Screen reader labels were hardcoded in Slovak**, regardless of the
  admin's actual language. `aria-label="Galéria obrázkov"`,
  `"Zatvoriť"`, `"Predchádzajúci obrázok"`, `"Ďalší obrázok"` were
  literal strings in the JS engine - an `en-GB` Joomla install would
  still have its screen reader announce these in Slovak.
- **Fix:** four new translatable language constants
  (`PLG_CONTENT_FGAUTOLIGHTBOX_JS_DIALOG_LABEL`, `_CLOSE_LABEL`,
  `_PREV_LABEL`, `_NEXT_LABEL`) are resolved via `Text::_()` on the PHP
  side and sent through the existing inline config block as a `labels`
  object, matching the site's actual language.
- `$this->loadLanguage()` is now called before `Text::_()` - not
  guaranteed to already be loaded on the frontend the way it is for the
  admin config form, so without this the labels would have silently
  come back as the raw untranslated constant names instead of text.
- The JS side merges `labels` key-by-key (not as one shallow object
  replacement) with an English-language fallback for each individual
  key, so a partially-populated or entirely missing `labels` object
  (e.g. a stale cached JS file from before this version, paired with a
  fresh config) degrades gracefully to English rather than showing
  `undefined`.
- Label text going into the overlay's `innerHTML`-built buttons is now
  HTML-attribute-escaped, since these strings originate from
  (admin-editable) language files rather than being fixed literals.
- Also fixed a stale line in the `watch_container` field's admin
  description - it still described the pre-2.1.2 "falls back to
  watching the whole page" behavior that no longer exists.
- Verified with automated tests: English and Slovak label sets both
  render correctly on the actual DOM elements, a missing config
  entirely falls back to English, a partially-populated `labels` object
  falls back to English only for the missing keys (not `undefined`),
  and an HTML-unsafe character in a label round-trips correctly without
  breaking markup or enabling injection. PHP-side verified with a
  `Text::_()`/`loadLanguage()` stub confirming the call order and that
  translated strings reach the inline config JSON. Full regression
  across all 34 isolated Support-class tests and the JS mutation/
  container test suites confirms no other impact.

## 2.1.2
Response to a ChatGPT code review suggestion ("closes the loop" on
`watch_container`, not critical but worth doing):

- **Dynamically-created containers matching `watch_container` are now
  detected too**, not just ones present at page load. Previously, if
  `watch_container` was set to e.g. `.gallery` and that element didn't
  exist yet when the page loaded (AJAX creates it later), the plugin
  had no way to start watching it once it appeared.
- Added a lightweight "discovery" `MutationObserver` (childList-only,
  no attribute watching, no image scanning) that watches the whole page
  for new elements matching the `watch_container` selector. When one
  appears, the main (heavier) observer is attached to it - and it's
  also scanned immediately for images already inside it, since
  `observe()` only reports *future* changes, not content already
  present at the moment watching starts (relevant when AJAX inserts an
  entire populated `.gallery` block in one go).
- **A real regression was found and fixed while implementing this**,
  through the plugin's own test suite: the *previous* fallback behavior
  (if `watch_container` matched nothing at all, silently watch the
  entire `document.body` with full options) turned out to completely
  defeat scoping the moment nothing matched at page load - even for
  content that would never belong in any future container. This is
  **a deliberate behavior change**: `watch_container`'s scoping now
  holds consistently whether something matches at page load or not,
  rather than silently reverting to "watch everything" as soon as zero
  containers exist yet. The tradeoff: if the selector has a typo and
  never matches anything, ever, dynamically-added images are never
  tracked (server-rendered images on initial page load are unaffected,
  since those are handled entirely by PHP regardless of this setting).
- Verified with automated tests: an image added to a container that
  didn't exist at page load gets processed once the container appears,
  a container that arrives already populated with images (one AJAX
  batch) has all of them processed, and - importantly - an image added
  outside any `.gallery` (existing or newly discovered) still correctly
  stays unprocessed even when zero containers existed at page load.
  This last case is the one that initially failed during testing and
  led to the behavior-change fix above. Full regression across all
  prior `watch_container`, attribute-mutation, and stale-wrapper tests,
  plus all 34 isolated Support-class tests, confirms no other impact.

## 2.1.1
**Real bug**, follow-up to 2.1.0, reported by a ChatGPT code review (P1):

- **Stale lightbox link after a lazy-load placeholder is replaced.**
  2.1.0 added attribute-mutation watching so an `<img>` gaining a
  `src`/`data-src`/`srcset` value *for the first time* gets wrapped -
  but it didn't handle the equally common sequence where the image
  already had a *usable* (if low-quality) `src` at insertion time - a
  placeholder - got wrapped immediately with that placeholder's URL,
  and the `WeakSet` "already processed" guard then silently ignored
  the later attribute change that swapped in the real image. Net
  result: the lightbox would open the placeholder forever, even though
  the on-page `<img>` itself correctly showed the real photo.
- **Fix:** `wrapNewImage()` now distinguishes two cases when it finds
  an enclosing `<a>` on attribute-change: if that `<a>` is one we
  created (`alb-link` class), it *updates* the existing wrapper's
  `href` (and the derived `title`/`data-alb-alt`) to the current best
  source instead of doing nothing. A pre-existing `<a>` from the
  article's own content (not ours) is still left completely alone, as
  before.
- Verified by first reproducing the exact scenario from the report
  (image wrapped with a placeholder, then `src` changed to the real
  image, confirming the `href` incorrectly stayed on the placeholder),
  then confirming the fix resolves it. Additional cases verified: a
  foreign (non-`alb-link`) existing wrapper is never touched, `title`/
  `data-alb-alt` update alongside `href` when the caption depends on
  `alt` text, repeated attribute changes (not just one) keep updating
  correctly, and no double-wrapping ever occurs across multiple
  updates. Full regression of the 2.1.0 attribute-mutation tests,
  `watch_container` scoping tests, and all 34 isolated Support-class
  tests confirms no other impact.

## 2.1.0
Response to a ChatGPT code review observation, flagged as the most
interesting gap for a minor release:

- **`MutationObserver` now also catches attribute-only changes**, not
  just new elements added to the page. Previously, only
  `{ childList: true, subtree: true }` was observed - meaning an
  `<img>` element already present in the DOM (with no usable `src`,
  `data-src`, or `srcset` yet) that a lazy-load library later populates
  via `img.setAttribute("src", "...")` / `img.src = "..."` was
  completely invisible to the plugin, since no *new node* was ever
  added - only an *attribute* changed on an existing one. This is a
  very common lazy-load pattern (especially custom
  `IntersectionObserver`-based implementations that leave `<img>`
  attribute-less until the image nears the viewport).
- Now also observes `{ attributes: true, attributeFilter: ["src",
  "data-src", "srcset", "data-full", "data-highres"] }` - scoped to
  only the attributes the plugin actually cares about, to keep the
  performance cost contained (an unfiltered `attributes: true` would
  fire on every attribute change of every element in the observed
  subtree, which would be far too broad).
- Also handles the `<picture><source>` case: if the attribute change
  happens on a `<source>` element (not directly on the `<img>`), the
  associated `<img>` inside the same `<picture>` is located and
  re-evaluated.
- Reuses the existing `wrapNewImage()` function unchanged - its guards
  (`closest("a")`, the `WeakSet` "already processed" check) already
  made it safe to call repeatedly/idempotently, so no new wrapping
  logic was needed, only a new trigger path into the same function.
- Verified with automated tests reproducing the exact scenario from the
  report (bare `<img>` gaining a `src` attribute after insertion),
  plus `data-src` set later, a `<source>`'s `srcset` set later inside
  a `<picture>`, and a check that repeated attribute changes on an
  already-wrapped image never cause double-wrapping. Full regression
  of the `watch_container` scoping tests confirms no other impact.

## 2.0.5
Response to a ChatGPT code review suggestion (performance, non-urgent):

- **CSS/JS assets are no longer loaded unconditionally.** Previously,
  `ensureAssetsLoaded()` ran after every `wrapImages()` call regardless
  of whether any image was actually found and wrapped - meaning a plain
  text article with zero images still triggered the lightbox CSS/JS on
  every page load.
- **The exact rule requested**: when `watch_dynamic` is enabled
  (the default), assets are still always loaded - a `MutationObserver`
  needs to be running to catch images that might be added later via
  AJAX, even if the page has none on initial render. Only when
  `watch_dynamic` is disabled does the plugin skip loading assets
  entirely for content where nothing was actually wrapped.
- `HtmlProcessor::wrapImages()` gained an optional by-reference output
  parameter (`int &$wrappedCount = 0`) reporting how many images were
  actually wrapped - deliberately backward compatible (default value
  means every prior call site, including all 34 existing tests, keeps
  working unchanged without passing it).
- Verified with automated tests covering all four combinations: no
  images, no counted images are ignored (e.g. `exclude_classes`-filtered
  images correctly don't count), and - the case that matters most -
  `watch_dynamic=true` with zero images still triggers asset loading.
  Full regression pass (34 isolated + 6 site-client tests) confirms no
  other behavior changed.

## 2.0.4
Response to a ChatGPT code review recommendation (P1):

- **Client check switched from a blacklist to a whitelist.** Previously:
  `if ($this->getApplication()->isClient('administrator')) { return; }`
  ("don't run in admin") - now:
  `if (!$this->getApplication()->isClient('site')) { return; }`
  ("only run in the site frontend").
- Joomla recognizes more application client types than just `site` and
  `administrator` - confirmed via Joomla's own API docs and issue
  tracker: at minimum `cli` (console commands) and `installation`, and
  Joomla 4+ added an `api` client for the Web Services REST API. A
  plugin that manipulates HTML output and touches a frontend
  `WebAssetManager` has no reasonable business running in any of those
  contexts - and the old blacklist would have silently let all of them
  through, since none of them are `'administrator'`.
- Concretely, if Joomla's Web Services API triggers the same
  `onContentPrepare` event chain while preparing article content for a
  JSON response (plausible, since it reuses the same content pipeline),
  the plugin would previously have tried to call
  `getDocument()->getWebAssetManager()` on a document that may not
  support it the same way - the site-only whitelist rules this out
  entirely rather than depending on it happening not to break.
- Verified with automated tests across five client types (`site`,
  `administrator`, `api`, `cli`, `installation`) confirming only `site`
  is processed - the three new ones (`api`, `cli`, `installation`) would
  have incorrectly been processed under the old blacklist logic, and are
  now correctly skipped. A dedicated test also confirms
  `WebAssetManager` registration still completes successfully for the
  `site` case. Full regression pass across all 34 isolated Support-class
  tests confirms no other behavior changed.

## 2.0.3
Response to a ChatGPT code review recommendation (P1, "more properly
Joomla 6 native" - not fixing a bug, improving idiomatic correctness):

- **`onContentPrepare()` now type-hints the concrete
  `Joomla\CMS\Event\Content\ContentPrepareEvent`** instead of the
  generic `Joomla\Event\Event`, and extracts the context/item via the
  named accessors `$event->getContext()`/`$event->getItem()` instead of
  `array_values($event->getArguments())`.
- The `array_values(...)` pattern remains the officially documented,
  correct choice when a plugin must stay compatible with **both**
  `GenericEvent` and a concrete event class (exactly why it was kept in
  the classic build, which supports J3 through J6 from one codebase).
  This native build targets Joomla 6 exclusively, where
  `ContentPrepareEvent` is guaranteed to be dispatched, so the named
  accessors are strictly more correct here: no positional-argument-order
  assumption, and immediate static type errors instead of silent
  `null`/wrong-type bugs if Joomla's argument order or count ever
  changed in some future version.
- Verified against a `ContentPrepareEvent` stub matching the real
  `getContext(): string` / `getItem(): object` contract confirmed via
  Joomla's own API documentation and plugin tutorial (both explicitly
  demonstrate this exact usage pattern) - including a full regression
  of admin-context skipping and the per-article `rel` grouping fix.
  Full regression pass across all 34 isolated Support-class tests
  confirms no other behavior changed (this change only touches the
  Joomla-facing event handling, not the pure-PHP processing logic).

## 2.0.2
Response to a ChatGPT code review observation (P2, quality improvement):

- **`srcset` "w" and "x" descriptors were compared as if on the same
  numeric scale.** `SrcSetResolver::parseLargestFromSrcset()` picked the
  candidate with the highest raw number regardless of whether it came
  from a width descriptor (`1200w`) or a pixel-density descriptor
  (`2x`) - meaning `image.webp 2x, image.jpg 1200w` would compare `2`
  against `1200` and pick `1200w`, which happened to be reasonable in
  that specific example but isn't actually a valid comparison in
  general (the two units aren't on the same scale). This matters
  specifically because `getCombinedSrcset()` pools `srcset` values from
  multiple `<picture><source>` elements together, and different
  `<source>` elements can legitimately use different descriptor types.
- **Fix:** candidates are now split by descriptor type first. If any
  `"w"` candidates exist, only those are compared (largest width wins,
  `"x"` candidates are ignored entirely for that comparison). Only when
  there are no `"w"` candidates at all does it fall back to comparing
  `"x"` candidates against each other.
- Ported identically to both the PHP side (`SrcSetResolver`) and the JS
  side (`MutationObserver` handling for dynamically added images) to
  keep both processing paths consistent.
- **Classic build (repository root) deliberately NOT changed** - it's
  frozen per the earlier decision to stop Joomla 3.10 development, and
  this quality improvement doesn't fix a user-visible bug, so it wasn't
  worth reopening that line for.
- Verified with automated tests reproducing the exact scenario from the
  report (`2x` vs `1200w`), plus the more demanding case of a small `w`
  number correctly beating a large `x` number, both in PHP and in a
  real DOM environment (jsdom) for the JS side. Full regression pass
  (34 isolated tests + 8 integration tests) confirms no other behavior
  changed.

## 2.0.1
**Real production bug**, found and reported directly from testing on a
live Joomla 6.1.2 site (khanovaskola.sk):

- **CSS and JS never actually loaded on the page**, despite the inline
  config script (`window.FG_AUTOLIGHTBOX_CONFIG = {...}`) rendering
  correctly and the PHP-side image processing working perfectly (images
  correctly wrapped in `<a class="alb-link autolightbox" rel="...">`).
  The practical effect: clicking an image just navigated the browser
  directly to the raw image file - no lightbox opened at all.
- **Root cause:** `ensureAssetsLoaded()` used
  `$wa->getRegistry()->addRegistryFile('media/plg_content_fgautolightbox/joomla.asset.json')`
  followed by `$wa->useStyle(...)`/`$wa->useScript(...)`. This is exactly
  the mechanism flagged as *unverified* in the 2.0.0 changelog entry -
  and on a real site, it failed silently: no exception, no error, the
  `<link>`/`<script src>` tags for the CSS/JS just never appeared in the
  rendered `<head>`, while the unrelated `addInlineScript()` call
  (issued right after, for the config) worked correctly.
- **Fix:** switched to `WebAssetManager::registerAndUseStyle()` and
  `registerAndUseScript()` - the direct, well-documented one-call API
  for registering and enqueuing a single custom asset, with no
  intermediate JSON registry file involved at all. Cache-busting
  reverted to the classic build's proven `filemtime()`-based approach
  (`?v=<timestamp>` appended to the URI) instead of the registry file's
  `"version": "auto"`, since that mechanism could no longer be verified
  as working either once the registry-file approach was abandoned.
- `media/joomla.asset.json` removed entirely (no longer used) - along
  with its `<file>` declaration in the plugin manifest's `<media>` block.
- Verified with an updated integration test (8 checks) confirming
  `registerAndUseStyle()`/`registerAndUseScript()` are called with the
  correct asset names, correct URIs pointing at
  `/media/plg_content_fgautolightbox/{css,js}/fgautolightbox.{css,js}`,
  and a numeric cache-busting query parameter - plus a full rerun of all
  34 prior isolated tests confirming no other behavior changed.
- **Still not verified**: this fix has not yet been re-tested on the
  live site that surfaced the bug. The previous "not verified" note
  about `WebAssetManager` behavior in 2.0.0 turned out to matter in
  practice - so this fix should be treated the same way until confirmed
  working on a real page load, not assumed correct just because the
  reasoning and mocked tests check out.

## 2.0.0
First release of the native Joomla 6 build. Functionally equivalent to
classic build v1.3.2 (same behavior, same settings, same JS/CSS engine),
rebuilt on a modern, Joomla-6-only architecture:

- **PSR-4 namespaced classes** (`FG\Plugin\Content\Fgautolightbox\...`)
  instead of a single flat file with a legacy/modern dual-path `trait` -
  the classic build's `interface_exists('Joomla\Event\SubscriberInterface')`
  branching (needed to support both J3 and J4-6 from one file) is gone
  entirely, since this build only ever targets J6.
- **Constructor dependency injection** via `services/provider.php` - the
  plugin's core image-processing logic (`HtmlProcessor` and its
  collaborators: `SrcSetResolver`, `LinkAttributes`, `ExtensionFilter`,
  `ContentScopeResolver`) has zero dependency on Joomla APIs and can be
  unit-tested in complete isolation, without any Joomla bootstrap or
  stubbing.
- **`WebAssetManager`** (`joomla.asset.json` + `useStyle()`/`useScript()`/
  `addInlineScript()`) instead of the classic build's `addStyleSheet()`/
  `addScript()`/`addScriptDeclaration()` calls - lets Joomla's own asset
  registry handle dependency ordering and versioning instead of the
  classic build's manual `filemtime()`-based cache-busting.
- **PHP 8.3+ syntax throughout**: a `CaptionMode` enum instead of raw
  string comparisons for `show_caption`, `readonly` properties,
  constructor property promotion, `match` expressions, typed class
  constants, `str_contains()`/`str_starts_with()`.
- **Regex HTML fallback removed.** The classic build kept a regex-based
  fallback parser for the (extremely rare) case that `DOMDocument` isn't
  available on the server. On Joomla 6, the `dom` PHP extension is a
  hard requirement of Joomla itself, so that scenario cannot occur here -
  removing it simplifies the codebase with no loss of real-world safety.
- All settings, defaults, and behavior are unchanged from classic 1.3.2:
  gallery grouping (per-article, via the 1.2.1 fix), `data-full`/
  `data-highres`/`srcset`/`<picture>` resolution (point A/E), extensible
  contexts for K2/Zoo (point B), `MutationObserver` container scoping
  (point C), JSON hardening (point 5), navigation/preload toggles
  (points P3), and the `libxml` global-state fix (point 4).
- **Verified**: 34 isolated unit-style tests covering every support class
  (`SrcSetResolver`, `ContentScopeResolver`, `ExtensionFilter`,
  `LinkAttributes`, `HtmlProcessor`) with zero Joomla stubbing required,
  plus 11 integration tests against a stubbed Joomla environment
  (`CMSPlugin`, `DispatcherInterface`, `Event`, `WebAssetManager`)
  covering the full `onContentPrepare` flow: admin-context skip,
  disallowed-context skip, per-article `rel` grouping across multiple
  simultaneous plugin instances, constructor-time `allowed_extensions`
  wiring, and correct `WebAssetManager` registration (style/script/
  inline-script/registry-file calls).
- **Not verified (documented, not hidden)**: this build could not be
  installed and tested on a real, running Joomla 6 instance from this
  environment. In particular, the exact behavior of
  `WebAssetManager::addInlineScript()`'s dependency-based ordering
  relative to the registered `plg_content_fgautolightbox.script` asset,
  and whether `"version": "auto"` in `joomla.asset.json` resolves the
  way expected, should be confirmed on a live site before relying on
  them in production. As a safety net, the JS engine (unchanged from
  classic) already reads `window.FG_AUTOLIGHTBOX_CONFIG` lazily inside
  `albInit()` rather than at top-level script-parse time - the same
  defensive pattern that fixed the classic build's 1.0.10 production
  bug - so even if asset ordering turns out imperfect, the plugin should
  not silently fall back to wrong defaults the way the classic build
  once did.
- **Deliberately not pursued in this release**: bundling
  `masterminds/html5-php` as a scoped Composer dependency to eliminate
  the `<source>`-is-not-void parsing workaround in `SrcSetResolver`
  (`findPictureAncestor()` walks up the ancestor chain instead of
  checking the immediate parent). The current workaround is already
  fully tested and correct; adding a bundled third-party parser is a
  meaningful extra maintenance surface (Composer vendor packaging,
  potential version conflicts with other extensions bundling the same
  library) for a problem that has no user-visible symptom today.

## Installation

This build requires **Joomla 6.0+ and PHP 8.3+**. It is a separate
extension element install from the classic build - see the "Two builds"
section in the main [README](../README.md) before installing.
