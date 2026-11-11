# hop/safety

[English](README.md) · **简体中文** · [Deutsch](README.de.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md) · [Português (Brasil)](README.pt-BR.md) · [Español](README.es.md) · [Français](README.fr.md) · [Русский](README.ru.md)

HOP 系列（[hel.ink](https://hel.ink)）的 URL 与内容安全规则，打包成一个 Laravel 包。LinkHop、BioHop 和 hub 都使用它，因此一个链接或一个 bio 页面在每个应用里都按同样的方式判定。

## 提供的内容

| 类 | 作用 |
| --- | --- |
| `Hop\Safety\LinkSafety` | 根据关键词模式、高风险主机、品牌仿冒、共享的域名黑名单、本地威胁源和可选的在线查询，判定一个目标 URL（以及它周围的文本）。 |
| `Hop\Safety\LinkVerdict` | 沿着链接的重定向一路跟随，把分数变成一个答案，即 `Verdict`：`allow`、`interstitial` 或 `block`，并附带原因、最终地址和跳转次数。 |
| `Hop\Safety\EncryptedNoteLink` | 识别加密的 NoteHop 地址（其密钥位于 `#fragment` 中），让保存目标地址的应用可以拒绝它。`LinkSafety` 会用到它。 |
| `Hop\Safety\UrlGuard` | 决定服务器能否抓取某个 URL：只允许 http 或 https，并且主机解析出的每个地址都必须是公网地址。它还可以把连接固定到已检查过的地址上。 |
| `Hop\Safety\SafeContent` | 在显示前清理用户内容：净化 HTML，把不安全的链接改成 `#`，并去掉可能跳出 `<style>` 元素的内容。 |
| `Hop\Safety\Models\DomainBlacklist` | `domain_blacklist` 表的 Eloquent 模型。 |
| `abuse:feeds` | 下载 URLhaus 和 OpenPhish 威胁源的 Artisan 命令。 |

## 环境要求

- PHP 8.3、`ext-intl`、Laravel 12（`illuminate/support` ^12.0）以及 `symfony/html-sanitizer` ^7.4。`EncryptedNoteLink` 借助 `ext-intl` 像浏览器一样读取主机名。
- 黑名单需要一张 `domain_blacklist` 表，包含 `domain`、`match_type`（`exact` 或 `wildcard`）和 `category`。这张表归 LinkHop 所有，其他应用只读取它。没有这张表时，`LinkSafety` 只使用包内自带的列表 `resources/domain_blacklist.json`。该列表的列相同，并与表一起读取。

## 安装

本包不在 Packagist 上，也还没有打过标签的版本。把仓库加入应用的 `composer.json`，并依赖 `dev-main`。如果需要固定版本，可以锁定某个提交：

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

然后运行：

```bash
composer update hop/safety
```

Laravel 的包自动发现会注册 `Hop\Safety\SafetyServiceProvider`。它会合并 `safety` 配置，把 `LinkSafety` 绑定为 scoped 实例（这样黑名单在每个请求或任务中只加载一次），并在控制台中运行时注册 `abuse:feeds`。要修改配置，先发布它：

```bash
php artisan vendor:publish --tag=hop-safety-config
```

## 用法

### 检查目标 URL

`LinkSafety::rejectionReason()` 返回一条可以展示给用户的消息；URL 被允许时返回 `null`。第二个参数是 URL 附近的文本（标题或描述），第三个参数是允许的协议列表。默认是 `LinkSafety::WEB_SCHEMES`（`http`、`https`）；`LinkSafety::BIO_SCHEMES` 额外加入 `mailto`、`tel` 和 `sms`。

```php
use Hop\Safety\LinkSafety;

$reason = app(LinkSafety::class)->rejectionReason($url, $title);
if ($reason !== null) {
    return back()->withErrors(['url' => $reason]);
}
```

以下情况会拒绝 URL：协议不被允许、缺少主机、是加密的 NoteHop 地址（见下文），或者命中 `safety.block_categories` 中列出的某个类别。这个调用也会询问在线服务（见下文）。

`assess()` 给出细节而不是结论：一个分数和找到的信号列表。除非传入 `remote: true`，它会跳过在线服务。

```php
$result = app(LinkSafety::class)->assess($url, $text);
// for example ['score' => 110, 'signals' => ['gambling', 'chained_shortener', 'executable_download']]
```

信号包括 `csam`、`gambling`、`phishing`、`scam` 和 `adult`（关键词模式），`dangerous_scheme`（`javascript:`、`data:`、`vbscript:`、`file:`、`blob:`），`ip_host`、`punycode`、`chained_shortener`、`file_host`、`risky_tld`、`tunnel_host`、`brand_spoof`、`random_subdomain`、`executable_download`、`blacklisted`（再加上黑名单行自身的类别），来自威胁源的 `malware` 和 `phishing`，以及来自在线服务的 `malware`。每个信号把它在 `safety.weights` 中的权重加到分数上。

- `risky_tld`：主机以 `safety.risky_tlds` 中的某一项结尾，该项可以是由多个标签组成的公共后缀（`my.id`、`biz.id`）。
- `tunnel_host`：主机是 `safety.tunnel_hosts` 中的某一项或其子域名（ngrok、Cloudflare quick tunnels、localtunnel、serveo、localhost.run）。这是硬性拦截：这些主机上的页面只存活一个下午，任何信誉列表都来不及收录。
- `brand_spoof`：主机的某个标签或路径的某一段包含 `safety.brand_keywords` 中某家印度尼西亚银行、钱包或机构的名称（`klikbca`、`brimo`、`livin`、`mandiri`、`bni`、`bsi`、`dana`、`kaget`、`ovo`、`gopay`、`shopeepay`、`telegram`、`whatsapp`、`kominfo`、`undian`、`blokir`、`tarif`），而主机既不是 `safety.official_domains` 中的某一项，也不是其子域名。六个字符及以下的名称只匹配完整的标签或路径段（`bni.example.com`、`/dana`），所以 `bandana.com` 或 `omnibus` 永远不会命中；更长的名称在标签或路径段内部也会匹配（`bankmandiri-login`、`telegramweb`）。路径会先做百分号解码再读取。官方列表（`klikbca.com`、`bca.co.id`、`bri.co.id`、`bankmandiri.co.id`、`bni.co.id`、`bankbsi.co.id`、`dana.id`、`ovo.id`、`gopay.co.id`、`shopee.co.id`、`telegram.org`、`t.me`、`whatsapp.com`、`wa.me`、`kominfo.go.id`、`komdigi.go.id`）只覆盖该域名及其子域名：`klikbca.com.example.xyz` 和 `notklikbca.com` 都是仿冒。
- `executable_download`：地址以 `.apk`、`.exe`、`.scr`、`.msi`、`.bat`、`.cmd`、`.ps1`、`.vbs` 或 `.jar` 结尾。这是硬性拦截。

### 链接判定

`LinkVerdict::evaluate()` 是访客点开链接时各应用据以行动的唯一答案：直接放行、先显示警告页，或者拒绝。它沿着链接的重定向一路跟随，用 `LinkSafety::assess()` 给最终落地的地址（以及提交的地址）打分，加上只有实际请求才能发现的信息，再把总分变成判定结果。

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

`Verdict` 是一个值对象：

| 属性 | 含义 |
| --- | --- |
| `verdict` | `Verdict::ALLOW`（`allow`）、`Verdict::INTERSTITIAL`（`interstitial`）或 `Verdict::BLOCK`（`block`）。`allows()`、`needsInterstitial()` 和 `blocks()` 表达的是同一件事。 |
| `score` | 所有原因在 `safety.weights` 中的权重之和。 |
| `reasons` | 找到的信号，每个只出现一次，以键的形式给出，由应用自行翻译（`blacklisted`、`brand_spoof`、`redirect_chain`、`guest`、...），从不是成句的文字。 |
| `finalUrl` | 链接经过重定向后最终落地的地址；如果没有跟随任何重定向，就是提交的地址。 |
| `hops` | 跟随了多少次重定向。 |

`toArray()` 给出相同内容，但键名为 `final_url`，可用于 JSON 响应或日志行。

参数说明：`guest` 在链接由未登录用户创建时为 true；`text` 是链接周围的标题或描述；`unroll: false` 完全不访问网络，只判定地址本身（用于创建时的检查或对已保存链接的扫描）；`remote: false` 跳过在线服务。

链接的跟随方式：

- 每一跳都先经过 `UrlGuard::resolve()`，请求被固定到已检查的地址上。如果某一跳的主机没有 DNS 应答，或者解析到私有或本地地址，就永远不会去抓取，并以“无法解析”结束整条链。
- 每一跳都是一个 `HEAD` 请求；如果服务器拒绝 `HEAD` 或请求失败，则改用 `GET`。从不读取响应体：响应以流的方式接收，只使用状态码和响应头。每跳超时为 `safety.verdict.timeout`（4 秒），使用 `safety.verdict.user_agent` 中的移动端 User-Agent，因为钓鱼工具包常常给桌面浏览器返回一个无害页面。
- 最多跟随 `safety.verdict.max_hops`（5）次重定向；相对的 `Location` 头按发送它的那一跳来解析。超过这个次数、出现传输错误，或最后一跳返回错误状态（4xx、5xx），都算作无法解析。

只有判定阶段才会添加的信号：

| 原因 | 权重 | 条件 |
| --- | --- | --- |
| `redirect_chain` | 25 | 重定向达到 `safety.verdict.chain_hops`（3）次或更多。 |
| `unresolved_destination` | 50 | 无法验证目标地址（见上文）。单独出现时不会拦截。 |
| `executable_download` | 100 | 最终的 `Content-Type` 属于 `safety.verdict.executable_types`（Android 安装包、Windows 可执行文件或安装程序），无论地址看起来是什么样。 |
| `guest` | 25 | 链接由未登录用户创建。 |
| `guest_ip_host` | 100 | 未登录用户创建的链接，且主机是裸 IP 地址。 |
| `invalid_url` | 100 | 不是网页地址：没有协议、没有主机，或协议不是 `http` 和 `https`。不会抓取任何内容。 |

阈值在 `safety.verdict.thresholds` 中：分数达到 70 及以上为 `block`，30 及以上为 `interstitial`，更低则为 `allow`。按默认权重，未登录用户创建的指向 `.xyz` 域名的链接会显示中间警告页（30 + 25），在高风险 TLD 下的非官方主机上出现银行名称会对所有人拦截（55 + 30），而 `.apk`、隧道主机、黑名单中的域名或未登录用户指向裸 IP 的链接，单独一项就足以拦截。

`LinkVerdict` 和 `LinkSafety` 一样绑定为 scoped 实例。它不做缓存；何时重新检查已保存的链接由各应用决定。

### 加密的 NoteHop 链接

NoteHop 链接把笔记密钥放在片段中（`https://n.hel.ink/TOKEN#k=KEY`，所有者链接还带 `&a=`）。浏览器从不把片段发送给服务器，所以 NoteHop 读不到笔记内容。如果某个应用把这样的链接保存为目标地址、bio 链接或举报内容，密钥就会留在它的数据库、日志和重定向里。因此 `rejectionReason()` 会拒绝片段中带有值的 `k` 或 `a` 参数的 NoteHop 地址，无论它出现在 URL 中还是自由文本中；`assess()` 则把它报告为 `encrypted_note_key` 信号（权重为 0：保存时就被拒绝，从不计分）。

地址按浏览器的方式读取，而不是按 `parse_url()` 的方式：去掉制表符和换行，裁掉控制字符，`https:` 后面可以有任意数量的斜杠，反斜杠结束主机部分，最后一个 `@` 结束用户信息部分；百分号转义、全角字母、`U+3002`、软连字符和末尾的点，最终都指向浏览器实际会连接的那个主机。`#?k=KEY` 被视为 `k` 参数，因为 `URLSearchParams` 会丢掉一个 `?`。对于 Android 的 `intent://` 地址，会在其中查找 NoteHop 的回退地址。其他主机、其他片段以及查询串中的 `?k=`（NoteHop 不读取它）都是普通目标地址。

主机列表是 `safety.note_hosts`（`HOP_NOTE_HOSTS`，默认 `note.hel.ink,n.hel.ink`）。应用可以通过 `EncryptedNoteLink::resolveHostsUsing(fn () => [...])` 添加更多主机，例如来自自己的应用注册表。

同一个类还能帮助应用清理已经保存的数据：`withoutFragment($url)`、`withoutSecrets($url)`（只从片段中移除 `k` 和 `a`），以及 `scrub($text)` 和 `scrubJson($json)`（处理文本或 JSON 列中的所有 NoteHop 地址；其他主机的片段保持不变）。

### 抓取用户提供的 URL

`UrlGuard::isSafe($url)` 回答服务器能否抓取某个 URL。它只接受 `http` 和 `https`，会解析主机，只要有任何一个解析出的地址是私有或保留地址，就拒绝该 URL。这涵盖回环地址、链路本地地址（包括 `169.254.169.254` 这类云元数据地址）以及私有地址段。

`isSafe()` 只给出“是”或“否”。如果随后把 URL 交给 HTTP 客户端，客户端会再查一次主机，而恶意的 DNS 服务器第二次可以给出不同的答案（DNS rebinding）。要堵住这个漏洞，请只调用一次 `resolve()`，并让客户端连接到已检查过的地址：

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

`curlResolve()` 返回单个 `host:port:address1,address2` 条目，列出所有已检查的地址。请自行处理重定向，并对每个 `Location` 再次调用 `resolve()`。`isSafe()` 就是 `resolve() !== null`。

所有地址放在同一个条目里，是因为 libcurl 对同一主机和端口只保留最后一个条目。如果每个地址一个条目，请求就只会被固定到最后一个地址，在没有 IPv6 的机器上会失败。用一个条目时，libcurl 会依次尝试已检查的地址，请求永远不会发往未经检查的地址。

### 净化用户内容

```php
use Hop\Safety\SafeContent;

$html = SafeContent::html($rawUserHtml);
$href = SafeContent::url($userUrl);          // '#' unless the scheme is allowed
$css  = SafeContent::css($userCss);
```

- `html()` 保留格式标签，去掉脚本、事件处理器、样式、表单，以及协议不是 `http`、`https`、`mailto` 或 `tel` 的链接。链接会加上 `rel="nofollow noreferrer noopener"` 和 `target="_blank"`。输入在 50,000 个字符处截断。
- `url()` 对同站路径原样返回；其他值只有在协议属于允许列表（默认 `LinkSafety::BIO_SCHEMES`）时才返回。其余一律变成 `#`。
- `css()` 去掉 `<`、`@import`、`expression(`、`javascript:`、`behavior:` 和 `-moz-binding`，使自定义 CSS 无法闭合它所在的 `<style>` 元素，也无法加载脚本。
- `isSitePath()` 区分真正的同站路径和会被浏览器当作另一个主机的路径，例如 `/\evil.com`。

请在渲染时使用这些方法，而不只是在保存时，这样在某条规则出现之前保存的数据也能被覆盖。

### 威胁源

```bash
php artisan abuse:feeds
```

该命令把 URLhaus 的在线恶意软件列表和 OpenPhish 社区源下载到 `storage/app/threat-feeds/malware.txt` 和 `phishing.txt`。`LinkSafety` 将 URL 与它们做精确匹配（忽略协议和开头的 `www.`），这样不会把整个共享平台一并拦截。LinkHop 每天运行一次该命令。

每个源先下载到临时文件，检查后再覆盖旧文件。下载失败、被截断、为空或不含 URL 时，保留之前的列表。超过 `safety.feed_max_bytes` 的下载会被中止。只有所有源都失败时，命令才会失败。

### 在线查询

运行 `rejectionReason()`（或 `assess(..., remote: true)`）时，会询问三个可选的服务，命中时添加 `malware` 信号。应答会缓存 `safety.providers.cache_hours`（12）小时。无法访问或返回错误的服务从不拦截 URL。

- 通过 DNS 查询的 Spamhaus DBL，默认开启（`SPAMHAUS_DBL`）。它需要 Spamhaus 接受的 DNS 解析器，公共解析器不被接受。
- Google Safe Browsing，在设置了 `SAFE_BROWSING_KEY` 时使用。只发送去掉 `#fragment` 的地址（片段从不发送给服务器，因此也不会告诉第三方），缓存应答的键也按同样的方式生成。
- URLhaus 主机查询，在设置了 `URLHAUS_AUTH_KEY` 时使用。

## 配置

| 变量 | 默认值 | 含义 |
| --- | --- | --- |
| `SAFE_BROWSING_KEY` | 无 | 启用 Google Safe Browsing 查询。 |
| `URLHAUS_AUTH_KEY` | 无 | 启用 URLhaus 主机查询。 |
| `SPAMHAUS_DBL` | `true` | 通过 DNS 进行 Spamhaus DBL 查询。 |
| `THREAT_FEED_MAX_BYTES` | `67108864`（64 MB） | `abuse:feeds` 接受的最大威胁源大小。 |
| `HOP_NOTE_HOSTS` | `note.hel.ink,n.hel.ink` | 以逗号分隔的 NoteHop 主机，其链接在片段中携带密钥（自行托管的部署需加入自己的主机）。 |
| `ABUSE_REGISTER_PER_HOUR` | `3` | 由各应用读取，而不是本包。 |

其余配置在 `config/safety.php` 中：关键词模式 `patterns`、`block_categories`、每个信号的权重 `weights`，列表 `shorteners`、`file_hosts`、`risky_tlds`、`tunnel_hosts`、`brand_keywords`、`official_domains` 和 `known_domains`，以及 `verdict` 设置（`thresholds`、`max_hops`、`chain_hops`、`timeout`、`user_agent`、`executable_types`）。键 `thresholds`、`suspend_after_disabled_items` 和 `purge_after_days` 由 LinkHop 和 BioHop 的 `abuse:scan` 命令读取，而不是本包。

## 测试

`tests/Feature` 中的测试是 Pest 测试，在使用本包的应用内运行（HEL.ink 在 `packages/safety` 中带有一份副本）。`tests/Unit` 中的测试不需要应用。`tests/fixtures/note-link-variants.json` 收录了 NoteHop 地址的 64 种写法，以及 WHATWG URL 解析器对每种写法的判定，由 `tests/fixtures/note-link-truth.js`（Node）生成。`tests/fixtures/verdict-cases.json` 列出若干地址及其预期判定，其中包括曾经进入 LinkHop 的钓鱼主机；判定测试会模拟 DNS 和 HTTP，从不访问网络。本仓库自己的 CI 会检查 `composer.json` 是否有效、每个 PHP 文件能否在 PHP 8.3 下通过语法检查，以及下面的系列表格是否与标准版本一致。

## 安全

请按 [SECURITY.md](SECURITY.md) 中的说明报告漏洞，不要提交公开 issue。

## 更新日志

参见 [CHANGELOG.md](CHANGELOG.md)。

## 自行托管

运行 HOP 时不需要手动安装本包。LinkHop 和 BioHop 在各自的 `composer.json` 中列出了这个公开仓库，因此 `composer install` 无需令牌即可获取它；hub（[HEL.ink](https://github.com/navi-crwn/HEL.ink)）则在 `packages/safety` 中带有一份副本。它完全不需要任何密钥也能工作：在你设置 `SAFE_BROWSING_KEY` 和 `URLHAUS_AUTH_KEY` 之前，Safe Browsing 和 URLhaus 保持关闭；Spamhaus 检查是一次无需密钥的 DNS 查询（`SPAMHAUS_DBL=false` 可关闭它）；没有 LinkHop 的 `domain_blacklist` 表时，它使用自带的列表。如果你运行 NoteHop，请把 `HOP_NOTE_HOSTS` 设为它的主机名，这样你域名上的加密笔记链接才能被识别；默认值是 hel.ink 的主机。其余内容见系列指南：[docs/self-hosting.md](https://github.com/navi-crwn/HEL.ink/blob/main/docs/self-hosting.md)。

## HOP 系列

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

## 许可证

HOP 共享包是开源软件，采用 [MIT 许可证](LICENSE)。
