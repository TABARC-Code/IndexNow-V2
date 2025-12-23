=== IndexNow ===
Contributors: tabarc-code
Tags: indexnow, seo, indexing, bing
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Queues URLs on publish/update and submits them to IndexNow (default endpoint: Bing).

== Description ==
This plugin submits updated URLs via IndexNow using a single POST JSON request per page load (flushed on shutdown).
Optional sitemap submission is rate-limited.

Security notes:
- Settings page is manage_options only.
- Settings saves are nonce protected.
- URL submission is gated behind "enabled" + presence of a key.

== Installation ==
1. Upload the plugin folder to /wp-content/plugins/
2. Activate it
3. Go to Settings → TABARC IndexNow and set your IndexNow key

== Frequently Asked Questions ==
= Do I need to host a key file? =
Yes. IndexNow expects a UTF-8 text file named {key}.txt containing the key, hosted on the same site.

== Changelog ==
= 1.0.0.2 =
* Rewritten: options UI, queued batching, POST JSON sender, and safer defaults.
