# hop/safety

Content and URL safety verification, threat feeds, and HTML sanitization for the **HOP** family of applications behind [hel.ink](https://hel.ink).

`hop/safety` protects the ecosystem against phishing, malware, malicious redirects, SSRF attempts, and XSS across short links (LinkHop) and bio pages (BioHop).

---

## Features

- **URL Guard (`UrlGuard`)**:
  - Restricts URL schemes to `http` and `https` (strictly blocks dangerous schemes like `javascript:`, `data:`, `file:`).
  - Protects against SSRF by disallowing private IP ranges, localhost, link-local, and loopback addresses.
- **Threat Detection (`LinkSafety`)**:
  - Checks target domains against the shared ecosystem database table `domain_blacklist`.
  - Integrates with external reputation feeds (URLhaus, PhishTank, Spamhaus DBL).
- **HTML Sanitization (`SafeContent`)**:
  - Sanitizes user-submitted rich content and markdown using Symfony HTML Sanitizer with strict safe element and attribute policies.
- **Threat Feed Sync (`abuse:feeds`)**:
  - Automated console command to keep local blacklist tables updated against real-time threat databases.

---

## Installation

Within a HOP ecosystem app, add the repository reference in `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/navi-crwn/hop-safety.git"
    }
],
"require": {
    "hop/safety": "^0.1.0"
}
```

Then run:

```bash
composer update hop/safety
```

The `Hop\Safety\SafetyServiceProvider` will auto-register with Laravel package discovery.

---

## Usage

### Validating a Destination URL

```php
use Hop\Safety\LinkSafety;

if (LinkSafety::isBlocked($url)) {
    return back()->withErrors(['url' => 'Target domain is flagged as dangerous or malicious.']);
}
```

### Sanitizing Rich HTML / Markdown Content

```php
use Hop\Safety\SafeContent;

$cleanHtml = SafeContent::sanitize($rawUserHtml);
```

### Updating Threat Feeds

Run via cron or task scheduler:

```bash
php artisan abuse:feeds
```

---

## HOP Family Ecosystem

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

---

## License

The HOP shared packages are open-source software licensed under the [MIT license](LICENSE).
