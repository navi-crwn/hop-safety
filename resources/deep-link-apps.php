<?php

/*
 * The apps Hop\Safety\DeepLinks knows. This file is data: add or change an app without touching the class, either
 * here or from the using app with config('safety.deep_links.apps') (same shape, merged by key; `false` removes an app).
 *
 * An app:
 *   name             shown to people ("Opening YouTube").
 *   hosts            exact lower-case hosts; a leading "*." means the domain and its subdomains. One leading www., m. or
 *                    mobile. label on the destination is ignored before matching.
 *   android_package  the Android package, needed when a route has an intent address.
 *   routes           tried in order, first match wins:
 *     hosts               optional, narrows the route to some of the app's hosts (compared after the www./m. strip).
 *     path                a regex for the whole path; named groups become values. Anything it does not match is refused.
 *     query               name => regex for a query parameter that is read; present but not matching = no deep link.
 *     required            value names that must be present.
 *     trim_start          value name => characters removed from its start (a leading "+" of a phone number).
 *     query_passthrough   true lets {query} carry every query parameter, re-encoded.
 *     args                ordered query-string out-name => value name; becomes {args}, empty values are left out.
 *     ios                 template of the iOS address, or null.
 *     android             ['intent' => template, 'scheme' => 'https'] (Android intent address; its fallback is always the
 *                         visited https address), or ['url' => template] for a store scheme such as market://, or null.
 *
 * Templates: {name} (a value, percent-encoded), {path} (the path without its first slash, re-encoded), {query}, {args}
 * and {host}. Nothing else is substituted and every substituted piece is percent-encoded, so a destination can never
 * add a ";", a "#" or a quote to an address. An unknown placeholder drops the route.
 */

return [
    'youtube' => [
        'name' => 'YouTube',
        'hosts' => ['youtube.com', 'youtu.be'],
        'android_package' => 'com.google.android.youtube',
        'routes' => [
            [
                'hosts' => ['youtu.be'],
                'path' => '#^/(?<id>[A-Za-z0-9_-]{6,20})$#',
                'query_passthrough' => true,
                'ios' => 'youtube://youtu.be/{id}{query}',
                'android' => ['intent' => 'youtu.be/{id}{query}', 'scheme' => 'https'],
            ],
            [
                'hosts' => ['youtube.com'],
                'path' => '#^/(?:watch|playlist|(?:shorts|live)/[A-Za-z0-9_-]{5,20})$#',
                'query_passthrough' => true,
                'ios' => 'youtube://www.youtube.com/{path}{query}',
                'android' => ['intent' => 'www.youtube.com/{path}{query}', 'scheme' => 'https'],
            ],
        ],
    ],
    'spotify' => [
        'name' => 'Spotify',
        'hosts' => ['open.spotify.com'],
        'android_package' => 'com.spotify.music',
        'routes' => [[
            'path' => '#^/(?:intl-[a-z]+/)?(?<type>track|album|artist|playlist|show|episode)/(?<id>[A-Za-z0-9]{10,30})/?$#',
            'ios' => 'spotify:{type}:{id}',
            'android' => ['intent' => 'open.spotify.com/{type}/{id}', 'scheme' => 'https'],
        ]],
    ],
    'whatsapp' => [
        'name' => 'WhatsApp',
        'hosts' => ['wa.me', 'api.whatsapp.com'],
        'android_package' => 'com.whatsapp',
        'routes' => [
            [
                'hosts' => ['wa.me'],
                'path' => '#^/(?<phone>\+?\d{6,16})?$#',
                'query' => ['text' => '/^.{1,1000}$/su'],
                'trim_start' => ['phone' => '+'],
                'args' => ['phone' => 'phone', 'text' => 'text'],
                'ios' => 'whatsapp://send{args}',
                'android' => ['intent' => 'send{args}', 'scheme' => 'whatsapp'],
            ],
            [
                'hosts' => ['api.whatsapp.com'],
                'path' => '#^/send/?$#',
                'query' => ['phone' => '/^\+?\d{6,16}$/', 'text' => '/^.{1,1000}$/su'],
                'trim_start' => ['phone' => '+'],
                'args' => ['phone' => 'phone', 'text' => 'text'],
                'ios' => 'whatsapp://send{args}',
                'android' => ['intent' => 'send{args}', 'scheme' => 'whatsapp'],
            ],
        ],
    ],
    'telegram' => [
        'name' => 'Telegram',
        'hosts' => ['t.me', 'telegram.me'],
        'android_package' => 'org.telegram.messenger',
        'routes' => [[
            'path' => '#^/(?<name>[A-Za-z0-9_]{4,32})/?$#',
            'args' => ['domain' => 'name'],
            'ios' => 'tg://resolve{args}',
            'android' => ['intent' => 'resolve{args}', 'scheme' => 'tg'],
        ]],
    ],
    'instagram' => [
        'name' => 'Instagram',
        'hosts' => ['instagram.com'],
        'android_package' => 'com.instagram.android',
        'routes' => [[
            // a profile only
            'path' => '#^/(?!(?:p|reel|reels|stories|explore|accounts|direct|tv)/?$)(?<user>[A-Za-z0-9._]{1,30})/?$#i',
            'args' => ['username' => 'user'],
            'ios' => 'instagram://user{args}',
            'android' => ['intent' => 'instagram.com/_u/{user}', 'scheme' => 'https'],
        ]],
    ],
    'x' => [
        'name' => 'X',
        'hosts' => ['x.com', 'twitter.com'],
        'android_package' => 'com.twitter.android',
        'routes' => [
            [
                'path' => '#^/(?<user>[A-Za-z0-9_]{1,15})/status/(?<id>\d{5,25})/?$#',
                'args' => ['id' => 'id'],
                'ios' => 'twitter://status{args}',
                'android' => ['intent' => 'twitter.com/{user}/status/{id}', 'scheme' => 'https'],
            ],
            [
                'path' => '#^/(?!(?:home|explore|search|settings|i|intent|share|login|compose)/?$)(?<user>[A-Za-z0-9_]{1,15})/?$#i',
                'args' => ['screen_name' => 'user'],
                'ios' => 'twitter://user{args}',
                'android' => ['intent' => 'twitter.com/{user}', 'scheme' => 'https'],
            ],
        ],
    ],
    'tiktok' => [
        'name' => 'TikTok',
        'hosts' => ['tiktok.com'],
        'android_package' => 'com.zhiliaoapp.musically',
        'routes' => [[
            // a profile, Android only (iOS opens the app from TikTok's own universal link)
            'path' => '#^/@(?<user>[A-Za-z0-9._]{2,24})/?$#',
            'ios' => null,
            'android' => ['intent' => 'www.tiktok.com/@{user}', 'scheme' => 'https'],
        ]],
    ],
    'zoom' => [
        'name' => 'Zoom',
        'hosts' => ['*.zoom.us'],
        'android_package' => 'us.zoom.videomeetings',
        'routes' => [[
            // a meeting
            'path' => '#^/j/(?<id>\d{8,12})/?$#',
            'query' => ['pwd' => '/^[A-Za-z0-9._~-]{1,200}$/'],
            'args' => ['confno' => 'id', 'pwd' => 'pwd'],
            'ios' => 'zoomus://zoom.us/join{args}',
            'android' => ['intent' => 'zoom.us/join{args}', 'scheme' => 'zoomus'],
        ]],
    ],
    'app-store' => [
        'name' => 'App Store',
        'hosts' => ['apps.apple.com', 'itunes.apple.com'],
        'routes' => [[
            'path' => '#^/(?:[a-z]{2}/)?app/(?:[A-Za-z0-9%._-]{1,100}/)?id\d{5,15}$#',
            'query_passthrough' => true,
            'ios' => 'itms-apps://{host}/{path}{query}',
            'android' => null,
        ]],
    ],
    'google-play' => [
        'name' => 'Google Play',
        'hosts' => ['play.google.com'],
        'routes' => [[
            'path' => '#^/store/apps/details$#',
            'query' => ['id' => '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/i'],
            'required' => ['id'],
            'args' => ['id' => 'id'],
            'ios' => null,
            'android' => ['url' => 'market://details{args}'],
        ]],
    ],
];
