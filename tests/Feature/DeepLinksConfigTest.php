<?php

use Hop\Safety\DeepLinks;

function deepApp(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Example Chat',
        'hosts' => ['chat.example.com'],
        'android_package' => 'com.example.chat',
        'routes' => [[
            'path' => '#^/room/(?<room>[a-z0-9]{3,20})$#',
            'args' => ['room' => 'room'],
            'ios' => 'examplechat://join{args}',
            'android' => ['intent' => 'join{args}', 'scheme' => 'examplechat'],
        ]],
    ], $overrides);
}

it('is bound in the container and works with the shipped table', function () {
    expect(app(DeepLinks::class))->toBeInstanceOf(DeepLinks::class)->and(app(DeepLinks::class))->toBe(app(DeepLinks::class));
    expect(app(DeepLinks::class)->resolve('https://youtu.be/abc123')?->name)->toBe('YouTube');
});

it('ships a table of valid apps only', function () {
    $table = DeepLinks::defaults();
    expect(array_keys((new DeepLinks)->apps()))->toBe(array_keys($table));
});

it('lets the using app add an app through configuration', function () {
    config(['safety.deep_links.apps' => ['example-chat' => deepApp()]]);
    $link = (new DeepLinks)->resolve('https://chat.example.com/room/abc123');
    expect($link->app)->toBe('example-chat')
        ->and($link->ios)->toBe('examplechat://join?room=abc123')
        ->and($link->android)->toBe('intent://join?room=abc123#Intent;scheme=examplechat;package=com.example.chat;S.browser_fallback_url='.rawurlencode('https://chat.example.com/room/abc123').';end');
    // the shipped apps are still there, and the new one is as strict as they are
    expect((new DeepLinks)->resolve('https://youtu.be/abc123'))->not->toBeNull();
    expect((new DeepLinks)->resolve('https://chat.example.com/room/abc;package=evil'))->toBeNull();
    expect((new DeepLinks)->resolve('https://chat.example.com/room/ab'))->toBeNull();
    expect((new DeepLinks)->resolve('https://evilchat.example.com/room/abc123'))->toBeNull();
    expect((new DeepLinks)->resolve('https://chat.example.com:8443/room/abc123'))->toBeNull();
});

it('lets the using app replace or remove a shipped app', function () {
    config(['safety.deep_links.apps' => [
        'youtube' => false,
        'telegram' => deepApp(['name' => 'Telegram (own)', 'hosts' => ['t.me'], 'routes' => [['path' => '#^/(?<room>[a-z0-9]{3,20})$#']]]),
    ]]);
    $deep = new DeepLinks;
    expect($deep->resolve('https://youtu.be/abc123'))->toBeNull()
        ->and($deep->resolve('https://t.me/abcdef')->name)->toBe('Telegram (own)')
        ->and($deep->resolve('https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC'))->not->toBeNull();
});

it('leaves out an entry that is not valid instead of guessing', function (array $entry) {
    config(['safety.deep_links.apps' => ['broken' => $entry]]);
    $deep = new DeepLinks;
    expect($deep->apps())->not->toHaveKey('broken');
    expect($deep->resolve('https://chat.example.com/room/abc123'))->toBeNull();
})->with([
    'no name' => [deepApp(['name' => ''])],
    'host with a scheme' => [deepApp(['hosts' => ['https://chat.example.com']])],
    'host with a port' => [deepApp(['hosts' => ['chat.example.com:8443']])],
    'bare top-level host' => [deepApp(['hosts' => ['com']])],
    'package with a semicolon' => [deepApp(['android_package' => 'com.example;package=evil'])],
    'intent scheme that is blocked' => [deepApp(['routes' => [['android' => ['intent' => 'x', 'scheme' => 'javascript'], 'ios' => null]]])],
    'ios scheme that is blocked' => [deepApp(['routes' => [['ios' => 'javascript:alert(1)', 'android' => null]]])],
    'ios intent scheme' => [deepApp(['routes' => [['ios' => 'intent://x#Intent;end', 'android' => null]]])],
    'ios file scheme' => [deepApp(['routes' => [['ios' => 'file:///etc/passwd', 'android' => null]]])],
    'an invalid pattern' => [deepApp(['routes' => [['path' => '#^/(room#']]])],
    'path without a start anchor' => [deepApp(['routes' => [['path' => '#/room/(?<room>[a-z0-9]{3,20})\z#']]])],
    'path without an end anchor' => [deepApp(['routes' => [['path' => '#^/room/(?<room>[a-z0-9]{3,20})#']]])],
    'path anchored in the middle only' => [deepApp(['routes' => [['path' => '#^/room/(?<room>[a-z0-9]{3,20})/more#']]])],
    'no routes' => [array_merge(deepApp(), ['routes' => []])],
]);

it('refuses a route whose template uses a value the destination did not carry or an unknown placeholder', function (string $template) {
    config(['safety.deep_links.apps' => ['example-chat' => deepApp(['routes' => [['ios' => $template, 'android' => null]]])]]);
    expect((new DeepLinks)->resolve('https://chat.example.com/room/abc123'))->toBeNull();
})->with([
    'unknown placeholder' => ['examplechat://join/{secret}'],
    'query without passthrough' => ['examplechat://join{query}'],
]);

it('still refuses an address that would not be well formed whatever the table says', function () {
    // a template that adds its own ";" or a space is caught by the final check
    config(['safety.deep_links.apps' => ['example-chat' => deepApp(['routes' => [['ios' => 'examplechat://join;package=evil/{room}', 'android' => null]]])]]);
    expect((new DeepLinks)->resolve('https://chat.example.com/room/abc123'))->toBeNull();
    config(['safety.deep_links.apps' => ['example-chat' => deepApp(['routes' => [['ios' => 'examplechat://join to/{room}', 'android' => null]]])]]);
    expect((new DeepLinks)->resolve('https://chat.example.com/room/abc123'))->toBeNull();
});

it('takes a fixed table and a length limit from the constructor, and reads the limit from configuration', function () {
    $deep = new DeepLinks(['example-chat' => deepApp()]);
    expect($deep->resolve('https://chat.example.com/room/abc123'))->not->toBeNull()
        ->and($deep->resolve('https://youtu.be/abc123'))->toBeNull();
    config(['safety.deep_links.max_url_length' => 30]);
    expect((new DeepLinks)->resolve('https://youtu.be/abc123'))->not->toBeNull(); // 23 characters
    expect((new DeepLinks)->resolve('https://youtu.be/abc123?t=30s&x=1'))->toBeNull();
});

it('does not depend on LinkHop or on anything outside the library and Laravel config', function () {
    $src = dirname((new ReflectionClass(DeepLinks::class))->getFileName()); // the library's src, wherever it is installed
    foreach (['DeepLinks.php', 'DeepLink.php'] as $file) {
        $names = [];
        $tokens = token_get_all((string) file_get_contents($src.'/'.$file));
        foreach ($tokens as $i => $t) {
            if (! is_array($t)) {
                continue;
            }
            if (in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $names[] = ltrim($t[1], '\\');
            }
            if ($t[0] === T_STRING) {
                // a call is told apart from a property or a method name
                $names[] = ($tokens[$i + 1] ?? null) === '(' ? $t[1].'()' : $t[1];
            }
        }
        foreach ($names as $n) {
            expect($n)->not->toStartWith('App\\')->not->toStartWith('Illuminate\\')
                ->not->toBeIn(['Link', 'LinkRouting', 'LinkLimits', 'LinkHop', 'User', 'DB', 'Cache', 'Http', 'Auth', 'app()', 'auth()', 'request()', 'session()', 'cache()', 'view()', '__()', 'trans()', 'route()', 'url()', 'abort()', 'logger()']);
        }
    }
    // the table is data: no code in it beyond the array
    $data = (string) file_get_contents($src.'/../resources/deep-link-apps.php');
    $allowed = [T_OPEN_TAG, T_RETURN, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_DOUBLE_ARROW];
    foreach (token_get_all($data) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_STRING) {
                expect(['true', 'false', 'null'])->toContain(strtolower($t[1])); // constants only, never a call
            } else {
                expect($allowed)->toContain($t[0]);
            }
        }
    }
});

it('accepts a configured path pattern that ends in $ or \z', function (string $path) {
    config(['safety.deep_links.apps' => ['example-chat' => deepApp(['routes' => [['path' => $path]]])]]);
    expect((new DeepLinks)->resolve('https://chat.example.com/room/abc123')?->app)->toBe('example-chat');
})->with([
    'dollar' => ['#^/room/(?<room>[a-z0-9]{3,20})$#'],
    'backslash z' => ['#^/room/(?<room>[a-z0-9]{3,20})\z#'],
    'with a flag' => ['#^/room/(?<room>[a-z0-9]{3,20})\z#i'],
    'another delimiter' => ['~^/room/(?<room>[a-z0-9]{3,20})\z~'],
]);
