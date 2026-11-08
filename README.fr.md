# hop/safety

[English](README.md) · [简体中文](README.zh-CN.md) · [Deutsch](README.de.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md) · [Português (Brasil)](README.pt-BR.md) · [Español](README.es.md) · **Français** · [Русский](README.ru.md)

Les règles de sécurité des URL et des contenus de la famille HOP ([hel.ink](https://hel.ink)), réunies dans un seul paquet Laravel. LinkHop, BioHop et le hub l'utilisent, donc un lien ou une page bio est jugé de la même façon dans chaque app.

## Ce qu'il fournit

| Classe | Ce qu'elle fait |
| --- | --- |
| `Hop\Safety\LinkSafety` | Juge une URL de destination (et le texte qui l'entoure) selon des motifs de mots-clés, des hôtes à risque, l'usurpation de marques, la liste noire de domaines partagée, des flux de menaces locaux et des vérifications en ligne facultatives. |
| `Hop\Safety\LinkVerdict` | Suit un lien à travers ses redirections et transforme le score en une seule réponse, un `Verdict` : `allow`, `interstitial` ou `block`, avec les raisons, l'adresse finale et le nombre de sauts. |
| `Hop\Safety\EncryptedNoteLink` | Reconnaît une adresse NoteHop chiffrée, dont la clé se trouve dans le `#fragment`, pour qu'une app qui enregistre des destinations puisse la refuser. `LinkSafety` l'utilise. |
| `Hop\Safety\UrlGuard` | Décide si le serveur peut récupérer une URL : http ou https uniquement, et chaque adresse vers laquelle l'hôte se résout doit être publique. Il peut aussi épingler la connexion aux adresses vérifiées. |
| `Hop\Safety\SafeContent` | Nettoie le contenu des utilisateurs avant affichage : assainit le HTML, transforme un lien dangereux en `#` et retire ce qui pourrait sortir d'un élément `<style>`. |
| `Hop\Safety\Models\DomainBlacklist` | Le modèle Eloquent de la table `domain_blacklist`. |
| `abuse:feeds` | Commande Artisan qui télécharge les flux URLhaus et OpenPhish. |

## Prérequis

- PHP 8.3, `ext-intl`, Laravel 12 (`illuminate/support` ^12.0) et `symfony/html-sanitizer` ^7.4. C'est avec `ext-intl` que `EncryptedNoteLink` lit un hôte comme le fait un navigateur.
- Pour la liste noire, une table `domain_blacklist` avec `domain`, `match_type` (`exact` ou `wildcard`) et `category`. La table appartient à LinkHop, les autres apps ne font que la lire. Sans la table, `LinkSafety` utilise seulement la liste fournie avec le paquet, `resources/domain_blacklist.json`, qui a les mêmes colonnes et est lue en plus de la table.

## Installation

Le paquet n'est pas sur Packagist et n'a pas encore de version taguée. Ajoutez le dépôt au `composer.json` de l'app et demandez `dev-main`. Épinglez un commit si vous voulez une version fixe :

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

Puis lancez :

```bash
composer update hop/safety
```

La découverte de paquets de Laravel enregistre `Hop\Safety\SafetyServiceProvider`. Il fusionne la configuration `safety`, lie `LinkSafety` comme instance scoped (la liste noire est donc chargée une fois par requête ou par job) et enregistre `abuse:feeds` quand il tourne en console. Pour modifier la configuration, publiez-la :

```bash
php artisan vendor:publish --tag=hop-safety-config
```

## Utilisation

### Vérifier une URL de destination

`LinkSafety::rejectionReason()` renvoie un message que vous pouvez afficher à l'utilisateur, ou `null` si l'URL est autorisée. Le deuxième argument est le texte trouvé près de l'URL (un titre ou une description), le troisième la liste des schémas autorisés. Par défaut, c'est `LinkSafety::WEB_SCHEMES` (`http`, `https`) ; `LinkSafety::BIO_SCHEMES` ajoute `mailto`, `tel` et `sms`.

```php
use Hop\Safety\LinkSafety;

$reason = app(LinkSafety::class)->rejectionReason($url, $title);
if ($reason !== null) {
    return back()->withErrors(['url' => $reason]);
}
```

Une URL est refusée quand son schéma n'est pas autorisé, quand son hôte manque, quand c'est une adresse NoteHop chiffrée (voir plus bas) ou quand elle correspond à une catégorie listée dans `safety.block_categories`. Cet appel interroge aussi les fournisseurs en ligne (voir plus bas).

`assess()` donne le détail au lieu d'un verdict : un score et la liste des signaux trouvés. Il ignore les fournisseurs en ligne, sauf si vous passez `remote: true`.

```php
$result = app(LinkSafety::class)->assess($url, $text);
// for example ['score' => 110, 'signals' => ['gambling', 'chained_shortener', 'executable_download']]
```

Les signaux sont `csam`, `gambling`, `phishing`, `scam` et `adult` (motifs de mots-clés), `dangerous_scheme` (`javascript:`, `data:`, `vbscript:`, `file:`, `blob:`), `ip_host`, `punycode`, `chained_shortener`, `file_host`, `risky_tld`, `tunnel_host`, `brand_spoof`, `random_subdomain`, `executable_download`, `blacklisted` (plus la catégorie propre à la ligne de la liste noire), `malware` et `phishing` venant des flux, et `malware` venant des fournisseurs en ligne. Chaque signal ajoute au score son poids défini dans `safety.weights`.

- `risky_tld` : l'hôte se termine par l'un des `safety.risky_tlds`, qui peut être un suffixe public de plus d'un label (`my.id`, `biz.id`).
- `tunnel_host` : l'hôte est l'un des `safety.tunnel_hosts` ou un sous-domaine de l'un d'eux (ngrok, Cloudflare quick tunnels, localtunnel, serveo, localhost.run). C'est un blocage ferme : une page sur ces hôtes vit le temps d'un après-midi et aucune liste de réputation ne peut la connaître.
- `brand_spoof` : un label de l'hôte ou un segment du chemin porte le nom d'une banque, d'un portefeuille ou d'une institution indonésienne de `safety.brand_keywords` (`klikbca`, `brimo`, `livin`, `mandiri`, `bni`, `bsi`, `dana`, `kaget`, `ovo`, `gopay`, `shopeepay`, `telegram`, `whatsapp`, `kominfo`, `undian`, `blokir`, `tarif`), et l'hôte n'est ni l'un des `safety.official_domains` ni un sous-domaine de l'un d'eux. Un nom de six caractères ou moins ne correspond qu'à un label ou un segment entier (`bni.example.com`, `/dana`), donc `bandana.com` ou `omnibus` ne correspondent jamais ; un nom plus long correspond aussi à l'intérieur d'un label ou d'un segment (`bankmandiri-login`, `telegramweb`). Le chemin est lu après décodage des séquences en pourcentage. La liste officielle (`klikbca.com`, `bca.co.id`, `bri.co.id`, `bankmandiri.co.id`, `bni.co.id`, `bankbsi.co.id`, `dana.id`, `ovo.id`, `gopay.co.id`, `shopee.co.id`, `telegram.org`, `t.me`, `whatsapp.com`, `wa.me`, `kominfo.go.id`, `komdigi.go.id`) ne couvre que le domaine et ses sous-domaines : `klikbca.com.example.xyz` et `notklikbca.com` sont des usurpations.
- `executable_download` : l'adresse se termine par `.apk`, `.exe`, `.scr`, `.msi`, `.bat`, `.cmd`, `.ps1`, `.vbs` ou `.jar`. C'est un blocage ferme.

### Le verdict sur un lien

`LinkVerdict::evaluate()` est la réponse unique sur laquelle les apps se basent quand un visiteur suit un lien : le laisser passer, afficher d'abord une page d'avertissement, ou refuser. Il suit le lien à travers ses redirections, évalue avec `LinkSafety::assess()` l'adresse d'arrivée (et celle qui a été soumise), ajoute ce que seule la requête peut révéler et transforme le total en verdict.

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

`Verdict` est un objet valeur :

| Propriété | Signification |
| --- | --- |
| `verdict` | `Verdict::ALLOW` (`allow`), `Verdict::INTERSTITIAL` (`interstitial`) ou `Verdict::BLOCK` (`block`). `allows()`, `needsInterstitial()` et `blocks()` disent la même chose. |
| `score` | La somme des `safety.weights` de toutes les raisons. |
| `reasons` | Les signaux trouvés, une fois chacun, sous forme de clés que l'app traduit (`blacklisted`, `brand_spoof`, `redirect_chain`, `guest`, ...), jamais en texte rédigé. |
| `finalUrl` | L'adresse où le lien arrive après ses redirections, ou l'adresse soumise si rien n'a été suivi. |
| `hops` | Le nombre de redirections suivies. |

`toArray()` donne la même chose avec `final_url` comme clé, pour une réponse JSON ou une ligne de log.

Les arguments : `guest` vaut true quand le lien a été créé sans compte ; `text` est le titre ou la description autour du lien ; `unroll: false` évite complètement le réseau et juge l'adresse seule (pour une vérification à la création ou un scan des liens enregistrés) ; `remote: false` ignore les fournisseurs en ligne.

Comment le lien est suivi :

- Chaque saut passe d'abord par `UrlGuard::resolve()` et la requête est épinglée aux adresses vérifiées. Un saut dont l'hôte n'a pas de réponse DNS, ou se résout vers une adresse privée ou locale, n'est jamais récupéré et termine la chaîne comme non résolu.
- Chaque saut est une requête `HEAD`, puis une `GET` si le serveur refuse `HEAD` ou si la requête échoue. Le corps n'est jamais lu : la réponse est reçue en streaming et seuls le statut et les en-têtes sont utilisés. Délai `safety.verdict.timeout` (4 s) par saut, avec le User-Agent mobile de `safety.verdict.user_agent`, car les kits de phishing servent souvent une page inoffensive à un navigateur de bureau.
- Jusqu'à `safety.verdict.max_hops` (5) redirections sont suivies ; les en-têtes `Location` relatifs sont résolus par rapport au saut qui les a envoyés. Au-delà, une erreur de transport ou un statut d'erreur (4xx, 5xx) sur le dernier saut comptent comme non résolu.

Les signaux que seul le verdict ajoute :

| Raison | Poids | Quand |
| --- | --- | --- |
| `redirect_chain` | 25 | `safety.verdict.chain_hops` (3) redirections ou plus. |
| `unresolved_destination` | 50 | La destination n'a pas pu être vérifiée (voir plus haut). Pas un blocage à lui seul. |
| `executable_download` | 100 | Le `Content-Type` final est l'un des `safety.verdict.executable_types` (un paquet Android, un exécutable ou un installeur Windows), quelle que soit l'apparence de l'adresse. |
| `guest` | 25 | Le lien a été créé par un invité. |
| `guest_ip_host` | 100 | Un lien d'invité dont l'hôte est une simple adresse IP. |
| `invalid_url` | 100 | Pas une adresse web : pas de schéma, pas d'hôte, ou un schéma autre que `http` et `https`. Rien n'est récupéré. |

Les seuils sont dans `safety.verdict.thresholds` : un score de 70 ou plus donne `block`, de 30 ou plus `interstitial`, et en dessous `allow`. Avec les poids par défaut, un lien d'invité vers un domaine `.xyz` donne un interstitiel (30 + 25), un nom de banque sur un hôte non officiel sous un TLD à risque est bloqué pour tout le monde (55 + 30), et un `.apk`, un hôte de tunnel, un domaine en liste noire ou un lien d'invité vers une simple IP suffisent chacun à bloquer.

`LinkVerdict` est lié comme instance scoped, comme `LinkSafety`. Il ne met rien en cache ; ce sont les apps qui décident quand revérifier un lien enregistré.

### Liens NoteHop chiffrés

Un lien NoteHop garde la clé de la note dans le fragment (`https://n.hel.ink/TOKEN#k=KEY`, et `&a=` pour le lien du propriétaire). Les navigateurs n'envoient jamais de fragment à un serveur, c'est pourquoi NoteHop ne peut pas lire la note. Une app qui enregistrerait un tel lien comme destination, lien bio ou signalement garderait la clé dans sa base de données, ses logs et ses redirections. `rejectionReason()` refuse donc une adresse NoteHop dont le fragment contient un paramètre `k` ou `a` avec une valeur, dans une URL comme dans du texte libre, et `assess()` la signale avec le signal `encrypted_note_key` (poids 0 : elle est refusée à l'enregistrement et jamais notée).

L'adresse est lue comme un navigateur la lit, pas comme le fait `parse_url()` : les tabulations et les sauts de ligne sont supprimés, les caractères de contrôle sont retirés aux extrémités, n'importe quel nombre de barres après `https:` est accepté, une barre oblique inverse termine l'hôte, le dernier `@` termine les informations utilisateur, et les séquences en pourcentage, les lettres pleine chasse, `U+3002`, les traits d'union conditionnels et un point final mènent tous à l'hôte auquel le navigateur se connecterait. `#?k=KEY` est le paramètre `k` car `URLSearchParams` supprime un `?`. Dans une adresse Android `intent://`, on cherche une adresse de repli NoteHop. Les autres hôtes, les autres fragments et `?k=` dans la query (que NoteHop ne lit pas) sont des destinations ordinaires.

Les hôtes sont `safety.note_hosts` (`HOP_NOTE_HOSTS`, par défaut `note.hel.ink,n.hel.ink`). Une app peut en ajouter, par exemple depuis son registre d'apps, avec `EncryptedNoteLink::resolveHostsUsing(fn () => [...])`.

La même classe aide les apps à nettoyer ce qu'elles stockent déjà : `withoutFragment($url)`, `withoutSecrets($url)` (seuls `k` et `a` sont retirés du fragment), ainsi que `scrub($text)` et `scrubJson($json)` (toutes les adresses NoteHop d'un texte ou d'une colonne JSON ; les fragments des autres hôtes restent).

### Récupérer une URL fournie par un utilisateur

`UrlGuard::isSafe($url)` indique si une URL peut être récupérée depuis le serveur. Il n'accepte que `http` et `https`, résout l'hôte et refuse l'URL si l'une des adresses résolues est privée ou réservée. Cela couvre le loopback, le link-local (y compris les adresses de métadonnées cloud comme `169.254.169.254`) et les plages privées.

`isSafe()` ne donne qu'un oui ou un non. Si vous passez ensuite l'URL à un client HTTP, le client résout l'hôte une seconde fois, et un serveur DNS hostile peut répondre autre chose cette fois-là (DNS rebinding). Pour fermer cette faille, appelez `resolve()` une fois et faites en sorte que le client se connecte aux adresses vérifiées :

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

`curlResolve()` renvoie une seule entrée `host:port:address1,address2` qui liste toutes les adresses vérifiées. Suivez vous-même les redirections et rappelez `resolve()` pour chaque `Location`. `isSafe()` équivaut à `resolve() !== null`.

Toutes les adresses vont dans une seule entrée, car libcurl ne garde que la dernière entrée pour un hôte et un port donnés. Avec une entrée par adresse, la requête serait épinglée à la dernière adresse seulement et échouerait sur une machine sans IPv6. Avec une seule entrée, libcurl essaie les adresses vérifiées et la requête ne part jamais vers une adresse non vérifiée.

### Assainir le contenu des utilisateurs

```php
use Hop\Safety\SafeContent;

$html = SafeContent::html($rawUserHtml);
$href = SafeContent::url($userUrl);          // '#' unless the scheme is allowed
$css  = SafeContent::css($userCss);
```

- `html()` garde les balises de mise en forme et supprime les scripts, les gestionnaires d'événements, les styles, les formulaires et les liens qui ne sont pas `http`, `https`, `mailto` ou `tel`. Les liens reçoivent `rel="nofollow noreferrer noopener"` et `target="_blank"`. L'entrée est coupée à 50 000 caractères.
- `url()` renvoie tel quel un chemin du même site, et toute autre valeur seulement si son schéma figure dans la liste autorisée (par défaut `LinkSafety::BIO_SCHEMES`). Tout le reste devient `#`.
- `css()` retire `<`, `@import`, `expression(`, `javascript:`, `behavior:` et `-moz-binding`, pour que le CSS personnalisé ne puisse pas fermer son élément `<style>` ni charger de scripts.
- `isSitePath()` distingue un vrai chemin du même site d'un chemin qu'un navigateur lirait comme un autre hôte, par exemple `/\evil.com`.

Appliquez-les au rendu, pas seulement à l'enregistrement, pour couvrir aussi les lignes enregistrées avant qu'une règle n'existe.

### Flux de menaces

```bash
php artisan abuse:feeds
```

La commande télécharge la liste des malwares en ligne d'URLhaus et le flux communautaire d'OpenPhish dans `storage/app/threat-feeds/malware.txt` et `phishing.txt`. `LinkSafety` compare une URL à ces listes de façon exacte (le schéma et un `www.` initial sont ignorés), pour ne pas bloquer en entier une plateforme partagée. LinkHop lance la commande chaque jour.

Chaque flux est téléchargé dans un fichier temporaire, vérifié, puis déplacé à la place de l'ancien. Un téléchargement en échec, tronqué, vide ou sans URL conserve la liste précédente. Un téléchargement plus gros que `safety.feed_max_bytes` est interrompu. La commande n'échoue que si tous les flux ont échoué.

### Vérifications en ligne

Quand `rejectionReason()` s'exécute (ou `assess(..., remote: true)`), trois fournisseurs facultatifs sont interrogés, et une correspondance ajoute le signal `malware`. Les réponses sont mises en cache pendant `safety.providers.cache_hours` (12). Un fournisseur injoignable ou qui renvoie une erreur ne bloque jamais une URL.

- Spamhaus DBL via DNS, activé par défaut (`SPAMHAUS_DBL`). Il faut un résolveur DNS accepté par Spamhaus, ce qui n'est pas le cas des résolveurs publics.
- Google Safe Browsing, utilisé quand `SAFE_BROWSING_KEY` est défini. Seule l'adresse sans son `#fragment` est envoyée (un fragment n'est jamais envoyé à un serveur, il n'est donc pas non plus transmis à un tiers), et la réponse en cache utilise la même clé.
- Recherche d'hôtes URLhaus, utilisée quand `URLHAUS_AUTH_KEY` est défini.

## Configuration

| Variable | Défaut | Signification |
| --- | --- | --- |
| `SAFE_BROWSING_KEY` | aucune | Active la vérification Google Safe Browsing. |
| `URLHAUS_AUTH_KEY` | aucune | Active la recherche d'hôtes URLhaus. |
| `SPAMHAUS_DBL` | `true` | Vérification Spamhaus DBL via DNS. |
| `THREAT_FEED_MAX_BYTES` | `67108864` (64 MB) | Taille maximale de flux acceptée par `abuse:feeds`. |
| `HOP_NOTE_HOSTS` | `note.hel.ink,n.hel.ink` | Hôtes NoteHop séparés par des virgules, dont les liens portent la clé dans le fragment (les installations auto-hébergées ajoutent les leurs). |
| `ABUSE_REGISTER_PER_HOUR` | `3` | Lu par les apps, pas par ce paquet. |

Le reste se trouve dans `config/safety.php` : les motifs de mots-clés `patterns`, `block_categories`, les poids `weights` de chaque signal, les listes `shorteners`, `file_hosts`, `risky_tlds`, `tunnel_hosts`, `brand_keywords`, `official_domains` et `known_domains`, et les réglages de `verdict` (`thresholds`, `max_hops`, `chain_hops`, `timeout`, `user_agent`, `executable_types`). Les clés `thresholds`, `suspend_after_disabled_items` et `purge_after_days` sont lues par la commande `abuse:scan` de LinkHop et BioHop, pas par ce paquet.

## Tests

Les tests de `tests/Feature` sont des tests Pest qui tournent dans une app utilisant le paquet (HEL.ink en contient une copie dans `packages/safety`). Les tests de `tests/Unit` n'ont besoin d'aucune application. `tests/fixtures/note-link-variants.json` contient 64 graphies d'une adresse NoteHop avec le verdict d'un parseur d'URL WHATWG, produites par `tests/fixtures/note-link-truth.js` (Node). `tests/fixtures/verdict-cases.json` liste des adresses avec le verdict attendu pour chacune, dont les hôtes de phishing qui sont arrivés jusqu'à LinkHop ; les tests du verdict simulent DNS et HTTP et ne touchent jamais le réseau. La CI propre à ce dépôt vérifie que `composer.json` est valide, que chaque fichier PHP passe le lint sous PHP 8.3 et que le tableau de la famille ci-dessous correspond au tableau de référence.

## Sécurité

Signalez les vulnérabilités comme décrit dans [SECURITY.md](SECURITY.md), pas dans des issues publiques.

## Journal des modifications

Voir [CHANGELOG.md](CHANGELOG.md).

## Auto-hébergement

Vous n'installez pas ce paquet à la main pour faire tourner HOP. LinkHop et BioHop listent ce dépôt public dans leur `composer.json`, donc `composer install` le récupère sans token, et le hub ([HEL.ink](https://github.com/navi-crwn/HEL.ink)) en contient une copie dans `packages/safety`. Il fonctionne sans aucune clé : Safe Browsing et URLhaus restent désactivés tant que vous ne définissez pas `SAFE_BROWSING_KEY` et `URLHAUS_AUTH_KEY`, la vérification Spamhaus est une requête DNS qui ne demande pas de clé (`SPAMHAUS_DBL=false` la désactive), et sans la table `domain_blacklist` de LinkHop, il utilise la liste qu'il fournit. Si vous faites tourner NoteHop, définissez `HOP_NOTE_HOSTS` avec ses noms d'hôte, pour que les liens de notes chiffrées sur votre domaine soient reconnus ; par défaut, ce sont ceux de hel.ink. Le guide de la famille couvre le reste : [docs/self-hosting.md](https://github.com/navi-crwn/HEL.ink/blob/main/docs/self-hosting.md).

## Famille HOP

<!-- hop-family:start -->
<!-- Generated from HEL.ink resources/hop-assets/apps.source.json; edit there, then run scripts/hop_assets.py build. -->
| App | Address | Repository | What it does |
| --- | --- | --- | --- |
| **HOP ID** | [hel.ink](https://hel.ink) · accounts at `hub.hel.ink` | [navi-crwn/HEL.ink](https://github.com/navi-crwn/HEL.ink) | One account for every HOP app. |
| **LinkHop** | [link.hel.ink](https://link.hel.ink) · `hel.ink/l/…` | [navi-crwn/LinkHop](https://github.com/navi-crwn/LinkHop) | Short links, QR codes and click analytics. |
| **BioHop** | [bio.hel.ink](https://bio.hel.ink) · `hel.ink/b/…` | [navi-crwn/BioHop](https://github.com/navi-crwn/BioHop) | One link-in-bio page for everything you do. |
| **PixelHop** | [pixel.hel.ink](https://pixel.hel.ink) · `p.hel.ink/…` | [navi-crwn/PixelHop](https://github.com/navi-crwn/PixelHop) | Image hosting with albums and direct links. |
| **NoteHop** | [note.hel.ink](https://note.hel.ink) · `n.hel.ink/…` | [navi-crwn/NoteHop](https://github.com/navi-crwn/NoteHop) | Encrypted notes you share with a link. |
| **Imperium** | not public-facing: optional, for admins | [navi-crwn/Imperium](https://github.com/navi-crwn/Imperium) | The HOP control panel. |
<!-- hop-family:end -->

## Licence

Les paquets partagés HOP sont des logiciels libres publiés sous [licence MIT](LICENSE).
