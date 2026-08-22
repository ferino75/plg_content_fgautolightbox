# Content - FG AutoLightbox — Joomla 6 native build

This is the **native, Joomla-6-only** build. Requires **Joomla 6.0+ and
PHP 8.3+**. If you're on Joomla 3.10, use the classic build in the
repository root instead — see [the main README](../README.md) for the
full picture of why there are two builds.

## What's different from the classic build

Functionally, nothing from your point of view as a site administrator —
same settings, same behavior, same lightbox. Internally, this build is a
ground-up rewrite using PSR-4 classes, constructor dependency injection,
Joomla's `WebAssetManager`, and PHP 8.3+ syntax (enums, readonly
properties, `match` expressions). See [CHANGELOG.md](CHANGELOG.md) for
the full technical breakdown.

## Installation

1. Download the latest `plg_content_fgautolightbox_joomla6_vX.Y.Z.zip`
   from [Releases](https://github.com/ferino75/plg_content_fgautolightbox/releases)
   (tagged `joomla6-vX.Y.Z`, distinct from the classic build's plain
   `vX.Y.Z` tags)
2. In Joomla admin: **System → Install → Extensions**, upload the ZIP
3. Go to **System → Plugins**, search for `AutoLightbox`, and enable
   **Content - FG AutoLightbox**

## Migrating from the classic build

If a site currently has the classic build installed (even on Joomla 6,
where it still runs fine via its legacy/modern dual-path code), this
native build is a **separate install**, not an in-place update. Both
builds share the same plugin element (`content`/`fgautolightbox`), so
install them **in this order**:

1. **Uninstall the classic plugin first** (System → Manage → Extensions,
   or disable it at minimum if you're not ready to remove it yet).
   Installing the native build on top of an active classic install risks
   Joomla finding both the old flat `fgautolightbox.php` file and the
   new `src/`/`services/` structure in the same plugin folder at once.
2. **Then install this native build** on the now-clean folder.

Your settings are not carried over automatically since they're stored
per-plugin-instance; re-enter them in the new plugin's configuration
(they're the same fields, same names).

Note that Joomla's own **update system will never prompt this
migration automatically** — the classic build's update channel is
deliberately restricted to Joomla 3.x only (`targetplatform`), so a
Joomla 6 site running the classic build won't see this native build
offered as an "update". Migrating is a manual, one-time step.

## Requirements

- Joomla 6.0 or later
- PHP 8.3 or later
- `dom`, `json`, `mbstring` PHP extensions (all standard Joomla 6
  requirements already)
