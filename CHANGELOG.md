# Changelog — plg_content_fgautolightbox

## 1.3.0
Response to a ChatGPT code review suggestion (P3, new feature):

- **New "Enable gallery navigation" parameter** (enabled by default,
  matching all prior behavior). When disabled, the lightbox acts as a
  single-image viewer: each image opens on its own, with no prev/next
  arrows, no `X / Y` counter, and no way to navigate to other images via
  keyboard (`←`/`→`) or swipe - useful for sites that want a plain
  "click to zoom" experience without implying a gallery exists.
- Escape (close) and Tab (focus trap) continue to work normally
  regardless of this setting - only navigation *between* images is
  affected.
- Verified with automated tests: with navigation enabled, arrows/counter
  are visible and keyboard navigation works exactly as before; with it
  disabled, arrows/counter are hidden and `ArrowRight` no longer changes
  the displayed image. Full regression pass across all prior points
  confirms no other impact.

## 1.2.3
Response to a ChatGPT code review (P2, defense-in-depth hardening):

- **`json_encode()` for the inline `<script>` config block now uses
  `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.**
  Without these flags, a configuration value (e.g. `exclude_urls`,
  `exclude_classes`, `watch_container`) could in principle contain a
  sequence like `</script>` that would end the script block early when
  embedded raw. This isn't a realistic vulnerability today - the plugin
  configuration is only editable by trusted Joomla administrators, not
  by untrusted input - but it's a reasonable extra defensive layer with
  no downside.
- Verified with automated tests: the hardened JSON still round-trips to
  byte-identical data after decoding, dangerous characters (`<`, `>`,
  etc.) no longer appear raw in the output, and — most importantly — the
  JS engine correctly receives the original, unescaped value when the
  script is actually parsed (simulated via Node's `eval`, matching what
  a browser's `<script>` tag parser would do). Full regression pass
  across all prior points confirms no other impact.

## 1.2.2
Response to a ChatGPT code review (P2, global PHP state hygiene):

- **`libxml_use_internal_errors(true)` global state was never restored.**
  The previous code enabled internal libxml error handling before
  `DOMDocument::loadHTML()` and cleared the accumulated errors afterward,
  but never restored the *previous* setting — silently leaving this
  global PHP setting changed for the rest of the request, which could
  affect other code/plugins running in the same request that rely on
  the default (visible) libxml error behavior.
- Fixed with the standard save/try/finally pattern: the previous state
  is captured via `libxml_use_internal_errors(true)`'s own return value,
  and restored in a `finally` block so it happens even if `loadHTML()`
  or anything around it were to throw.
- **Bonus fix found while touching this code**: if `DOMDocument::loadHTML()`
  failed and the plugin fell back to the regex-based parser, the
  per-article `$instanceKey` (the 1.2.1 grouping fix) was silently
  dropped — falling back to the old ungrouped `rel` behavior in that
  rare scenario. Now correctly passed through.
- Verified with automated tests: the libxml global setting is correctly
  restored to its prior value in both directions (was `true` before the
  call, was `false` before the call), and the regex-fallback path now
  correctly includes the instance key in its output. Full regression
  pass across all prior points (A–E, plus the 1.2.1 grouping fix)
  confirms no other impact.

## 1.2.1
**Critical fix (P1)**, reported by a ChatGPT code review:

- **The "per-article grouping" feature never actually worked.** The
  README and the 1.0.3 changelog entry claimed that on a category/blog
  page, arrow navigation stays within the article you clicked in. In
  reality, every image on the page got the exact same `rel` attribute
  (just the static `gallery_group` setting value, identical for all
  content), so the JS grouped ALL images from ALL articles on the page
  into one single gallery — e.g. clicking image 2 of 3 in Article A
  could show "2 / 7" instead of "2 / 3", mixing in images from Articles
  B and C.
- **Root cause:** `rel` was always set to the plain configured
  `gallery_group` value with no per-content uniqueness added.
- **Fix (PHP, server-rendered images):** each processed article/item now
  gets a unique "instance key" appended to the `gallery_group` value —
  preferably the content's own `id` property (works for `com_content`,
  `com_contact`, `com_newsfeeds`, K2, Zoo, and most other components),
  falling back to `spl_object_hash()` for any content type without an
  `id`. Images within the *same* article still correctly share one
  group; different articles on the same page now get different, isolated
  groups.
- **Fix (JS, dynamically added images):** since client-side code has no
  access to a Joomla content ID, `MutationObserver`-detected images use
  the closest ancestor element with its own `id` attribute as scoping
  instead, with a safe fallback to the previous (unscoped) behavior if
  no such ancestor is found.
- Verified by first reproducing the exact reported bug with a direct
  test (three articles on one simulated category page, confirmed all
  three ended up sharing one identical `rel` value), then confirming the
  fix resolves it. Additional edge cases verified: images within one
  article/item still share a group, content without an `id` property
  still gets correctly isolated (via the hash fallback), a custom
  `gallery_group` setting is still respected as the base prefix, and the
  falsy-but-valid `id = 0` case is handled correctly (not mistaken for
  "no id"). Full regression pass across all prior points (A–E) confirms
  nothing else was affected.

## 1.2.0
Response to a Qwen AI code review:

**A. Responsive image support (`srcset` and `data-full`)**

- The plugin now picks the best available image URL using a clear
  priority order: `data-full` / `data-highres` (explicit author choice)
  → `data-src` (lazy-load convention) → the largest resolution found in
  `srcset` → `src` (final fallback).
- Solves the original problem: on templates using `srcset` for
  responsive images, the lightbox previously always opened whatever
  `href` ended up being (same as `src`/`data-src`), which could be a
  smaller, compressed variant even on a large monitor.
- New optional `data-full`/`data-highres` attribute support lets you
  explicitly point at a genuine full-resolution original when one exists
  at a separate URL from anything in `srcset`.
- `srcset` parsing supports both width descriptors (`800w`) and pixel
  density descriptors (`2x`); the entry with the highest numeric value
  wins, with a safe fallback to the first URL when descriptors are
  missing or ambiguous.
- Implemented identically in all three places the plugin reads an image
  source: the DOM branch, the regex fallback, and JS (`MutationObserver`
  for dynamically added images) — so server-rendered and dynamically
  added images behave the same way.
- **Bug found and fixed during testing**: the regex fallback's naive
  attribute-name matching could accidentally match `src=` as a substring
  inside `data-src=`, with correctness depending on attribute order in
  the tag. Fixed by requiring a proper attribute boundary (start-of-string
  or preceding whitespace) before the attribute name.
- Verified with automated tests: `srcset` parsing (six scenarios,
  including out-of-order entries and missing descriptors), the full
  priority chain in both PHP branches and in JS, attribute-order
  independence after the boundary fix, and a full regression pass to
  confirm existing functionality (class exclusion, diacritics, existing
  links, SVG exclusion, extension filtering) is unaffected.

**B. Extensible context support (K2, Zoo, custom components)**

- Problem: the plugin had a hardcoded allowlist of contexts
  (`com_content.article`, `com_content.featured`, `com_content.category`,
  `com_contact.contact`, `com_newsfeeds.newsfeed`) — sites using K2, Zoo,
  or a custom component got no lightbox at all.
- Solution: a new **"Extra allowed contexts"** setting extends the
  built-in list without changing default behavior for existing sites.
  Supports three formats, comma-separated:
  - an exact context (`com_k2.item`)
  - a whole component via wildcard (`com_k2.*`)
  - the same wildcard written without the suffix (`com_k2`)
- Deliberately kept as an allowlist extension rather than switching to a
  blocklist (process everything except explicitly excluded) — a blocklist
  would risk the plugin firing on unrelated `onContentPrepare` uses
  (system-rendered fragments, modules, editor previews, etc.) that
  happen to share the same event but were never meant to have images
  auto-wrapped.
- Verified with 11 automated test scenarios: default behavior unchanged
  with no setting, exact-context matching, both wildcard syntaxes,
  and multiple entries combined.

**C. `MutationObserver` performance tuning**

- Problem: watching the entire `document.body` with `subtree: true` can
  add unnecessary CPU overhead on very dynamic pages (lots of animations,
  chat widgets) unrelated to the article content.
- Solution: a new **"Watch container (CSS selector)"** setting lets you
  scope the `MutationObserver` to only the container(s) that actually
  hold article content (e.g. `.item-page, .blog`), instead of the whole
  page. Multiple comma-separated selectors are supported; each matching
  element is watched individually.
- Safety fallback: if the selector matches nothing (e.g. a typo), the
  plugin falls back to watching the whole page rather than silently
  disabling dynamic-image detection.
- Left empty (default), behavior is exactly as before — no change for
  existing sites.
- Verified with 5 automated tests: a mutation *outside* the configured
  container is correctly ignored, a mutation *inside* it is correctly
  processed, an invalid/non-matching selector correctly falls back to
  `document.body`, multiple containers via a comma-selector both work,
  and the no-selector default behavior is unchanged.

**E. `<picture>` element support**

- Problem: when an editor inserts an image as
  `<picture><source srcset="..."><img src="..."></picture>`, the plugin
  correctly found the `<img>`, but the lightbox `href` was always just
  the plain `src` — ignoring any higher-resolution variant offered
  through the `<picture>`'s `<source>` elements.
- Solution: the "largest resolution from srcset" logic from point A now
  also looks at every `<source>` inside the enclosing `<picture>` (not
  just the `<img>`'s own `srcset`), and picks the single largest
  candidate across all of them — including across multiple `<source>`
  elements used for format switching (e.g. WebP vs. JPEG).
- **Real bug found and fixed during testing**: `DOMDocument`/`libxml`
  does not treat `<source>` as the HTML5 void element it actually is —
  it nests the following `<img>` *inside* `<source>` in its internal
  tree instead of treating it as a sibling. Detecting the enclosing
  `<picture>` therefore has to walk up the ancestor chain (not just
  check the immediate parent), and then search for `<source>` elements
  anywhere within that `<picture>` subtree (not just direct children).
  Verified with jsdom (a spec-compliant HTML5 parser, unlike libxml)
  that the actual served markup still parses correctly in a real
  browser despite libxml's internal tree looking "wrong".
- **A second, more serious bug found and fixed**: initially the plugin
  wrapped just the `<img>` in the lightbox `<a>`, same as for any other
  image. For a `<picture>`, this breaks something real: per the HTML
  spec, native `<source>`-based responsive/format switching only
  applies when `<img>` is a *direct child* of `<picture>` — inserting
  an `<a>` in between silently disables that switching for the page's
  normal (non-lightbox) display, confirmed by testing the actual
  parsed DOM structure. Fixed by wrapping the *entire* `<picture>`
  element in the lightbox link instead of just the `<img>` inside it,
  in both the PHP DOM branch and JS (`MutationObserver` for
  dynamically added images) — the `<picture>`'s internal structure is
  left completely untouched, so native responsive switching keeps
  working exactly as before, while the whole visible image area is
  still clickable for the lightbox.
- **Known limitation, documented in the code**: the rare regex fallback
  path (used only if `DOMDocument` isn't available on the server at
  all) processes each `<img>` in isolation and has no awareness of a
  surrounding `<picture>`/`<source>` structure — it falls back to the
  `<img>`'s own attributes only. This only affects that fallback path,
  not the primary DOM-based processing virtually every real
  installation uses.
- Verified with automated tests throughout: srcset combination across
  multiple `<source>` elements, the DOM-nesting workaround, real-browser
  parsing of the served markup (confirming `<img>` stays a direct child
  of `<picture>`), the JS-side dynamic-image equivalent, and a full
  regression pass confirming ordinary (non-`<picture>`) images are
  completely unaffected.

## 1.1.0 (fix)
- Added the FG brand to the plugin's **displayed name** in the admin as
  well — the previous package only renamed the element/class, but the
  language files still read "Content - AutoLightbox" / "Obsah -
  AutoLightbox". Now correctly "Content - FG AutoLightbox" / "Obsah - FG
  AutoLightbox" (en-GB and sk-SK, both ini and sys.ini).

## 1.1.0
**Aligned with the FG plugin series** (same convention as
plg_system_fgstripcomments, plg_system_fgemailremover, etc.):

- Plugin element renamed: `autolightbox` → `fgautolightbox` (affects the
  install folder/file and the `<filename>` in the XML manifest).
- Main PHP file renamed to `fgautolightbox.php`.
- Class renamed: `PlgContentAutolightbox` → `PlgContentFgautolightbox`
  (internal helper classes/trait renamed the same way).
- Install media folder: `/media/plg_content_fgautolightbox/` (previously
  `/media/plg_content_autolightbox/`).
- CSS/JS files renamed: `fgautolightbox.css`, `fgautolightbox.js`.
- Global JS config variable: `window.FG_AUTOLIGHTBOX_CONFIG` (previously
  `window.ALB_AUTOLIGHTBOX_CONFIG`).
- Language constants: `PLG_CONTENT_FGAUTOLIGHTBOX_*` (previously
  `PLG_CONTENT_AUTOLIGHTBOX_*`), language files renamed to match.
- Author in the manifest: `FG`.
- **Deliberately left unchanged**: internal CSS/JS identifiers
  (`#alb-overlay`, `.alb-link`, local JS variables
  `ALB_SELECTOR`/`ALB_CONFIG`) and default parameter values
  (`autolightbox`, `autolightbox-gallery`) — these are implementation
  details, not the plugin's public identity, and changing them would add
  regression risk with no real benefit. The default parameter values also
  preserve backward compatibility for any custom CSS keyed to
  `.autolightbox`.
- Verified: full functional regression test after the rename (class,
  CSS/JS enqueue paths, config variable, class-based exclusion) — behavior
  is unchanged, only the name is new.

**Important for installation:** since the plugin element changes, Joomla
will not recognize this as an "update" to the existing
`plg_content_autolightbox` — it needs to be installed as a new plugin, and
the old one (`Content - AutoLightbox` under the original name) manually
uninstalled/disabled, to avoid images being processed twice.

## 1.0.15
Response to a Grok AI code review (minor items):

- **Added language support**: the XML manifest and the entire admin UI
  (field names, descriptions, options) are now translated — English
  (`en-GB`, the mandatory fallback) and Slovak (`sk-SK`, what actually
  shows up in this site's admin). Previously everything was hardcoded in
  English directly in the XML.
- Verified: all 27 language constants used in the XML have a translation
  in both language files (no raw `PLG_...` strings should ever be
  displayed).
- **Author in the manifest**: changed away from "Claude" — set as a
  placeholder pending confirmation.

## 1.0.14
Response to a Grok AI code review (functional/UX improvements):

- **`data-src` support** (lazy-load convention): the plugin now prefers
  `data-src` over `src` when present — addresses the risk that lazy-loaded
  images (class `lazy`, exactly as on fnspza.sk) would send only a
  placeholder into the lightbox instead of the real image. Implemented in
  PHP (both the DOM and regex branches) and in JS (`MutationObserver` for
  dynamically added images).
- **Preloading neighboring images**: opening or navigating the lightbox
  now preloads the previous and next image in the background, so
  arrow-key navigation feels smoother.
- **New "Watch for dynamically added images" parameter** (enabled by
  default) — lets you disable the `MutationObserver` on pages with a very
  "busy" DOM (frequent animations, chat widgets, etc.) where it could add
  unnecessary overhead. Static images keep working normally even when
  disabled.
- **More reliable "already processed" detection**: instead of relying
  solely on the persistent `data-alb-done` attribute (which can end up in
  an inconsistent state — exactly what caused the production bug fixed in
  1.0.10), the plugin now primarily uses a `WeakSet` tied to the actual
  object reference. An image with a "stale" attribute but no real wrapper
  is now correctly reprocessed instead of being silently skipped forever.
- **Deliberately NOT adopted**: deep-linking via URL hash (`#alb-3`) —
  ambiguous behavior with multiple galleries on one page (which one would
  open?), low value for a typical Joomla article relative to the
  complexity a correct implementation would need.
- **Explained, not implemented**: the native browser `loading="lazy"`
  attribute needs no special handling on our end — the `<img>` element is
  present in the DOM immediately, the browser just defers the actual file
  download; this doesn't affect how the plugin reads `src`/`data-src`
  attributes. The real "lazy-load" concern (JS libraries swapping `src`
  only once the image scrolls into the viewport) is already covered by
  the `data-src` support above.
- Verified with automated tests (jsdom): `data-src` in both PHP branches
  and in JS, the `watchDynamic` toggle, preload calls, and "already
  processed" detection including the edge case of an inconsistent
  attribute.

## 1.0.13
Response to a Grok AI code review (CSS section):

- **CSS custom properties for theming**: colors, sizes, and `z-index` are
  now defined as CSS custom properties (`--alb-overlay-bg`,
  `--alb-text-color`, `--alb-caption-color`, `--alb-z-index`, etc.) on
  `#alb-overlay`. They can be overridden from your own CSS without
  touching the plugin file — an example is right in the comment at the
  top of the CSS file.
- **`z-index` conflicts with other elements** (cookie banners, other
  lightboxes): solved the same way, via the `--alb-z-index` variable —
  just override it to a higher value in your template, no need to edit
  the plugin.
- **New "Show caption on mobile too" parameter** (disabled by default).
  Hiding the caption on mobile was a previous deliberate decision (to
  keep the image as large as possible on a small screen), so I did NOT
  change the default behavior — I added this as an opt-in setting for
  anyone who does want the caption visible on mobile.
- Verified: CSS structure (balanced braces, variables used vs. defined),
  and the JS logic toggling the `alb-caption-mobile` class in combination
  with hiding an empty caption (jsdom test).

## 1.0.12
Response to a Grok AI code review (JavaScript section):

- **Accessibility (a11y)**: `#alb-overlay` now has `role="dialog"` +
  `aria-modal="true"`; the close/prev/next controls are now real
  `<button>` elements (previously `<span>`, not keyboard-operable) with
  `aria-label`.
- **Focus trap**: while the lightbox is open, Tab/Shift+Tab cycles only
  between the buttons inside it (close/prev/next) and doesn't escape to
  the rest of the page. On close, focus returns to whatever element had
  it before.
- **Lightbox alt text independent of `show_caption`**: previously, when
  `show_caption` was set to "none", the image in the lightbox had no
  `alt` at all (bad for screen readers) — the visible caption and the
  accessible alt text are now separate (`data-alb-alt` is generated
  whenever the image has an `alt`, regardless of the visible-caption
  setting).
- **An empty caption is now actually hidden** (previously it rendered as
  an empty line/gap on desktop even with `show_caption: none`).
- **Swipe gesture fixed**: `touchmove` now calls `preventDefault()` only
  when the gesture is predominantly horizontal — vertical
  scrolling/pinch-zoom is no longer blocked from the very start of the
  touch.
- **Guard against double initialization** (`window.ALB_INITIALIZED`).
- **Deliberately NOT adopted**: rewriting `var`/`for` to
  `const`/`let`/`for...of` (purely stylistic, no functional benefit,
  needless regression risk) and an IE11 polyfill for `closest()` (IE11
  has been out of support since 2022; in 2026 adding complexity for a
  browser essentially nobody uses doesn't make sense).
- All changes verified with automated tests (jsdom): a11y attributes, alt
  text independent of the caption, hiding an empty caption, focus-trap
  Tab/Shift+Tab cycling, and touchmove's directional logic (horizontal vs.
  vertical gesture).

## 1.0.11
Response to a Grok AI code review:

- **Removed duplication** between the DOM branch and the regex fallback:
  parsing `exclude_classes`, building the caption (`buildTitle`), and the
  resulting CSS class (`buildLinkClass`) are now shared helper methods
  instead of copy-pasted code in two places.
- **`hasAllowedExtension()`** now internally uses
  `getAllowedExtensionsList()` instead of re-parsing the same setting.
- **Removed the version-desync risk**: the cache-busting parameter
  (`?v=...`) on the CSS/JS files is no longer based on a manually
  maintained version number (which had to stay in sync in two places —
  PHP and XML), but on the file's `filemtime()` — it changes automatically
  whenever the file actually changes.
- **`$page` parameter** documented with a comment (unused, kept to match
  the positional arguments Joomla's event sends).
- **Tried, but REJECTED**: modernizing the `DOMDocument` parsing
  (`LIBXML_HTML_NOIMPLIED` without a full HTML wrapper) — an empirical
  test showed it corrupts HTML structure when there are several sibling
  `<p>` elements (the normal structure of a real Joomla article): it
  merges the first two `<p>` tags together and adds a stray trailing
  `</p>`. Kept the original, proven-safe approach with a full
  `<html><body>` wrapper.
- **Explained why `array_values($event->getArguments())` is NOT fragile**,
  as Grok suggested: per the official Joomla documentation, this is
  exactly the recommended pattern for compatibility across both
  GenericEvent and concrete event classes. Extracting by named keys
  (`$args['item']`) would actually be more fragile — the documentation
  explicitly notes that, e.g., the `item` argument is stored under the key
  `subject`, not `item`.
- All changes verified with a regression test (5 scenarios: class
  exclusion, diacritics, existing links, SVG, filename caption, custom
  link_class, allowed_extensions) plus a dedicated test of the
  cache-busting mechanism.

## 1.0.10
- **Fixed a real production bug** (found and reported directly from
  fnspza.sk): the `exclude_classes` and `show_caption` settings sometimes
  weren't applied to images processed by the client-side JS engine
  (`MutationObserver` for dynamically added images, introduced in 1.0.5)
  — specifically for images that some other library on the page (e.g. a
  "lazy loading" script working with the `lazy` class) detaches and
  reattaches in the DOM tree, which our `MutationObserver` then treats as
  a "new" image.
- **Root cause:** the external JS file (`media/js/autolightbox.js`) read
  `window.ALB_AUTOLIGHTBOX_CONFIG` immediately when the file loaded (at
  the top level), not only once `DOMContentLoaded` fired. Joomla renders
  `<script src>` tags and inline `<script>` blocks in separate groups
  (independent of the order in which the plugin's PHP code calls them),
  so the engine would sometimes run before the configuration had even
  been set — falling back to the hardcoded defaults instead of the actual
  settings from the admin panel (`exclude_classes` empty, `show_caption`
  always "alt").
- **Fix:** reading the configuration was moved inside `albInit()`, which
  only runs once the entire `<head>` has finished loading — the result no
  longer depends on script order at all.
- Verified with an automated test that simulates exactly this scenario
  (engine running before the config is set, plus detaching/reattaching an
  image in the DOM) — confirmed that the old 1.0.9 code actually
  reproduces the bug and the fixed version resolves it.

## 1.0.9
- Architecture change: the plugin now supports Joomla's modern event
  system (`SubscriberInterface` + `getSubscribedEvents()`) on Joomla 4, 5,
  and 6, instead of relying solely on the old "legacy" way of registering
  an event handler (direct positional parameters in the
  `onContentPrepare` method).
- On Joomla 3, the plugin still works the original (legacy) way — there,
  `SubscriberInterface` isn't reliably available. The choice between the
  two approaches happens automatically when the plugin loads
  (`interface_exists(...)`), no action needed.
- Reason for the change: the Joomla documentation marks the old approach
  as kept only temporarily for backward compatibility, with the note that
  it may be removed in a future major version (likely J7+). The new
  implementation is ready for long-term compatibility without further
  work needed when moving to J6+.
- Internal reorganization: the shared logic (image processing, asset
  loading, all helper methods) now lives in a PHP `trait` used by both
  class variants (modern and legacy) — no code duplication.
- Functional behavior is unchanged — verified with a regression test that
  compares output before and after the refactor across several scenarios
  (diacritics, existing links, SVG exclusion, component exclusion), and
  with separate tests of both branches (simulating a J3 environment
  without `SubscriberInterface`, and a J4/5/6 environment with
  `SubscriberInterface` and an `Event` object).

## 1.0.8
- Fix: server-rendered images (PHP, on first page load) and dynamically
  added images (JS, `MutationObserver` for AJAX) were getting a
  DIFFERENT set of CSS classes under default settings — the server added
  `alb-link autolightbox`, while AJAX images got only `alb-link` (missing
  the default `autolightbox` class). This was caused by inconsistent
  logic between PHP (`wrapImages`) and the configuration sent to JS. Both
  paths now use identical logic and, given the same settings, always
  produce the same set of classes, whether the image was processed by the
  server or dynamically in the browser.
- Verified with an automated test comparing server-side and JS-side
  output for 4 different `link_class` values (default, custom, `alb-link`,
  empty).

## 1.0.7
- Architecture change: CSS and JS are no longer generated inline in PHP
  (`addStyleDeclaration`/`addScriptDeclaration`), but are separate static
  files: `media/css/autolightbox.css` and `media/js/autolightbox.js`.
  Benefits: the browser can cache them across pages, they can be
  minified, and they're easier to edit (syntax highlighting, linting in
  an editor).
- Introduced a fixed internal class, `alb-link`, that the static JS/CSS
  engine's functionality relies on. The `link_class` setting no longer
  determines the functional selector — it now serves only as an OPTIONAL
  extra class for custom styling (added alongside `alb-link`). This is
  what made it possible for the JS and CSS files to be genuinely static,
  with no PHP variables inside them.
- Dynamic settings from the plugin's admin panel (gallery_group,
  exclude_classes, show_caption, allowed_extensions, an optional extra
  link_class) are now sent through a small, separate inline block —
  `window.ALB_AUTOLIGHTBOX_CONFIG = {...}` — containing only data, not
  logic, so the main engine file stays fully static and cacheable.
- Added a `<media>` block to the XML manifest (installs to
  `/media/plg_content_autolightbox/`), and a cache-busting parameter
  (`?v=1.0.7`) appended to both files' URLs (prevents an old cached
  version from being used after an update).
- Backward compatibility: the default `link_class` value (`autolightbox`)
  is still added as an extra class, so any custom CSS from earlier
  versions keyed to `.autolightbox` keeps working.
- Verified: JS and XML syntax, and the dynamic config's functionality in
  a simulated DOM environment (jsdom), including the `MutationObserver`
  branch for AJAX images.
- **Testing note:** the exact behavior of installing the `<media>` block
  and of URL generation via `Uri::root()` was verified logically and
  against the Joomla documentation, but could not be tested on a real,
  running Joomla instance. If the CSS/JS fail to load after installing
  (e.g. a 404 on `/media/plg_content_autolightbox/...`), let me know — it's
  likely a small path discrepancy that can be fixed quickly.

## 1.0.6
- Security improvement: added a check for allowed file extensions. By
  default the lightbox now only applies to `jpg, jpeg, png, gif, webp,
  avif`. SVG is excluded by default since it can contain embedded
  scripts.
- New **"Allowed file extensions"** parameter — the list can be adjusted
  as needed (e.g. add `bmp`, or narrow the list further).
- The extension check is unified across all three places where the
  plugin processes images: the DOM branch, the regex fallback, and JS
  (`MutationObserver` for dynamically added images, from version 1.0.5).
- Verified with automated tests including edge cases (a dot inside the
  filename before the real extension, a query string after the
  extension).

## 1.0.5
- New feature: support for images added dynamically after the page loads
  (AJAX, infinite scroll, "load more" buttons, etc.).
- Added a `MutationObserver` that watches for DOM changes and wraps new
  `<img>` tags with a lightbox link using the same logic PHP previously
  applied only on first page render (respects `exclude_classes`,
  `show_caption`, `link_class`, `gallery_group`).
- Images that are already inside a link, or that have an excluded CSS
  class, are automatically skipped — the same rules as server-side
  processing.
- Verified with an automated test in a simulated DOM environment
  (jsdom): a dynamically added image, class-based exclusion, and
  protection against nested links.

## 1.0.4
- Change: removed the duplicate jQuery lightbox implementation.
  Previously there were two nearly identical versions of the JS engine
  (jQuery + vanilla), which increased the risk of the two drifting apart
  over future changes. As of this version there is a single vanilla JS
  engine that works equally reliably on Joomla 3, 4, and 5, regardless
  of whether jQuery is loaded on the page.
- As a result, the plugin file is about 15% shorter and easier to
  maintain.
- Functionality (navigation, keyboard, swipe, animations, grouping by
  `rel`) is unchanged — verified with tests.

## 1.0.3
- Fix: the `gallery_group` and `link_class` settings from the plugin's
  configuration were defined in the XML but weren't actually used in
  PHP/CSS/JS — the values were hardcoded as `autolightbox` /
  `autolightbox-gallery`. They're now genuinely applied across all three
  layers (generating the `<a>` link, the CSS selector, the JS selector).
- Improvement: the gallery now groups images by the `rel` attribute
  (the `gallery_group` value) — on a page with multiple articles (e.g. a
  category listing), clicking an image now only navigates among images
  from the same article, not across the whole page.

## 1.0.2
- Change: HTML is no longer parsed with regex, but with `DOMDocument` +
  `DOMXPath` — more reliable and more resilient to non-standard/nested
  HTML.
- Detecting existing links (to avoid nested `<a>` tags) is now handled
  directly via the DOM tree structure (`not(ancestor::a)`), instead of
  temporarily substituting text tokens as in 1.0.1.
- Correct UTF-8 handling (Slovak diacritics in alt text and filenames)
  via a meta-charset trick when loading into DOMDocument.
- The fallback regex mode is only used if `DOMDocument` isn't available
  on the server at all (missing PHP extension `php-xml`).
- Note: when serializing, DOMDocument percent-encodes diacritics in
  `src`/`href` attributes (e.g. "Amplitúdová" → "Amplit%C3%BAdov%C3%A1").
  This is standard, functionally equivalent behavior — both the browser
  and the web server decode it safely.

## 1.0.1
- Fix: images already wrapped in an `<a>` link in the article no longer
  get wrapped in another `<a class="autolightbox">`. This prevents
  invalid nested HTML (`<a><a>...</a></a>`) and unpredictable click
  behavior.

## 1.0.0
- First working version. Automatically adds a lightbox to all images in
  articles (`com_content`), with no action needed from the editor.
- Custom JS/CSS lightbox with no external libraries, compatible with
  Joomla 3.x.
- Joomla 4/5 support (`isClient('administrator')` fallback instead of
  `isAdmin()`, `CMSPlugin`/`JPlugin` fallback, vanilla-JS fallback if
  jQuery is missing).
- Open/close animations for the lightbox.
- Arrow-key and keyboard navigation (Esc, ←, →).
- Swipe gestures on mobile.
- Mobile-optimized display (full-screen image, smaller controls).
- "X / Y" counter in the lightbox.
- Parameter to exclude images by CSS class.
- Parameter to choose the caption type below the image (alt text /
  filename / none).
- Parameter to exclude by component (`com_content`, `com_contact`,
  `com_newsfeeds`).
- Parameter to exclude by page URL/path.
