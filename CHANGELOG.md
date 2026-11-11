# Changelog

All notable changes to `hop/safety` are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). The package has no tagged release yet, so everything is under Unreleased and apps require `dev-main`.

## [Unreleased]

## [0.2.0] - 2026-11-12

### Added

- `Hop\Safety\DeepLinks` and the `DeepLink` value object: "open in the app" for an https destination on the host of a known app (YouTube, Spotify, WhatsApp, Telegram, Instagram, X, TikTok, Zoom, App Store, Google Play). `resolve($url)` returns the app, the iOS address, the Android `intent://` (or `market://`) address and the original address as `fallback`, or `null`. One class serves a redirect and a block renderer. Which platform a visitor is on stays with the caller.
- Safety rules, all failing closed: only `https://` is read and only its host picks the app (no typed scheme, user info, port, IP literal, trailing dot or look-alike host, no whitespace, control character or backslash, no address over `safety.deep_links.max_url_length`, 2048 by default); the path must match the route as a whole (every pattern of the table ends in `\z`); a query value that decodes to a control character (`%0A`, `%0D`, `%00`...) gives no deep link; every piece carried over is percent-encoded, so `;`, `#`, quotes and line breaks cannot leave an `intent://` address; the finished address is checked against a strict shape; the Android package always comes from the table.
- The known apps are data in `resources/deep-link-apps.php`. An app adds, replaces or removes one with `config('safety.deep_links.apps')` (same shape, merged by key, `false` removes); entries that are not valid are ignored, and so is a route whose `path` pattern does not start with `^` and end with `$` or `\z`. `DeepLinks` is bound as a singleton by the service provider.
- README (and its 8 translations): a section and a table row for `DeepLinks`. Tests: `tests/Unit/DeepLinksTest.php` (known apps, the injection table, host and scheme refusals) and `tests/Feature/DeepLinksConfigTest.php` (configuration, invalid entries, a guard that the class stays free of app code).

## [0.1.1] - 2026-11-11

### Changed

- README (and its 8 translations): the HOP family table now lists PayHop (`pay.hel.ink`). `scripts/check-hop-family.sh` can check against another HEL.ink ref or a local file (`--ref`, `--from`). No change in behaviour.

## [0.1.0] - 2026-11-10

### Safe Browsing v5
- Safe Browsing is now v5 `hashes:search` (`Hop\Safety\SafeBrowsing`), not v4 `threatMatches:find`. The address no longer leaves the install at all: the lookup sends base64url four-byte SHA-256 prefixes of its expressions (the URL, the same without its query, the host, and the host with a slash), and a returned full hash is checked against those expressions before it counts, so a prefix collision is not a block. Threat types and attributes the client does not understand, and `CANARY`, are disregarded as the API requires.
- The lookup answers `blocked`, `clean` or `unknown`. `unknown` is what a missing key, a failed request, an error status or an unreadable answer now produce; it is never reported as clean, and `LinkVerdict` adds the new `unverified_destination` signal (30, never a block on its own) when a provider that was asked could not answer.
- Each provider keeps its own cache and its own answer: Safe Browsing inside `SafeBrowsing`, URLhaus under its own key. Block and warning data is trusted for at most `safety.providers.threat_cache_minutes` (30 by default) whatever `cacheDuration` the provider asks for; a clean answer may be kept for `cache_hours` but not beyond the provider's own duration plus a day; a failure is remembered for `unknown_cache_minutes` (5) only, so a recovered provider is asked again soon.
- `LinkSafety::providerStatus()` reports what each provider said (`blocked`/`clean`/`unknown`, `hit`/`miss`/`unknown`), so a caller can tell "nothing known" from "could not ask".

### Added

- `LinkVerdict::evaluate()` and the `Verdict` value object: the one answer an app acts on when a visitor follows a link, `allow`, `interstitial` or `block`, with the score, the reasons as translatable keys, the final address and the number of redirects. The link is followed through up to five redirects (each hop checked by `UrlGuard` and fetched pinned to the checked addresses, `HEAD` then `GET`, 4 s, a mobile User-Agent, the body never read), the final address is scored by `LinkSafety::assess()` so every existing signal counts, and the engine adds `redirect_chain` (25, three or more hops), `unresolved_destination` (50, no DNS answer, a private target, a transport error, an error status or too many hops; never a block alone), `executable_download` from the final `Content-Type`, `guest` (25), `guest_ip_host` (100) and `invalid_url` (100). Thresholds in `safety.verdict.thresholds`: 70 blocks, 30 shows an interstitial. `LinkVerdict` is a scoped binding.
- The `tunnel_host` signal (100, a blocking category): ngrok, Cloudflare quick tunnels, localtunnel, serveo and localhost.run hosts from `safety.tunnel_hosts`.
- The `brand_spoof` signal (55): an Indonesian bank, wallet or institution name from `safety.brand_keywords` in a host label or path segment of a host that is not in `safety.official_domains` (or a subdomain of one). A name of six characters or fewer matches whole labels and segments only, so `bandana.com` is not `dana`.
- `my.id` and `biz.id` in `safety.risky_tlds`; the list now matches the end of the host, so a multi-label suffix works, and it is the one source for the `risky_tld` signal.
- `tests/fixtures/verdict-cases.json` with the verdict expected for the live phishing hosts (`fnjphdp.com`, `bgp09.icu`), the official `t.me`, a bank name under a risky TLD, an `.apk` and a false-positive guard.

### Changed

- `executable_download` weighs 100 instead of 40, also matches `.ps1`, and is a blocking category together with `tunnel_host`, so `rejectionReason()` refuses an installer or a tunnel host at creation time.
- `risky_tld` weighs 30 instead of 5, the owner's design for the verdict. In the apps' `abuse:scan` (disable at 60) a risky TLD alone still disables nothing; with `random_subdomain` (30) it now does.

- A bundled domain blacklist (`resources/domain_blacklist.json`, the `domain_blacklist` table's row format) that `LinkSafety` reads next to the shared table, so a domain the package knows is blocked in every app without a database change. First entry: `fnjphdp.com` and its subdomains (phishing), the Telegram account-takeover kit that reached LinkHop on 2026-11-05 while Safe Browsing and URLhaus did not list it.
- Phishing keywords for that kit's page title, form and confirm button ("account security compliance", "two-step password", "yes, it was me", "restore your account status" and the like). `LinkSafety::assess()` now also matches every pattern against the address as a person reads it (path, query and fragment percent-decoded, `+` as a space), so a phrase in `/account-security-compliance` or `?t=yes%2C+it+was+me` counts like one in a page title.
- The `random_subdomain` signal (weight 30, not a blocking category): a label left of the registrable domain with seven or more characters and almost no vowels (`mjdgdqgk.example.com`), the throwaway hosts phishing kits hand out per victim. It raises the score and never disables anything by itself. Domains where such labels are normal (CDNs, object storage, `hel.ink`) are listed in `safety.known_domains` and never fire it.

- `EncryptedNoteLink` and the `encrypted_note_key` signal. `LinkSafety::rejectionReason()` refuses an address on a NoteHop host (`safety.note_hosts`, `HOP_NOTE_HOSTS`) whose fragment carries a `k` or `a` parameter, however a browser would read it (`#?k=`, full-width or percent-encoded host, one slash after the scheme, an Android intent fallback, and so on), in a URL and in free text. The class also offers `withoutFragment()`, `withoutSecrets()`, `scrub()` and `scrubJson()` for cleaning what apps already stored.
- `ext-intl` is now required (UTS 46 host mapping). Without it a host that is not plain ASCII is read as unknown, and an unknown host with a secret fragment is refused.
- `UrlGuard::resolve()` returns the checked target (scheme, host, port and public addresses) and `UrlGuard::curlResolve()` turns it into `CURLOPT_RESOLVE` entries, so an HTTP client can connect to the addresses that were checked instead of resolving the host again (DNS rebinding). `isSafe()` is now `resolve() !== null`.
- `safety.feed_max_bytes` (`THREAT_FEED_MAX_BYTES`, 64 MB by default): the largest threat feed `abuse:feeds` accepts.

### Changed

- `abuse:feeds` downloads to a temporary file, aborts a download that grows past the limit, checks that the file is a list of URLs, and replaces the old feed with an atomic rename. A failed, empty or oversized download keeps the previous feed. The command fails only when every feed failed.
- Rewrote the README. It now shows the real API (`LinkSafety::rejectionReason()` and `assess()`, `SafeContent::html()`), lists the feeds and online providers that exist (URLhaus, OpenPhish, Spamhaus DBL, Google Safe Browsing), and tells apps to require `dev-main` because there is no tag.
- Added this changelog.

### Fixed

- `LinkSafety::assess(..., remote: true)` sent the whole URL, fragment included, to Google Safe Browsing when `SAFE_BROWSING_KEY` was set, so the key of an encrypted note link left the server. The fragment is now cut before any outside lookup, and the cache key of the answer no longer includes it.
- `curlResolve()` returns one `host:port:address1,address2` entry instead of one entry per address. libcurl keeps only the last entry for a host and port, so the pin used to point at the last address only (an IPv6 address for most dual-stack hosts) and connections failed on machines without IPv6.

## Earlier history

- 2026-10-28: first version of the shared package, moved out of LinkHop: `LinkSafety`, `UrlGuard`, `SafeContent`, `DomainBlacklist` and `abuse:feeds`.
- 2026-10-30 to 2026-10-31: security policy and issue templates, a static checks workflow (`composer validate`, `php -l` on PHP 8.3), the canonical HOP family table with a CI check, and the product page.
