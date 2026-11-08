# hop/safety

[English](README.md) · [简体中文](README.zh-CN.md) · [Deutsch](README.de.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md) · [Português (Brasil)](README.pt-BR.md) · **Español** · [Français](README.fr.md) · [Русский](README.ru.md)

Las reglas de seguridad de URL y contenido de la familia HOP ([hel.ink](https://hel.ink)), en un solo paquete de Laravel. LinkHop, BioHop y el hub lo usan, así que un enlace o una página de bio se juzga igual en todas las apps.

## Qué ofrece

| Clase | Qué hace |
| --- | --- |
| `Hop\Safety\LinkSafety` | Juzga una URL de destino (y el texto que la rodea) según patrones de palabras clave, hosts de riesgo, suplantación de marcas, la lista negra de dominios compartida, fuentes de amenazas locales y consultas en línea opcionales. |
| `Hop\Safety\LinkVerdict` | Sigue un enlace a través de sus redirecciones y convierte la puntuación en una sola respuesta, un `Verdict`: `allow`, `interstitial` o `block`, con los motivos, la dirección final y el número de saltos. |
| `Hop\Safety\EncryptedNoteLink` | Reconoce una dirección cifrada de NoteHop, cuya clave está en el `#fragment`, para que una app que guarda destinos pueda rechazarla. `LinkSafety` la usa. |
| `Hop\Safety\UrlGuard` | Decide si el servidor puede descargar una URL: solo http o https, y cada dirección a la que resuelve el host debe ser pública. También puede fijar la conexión a las direcciones que comprobó. |
| `Hop\Safety\SafeContent` | Limpia el contenido del usuario antes de mostrarlo: sanea el HTML, convierte un enlace inseguro en `#` y elimina lo que podría salir de un elemento `<style>`. |
| `Hop\Safety\Models\DomainBlacklist` | El modelo Eloquent de la tabla `domain_blacklist`. |
| `abuse:feeds` | Comando de Artisan que descarga las fuentes de URLhaus y OpenPhish. |

## Requisitos

- PHP 8.3, `ext-intl`, Laravel 12 (`illuminate/support` ^12.0) y `symfony/html-sanitizer` ^7.4. `EncryptedNoteLink` usa `ext-intl` para leer un host como lo hace un navegador.
- Para la lista negra, una tabla `domain_blacklist` con `domain`, `match_type` (`exact` o `wildcard`) y `category`. La tabla pertenece a LinkHop y las demás apps solo la leen. Sin la tabla, `LinkSafety` solo usa la lista que incluye el paquete, `resources/domain_blacklist.json`, que tiene las mismas columnas y se lee junto con la tabla.

## Instalación

El paquete no está en Packagist y aún no tiene versiones etiquetadas. Añade el repositorio al `composer.json` de la app y requiere `dev-main`. Fija un commit si quieres una versión estable:

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

Después ejecuta:

```bash
composer update hop/safety
```

El package discovery de Laravel registra `Hop\Safety\SafetyServiceProvider`. Este fusiona la configuración `safety`, vincula `LinkSafety` como instancia scoped (así la lista negra se carga una vez por petición o job) y registra `abuse:feeds` cuando se ejecuta en la consola. Para cambiar la configuración, publícala:

```bash
php artisan vendor:publish --tag=hop-safety-config
```

## Uso

### Comprobar una URL de destino

`LinkSafety::rejectionReason()` devuelve un mensaje que puedes mostrar al usuario, o `null` cuando la URL está permitida. El segundo argumento es el texto que hay cerca de la URL (un título o una descripción) y el tercero es la lista de esquemas permitidos. Por defecto es `LinkSafety::WEB_SCHEMES` (`http`, `https`); `LinkSafety::BIO_SCHEMES` añade `mailto`, `tel` y `sms`.

```php
use Hop\Safety\LinkSafety;

$reason = app(LinkSafety::class)->rejectionReason($url, $title);
if ($reason !== null) {
    return back()->withErrors(['url' => $reason]);
}
```

Una URL se rechaza cuando su esquema no está permitido, cuando no tiene host, cuando es una dirección cifrada de NoteHop (ver más abajo) o cuando coincide con una categoría de `safety.block_categories`. Esta llamada también consulta a los proveedores en línea (ver más abajo).

`assess()` da el detalle en lugar de un veredicto: una puntuación y la lista de señales encontradas. Omite los proveedores en línea salvo que pases `remote: true`.

```php
$result = app(LinkSafety::class)->assess($url, $text);
// for example ['score' => 110, 'signals' => ['gambling', 'chained_shortener', 'executable_download']]
```

Las señales son `csam`, `gambling`, `phishing`, `scam` y `adult` (patrones de palabras clave), `dangerous_scheme` (`javascript:`, `data:`, `vbscript:`, `file:`, `blob:`), `ip_host`, `punycode`, `chained_shortener`, `file_host`, `risky_tld`, `tunnel_host`, `brand_spoof`, `random_subdomain`, `executable_download`, `blacklisted` (más la categoría propia de la fila de la lista negra), `malware` y `phishing` de las fuentes, y `malware` de los proveedores en línea. Cada señal suma su peso de `safety.weights` a la puntuación.

- `risky_tld`: el host termina en uno de `safety.risky_tlds`, que puede ser un sufijo público de más de una etiqueta (`my.id`, `biz.id`).
- `tunnel_host`: el host es uno de `safety.tunnel_hosts` o un subdominio de uno (ngrok, Cloudflare quick tunnels, localtunnel, serveo, localhost.run). Es un bloqueo directo: una página en ellos vive una tarde y ninguna lista de reputación puede conocerla.
- `brand_spoof`: una etiqueta del host o un segmento de la ruta lleva el nombre de un banco, billetera o institución de Indonesia de `safety.brand_keywords` (`klikbca`, `brimo`, `livin`, `mandiri`, `bni`, `bsi`, `dana`, `kaget`, `ovo`, `gopay`, `shopeepay`, `telegram`, `whatsapp`, `kominfo`, `undian`, `blokir`, `tarif`), y el host no es uno de `safety.official_domains` ni un subdominio de uno. Un nombre de seis caracteres o menos solo coincide con una etiqueta o un segmento completo (`bni.example.com`, `/dana`), así que `bandana.com` u `omnibus` nunca coinciden; un nombre más largo también coincide dentro de uno (`bankmandiri-login`, `telegramweb`). La ruta se lee con la codificación porcentual decodificada. La lista oficial (`klikbca.com`, `bca.co.id`, `bri.co.id`, `bankmandiri.co.id`, `bni.co.id`, `bankbsi.co.id`, `dana.id`, `ovo.id`, `gopay.co.id`, `shopee.co.id`, `telegram.org`, `t.me`, `whatsapp.com`, `wa.me`, `kominfo.go.id`, `komdigi.go.id`) cubre solo el dominio y sus subdominios: `klikbca.com.example.xyz` y `notklikbca.com` son suplantaciones.
- `executable_download`: la dirección termina en `.apk`, `.exe`, `.scr`, `.msi`, `.bat`, `.cmd`, `.ps1`, `.vbs` o `.jar`. Es un bloqueo directo.

### El veredicto del enlace

`LinkVerdict::evaluate()` es la respuesta única que usan las apps cuando un visitante sigue un enlace: dejarlo pasar, mostrar antes una página de aviso o rechazarlo. Sigue el enlace a través de sus redirecciones, puntúa la dirección a la que llega (y la que se envió) con `LinkSafety::assess()`, añade lo que solo la descarga puede revelar y convierte el total en un veredicto.

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

`Verdict` es un objeto de valor:

| Propiedad | Significado |
| --- | --- |
| `verdict` | `Verdict::ALLOW` (`allow`), `Verdict::INTERSTITIAL` (`interstitial`) o `Verdict::BLOCK` (`block`). `allows()`, `needsInterstitial()` y `blocks()` dicen lo mismo. |
| `score` | La suma de `safety.weights` de todos los motivos. |
| `reasons` | Las señales encontradas, una vez cada una, como claves que la app traduce (`blacklisted`, `brand_spoof`, `redirect_chain`, `guest`, ...), nunca como texto. |
| `finalUrl` | La dirección a la que llega el enlace tras sus redirecciones, o la dirección enviada si no se siguió nada. |
| `hops` | Cuántas redirecciones se siguieron. |

`toArray()` da lo mismo con `final_url` como clave, para una respuesta JSON o una línea de log.

Los argumentos: `guest` es true cuando el enlace se creó sin cuenta; `text` es el título o la descripción que rodea el enlace; `unroll: false` omite la red por completo y juzga solo la dirección (para una comprobación al crear el enlace o un análisis de enlaces guardados); `remote: false` omite los proveedores en línea.

Cómo se sigue el enlace:

- Cada salto pasa primero por `UrlGuard::resolve()` y la petición se fija a las direcciones comprobadas. Un salto cuyo host no tiene respuesta DNS, o resuelve a una dirección privada o local, nunca se descarga y termina la cadena como no resuelto.
- Cada salto es una petición `HEAD`, y una `GET` cuando el servidor rechaza `HEAD` o la petición falla. El cuerpo nunca se lee: la respuesta se recibe en streaming y solo se usan el estado y las cabeceras. Tiempo de espera `safety.verdict.timeout` (4 s) por salto, con el User-Agent móvil de `safety.verdict.user_agent`, porque los kits de phishing suelen servir una página inofensiva a un navegador de escritorio.
- Se siguen hasta `safety.verdict.max_hops` (5) redirecciones; las cabeceras `Location` relativas se resuelven respecto al salto que las envió. Más redirecciones, un error de transporte o un estado de error (4xx, 5xx) en el último salto cuentan como no resuelto.

Las señales que solo añade el veredicto:

| Motivo | Peso | Cuándo |
| --- | --- | --- |
| `redirect_chain` | 25 | `safety.verdict.chain_hops` (3) o más redirecciones. |
| `unresolved_destination` | 50 | No se pudo verificar el destino (ver arriba). Por sí solo no es un bloqueo. |
| `executable_download` | 100 | El `Content-Type` final es uno de `safety.verdict.executable_types` (un paquete de Android, un ejecutable o un instalador de Windows), sea cual sea el aspecto de la dirección. |
| `guest` | 25 | El enlace lo creó un invitado. |
| `guest_ip_host` | 100 | Un enlace de invitado cuyo host es una dirección IP sin más. |
| `invalid_url` | 100 | No es una dirección web: sin esquema, sin host o con un esquema distinto de `http` y `https`. No se descarga nada. |

Los umbrales están en `safety.verdict.thresholds`: una puntuación de 70 o más es `block`, de 30 o más es `interstitial` y cualquier valor menor es `allow`. Con los pesos por defecto, un enlace de invitado a un dominio `.xyz` es un interstitial (30 + 25), el nombre de un banco en un host no oficial bajo un TLD de riesgo es un bloqueo para todos (55 + 30), y un `.apk`, un host de túnel, un dominio en la lista negra o un enlace de invitado a una IP sin más son un bloqueo por sí solos.

`LinkVerdict` se vincula como instancia scoped, igual que `LinkSafety`. No usa caché; las apps deciden cuándo volver a comprobar un enlace guardado.

### Enlaces cifrados de NoteHop

Un enlace de NoteHop guarda la clave de la nota en el fragmento (`https://n.hel.ink/TOKEN#k=KEY`, y `&a=` para el enlace del propietario). Los navegadores nunca envían un fragmento a un servidor, y por eso NoteHop no puede leer la nota. Una app que guarde ese enlace como destino, enlace de bio o denuncia tendría la clave en su base de datos, sus logs y sus redirecciones. Por eso `rejectionReason()` rechaza una dirección de NoteHop cuyo fragmento tiene un parámetro `k` o `a` con valor, tanto en una URL como en texto libre, y `assess()` la informa como la señal `encrypted_note_key` (peso 0: se rechaza al guardar y nunca se puntúa).

La dirección se lee como la lee un navegador, no como lo hace `parse_url()`: se eliminan tabuladores y saltos de línea, se recortan los caracteres de control, se acepta cualquier número de barras tras `https:`, una barra invertida termina el host, la última `@` termina la información de usuario, y los escapes porcentuales, las letras de ancho completo, `U+3002`, los guiones suaves y un punto final llevan todos al host al que se conectaría el navegador. `#?k=KEY` es el parámetro `k` porque `URLSearchParams` descarta un `?`. En una dirección Android `intent://` se busca una dirección de respaldo de NoteHop. Otros hosts, otros fragmentos y `?k=` en la query (que NoteHop no lee) son destinos normales.

Los hosts son `safety.note_hosts` (`HOP_NOTE_HOSTS`, por defecto `note.hel.ink,n.hel.ink`). Una app puede añadir más, por ejemplo desde su registro de apps, con `EncryptedNoteLink::resolveHostsUsing(fn () => [...])`.

La misma clase ayuda a las apps a limpiar lo que ya guardan: `withoutFragment($url)`, `withoutSecrets($url)` (solo `k` y `a` salen del fragmento), y `scrub($text)` y `scrubJson($json)` (todas las direcciones de NoteHop en un texto o en una columna JSON; los fragmentos de otros hosts se quedan).

### Descargar una URL proporcionada por el usuario

`UrlGuard::isSafe($url)` responde si una URL se puede descargar desde el servidor. Solo acepta `http` y `https`, resuelve el host y rechaza la URL si alguna dirección resuelta es privada o reservada. Eso cubre loopback, link-local (incluidas direcciones de metadatos de la nube como `169.254.169.254`) y los rangos privados.

`isSafe()` solo da un sí o un no. Si después pasas la URL a un cliente HTTP, el cliente vuelve a consultar el host, y un servidor DNS hostil puede responder distinto la segunda vez (DNS rebinding). Para cerrar ese hueco, llama a `resolve()` una vez y haz que el cliente se conecte a las direcciones comprobadas:

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

`curlResolve()` devuelve una sola entrada `host:port:address1,address2` con todas las direcciones comprobadas. Sigue tú mismo las redirecciones y vuelve a llamar a `resolve()` para cada `Location`. `isSafe()` equivale a `resolve() !== null`.

Todas las direcciones van en una sola entrada porque libcurl solo conserva la última entrada para un host y un puerto dados. Con una entrada por dirección, la petición quedaría fijada solo a la última dirección y fallaría en una máquina sin IPv6. Con una sola entrada, libcurl prueba las direcciones comprobadas y la petición nunca va a una dirección que no se comprobó.

### Sanear el contenido del usuario

```php
use Hop\Safety\SafeContent;

$html = SafeContent::html($rawUserHtml);
$href = SafeContent::url($userUrl);          // '#' unless the scheme is allowed
$css  = SafeContent::css($userCss);
```

- `html()` conserva las etiquetas de formato y elimina scripts, manejadores de eventos, estilos, formularios y enlaces que no sean `http`, `https`, `mailto` o `tel`. Los enlaces reciben `rel="nofollow noreferrer noopener"` y `target="_blank"`. La entrada se corta en 50.000 caracteres.
- `url()` devuelve tal cual una ruta del mismo sitio, y cualquier otro valor solo si su esquema está en la lista permitida (por defecto `LinkSafety::BIO_SCHEMES`). Todo lo demás se convierte en `#`.
- `css()` elimina `<`, `@import`, `expression(`, `javascript:`, `behavior:` y `-moz-binding`, para que el CSS personalizado no pueda cerrar su elemento `<style>` ni cargar scripts.
- `isSitePath()` distingue una ruta real del mismo sitio de otra que un navegador leería como otro host, como `/\evil.com`.

Aplícalas al renderizar, no solo al guardar, para que también queden cubiertas las filas guardadas antes de que existiera una regla.

### Fuentes de amenazas

```bash
php artisan abuse:feeds
```

El comando descarga la lista de malware activo de URLhaus y la fuente comunitaria de OpenPhish en `storage/app/threat-feeds/malware.txt` y `phishing.txt`. `LinkSafety` compara una URL con ellas de forma exacta (se ignoran el esquema y un `www.` inicial), para no bloquear entera una plataforma compartida. LinkHop ejecuta el comando a diario.

Cada fuente se descarga a un archivo temporal, se comprueba y luego reemplaza al archivo anterior. Una descarga fallida, truncada, vacía o sin URL conserva la lista anterior. Una descarga mayor que `safety.feed_max_bytes` se cancela. El comando solo falla cuando fallan todas las fuentes.

### Consultas en línea

Cuando se ejecuta `rejectionReason()` (o `assess(..., remote: true)`), se consulta a tres proveedores opcionales, y una coincidencia añade la señal `malware`. Las respuestas se guardan en caché durante `safety.providers.cache_hours` (12). Un proveedor inaccesible o que devuelve un error nunca bloquea una URL.

- Spamhaus DBL por DNS, activado por defecto (`SPAMHAUS_DBL`). Necesita un resolvedor DNS que Spamhaus acepte, y los resolvedores públicos no lo son.
- Google Safe Browsing, usado cuando `SAFE_BROWSING_KEY` está definida. Solo se envía la dirección sin su `#fragment` (un fragmento nunca se envía a un servidor, así que tampoco se le cuenta a un tercero), y la respuesta en caché usa la misma clave.
- Consulta de hosts de URLhaus, usada cuando `URLHAUS_AUTH_KEY` está definida.

## Configuración

| Variable | Valor por defecto | Significado |
| --- | --- | --- |
| `SAFE_BROWSING_KEY` | ninguno | Activa la consulta a Google Safe Browsing. |
| `URLHAUS_AUTH_KEY` | ninguno | Activa la consulta de hosts de URLhaus. |
| `SPAMHAUS_DBL` | `true` | Consulta de Spamhaus DBL por DNS. |
| `THREAT_FEED_MAX_BYTES` | `67108864` (64 MB) | Tamaño máximo de fuente que acepta `abuse:feeds`. |
| `HOP_NOTE_HOSTS` | `note.hel.ink,n.hel.ink` | Hosts de NoteHop separados por comas cuyos enlaces llevan la clave en el fragmento (las instalaciones autoalojadas añaden los suyos). |
| `ABUSE_REGISTER_PER_HOUR` | `3` | Lo leen las apps, no este paquete. |

El resto está en `config/safety.php`: los patrones de palabras clave `patterns`, `block_categories`, los pesos `weights` de cada señal, las listas `shorteners`, `file_hosts`, `risky_tlds`, `tunnel_hosts`, `brand_keywords`, `official_domains` y `known_domains`, y los ajustes de `verdict` (`thresholds`, `max_hops`, `chain_hops`, `timeout`, `user_agent`, `executable_types`). Las claves `thresholds`, `suspend_after_disabled_items` y `purge_after_days` las lee el comando `abuse:scan` de LinkHop y BioHop, no este paquete.

## Pruebas

Las pruebas de `tests/Feature` son pruebas de Pest que se ejecutan dentro de una app que usa el paquete (HEL.ink incluye una copia en `packages/safety`). Las pruebas de `tests/Unit` no necesitan ninguna aplicación. `tests/fixtures/note-link-variants.json` contiene 64 formas de escribir una dirección de NoteHop con el veredicto de un parser de URL WHATWG, generadas por `tests/fixtures/note-link-truth.js` (Node). `tests/fixtures/verdict-cases.json` enumera direcciones con el veredicto esperado para cada una, entre ellas los hosts de phishing que llegaron a LinkHop; las pruebas del veredicto simulan DNS y HTTP y nunca tocan la red. La CI propia de este repositorio comprueba que `composer.json` es válido, que todos los archivos PHP pasan el lint con PHP 8.3 y que la tabla de la familia de abajo coincide con la canónica.

## Seguridad

Informa de las vulnerabilidades como se describe en [SECURITY.md](SECURITY.md), no en issues públicos.

## Registro de cambios

Consulta [CHANGELOG.md](CHANGELOG.md).

## Autoalojamiento

No tienes que instalar este paquete a mano para ejecutar HOP. LinkHop y BioHop incluyen este repositorio público en su `composer.json`, así que `composer install` lo descarga sin token, y el hub ([HEL.ink](https://github.com/navi-crwn/HEL.ink)) lleva una copia en `packages/safety`. Funciona sin ninguna clave: Safe Browsing y URLhaus siguen desactivados hasta que definas `SAFE_BROWSING_KEY` y `URLHAUS_AUTH_KEY`, la comprobación de Spamhaus es una consulta DNS que no necesita clave (`SPAMHAUS_DBL=false` la desactiva), y sin la tabla `domain_blacklist` de LinkHop usa la lista que incluye. Si ejecutas NoteHop, define `HOP_NOTE_HOSTS` con sus nombres de host, para que se reconozcan los enlaces de notas cifradas de tu dominio; por defecto son los de hel.ink. La guía de la familia cubre el resto: [docs/self-hosting.md](https://github.com/navi-crwn/HEL.ink/blob/main/docs/self-hosting.md).

## Familia HOP

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
| **Imperium** | not public-facing: optional, for admins | [navi-crwn/Imperium](https://github.com/navi-crwn/Imperium) | The HOP control panel. |
<!-- hop-family:end -->

## Licencia

Los paquetes compartidos de HOP son software de código abierto con [licencia MIT](LICENSE).
