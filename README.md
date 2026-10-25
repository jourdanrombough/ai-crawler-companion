# AI Crawler Companion

### The GoDaddy Managed WordPress fix for llms.txt, Markdown and AI bot tracking plugins

You installed an llms.txt generator, a "Markdown for AI" plugin or an AI bot tracker on a GoDaddy Managed WordPress site, and it seems to do nothing. The bot dashboard stays nearly empty, edits never show up in the files AI tools read, and you can't tell whether ChatGPT or Perplexity ever came by.

The plugin is usually fine. The host is the problem. AI Crawler Companion works around it.

## Is this you?

* Your site is on **GoDaddy Managed WordPress**
* You run one or more AI visibility plugins, for example:
  * **llms.txt generators**: All in One SEO, Yoast SEO, Rank Math, Website LLMs.txt, LLMagnet, RankReady
  * **Markdown copies of posts** (`/your-post.md`): LLM Markdown, LLMagnet, RankReady
  * **AI bot trackers**: LLMagnet analytics or any plugin that counts GPTBot, ClaudeBot, PerplexityBot and friends from inside WordPress
* The numbers look too low, or the files look out of date

Quick check from a terminal:

```bash
curl -sI https://yoursite.com/ | grep -iE "cache-control|cf-cache-status"
```

If you see `max-age=2678400` and a `cf-cache-status` header, you are behind GoDaddy's CDN and this plugin is for you.

## Why AI plugins break on GoDaddy

Every GoDaddy Managed WordPress site sits behind a Cloudflare CDN that GoDaddy runs for you. It caches pages for up to **31 days**, overrides the cache headers your plugins send, and **cannot be turned off**. GoDaddy support confirmed it cannot be disabled, URL exclusions are not supported, and the cache lifetime cannot be shortened. Their `wp wpaas cache flush` command exists but cannot be scheduled. On top of that, **WP-Cron is disabled** on the platform.

That breaks each kind of AI plugin in its own way:

| Plugin type | What goes wrong on GoDaddy | What AI Crawler Companion does |
|---|---|---|
| **AI bot trackers** | Most bot requests are answered by the CDN and never reach WordPress, so in-WordPress trackers see a small slice. They also trust the user agent, so a fake "GPTBot" counts the same as the real one. | Logs every bot request that reaches WordPress, checks each IP against the ranges OpenAI, Perplexity, Google, Bing and Apple publish, and can flush the CDN on a schedule so a steady share of bot traffic gets through and gets counted. |
| **Markdown copies (.md)** | The `.md` copy is cached like any other URL. When you edit a post, the copy bots read can stay old (we are still verifying how long). If content negotiation is on, a cache that keys on the URL alone can serve Markdown to human visitors. | Flushes the CDN about two minutes after you publish or update a post, records whether each bot actually received Markdown, and warns you if content negotiation is risky. |
| **llms.txt generators** | `/llms.txt` gets cached too, so updates can lag for weeks. Scheduled rebuilds that rely on WP-Cron never run. GoDaddy also has its own LLMS.TXT switch (GoDaddy > Tools in wp-admin), so two sources can fight over the same file. | Flushes after content changes without needing WP-Cron, and Diagnostics lists every llms.txt source it finds (including the GoDaddy switch) and warns when there is more than one. |
| **All of the above** | GoDaddy's access logs show IPs but not user agents, so the host cannot tell you which bots visited either. | Gives you a bot log with user agent, verified IP, status code and content type, plus CSV export. |

AI Crawler Companion works next to your existing plugins. It does not generate llms.txt or Markdown itself.

## How this was found

I hit every one of these problems while setting up a controlled test of whether Markdown copies of posts change how often AI tools crawl and cite a site. The test runs on three GoDaddy Managed WordPress sites. The workarounds I built for the study became this plugin, so anyone else on GoDaddy can use them. Study results will be published on [optimizationtheory.com](https://optimizationtheory.com/) and folded into future versions.

## Quick start for GoDaddy sites

1. Download the zip from [Releases](https://github.com/jourdanrombough/ai-crawler-companion/releases), then in WordPress go to **Plugins > Add New > Upload Plugin** and activate.
2. Open **Tools > AI Crawler Companion > Diagnostics**. Click **Run diagnostics**, then **Refresh IP ranges**.
3. In **Settings**:
   * Leave **Flush after post updates** on. This keeps `.md` copies and llms.txt current.
   * Set **Interval CDN flush** to 24 hours for everyday use, or 1 hour while you are measuring bot traffic closely.
4. If Diagnostics flags **content negotiation**, turn it off in your Markdown plugin and use `.md` URLs instead.
5. Check the **Overview** tab after a few days to see which bots are visiting, which ones are verified, and how many actually received Markdown.

## What's inside

**Bot log.** About 30 AI and search bots, grouped as `user` (a live fetch because a person asked an AI something), `search` (AI search index), `training` and `engine` (classic search). Each row stores time, bot, IP, method, URL, final status code, content type, whether Markdown was served, Accept header and user agent. It is written after the response is built, so it also captures Markdown plugins that end the request early. Browsers are never logged.

**IP verification.** Downloads the published ranges weekly: OpenAI (GPTBot, OAI-SearchBot, ChatGPT-User), Perplexity (PerplexityBot, Perplexity-User), Google (Googlebot, special crawlers, user-triggered fetchers), Bingbot and Applebot. Each hit is marked `yes`, `no` (spoofed user agent), `unlisted` (the operator publishes no list) or `pending`. IPv4 and IPv6.

**GoDaddy CDN flush.** Uses the flush function in GoDaddy's own system plugin. Interval flush (1, 3, 6, 12 or 24 hours), a debounced flush two minutes after any public post changes, and a **Flush now** button. Runs at the end of normal page loads, so it works with WP-Cron disabled. A lock prevents overlapping flushes.

**Diagnostics.** Host detection, WP-Cron status, homepage cache headers, competing llms.txt sources, Markdown plugin conflicts, a live `.md` check (content type and canonical `Link` header) and a content-negotiation warning.

**Admin screen.** Tools > AI Crawler Companion: Overview (7 and 30 day tables with requests, verified hits, Markdown served, unique URLs and bot-hours), Recent hits, Settings, Diagnostics, CSV export.

## Not on GoDaddy?

The bot log, IP verification and Diagnostics work on any WordPress host. The flush features only switch on when GoDaddy Managed WordPress is detected.

## Limits

* It can only log requests that reach WordPress. Between flushes, cached hits are still invisible. Flushing raises coverage; it does not make it complete.
* A CDN flush clears the whole site, not only `.md` URLs. GoDaddy offers no narrower purge that reliably clears its CDN.
* Anthropic, Meta, Amazon, Common Crawl and others publish no IP list this plugin can read, so their hits show as `unlisted`.
* The GoDaddy integration relies on an internal function of GoDaddy's system plugin. If GoDaddy changes it, Diagnostics will say so and flushing stops safely.
* Version 0.1.0 is a beta. It passes the official WordPress Plugin Check and was tested on WordPress 7.1 with LLM Markdown 1.1.0. Live GoDaddy testing is under way as part of the study above.

## For developers

| Filter | Use |
|---|---|
| `aicc_bot_registry` | Add or change bots (`name`, `pattern`, `vendor`, `class`, `ranges`) |
| `aicc_ip_range_sources` | Add published IP list URLs (`prefixes[].ipv4Prefix` / `ipv6Prefix` format) |
| `aicc_llms_txt_plugins` | Add llms.txt generator plugins to the conflict check |
| `aicc_markdown_plugins` | Add Markdown plugins to the conflict check |

## Uninstall

Deleting the plugin from wp-admin drops the `{prefix}aicc_hits` table and removes all of its options.

## License

GPL-2.0-or-later. Built by Jourdan Rombough, [Optimization Theory](https://optimizationtheory.com/). Not affiliated with or endorsed by GoDaddy.
