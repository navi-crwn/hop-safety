# hop/safety

[English](README.md) · [简体中文](README.zh-CN.md) · **Deutsch** · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md) · [Português (Brasil)](README.pt-BR.md) · [Español](README.es.md) · [Français](README.fr.md) · [Русский](README.ru.md)

Die Sicherheitsregeln für URLs und Inhalte der HOP-Familie ([hel.ink](https://hel.ink)) als ein Laravel-Paket. LinkHop, BioHop und der Hub nutzen es, damit ein Link oder eine Bio-Seite in jeder App gleich beurteilt wird.

## Was es bietet

| Klasse | Was sie tut |
| --- | --- |
| `Hop\Safety\LinkSafety` | Beurteilt eine Ziel-URL (und den Text um sie herum) anhand von Schlüsselwortmustern, riskanten Hosts, Markenimitation, der gemeinsamen Domain-Blacklist, lokalen Bedrohungsfeeds und optionalen Online-Abfragen. |
| `Hop\Safety\LinkVerdict` | Folgt einem Link durch seine Weiterleitungen und macht aus der Punktzahl eine einzige Antwort, ein `Verdict`: `allow`, `interstitial` oder `block`, mit den Gründen, der Endadresse und der Zahl der Hops. |
| `Hop\Safety\EncryptedNoteLink` | Erkennt eine verschlüsselte NoteHop-Adresse, deren Schlüssel im `#fragment` steht, damit eine App, die Ziele speichert, sie ablehnen kann. `LinkSafety` nutzt sie. |
| `Hop\Safety\DeepLinks` | Macht aus einem https-Ziel auf dem Host einer bekannten App (YouTube, Spotify, WhatsApp, ...) die `intent://`- und App-Schema-Adressen, die die App öffnen, mit der Originaladresse als Fallback. Liefert einen `DeepLink`. |
| `Hop\Safety\UrlGuard` | Entscheidet, ob der Server eine URL abrufen darf: nur http oder https, und jede Adresse, zu der der Host auflöst, muss öffentlich sein. Er kann die Verbindung auch an die geprüften Adressen binden. |
| `Hop\Safety\SafeContent` | Bereinigt Benutzerinhalte vor der Anzeige: säubert HTML, macht aus einem unsicheren Link `#` und entfernt, was aus einem `<style>`-Element ausbrechen könnte. |
| `Hop\Safety\Models\DomainBlacklist` | Das Eloquent-Modell der Tabelle `domain_blacklist`. |
| `abuse:feeds` | Artisan-Befehl, der die Feeds von URLhaus und OpenPhish herunterlädt. |

## Voraussetzungen

- PHP 8.3, `ext-intl`, Laravel 12 (`illuminate/support` ^12.0) und `symfony/html-sanitizer` ^7.4. Mit `ext-intl` liest `EncryptedNoteLink` einen Host so, wie ein Browser es tut.
- Für die Blacklist eine Tabelle `domain_blacklist` mit `domain`, `match_type` (`exact` oder `wildcard`) und `category`. Die Tabelle gehört LinkHop, die anderen Apps lesen sie nur. Ohne die Tabelle nutzt `LinkSafety` nur die Liste, die das Paket mitliefert, `resources/domain_blacklist.json`. Sie hat dieselben Spalten und wird zusätzlich zur Tabelle gelesen.

## Installation

Das Paket ist nicht auf Packagist und hat noch kein getaggtes Release. Füge das Repository zur `composer.json` der App hinzu und trage `dev-main` als Abhängigkeit ein. Wenn du eine feste Version willst, pinne einen Commit:

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

Dann führe aus:

```bash
composer update hop/safety
```

Die Package Discovery von Laravel registriert `Hop\Safety\SafetyServiceProvider`. Er führt die Konfiguration `safety` zusammen, bindet `LinkSafety` als Scoped-Instanz (so wird die Blacklist einmal pro Request oder Job geladen) und registriert `abuse:feeds`, wenn er in der Konsole läuft. Um die Konfiguration zu ändern, veröffentliche sie:

```bash
php artisan vendor:publish --tag=hop-safety-config
```

## Verwendung

### Eine Ziel-URL prüfen

`LinkSafety::rejectionReason()` gibt eine Meldung zurück, die du dem Benutzer zeigen kannst, oder `null`, wenn die URL erlaubt ist. Das zweite Argument ist Text in der Nähe der URL (ein Titel oder eine Beschreibung), das dritte die Liste der erlaubten Schemas. Standard ist `LinkSafety::WEB_SCHEMES` (`http`, `https`); `LinkSafety::BIO_SCHEMES` fügt `mailto`, `tel` und `sms` hinzu.

```php
use Hop\Safety\LinkSafety;

$reason = app(LinkSafety::class)->rejectionReason($url, $title);
if ($reason !== null) {
    return back()->withErrors(['url' => $reason]);
}
```

Eine URL wird abgelehnt, wenn ihr Schema nicht erlaubt ist, wenn ihr Host fehlt, wenn sie eine verschlüsselte NoteHop-Adresse ist (siehe unten) oder wenn sie zu einer Kategorie aus `safety.block_categories` passt. Dieser Aufruf fragt auch die Online-Anbieter (siehe unten).

`assess()` liefert die Details statt eines Urteils: eine Punktzahl und die Liste der gefundenen Signale. Die Online-Anbieter werden übersprungen, außer du übergibst `remote: true`.

```php
$result = app(LinkSafety::class)->assess($url, $text);
// for example ['score' => 110, 'signals' => ['gambling', 'chained_shortener', 'executable_download']]
```

Die Signale sind `csam`, `gambling`, `phishing`, `scam` und `adult` (Schlüsselwortmuster), `dangerous_scheme` (`javascript:`, `data:`, `vbscript:`, `file:`, `blob:`), `ip_host`, `punycode`, `chained_shortener`, `file_host`, `risky_tld`, `tunnel_host`, `brand_spoof`, `random_subdomain`, `executable_download`, `blacklisted` (plus die eigene Kategorie der Blacklist-Zeile), `malware` und `phishing` aus den Feeds sowie `malware` von den Online-Anbietern. Jedes Signal addiert sein Gewicht aus `safety.weights` zur Punktzahl.

- `risky_tld`: Der Host endet auf einen Eintrag aus `safety.risky_tlds`. Das kann ein Public Suffix mit mehr als einem Label sein (`my.id`, `biz.id`).
- `tunnel_host`: Der Host ist einer aus `safety.tunnel_hosts` oder eine Subdomain davon (ngrok, Cloudflare quick tunnels, localtunnel, serveo, localhost.run). Eine harte Sperre: Eine Seite dort lebt einen Nachmittag lang, und keine Reputationsliste kann sie kennen.
- `brand_spoof`: Ein Label des Hosts oder ein Pfadsegment enthält den Namen einer indonesischen Bank, Wallet oder Institution aus `safety.brand_keywords` (`klikbca`, `brimo`, `livin`, `mandiri`, `bni`, `bsi`, `dana`, `kaget`, `ovo`, `gopay`, `shopeepay`, `telegram`, `whatsapp`, `kominfo`, `undian`, `blokir`, `tarif`), und der Host ist keiner aus `safety.official_domains` und keine Subdomain davon. Ein Name mit sechs oder weniger Zeichen passt nur auf ein ganzes Label oder Segment (`bni.example.com`, `/dana`), daher passen `bandana.com` oder `omnibus` nie; ein längerer Name passt auch innerhalb davon (`bankmandiri-login`, `telegramweb`). Der Pfad wird prozentdekodiert gelesen. Die offizielle Liste (`klikbca.com`, `bca.co.id`, `bri.co.id`, `bankmandiri.co.id`, `bni.co.id`, `bankbsi.co.id`, `dana.id`, `ovo.id`, `gopay.co.id`, `shopee.co.id`, `telegram.org`, `t.me`, `whatsapp.com`, `wa.me`, `kominfo.go.id`, `komdigi.go.id`) deckt nur die Domain und ihre Subdomains ab: `klikbca.com.example.xyz` und `notklikbca.com` sind Fälschungen.
- `executable_download`: Die Adresse endet auf `.apk`, `.exe`, `.scr`, `.msi`, `.bat`, `.cmd`, `.ps1`, `.vbs` oder `.jar`. Eine harte Sperre.

### Das Link-Urteil

`LinkVerdict::evaluate()` ist die eine Antwort, nach der die Apps handeln, wenn ein Besucher einem Link folgt: weiterleiten, zuerst eine Warnseite zeigen oder ablehnen. Es folgt dem Link durch seine Weiterleitungen, bewertet die Adresse, auf der er landet (und die eingereichte), mit `LinkSafety::assess()`, ergänzt, was nur der Abruf zeigen kann, und macht aus der Summe ein Urteil.

```php
use Hop\Safety\LinkVerdict;
use Hop\Safety\Verdict;

$verdict = app(LinkVerdict::class)->evaluate($url, guest: $link->user_id === null, text: $link->title);

if ($verdict->blocks()) {
    abort(451);
}
if ($verdict->needsInterstitial()) {
    return view('interstitial', ['destination' => $verdict->finalUrl, 'reasons' => $verdict->reasons]);
}
return redirect()->away($verdict->finalUrl);
```

`Verdict` ist ein Value Object:

| Eigenschaft | Bedeutung |
| --- | --- |
| `verdict` | `Verdict::ALLOW` (`allow`), `Verdict::INTERSTITIAL` (`interstitial`) oder `Verdict::BLOCK` (`block`). `allows()`, `needsInterstitial()` und `blocks()` sagen dasselbe. |
| `score` | Die Summe von `safety.weights` für jeden Grund. |
| `reasons` | Die gefundenen Signale, jedes einmal, als Schlüssel, die die App übersetzt (`blacklisted`, `brand_spoof`, `redirect_chain`, `guest`, ...), nie als Fließtext. |
| `finalUrl` | Die Adresse, auf der der Link nach seinen Weiterleitungen landet, oder die eingereichte Adresse, wenn nichts verfolgt wurde. |
| `hops` | Wie viele Weiterleitungen verfolgt wurden. |

`toArray()` liefert dasselbe mit `final_url` als Schlüssel, für eine JSON-Antwort oder eine Logzeile.

Die Argumente: `guest` ist true, wenn der Link ohne Konto erstellt wurde; `text` ist der Titel oder die Beschreibung um den Link; `unroll: false` lässt das Netzwerk ganz weg und beurteilt nur die Adresse (für eine Prüfung beim Erstellen oder einen Scan über gespeicherte Links); `remote: false` lässt die Online-Anbieter weg.

So wird dem Link gefolgt:

- Jeder Hop geht zuerst durch `UrlGuard::resolve()`, und die Anfrage wird an die geprüften Adressen gebunden. Ein Hop, dessen Host keine DNS-Antwort hat oder zu einer privaten oder lokalen Adresse auflöst, wird nie abgerufen und beendet die Kette als nicht aufgelöst.
- Jeder Hop ist eine `HEAD`-Anfrage und eine `GET`-Anfrage, wenn der Server `HEAD` ablehnt oder die Anfrage fehlschlägt. Der Body wird nie gelesen: Die Antwort wird gestreamt, und nur Status und Header werden genutzt. Timeout `safety.verdict.timeout` (4 s) pro Hop, mit dem mobilen User-Agent aus `safety.verdict.user_agent`, weil Phishing-Kits einem Desktop-Browser oft eine harmlose Seite zeigen.
- Bis zu `safety.verdict.max_hops` (5) Weiterleitungen werden verfolgt; relative `Location`-Header werden gegen den Hop aufgelöst, der sie gesendet hat. Mehr Weiterleitungen, ein Transportfehler oder ein Fehlerstatus (4xx, 5xx) beim letzten Hop zählen als nicht aufgelöst.

Die Signale, die nur das Urteil hinzufügt:

| Grund | Gewicht | Wann |
| --- | --- | --- |
| `redirect_chain` | 25 | `safety.verdict.chain_hops` (3) oder mehr Weiterleitungen. |
| `unresolved_destination` | 50 | Das Ziel konnte nicht überprüft werden (siehe oben). Allein keine Sperre. |
| `executable_download` | 100 | Der finale `Content-Type` ist einer aus `safety.verdict.executable_types` (ein Android-Paket, eine ausführbare Windows-Datei oder ein Installer), egal wie die Adresse aussieht. |
| `guest` | 25 | Der Link wurde von einem Gast erstellt. |
| `guest_ip_host` | 100 | Der Link eines Gasts, dessen Host eine nackte IP-Adresse ist. |
| `invalid_url` | 100 | Keine Webadresse: kein Schema, kein Host oder ein anderes Schema als `http` und `https`. Es wird nichts abgerufen. |

Die Schwellenwerte stehen in `safety.verdict.thresholds`: Eine Punktzahl ab 70 ist `block`, ab 30 `interstitial`, alles darunter `allow`. Mit den Standardgewichten ist der Link eines Gasts auf eine `.xyz`-Domain ein Interstitial (30 + 25), ein Bankname auf einem inoffiziellen Host unter einer riskanten TLD ist für alle eine Sperre (55 + 30), und eine `.apk`, ein Tunnel-Host, eine Domain auf der Blacklist oder der Link eines Gasts auf eine nackte IP sind schon allein eine Sperre.

`LinkVerdict` ist wie `LinkSafety` als Scoped-Instanz gebunden. Es cacht nicht; die Apps entscheiden, wann ein gespeicherter Link neu geprüft wird.

### Verschlüsselte NoteHop-Links

Ein NoteHop-Link trägt den Notizschlüssel im Fragment (`https://n.hel.ink/TOKEN#k=KEY` und `&a=` für den Besitzer-Link). Browser senden ein Fragment nie an einen Server, deshalb kann NoteHop die Notiz nicht lesen. Eine App, die so einen Link als Ziel, Bio-Link oder Meldung speichert, hätte den Schlüssel in ihrer Datenbank, ihren Logs und ihren Weiterleitungen. Darum lehnt `rejectionReason()` eine NoteHop-Adresse ab, deren Fragment einen Parameter `k` oder `a` mit Wert hat, in einer URL und in freiem Text, und `assess()` meldet sie als Signal `encrypted_note_key` (Gewicht 0: Sie wird beim Speichern abgelehnt und nie bewertet).

Die Adresse wird gelesen, wie ein Browser sie liest, nicht wie `parse_url()` es tut: Tabs und Zeilenumbrüche fallen weg, Steuerzeichen werden abgeschnitten, beliebig viele Schrägstriche nach `https:` sind erlaubt, ein Backslash beendet den Host, das letzte `@` beendet die Benutzerinfo, und Prozent-Escapes, Vollbreitenbuchstaben, `U+3002`, weiche Trennstriche und ein Punkt am Ende führen alle zu dem Host, mit dem sich der Browser verbinden würde. `#?k=KEY` ist der Parameter `k`, weil `URLSearchParams` ein `?` verwirft. In einer Android-Adresse `intent://` wird nach einer NoteHop-Fallback-Adresse gesucht. Andere Hosts, andere Fragmente und `?k=` in der Query (die NoteHop nicht liest) sind normale Ziele.

Die Hosts sind `safety.note_hosts` (`HOP_NOTE_HOSTS`, Standard `note.hel.ink,n.hel.ink`). Eine App kann weitere hinzufügen, zum Beispiel aus ihrer App-Registry, mit `EncryptedNoteLink::resolveHostsUsing(fn () => [...])`.

Dieselbe Klasse hilft Apps, zu bereinigen, was sie schon gespeichert haben: `withoutFragment($url)`, `withoutSecrets($url)` (nur `k` und `a` werden aus dem Fragment entfernt) sowie `scrub($text)` und `scrubJson($json)` (jede NoteHop-Adresse in einem Text oder einer JSON-Spalte; Fragmente anderer Hosts bleiben).

### In der App öffnen (Deep Links)

`DeepLinks::resolve($url)` macht aus einem `https://`-Ziel auf dem Host einer bekannten App (YouTube, Spotify, WhatsApp, Telegram, Instagram, X, TikTok, Zoom, App Store, Google Play) einen `DeepLink`: `app`, `name`, `ios` und `android` (jeweils `null`, wenn es für die Plattform keine Adresse gibt) sowie `fallback`, die übergebene Adresse. Alles andere ergibt `null`. Auf welcher Plattform ein Besucher ist, entscheidet der Aufrufer.

```php
use Hop\Safety\DeepLinks;

// A redirect (LinkHop): $url is the destination, already checked; $os is 'ios' or 'android' from your own user-agent detection.
$deep = app(DeepLinks::class)->resolve($url);
if ($deep && ($address = $deep->forPlatform($os))) {
    return response()->view('open-in-app', ['app' => $deep->name, 'open' => $address, 'fallback' => $deep->fallback]);
}

return redirect()->away($url);
```

```php
// A block renderer (BioHop): the link always works without script, the app addresses ride along as data attributes.
$deep = app(DeepLinks::class)->resolve($block['url']);
```

```blade
<a href="{{ $deep?->fallback ?? $block['url'] }}" @if ($deep) data-app="{{ $deep->name }}" data-ios="{{ $deep->ios }}" data-android="{{ $deep->android }}" @endif>{{ $block['title'] }}</a>
```

Gelesen wird nur eine `https://`-Adresse, und nur ihr Host wählt die App: nie ein getipptes Schema, keine Benutzerinfo, kein Port, keine IP-Adresse, kein Punkt am Ende, kein Host, der nur ähnlich aussieht, kein Leerzeichen, Steuerzeichen oder Backslash und nichts, was länger ist als `safety.deep_links.max_url_length` (2048). Der Pfad muss als Ganzes zur Route passen, alles Übernommene wird Stück für Stück prozentcodiert (`;`, `#`, Anführungszeichen und Zeilenumbrüche können eine `intent://`-Adresse also nicht verlassen), und das Ergebnis wird noch einmal gegen eine strenge Form geprüft.

Die bekannten Apps sind Daten in `resources/deep-link-apps.php`. Eine App kann über `safety.deep_links.apps` Einträge hinzufügen, ersetzen oder entfernen (gleiche Form, nach Schlüssel zusammengeführt, `false` entfernt); ein ungültiger Eintrag wird ignoriert.

```php
// config/safety.php
'deep_links' => [
    'apps' => [
        'example-chat' => [
            'name' => 'Example Chat',
            'hosts' => ['chat.example.com'],
            'android_package' => 'com.example.chat',
            'routes' => [[
                'path' => '#^/room/(?<room>[a-z0-9]{3,20})$#',
                'args' => ['room' => 'room'],
                'ios' => 'examplechat://join{args}',
                'android' => ['intent' => 'join{args}', 'scheme' => 'examplechat'],
            ]],
        ],
        'tiktok' => false, // remove a shipped app
    ],
],
```

### Eine vom Benutzer gelieferte URL abrufen

`UrlGuard::isSafe($url)` beantwortet, ob eine URL vom Server abgerufen werden darf. Es akzeptiert nur `http` und `https`, löst den Host auf und lehnt die URL ab, wenn eine aufgelöste Adresse privat oder reserviert ist. Das umfasst Loopback, Link-Local (auch Cloud-Metadatenadressen wie `169.254.169.254`) und die privaten Bereiche.

`isSafe()` gibt nur ja oder nein zurück. Wenn du die URL danach einem HTTP-Client übergibst, schlägt der Client den Host erneut nach, und ein feindlicher DNS-Server kann beim zweiten Mal anders antworten (DNS-Rebinding). Um diese Lücke zu schließen, rufe `resolve()` einmal auf und lass den Client zu den geprüften Adressen verbinden:

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

`curlResolve()` gibt einen einzelnen Eintrag `host:port:address1,address2` zurück, der jede geprüfte Adresse auflistet. Folge Weiterleitungen selbst und rufe `resolve()` für jede `Location` erneut auf. `isSafe()` ist `resolve() !== null`.

Alle Adressen kommen in einen Eintrag, weil libcurl für einen Host und Port nur den letzten Eintrag behält. Mit einem Eintrag pro Adresse wäre die Anfrage nur an die letzte Adresse gebunden und würde auf einem Rechner ohne IPv6 fehlschlagen. Mit einem Eintrag probiert libcurl die geprüften Adressen, und die Anfrage geht nie an eine ungeprüfte Adresse.

### Benutzerinhalte bereinigen

```php
use Hop\Safety\SafeContent;

$html = SafeContent::html($rawUserHtml);
$href = SafeContent::url($userUrl);          // '#' unless the scheme is allowed
$css  = SafeContent::css($userCss);
```

- `html()` behält Formatierungstags und entfernt Skripte, Event-Handler, Styles, Formulare und Links, die nicht `http`, `https`, `mailto` oder `tel` sind. Links bekommen `rel="nofollow noreferrer noopener"` und `target="_blank"`. Eingaben werden bei 50.000 Zeichen abgeschnitten.
- `url()` gibt einen Pfad auf derselben Site unverändert zurück und jeden anderen Wert nur, wenn sein Schema in der erlaubten Liste steht (standardmäßig `LinkSafety::BIO_SCHEMES`). Alles andere wird zu `#`.
- `css()` entfernt `<`, `@import`, `expression(`, `javascript:`, `behavior:` und `-moz-binding`, damit eigenes CSS sein `<style>`-Element nicht schließen und keine Skripte laden kann.
- `isSitePath()` unterscheidet einen echten Pfad auf derselben Site von einem, den ein Browser als anderen Host lesen würde, etwa `/\evil.com`.

Wende sie beim Rendern an, nicht nur beim Speichern, damit auch Zeilen abgedeckt sind, die gespeichert wurden, bevor es eine Regel gab.

### Bedrohungsfeeds

```bash
php artisan abuse:feeds
```

Der Befehl lädt die URLhaus-Liste aktiver Malware-URLs und den OpenPhish-Community-Feed nach `storage/app/threat-feeds/malware.txt` und `phishing.txt` herunter. `LinkSafety` vergleicht eine URL exakt mit ihnen (Schema und ein führendes `www.` werden ignoriert), damit eine gemeinsam genutzte Plattform nicht als Ganzes gesperrt wird. LinkHop führt den Befehl täglich aus.

Ein Feed wird in eine temporäre Datei geladen, geprüft und dann über die alte Datei verschoben. Ein fehlgeschlagener, abgeschnittener oder leerer Download oder einer ohne URLs behält die vorherige Liste. Ein Download größer als `safety.feed_max_bytes` wird abgebrochen. Der Befehl schlägt nur fehl, wenn jeder Feed fehlgeschlagen ist.

### Online-Abfragen

Wenn `rejectionReason()` läuft (oder `assess(..., remote: true)`), werden drei optionale Anbieter gefragt, und ein Treffer fügt das Signal `malware` hinzu. Antworten werden für `safety.providers.cache_hours` (12) gecacht. Ein Anbieter, der nicht erreichbar ist oder einen Fehler zurückgibt, sperrt nie eine URL.

- Spamhaus DBL über DNS, standardmäßig an (`SPAMHAUS_DBL`). Es braucht einen DNS-Resolver, den Spamhaus akzeptiert. Öffentliche Resolver gehören nicht dazu.
- Google Safe Browsing, genutzt, wenn `SAFE_BROWSING_KEY` gesetzt ist. Gesendet wird nur die Adresse ohne ihr `#fragment` (ein Fragment wird nie an einen Server gesendet, also auch keinem Dritten mitgeteilt), und die gecachte Antwort nutzt denselben Schlüssel.
- URLhaus-Host-Abfrage, genutzt, wenn `URLHAUS_AUTH_KEY` gesetzt ist.

## Konfiguration

| Variable | Standard | Bedeutung |
| --- | --- | --- |
| `SAFE_BROWSING_KEY` | keiner | Aktiviert die Abfrage bei Google Safe Browsing. |
| `URLHAUS_AUTH_KEY` | keiner | Aktiviert die URLhaus-Host-Abfrage. |
| `SPAMHAUS_DBL` | `true` | Spamhaus-DBL-Abfrage über DNS. |
| `THREAT_FEED_MAX_BYTES` | `67108864` (64 MB) | Größter Feed, den `abuse:feeds` akzeptiert. |
| `HOP_NOTE_HOSTS` | `note.hel.ink,n.hel.ink` | Kommagetrennte NoteHop-Hosts, deren Links den Schlüssel im Fragment tragen (selbst gehostete Installationen fügen ihre eigenen hinzu). |
| `ABUSE_REGISTER_PER_HOUR` | `3` | Wird von den Apps gelesen, nicht von diesem Paket. |

Der Rest steht in `config/safety.php`: die Schlüsselwortmuster `patterns`, `block_categories`, die Gewichte `weights` jedes Signals, die Listen `shorteners`, `file_hosts`, `risky_tlds`, `tunnel_hosts`, `brand_keywords`, `official_domains` und `known_domains` sowie die Einstellungen unter `verdict` (`thresholds`, `max_hops`, `chain_hops`, `timeout`, `user_agent`, `executable_types`). Die Schlüssel `thresholds`, `suspend_after_disabled_items` und `purge_after_days` liest der Befehl `abuse:scan` von LinkHop und BioHop, nicht dieses Paket.

## Tests

Die Tests in `tests/Feature` sind Pest-Tests, die innerhalb einer App laufen, die das Paket nutzt (HEL.ink enthält eine Kopie in `packages/safety`). Die Tests in `tests/Unit` brauchen keine Anwendung. `tests/fixtures/note-link-variants.json` enthält 64 Schreibweisen einer NoteHop-Adresse mit dem Urteil eines WHATWG-URL-Parsers, erzeugt von `tests/fixtures/note-link-truth.js` (Node). `tests/fixtures/verdict-cases.json` listet Adressen mit dem jeweils erwarteten Urteil auf, darunter die Phishing-Hosts, die LinkHop erreicht haben. Die Urteilstests simulieren DNS und HTTP und berühren nie das Netzwerk. Die eigene CI dieses Repositorys prüft, dass `composer.json` gültig ist, dass jede PHP-Datei unter PHP 8.3 den Lint besteht und dass die Familientabelle unten der kanonischen entspricht.

## Sicherheit

Melde Schwachstellen wie in [SECURITY.md](SECURITY.md) beschrieben, nicht in öffentlichen Issues.

## Änderungsprotokoll

Siehe [CHANGELOG.md](CHANGELOG.md).

## Selbst hosten

Um HOP zu betreiben, installierst du dieses Paket nicht von Hand. LinkHop und BioHop führen dieses öffentliche Repository in ihrer `composer.json`, daher holt `composer install` es ohne Token, und der Hub ([HEL.ink](https://github.com/navi-crwn/HEL.ink)) enthält eine Kopie in `packages/safety`. Es funktioniert ganz ohne Schlüssel: Safe Browsing und URLhaus bleiben aus, bis du `SAFE_BROWSING_KEY` und `URLHAUS_AUTH_KEY` setzt, die Spamhaus-Prüfung ist eine DNS-Abfrage ohne Schlüssel (`SPAMHAUS_DBL=false` schaltet sie ab), und ohne die Tabelle `domain_blacklist` von LinkHop nutzt es die mitgelieferte Liste. Wenn du NoteHop betreibst, setze `HOP_NOTE_HOSTS` auf dessen Hostnamen, damit verschlüsselte Notiz-Links auf deiner Domain erkannt werden. Standard sind die Hosts von hel.ink. Den Rest beschreibt die Anleitung der Familie: [docs/self-hosting.md](https://github.com/navi-crwn/HEL.ink/blob/main/docs/self-hosting.md).

## HOP-Familie

<!-- hop-family:start -->
<!-- Generated from HEL.ink resources/hop-assets/apps.source.json; edit there, then run scripts/hop_assets.py build. -->
| App | Address | Repository | What it does |
| --- | --- | --- | --- |
| **HOP ID** | [hel.ink](https://hel.ink) · accounts at `hub.hel.ink` | [navi-crwn/HEL.ink](https://github.com/navi-crwn/HEL.ink) | One account for every HOP app. |
| **LinkHop** | [link.hel.ink](https://link.hel.ink) · `hel.ink/l/…` | [navi-crwn/LinkHop](https://github.com/navi-crwn/LinkHop) | Short links, QR codes and click analytics. |
| **BioHop** | [bio.hel.ink](https://bio.hel.ink) · `hel.ink/b/…` | [navi-crwn/BioHop](https://github.com/navi-crwn/BioHop) | One link-in-bio page for everything you do. |
| **PixelHop** | [pixel.hel.ink](https://pixel.hel.ink) · `p.hel.ink/…` | [navi-crwn/PixelHop](https://github.com/navi-crwn/PixelHop) | Image hosting with albums and direct links. |
| **NoteHop** | [note.hel.ink](https://note.hel.ink) · `n.hel.ink/…` | [navi-crwn/NoteHop](https://github.com/navi-crwn/NoteHop) | Encrypted notes you share with a link. |
| **FileHop** | [file.hel.ink](https://file.hel.ink) | [navi-crwn/FileHop](https://github.com/navi-crwn/FileHop) | Convert files in your browser; nothing is uploaded. |
| **PayHop** | [pay.hel.ink](https://pay.hel.ink) | [navi-crwn/PayHop](https://github.com/navi-crwn/PayHop) | Make a payment QR and split a bill; nothing leaves your browser. |
| **Imperium** | not public-facing: optional, for admins | [navi-crwn/Imperium](https://github.com/navi-crwn/Imperium) | The HOP control panel. |
<!-- hop-family:end -->

## Lizenz

Die gemeinsamen HOP-Pakete sind Open-Source-Software unter der [MIT-Lizenz](LICENSE).
