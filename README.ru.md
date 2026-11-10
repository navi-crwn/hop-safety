# hop/safety

[English](README.md) · [简体中文](README.zh-CN.md) · [Deutsch](README.de.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md) · [Português (Brasil)](README.pt-BR.md) · [Español](README.es.md) · [Français](README.fr.md) · **Русский**

Правила безопасности для URL и контента в семействе HOP ([hel.ink](https://hel.ink)) в одном пакете Laravel. Его используют LinkHop, BioHop и хаб, поэтому ссылка или bio-страница оценивается одинаково во всех приложениях.

## Что входит в пакет

| Класс | Что делает |
| --- | --- |
| `Hop\Safety\LinkSafety` | Оценивает целевой URL (и текст вокруг него) по шаблонам ключевых слов, рискованным хостам, подделке брендов, общему чёрному списку доменов, локальным фидам угроз и необязательным онлайн-проверкам. |
| `Hop\Safety\LinkVerdict` | Проходит по ссылке через все её редиректы и превращает оценку в один ответ, `Verdict`: `allow`, `interstitial` или `block`, с причинами, конечным адресом и числом переходов. |
| `Hop\Safety\EncryptedNoteLink` | Распознаёт зашифрованный адрес NoteHop, ключ которого лежит в `#fragment`, чтобы приложение, которое хранит адреса назначения, могло его отклонить. Его использует `LinkSafety`. |
| `Hop\Safety\UrlGuard` | Решает, может ли сервер загрузить URL: только http или https, и каждый адрес, в который резолвится хост, должен быть публичным. Также может привязать соединение к проверенным адресам. |
| `Hop\Safety\SafeContent` | Очищает пользовательский контент перед показом: санирует HTML, заменяет небезопасную ссылку на `#` и удаляет то, что может выйти за пределы элемента `<style>`. |
| `Hop\Safety\Models\DomainBlacklist` | Модель Eloquent для таблицы `domain_blacklist`. |
| `abuse:feeds` | Команда Artisan, которая скачивает фиды URLhaus и OpenPhish. |

## Требования

- PHP 8.3, `ext-intl`, Laravel 12 (`illuminate/support` ^12.0) и `symfony/html-sanitizer` ^7.4. С помощью `ext-intl` класс `EncryptedNoteLink` читает хост так же, как браузер.
- Для чёрного списка нужна таблица `domain_blacklist` с полями `domain`, `match_type` (`exact` или `wildcard`) и `category`. Таблица принадлежит LinkHop, остальные приложения только читают её. Без таблицы `LinkSafety` использует только список из пакета, `resources/domain_blacklist.json`. У него те же столбцы, и он читается вместе с таблицей.

## Установка

Пакета нет на Packagist, и у него ещё нет релизов с тегами. Добавь репозиторий в `composer.json` приложения и подключи `dev-main`. Если нужна фиксированная версия, закрепи коммит:

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

Затем выполни:

```bash
composer update hop/safety
```

Package discovery в Laravel регистрирует `Hop\Safety\SafetyServiceProvider`. Он объединяет конфигурацию `safety`, привязывает `LinkSafety` как scoped-экземпляр (поэтому чёрный список загружается один раз на запрос или задачу) и регистрирует `abuse:feeds` при запуске в консоли. Чтобы изменить конфигурацию, опубликуй её:

```bash
php artisan vendor:publish --tag=hop-safety-config
```

## Использование

### Проверка целевого URL

`LinkSafety::rejectionReason()` возвращает сообщение, которое можно показать пользователю, или `null`, если URL разрешён. Второй аргумент — текст рядом с URL (заголовок или описание), третий — список разрешённых схем. По умолчанию это `LinkSafety::WEB_SCHEMES` (`http`, `https`); `LinkSafety::BIO_SCHEMES` добавляет `mailto`, `tel` и `sms`.

```php
use Hop\Safety\LinkSafety;

$reason = app(LinkSafety::class)->rejectionReason($url, $title);
if ($reason !== null) {
    return back()->withErrors(['url' => $reason]);
}
```

URL отклоняется, если его схема не разрешена, если у него нет хоста, если это зашифрованный адрес NoteHop (см. ниже) или если он подходит под категорию из `safety.block_categories`. Этот вызов также опрашивает онлайн-сервисы (см. ниже).

`assess()` даёт подробности вместо вердикта: оценку и список найденных сигналов. Онлайн-сервисы он пропускает, если не передать `remote: true`.

```php
$result = app(LinkSafety::class)->assess($url, $text);
// for example ['score' => 110, 'signals' => ['gambling', 'chained_shortener', 'executable_download']]
```

Сигналы: `csam`, `gambling`, `phishing`, `scam` и `adult` (шаблоны ключевых слов), `dangerous_scheme` (`javascript:`, `data:`, `vbscript:`, `file:`, `blob:`), `ip_host`, `punycode`, `chained_shortener`, `file_host`, `risky_tld`, `tunnel_host`, `brand_spoof`, `random_subdomain`, `executable_download`, `blacklisted` (плюс собственная категория строки чёрного списка), `malware` и `phishing` из фидов и `malware` от онлайн-сервисов. Каждый сигнал добавляет к оценке свой вес из `safety.weights`.

- `risky_tld`: хост заканчивается на один из `safety.risky_tlds`. Это может быть публичный суффикс из нескольких меток (`my.id`, `biz.id`).
- `tunnel_host`: хост входит в `safety.tunnel_hosts` или является поддоменом одного из них (ngrok, Cloudflare quick tunnels, localtunnel, serveo, localhost.run). Это жёсткая блокировка: страница на таком хосте живёт полдня, и ни один репутационный список не успеет о ней узнать.
- `brand_spoof`: метка хоста или сегмент пути содержит название индонезийского банка, кошелька или учреждения из `safety.brand_keywords` (`klikbca`, `brimo`, `livin`, `mandiri`, `bni`, `bsi`, `dana`, `kaget`, `ovo`, `gopay`, `shopeepay`, `telegram`, `whatsapp`, `kominfo`, `undian`, `blokir`, `tarif`), а хост не входит в `safety.official_domains` и не является поддоменом одного из них. Название из шести символов или короче совпадает только с целой меткой или сегментом (`bni.example.com`, `/dana`), поэтому `bandana.com` или `omnibus` никогда не совпадут; более длинное название совпадает и внутри метки или сегмента (`bankmandiri-login`, `telegramweb`). Путь читается после percent-декодирования. Официальный список (`klikbca.com`, `bca.co.id`, `bri.co.id`, `bankmandiri.co.id`, `bni.co.id`, `bankbsi.co.id`, `dana.id`, `ovo.id`, `gopay.co.id`, `shopee.co.id`, `telegram.org`, `t.me`, `whatsapp.com`, `wa.me`, `kominfo.go.id`, `komdigi.go.id`) покрывает только сам домен и его поддомены: `klikbca.com.example.xyz` и `notklikbca.com` — подделки.
- `executable_download`: адрес заканчивается на `.apk`, `.exe`, `.scr`, `.msi`, `.bat`, `.cmd`, `.ps1`, `.vbs` или `.jar`. Это жёсткая блокировка.

### Вердикт по ссылке

`LinkVerdict::evaluate()` — это единый ответ, по которому приложения действуют, когда посетитель переходит по ссылке: пропустить, сначала показать страницу с предупреждением или отказать. Он проходит по ссылке через редиректы, оценивает с помощью `LinkSafety::assess()` конечный адрес (и отправленный), добавляет то, что можно узнать только при запросе, и превращает сумму в вердикт.

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

`Verdict` — это объект-значение:

| Свойство | Значение |
| --- | --- |
| `verdict` | `Verdict::ALLOW` (`allow`), `Verdict::INTERSTITIAL` (`interstitial`) или `Verdict::BLOCK` (`block`). `allows()`, `needsInterstitial()` и `blocks()` говорят то же самое. |
| `score` | Сумма `safety.weights` по всем причинам. |
| `reasons` | Найденные сигналы, каждый по одному разу, в виде ключей, которые переводит приложение (`blacklisted`, `brand_spoof`, `redirect_chain`, `guest`, ...), а не готового текста. |
| `finalUrl` | Адрес, на который ведёт ссылка после редиректов, или отправленный адрес, если переходов не было. |
| `hops` | Сколько редиректов было пройдено. |

`toArray()` возвращает то же самое с ключом `final_url`, для ответа в JSON или строки лога.

Аргументы: `guest` равен true, если ссылка создана без аккаунта; `text` — заголовок или описание рядом со ссылкой; `unroll: false` полностью обходится без сети и оценивает только адрес (для проверки при создании или сканирования сохранённых ссылок); `remote: false` пропускает онлайн-сервисы.

Как проходится ссылка:

- Каждый переход сначала проходит через `UrlGuard::resolve()`, и запрос привязывается к проверенным адресам. Переход, у хоста которого нет ответа DNS или который резолвится в частный или локальный адрес, никогда не загружается и завершает цепочку как неразрешённый.
- Каждый переход — это запрос `HEAD`, а если сервер отклоняет `HEAD` или запрос не удался, то `GET`. Тело ответа никогда не читается: ответ принимается потоком, используются только статус и заголовки. Таймаут `safety.verdict.timeout` (4 с) на переход, с мобильным User-Agent из `safety.verdict.user_agent`, потому что фишинговые наборы часто отдают десктопному браузеру безобидную страницу.
- Проходится до `safety.verdict.max_hops` (5) редиректов; относительные заголовки `Location` разрешаются относительно перехода, который их прислал. Больше редиректов, ошибка транспорта или статус ошибки (4xx, 5xx) на последнем переходе считаются неразрешённым адресом.

Сигналы, которые добавляет только вердикт:

| Причина | Вес | Когда |
| --- | --- | --- |
| `redirect_chain` | 25 | `safety.verdict.chain_hops` (3) или больше редиректов. |
| `unresolved_destination` | 50 | Адрес назначения не удалось проверить (см. выше). Сам по себе не блокирует. |
| `executable_download` | 100 | Итоговый `Content-Type` входит в `safety.verdict.executable_types` (пакет Android, исполняемый файл или установщик Windows), как бы ни выглядел адрес. |
| `guest` | 25 | Ссылку создал гость. |
| `guest_ip_host` | 100 | Ссылка гостя, хост которой — голый IP-адрес. |
| `invalid_url` | 100 | Не веб-адрес: нет схемы, нет хоста или схема отличается от `http` и `https`. Ничего не загружается. |

Пороги заданы в `safety.verdict.thresholds`: оценка 70 и выше — `block`, 30 и выше — `interstitial`, всё ниже — `allow`. С весами по умолчанию ссылка гостя на домен `.xyz` получает interstitial (30 + 25), название банка на неофициальном хосте в рискованной TLD блокируется для всех (55 + 30), а `.apk`, хост туннеля, домен из чёрного списка или ссылка гостя на голый IP блокируются сами по себе.

`LinkVerdict` привязан как scoped-экземпляр, как и `LinkSafety`. Он ничего не кэширует; приложения сами решают, когда перепроверять сохранённую ссылку.

### Зашифрованные ссылки NoteHop

Ссылка NoteHop хранит ключ заметки во фрагменте (`https://n.hel.ink/TOKEN#k=KEY`, и `&a=` для ссылки владельца). Браузеры никогда не отправляют фрагмент на сервер, поэтому NoteHop не может прочитать заметку. Приложение, которое сохранит такую ссылку как адрес назначения, bio-ссылку или жалобу, будет держать ключ в своей базе данных, логах и редиректах. Поэтому `rejectionReason()` отклоняет адрес NoteHop, во фрагменте которого есть параметр `k` или `a` со значением, и в URL, и в свободном тексте, а `assess()` сообщает о нём сигналом `encrypted_note_key` (вес 0: такой адрес отклоняется при сохранении и никогда не оценивается).

Адрес читается так, как его читает браузер, а не так, как это делает `parse_url()`: табуляции и переводы строк удаляются, управляющие символы обрезаются, допускается любое число слешей после `https:`, обратный слеш завершает хост, последний `@` завершает данные пользователя, а percent-escape-последовательности, полноширинные буквы, `U+3002`, мягкие переносы и точка в конце приводят к тому хосту, к которому подключился бы браузер. `#?k=KEY` считается параметром `k`, потому что `URLSearchParams` отбрасывает один `?`. В Android-адресе `intent://` ищется резервный адрес NoteHop. Другие хосты, другие фрагменты и `?k=` в query (который NoteHop не читает) — обычные адреса назначения.

Хосты задаются в `safety.note_hosts` (`HOP_NOTE_HOSTS`, по умолчанию `note.hel.ink,n.hel.ink`). Приложение может добавить свои, например из реестра приложений, через `EncryptedNoteLink::resolveHostsUsing(fn () => [...])`.

Этот же класс помогает приложениям очистить то, что они уже хранят: `withoutFragment($url)`, `withoutSecrets($url)` (из фрагмента удаляются только `k` и `a`), а также `scrub($text)` и `scrubJson($json)` (все адреса NoteHop в тексте или в JSON-столбце; фрагменты других хостов остаются).

### Загрузка URL от пользователя

`UrlGuard::isSafe($url)` отвечает, можно ли загрузить URL с сервера. Он принимает только `http` и `https`, резолвит хост и отклоняет URL, если хотя бы один полученный адрес частный или зарезервированный. Сюда входят loopback, link-local (включая адреса облачных метаданных, например `169.254.169.254`) и частные диапазоны.

`isSafe()` даёт только «да» или «нет». Если потом передать URL HTTP-клиенту, клиент снова запросит хост, и враждебный DNS-сервер может во второй раз ответить иначе (DNS rebinding). Чтобы закрыть эту дыру, вызови `resolve()` один раз и заставь клиент подключаться к проверенным адресам:

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

`curlResolve()` возвращает одну запись `host:port:address1,address2` со всеми проверенными адресами. Обрабатывай редиректы сам и вызывай `resolve()` заново для каждого `Location`. `isSafe()` — это `resolve() !== null`.

Все адреса идут в одну запись, потому что libcurl хранит для пары хост и порт только последнюю запись. С отдельной записью на каждый адрес запрос был бы привязан только к последнему адресу и падал бы на машине без IPv6. С одной записью libcurl перебирает проверенные адреса, и запрос никогда не уходит на непроверенный адрес.

### Очистка пользовательского контента

```php
use Hop\Safety\SafeContent;

$html = SafeContent::html($rawUserHtml);
$href = SafeContent::url($userUrl);          // '#' unless the scheme is allowed
$css  = SafeContent::css($userCss);
```

- `html()` сохраняет теги форматирования и удаляет скрипты, обработчики событий, стили, формы и ссылки со схемой, отличной от `http`, `https`, `mailto` или `tel`. Ссылки получают `rel="nofollow noreferrer noopener"` и `target="_blank"`. Ввод обрезается до 50 000 символов.
- `url()` возвращает путь на том же сайте как есть, а любое другое значение — только если его схема есть в списке разрешённых (по умолчанию `LinkSafety::BIO_SCHEMES`). Всё остальное превращается в `#`.
- `css()` удаляет `<`, `@import`, `expression(`, `javascript:`, `behavior:` и `-moz-binding`, чтобы пользовательский CSS не мог закрыть свой элемент `<style>` или загрузить скрипты.
- `isSitePath()` отличает настоящий путь на том же сайте от пути, который браузер прочитает как другой хост, например `/\evil.com`.

Применяй их при выводе, а не только при сохранении, чтобы охватить и строки, сохранённые до появления правила.

### Фиды угроз

```bash
php artisan abuse:feeds
```

Команда скачивает список активных вредоносных URL от URLhaus и общественный фид OpenPhish в `storage/app/threat-feeds/malware.txt` и `phishing.txt`. `LinkSafety` сравнивает URL с ними точно (схема и начальный `www.` не учитываются), чтобы общая платформа не блокировалась целиком. LinkHop запускает команду ежедневно.

Фид скачивается во временный файл, проверяется и затем заменяет старый. Неудачная, обрезанная, пустая загрузка или загрузка без URL оставляет прежний список. Загрузка больше `safety.feed_max_bytes` прерывается. Команда завершается с ошибкой, только если не удался ни один фид.

### Онлайн-проверки

Когда выполняется `rejectionReason()` (или `assess(..., remote: true)`), опрашиваются три необязательных сервиса, и совпадение добавляет сигнал `malware`. Ответы кэшируются на `safety.providers.cache_hours` (12). Недоступный сервис или сервис, вернувший ошибку, никогда не блокирует URL.

- Spamhaus DBL через DNS, включён по умолчанию (`SPAMHAUS_DBL`). Ему нужен DNS-резолвер, который принимает Spamhaus, а публичные резолверы он не принимает.
- Google Safe Browsing, используется, если задан `SAFE_BROWSING_KEY`. Отправляется только адрес без `#fragment` (фрагмент никогда не отправляется на сервер, поэтому и третьей стороне он не сообщается), и кэшированный ответ хранится под тем же ключом.
- Проверка хостов URLhaus, используется, если задан `URLHAUS_AUTH_KEY`.

## Конфигурация

| Переменная | По умолчанию | Значение |
| --- | --- | --- |
| `SAFE_BROWSING_KEY` | нет | Включает проверку через Google Safe Browsing. |
| `URLHAUS_AUTH_KEY` | нет | Включает проверку хостов URLhaus. |
| `SPAMHAUS_DBL` | `true` | Проверка Spamhaus DBL через DNS. |
| `THREAT_FEED_MAX_BYTES` | `67108864` (64 MB) | Максимальный размер фида, который принимает `abuse:feeds`. |
| `HOP_NOTE_HOSTS` | `note.hel.ink,n.hel.ink` | Хосты NoteHop через запятую, ссылки которых несут ключ во фрагменте (в собственных установках добавь свои). |
| `ABUSE_REGISTER_PER_HOUR` | `3` | Читается приложениями, а не этим пакетом. |

Остальное находится в `config/safety.php`: шаблоны ключевых слов `patterns`, `block_categories`, веса сигналов `weights`, списки `shorteners`, `file_hosts`, `risky_tlds`, `tunnel_hosts`, `brand_keywords`, `official_domains` и `known_domains`, а также настройки `verdict` (`thresholds`, `max_hops`, `chain_hops`, `timeout`, `user_agent`, `executable_types`). Ключи `thresholds`, `suspend_after_disabled_items` и `purge_after_days` читает команда `abuse:scan` в LinkHop и BioHop, а не этот пакет.

## Тесты

Тесты в `tests/Feature` — это тесты Pest, которые запускаются внутри приложения, использующего пакет (HEL.ink содержит копию в `packages/safety`). Тестам в `tests/Unit` приложение не нужно. `tests/fixtures/note-link-variants.json` содержит 64 варианта записи адреса NoteHop с вердиктом парсера URL по WHATWG, полученные с помощью `tests/fixtures/note-link-truth.js` (Node). `tests/fixtures/verdict-cases.json` перечисляет адреса с ожидаемым для каждого вердиктом, среди них фишинговые хосты, которые попадали в LinkHop; тесты вердикта подменяют DNS и HTTP и никогда не обращаются к сети. Собственный CI этого репозитория проверяет, что `composer.json` валиден, что каждый PHP-файл проходит lint под PHP 8.3 и что таблица семейства ниже совпадает с канонической.

## Безопасность

Сообщай об уязвимостях так, как описано в [SECURITY.md](SECURITY.md), а не в публичных issues.

## Список изменений

См. [CHANGELOG.md](CHANGELOG.md).

## Собственный хостинг

Чтобы запустить HOP, ставить этот пакет вручную не нужно. LinkHop и BioHop указывают этот публичный репозиторий в своём `composer.json`, поэтому `composer install` скачивает его без токена, а хаб ([HEL.ink](https://github.com/navi-crwn/HEL.ink)) содержит копию в `packages/safety`. Пакет работает вообще без ключей: Safe Browsing и URLhaus выключены, пока ты не задашь `SAFE_BROWSING_KEY` и `URLHAUS_AUTH_KEY`, проверка Spamhaus — это DNS-запрос без ключа (`SPAMHAUS_DBL=false` её выключает), а без таблицы `domain_blacklist` из LinkHop используется список из пакета. Если ты запускаешь NoteHop, укажи в `HOP_NOTE_HOSTS` его имена хостов, чтобы зашифрованные ссылки на заметки в твоём домене распознавались; по умолчанию там хосты hel.ink. Остальное описано в общем руководстве семейства: [docs/self-hosting.md](https://github.com/navi-crwn/HEL.ink/blob/main/docs/self-hosting.md).

## Семейство HOP

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

## Лицензия

Общие пакеты HOP — это программное обеспечение с открытым исходным кодом под [лицензией MIT](LICENSE).
