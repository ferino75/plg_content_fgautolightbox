# Changelog — plg_content_fgautolightbox (Joomla 6 native build)

This changelog covers the **native Joomla 6 build** only (this `joomla6/`
folder). For the classic Joomla 3.10 build's history, see
[`../CHANGELOG.md`](../CHANGELOG.md) in the repository root.

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
