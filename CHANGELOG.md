# Changelog

All notable changes to `hop/safety` are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). The package has no tagged release yet, so everything is under Unreleased and apps require `dev-main`.

## [Unreleased]

### Added

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
