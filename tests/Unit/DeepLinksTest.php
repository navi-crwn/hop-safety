<?php

use Hop\Safety\DeepLink;
use Hop\Safety\DeepLinks;

function deep(string $url): ?DeepLink
{
    return (new DeepLinks)->resolve($url);
}

// The cases LinkHop's own class was tested with, kept as they were.
it('recognises the places it knows and builds the app addresses', function (string $url, string $name, ?string $ios, ?string $androidPackage) {
    $link = deep($url);
    expect($link)->toBeInstanceOf(DeepLink::class)
        ->and($link->name)->toBe($name)
        ->and($link->ios)->toBe($ios)
        ->and($link->fallback)->toBe($url);
    if ($androidPackage) {
        expect($link->android)->toStartWith('intent://')->toContain('package='.$androidPackage)->toContain('S.browser_fallback_url='.rawurlencode($url))->toEndWith(';end');
    }
})->with([
    'youtube watch' => ['https://www.youtube.com/watch?v=abc123', 'YouTube', 'youtube://www.youtube.com/watch?v=abc123', 'com.google.android.youtube'],
    'youtu.be' => ['https://youtu.be/abc123', 'YouTube', 'youtube://youtu.be/abc123', 'com.google.android.youtube'],
    'spotify track' => ['https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC', 'Spotify', 'spotify:track:4uLU6hMCjMI75M1A2tKUQC', 'com.spotify.music'],
    'whatsapp' => ['https://wa.me/628123456789?text=Hello%20there', 'WhatsApp', 'whatsapp://send?phone=628123456789&text=Hello%20there', 'com.whatsapp'],
    'telegram' => ['https://t.me/hopfamily', 'Telegram', 'tg://resolve?domain=hopfamily', 'org.telegram.messenger'],
    'instagram profile' => ['https://www.instagram.com/helena.studio/', 'Instagram', 'instagram://user?username=helena.studio', 'com.instagram.android'],
    'x profile' => ['https://x.com/hopfamily', 'X', 'twitter://user?screen_name=hopfamily', 'com.twitter.android'],
    'x post' => ['https://twitter.com/hopfamily/status/1234567890123', 'X', 'twitter://status?id=1234567890123', 'com.twitter.android'],
    'zoom' => ['https://us02web.zoom.us/j/81234567890?pwd=abc', 'Zoom', 'zoomus://zoom.us/join?confno=81234567890&pwd=abc', 'us.zoom.videomeetings'],
    'app store' => ['https://apps.apple.com/id/app/example/id123456789', 'App Store', 'itms-apps://apps.apple.com/id/app/example/id123456789', null],
]);

it('knows google play and tiktok for Android only, and refuses what it cannot build', function () {
    $play = deep('https://play.google.com/store/apps/details?id=com.example.app');
    expect($play->toArray())->toBe(['app' => 'google-play', 'name' => 'Google Play', 'fallback' => 'https://play.google.com/store/apps/details?id=com.example.app', 'ios' => null, 'android' => 'market://details?id=com.example.app']);
    $tiktok = deep('https://www.tiktok.com/@helena');
    expect($tiktok->android)->toContain('package=com.zhiliaoapp.musically')->toContain('intent://www.tiktok.com/@helena#Intent;')->and($tiktok->ios)->toBeNull();
    // not a profile, not a known place, not an https address
    foreach (['https://www.instagram.com/p/abc/', 'https://example.com/page', 'https://x.com/home', 'ftp://youtube.com/watch?v=1', 'https://play.google.com/store/apps/details?id=bad id'] as $url) {
        expect(deep($url))->toBeNull();
    }
});

it('gives each platform its own address and nothing for another one', function () {
    $link = deep('https://t.me/hopfamily');
    expect($link->forPlatform('ios'))->toBe('tg://resolve?domain=hopfamily')
        ->and($link->forPlatform('android'))->toStartWith('intent://resolve?domain=hopfamily#Intent;scheme=tg;package=org.telegram.messenger;')
        ->and($link->forPlatform('windows'))->toBeNull()
        ->and($link->app)->toBe('telegram');
    expect(deep('https://www.tiktok.com/@helena')->forPlatform('ios'))->toBeNull();
});

it('names the app of a destination', function () {
    expect((new DeepLinks)->recognise('https://youtu.be/abc123'))->toBe('YouTube')
        ->and((new DeepLinks)->recognise('https://example.com/'))->toBeNull();
});

it('ignores one leading www, m or mobile label and upper case', function (string $url) {
    expect(deep($url)?->name)->toBe('YouTube');
})->with([
    'www' => ['https://www.youtube.com/watch?v=abc123'],
    'm' => ['https://m.youtube.com/watch?v=abc123'],
    'mobile' => ['https://mobile.youtube.com/watch?v=abc123'],
    'upper case' => ['HTTPS://YouTube.COM/watch?v=abc123'],
]);

it('keeps what the destination carries: playlist, time, shorts and the whatsapp variants', function () {
    expect(deep('https://www.youtube.com/watch?v=abc123&list=PL123&t=30s')->ios)->toBe('youtube://www.youtube.com/watch?v=abc123&list=PL123&t=30s');
    expect(deep('https://www.youtube.com/shorts/abc123XYZ')->ios)->toBe('youtube://www.youtube.com/shorts/abc123XYZ');
    expect(deep('https://wa.me/')->ios)->toBe('whatsapp://send');
    expect(deep('https://wa.me/+628123456789')->ios)->toBe('whatsapp://send?phone=628123456789');
    expect(deep('https://api.whatsapp.com/send?phone=628123456789&text=Hi')->ios)->toBe('whatsapp://send?phone=628123456789&text=Hi');
    expect(deep('https://api.whatsapp.com/send?text=Hi')->ios)->toBe('whatsapp://send?text=Hi');
    expect(deep('https://wa.me/628123456789?text=a%26b%3Dc%0Ad')->ios)->toBe('whatsapp://send?phone=628123456789&text=a%26b%3Dc%0Ad');
    expect(deep('https://wa.me/628123456789?text=a+b')->ios)->toBe('whatsapp://send?phone=628123456789&text=a%20b');
});

it('refuses a phone number or a pattern that is not what the route expects', function (string $url) {
    expect(deep($url))->toBeNull();
})->with([
    'short phone' => ['https://wa.me/12345'],
    'letters in phone' => ['https://wa.me/62812abc789'],
    'phone in query' => ['https://api.whatsapp.com/send?phone=62812;package=evil'],
    'text too long' => ['https://wa.me/628123456789?text='.str_repeat('a', 1001)],
    'zoom password with a semicolon' => ['https://zoom.us/j/81234567890?pwd=ab;c'],
    'spotify id too short' => ['https://open.spotify.com/track/abc'],
    'instagram post' => ['https://www.instagram.com/reel/abc/'],
    'twitter intent' => ['https://twitter.com/intent'],
    'play without id' => ['https://play.google.com/store/apps/details'],
    'app store search' => ['https://apps.apple.com/us/search?term=x'],
    'youtube account page' => ['https://www.youtube.com/account'],
    'youtu.be root' => ['https://youtu.be/'],
]);

// What is not allowed to become a deep link at all.
it('only ever reads an https address', function (string $url) {
    expect(deep($url))->toBeNull();
})->with([
    'http' => ['http://www.youtube.com/watch?v=abc123'],
    'ftp' => ['ftp://youtube.com/watch?v=abc123'],
    'javascript' => ['javascript:alert(1)//https://youtube.com/watch?v=abc123'],
    'intent typed by a user' => ['intent://youtube.com/watch?v=abc#Intent;package=com.evil;end'],
    'app scheme typed by a user' => ['youtube://www.youtube.com/watch?v=abc123'],
    'data' => ['data:text/html,https://youtube.com/watch?v=abc123'],
    'scheme-relative' => ['//youtube.com/watch?v=abc123'],
    'no scheme' => ['youtube.com/watch?v=abc123'],
    'empty' => [''],
    'https with one slash' => ['https:/youtube.com/watch?v=abc123'],
    'https with three slashes' => ['https:///youtube.com/watch?v=abc123'],
]);

it('refuses unknown hosts, look-alikes, userinfo, ports and IP literals', function (string $url) {
    expect(deep($url))->toBeNull();
})->with([
    'unknown' => ['https://example.com/watch?v=abc123'],
    'suffix look-alike' => ['https://evilyoutube.com/watch?v=abc123'],
    'prefix look-alike' => ['https://youtube.com.evil.test/watch?v=abc123'],
    'dash look-alike' => ['https://you-tube.com/watch?v=abc123'],
    'subdomain of a look-alike' => ['https://youtube.com.evil.com/watch?v=abc123'],
    'zoom look-alike' => ['https://evilzoom.us/j/81234567890'],
    'zoom with the real name inside' => ['https://zoom.us.evil.test/j/81234567890'],
    'wa.me look-alike' => ['https://awa.me/628123456789'],
    'wa.me subdomain' => ['https://evil.wa.me/628123456789'],
    'second subdomain' => ['https://a.www.youtube.com/watch?v=abc123'],
    'userinfo' => ['https://user@www.youtube.com/watch?v=abc123'],
    'decoy userinfo' => ['https://www.youtube.com@evil.test/watch?v=abc123'],
    'userinfo with password' => ['https://user:pw@www.youtube.com/watch?v=abc123'],
    'port' => ['https://www.youtube.com:8443/watch?v=abc123'],
    'default port' => ['https://www.youtube.com:443/watch?v=abc123'],
    'ipv4' => ['https://127.0.0.1/watch?v=abc123'],
    'ipv4 as a number' => ['https://2130706433/watch?v=abc123'],
    'ipv6' => ['https://[::1]/watch?v=abc123'],
    'trailing dot' => ['https://www.youtube.com./watch?v=abc123'],
    'empty label' => ['https://www..youtube.com/watch?v=abc123'],
    'leading dot' => ['https://.youtube.com/watch?v=abc123'],
    'percent-encoded host' => ['https://www.youtub%65.com/watch?v=abc123'],
    'non-ascii host' => ['https://www.youtubе.com/watch?v=abc123'],
    'backslash as slash' => ['https://www.youtube.com\\@evil.test/watch?v=abc123'],
    'backslash path' => ['https://www.youtube.com/watch\\?v=abc123'],
    'space' => ['https://www.youtube.com/watch?v=abc 123'],
    'tab' => ["https://www.youtube.com/wat\tch?v=abc123"],
    'line break' => ["https://www.youtube.com/watch?v=abc123\r\nLocation: https://evil.test"],
    'nul' => ["https://www.youtube.com/watch?v=abc123\0"],
    'leading space' => [' https://www.youtube.com/watch?v=abc123'],
    'trailing newline' => ["https://youtu.be/abc123\n"],
]);

// Nothing from the destination may break out of the intent:// or the app address.
it('never lets the destination add to an intent address', function (string $url) {
    $link = deep($url);
    if ($link === null) {
        expect($link)->toBeNull();

        return;
    }
    foreach ([$link->android, $link->ios] as $address) {
        if ($address === null) {
            continue;
        }
        expect($address)->not->toMatch('/[\s"\'<>`\\\\{}|^]/');
        if (str_starts_with($address, 'intent://')) {
            // exactly the four fields we write, in order, and no second "#Intent" or extra ";"
            expect(substr_count($address, ';'))->toBe(4)
                ->and(substr_count($address, '#'))->toBe(1)
                ->and($address)->toMatch('~^intent://[^#;]*#Intent;scheme=[a-z][a-z0-9+.-]*;package=[A-Za-z0-9_.]+;S\.browser_fallback_url=[A-Za-z0-9%_.\x7e-]+;end$~');
            // and the package is the app's own, never one that came from the destination
            expect($address)->not->toContain('package=com.evil')->not->toContain('package=evil');
        } else {
            expect($address)->not->toContain(';')->not->toContain('#');
        }
    }
    expect($link->fallback)->toBe($url);
})->with([
    'semicolon in a query' => ['https://www.youtube.com/watch?v=abc123;package=com.evil;end'],
    'encoded semicolon' => ['https://www.youtube.com/watch?v=abc123%3Bpackage%3Dcom.evil%3Bend'],
    'double encoded semicolon' => ['https://www.youtube.com/watch?v=abc123%253Bpackage%253Dcom.evil'],
    'semicolon in a name' => ['https://www.youtube.com/watch?v=abc123&x;package=com.evil=1'],
    'hash in a query value' => ['https://www.youtube.com/watch?v=abc123%23Intent%3Bend'],
    'raw hash starts a fragment' => ['https://www.youtube.com/watch?v=abc123#Intent;package=com.evil;end'],
    'semicolon in a path' => ['https://www.youtube.com/watch;package=com.evil'],
    'end in a query' => ['https://www.youtube.com/watch?v=end;end'],
    'quotes' => ['https://www.youtube.com/watch?v=abc"123\'45'],
    'angle brackets' => ['https://www.youtube.com/watch?v=<script>alert(1)</script>'],
    'encoded CR LF' => ['https://www.youtube.com/watch?v=abc123%0D%0ALocation:%20https://evil.test'],
    'encoded NUL' => ['https://www.youtube.com/watch?v=abc123%00'],
    'encoded CR LF in a path' => ['https://www.youtube.com/shorts/abc123%0D%0AXYZ'],
    'encoded slash in a path' => ['https://www.youtube.com/shorts/abc123%2F..%2Fevil'],
    'encoded dot segments' => ['https://www.youtube.com/watch/%2e%2e/evil'],
    'percent sign alone' => ['https://www.youtube.com/watch?v=abc%'],
    'bad escape' => ['https://www.youtube.com/watch?v=abc%zz123'],
    'utf-8' => ['https://www.youtube.com/watch?v=abc%E2%82%AC123'],
    'raw utf-8' => ['https://www.youtube.com/watch?v=abc€123'],
    'whatsapp text with everything' => ['https://wa.me/628123456789?text='.rawurlencode("a;b#c\"d'e\r\nf;package=com.evil;end")],
    'whatsapp text raw' => ['https://wa.me/628123456789?text=a;package=com.evil;end'],
    'whatsapp text hash' => ['https://wa.me/628123456789?text=a#b'],
    'zoom password' => ['https://zoom.us/j/81234567890?pwd=abc%3Bend'],
    'app store query' => ['https://apps.apple.com/us/app/x/id123456789?mt=8;evil=1'],
    'x user with semicolon' => ['https://twitter.com/user;evil'],
    'x status with a path' => ['https://twitter.com/user/status/123456789;package=evil'],
    'instagram user' => ['https://www.instagram.com/user;package=evil/'],
    'telegram name' => ['https://t.me/name;package=evil'],
    'spotify id' => ['https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC;package=evil'],
    'play id' => ['https://play.google.com/store/apps/details?id=com.example.app;package=evil'],
    'play id encoded' => ['https://play.google.com/store/apps/details?id=com.example.app%3Bpackage%3Devil'],
]);

it('keeps the real destination as the encoded fallback of the intent', function () {
    $url = 'https://www.youtube.com/watch?v=abc123&list=PL1;x#t=5';
    $link = deep($url);
    expect($link->fallback)->toBe($url)
        ->and($link->android)->toContain('S.browser_fallback_url='.rawurlencode($url).';end')
        ->and($link->android)->toStartWith('intent://www.youtube.com/watch?v=abc123&list=PL1%3Bx#Intent;');
});

it('encodes the pieces it carries over, one by one', function () {
    expect(deep('https://www.youtube.com/watch?v=a%20b&t=1%3B2')->ios)->toBe('youtube://www.youtube.com/watch?v=a%20b&t=1%3B2');
    // names that are not plain are dropped, never passed on
    expect(deep('https://www.youtube.com/watch?v=abc123&p%3Bx=1&ok=1')->ios)->toBe('youtube://www.youtube.com/watch?v=abc123&ok=1');
    // a very long value is dropped, a very long address is refused
    expect(deep('https://www.youtube.com/watch?v=abc123&x='.str_repeat('a', 300))->ios)->toBe('youtube://www.youtube.com/watch?v=abc123');
    expect(deep('https://www.youtube.com/watch?v=abc123&x='.str_repeat('a', 2100)))->toBeNull();
    expect(deep('https://www.youtube.com/watch?v=abc123&'.str_repeat('a=1&', 50))->ios)->toBe('youtube://www.youtube.com/watch?v=abc123&'.rtrim(str_repeat('a=1&', 19), '&'));
});

it('refuses very long paths and keeps the limit configurable', function () {
    $long = 'https://www.youtube.com/shorts/'.str_repeat('a', 2100);
    expect(deep($long))->toBeNull();
    expect(deep('https://www.youtube.com/shorts/'.str_repeat('a', 30)))->toBeNull(); // the route allows 5 to 20
    expect((new DeepLinks(null, 40))->resolve('https://www.youtube.com/watch?v=abc123&list=PL1234567890'))->toBeNull();
    expect((new DeepLinks(null, 80))->resolve('https://www.youtube.com/watch?v=abc123&list=PL1234567890'))->not->toBeNull();
});

it('does not read a fragment as part of the address', function () {
    expect(deep('https://youtu.be/abc123#evil')->ios)->toBe('youtube://youtu.be/abc123');
});

it('is stable: the same destination always gives the same answer', function () {
    expect(deep('https://youtu.be/abc123?t=5')->toArray())->toBe(deep('https://youtu.be/abc123?t=5')->toArray());
});
