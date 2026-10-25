=== AI Crawler Companion ===
Contributors: jourdanrombough
Tags: ai crawlers, gptbot, llms.txt, markdown, godaddy
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Makes llms.txt, Markdown and AI bot tracking plugins work on GoDaddy Managed WordPress, with a verified AI bot log for any host.

== Description ==

Installed an llms.txt generator, a Markdown-for-AI plugin or an AI bot tracker on GoDaddy Managed WordPress, and it seems to do nothing? The bot dashboard stays nearly empty, edits never reach the files AI tools read, and you cannot tell whether ChatGPT or Perplexity ever visited. The plugin is usually fine. The host is the problem.

AI visibility plugins (llms.txt generators, Markdown copies of posts, AI bot trackers) assume every request reaches WordPress. On GoDaddy Managed WordPress it usually does not:

* Every site sits behind a GoDaddy-managed Cloudflare CDN that can cache pages for up to 31 days.
* The CDN overrides the Cache-Control header your plugins send, so "don't cache this" has no effect.
* The CDN cannot be turned off, URL exclusions are not offered and the cache lifetime cannot be shortened.
* WP-Cron is disabled, so plugins that schedule cleanups, rebuilds or pings never run them.
* GoDaddy's own access logs keep IPs but not user agents, so you cannot see which bots visited.

The result: bot trackers undercount, Markdown (.md) copies and llms.txt can stay stale after you edit a post, GoDaddy's own LLMS.TXT switch can compete with your SEO plugin's file, and you cannot tell a real GPTBot from someone pretending to be one.

AI Crawler Companion works alongside your existing plugins. It does not generate llms.txt or Markdown itself.

**Bot log**

* Logs requests from about 30 AI and search bots (OpenAI, Anthropic, Perplexity, Google, Microsoft, Apple, Meta, Amazon, Common Crawl, ByteDance, Mistral and more), grouped as live user fetches, AI search indexers, training crawlers and classic search engines.
* Records the final status code and content type, so you can see whether a bot actually received Markdown.
* Checks each IP against the ranges OpenAI, Perplexity, Google, Microsoft and Apple publish, and marks spoofed user agents.
* Bot-hours metric: each URL counted once per bot per hour, so bursts and retries do not inflate the numbers.
* CSV export. Browsers are never logged.

**GoDaddy CDN tools**

* Optional interval flush (every 1 to 24 hours) through GoDaddy's own cache API, so a steady share of bot traffic reaches WordPress and gets logged.
* Flush shortly after a post is published, updated or unpublished, so .md copies and llms.txt stay current. Edits within two minutes share one flush.
* "Flush now" button.
* Runs without WP-Cron.

**Diagnostics**

* Detects GoDaddy Managed WordPress and checks the flush method is available.
* Reports CDN cache headers on your homepage.
* Finds every active llms.txt generator (AIOSEO, Yoast, Rank Math, LLMagnet, RankReady and others) and warns when more than one could publish the file. Reminds you of GoDaddy's own LLMS.TXT switch.
* Fetches a recent post's .md copy and checks content type and canonical Link header.
* Warns when content negotiation would let a shared cache serve Markdown to human visitors.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/ or install the zip from Plugins > Add New > Upload.
2. Activate it.
3. Go to Tools > AI Crawler Companion > Diagnostics and click "Run diagnostics", then "Refresh IP ranges".
4. On GoDaddy, choose an interval flush in Settings if you want fuller bot counts.

== External services ==

This plugin connects to the following services. Nothing about your site or its visitors is sent to any of them; the plugin only downloads public files.

**Bot IP range lists** (used for IP verification, which you can turn off in Settings). Downloaded at most once a week, during a page load by a logged-in administrator, or when you click "Refresh IP ranges". A plain GET request with WordPress's default user agent; no site data is sent.

* OpenAI: https://openai.com/gptbot.json, https://openai.com/searchbot.json, https://openai.com/chatgpt-user.json (terms: https://openai.com/policies/terms-of-use/, privacy: https://openai.com/policies/privacy-policy/)
* Perplexity: https://www.perplexity.ai/perplexitybot.json, https://www.perplexity.ai/perplexity-user.json (terms: https://www.perplexity.ai/hub/legal/terms-of-service, privacy: https://www.perplexity.ai/hub/legal/privacy-policy)
* Google: https://developers.google.com/static/search/apis/ipranges/googlebot.json, special-crawlers.json and user-triggered-fetchers.json in the same folder (terms: https://policies.google.com/terms, privacy: https://policies.google.com/privacy)
* Microsoft Bing: https://www.bing.com/toolbox/bingbot.json (terms: https://www.microsoft.com/servicesagreement, privacy: https://privacy.microsoft.com/privacystatement)
* Apple: https://search.developer.apple.com/applebot.json (terms: https://www.apple.com/legal/internet-services/terms/site.html, privacy: https://www.apple.com/legal/privacy/)

**GoDaddy CDN** (only on GoDaddy Managed WordPress, only when you enable a flush option or click "Flush now"). The plugin calls the cache-flush function in GoDaddy's own system plugin, which tells GoDaddy's CDN to clear its cache for your site. GoDaddy terms: https://www.godaddy.com/legal/agreements, privacy: https://www.godaddy.com/legal/agreements/privacy-policy

**Your own site.** Diagnostics fetch your homepage, /llms.txt and one post's .md copy from your own server to read their headers.

The bot log is stored only in your own database. IP addresses of bots are stored; browser visits are never logged.

== Frequently Asked Questions ==

= Does this work outside GoDaddy? =

Yes. Logging, IP verification and diagnostics work on any host. The flush features only act on GoDaddy Managed WordPress.

= Will the interval flush slow my site? =

Each flush empties the CDN, so the next visitors to each page are served by WordPress instead of the edge. Hourly is meant for measurement periods. For normal use, 12 or 24 hours, or off, is enough.

= Why do some bots show "unlisted"? =

Their operators do not publish IP lists in a format the plugin reads, so those hits are counted by user agent only.

= Does this make AI tools cite my site? =

No. It shows you which bots visit, what they receive, and removes hosting problems that get in the way. Whether Markdown copies or llms.txt change citations is still an open question.

== Changelog ==

= 0.1.0 =
* First release.
