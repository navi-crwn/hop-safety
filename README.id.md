# hop/safety

[English](README.md) · [简体中文](README.zh-CN.md) · [Deutsch](README.de.md) · **Bahasa Indonesia** · [日本語](README.ja.md) · [Português (Brasil)](README.pt-BR.md) · [Español](README.es.md) · [Français](README.fr.md) · [Русский](README.ru.md)

Aturan keamanan URL dan konten untuk keluarga HOP ([hel.ink](https://hel.ink)), dalam satu paket Laravel. LinkHop, BioHop, dan hub memakainya, sehingga sebuah tautan atau halaman bio dinilai dengan cara yang sama di setiap aplikasi.

## Isi paket

| Kelas | Fungsinya |
| --- | --- |
| `Hop\Safety\LinkSafety` | Menilai URL tujuan (dan teks di sekitarnya) berdasarkan pola kata kunci, host berisiko, pemalsuan merek, daftar hitam domain bersama, feed ancaman lokal, dan pengecekan online opsional. |
| `Hop\Safety\LinkVerdict` | Mengikuti tautan melewati semua pengalihannya dan mengubah skor menjadi satu jawaban, sebuah `Verdict`: `allow`, `interstitial`, atau `block`, beserta alasannya, alamat akhir, dan jumlah hop. |
| `Hop\Safety\EncryptedNoteLink` | Mengenali alamat NoteHop terenkripsi, yang kuncinya ada di `#fragment`, agar aplikasi yang menyimpan alamat tujuan bisa menolaknya. `LinkSafety` memakainya. |
| `Hop\Safety\UrlGuard` | Menentukan apakah server boleh mengambil sebuah URL: hanya http atau https, dan setiap alamat hasil resolusi host harus publik. Kelas ini juga bisa mengunci koneksi ke alamat yang sudah dicek. |
| `Hop\Safety\SafeContent` | Membersihkan konten pengguna sebelum ditampilkan: menyaring HTML, mengubah tautan yang tidak aman menjadi `#`, dan membuang apa pun yang bisa keluar dari elemen `<style>`. |
| `Hop\Safety\Models\DomainBlacklist` | Model Eloquent untuk tabel `domain_blacklist`. |
| `abuse:feeds` | Perintah Artisan yang mengunduh feed URLhaus dan OpenPhish. |

## Persyaratan

- PHP 8.3, `ext-intl`, Laravel 12 (`illuminate/support` ^12.0), dan `symfony/html-sanitizer` ^7.4. `ext-intl` dipakai `EncryptedNoteLink` untuk membaca host seperti cara browser membacanya.
- Untuk daftar hitam, tabel `domain_blacklist` dengan kolom `domain`, `match_type` (`exact` atau `wildcard`), dan `category`. Tabel ini milik LinkHop, aplikasi lain hanya membacanya. Tanpa tabel itu, `LinkSafety` hanya memakai daftar bawaan paket, `resources/domain_blacklist.json`, yang punya kolom yang sama dan dibaca bersama tabel.

## Instalasi

Paket ini tidak ada di Packagist dan belum punya rilis bertag. Tambahkan repositori ke `composer.json` aplikasi dan minta `dev-main`. Kunci ke satu commit jika ingin versi yang tetap:

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

Lalu jalankan:

```bash
composer update hop/safety
```

Package discovery Laravel mendaftarkan `Hop\Safety\SafetyServiceProvider`. Provider ini menggabungkan konfigurasi `safety`, mengikat `LinkSafety` sebagai instance scoped (jadi daftar hitam dimuat sekali per request atau job), dan mendaftarkan `abuse:feeds` saat berjalan di konsol. Untuk mengubah konfigurasi, publikasikan dulu:

```bash
php artisan vendor:publish --tag=hop-safety-config
```

## Penggunaan

### Mengecek URL tujuan

`LinkSafety::rejectionReason()` mengembalikan pesan yang bisa kamu tampilkan ke pengguna, atau `null` jika URL diizinkan. Argumen kedua adalah teks di dekat URL (judul atau deskripsi), argumen ketiga adalah daftar skema yang diizinkan. Bawaannya `LinkSafety::WEB_SCHEMES` (`http`, `https`); `LinkSafety::BIO_SCHEMES` menambahkan `mailto`, `tel`, dan `sms`.

```php
use Hop\Safety\LinkSafety;

$reason = app(LinkSafety::class)->rejectionReason($url, $title);
if ($reason !== null) {
    return back()->withErrors(['url' => $reason]);
}
```

URL ditolak jika skemanya tidak diizinkan, jika host-nya tidak ada, jika itu alamat NoteHop terenkripsi (lihat di bawah), atau jika cocok dengan kategori yang tercantum di `safety.block_categories`. Pemanggilan ini juga bertanya ke penyedia online (lihat di bawah).

`assess()` memberi rinciannya, bukan putusan: skor dan daftar sinyal yang ditemukan. Penyedia online dilewati kecuali kamu mengirim `remote: true`.

```php
$result = app(LinkSafety::class)->assess($url, $text);
// for example ['score' => 110, 'signals' => ['gambling', 'chained_shortener', 'executable_download']]
```

Sinyalnya adalah `csam`, `gambling`, `phishing`, `scam`, dan `adult` (pola kata kunci), `dangerous_scheme` (`javascript:`, `data:`, `vbscript:`, `file:`, `blob:`), `ip_host`, `punycode`, `chained_shortener`, `file_host`, `risky_tld`, `tunnel_host`, `brand_spoof`, `random_subdomain`, `executable_download`, `blacklisted` (ditambah kategori milik baris daftar hitam itu sendiri), `malware` dan `phishing` dari feed, serta `malware` dari penyedia online. Setiap sinyal menambahkan bobotnya dari `safety.weights` ke skor.

- `risky_tld`: host berakhir dengan salah satu `safety.risky_tlds`, yang bisa berupa public suffix lebih dari satu label (`my.id`, `biz.id`).
- `tunnel_host`: host adalah salah satu `safety.tunnel_hosts` atau subdomainnya (ngrok, Cloudflare quick tunnels, localtunnel, serveo, localhost.run). Ini blokir mutlak: halaman di sana hanya hidup satu sore dan tidak ada daftar reputasi yang bisa mengetahuinya.
- `brand_spoof`: sebuah label host atau segmen path memuat nama bank, dompet digital, atau lembaga Indonesia dari `safety.brand_keywords` (`klikbca`, `brimo`, `livin`, `mandiri`, `bni`, `bsi`, `dana`, `kaget`, `ovo`, `gopay`, `shopeepay`, `telegram`, `whatsapp`, `kominfo`, `undian`, `blokir`, `tarif`), dan host bukan salah satu `safety.official_domains` atau subdomainnya. Nama enam karakter atau kurang hanya cocok dengan label atau segmen yang utuh (`bni.example.com`, `/dana`), jadi `bandana.com` atau `omnibus` tidak pernah cocok; nama yang lebih panjang juga cocok di dalam label atau segmen (`bankmandiri-login`, `telegramweb`). Path dibaca setelah percent-decoding. Daftar resmi (`klikbca.com`, `bca.co.id`, `bri.co.id`, `bankmandiri.co.id`, `bni.co.id`, `bankbsi.co.id`, `dana.id`, `ovo.id`, `gopay.co.id`, `shopee.co.id`, `telegram.org`, `t.me`, `whatsapp.com`, `wa.me`, `kominfo.go.id`, `komdigi.go.id`) hanya mencakup domain itu dan subdomainnya: `klikbca.com.example.xyz` dan `notklikbca.com` adalah pemalsuan.
- `executable_download`: alamat berakhir dengan `.apk`, `.exe`, `.scr`, `.msi`, `.bat`, `.cmd`, `.ps1`, `.vbs`, atau `.jar`. Ini blokir mutlak.

### Putusan tautan

`LinkVerdict::evaluate()` adalah satu jawaban yang dipakai aplikasi saat pengunjung mengikuti tautan: teruskan, tampilkan halaman peringatan dulu, atau tolak. Kelas ini mengikuti tautan melewati pengalihannya, memberi skor pada alamat akhir (dan alamat yang dikirim) dengan `LinkSafety::assess()`, menambahkan apa yang hanya bisa diketahui dari pengambilan, lalu mengubah totalnya menjadi putusan.

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

`Verdict` adalah value object:

| Properti | Arti |
| --- | --- |
| `verdict` | `Verdict::ALLOW` (`allow`), `Verdict::INTERSTITIAL` (`interstitial`), atau `Verdict::BLOCK` (`block`). `allows()`, `needsInterstitial()`, dan `blocks()` menyatakan hal yang sama. |
| `score` | Jumlah `safety.weights` untuk setiap alasan. |
| `reasons` | Sinyal yang ditemukan, masing-masing sekali, sebagai kunci yang diterjemahkan oleh aplikasi (`blacklisted`, `brand_spoof`, `redirect_chain`, `guest`, ...), bukan kalimat. |
| `finalUrl` | Alamat tujuan akhir tautan setelah pengalihan, atau alamat yang dikirim jika tidak ada yang diikuti. |
| `hops` | Berapa banyak pengalihan yang diikuti. |

`toArray()` memberi isi yang sama dengan `final_url` sebagai kunci, untuk jawaban JSON atau baris log.

Argumennya: `guest` bernilai true jika tautan dibuat tanpa akun; `text` adalah judul atau deskripsi di sekitar tautan; `unroll: false` melewati jaringan sepenuhnya dan hanya menilai alamatnya (untuk pengecekan saat pembuatan atau pemindaian tautan yang tersimpan); `remote: false` melewati penyedia online.

Cara tautan diikuti:

- Setiap hop melewati `UrlGuard::resolve()` lebih dulu dan request dikunci ke alamat yang sudah dicek. Hop yang host-nya tidak punya jawaban DNS, atau resolve ke alamat privat atau lokal, tidak pernah diambil dan mengakhiri rantai sebagai tidak terselesaikan.
- Setiap hop adalah request `HEAD`, dan `GET` jika server menolak `HEAD` atau request gagal. Body tidak pernah dibaca: respons di-stream dan hanya status serta header yang dipakai. Timeout `safety.verdict.timeout` (4 dtk) per hop, dengan User-Agent seluler dari `safety.verdict.user_agent`, karena kit phishing sering menyajikan halaman yang tidak berbahaya ke browser desktop.
- Maksimal `safety.verdict.max_hops` (5) pengalihan diikuti; header `Location` relatif di-resolve terhadap hop yang mengirimnya. Lebih dari itu, error transport, atau status error (4xx, 5xx) di hop terakhir dihitung sebagai tidak terselesaikan.

Sinyal yang hanya ditambahkan oleh putusan:

| Alasan | Bobot | Kapan |
| --- | --- | --- |
| `redirect_chain` | 25 | `safety.verdict.chain_hops` (3) pengalihan atau lebih. |
| `unresolved_destination` | 50 | Tujuan tidak bisa diverifikasi (lihat di atas). Bukan blokir jika berdiri sendiri. |
| `executable_download` | 100 | `Content-Type` akhir adalah salah satu `safety.verdict.executable_types` (paket Android, file eksekusi atau installer Windows), apa pun bentuk alamatnya. |
| `guest` | 25 | Tautan dibuat oleh tamu. |
| `guest_ip_host` | 100 | Tautan tamu yang host-nya berupa alamat IP polos. |
| `invalid_url` | 100 | Bukan alamat web: tanpa skema, tanpa host, atau skema selain `http` dan `https`. Tidak ada yang diambil. |

Ambang batasnya ada di `safety.verdict.thresholds`: skor 70 atau lebih adalah `block`, 30 atau lebih adalah `interstitial`, di bawahnya `allow`. Dengan bobot bawaan, tautan buatan tamu ke domain `.xyz` menjadi interstitial (30 + 25), nama bank di host tidak resmi dengan TLD berisiko diblokir untuk semua orang (55 + 30), dan `.apk`, host tunnel, domain di daftar hitam, atau tautan tamu ke IP polos masing-masing sudah cukup untuk diblokir.

`LinkVerdict` diikat sebagai instance scoped, seperti `LinkSafety`. Kelas ini tidak menyimpan cache; aplikasi yang menentukan kapan tautan tersimpan dicek ulang.

### Tautan NoteHop terenkripsi

Tautan NoteHop menyimpan kunci catatan di fragment (`https://n.hel.ink/TOKEN#k=KEY`, dan `&a=` untuk tautan pemilik). Browser tidak pernah mengirim fragment ke server, itulah sebabnya NoteHop tidak bisa membaca catatan. Aplikasi yang menyimpan tautan seperti itu sebagai tujuan, tautan bio, atau laporan akan menyimpan kunci di database, log, dan pengalihannya. Karena itu `rejectionReason()` menolak alamat NoteHop yang fragment-nya punya parameter `k` atau `a` dengan nilai, baik di URL maupun di teks bebas, dan `assess()` melaporkannya sebagai sinyal `encrypted_note_key` (bobot 0, karena ditolak saat disimpan dan tidak pernah diberi skor).

Alamat dibaca seperti cara browser membacanya, bukan seperti `parse_url()`: tab dan baris baru dibuang, karakter kontrol dipangkas, garis miring berapa pun setelah `https:` diterima, backslash mengakhiri host, `@` terakhir mengakhiri info pengguna, dan percent escape, huruf lebar penuh, `U+3002`, soft hyphen, serta titik di akhir semuanya mengarah ke host yang akan dihubungi browser. `#?k=KEY` dianggap parameter `k` karena `URLSearchParams` membuang satu `?`. Alamat Android `intent://` diperiksa untuk mencari alamat fallback NoteHop. Host lain, fragment lain, dan `?k=` di query (yang tidak dibaca NoteHop) adalah tujuan biasa.

Host-nya adalah `safety.note_hosts` (`HOP_NOTE_HOSTS`, bawaan `note.hel.ink,n.hel.ink`). Aplikasi bisa menambahkan host lain, misalnya dari registri aplikasinya, dengan `EncryptedNoteLink::resolveHostsUsing(fn () => [...])`.

Kelas yang sama membantu aplikasi membersihkan data yang sudah tersimpan: `withoutFragment($url)`, `withoutSecrets($url)` (hanya `k` dan `a` yang dikeluarkan dari fragment), serta `scrub($text)` dan `scrubJson($json)` (setiap alamat NoteHop di teks atau kolom JSON; fragment host lain tetap ada).

### Mengambil URL dari pengguna

`UrlGuard::isSafe($url)` menjawab apakah sebuah URL boleh diambil dari server. Hanya `http` dan `https` yang diterima, host di-resolve, dan URL ditolak jika ada alamat hasil resolve yang privat atau dicadangkan. Ini mencakup loopback, link-local (termasuk alamat metadata cloud seperti `169.254.169.254`), dan rentang privat.

`isSafe()` hanya memberi ya atau tidak. Jika kemudian kamu memberikan URL itu ke klien HTTP, klien akan mencari host-nya lagi, dan server DNS jahat bisa menjawab berbeda di kali kedua (DNS rebinding). Untuk menutup celah itu, panggil `resolve()` sekali dan buat klien terhubung ke alamat yang sudah dicek:

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

`curlResolve()` mengembalikan satu entri `host:port:address1,address2` yang memuat setiap alamat yang sudah dicek. Ikuti pengalihan sendiri dan panggil `resolve()` lagi untuk setiap `Location`. `isSafe()` sama dengan `resolve() !== null`.

Semua alamat masuk ke satu entri karena libcurl hanya menyimpan entri terakhir untuk host dan port yang sama. Dengan satu entri per alamat, request hanya akan dikunci ke alamat terakhir dan gagal di mesin tanpa IPv6. Dengan satu entri, libcurl mencoba alamat yang sudah dicek dan request tidak pernah pergi ke alamat yang belum dicek.

### Membersihkan konten pengguna

```php
use Hop\Safety\SafeContent;

$html = SafeContent::html($rawUserHtml);
$href = SafeContent::url($userUrl);          // '#' unless the scheme is allowed
$css  = SafeContent::css($userCss);
```

- `html()` mempertahankan tag format dan membuang script, event handler, style, form, serta tautan yang bukan `http`, `https`, `mailto`, atau `tel`. Tautan mendapat `rel="nofollow noreferrer noopener"` dan `target="_blank"`. Input dipotong di 50.000 karakter.
- `url()` mengembalikan path di situs yang sama apa adanya, dan nilai lain hanya jika skemanya ada di daftar yang diizinkan (bawaannya `LinkSafety::BIO_SCHEMES`). Selain itu menjadi `#`.
- `css()` membuang `<`, `@import`, `expression(`, `javascript:`, `behavior:`, dan `-moz-binding`, sehingga CSS kustom tidak bisa menutup elemen `<style>`-nya atau memuat script.
- `isSitePath()` membedakan path asli di situs yang sama dari path yang akan dibaca browser sebagai host lain, seperti `/\evil.com`.

Terapkan ini saat merender, bukan hanya saat menyimpan, agar baris yang tersimpan sebelum sebuah aturan ada juga tercakup.

### Feed ancaman

```bash
php artisan abuse:feeds
```

Perintah ini mengunduh daftar malware online URLhaus dan feed komunitas OpenPhish ke `storage/app/threat-feeds/malware.txt` dan `phishing.txt`. `LinkSafety` mencocokkan URL dengan daftar itu secara persis (skema dan awalan `www.` diabaikan), sehingga platform bersama tidak diblokir seluruhnya. LinkHop menjalankan perintah ini setiap hari.

Feed diunduh ke file sementara, dicek, lalu dipindahkan menimpa file lama. Unduhan yang gagal, terpotong, kosong, atau tidak berisi URL mempertahankan daftar sebelumnya. Unduhan yang lebih besar dari `safety.feed_max_bytes` dibatalkan. Perintah ini hanya gagal jika semua feed gagal.

### Pengecekan online

Saat `rejectionReason()` berjalan (atau `assess(..., remote: true)`), tiga penyedia opsional ditanya, dan temuan menambahkan sinyal `malware`. Jawaban disimpan di cache selama `safety.providers.cache_hours` (12). Penyedia yang tidak bisa dihubungi atau mengembalikan error tidak pernah memblokir URL.

- Spamhaus DBL lewat DNS, aktif secara bawaan (`SPAMHAUS_DBL`). Fitur ini butuh resolver DNS yang diterima Spamhaus, dan resolver publik tidak diterima.
- Google Safe Browsing, dipakai jika `SAFE_BROWSING_KEY` diisi. Hanya alamat tanpa `#fragment` yang dikirim (fragment tidak pernah dikirim ke server, jadi juga tidak diberitahukan ke pihak ketiga), dan jawaban di cache memakai kunci yang sama.
- Pengecekan host URLhaus, dipakai jika `URLHAUS_AUTH_KEY` diisi.

## Konfigurasi

| Variabel | Bawaan | Arti |
| --- | --- | --- |
| `SAFE_BROWSING_KEY` | tidak ada | Mengaktifkan pengecekan Google Safe Browsing. |
| `URLHAUS_AUTH_KEY` | tidak ada | Mengaktifkan pengecekan host URLhaus. |
| `SPAMHAUS_DBL` | `true` | Pengecekan Spamhaus DBL lewat DNS. |
| `THREAT_FEED_MAX_BYTES` | `67108864` (64 MB) | Ukuran feed terbesar yang diterima `abuse:feeds`. |
| `HOP_NOTE_HOSTS` | `note.hel.ink,n.hel.ink` | Host NoteHop dipisah koma, yang tautannya membawa kunci di fragment (instalasi hosting sendiri menambahkan host mereka). |
| `ABUSE_REGISTER_PER_HOUR` | `3` | Dibaca oleh aplikasi, bukan oleh paket ini. |

Sisanya ada di `config/safety.php`: pola kata kunci `patterns`, `block_categories`, bobot `weights` setiap sinyal, daftar `shorteners`, `file_hosts`, `risky_tlds`, `tunnel_hosts`, `brand_keywords`, `official_domains`, dan `known_domains`, serta pengaturan `verdict` (`thresholds`, `max_hops`, `chain_hops`, `timeout`, `user_agent`, `executable_types`). Kunci `thresholds`, `suspend_after_disabled_items`, dan `purge_after_days` dibaca oleh perintah `abuse:scan` di LinkHop dan BioHop, bukan oleh paket ini.

## Pengujian

Tes di `tests/Feature` adalah tes Pest yang berjalan di dalam aplikasi yang memakai paket ini (HEL.ink menyertakan salinannya di `packages/safety`). Tes di `tests/Unit` tidak butuh aplikasi. `tests/fixtures/note-link-variants.json` berisi 64 variasi penulisan alamat NoteHop beserta putusan parser URL WHATWG, dihasilkan oleh `tests/fixtures/note-link-truth.js` (Node). `tests/fixtures/verdict-cases.json` berisi daftar alamat dengan putusan yang diharapkan, di antaranya host phishing yang pernah masuk ke LinkHop; tes putusan memalsukan DNS dan HTTP dan tidak pernah menyentuh jaringan. CI repositori ini sendiri mengecek bahwa `composer.json` valid, bahwa setiap file PHP lolos lint di PHP 8.3, dan bahwa tabel keluarga di bawah sama dengan tabel kanonis.

## Keamanan

Laporkan kerentanan seperti dijelaskan di [SECURITY.md](SECURITY.md), bukan di issue publik.

## Catatan perubahan

Lihat [CHANGELOG.md](CHANGELOG.md).

## Hosting sendiri

Kamu tidak perlu memasang paket ini secara manual untuk menjalankan HOP. LinkHop dan BioHop mencantumkan repositori publik ini di `composer.json` mereka, jadi `composer install` mengambilnya tanpa token, dan hub ([HEL.ink](https://github.com/navi-crwn/HEL.ink)) membawa salinannya di `packages/safety`. Paket ini bekerja tanpa kunci apa pun: Safe Browsing dan URLhaus tetap mati sampai kamu mengisi `SAFE_BROWSING_KEY` dan `URLHAUS_AUTH_KEY`, pengecekan Spamhaus adalah lookup DNS yang tidak butuh kunci (`SPAMHAUS_DBL=false` mematikannya), dan tanpa tabel `domain_blacklist` milik LinkHop paket ini memakai daftar bawaannya. Jika kamu menjalankan NoteHop, isi `HOP_NOTE_HOSTS` dengan nama host-nya, agar tautan catatan terenkripsi di domainmu dikenali; bawaannya adalah host hel.ink. Panduan keluarga membahas sisanya: [docs/self-hosting.md](https://github.com/navi-crwn/HEL.ink/blob/main/docs/self-hosting.md).

## Keluarga HOP

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

## Lisensi

Paket bersama HOP adalah perangkat lunak open-source dengan [lisensi MIT](LICENSE).
