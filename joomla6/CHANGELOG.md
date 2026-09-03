# Changelog — plg_content_fgautolightbox (Joomla 6 native build)

This changelog covers the **native Joomla 6 build** only (this `joomla6/`
folder). For the classic Joomla 3.10 build's history, see
[`../CHANGELOG.md`](../CHANGELOG.md) in the repository root.

## 2.3.9
JED Checker flagged all 9 PHP source files as missing a GPL license
notice (a JED submission requirement, separate from the manifest-level
`<license>` element added in 2.3.4).

- Added a standard Joomla-convention license header comment (package,
  subpackage, copyright, license) to the top of every PHP file:
  `script.php`, `services/provider.php`,
  `src/Extension/Fgautolightbox.php`, and all six files under
  `src/Support/`.
- Verified with `php -l` on all 9 files (a fresh PHP 8.3 install was
  needed in this environment first, since the sandbox this work was
  done in didn't have PHP installed at all - a different sandbox
  instance than earlier work in this project), plus a functional smoke
  test confirming every affected class still autoloads and behaves
  identically (`SrcSetResolver`, `ContentScopeResolver`,
  `ExtensionFilter` including its scheme check, `LinkAttributes`, and
  `HtmlProcessor`'s full image-wrapping pipeline). No functional code
  changed - only the new header comment was added.

## 2.3.8
Item 7 from a broader Grok AI review of smaller improvements ("Menšie,
ale oplatí sa to") - implemented carefully and tested thoroughly, since
it touches every direct child of `<body>` on the page, not just the
plugin's own markup.

- **The existing focus trap only handled keyboard Tab cycling, and only
  when focus was already inside the three buttons.** If focus ever
  ended up outside that set - most importantly, a screen reader user
  in "browse mode" (navigating the accessibility tree directly with
  arrow keys, independent of Tab order and DOM visual layering) - it
  could reach and activate background page content while the lightbox
  was technically still open.
- **Added `inert` on every direct `<body>` child except the overlay
  itself**, applied when the overlay opens and removed when it closes.
  Unlike Tab-cycling, `inert` makes background content genuinely
  unreachable through *any* interaction method - not just keyboard
  navigation - which is exactly the gap the existing trap had.
- The existing Tab-cycling trap is kept as-is, working alongside
  `inert` rather than being replaced by it - `inert` alone doesn't
  define a specific Tab order among the three buttons themselves, so
  the explicit cycling is still useful for well-defined keyboard
  behavior.
- Only elements that didn't already have `inert` are touched, and only
  those specific elements get it removed again on close - if some
  other script or plugin had already made a `<body>` child `inert` for
  its own reasons, this plugin doesn't interfere with it either way.
- **Assumes native browser support for the `inert` attribute** (well
  supported in all current major browsers) - no polyfill was added.
- Verified with automated tests: every direct `<body>` child except the
  overlay gets `inert` on open and has it correctly removed on close;
  an element that already had `inert` before the lightbox opened
  (simulating another script's own use of it) is left untouched in
  both directions; the overlay itself never receives `inert`; and the
  pre-existing Tab-cycling trap still functions exactly as before
  alongside the new mechanism. Full regression across the entire JS
  test suite confirms no other impact.

## 2.3.7
Item 8 from a broader Grok AI review of smaller improvements ("Menšie,
ale oplatí sa to") - real mobile usability bug.

- **Pinch-zoom on the displayed image was silently blocked.** The swipe
  handler's `touchmove` listener called `preventDefault()` whenever the
  first touch point's horizontal movement exceeded its vertical
  movement - but it never checked how many fingers were actually
  touching the screen. During a genuine two-finger pinch-zoom gesture,
  the *first* finger commonly drifts slightly more horizontally than
  vertically as a natural side effect of the pinch motion, which was
  enough to trigger `preventDefault()` and block the browser's native
  pinch-zoom entirely - on an image lightbox, exactly the one place
  visitors most want to be able to zoom in.
- Also missing: any minimum movement threshold before treating a touch
  as a horizontal swipe at all, meaning even a 1-2px hand-tremor jitter
  right at the start of any touch (including the very beginning of a
  vertical scroll) could prematurely trigger `preventDefault()`.
- **Fixed both issues:**
  1. `touchstart` and `touchmove` now check `e.touches.length` - if more
     than one finger is touching (a pinch-zoom gesture, whether it
     started that way or a second finger joined mid-gesture),
     `preventDefault()` is never called and the browser handles the
     gesture natively.
  2. A minimum threshold (`SWIPE_THRESHOLD = 10px`) is now required
     before a horizontal-dominant single-finger movement is treated as
     an intentional swipe worth intercepting.
- Verified with automated tests simulating real multi-touch event
  sequences: a genuine single-finger horizontal swipe above the
  threshold still correctly calls `preventDefault()`; small
  below-threshold jitter does not; a two-finger pinch present from the
  very start of the gesture never calls `preventDefault()`, even with
  a horizontally-drifting first finger; a second finger joining
  *mid-gesture* (starting as a single-finger touch, transitioning to
  pinch) correctly stops any further `preventDefault()` calls; a pure
  vertical single-finger scroll is never blocked; and - a functional
  regression check - an actual completed swipe gesture (past the
  existing 50px navigation threshold) still correctly advances to the
  next image exactly as before. Full regression across the entire JS
  test suite confirms no other impact.

## 2.3.6
Items 9 and 11 from a broader Grok AI review of smaller improvements
("Menšie, ale oplatí sa to"). Item 10 (language file prefix) skipped
per explicit decision - the current `en-GB.plg_..`/`sk-SK.plg_...`
naming already works correctly under J4+, so renaming would be pure
risk for zero functional gain.

**9. Overlay hidden when printing**
- If a visitor printed the page (Ctrl+P) while the lightbox happened to
  be open, the full-screen dark overlay would print too - a large,
  useless dark panel on top of the actual page content.
- Added `@media print { #alb-overlay { display:none !important; } }` -
  the overlay is completely hidden in print output, regardless of
  whether it was open on screen.
- Verified as syntactically valid CSS via a real parser (`tinycss2`,
  zero errors, 30 top-level rules total). Actual print-preview
  rendering isn't testable outside a real browser from this
  environment.

**11. `joomla.asset.json` workaround - documented as a TODO, not
reverted**
- 2.0.1 replaced `WebAssetManager::addRegistryFile()` +
  `useStyle()`/`useScript()` with the more direct
  `registerAndUseStyle()`/`registerAndUseScript()`, after the registry-
  file approach was found to silently fail to render `<link>`/
  `<script>` tags on a live Joomla 6.1.2 site (see the 2.0.1 entry
  below for the original write-up).
- This is being left exactly as-is for now - the direct API call is
  proven working end-to-end on a live site, and reverting to the
  registry-file approach without being able to re-test it would be a
  pure regression risk for no benefit.
- **Recorded here as an open TODO**: re-verify whether
  `addRegistryFile()` + `useStyle()`/`useScript()` behaves correctly on
  a more recent Joomla 6.x release (in case the underlying issue was
  specific to 6.1.2 and has since been fixed upstream) - only worth
  reconsidering if there's a concrete reason to prefer the registry-
  file approach again (e.g. wanting Joomla's own asset dependency
  graph resolution), not for its own sake.

## 2.3.5
Items 4 and 6 from a broader Grok AI review of smaller improvements
("Menšie, ale oplatí sa to"). Item 5 (figcaption/title) intentionally
skipped for now, pending further discussion.

**4. Screen readers now announce caption/counter changes during
navigation**
- Navigating between images (prev/next, or arrow keys) previously
  updated the caption text and the "2 / 5" counter silently - a
  screen reader user got no feedback that anything had changed unless
  they re-read the whole overlay manually.
- Added `aria-live="polite"` and `aria-atomic="true"` to both
  `#alb-caption` and `#alb-counter`. `aria-live="polite"` means the
  update is announced without interrupting whatever the screen reader
  is currently saying; `aria-atomic="true"` ensures the *entire*
  updated text is announced as one unit (e.g. the full "2 / 5", not
  just a changed digit).

**6. `decoding="async"` on the lightbox image**
- Added to `#alb-img`. A small, low-risk win: lets the browser decode
  the image off the main thread instead of potentially blocking
  rendering, particularly relevant when switching quickly between
  images in a gallery.

Verified with automated tests: both new attributes are present on the
overlay elements, and - importantly - caption/counter content still
updates correctly on open and on navigation (a functional regression
check, since this touches the same HTML template used throughout the
overlay). Full regression across the entire JS test suite confirms no
other impact.

## 2.3.4
First three items from a broader Grok AI review of smaller
improvements ("Menšie, ale oplatí sa to").

**1. Manifest metadata for JED compliance**
- Added `authorEmail`, `authorUrl`, `copyright`, `license`, and
  `creationDate` to the manifest - previously missing entirely (the
  classic build never had them either). Joomla Extensions Directory
  listings expect these, and JED requires GPL licensing to be
  explicitly stated.
- A `LICENSE.txt` (standard GPL-2.0 text) was added *inside* the
  `joomla6/` folder itself and registered in the manifest's `<files>`
  section, so it actually ships with the installed plugin - not just
  sitting in the repository, which an end user who only downloads the
  release ZIP would never see otherwise.

**2. Cache-busting moved from `filemtime()` to the plugin version**
- On load-balanced hosting (multiple servers behind one site), the
  same static file can have a different `mtime` on each server -
  slightly different deployment timing, filesystem sync artifacts -
  which meant the CSS/JS cache-busting query parameter could differ
  between requests hitting different servers. Replaced with a fixed
  `VERSION` class constant instead, identical everywhere regardless of
  server filesystem state. This constant must be kept in sync with the
  manifest's `<version>` on every release going forward - now part of
  the release checklist.
- Verified with automated tests: the cache-bust value in both the CSS
  and JS URLs matches the plugin version exactly, and both use the
  *same* value (previously two independent `filemtime()` calls could
  theoretically produce different values for CSS vs. JS if one file
  was touched slightly after the other during deployment).

**3. `prefers-reduced-motion` support**
- Visitors with reduced-motion enabled (an OS/browser accessibility
  setting for motion sensitivity) previously got the full animated
  fade-in and scale-up transition on every lightbox open/close, with
  no way to opt out.
- Added `@media (prefers-reduced-motion: reduce)` disabling all
  `transition` properties on the overlay, wrap, image, caption, and
  the three buttons, and fixing the wrap's scale transform at its
  final value - functionally nothing changes (the same show/hide
  states still apply), only the animated interpolation is skipped.
  Verified as syntactically valid CSS via a real parser (`tinycss2`,
  zero errors); actual browser-level `prefers-reduced-motion`
  evaluation isn't testable outside a real browser from this
  environment.

## 2.3.3
Response to a Grok AI code review (P1, real security hardening).

- **An empty `allowed_extensions` setting meant "allow everything"**,
  not "use the default list". If an administrator cleared the field
  entirely (accidentally or otherwise), `ExtensionFilter::isAllowed()`
  returned `true` unconditionally - including for `javascript:` URIs,
  `data:text/html,...` payloads, and `.svg` files (which are excluded
  from the *default* list specifically because they can contain
  embedded scripts). Since `pickBestSrc()` picks its value from
  content-controlled attributes (`src`, `data-full`, `srcset`, etc.),
  this was a real, if narrow, risk surface.
- **Fixed in two independent, complementary ways:**
  1. `ExtensionFilter::fromCsv('')` now falls back to the same safe
     default list used in the manifest (`jpg,jpeg,png,gif,webp,avif`)
     instead of returning "no restriction". Direct construction with an
     empty array (bypassing `fromCsv()`) now fails closed - denies
     everything - as the safest behavior for a class that could be
     reused elsewhere in the future without going through the CSV
     parser.
  2. Added an independent **URL scheme whitelist**, checked before the
     extension check and regardless of the `allowed_extensions` setting:
     only `http:`/`https:` (or no scheme at all - i.e. a normal relative
     path like `/images/photo.jpg`) are accepted; anything else
     (`javascript:`, `data:`, `vbscript:`, `ftp:`, etc.) is rejected
     outright, even if it happened to have an otherwise-allowed file
     extension.
  Both fixes were implemented identically in PHP
  (`ExtensionFilter`) and JS (`hasAllowedExtension()`/new
  `hasSafeScheme()`), since JS independently resolves sources for
  dynamically-added images.
- Verified with automated tests: the exact reported scenarios
  (`javascript:alert(1)`, `data:text/html,...`, `.svg` all correctly
  rejected with an empty extensions list, while `.jpg`/`.png` remain
  allowed via the safe default), the scheme check applying
  independently of the extensions setting (`data:image/svg+xml;...`
  rejected even with a normal non-empty allowlist), legitimate
  `http:`/`https:`/relative URLs continuing to work exactly as before,
  and the fail-closed behavior of direct `ExtensionFilter`
  construction with an empty array. Full regression across the entire
  test suite (PHP and JS) confirms no other impact - one existing test
  that specifically asserted the old "empty list = unrestricted"
  behavior was updated to reflect the new, safer behavior.

## 2.3.2
**Real bug, reported directly from the live site right after 2.3.1**:
the "Watch for dynamically added images" dropdown still showed "Yes
(default)" even though 2.3.0 changed the actual default to off.

- **Root cause**: the option label text is a static, hand-written
  language string, not something Joomla derives automatically from the
  field's `default` attribute. When `watch_dynamic`'s default was
  changed from `1` to `0` in 2.3.0, the manifest's `default` attribute
  and the `<option>` order were updated correctly, but the *visible
  label wording* ("(default)") was left attached to "Yes" instead of
  being moved to "No" - purely a leftover from the earlier default,
  never corrected.
- **Fixed** by moving "(default)" to the "No" option in both languages.
  Cross-checked every other list-type field with a similar "(default)"
  label (`caption_mobile`, `prefer_srcset`, `show_navigation`,
  `preload_adjacent`) against its actual XML `default` attribute -
  confirmed `watch_dynamic` was the only mismatch, since it's the only
  one of these whose default value has ever changed after being
  shipped.
- **Separate, important note for anyone upgrading from before 2.3.0**:
  if a site had already saved this plugin's settings prior to
  upgrading (even just once), Joomla keeps that saved value in the
  database - the new XML default only applies to installs that have
  never explicitly saved a value. Changing the manifest's default does
  not retroactively change an already-stored "on" setting; if dynamic
  watching should actually be turned off going forward, the dropdown
  needs to be explicitly switched and saved.

## 2.3.1
**Real regression, reported directly from a live site**: since 2.2.4,
the plugin's admin settings screen displayed with a visually broken
field layout (fields packed inconsistently multiple-per-row instead of
one clean field per row), and a third-party plugin's tab injection
(the Params Backup plugin, which normally adds a "Backup" tab
alongside the plugin's own settings) stopped rendering as a proper tab
and appeared stacked below instead. Confirmed via a direct side-by-side
comparison on the same site, same browser window: broken on 2.3.0,
correct after downgrading to 2.2.3.

- **Likely root cause**: the `exclude_classes` field's description
  (added in 2.2.4, to explain the new ancestor-checking behavior)
  contained literal HTML-tag-like text - `<figure>`, `<div>`, and
  `<img>` - written directly into the language string as plain-text
  examples. If Joomla's admin UI renders field hints/tooltips with HTML
  interpretation enabled (common, since Joomla hints support some
  formatting), these would be parsed as real, never-properly-closed
  HTML elements injected into the admin page itself - corrupting the
  surrounding DOM structure. That would plausibly explain both symptoms
  at once: broken field-row layout (elements reflowing around the
  stray unclosed tags) and a third-party plugin's tab-rendering logic
  failing to find/attach to the expected DOM structure.
- **Fixed** by rewriting the description to avoid literal angle-bracket
  tag syntax entirely (e.g. "a figure or div" instead of "a `<figure>`
  or `<div>`"), in both English and Slovak. A full scan of every other
  field label and description string in both language files, plus the
  plugin's top-level XML description, confirmed this was the only
  occurrence of this pattern anywhere in the plugin.
- **Honesty about confidence**: this is a strong hypothesis based on
  how Joomla's admin field-hint rendering is understood to work, not a
  verified root cause - it could not be reproduced or confirmed on a
  live Joomla install from this environment. If the issue persists
  after upgrading to this version, further investigation will be
  needed with actual browser DevTools inspection on the live site.

## 2.3.0
Response to a Grok AI code review (P2, real ongoing performance cost -
**default behavior change, please read**).

**⚠ If your site relies on images being added to the page dynamically
after it loads (AJAX, infinite scroll, sliders, lazy-loaded galleries),
check that "Watch for dynamically added images" is explicitly enabled
after upgrading to this version - it is no longer on by default.**

- **`watch_dynamic` now defaults to off**, not on. A `MutationObserver`
  watching `childList + subtree + attributes` across the whole page
  isn't free - on pages with a lot of unrelated DOM activity
  (animations, chat widgets, cookie consent banners), it adds a small
  but real ongoing cost, for a feature most ordinary articles/blogs
  with no dynamically-loaded images never actually use. Static images
  (present when the page loads, handled entirely server-side by PHP)
  are completely unaffected by this setting either way.
- Considered and deliberately **not** implemented: automatically
  turning `watch_dynamic` on whenever `watch_container` has a value
  set. Joomla plugin parameters can't reliably distinguish "the
  administrator explicitly set this to 0" from "never touched, this is
  just the default" - any auto-on logic built on that distinction would
  be fragile. A plain default change is simpler and more predictable.
- Field descriptions for both `watch_dynamic` and `watch_container`
  updated in both languages to cross-reference each other and explain
  when to turn dynamic watching on.
- Changed in all three places the default is read: the manifest field
  default, the PHP fallback in both places `watch_dynamic` is read, and
  the JS `DEFAULTS` object (the safety-net fallback used if the config
  sent from PHP is ever missing this key for any reason).
- Explicit opt-in (`watch_dynamic=1`) continues to work exactly as
  before - verified with automated tests confirming the setting's
  *presence and effect*, not just its default value, is unchanged.
- Verified with automated tests: with no explicit setting, dynamically
  added images are no longer picked up and the conditional-asset-
  loading optimization from 2.0.5 no longer loads assets for image-free
  content by default either (since that logic also depends on
  `watch_dynamic`); explicitly enabling the setting restores the exact
  previous behavior in both respects. A significant number of existing
  tests across the whole suite (PHP and JS) implicitly relied on the
  old default to exercise `MutationObserver`-dependent features
  (dynamic image discovery, `watch_container` scoping, editor-link
  upgrades on dynamic content, and more) - all of these were updated to
  explicitly enable `watch_dynamic` in their test setup, since they are
  specifically testing functionality that requires it; this was
  expected and is not a functional regression, just tests catching up
  to the new default they no longer get for free. Full regression
  confirms zero unexpected failures across the entire test suite once
  updated.

## 2.2.4
Response to a Grok AI code review (P1, real editor UX expectation):

- **`exclude_classes` only checked the `<img>` element's own `class`
  attribute.** A content editor wrapping a whole block - e.g.
  `<figure class="no-lightbox"><img></figure>` or
  `<div class="no-lightbox"><img></div>` - would naturally expect that
  to exclude the image, but the image inside was still processed,
  since the wrapping element's class was never checked.
- **Fixed by walking up to 4 ancestor levels** from the image, checking
  each ancestor's own class list too, not just the image's. Four
  levels is a deliberate compromise - enough for common wrapping
  patterns (`<figure>`, a `<div>`, or a `<p>`, even a couple of levels
  nested), without walking all the way up to the article root, where a
  coincidental class match unrelated to the administrator's intent
  would become a real risk.
- Implemented identically in PHP (`HtmlProcessor::hasExcludedClass()`)
  and JS (new `hasExcludedClass()` helper, replacing the previous
  image-only inline check in `wrapNewImage()`).
- **A real gap was found and fixed while implementing this**: the
  editor-link-upgrade feature (2.1.8, point 1's Grok fix) already
  checked `exclude_classes` on the PHP side, but its JS equivalent
  (`tryUpgradeForeignLink()`, for dynamically-added content) never
  checked it at all - a TinyMCE/JCE-style link inside an excluded
  block added via AJAX would have been upgraded anyway. Now consistent
  between PHP and JS.
- Field description updated in both languages to explain that wrapping
  elements are checked too, not just the image itself.
- Verified with 12 tests across PHP and JS: the exact reported scenario
  (`<div class="no-lightbox">`), `<figure>`, a two-level-deep nesting
  case, the class-directly-on-`<img>` case (still working exactly as
  before), an unrelated class correctly *not* excluding, multiple
  images on the page where only the wrapped one is excluded, an empty
  `exclude_classes` setting behaving exactly as before, and the newly
  fixed foreign-link-upgrade consistency case (both for a plain
  dynamically-added image and for the TinyMCE/JCE upgrade path). Full
  regression across all previously available tests confirms no other
  impact.

## 2.2.3
Response to a Grok AI code review (P1, real false-positive risk),
revisiting a point ChatGPT had also raised earlier (where the decision
at the time was to leave it as pure substring matching, since it wasn't
a reported bug) - now implemented with a lower-risk approach than the
full mode-picker considered back then.

- **`exclude_urls` matched as a substring anywhere**, meaning `/12`
  would also match `/120`, `/1129`, or a query string like `?id=120` -
  and `task=edit` could match unrelated URLs containing that exact
  text as part of a longer word (e.g. `mytask=editorial`).
- **Fixed with word-boundary-aware matching as the new default**: a
  pattern only matches when it isn't touching another alphanumeric
  character on either side - so `/12` now matches `/12` and
  `/path/12`, but not `/120` or `/1129`. The boundary check only
  applies on the side(s) where the pattern's own edge character is
  itself alphanumeric - a pattern already starting or ending with a
  delimiter like `/` doesn't need a redundant one added, which is what
  correctly allows `/12` to still match at the end of a longer path
  like `/some/article/12`.
- **A real bug in this exact logic was found and fixed while testing**:
  the first implementation attempt incorrectly required a boundary
  character immediately before the *entire* pattern text even when the
  pattern itself already started with a delimiter (`/`), which broke
  matching the deliberately-supported case above - caught by the test
  suite before release, not after.
- **A `*` wildcard is available as an explicit opt-in** for cases where
  broader, deliberate substring matching is wanted (e.g. `/kontakt*`
  also matches `/kontakt-us` or `/kontakty`). Full regex was
  deliberately not added, to keep the setting simple and safe for a
  non-technical administrator - matching Grok's own stated preference.
- Field description updated in both languages to explain the new
  default and the wildcard syntax.
- Verified with 18 automated tests covering the exact reported
  scenarios (`/12` vs. `/120`/`/1129`, `task=edit` vs.
  `mytask=editorial`), the wildcard opt-in, query-string ID matching,
  case-insensitivity (unchanged from before), and an end-to-end test
  through the full `onContentPrepare` flow confirming a page that was
  incorrectly excluded before (`/120` matching `exclude_urls: /12`) is
  now correctly processed. Full regression across all previously
  available tests confirms no other impact.

## 2.2.2
Response to a Grok AI code review (P1, real bug on iOS Safari):

- **`document.body.style.overflow = "hidden"` alone is well known to not
  reliably prevent background scrolling on iOS Safari** - iOS has its
  own viewport scrolling model that often ignores this rule entirely,
  letting the page "bleed through" underneath the open lightbox.
- Replaced with the standard, widely-used technique: also lock
  `<html>`'s overflow, capture the current scroll position
  (`window.scrollY`), and temporarily switch `<body>` to
  `position: fixed` with `top` offset by that scroll position (plus
  `width: 100%` to prevent the fixed body from collapsing to content
  width). On close, all of this is reverted and the scroll position is
  restored exactly via `window.scrollTo()` - `position: fixed` removes
  the page from the normal scroll flow, so the browser doesn't
  remember the scroll position on its own; it has to be restored
  manually.
- Extends the "restore the previous value, don't clobber it" principle
  from 2.1.5 (originally just `body.overflow`) to all five properties
  now touched (`html.overflow`, `body.overflow`, `body.position`,
  `body.top`, `body.width`) - a template or another plugin with its own
  values on any of these is still respected exactly as before.
- Verified with automated tests: the full lock/unlock cycle sets and
  restores all five properties correctly, the captured scroll position
  is applied as the `body.top` offset while open and restored via
  `scrollTo()` on close, and - explicitly reproducing 2.1.5's original
  scenario at larger scope - pre-existing template values on multiple
  properties simultaneously (not just `overflow`) are confirmed
  preserved exactly, not reset to empty. Full regression across all
  previously available tests (PHP and JS combined) confirms no other
  impact.

## 2.2.1
Response to a Grok AI code review (P1, real bugs):

**A broken image stayed "loading" forever**
- No `onerror` handler existed - if an image URL 404'd or otherwise
  failed to load, `#alb-img` stayed at `opacity:0.25` (the "loading"
  state) indefinitely, with no feedback to the visitor.
- Fixed with a proper `onerror` handler: the broken-image icon is
  hidden, and a new, translatable error message ("Failed to load
  image" / "Obrázok sa nepodarilo načítať") is shown in a new
  `#alb-error` element. The error state is cleared automatically at
  the start of every `show()` call, so navigating away from a broken
  image doesn't leave the message stuck on-screen.

**Race condition on rapid navigation**
- Reported scenario: pressing next/prev rapidly could leave a stale
  `onload`/`onerror` handler from an earlier, superseded `show()` call
  able to affect the *current* image's loading state.
- Fixed with a generation counter: each `show()` call increments a
  counter and captures its own value in a closure; the `onload`/
  `onerror` handlers check that value against the current counter
  before doing anything, so a handler from an outdated navigation
  step is a guaranteed no-op regardless of when the browser actually
  fires it.
- New `error` label added to the existing translatable labels
  mechanism (2.1.3), following the same pattern - resolved via
  `Text::_()` on the PHP side, merged key-by-key on the JS side with
  an English fallback.

Verified with automated tests: a successful load still clears the
loading state exactly as before, a failed load shows the error message
and clears loading, navigating to another image clears any lingering
error state, and - reproducing the race directly - a captured stale
`onload` handler manually invoked *after* a subsequent `show()` call is
confirmed to have zero effect on the current (newer) loading state.
Full regression across all previously available tests (PHP and JS
combined) confirms no other impact.

## 2.2.0
Two items from a Grok AI code review, bundled into a minor version
(new setting + a real behavior change to the loading pipeline):

**5. Lightbox now lets the browser pick the right image size, instead
of always fetching the largest `srcset` candidate (P1, real mobile
data/performance impact)**
- Previously, `parseLargestFromSrcset()` always resolved to a single,
  fixed URL - the largest candidate in the `srcset`. On a phone with a
  ~390px viewport, this meant downloading a 2400px JPEG when a much
  smaller version would have looked identical - and with
  `preload_adjacent` enabled (the default), the *next* and *previous*
  images got the same oversized treatment too, tripling the
  unnecessary data for a single lightbox open.
- Fixed by passing the entire `srcset` through to the lightbox's
  `<img>` element (as `data-alb-srcset`, then applied as
  `img.srcset` + `img.sizes="100vw"`) instead of reducing it to one
  URL - the browser's own responsive-image algorithm now picks the
  right size for the actual viewport and pixel density. `data-full`/
  `data-highres` remain a deliberate "always show the true original"
  opt-in that bypasses this entirely, exactly as before.
- **Preload was fixed to match** - without this, preloading the
  neighboring images would have kept fetching the oversized fallback
  URL regardless, doubling back the exact problem this fix addresses.
  Preload `Image()` objects now also use `srcset`+`sizes` when
  available.
- Verified with automated tests: the full `srcset` correctly reaches
  the lightbox `<img>` and switches correctly when navigating between
  images with and without a `srcset`, `data-full` correctly suppresses
  it, and preloaded neighbor images are confirmed to use `srcset`+
  `sizes` rather than the raw largest URL. (Whether real browsers pick
  a meaningfully smaller file in practice isn't verifiable outside a
  real device/network - `sizes="100vw"` is a standard, slightly
  conservative simplification, since the lightbox's CSS actually caps
  the image at `max-width: 88vw` on desktop, not a full 100vw.)

**6. New "Prefer srcset over data-src" setting - default off, matching
existing behavior exactly (P1, but genuinely ambiguous - resolved as a
setting rather than guessed)**
- `data-src` is used for opposite purposes by different lazy-load
  libraries: some hold the real full-size image there (the classic
  build's proven, production-tested convention this plugin has
  followed since day one), others use it only for a small placeholder
  while genuine full-size versions live in `srcset`. Reordering the
  priority outright risks trading one real bug for its mirror image on
  a differently-configured site.
- Added `prefer_srcset` (default off/`0`): when enabled, priority
  becomes `data-full`/`data-highres` > `srcset` > `data-src` > `src`
  instead of the default `data-full`/`data-highres` > `data-src` >
  `srcset` > `src`. `data-full`/`data-highres` always wins regardless
  of this setting.
- Implemented in both `SrcSetResolver::pickBestSrc()` (new trailing
  `bool $preferSrcset = false` parameter - deliberately placed last to
  avoid breaking any existing positional call, including every prior
  test) and its JS equivalent, driven by the same setting sent through
  the existing inline config.
- Verified with automated tests across both PHP and JS: default
  behavior is unchanged (confirmed identical to pre-2.2.0 output),
  enabling the setting flips the priority correctly, `data-full` still
  wins regardless of the setting either way, and the setting correctly
  falls through to `data-src` then `src` when no `srcset` is present.
  Full regression across all previously available tests (dozens, PHP
  and JS combined) confirms no other impact, including full
  interoperability with 2.1.8's `data-alb-group`/editor-link-upgrade
  work and this same release's own `data-alb-srcset` addition.

## 2.1.8
Two items from a Grok AI code review:

**1. Images already wrapped in an editor-created `<a>` are no longer
skipped (P1, common real-world case)**
- `//img[not(ancestor::a)]` deliberately skips images already inside a
  link, to avoid producing invalid nested `<a><a>...</a></a>` markup.
  But TinyMCE and JCE both commonly insert exactly
  `<a href="full.jpg"><img src="thumb.jpg"></a>` via their own "link to
  full-size image" option - on real articles, this was reportedly the
  most common reason the lightbox "just didn't work" on a particular
  image, since that image was silently skipped entirely.
- Fixed with a second pass: for images found *inside* an `<a>`, if that
  anchor's `href` points to an allowed image extension, the *existing*
  link is upgraded in place (class + grouping + accessible alt added)
  instead of creating a nested wrapper. A link to something else (PDF,
  another page) is left completely untouched. Existing CSS classes are
  extended, not replaced; an existing `title` is preserved, not
  overwritten. Implemented identically in the PHP DOM branch and in JS
  (`MutationObserver`, for dynamically added content following the same
  editor pattern).
- Verified with 16 tests across PHP and JS: the exact TinyMCE/JCE
  pattern, a PDF link left untouched, existing classes/title preserved,
  `exclude_classes` still respected, and correct gallery grouping across
  multiple upgraded links in the same article.

**2. Gallery grouping moved from `rel` to `data-alb-group` (P1,
semantic correctness)**
- `rel="autolightbox-gallery:42"` is not a valid HTML5 link type
  (`nofollow`, `noopener`, `license`, etc. are) - real validators flag
  it, and it's not what `rel` is for. Custom application data belongs in
  a `data-*` attribute instead.
- Moved gallery-grouping data to `data-alb-group` throughout (PHP and
  JS); `rel` is no longer touched or read by the plugin at all.
- **A follow-on improvement fell out of this naturally**: the guard from
  point 1 above that skipped upgrading a link if it already had *any*
  `rel` value (added specifically to avoid clobbering an editor's own
  `rel="nofollow"` etc.) no longer serves a purpose, since we don't
  write into `rel` anymore. Removed in both PHP and JS - a link with
  `rel="nofollow"` (or any other real `rel` value) now gets upgraded
  too, with its own `rel` left completely untouched alongside the new
  `data-alb-group`.
- **A real inconsistency was found and fixed while testing**: the guard
  removal was initially only applied to the JS side, not PHP - caught
  immediately by the (now-failing) test suite and fixed for both.
- Verified with automated tests confirming `rel` is never written or
  read anywhere in the plugin anymore, an existing `rel="nofollow"` is
  preserved exactly while the link still gets `data-alb-group`, and
  click-driven gallery grouping still works correctly via the new
  attribute. Full regression across all 65 available tests (34 isolated
  Support-class tests, plus the point-1 upgrade tests, foreign-link
  tests, and stale-wrapper tests, each updated where they specifically
  asserted the old `rel`-based behavior) confirms no other impact.

**Heads-up on content caching:** if Joomla's content cache is enabled
and holds articles rendered by an older version of this plugin, that
stale cached HTML will still have the old `rel`-based grouping until the
cache is cleared or naturally expires - it degrades gracefully (each
such image just behaves as its own single-item gallery in the meantime)
rather than breaking anything.

## 2.1.7
Three small items from a ChatGPT code review, bundled into one release:

**Comment accuracy (no functional change)**
- Corrected an overstated comment in the constructor. It previously read
  as if constructor DI's testability benefit applied to the plugin
  class itself. In reality, `Fgautolightbox` still constructs
  `SrcSetResolver`/`ContentScopeResolver`/`HtmlProcessor` itself via
  `new` rather than receiving them injected - the isolated-testability
  benefit is real, but it applies specifically to those Support classes
  (zero Joomla API surface), not to the plugin's own DI story. Also
  removed a stray reference to a `tests/` directory that doesn't
  actually exist in the shipped package.

**`JSON_THROW_ON_ERROR` with a graceful fallback (P3, defensive
hardening)**
- The inline config `json_encode()` call now also passes
  `JSON_THROW_ON_ERROR`, wrapped in a `try`/`catch`. `$config` only ever
  contains simple strings/booleans/arrays built from Joomla settings, so
  an encoding failure is practically impossible - this is a pure
  defensive layer. On the (extremely unlikely) failure, an empty `{}`
  object is used instead of rendering broken/truncated JavaScript - the
  JS engine's own built-in defaults already handle a missing or empty
  config gracefully, so the lightbox keeps working with default
  settings rather than the page breaking.

**Cheap early-exit before `DOMDocument` (P2, measured performance win)**
- `HtmlProcessor::wrapImages()` now checks `stripos($html, '<img') ===
  false` and returns the content unchanged *before* constructing
  `DOMDocument`/`DOMXPath` - by far the most expensive operation the
  plugin performs. Pages or articles with zero images (plain text
  content) now skip DOM parsing entirely. `<picture>` elements are
  safely covered too, since a `<picture>` is only valid HTML5 with an
  `<img>` as a direct child - there's no case where an image is present
  via `<picture>` alone without an `<img>` tag.

Verified with automated tests: the comment change was confirmed to only
touch comment lines (diffed against the previous version); a genuinely
invalid UTF-8 payload correctly triggers `JsonException`, is caught, and
falls back to a valid, decodable `{}`; and the early-exit correctly
returns text-only content completely unchanged (including a
case-insensitive check for `<IMG>`), while still processing ordinary
images normally - measured at roughly **30× faster** on image-free
content (5.48ms vs. 165.53ms across 200 iterations of a large text-only
block). Full regression across all 34 isolated Support-class tests and
the i18n/labels integration test confirms no other impact.

## 2.1.6
Response to two related ChatGPT code review points about manifest/update
server safety (P1):

- **New installer preflight check** (`script.php`, declared via
  `<scriptfile>` in the manifest). Previously, installing this
  Joomla-6-only build on an incompatible environment (Joomla 5, or PHP
  below 8.3) would silently succeed at the install step and only fail
  later with a confusing fatal error the first time the plugin actually
  ran. It now checks the PHP version and `JVERSION` in `preflight()`
  and aborts the installation cleanly with a proper error message if
  either requirement isn't met - before any files are even copied into
  place.
- **`updates.xml` now also declares `<php_minimum>8.3</php_minimum>`**
  alongside the existing `<targetplatform>` restriction. This is
  Joomla's own, officially documented mechanism (used by Joomla core's
  own update stream) for the update client itself to filter out
  incompatible offers - so "Find Updates" won't offer this build to a
  site running an unsupported PHP version, on top of the existing
  Joomla-version restriction.
- Verified with automated tests: `preflight()` returns `true` on a
  simulated compatible environment (current PHP 8.3.6, simulated
  Joomla 6.1.2), returns `false` and enqueues a proper `error`-type
  message on a simulated incompatible Joomla version (5.4.3), the
  version-comparison logic itself is correct at the boundary (exactly
  8.3.0 passes, 8.2.9 doesn't), and the remaining
  `InstallerScriptInterface` methods (`install`/`update`/`uninstall`/
  `postflight`) are present and return `true` as expected. Full
  regression across the body-overflow test and all 34 isolated
  Support-class tests confirms no other impact.

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
