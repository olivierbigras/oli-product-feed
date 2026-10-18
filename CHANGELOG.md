# Changelog

## 2026-10-18

- **Liens** (`src/Util.php`, `src/Feeds.php`, `src/ItemBuilder.php`, `src/Admin/Editor.php`) : réglage « Paramètres ajoutés aux liens » (chaîne de requête, ex. `wcpbc-manual-country=US`, vide par défaut). Ajouté à `<link>` (`g:link`) de chaque article et à `mobile_link` s’il existe, via `add_query_arg`, en conservant les paramètres déjà présents (`attribute_*` des variations, `lang`). Pas ajouté à `image_link`. Saisie limitée aux paires `clé=valeur` (`sanitize_key` / `sanitize_text_field`). Filtre `oliprfe_link_args` (tableau, produit, flux — ordre déjà utilisé par Price Based on Country). Sans réglage, la sortie reste identique.
- **Tests** (`tests/test-link-args.php`) : produit simple, variation `attribute_*`, paramètre déjà présent, `mobile_link`, `image_link` intact, filtre.
- **Version** (`oli-product-feed.php`, `readme.txt`, `languages/oli-product-feed.pot`) : 0.5.0 → 0.5.1.

- **Filtres** (`src/Feeds.php`, `src/Pricing.php`, `src/ItemBuilder.php`, `src/Admin/Editor.php`) : seuil « Prix minimum » par flux (décimal, vide ou 0 = désactivé). Les produits simples et les variations dont le prix affiché — prix soldé si une promo est active, sinon le prix régulier, dans la devise du flux, le même montant que `<g:price>` / `<g:sale_price>` (y compris Price Based on Country / prix manuels US) — est sous ce seuil sont exclus. La comparaison se fait sur ce prix final, sans convertir le seuil (150 USD pour un flux USD, 150 CAD pour un flux CAD). Filtre `oliprfe_min_price` (valeur, flux, produit) pour les surcharges. Sans seuil, la sortie des flux existants reste identique.
- **Tests** (`tests/test-min-price.php`) : cas vide/0, promo, devise du flux sans conversion, prix style WCPBC, filtre de surcharge.
- **Version** (`oli-product-feed.php`, `readme.txt`, `languages/oli-product-feed.pot`) : 0.4.1 → 0.5.0.

## 0.4.1 (2026-10-16)

- Fix: with TranslatePress, a `fr_CA` feed wrote `http://site/fr/fr/produit/slug/` (404) for every item link and the channel `<link>`. After `trp_switch_language()`, `get_permalink()` and `home_url()` already include `/fr/`; `Lang::url()` ran the converter again (second `/fr/`, `https` dropped) and `prefix_url()` compared against the already-prefixed `home_url()`. URL translation is now idempotent (existing language slug is not doubled), the base URL is taken in the default language then converted once, and `force_https` runs after conversion.

## 0.4.0 (2026-10-16)

- Backorder availability date: per-feed toggle and lead time in days (default 21) for products with stock status `onbackorder` or `outofstock` (when the feed includes them). Google, Microsoft (Bing) and Meta receive `g:availability` = `backorder` (or `preorder` if configured), `g:availability_date` in ISO 8601 with the site time zone (recalculated at each generation), and optional `min_handling_time` / `max_handling_time`. In-stock items are unchanged. Other channels are not affected.
- Optional per-product override meta (`_oliprfe_availability`: a number of days or a `YYYY-MM-DD` date) on the product Inventory tab and on each variation.
- Fix: TranslatePress language (e.g. `fr_CA`) was not applied during generation (cron, Action Scheduler, WP-CLI, admin). The plugin now calls `trp_switch_language()`, sets the WordPress locale, uses translated permalinks / `get_url_for_language()`, and falls back to the dictionary and the `/fr/` URL slug so titles, descriptions and links match the feed language.

## 0.3.9 (2026-10-14)

Published on WordPress.org 2026-10-16 (SVN r3750329): https://wordpress.org/plugins/oli-product-feed/

WordPress.org plugin review follow-up (Review ID P0TDX382228HGN).

- Removed the optional must-use loader (`mu-plugin/`): no file outside the plugin is loaded.
- Support footer: inline `style` attribute replaced by the `oliprfe-support-footer` class in `assets/css/admin.css`.
- Admin asset handles renamed `oli-pf-admin` -> `oliprfe-admin`, script object `oliPfAdmin` -> `oliprfeAdmin`.

## 0.3.8 (2026-10-14)

WordPress.org plugin review fix (Review ID P0TDX382228HGN).

- Support notice: the dismiss handler is attached to the enqueued `oli-pf-admin` script with `wp_add_inline_script()`; the inline `<script>` tag in `src/Admin/Admin.php` is removed.

## 0.3.7 (2026-10-12)

Donate / support links (same pattern as Oli Media Cleaner).

- Plugins screen: Feeds + Donate action links, Donate in plugin meta row
- Dismissible support notice on plugin admin screens
- Footer donation line on feeds list, settings and feed editor
- `Donate link` in `readme.txt` for the WordPress.org plugin page (PayPal)

## 0.3.6 (2026-10-12)

WordPress.org plugin review fixes (second round).

- Namespace: renamed `OliProductFeed` to `Oliprfe` (and `Oliprfe\Admin`, `Oliprfe\Channels`, …). Plugin Check treated the old namespace as the too-short prefix `oli` (3 characters). `Oliprfe` matches the `oliprfe` / `OLIPRFE_` prefix already used on globals. The `oli-product-feed` text domain and slug are unchanged.
- Escaping: admin form fragments in `Editor` and the feed URL control in `Admin` are escaped late with `wp_kses` at every `echo` (allowed tags/attributes for form controls). The previous `phpcs:ignore WordPress.Security.EscapeOutput` annotations on those lines are removed.
- Must-use loader: when the plugin is active, requires the local `src/Endpoint.php` with a literal path under `WP_PLUGIN_DIR` (the WordPress.org slug folder). No variable `require` and no remote file access. Expects the standard folder name `oli-product-feed`.

## 0.3.5 (2026-10-09)

WordPress.org plugin review fixes.

- Prefix: renamed the plugin prefix from `oli_pf` / `OLI_PF` to `oliprfe` / `OLIPRFE` on every global symbol — the five `OLIPRFE_*` constants, the two global helper functions (`oliprfe_get_feed`, `oliprfe_get_feed_url`), the mu-plugin global variable, and the fired hooks (`oliprfe_channels`, `oliprfe_feed_generated`, `oliprfe_item`, `oliprfe_price`, `oliprfe_output_dir`, etc.). For consistency the option names, post type (`oliprfe_feed`), meta keys, transients, query var, scheduler hooks and admin-post/AJAX action names were renamed too. The `oli-product-feed` text domain and slug are unchanged. Plugin Check derives the accepted prefix from a single underscore-segment of four or more characters; `oli_pf` split into `oli` (too short) plus `oli_pf` and was not recognized, so all `oli_pf_*` globals were flagged. `oliprfe` is one segment of seven characters and is accepted. Note: because the option names and post type changed, feeds and settings stored under the old prefix are not carried over; reactivate the plugin and recreate feeds (acceptable for a plugin still in initial review).
- Sanitization: `Admin::handle_save()` now sanitizes the posted configuration (new `Admin::sanitize_config()`: every value through `Feeds::sanitize()`, the name through `sanitize_text_field()`, the active flag cast to boolean) before it is validated, saved, or stored in the error transient for redisplay. Previously the parsed configuration was stored in the transient before `Feeds::sanitize()` ran (review flag at `src/Admin/Admin.php:494`). Behavior is unchanged; the stored and saved data are identical, only sanitized earlier.
- Internationalization: removed the `load_plugin_textdomain()` call and its `init` hook (`src/Plugin.php`). WordPress 4.6+ loads the text domain of a directory-hosted plugin automatically, and WordPress.org serves the translations from its translation platform.
- Translations: removed the bundled compiled and source language files (`languages/oli-product-feed-fr_CA.po/.mo`, `oli-product-feed-fr_FR.po/.mo`); the `.pot` template is kept. Translations come from the WordPress.org translation platform.
- Optional must-use loader (`mu-plugin/oli-product-feed-early.php`): the plugin folder is read from the active plugins list instead of the hardcoded `wp-content/plugins/oli-product-feed/` path (review flag at `mu-plugin/oli-product-feed-early.php:15`), so a renamed plugin folder still works.

## 0.3.4 (2026-10-06)

- Output folder that cannot be used (QA E1): `OutputDirs::check()` tells whether a folder can be the output folder: no symbolic link on the way below the uploads directory, real path strictly inside the real path of the uploads directory (so an uploads directory that is itself a symbolic link still works), writable, or creatable in a writable folder. The settings form and `wp oli-feed settings output_dir` refuse a new folder that fails this check, or that cannot be created when the files are moved: the previous value is kept, the other settings are saved, and an error notice gives the reason (WP-CLI: error, exit code 1). When the folder comes from the `oliprfe_output_dir` filter, or the current folder becomes unusable, the effective folder stays the recorded folder that holds the feed files (`Storage::folder()`, fallback). The feeds keep being generated and served there (static URL and endpoint 200), nothing is moved or deleted, the registry `oliprfe_output_dirs` is kept, and a persistent error notice on the plugin screens names the folder, the reason and where the feeds are. Without such a folder, generation stops with that message, logged on the feed and in the WooCommerce log. `OutputDirs::sync()` also does nothing when its target folder is not usable (defense in depth). Folders created during a failed nested creation are removed when empty.
- File of another origin in the new folder (QA E2): a feed file is not moved over a file of the same name that the plugin did not write, and its copy in the previous folder is no longer deleted. The previous folder keeps its registry entry with that file, the feed is served from there (static URL of the previous folder, endpoint, URL shown in the list), the other file is never touched, and a warning notice plus a WooCommerce log entry explain what to do. The move happens on a later sync once the way is free. Generation never overwrites a file it did not write: it refuses to start when the feed's file (or its `.tmp`) exists in the output folder without having been written by the plugin, or is a symbolic link, and refuses to publish when such a file appeared during the generation. Deletion (trash, delete, uninstall with purge) only removes files recorded as written by the plugin. The current-folder sweep of 0.3.2 at uninstall is removed: the registry covers it, and before 0.3.3 the registry is created from the existing files first.
- Symbolic link (QA E3, since 0.3.2): `Storage::ensure_dir()` refuses an output folder that is a symbolic link or is inside one, or whose real path is outside the uploads directory, so neither `index.php` nor a feed file is written through a link.
- Settings: help text of the output folder updated; new notices and messages translated (fr_CA, fr_FR).

## 0.3.3 (2026-10-06)

- Output folder: the folders and files written by the plugin are recorded (option `oliprfe_output_dirs`: per folder, created by the plugin or not, index.php written by the plugin or not, feed files written). When the folder changes (setting, or `oliprfe_output_dir` filter: applied on the next settings save or generation), the files of the existing feeds move to the new folder (never over an existing file), the other files of the plugin and their `.tmp` are deleted from the previous folders, the plugin's index.php is deleted when nothing else is left, and a previous folder is removed when the plugin created it and it is empty (parent folders it created included). Safeguards: real path strictly inside the uploads directory (never the uploads directory or a parent), no symbolic link followed or deleted, a folder with a running generation is cleaned when it ends. On the first load after the update, the current folder is recorded with the files of the existing feeds (rules of 0.3.2: index.php recognized by its content, default folder counted as created by the plugin).
- Uninstall with "Delete all data": every recorded output folder is cleaned with the same rules (the index.php stays when files the plugin did not write are left, so the folder cannot be listed); the WooCommerce logs of the plugin are deleted: files `oli-product-feed-YYYY-MM-DD-<hash>.log` and their rotations in the WooCommerce log folder (its default folder when WooCommerce is inactive), and the rows of source `oli-product-feed` in the database log handler table. Unchecked (default): unchanged from 0.3.2, feeds, settings, files and logs are kept (documented under the setting and in the readme).
- Trash: also hooked to `transition_post_status` (any change to `trash`: `wp_update_post`, `wp_insert_post`, `wp post update`), so the file of a trashed feed is removed at once and the static URL stops serving it; permanent deletion (`before_delete_post`) unchanged. A status changed by a direct database query fires no hook: the endpoint still checks the feed status before serving.
- Settings: help texts of the output folder and of the uninstall option updated (fr_CA, fr_FR).

## 0.3.2 (2026-10-06)

- Mapping: `color` and `size` now have an automatic source on every channel (Google, Pinterest, Meta, TikTok, Microsoft, generic): the first of `pa_color`, `pa_colour`, `color`, `colour` (resp. `pa_size`, `size`) that the product has, variation value first, translated, one value only. An explicit mapping (including "Not sent") always wins. Attribute sources accept alternatives separated by `|`.
- Trash: a feed trashed (or deleted) outside the plugin has its file and temporary file removed, its generation canceled and the early index refreshed, so neither the endpoint nor the static URL serves it; a trashed feed cannot be generated. The early path also checks the feed status (one primary key lookup; entries now carry the feed ID) and ignores an index built by another plugin version (rebuilt on init), so a feed trashed before the update, or by a direct database change, is not served either; on the first load after the update, the files of feeds trashed by an older version are removed.
- Static file mode: `?oliprfe_feed=<slug>` answers 404 (it showed the home page).
- Uninstall: removes the Action Scheduler history of the plugin (`oliprfe_tick`, `oliprfe_run_batch` and the `oli-product-feed` group: actions, logs, group), skipped when the tables are absent; the files of trashed feeds are deleted too.
- Admin: URL label `?<slug>` (distinct per feed, full URL in the title) with WordPress serving; `position: relative` on the link (the screen reader text overflowed by 9 px at 1440 px); URL column 14% wide, the Copy button wraps under the label instead of truncating it.
- Docs: capability `manage_woocommerce` (or `manage_options` when shop manager access is off) in the readme and under the setting.

## 0.3.1 (2026-10-06)

- Fix (WordPress serving): a generated feed that is inactive (draft, e.g. imported with `active: false`) answered 404 "Feed not found." on `?oliprfe_feed=`, early path and init path alike, while its file existed (and was public in static file mode). Every feed with a generated file is now served and listed in the early index; "inactive" only stops the scheduled generation.

## 0.3.0 (2026-10-06)

- Channels: TikTok Catalog (CSV, TSV, XML), Microsoft Merchant Center (TXT, XML) and a generic CSV/TSV/XML channel with free columns and XML element names.
- Filters: product tags (include, exclude) and price range on the price shown; live count of the matching products in the editor.
- Editor: preview column in the attribute mapping, "Submit this feed" help per channel, time zone note, next generation; list search and Errors view.
- Mapping: source "copy of another feed attribute" (final value, after the brand detection and the field rules), e.g. custom_label_0 = brand; per-product values (`item_overrides`).
- Descriptions: when the content has no translation in the feed language, the translated meta description is used (call to action removed); the cleanup keeps ", .011" and, in French, the space before ; : ! ?
- Titles: the type rule is tested on the name in every language (same type in every feed of a product); optional words in brackets in type labels ("[Guitar] Strap").
- WordPress serving: the pre-generated file is sent while the plugins load (autoloaded index), with an optional must-use loader (`mu-plugin/oli-product-feed-early.php`); `application/xml`; max-age = min(setting, half the regeneration interval); weak ETags and If-None-Match first; cookies and Link headers removed.
- Settings: log level (WooCommerce logs), shop manager access, regeneration when products change (at most every 15 minutes), regenerate all active feeds, next schedule check.

## 0.2.0 (2026-10-06)

- Admin: WooCommerce > Product Feeds with a feed list (WP_List_Table, status, progress, copyable URL, bulk actions), a complete editor (target, filters, availability and exclusion rules, texts, attribute mapping, categories, links, schedule) and a settings tab.
- Channels: Pinterest Catalogs and Meta (Facebook, Instagram) catalogs.
- Title template per feed (`{brand} {name} {type} - {variation}`, type per category).
- MPN = SKU for all brands or only for a list of brands; a valid GTIN is always sent.
- Brand: priority brand list for products with several brand terms, else the brand that opens the name; "one value only" option for mapped attributes.
- Titles: type by name pattern (before the category table), brand spelling from the brand term, patterns removed from variation values (for example a SKU prefix).
- Descriptions: patterns removed (for example a meta description call to action), sentences with an email address removed.
- Field rules: fill an empty attribute (color, age_group, gender…) for some Google categories, with a fixed value or from words of the product name; rules can be kept inactive.
- Scheduler: `wp oli-feed run-due` and an admin warning when WP-Cron does not run.
- Optional serving through WordPress (`?oliprfe_feed=<file>`): short Cache-Control, X-Robots-Tag noindex, ETag, Last-Modified, 304.
- Translations: fr_FR and fr_CA (`tools/build-translations.py`); installable ZIP built with `tools/build-zip.sh`.
- uninstall.php (data deleted only when the option is checked).
- Fix: variations of a batch could get the ID 0 when several batches ran in the same request.

## 0.1.0 (2026-10-05)

- Google Merchant Center feeds (XML, CSV, TSV), one per language and currency.
- TranslatePress and Price Based on Country integrations.
- Batch generation with Action Scheduler, lock and atomic swap; WP-CLI.
