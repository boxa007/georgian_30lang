=== Text to Image API ===
Contributors: boxa007
Tags: image, content protection, gd
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.0.0
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
