# hop/safety

The URL and content safety rules for the HOP family ([hel.ink](https://hel.ink)), as one Laravel package. LinkHop, BioHop and the hub use it, so a link or a bio page is judged the same way in every app.

## What it provides

| Class | What it does |
| --- | --- |
| `Hop\Safety\LinkSafety` | Judges a destination URL (and the text around it) against keyword patterns, risky hosts, the shared domain blacklist, local threat feeds and optional online lookups. |
| `Hop\Safety\EncryptedNoteLink` | Recognises an encrypted NoteHop address, whose key sits in the `#fragment`, so an app that stores destinations can refuse it. `LinkSafety` uses it. |
| `Hop\Safety\UrlGuard` | Decides whether the server may fetch a URL: http or https only, and every address the host resolves to must be public. It can also pin the connection to the addresses it checked. |
| `Hop\Safety\SafeContent` | Cleans user content before it is shown: sanitizes HTML, turns an unsafe link into `#`, and strips what could break out of a `<style>` element. |
| `Hop\Safety\Models\DomainBlacklist` | The Eloquent model of the `domain_blacklist` table. |
| `abuse:feeds` | Artisan command that downloads the URLhaus and OpenPhish feeds. |

## Requirements

- PHP 8.3, `ext-intl`, Laravel 12 (`illuminate/support` ^12.0) and `symfony/html-sanitizer` ^7.4. `ext-intl` is how `EncryptedNoteLink` reads a host the way a browser does.
- For the blacklist, a `domain_blacklist` table with `domain`, `match_type` (`exact` or `wildcard`) and `category`. LinkHop owns the table and the other apps only read it. Without the table `LinkSafety` only uses the list the package ships, `resources/domain_blacklist.json`, which has the same columns and is read next to the table.

## Installation

The package is not on Packagist and has no tagged release yet. Add the repository to the app's `composer.json` and require `dev-main`, pinning a commit if you want a fixed version:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/navi-crwn/hop-safety.git"
    }
],
"require": {
    "hop/safety": "dev-main"
}
```

Then run:

```bash
composer update hop/safety
```

Laravel's package discovery registers `Hop\Safety\SafetyServiceProvider`. It merges the `safety` config, binds `LinkSafety` as a scoped instance (so the blacklist is loaded once per request or job) and registers `abuse:feeds` when running in the console. To change the config, publish it:

```bash
php artisan vendor:publish --tag=hop-safety-config
```

## Usage

### Checking a destination URL

`LinkSafety::rejectionReason()` returns a message you can show to the user, or `null` when the URL is allowed. The second argument is text found near the URL (a title or description), the third is the list of allowed schemes. It defaults to `LinkSafety::WEB_SCHEMES` (`http`, `https`); `LinkSafety::BIO_SCHEMES` adds `mailto`, `tel` and `sms`.

```php
use Hop\Safety\LinkSafety;

$reason = app(LinkSafety::class)->rejectionReason($url, $title);
if ($reason !== null) {
    return back()->withErrors(['url' => $reason]);
}
```

A URL is rejected when its scheme is not allowed, when its host is missing, when it is an encrypted NoteHop address (see below), or when it matches a category listed in `safety.block_categories`. This call also asks the online providers (see below).

`assess()` gives the detail instead of a verdict: a score and the list of signals found. It skips the online providers unless you pass `remote: true`.

```php
$result = app(LinkSafety::class)->assess($url, $text);
// for example ['score' => 110, 'signals' => ['gambling', 'chained_shortener', 'executable_download']]
```

The signals are `csam`, `gambling`, `phishing`, `scam` and `adult` (keyword patterns), `dangerous_scheme` (`javascript:`, `data:`, `vbscript:`, `file:`, `blob:`), `ip_host`, `punycode`, `chained_shortener`, `file_host`, `risky_tld`, `executable_download`, `blacklisted` (plus the blacklist row's own category), `malware` and `phishing` from the feeds, and `malware` from the online providers. Each signal adds its weight from `safety.weights` to the score.

### Encrypted NoteHop links

A NoteHop link keeps the note key in the fragment (`https://n.hel.ink/TOKEN#k=KEY`, and `&a=` for the owner link). Browsers never send a fragment to a server, which is why NoteHop cannot read the note. An app that stores such a link as a destination, a bio link or a report would hold the key in its database, its logs and its redirects. `rejectionReason()` therefore refuses a NoteHop address whose fragment has a `k` or `a` parameter with a value, in a URL and in free text, and `assess()` reports it as the `encrypted_note_key` signal (weight 0, it is refused when saved and never scored).

The address is read the way a browser reads it, not the way `parse_url()` does: tabs and line breaks dropped, control characters trimmed, any number of slashes after `https:`, a backslash ending the host, the last `@` ending the user info, percent escapes, full-width letters, `U+3002`, soft hyphens and a trailing dot all reach the host the browser would connect to, and `#?k=KEY` is the `k` parameter because `URLSearchParams` drops one `?`. An Android `intent://` address is searched for a NoteHop fallback address. Other hosts, other fragments and `?k=` in the query (which NoteHop does not read) are ordinary destinations.

The hosts are `safety.note_hosts` (`HOP_NOTE_HOSTS`, default `note.hel.ink,n.hel.ink`). An app can add more, for example from its app registry, with `EncryptedNoteLink::resolveHostsUsing(fn () => [...])`.

The same class helps apps clean what they already hold: `withoutFragment($url)`, `withoutSecrets($url)` (only `k` and `a` leave the fragment), and `scrub($text)` and `scrubJson($json)` (every NoteHop address in a text or a JSON column; other hosts' fragments stay).

### Fetching a user-supplied URL

`UrlGuard::isSafe($url)` answers whether a URL may be fetched from the server. It accepts only `http` and `https`, resolves the host, and refuses the URL if any resolved address is private or reserved. That covers loopback, link-local (including cloud metadata addresses such as `169.254.169.254`) and the private ranges.

`isSafe()` only gives a yes or no. If you then hand the URL to an HTTP client, the client looks the host up again, and a hostile DNS server can answer differently the second time (DNS rebinding). To close that gap, call `resolve()` once and make the client connect to the addresses that were checked:

```php
use Hop\Safety\UrlGuard;
use Illuminate\Support\Facades\Http;

$guard = app(UrlGuard::class);
$target = $guard->resolve($url);   // null when the URL is not safe
if ($target === null) {
    throw new RuntimeException('Address not allowed');
}
// ['scheme' => 'https', 'host' => 'example.com', 'port' => 443, 'ips' => ['93.184.216.34', ...]]

$response = Http::withOptions([
    'allow_redirects' => false,
    'curl' => [CURLOPT_RESOLVE => $guard->curlResolve($target)],
])->get($url);
```

`curlResolve()` returns a single `host:port:address1,address2` entry that lists every checked address. Follow redirects yourself and call `resolve()` again for every `Location`. `isSafe()` is `resolve() !== null`.

All addresses go into one entry because libcurl keeps only the last entry for a given host and port; with one entry per address the request would be pinned to the last address only, and fail on a machine without IPv6. With one entry, libcurl tries the checked addresses and the request never goes to an address that was not checked.

### Sanitizing user content

```php
use Hop\Safety\SafeContent;

$html = SafeContent::html($rawUserHtml);
$href = SafeContent::url($userUrl);          // '#' unless the scheme is allowed
$css  = SafeContent::css($userCss);
```

- `html()` keeps formatting tags and drops scripts, event handlers, styles, forms and links that are not `http`, `https`, `mailto` or `tel`. Links get `rel="nofollow noreferrer noopener"` and `target="_blank"`. Input is cut at 50,000 characters.
- `url()` returns a same-site path as is, and any other value only if its scheme is in the allowed list (by default `LinkSafety::BIO_SCHEMES`). Everything else becomes `#`.
- `css()` removes `<`, `@import`, `expression(`, `javascript:`, `behavior:` and `-moz-binding`, so custom CSS cannot close its `<style>` element or load scripts.
- `isSitePath()` tells a real same-site path from one a browser would read as another host, such as `/\evil.com`.

Apply these when rendering, not only when saving, so rows stored before a rule existed are covered.

### Threat feeds

```bash
php artisan abuse:feeds
```

The command downloads the URLhaus online malware list and the OpenPhish community feed into `storage/app/threat-feeds/malware.txt` and `phishing.txt`. `LinkSafety` matches a URL against them exactly (scheme and a leading `www.` ignored), so a shared platform is not blocked as a whole. LinkHop runs the command daily.

A feed is downloaded to a temporary file, checked and then moved over the old one. A failed, truncated, empty or non-URL download keeps the previous list. A download larger than `safety.feed_max_bytes` is aborted. The command fails only when every feed failed.

### Online lookups

When `rejectionReason()` runs (or `assess(..., remote: true)`), three optional providers are asked, and a hit adds the `malware` signal. Answers are cached for `safety.providers.cache_hours` (12). A provider that is unreachable or returns an error never blocks a URL.

- Spamhaus DBL over DNS, on by default (`SPAMHAUS_DBL`). It needs a DNS resolver that Spamhaus accepts, which public resolvers are not.
- Google Safe Browsing, used when `SAFE_BROWSING_KEY` is set. Only the address without its `#fragment` is sent (a fragment is never sent to a server, so it is not told to a third party either), and the cached answer is keyed the same way.
- URLhaus host lookup, used when `URLHAUS_AUTH_KEY` is set.

## Configuration

| Variable | Default | Meaning |
| --- | --- | --- |
| `SAFE_BROWSING_KEY` | none | Enables the Google Safe Browsing lookup. |
| `URLHAUS_AUTH_KEY` | none | Enables the URLhaus host lookup. |
| `SPAMHAUS_DBL` | `true` | Spamhaus DBL lookup over DNS. |
| `THREAT_FEED_MAX_BYTES` | `67108864` (64 MB) | Largest feed `abuse:feeds` accepts. |
| `HOP_NOTE_HOSTS` | `note.hel.ink,n.hel.ink` | Comma-separated NoteHop hosts whose links carry the key in the fragment (self-hosted installs add theirs). |
| `ABUSE_REGISTER_PER_HOUR` | `3` | Read by the apps, not by this package. |

The rest lives in `config/safety.php`: the keyword `patterns`, `block_categories`, the `weights` of each signal, and the lists `shorteners`, `file_hosts` and `risky_tlds`. The keys `thresholds`, `suspend_after_disabled_items` and `purge_after_days` are read by the `abuse:scan` command of LinkHop and BioHop, not by this package.

## Tests

The tests in `tests/Feature` are Pest tests that run inside an app that uses the package (HEL.ink includes a copy in `packages/safety`). The tests in `tests/Unit` need no application. `tests/fixtures/note-link-variants.json` holds 64 spellings of a NoteHop address with the verdict of a WHATWG URL parser, produced by `tests/fixtures/note-link-truth.js` (Node). This repository's own CI checks that `composer.json` is valid, that every PHP file lints under PHP 8.3, and that the family table below matches the canonical one.

## Security

Report vulnerabilities as described in [SECURITY.md](SECURITY.md), not in public issues.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## HOP family

<!-- hop-family:start -->
<!-- Generated from HEL.ink resources/hop-assets/apps.source.json; edit there, then run scripts/hop_assets.py build. -->
| App | Address | Repository | What it does |
| --- | --- | --- | --- |
| **HOP ID** | [hel.ink](https://hel.ink) · accounts at `hub.hel.ink` | [navi-crwn/HEL.ink](https://github.com/navi-crwn/HEL.ink) | One account for every HOP app. |
| **LinkHop** | [link.hel.ink](https://link.hel.ink) · `hel.ink/l/…` | [navi-crwn/LinkHop](https://github.com/navi-crwn/LinkHop) | Short links, QR codes and click analytics. |
| **BioHop** | [bio.hel.ink](https://bio.hel.ink) · `hel.ink/b/…` | [navi-crwn/BioHop](https://github.com/navi-crwn/BioHop) | One link-in-bio page for everything you do. |
| **PixelHop** | [pixel.hel.ink](https://pixel.hel.ink) · `p.hel.ink/…` | [navi-crwn/PixelHop](https://github.com/navi-crwn/PixelHop) | Image hosting with albums and direct links. |
| **NoteHop** | [note.hel.ink](https://note.hel.ink) · `n.hel.ink/…` | [navi-crwn/NoteHop](https://github.com/navi-crwn/NoteHop) | Encrypted notes you share with a link. |
| **Imperium** | private (owner only) | private | The HOP control panel. |
<!-- hop-family:end -->

## License

The HOP shared packages are open-source software licensed under the [MIT license](LICENSE).
