# hop/safety

[English](README.md) · [简体中文](README.zh-CN.md) · [Deutsch](README.de.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md) · **Português (Brasil)** · [Español](README.es.md) · [Français](README.fr.md) · [Русский](README.ru.md)

As regras de segurança de URL e conteúdo da família HOP ([hel.ink](https://hel.ink)), em um único pacote Laravel. LinkHop, BioHop e o hub o usam, então um link ou uma página de bio é julgado do mesmo jeito em todos os apps.

## O que ele oferece

| Classe | O que faz |
| --- | --- |
| `Hop\Safety\LinkSafety` | Julga uma URL de destino (e o texto ao redor) com base em padrões de palavras-chave, hosts de risco, falsificação de marcas, a lista negra de domínios compartilhada, feeds de ameaças locais e consultas online opcionais. |
| `Hop\Safety\LinkVerdict` | Segue um link pelos seus redirecionamentos e transforma a pontuação em uma única resposta, um `Verdict`: `allow`, `interstitial` ou `block`, com os motivos, o endereço final e o número de saltos. |
| `Hop\Safety\EncryptedNoteLink` | Reconhece um endereço criptografado do NoteHop, cuja chave fica no `#fragment`, para que um app que guarda destinos possa recusá-lo. `LinkSafety` o usa. |
| `Hop\Safety\UrlGuard` | Decide se o servidor pode buscar uma URL: só http ou https, e todo endereço para o qual o host resolve precisa ser público. Também pode fixar a conexão nos endereços que verificou. |
| `Hop\Safety\SafeContent` | Limpa o conteúdo do usuário antes de exibi-lo: sanitiza o HTML, transforma um link inseguro em `#` e remove o que poderia escapar de um elemento `<style>`. |
| `Hop\Safety\Models\DomainBlacklist` | O model Eloquent da tabela `domain_blacklist`. |
| `abuse:feeds` | Comando Artisan que baixa os feeds do URLhaus e do OpenPhish. |

## Requisitos

- PHP 8.3, `ext-intl`, Laravel 12 (`illuminate/support` ^12.0) e `symfony/html-sanitizer` ^7.4. É com `ext-intl` que `EncryptedNoteLink` lê um host do mesmo jeito que um navegador.
- Para a lista negra, uma tabela `domain_blacklist` com `domain`, `match_type` (`exact` ou `wildcard`) e `category`. A tabela pertence ao LinkHop e os outros apps só a leem. Sem a tabela, `LinkSafety` usa apenas a lista que vem com o pacote, `resources/domain_blacklist.json`, que tem as mesmas colunas e é lida junto com a tabela.

## Instalação

O pacote não está no Packagist e ainda não tem release com tag. Adicione o repositório ao `composer.json` do app e exija `dev-main`. Fixe um commit se quiser uma versão estável:

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

Depois execute:

```bash
composer update hop/safety
```

O package discovery do Laravel registra `Hop\Safety\SafetyServiceProvider`. Ele mescla a configuração `safety`, registra `LinkSafety` como instância scoped (assim a lista negra é carregada uma vez por requisição ou job) e registra `abuse:feeds` quando roda no console. Para mudar a configuração, publique-a:

```bash
php artisan vendor:publish --tag=hop-safety-config
```

## Uso

### Verificar uma URL de destino

`LinkSafety::rejectionReason()` retorna uma mensagem que você pode mostrar ao usuário, ou `null` quando a URL é permitida. O segundo argumento é o texto encontrado perto da URL (um título ou uma descrição), e o terceiro é a lista de esquemas permitidos. O padrão é `LinkSafety::WEB_SCHEMES` (`http`, `https`); `LinkSafety::BIO_SCHEMES` adiciona `mailto`, `tel` e `sms`.

```php
use Hop\Safety\LinkSafety;

$reason = app(LinkSafety::class)->rejectionReason($url, $title);
if ($reason !== null) {
    return back()->withErrors(['url' => $reason]);
}
```

Uma URL é recusada quando o esquema não é permitido, quando falta o host, quando é um endereço criptografado do NoteHop (veja abaixo) ou quando corresponde a uma categoria listada em `safety.block_categories`. Essa chamada também consulta os provedores online (veja abaixo).

`assess()` dá o detalhe em vez de um veredito: uma pontuação e a lista de sinais encontrados. Ele pula os provedores online, a menos que você passe `remote: true`.

```php
$result = app(LinkSafety::class)->assess($url, $text);
// for example ['score' => 110, 'signals' => ['gambling', 'chained_shortener', 'executable_download']]
```

Os sinais são `csam`, `gambling`, `phishing`, `scam` e `adult` (padrões de palavras-chave), `dangerous_scheme` (`javascript:`, `data:`, `vbscript:`, `file:`, `blob:`), `ip_host`, `punycode`, `chained_shortener`, `file_host`, `risky_tld`, `tunnel_host`, `brand_spoof`, `random_subdomain`, `executable_download`, `blacklisted` (mais a categoria da própria linha da lista negra), `malware` e `phishing` dos feeds, e `malware` dos provedores online. Cada sinal soma seu peso de `safety.weights` à pontuação.

- `risky_tld`: o host termina em um dos `safety.risky_tlds`, que pode ser um sufixo público com mais de um rótulo (`my.id`, `biz.id`).
- `tunnel_host`: o host é um dos `safety.tunnel_hosts` ou um subdomínio de um deles (ngrok, Cloudflare quick tunnels, localtunnel, serveo, localhost.run). É um bloqueio direto: uma página nesses hosts vive uma tarde e nenhuma lista de reputação consegue conhecê-la.
- `brand_spoof`: um rótulo do host ou um segmento do caminho traz o nome de um banco, carteira digital ou instituição da Indonésia de `safety.brand_keywords` (`klikbca`, `brimo`, `livin`, `mandiri`, `bni`, `bsi`, `dana`, `kaget`, `ovo`, `gopay`, `shopeepay`, `telegram`, `whatsapp`, `kominfo`, `undian`, `blokir`, `tarif`), e o host não é um dos `safety.official_domains` nem um subdomínio de um deles. Um nome de seis caracteres ou menos só corresponde a um rótulo ou segmento inteiro (`bni.example.com`, `/dana`), então `bandana.com` ou `omnibus` nunca correspondem; um nome mais longo também corresponde dentro de um (`bankmandiri-login`, `telegramweb`). O caminho é lido com a codificação percentual decodificada. A lista oficial (`klikbca.com`, `bca.co.id`, `bri.co.id`, `bankmandiri.co.id`, `bni.co.id`, `bankbsi.co.id`, `dana.id`, `ovo.id`, `gopay.co.id`, `shopee.co.id`, `telegram.org`, `t.me`, `whatsapp.com`, `wa.me`, `kominfo.go.id`, `komdigi.go.id`) cobre só o domínio e seus subdomínios: `klikbca.com.example.xyz` e `notklikbca.com` são falsificações.
- `executable_download`: o endereço termina em `.apk`, `.exe`, `.scr`, `.msi`, `.bat`, `.cmd`, `.ps1`, `.vbs` ou `.jar`. É um bloqueio direto.

### O veredito do link

`LinkVerdict::evaluate()` é a resposta única que os apps usam quando um visitante segue um link: encaminhar, mostrar antes uma página de aviso ou recusar. Ele segue o link pelos redirecionamentos, pontua o endereço onde ele chega (e o que foi enviado) com `LinkSafety::assess()`, acrescenta o que só a busca consegue revelar e transforma o total em um veredito.

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

`Verdict` é um value object:

| Propriedade | Significado |
| --- | --- |
| `verdict` | `Verdict::ALLOW` (`allow`), `Verdict::INTERSTITIAL` (`interstitial`) ou `Verdict::BLOCK` (`block`). `allows()`, `needsInterstitial()` e `blocks()` dizem o mesmo. |
| `score` | A soma de `safety.weights` de todos os motivos. |
| `reasons` | Os sinais encontrados, uma vez cada, como chaves para o app traduzir (`blacklisted`, `brand_spoof`, `redirect_chain`, `guest`, ...), nunca como texto corrido. |
| `finalUrl` | O endereço onde o link chega após os redirecionamentos, ou o endereço enviado quando nada foi seguido. |
| `hops` | Quantos redirecionamentos foram seguidos. |

`toArray()` dá o mesmo com `final_url` como chave, para uma resposta JSON ou uma linha de log.

Os argumentos: `guest` é true quando o link foi criado sem conta; `text` é o título ou a descrição em torno do link; `unroll: false` pula a rede por completo e julga só o endereço (para uma verificação na criação ou uma varredura de links salvos); `remote: false` pula os provedores online.

Como o link é seguido:

- Cada salto passa primeiro por `UrlGuard::resolve()` e a requisição é fixada nos endereços verificados. Um salto cujo host não tem resposta DNS, ou resolve para um endereço privado ou local, nunca é buscado e encerra a cadeia como não resolvido.
- Cada salto é uma requisição `HEAD`, e uma `GET` quando o servidor recusa `HEAD` ou a requisição falha. O corpo nunca é lido: a resposta é recebida em streaming e só o status e os cabeçalhos são usados. Timeout `safety.verdict.timeout` (4 s) por salto, com o User-Agent móvel de `safety.verdict.user_agent`, porque kits de phishing muitas vezes servem uma página inofensiva a um navegador de desktop.
- São seguidos até `safety.verdict.max_hops` (5) redirecionamentos; cabeçalhos `Location` relativos são resolvidos em relação ao salto que os enviou. Mais do que isso, um erro de transporte ou um status de erro (4xx, 5xx) no último salto contam como não resolvido.

Os sinais que só o veredito acrescenta:

| Motivo | Peso | Quando |
| --- | --- | --- |
| `redirect_chain` | 25 | `safety.verdict.chain_hops` (3) ou mais redirecionamentos. |
| `unresolved_destination` | 50 | Não foi possível verificar o destino (veja acima). Sozinho não é um bloqueio. |
| `executable_download` | 100 | O `Content-Type` final é um dos `safety.verdict.executable_types` (um pacote Android, um executável ou instalador do Windows), seja qual for a aparência do endereço. |
| `guest` | 25 | O link foi criado por um usuário sem conta. |
| `guest_ip_host` | 100 | Um link de usuário sem conta cujo host é um endereço IP puro. |
| `invalid_url` | 100 | Não é um endereço web: sem esquema, sem host ou com um esquema diferente de `http` e `https`. Nada é buscado. |

Os limites ficam em `safety.verdict.thresholds`: uma pontuação de 70 ou mais é `block`, de 30 ou mais é `interstitial`, e qualquer valor menor é `allow`. Com os pesos padrão, um link de usuário sem conta para um domínio `.xyz` é um interstitial (30 + 25), o nome de um banco em um host não oficial sob um TLD de risco é um bloqueio para todos (55 + 30), e um `.apk`, um host de túnel, um domínio na lista negra ou um link de usuário sem conta para um IP puro são um bloqueio por si só.

`LinkVerdict` é registrado como instância scoped, assim como `LinkSafety`. Ele não usa cache; os apps decidem quando verificar de novo um link salvo.

### Links criptografados do NoteHop

Um link do NoteHop guarda a chave da nota no fragmento (`https://n.hel.ink/TOKEN#k=KEY`, e `&a=` para o link do dono). Navegadores nunca enviam um fragmento a um servidor, e é por isso que o NoteHop não consegue ler a nota. Um app que guardasse esse link como destino, link de bio ou denúncia teria a chave no banco de dados, nos logs e nos redirecionamentos. Por isso `rejectionReason()` recusa um endereço do NoteHop cujo fragmento tem um parâmetro `k` ou `a` com valor, numa URL e em texto livre, e `assess()` o informa como o sinal `encrypted_note_key` (peso 0: ele é recusado ao salvar e nunca pontuado).

O endereço é lido como um navegador o lê, não como `parse_url()` faz: tabulações e quebras de linha são descartadas, caracteres de controle são aparados, qualquer número de barras após `https:` é aceito, uma barra invertida encerra o host, o último `@` encerra as informações de usuário, e escapes percentuais, letras de largura total, `U+3002`, hifens suaves e um ponto final levam todos ao host ao qual o navegador se conectaria. `#?k=KEY` é o parâmetro `k` porque `URLSearchParams` descarta um `?`. Num endereço Android `intent://` é procurado um endereço de fallback do NoteHop. Outros hosts, outros fragmentos e `?k=` na query (que o NoteHop não lê) são destinos comuns.

Os hosts são `safety.note_hosts` (`HOP_NOTE_HOSTS`, padrão `note.hel.ink,n.hel.ink`). Um app pode adicionar mais, por exemplo a partir do seu registro de apps, com `EncryptedNoteLink::resolveHostsUsing(fn () => [...])`.

A mesma classe ajuda os apps a limpar o que já guardam: `withoutFragment($url)`, `withoutSecrets($url)` (só `k` e `a` saem do fragmento), e `scrub($text)` e `scrubJson($json)` (todo endereço do NoteHop num texto ou numa coluna JSON; fragmentos de outros hosts permanecem).

### Buscar uma URL enviada pelo usuário

`UrlGuard::isSafe($url)` responde se uma URL pode ser buscada a partir do servidor. Aceita só `http` e `https`, resolve o host e recusa a URL se algum endereço resolvido for privado ou reservado. Isso cobre loopback, link-local (incluindo endereços de metadados de nuvem como `169.254.169.254`) e as faixas privadas.

`isSafe()` só dá um sim ou um não. Se você depois passar a URL para um cliente HTTP, o cliente consulta o host de novo, e um servidor DNS hostil pode responder diferente na segunda vez (DNS rebinding). Para fechar essa brecha, chame `resolve()` uma vez e faça o cliente se conectar aos endereços verificados:

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

`curlResolve()` retorna uma única entrada `host:port:address1,address2` que lista todos os endereços verificados. Siga os redirecionamentos você mesmo e chame `resolve()` de novo para cada `Location`. `isSafe()` é `resolve() !== null`.

Todos os endereços vão numa única entrada porque a libcurl guarda só a última entrada para um mesmo host e porta. Com uma entrada por endereço, a requisição ficaria fixada só no último endereço e falharia numa máquina sem IPv6. Com uma única entrada, a libcurl tenta os endereços verificados e a requisição nunca vai para um endereço que não foi verificado.

### Sanitizar conteúdo do usuário

```php
use Hop\Safety\SafeContent;

$html = SafeContent::html($rawUserHtml);
$href = SafeContent::url($userUrl);          // '#' unless the scheme is allowed
$css  = SafeContent::css($userCss);
```

- `html()` mantém as tags de formatação e remove scripts, handlers de eventos, estilos, formulários e links que não sejam `http`, `https`, `mailto` ou `tel`. Os links recebem `rel="nofollow noreferrer noopener"` e `target="_blank"`. A entrada é cortada em 50.000 caracteres.
- `url()` retorna um caminho do mesmo site como está, e qualquer outro valor só se o esquema estiver na lista permitida (por padrão `LinkSafety::BIO_SCHEMES`). Todo o resto vira `#`.
- `css()` remove `<`, `@import`, `expression(`, `javascript:`, `behavior:` e `-moz-binding`, para que o CSS personalizado não consiga fechar o seu elemento `<style>` nem carregar scripts.
- `isSitePath()` distingue um caminho real do mesmo site de um que o navegador leria como outro host, como `/\evil.com`.

Aplique esses métodos ao renderizar, não só ao salvar, para que as linhas salvas antes de uma regra existir também fiquem cobertas.

### Feeds de ameaças

```bash
php artisan abuse:feeds
```

O comando baixa a lista de malware online do URLhaus e o feed comunitário do OpenPhish para `storage/app/threat-feeds/malware.txt` e `phishing.txt`. `LinkSafety` compara uma URL com eles de forma exata (o esquema e um `www.` inicial são ignorados), para que uma plataforma compartilhada não seja bloqueada por inteiro. O LinkHop executa o comando diariamente.

Cada feed é baixado para um arquivo temporário, verificado e depois movido por cima do antigo. Um download que falhou, veio truncado, vazio ou sem URLs mantém a lista anterior. Um download maior que `safety.feed_max_bytes` é abortado. O comando só falha quando todos os feeds falharam.

### Consultas online

Quando `rejectionReason()` roda (ou `assess(..., remote: true)`), três provedores opcionais são consultados, e uma ocorrência adiciona o sinal `malware`. As respostas ficam em cache por `safety.providers.cache_hours` (12). Um provedor inacessível ou que retorna um erro nunca bloqueia uma URL.

- Spamhaus DBL via DNS, ativado por padrão (`SPAMHAUS_DBL`). Ele precisa de um resolvedor DNS aceito pelo Spamhaus, e resolvedores públicos não são aceitos.
- Google Safe Browsing, usado quando `SAFE_BROWSING_KEY` está definida. Só o endereço sem o `#fragment` é enviado (um fragmento nunca é enviado a um servidor, então também não é contado a terceiros), e a resposta em cache usa a mesma chave.
- Consulta de hosts do URLhaus, usada quando `URLHAUS_AUTH_KEY` está definida.

## Configuração

| Variável | Padrão | Significado |
| --- | --- | --- |
| `SAFE_BROWSING_KEY` | nenhum | Ativa a consulta ao Google Safe Browsing. |
| `URLHAUS_AUTH_KEY` | nenhum | Ativa a consulta de hosts do URLhaus. |
| `SPAMHAUS_DBL` | `true` | Consulta ao Spamhaus DBL via DNS. |
| `THREAT_FEED_MAX_BYTES` | `67108864` (64 MB) | Maior feed que `abuse:feeds` aceita. |
| `HOP_NOTE_HOSTS` | `note.hel.ink,n.hel.ink` | Hosts do NoteHop separados por vírgula, cujos links levam a chave no fragmento (instalações com hospedagem própria adicionam os seus). |
| `ABUSE_REGISTER_PER_HOUR` | `3` | Lido pelos apps, não por este pacote. |

O resto fica em `config/safety.php`: os padrões de palavras-chave `patterns`, `block_categories`, os pesos `weights` de cada sinal, as listas `shorteners`, `file_hosts`, `risky_tlds`, `tunnel_hosts`, `brand_keywords`, `official_domains` e `known_domains`, e as configurações de `verdict` (`thresholds`, `max_hops`, `chain_hops`, `timeout`, `user_agent`, `executable_types`). As chaves `thresholds`, `suspend_after_disabled_items` e `purge_after_days` são lidas pelo comando `abuse:scan` do LinkHop e do BioHop, não por este pacote.

## Testes

Os testes em `tests/Feature` são testes Pest que rodam dentro de um app que usa o pacote (o HEL.ink inclui uma cópia em `packages/safety`). Os testes em `tests/Unit` não precisam de aplicação. `tests/fixtures/note-link-variants.json` contém 64 grafias de um endereço do NoteHop com o veredito de um parser de URL WHATWG, geradas por `tests/fixtures/note-link-truth.js` (Node). `tests/fixtures/verdict-cases.json` lista endereços com o veredito esperado para cada um, entre eles os hosts de phishing que chegaram ao LinkHop; os testes do veredito simulam DNS e HTTP e nunca tocam a rede. A CI deste repositório verifica que `composer.json` é válido, que todo arquivo PHP passa no lint com PHP 8.3 e que a tabela da família abaixo corresponde à canônica.

## Segurança

Relate vulnerabilidades como descrito em [SECURITY.md](SECURITY.md), não em issues públicas.

## Registro de alterações

Veja [CHANGELOG.md](CHANGELOG.md).

## Hospedagem própria

Você não instala este pacote manualmente para rodar o HOP. LinkHop e BioHop listam este repositório público no `composer.json` deles, então `composer install` o baixa sem token, e o hub ([HEL.ink](https://github.com/navi-crwn/HEL.ink)) traz uma cópia em `packages/safety`. Ele funciona sem nenhuma chave: Safe Browsing e URLhaus ficam desligados até você definir `SAFE_BROWSING_KEY` e `URLHAUS_AUTH_KEY`, a verificação do Spamhaus é uma consulta DNS que não precisa de chave (`SPAMHAUS_DBL=false` a desliga), e sem a tabela `domain_blacklist` do LinkHop ele usa a lista que vem com o pacote. Se você roda o NoteHop, defina `HOP_NOTE_HOSTS` com os nomes de host dele, para que links de notas criptografadas no seu domínio sejam reconhecidos; o padrão são os do hel.ink. O guia da família cobre o resto: [docs/self-hosting.md](https://github.com/navi-crwn/HEL.ink/blob/main/docs/self-hosting.md).

## Família HOP

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

## Licença

Os pacotes compartilhados do HOP são software de código aberto sob a [licença MIT](LICENSE).
