=== Text to Image API ===
Contributors: boxa007
Tags: translation, image, rest api, gd
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translates submitted text into English and returns four image cards and formatted English text through a REST API.

== Description ==

The plugin accepts text through its REST endpoint. It keeps the portion before the second-part marker, divides that text into up to four parts, translates each part into English, and returns image-card HTML together with the English text formatted at 11pt.

For the usual WordPress REST publishing flow, add `[text_to_image]` to a post body. The plugin processes only that post on save, removes the shortcode, preserves the source text, and appends four permanent Media Library images plus the English translation. Use `[text_to_image marker="PARTE 2"]` when the split point should be explicit.

For any language, add an optional `split_marker` property with the exact marker from the source. Without it, the plugin detects a standalone numbered heading such as `PARTE 2`, `ЧАСТЬ 2`, `भाग 2`, `PART II`, or `第２部`.

The generated image links are protected with short-lived, random tokens. The endpoint also applies a small per-IP rate limit to reduce misuse of the public translation route.

Requirements:

* PHP GD extension, including FreeType support for best typography.
* WordPress must be able to make outgoing HTTPS requests to Google Translate.

== Installation ==

1. In WordPress, go to Plugins > Add New > Upload Plugin.
2. Upload `text-to-image-api.zip` and activate **Text to Image API**.
3. Send a POST request to `/wp-json/text-to-image/v1/translate`.

== Usage ==

POST JSON to `/wp-json/text-to-image/v1/translate`:

`{"text":"თქვენი ქართული ტექსტი... ნაწილი 2 ... დარჩენილი ტექსტი"}`

The response contains:

* `images` — HTML image cards.
* `english_text` — translated text in HTML.
* `full_output` — both values combined.
* `remaining` — the source text from `ნაწილი 2` onward.

Example browser request:

`fetch('/wp-json/text-to-image/v1/translate', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({text: 'ქართული ტექსტი. ნაწილი 2 დარჩენილი ნაწილი'})}).then(r => r.json()).then(console.log);`

== Changelog ==

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
