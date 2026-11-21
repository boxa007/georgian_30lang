=== Text to Image API ===
Contributors: boxa007
Tags: image, content protection, gd
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.4.20
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converts text inside selected HTML classes into local image blocks when a post is saved.

== Description ==

The plugin automatically detects HTML elements with the class `imgjs1` or `nextjs1` whenever a WordPress post is saved. It replaces each marked block's text with one or more locally generated PNG images while preserving the outer element and its CSS classes.

No shortcode, translation API, or API key is needed. Normal WordPress REST post publishing remains unchanged: include the classes in the HTML sent by your system and the plugin processes only those marked blocks on save.

Long marked blocks are divided into multiple images to keep the resulting cards readable. Blocks already rendered by the plugin are marked internally and are not processed again on later saves.

The generated images are stored in the Media Library and attached to the post. Source text is removed from the saved post HTML; images use empty alt text.

Requirements:

* PHP GD extension, including FreeType support for best typography.

== Installation ==

1. In WordPress, go to Plugins > Add New > Upload Plugin.
2. Upload `text-to-image-api.zip` and activate **Text to Image API**.
3. Publish posts normally. Wrap any text that must become an image in `imgjs1` or `nextjs1`.

== Usage ==

Send regular post content containing either class:

`<div class="imgjs1">Text to render as an image.</div>`

`<p class="nextjs1">This paragraph will also become a local PNG.</p>`

== Changelog ==

= 2.4.20 =
* Load guard fix: when hosting hides the CPU count (/proc blocked by open_basedir) the job no longer pauses forever; it pauses only at load above 8. Status page shows current load, cores and threshold.

= 2.4.19 =
* Sends a short status report (site, version, converted/remaining, space saved) to the central table: once per installed version, daily while cleanup runs, once when done.

= 2.4.18 =
* Status page counts card files (not attachment rows) and includes leftover copies removed by the files phase.

= 2.4.17 =
* Cards interrupted mid-conversion (e.g. by the PHP time limit under the old 40 s batches) get one more attempt instead of being skipped.

= 2.4.16 =
* Gentle cleanup: at most 50 old cards per run, one run every 2 minutes (20 s max), skipped while server load is above 70% of CPU cores.
* Media > Сжатие карточек (Text to Image): status page with converted / remaining counts and space saved.

= 2.4.15 =
* Opt into WordPress automatic background updates from GitHub releases.

= 2.4.14 =
* Manual cleanup phase 'files': removes leftover resized and '-scaled' copies of cards and re-points attachments to the original card.

= 2.4.12 =
* Cards are black-and-white: PNGs are saved as a 3-level gray palette on pure white (about 4.7x smaller).
* Cleanup no longer loops on cached query results (W3 Total Cache) and re-encodes older 4-level cards.

= 2.4.10 =
* Cards are black-and-white: PNGs are saved as a 4-level gray palette on pure white (about 4x smaller).
* No intermediate image sizes are generated for cards (they are only shown full size): ~88% less disk, less CPU on publish.
* One-time background cleanup of older cards: deletes their unused resized and '-scaled' copies and recompresses the original in place (same URL).

= 2.0.0 =
* Replaced shortcode/translation processing with automatic image rendering for `.imgjs1` and `.nextjs1` blocks on post save.
* Removed the need for an external translation API or API key for protected blocks.

= 1.5.0 =
* Added OpenRouter translation with automatic source-language detection and an admin settings page for the API key and model.

= 1.4.2 =
* Fixed a PHP/cURL compatibility error that could return 502 during translation on shared hosting.

= 1.4.1 =
* Show WordPress's per-plugin auto-update switch even when a GitHub release is already current.

= 1.4.0 =
* Add WordPress-native update checks for public GitHub releases from boxa007/deepseek.

= 1.3.0 =
* Do not silently publish source-language text when the translator is unavailable.
* Add a cURL fallback for hosts that block WordPress outbound HTTP requests.
* Remove HTML and unrelated shortcodes before creating translations and image cards.

= 1.2.0 =
* Added shortcode-triggered processing during standard post publishing.
* Generated images are saved permanently in Media Library for shortcode-triggered posts.
* Added language-independent automatic numbered-heading detection and `marker` option.
