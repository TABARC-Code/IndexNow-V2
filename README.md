# IndexNow (WordPress Plugin)

A small IndexNow submitter for WordPress, written with the usual expectations:
WordPress is inconsistent, hosting environments are “creative”, and someone will click the wrong thing at the wrong time.

This plugin submits updated/deleted URLs to IndexNow-compatible endpoints (default: Bing).  
It batches submissions, dedupes URLs, and avoids turning post saves into a distributed denial-of-service against your own server.

No promises about rankings. No SEO mysticism. Just notifications.

---

## What’s in v2

- URL queue (transient-backed) with dedupe and a hard cap.
- Batch submit at `shutdown` so a save doesn’t trigger an HTTP call every time.
- Cron fallback for when `shutdown` never runs (fatals/timeouts happen; pretending otherwise is adorable).
- Admin tools:
  - Verify Key File
  - Submit Queue Now
  - Clear Queue
  - Last Result (stores the last attempt so you’re not guessing)
- Optional sitemap queueing (off by default).
- WP-CLI commands when WP-CLI is available.

---

## Requirements

- WordPress 6.x
- PHP 8.0+
- Outbound HTTP allowed from the server (some hosts block it and then everything looks “fine” while doing nothing)

---

## Idiot’s Guide (do this in order)

### 1) Install

- WP Admin → Plugins → Add New → Upload Plugin
- Activate **IndexNow**

If activation fails, stop here. Fix that first. Nothing else will improve by optimism.

### 2) Create your IndexNow key file

IndexNow expects a key file on your site. If it can’t fetch it, it will ignore you.

You need:
- a key value (random string)
- a text file named `<key>.txt`
- that file reachable at `https://your-site.com/<key>.txt`

Example key value:
```
1234567890abcdef1234567890abcdef
```

Example file name:
```
1234567890abcdef1234567890abcdef.txt
```

Example URL that MUST load in a browser:
```
https://your-site.com/1234567890abcdef1234567890abcdef.txt
```

If that URL returns 404, fix it.  
If it redirects through a login wall, fix it.  
If it loads sometimes, welcome to your hosting provider: fix it anyway.

### 3) Configure the plugin

Settings → **IndexNow**

- Paste the key value into **IndexNow Key** (key value only)
- Save

Then click **Verify Key File**.  
If verification fails, you’re not ready yet. Don’t “try it anyway”.

### 4) Use it

Publish, update, or delete content. The plugin queues URLs and submits them in batches.

If you need to force submission:
- click **Submit Queue Now**

---

## Common “it doesn’t work” checks

1. Key file URL loads publicly (no auth, no firewall, no “clever” redirects).
2. Your server can make outbound HTTPS requests.
3. You’re editing **published** content (drafts/private posts are ignored).
4. You’re not expecting instant indexing. IndexNow is a notification, not a contract.

If you enable Debug Logging, logs go to your PHP error log.  
Yes, logs are useful. Yes, logs are also how data leaks. Use sparingly.

---

## WP-CLI

If WP-CLI is present:

- `wp indexnow queue`
- `wp indexnow submit`
- `wp indexnow clear`
- `wp indexnow verify`

---

## Packaging

`scripts/pack-plugin.sh` creates a WP-uploadable ZIP and excludes repo-only files.

---

## Licence

MIT.

## Author

TABARC-Code  
Plugin URI: https://github.com/TABARC-Code/
