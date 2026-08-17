<p align="center">
  <img src="assets/logo.png" alt="FG AutoLightbox" width="128">
</p>

# FG AutoLightbox plugin for Joomla

<p align="center">
  <img src="https://img.shields.io/github/v/release/ferino75/plg_content_fgautolightbox?color=FF6B4A&label=release" alt="Latest release">
  <img src="https://img.shields.io/badge/Joomla-3.10%20%7C%204%20%7C%205%20%7C%206-5091CD.svg" alt="Joomla">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4.svg" alt="PHP">
  <img src="https://img.shields.io/badge/license-GPL--2.0-green.svg" alt="License">
  <img src="https://img.shields.io/github/downloads/ferino75/plg_content_fgautolightbox/total?color=FF6B4A" alt="Downloads">
</p>

A Joomla content plugin that automatically turns every image in your
articles into a lightbox gallery — with **no work required from your
content editors**. They keep inserting images exactly as they always
have; the plugin does the rest.

No jQuery, no external lightbox library, no build step.

## Why

Most "auto lightbox" plugins either stopped supporting Joomla 3, moved
the useful bits behind a paid tier, or require editors to learn a tag
syntax like `{gallery}folder{/gallery}`. This one was built to fill that
gap: editors change nothing, administrators install one plugin.

## Features

- **Zero editor workflow change** — images inserted normally through
  TinyMCE/JCE are picked up automatically
- **Wide Joomla support** — one package works on Joomla 3.10, 4, 5 and 6
- **No dependencies** — self-contained vanilla JS/CSS, works whether or
  not jQuery is present
- Keyboard navigation (`Esc`, `←`, `→`) and touch swipe gestures
- Open/close animations, neighbouring-image preloading, `X / Y` counter
- Per-article grouping — on a category page, arrows navigate only within
  the article you clicked in
- Handles images added after page load (AJAX, infinite scroll) via
  `MutationObserver` — with an optional CSS selector to scope watching
  to just the content area, for better performance on very dynamic pages
- Lazy-load aware — prefers `data-src` over `src` when present
- **Responsive images done right** — picks the best available resolution
  in order: `data-full`/`data-highres` (explicit override) → `data-src` →
  the largest candidate in `srcset` → plain `src`. Works with `<picture>`
  elements too (scans every `<source>`), without breaking the browser's
  native responsive/format switching for the page's normal display
- Extensible beyond the built-in components (`com_content`, `com_contact`,
  `com_newsfeeds`) — add K2, Zoo, or any custom component via a setting
- Accessible: `role="dialog"`, `aria-modal`, real `<button>` controls with
  `aria-label`, focus trap, and screen-reader alt text that stays present
  even when visible captions are turned off

## Installation

1. Download the latest `plg_content_fgautolightbox_vX.Y.Z.zip` from
   [Releases](https://github.com/ferino75/plg_content_fgautolightbox/releases)
2. In Joomla admin: **System → Install → Extensions**, upload the ZIP
3. Go to **System → Plugins**, search for `AutoLightbox`, and enable
   **Content - FG AutoLightbox**

That's it — existing articles work immediately, no content changes needed.

## Configuration

All settings are optional; the defaults are sensible for a typical site.

| Setting | Default | What it does |
|---|---|---|
| Gallery group name | `autolightbox-gallery` | Internal identifier used to group images for arrow navigation |
| Extra link CSS class | `autolightbox` | Optional extra class on the generated link, for your own styling |
| Exclude CSS classes | *(empty)* | Comma-separated list; images carrying any of these classes are skipped (e.g. `logo, banner, no-lightbox`) |
| Caption under image | Alt text | Alt text / file name / none |
| Show caption on mobile too | No | Captions are hidden on small screens by default so the image gets maximum space |
| Exclude components | *(empty)* | Comma-separated component names to skip (e.g. `com_contact`) |
| Extra allowed contexts | *(empty)* | Comma-separated contexts to process beyond the built-in ones — exact (`com_k2.item`) or a whole component via wildcard (`com_k2.*` or just `com_k2`); useful for K2, Zoo, custom components |
| Exclude pages/URLs | *(empty)* | Comma-separated substrings matched against the page URL |
| Allowed file extensions | `jpg,jpeg,png,gif,webp,avif` | Only these get a lightbox. SVG is excluded by default since it can carry embedded scripts |
| Watch for dynamically added images | Yes | Enables the `MutationObserver`; disable on pages with a very busy DOM |
| Watch container (CSS selector) | *(empty)* | Scope the `MutationObserver` to matching container(s) (e.g. `.item-page, .blog`) instead of the whole page, for better performance. Falls back to the whole page if the selector matches nothing |

## Theming

The lightbox styles are driven by CSS custom properties, so you can
restyle it from your template's CSS without touching the plugin:

```css
#alb-overlay {
    --alb-z-index: 999999999;   /* if it clashes with a cookie bar */
    --alb-overlay-bg: rgba(20, 20, 40, 0.95);
    --alb-text-color: #fff;
    --alb-caption-color: #eee;
    --alb-caption-size: 13px;
    --alb-nav-size-mobile: 36px;
    --alb-nav-size-desktop: 56px;
    --alb-counter-bg: rgba(0, 0, 0, 0.5);
    --alb-counter-color: #ccc;
}
```

## Compatibility notes

The plugin ships a single codebase that adapts at load time:

- On **Joomla 4/5/6** it registers via the modern `SubscriberInterface` /
  `getSubscribedEvents()` API
- On **Joomla 3.10** it falls back to the classic positional
  `onContentPrepare()` signature

Detection is automatic (`interface_exists()`), so the same ZIP installs
everywhere. Tested in production on Joomla 3.10 and Joomla 6.1.2.

## Conflicts with other lightboxes

If clicking an image opens a *different* lightbox than expected, another
extension is likely also grabbing images — common culprits are JCE
MediaBox, Mediabox CK, Image Effect CK, and gallery field plugins that
bundle GLightbox. Inspect the opened overlay: this plugin's markup always
uses `id="alb-overlay"`. If you see something else, disable that
extension's auto-lightbox behaviour.

## Upgrading from `plg_content_autolightbox`

Version 1.1.0 renamed the plugin element to align with the FG series.
Joomla therefore treats it as a **new** extension, not an update:

1. Install `plg_content_fgautolightbox`
2. Uninstall (or at least disable) the old `Content - AutoLightbox`

Leaving both enabled would process every image twice.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full history.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
