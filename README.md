# AI Crawler Companion

A WordPress plugin that logs AI and search bot visits with verified IPs, and fixes the GoDaddy Managed WordPress CDN problems that make AI visibility plugins undercount bots and serve stale Markdown.

It works next to llms.txt and Markdown plugins (LLM Markdown, LLMagnet, AIOSEO, Yoast, Rank Math and others). It does not generate either file itself.

## Why it exists

Found while setting up a controlled Markdown-for-AI test on three GoDaddy Managed WordPress sites:

| Problem on GoDaddy Managed WP | Effect on AI visibility plugins |
|---|---|
| Managed Cloudflare CDN, `max-age=2678400` (31 days) | Most bot requests never reach WordPress, so in-WordPress trackers miss them |
| Origin `Cache-Control` and `Set-Cookie` overridden or stripped | Per-page "don't cache" headers do nothing |
| CDN cannot be disabled, no URL exclusions | No way to exempt `.md` or `llms.txt` |
| Purges on post save target the page URL, not its `.md` copy (still being verified) | Bots can get old Markdown after an edit |
| `DISABLE_WP_CRON` is true | Scheduled cleanups, rebuilds and IndexNow pings don't run |
| Access logs have IPs but no user agents | You can't see which bots visited from the host |
| A built-in LLMS.TXT switch under GoDaddy > Tools | Can compete with your SEO plugin's llms.txt |

## What it does

**Bot log.** About 30 bots, classed as `user` (live fetch for a person's question), `search` (AI search index), `training` and `engine`. Each row stores time, bot, IP, method, URI, final status, content type, whether Markdown was served, Accept header and user agent. Written at `shutdown`, so it also captures plugins that `exit` early when serving Markdown.

**IP verification.** Downloads the published ranges weekly (OpenAI GPTBot, OAI-SearchBot, ChatGPT-User; PerplexityBot, Perplexity-User; Googlebot, special crawlers, user-triggered fetchers; Bingbot; Applebot). Each hit is `yes`, `no` (spoofed user agent), `unlisted` or `pending`. IPv4 and IPv6.

**GoDaddy CDN flush.** Uses GoDaddy's own `$wpaas_cache_class->flush_cdn()`. Interval flush (1, 3, 6, 12 or 24 hours) and a debounced flush two minutes after any public post changes status or content. Triggered at the end of normal requests, so it works with WP-Cron disabled. A lock prevents concurrent flushes.

**Diagnostics.** Host detection, WP-Cron, homepage cache headers, competing llms.txt generators, Markdown plugin conflicts, a live `.md` fetch (content type and canonical `Link` header), and a content-negotiation warning when a shared cache could serve Markdown to people.

**Admin.** Tools > AI Crawler Companion: Overview (7 and 30 day tables with requests, verified, Markdown served, unique URLs, bot-hours), Recent hits, Settings, Diagnostics, CSV export.

## Install

Download the zip, then Plugins > Add New > Upload Plugin. Activate, open Tools > AI Crawler Companion > Diagnostics, run diagnostics and refresh IP ranges.

## Filters

| Filter | Use |
|---|---|
| `aicc_bot_registry` | Add or change bots (`name`, `pattern`, `vendor`, `class`, `ranges`) |
| `aicc_ip_range_sources` | Add published IP list URLs (`prefixes[].ipv4Prefix` / `ipv6Prefix` format) |
| `aicc_llms_txt_plugins` | Add llms.txt generator plugins to the conflict check |
| `aicc_markdown_plugins` | Add Markdown plugins to the conflict check |

## Limits

* It can only log requests that reach WordPress. Between flushes, cached hits are still invisible. The interval flush raises coverage; it does not make it complete.
* A full CDN flush affects every page, not only `.md` URLs. GoDaddy offers no narrower purge that reliably clears Cloudflare.
* Anthropic, Meta, Amazon, Common Crawl and others publish no IP list this plugin can read, so their hits are `unlisted`.
* The GoDaddy integration relies on an internal API of GoDaddy's system plugin. If GoDaddy changes it, diagnostics will say so and flushing stops safely.

## Uninstall

Deleting the plugin from wp-admin drops the `{prefix}aicc_hits` table and removes all options.

## License

GPL-2.0-or-later. Built by Jourdan Rombough, [Optimization Theory](https://optimizationtheory.com/).
