# hop/safety

[English](README.md) · [简体中文](README.zh-CN.md) · [Deutsch](README.de.md) · [Bahasa Indonesia](README.id.md) · **日本語** · [Português (Brasil)](README.pt-BR.md) · [Español](README.es.md) · [Français](README.fr.md) · [Русский](README.ru.md)

HOP ファミリー（[hel.ink](https://hel.ink)）の URL とコンテンツの安全ルールを、1 つの Laravel パッケージにまとめたものです。LinkHop、BioHop、hub がこれを使うので、リンクや bio ページはどのアプリでも同じ基準で判定されます。

## 提供するもの

| クラス | 役割 |
| --- | --- |
| `Hop\Safety\LinkSafety` | 遷移先 URL（とその周りのテキスト）を、キーワードパターン、危険なホスト、ブランドのなりすまし、共有のドメインブラックリスト、ローカルの脅威フィード、任意のオンライン照会で判定します。 |
| `Hop\Safety\LinkVerdict` | リンクをリダイレクトの先までたどり、スコアを 1 つの答え、つまり `Verdict` に変えます。値は `allow`、`interstitial`、`block` のいずれかで、理由、最終アドレス、ホップ数が付きます。 |
| `Hop\Safety\EncryptedNoteLink` | 鍵が `#fragment` にある暗号化された NoteHop アドレスを見分け、遷移先を保存するアプリがそれを拒否できるようにします。`LinkSafety` が使います。 |
| `Hop\Safety\UrlGuard` | サーバーがその URL を取得してよいかを決めます。http か https のみで、ホストが解決されるアドレスはすべて公開アドレスである必要があります。接続を確認済みのアドレスに固定することもできます。 |
| `Hop\Safety\SafeContent` | 表示前にユーザーのコンテンツをきれいにします。HTML をサニタイズし、安全でないリンクを `#` に変え、`<style>` 要素の外に抜け出せるものを取り除きます。 |
| `Hop\Safety\Models\DomainBlacklist` | `domain_blacklist` テーブルの Eloquent モデルです。 |
| `abuse:feeds` | URLhaus と OpenPhish のフィードをダウンロードする Artisan コマンドです。 |

## 必要なもの

- PHP 8.3、`ext-intl`、Laravel 12（`illuminate/support` ^12.0）、`symfony/html-sanitizer` ^7.4。`EncryptedNoteLink` は `ext-intl` を使って、ブラウザと同じ方法でホストを読み取ります。
- ブラックリスト用に、`domain`、`match_type`（`exact` または `wildcard`）、`category` を持つ `domain_blacklist` テーブル。テーブルは LinkHop が所有し、ほかのアプリは読むだけです。テーブルがない場合、`LinkSafety` はパッケージに同梱のリスト `resources/domain_blacklist.json` だけを使います。このリストは同じ列を持ち、テーブルと合わせて読み込まれます。

## インストール

このパッケージは Packagist になく、タグ付きのリリースもまだありません。アプリの `composer.json` にリポジトリを追加して `dev-main` を require してください。バージョンを固定したい場合はコミットを指定します。

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

続けて実行します。

```bash
composer update hop/safety
```

Laravel のパッケージディスカバリーが `Hop\Safety\SafetyServiceProvider` を登録します。このプロバイダーは `safety` 設定をマージし、`LinkSafety` を scoped インスタンスとしてバインドし（そのためブラックリストはリクエストやジョブごとに 1 回だけ読み込まれます）、コンソールで動くときは `abuse:feeds` を登録します。設定を変えるには、公開してください。

```bash
php artisan vendor:publish --tag=hop-safety-config
```

## 使い方

### 遷移先 URL のチェック

`LinkSafety::rejectionReason()` は、ユーザーに表示できるメッセージを返します。URL が許可される場合は `null` を返します。第 2 引数は URL の近くにあるテキスト（タイトルや説明）、第 3 引数は許可するスキームのリストです。デフォルトは `LinkSafety::WEB_SCHEMES`（`http`、`https`）で、`LinkSafety::BIO_SCHEMES` は `mailto`、`tel`、`sms` を加えます。

```php
use Hop\Safety\LinkSafety;

$reason = app(LinkSafety::class)->rejectionReason($url, $title);
if ($reason !== null) {
    return back()->withErrors(['url' => $reason]);
}
```

URL が拒否されるのは、スキームが許可されていないとき、ホストがないとき、暗号化された NoteHop アドレスのとき（後述）、または `safety.block_categories` にあるカテゴリーに一致したときです。この呼び出しはオンラインのプロバイダーにも問い合わせます（後述）。

`assess()` は判定ではなく詳細を返します。スコアと、見つかったシグナルのリストです。`remote: true` を渡さない限り、オンラインのプロバイダーは使いません。

```php
$result = app(LinkSafety::class)->assess($url, $text);
// for example ['score' => 110, 'signals' => ['gambling', 'chained_shortener', 'executable_download']]
```

シグナルは `csam`、`gambling`、`phishing`、`scam`、`adult`（キーワードパターン）、`dangerous_scheme`（`javascript:`、`data:`、`vbscript:`、`file:`、`blob:`）、`ip_host`、`punycode`、`chained_shortener`、`file_host`、`risky_tld`、`tunnel_host`、`brand_spoof`、`random_subdomain`、`executable_download`、`blacklisted`（加えてブラックリストの行自身のカテゴリー）、フィード由来の `malware` と `phishing`、オンラインプロバイダー由来の `malware` です。各シグナルは `safety.weights` にある重みをスコアに加えます。

- `risky_tld`: ホストが `safety.risky_tlds` のいずれかで終わります。これは複数のラベルからなるパブリックサフィックス（`my.id`、`biz.id`）の場合もあります。
- `tunnel_host`: ホストが `safety.tunnel_hosts` のいずれか、またはそのサブドメインです（ngrok、Cloudflare quick tunnels、localtunnel、serveo、localhost.run）。無条件でブロックします。そこにあるページは半日しか存在せず、どの評判リストにも載りようがないためです。
- `brand_spoof`: ホストのラベルかパスのセグメントに、`safety.brand_keywords` にあるインドネシアの銀行、ウォレット、機関の名前（`klikbca`、`brimo`、`livin`、`mandiri`、`bni`、`bsi`、`dana`、`kaget`、`ovo`、`gopay`、`shopeepay`、`telegram`、`whatsapp`、`kominfo`、`undian`、`blokir`、`tarif`）が含まれ、かつホストが `safety.official_domains` のいずれでもそのサブドメインでもない場合です。6 文字以下の名前はラベルやセグメント全体とだけ一致します（`bni.example.com`、`/dana`）。そのため `bandana.com` や `omnibus` は一致しません。それより長い名前はラベルやセグメントの途中でも一致します（`bankmandiri-login`、`telegramweb`）。パスはパーセントデコードしてから読みます。公式リスト（`klikbca.com`、`bca.co.id`、`bri.co.id`、`bankmandiri.co.id`、`bni.co.id`、`bankbsi.co.id`、`dana.id`、`ovo.id`、`gopay.co.id`、`shopee.co.id`、`telegram.org`、`t.me`、`whatsapp.com`、`wa.me`、`kominfo.go.id`、`komdigi.go.id`）が対象とするのはそのドメインとサブドメインだけです。`klikbca.com.example.xyz` や `notklikbca.com` はなりすましです。
- `executable_download`: アドレスが `.apk`、`.exe`、`.scr`、`.msi`、`.bat`、`.cmd`、`.ps1`、`.vbs`、`.jar` のいずれかで終わります。無条件でブロックします。

### リンクの判定

`LinkVerdict::evaluate()` は、訪問者がリンクをたどったときにアプリが従う唯一の答えです。そのまま転送するか、先に警告ページを表示するか、拒否するかを決めます。リンクをリダイレクトの先までたどり、到達したアドレス（と送信されたアドレス）を `LinkSafety::assess()` で採点し、実際の取得でしかわからない情報を加え、合計を判定に変えます。

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

`Verdict` は値オブジェクトです。

| プロパティ | 意味 |
| --- | --- |
| `verdict` | `Verdict::ALLOW`（`allow`）、`Verdict::INTERSTITIAL`（`interstitial`）、`Verdict::BLOCK`（`block`）のいずれか。`allows()`、`needsInterstitial()`、`blocks()` も同じことを表します。 |
| `score` | すべての理由について `safety.weights` を合計した値。 |
| `reasons` | 見つかったシグナル。それぞれ 1 回ずつ、アプリが翻訳するためのキーとして入ります（`blacklisted`、`brand_spoof`、`redirect_chain`、`guest`、...）。文章は入りません。 |
| `finalUrl` | リダイレクトの後にリンクが到達するアドレス。何もたどらなかった場合は送信されたアドレス。 |
| `hops` | たどったリダイレクトの数。 |

`toArray()` は同じ内容を `final_url` というキーで返します。JSON のレスポンスやログ行に使えます。

引数: `guest` はアカウントなしでリンクが作られたときに true です。`text` はリンクの周りのタイトルや説明です。`unroll: false` はネットワークをまったく使わず、アドレスだけで判定します（作成時のチェックや、保存済みリンクのスキャン向け）。`remote: false` はオンラインのプロバイダーを使いません。

リンクのたどり方:

- 各ホップはまず `UrlGuard::resolve()` を通り、リクエストは確認済みのアドレスに固定されます。ホストに DNS の応答がないホップや、プライベートまたはローカルのアドレスに解決されるホップは取得せず、未解決としてチェーンを終えます。
- 各ホップは `HEAD` リクエストで、サーバーが `HEAD` を拒否したかリクエストが失敗した場合は `GET` を使います。ボディは読みません。レスポンスはストリームで受け取り、ステータスとヘッダーだけを使います。タイムアウトはホップごとに `safety.verdict.timeout`（4 秒）で、`safety.verdict.user_agent` のモバイル User-Agent を使います。フィッシングキットはデスクトップブラウザに無害なページを返すことが多いためです。
- リダイレクトは最大 `safety.verdict.max_hops`（5）回までたどります。相対的な `Location` ヘッダーは、それを送ってきたホップを基準に解決します。それを超えた場合、通信エラーの場合、最後のホップがエラーステータス（4xx、5xx）の場合は未解決として扱います。

判定だけが加えるシグナル:

| 理由 | 重み | 条件 |
| --- | --- | --- |
| `redirect_chain` | 25 | リダイレクトが `safety.verdict.chain_hops`（3）回以上。 |
| `unresolved_destination` | 50 | 遷移先を確認できなかった（前述）。これだけではブロックしない。 |
| `executable_download` | 100 | 最終的な `Content-Type` が `safety.verdict.executable_types`（Android パッケージ、Windows の実行ファイルやインストーラー）のいずれか。アドレスの見た目は問わない。 |
| `guest` | 25 | ゲストが作ったリンク。 |
| `guest_ip_host` | 100 | ホストが IP アドレスそのものであるゲストのリンク。 |
| `invalid_url` | 100 | Web アドレスではない。スキームがない、ホストがない、またはスキームが `http` と `https` 以外。何も取得しない。 |

しきい値は `safety.verdict.thresholds` にあります。スコアが 70 以上なら `block`、30 以上なら `interstitial`、それ未満なら `allow` です。デフォルトの重みでは、ゲストが作った `.xyz` ドメインへのリンクはインタースティシャルになり（30 + 25）、危険な TLD 配下の非公式ホストにある銀行名は誰に対してもブロックされ（55 + 30）、`.apk`、トンネルホスト、ブラックリストにあるドメイン、ゲストによる IP アドレスそのものへのリンクは、それぞれ単独でブロックになります。

`LinkVerdict` は `LinkSafety` と同じく scoped インスタンスとしてバインドされます。キャッシュはしません。保存済みリンクをいつ再チェックするかはアプリが決めます。

### 暗号化された NoteHop リンク

NoteHop のリンクはノートの鍵をフラグメントに持ちます（`https://n.hel.ink/TOKEN#k=KEY`、オーナー用リンクでは `&a=` も）。ブラウザはフラグメントをサーバーに送らないので、NoteHop はノートを読めません。そうしたリンクを遷移先、bio リンク、通報として保存するアプリは、鍵をデータベース、ログ、リダイレクトに残すことになります。そのため `rejectionReason()` は、フラグメントに値付きの `k` または `a` パラメーターを持つ NoteHop アドレスを、URL でも自由テキストでも拒否します。`assess()` はこれを `encrypted_note_key` シグナルとして報告します（重みは 0。保存時に拒否され、採点されることはありません）。

アドレスは `parse_url()` のやり方ではなく、ブラウザと同じやり方で読みます。タブと改行は取り除き、制御文字は切り詰め、`https:` の後のスラッシュはいくつでも受け付け、バックスラッシュでホストが終わり、最後の `@` でユーザー情報が終わります。パーセントエスケープ、全角文字、`U+3002`、ソフトハイフン、末尾のドットは、どれもブラウザが実際に接続するホストにたどり着きます。`#?k=KEY` は `k` パラメーターとして扱います。`URLSearchParams` が `?` を 1 つ捨てるためです。Android の `intent://` アドレスは、中に NoteHop のフォールバックアドレスがないか調べます。ほかのホスト、ほかのフラグメント、クエリ内の `?k=`（NoteHop は読みません）は普通の遷移先です。

ホストは `safety.note_hosts`（`HOP_NOTE_HOSTS`、デフォルトは `note.hel.ink,n.hel.ink`）です。アプリは `EncryptedNoteLink::resolveHostsUsing(fn () => [...])` で、たとえば自分のアプリレジストリからホストを追加できます。

同じクラスは、アプリがすでに保存しているデータの掃除にも使えます。`withoutFragment($url)`、`withoutSecrets($url)`（フラグメントから外れるのは `k` と `a` だけ）、そして `scrub($text)` と `scrubJson($json)`（テキストや JSON 列にあるすべての NoteHop アドレスが対象。ほかのホストのフラグメントは残ります）です。

### ユーザーが入力した URL の取得

`UrlGuard::isSafe($url)` は、その URL をサーバーから取得してよいかを答えます。`http` と `https` だけを受け付け、ホストを解決し、解決されたアドレスのどれかがプライベートまたは予約済みなら URL を拒否します。これにはループバック、リンクローカル（`169.254.169.254` のようなクラウドのメタデータアドレスを含む）、プライベートアドレス範囲が含まれます。

`isSafe()` が返すのは yes か no だけです。その URL を後で HTTP クライアントに渡すと、クライアントはホストをもう一度引き、悪意のある DNS サーバーは 2 回目に別の答えを返せます（DNS rebinding）。この穴をふさぐには、`resolve()` を 1 回だけ呼び、確認済みのアドレスにクライアントを接続させます。

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

`curlResolve()` は、確認済みのアドレスをすべて並べた `host:port:address1,address2` 形式のエントリーを 1 つ返します。リダイレクトは自分でたどり、`Location` ごとに `resolve()` を呼び直してください。`isSafe()` は `resolve() !== null` と同じです。

すべてのアドレスを 1 つのエントリーに入れるのは、libcurl が同じホストとポートについて最後のエントリーしか保持しないためです。アドレスごとにエントリーを分けると、リクエストは最後のアドレスにだけ固定され、IPv6 のないマシンでは失敗します。エントリーが 1 つなら、libcurl は確認済みのアドレスを順に試し、リクエストが未確認のアドレスに向かうことはありません。

### ユーザーコンテンツのサニタイズ

```php
use Hop\Safety\SafeContent;

$html = SafeContent::html($rawUserHtml);
$href = SafeContent::url($userUrl);          // '#' unless the scheme is allowed
$css  = SafeContent::css($userCss);
```

- `html()` は書式のタグを残し、スクリプト、イベントハンドラー、スタイル、フォーム、そして `http`、`https`、`mailto`、`tel` 以外のリンクを取り除きます。リンクには `rel="nofollow noreferrer noopener"` と `target="_blank"` が付きます。入力は 50,000 文字で切り詰めます。
- `url()` は同一サイトのパスならそのまま返し、それ以外の値はスキームが許可リスト（デフォルトは `LinkSafety::BIO_SCHEMES`）にある場合だけ返します。それ以外はすべて `#` になります。
- `css()` は `<`、`@import`、`expression(`、`javascript:`、`behavior:`、`-moz-binding` を取り除くので、カスタム CSS が自分の `<style>` 要素を閉じたりスクリプトを読み込んだりすることはできません。
- `isSitePath()` は、本当の同一サイトのパスと、`/\evil.com` のようにブラウザが別のホストとして読むパスを区別します。

保存時だけでなく表示時にも適用してください。そうすれば、ルールができる前に保存された行も対象になります。

### 脅威フィード

```bash
php artisan abuse:feeds
```

このコマンドは URLhaus のオンラインマルウェアリストと OpenPhish のコミュニティフィードを `storage/app/threat-feeds/malware.txt` と `phishing.txt` にダウンロードします。`LinkSafety` は URL をこれらと完全一致で照合します（スキームと先頭の `www.` は無視）。そのため、共有プラットフォームが丸ごとブロックされることはありません。LinkHop はこのコマンドを毎日実行します。

フィードは一時ファイルにダウンロードし、チェックしてから古いファイルと置き換えます。失敗した、途中で切れた、空だった、URL を含まないダウンロードでは、前のリストを残します。`safety.feed_max_bytes` より大きいダウンロードは中断します。コマンドが失敗するのは、すべてのフィードが失敗したときだけです。

### オンライン照会

`rejectionReason()`（または `assess(..., remote: true)`）を実行すると、3 つの任意のプロバイダーに問い合わせ、ヒットすると `malware` シグナルが加わります。応答は `safety.providers.cache_hours`（12）の間キャッシュされます。到達できないプロバイダーやエラーを返したプロバイダーが URL をブロックすることはありません。

- DNS 経由の Spamhaus DBL。デフォルトで有効です（`SPAMHAUS_DBL`）。Spamhaus が受け付ける DNS リゾルバーが必要で、公開リゾルバーは受け付けられません。
- Google Safe Browsing。`SAFE_BROWSING_KEY` を設定すると使われます。送るのは `#fragment` を除いたアドレスだけで（フラグメントはサーバーに送られないものなので、第三者にも伝えません）、キャッシュされる応答も同じキーで保存されます。
- URLhaus のホスト照会。`URLHAUS_AUTH_KEY` を設定すると使われます。

## 設定

| 変数 | デフォルト | 意味 |
| --- | --- | --- |
| `SAFE_BROWSING_KEY` | なし | Google Safe Browsing の照会を有効にします。 |
| `URLHAUS_AUTH_KEY` | なし | URLhaus のホスト照会を有効にします。 |
| `SPAMHAUS_DBL` | `true` | DNS 経由の Spamhaus DBL 照会。 |
| `THREAT_FEED_MAX_BYTES` | `67108864`（64 MB） | `abuse:feeds` が受け付けるフィードの最大サイズ。 |
| `HOP_NOTE_HOSTS` | `note.hel.ink,n.hel.ink` | リンクのフラグメントに鍵を持つ NoteHop ホストのカンマ区切りリスト（セルフホスティングの環境では自分のホストを追加します）。 |
| `ABUSE_REGISTER_PER_HOUR` | `3` | このパッケージではなく、各アプリが読みます。 |

残りは `config/safety.php` にあります。キーワードパターン `patterns`、`block_categories`、各シグナルの重み `weights`、リスト `shorteners`、`file_hosts`、`risky_tlds`、`tunnel_hosts`、`brand_keywords`、`official_domains`、`known_domains`、そして `verdict` の設定（`thresholds`、`max_hops`、`chain_hops`、`timeout`、`user_agent`、`executable_types`）です。キー `thresholds`、`suspend_after_disabled_items`、`purge_after_days` は、このパッケージではなく LinkHop と BioHop の `abuse:scan` コマンドが読みます。

## テスト

`tests/Feature` のテストは、このパッケージを使うアプリの中で動く Pest テストです（HEL.ink は `packages/safety` にコピーを含んでいます）。`tests/Unit` のテストにアプリは不要です。`tests/fixtures/note-link-variants.json` には NoteHop アドレスの 64 通りの書き方と、それぞれに対する WHATWG URL パーサーの判定が入っており、`tests/fixtures/note-link-truth.js`（Node）で生成しています。`tests/fixtures/verdict-cases.json` はアドレスと期待される判定の一覧で、実際に LinkHop に届いたフィッシングホストも含みます。判定のテストは DNS と HTTP をフェイクにし、ネットワークには一切触れません。このリポジトリ自身の CI は、`composer.json` が有効であること、すべての PHP ファイルが PHP 8.3 で lint を通ること、下のファミリー表が正規の表と一致することをチェックします。

## セキュリティ

脆弱性は公開 issue ではなく、[SECURITY.md](SECURITY.md) に書かれた方法で報告してください。

## 変更履歴

[CHANGELOG.md](CHANGELOG.md) を参照してください。

## セルフホスティング

HOP を動かすために、このパッケージを手でインストールする必要はありません。LinkHop と BioHop は `composer.json` にこの公開リポジトリを記載しているので、`composer install` がトークンなしで取得します。hub（[HEL.ink](https://github.com/navi-crwn/HEL.ink)）は `packages/safety` にコピーを持っています。キーがまったくなくても動きます。`SAFE_BROWSING_KEY` と `URLHAUS_AUTH_KEY` を設定するまで Safe Browsing と URLhaus はオフのままで、Spamhaus のチェックはキー不要の DNS 照会です（`SPAMHAUS_DBL=false` でオフになります）。LinkHop の `domain_blacklist` テーブルがなければ、同梱のリストを使います。NoteHop を動かしている場合は、`HOP_NOTE_HOSTS` にそのホスト名を設定してください。そうすれば自分のドメインにある暗号化ノートのリンクが認識されます。デフォルトは hel.ink のホストです。その他はファミリー共通のガイドを参照してください: [docs/self-hosting.md](https://github.com/navi-crwn/HEL.ink/blob/main/docs/self-hosting.md)。

## HOP ファミリー

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

## ライセンス

HOP の共有パッケージは、[MIT ライセンス](LICENSE)のもとで公開されているオープンソースソフトウェアです。
