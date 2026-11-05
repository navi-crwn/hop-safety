# Changelog

All notable changes to `hop/safety` are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). The package has no tagged release yet, so everything is under Unreleased and apps require `dev-main`.

## [Unreleased]

### Added

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
