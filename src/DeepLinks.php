<?php

namespace Hop\Safety;

/**
 * "Open in the app": turns an https destination on a known app's host (YouTube, Spotify, WhatsApp, Telegram,
 * Instagram, X, TikTok, Zoom, App Store, Google Play) into the address that opens the installed app on iOS and on
 * Android, with the original https address as the fallback. A redirect (LinkHop) and a block renderer (BioHop) call
 * the same method; deciding which platform a visitor is on stays with the caller.
 *
 * The rules, all of them fail closed (the answer is null, the caller keeps the plain https link):
 *  - only an https:// address is read, and only its host decides the app. A user-typed scheme (javascript:, intent://,
 *    myapp://) is never turned into anything. No userinfo, no port, no IP literal, no trailing dot, no look-alike
 *    host (the table matches whole host labels), no space, control character or backslash anywhere, and no address
 *    longer than `safety.deep_links.max_url_length` (2048);
 *  - the path must match the route's pattern as a whole; whatever is carried over (a path, query parameters, a phone
 *    number, a user name) is percent-encoded piece by piece, so nothing from the destination can add a ";", a "#",
 *    a quote or a line break to an `intent://` address. The finished address is checked once more against a strict
 *    shape before it is returned;
 *  - the Android intent names the app's package from the table and carries the original address, encoded, as
 *    S.browser_fallback_url.
 *
 * The apps are data: resources/deep-link-apps.php, extended or trimmed by the using app with
 * config('safety.deep_links.apps') (see the README). The configuration is trusted: its regular expressions run on
 * visitor-controlled text, so keep them simple. A route whose `path` pattern does not start with ^ and end with $ or \z is
 * left out like any other invalid entry; end the patterns with \z, because $ also matches before a final line feed.
 */
class DeepLinks
{
    /** The longest destination read when nothing is configured. */
    public const DEFAULT_MAX_LENGTH = 2048;

    /** Schemes an app address may never use: they dial, write, read files, run script or hand over data. */
    private const BLOCKED_SCHEMES = ['javascript', 'data', 'vbscript', 'file', 'blob', 'about', 'ftp', 'tel', 'sms', 'smsto', 'mms', 'mailto', 'content', 'jar', 'view-source', 'ws', 'wss', 'chrome', 'chrome-extension', 'resource', 'http', 'sip', 'facetime', 'facetime-audio', 'intent'];

    /** Template placeholders that are not values of the route. */
    private const BUILT_IN = ['path', 'query', 'args', 'host'];

    private const HOST = '/^(?:\*\.)?[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/';

    private const SCHEME = '/^[a-z][a-z0-9+.-]{1,31}$/';

    private const PACKAGE = '/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)+$/';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $apps = null;

    private ?array $fixed;

    private ?int $maxLength;

    /**
     * @param  array<string, array<string, mixed>>|null  $apps  A fixed table (tests, other callers); null reads the
     *                                                             shipped table and config('safety.deep_links.apps').
     * @param  int|null  $maxLength  Longest destination read; null reads config('safety.deep_links.max_url_length').
     */
    public function __construct(?array $apps = null, ?int $maxLength = null)
    {
        $this->fixed = $apps;
        $this->maxLength = $maxLength;
    }

    /** The table that ships with the library. @return array<string, array<string, mixed>> */
    public static function defaults(): array
    {
        return require __DIR__.'/../resources/deep-link-apps.php';
    }

    /**
     * The apps in use, after the configuration has been applied and every entry checked; an entry that is not valid
     * is left out. @return array<string, array<string, mixed>>
     */
    public function apps(): array
    {
        if ($this->apps !== null) {
            return $this->apps;
        }
        $table = $this->fixed;
        if ($table === null) {
            $table = self::defaults();
            try {
                $extra = config('safety.deep_links.apps', []);
            } catch (\Throwable) {
                $extra = []; // no application booted (a plain unit test, a script)
            }
            foreach (is_array($extra) ? $extra : [] as $key => $entry) {
                if (is_array($entry)) {
                    $table[$key] = $entry;
                } else {
                    unset($table[$key]); // false or null removes an app
                }
            }
        }
        $apps = [];
        foreach ($table as $key => $entry) {
            if (is_string($key) && is_array($entry) && ($clean = $this->clean($entry)) !== null) {
                $apps[$key] = $clean;
            }
        }

        return $this->apps = $apps;
    }

    /** The app a destination belongs to and its addresses, or null when it is not an https address of a known app. */
    public function resolve(string $url): ?DeepLink
    {
        $parts = $this->parse($url);
        if ($parts === null) {
            return null;
        }
        foreach ($this->apps() as $key => $app) {
            if (! $this->hostMatches($app['hosts'], $parts['host'])) {
                continue;
            }
            foreach ($app['routes'] as $route) {
                if (isset($route['hosts']) && ! in_array($parts['host'], $route['hosts'], true)) {
                    continue;
                }
                $addresses = $this->addresses($app, $route, $parts, $url);
                if ($addresses !== null) {
                    return new DeepLink($key, $app['name'], $url, $addresses['ios'], $addresses['android']);
                }
            }
        }

        return null;
    }

    /** The name of the app a destination belongs to ("YouTube"), or null. */
    public function recognise(string $url): ?string
    {
        return $this->resolve($url)?->name;
    }

    /**
     * Split an https address into host, path and query pairs, the strict way: nothing a browser would read
     * differently from parse_url() gets through.
     *
     * @return array{host: string, path: string, pairs: list<array{0: string, 1: string}>}|null
     */
    private function parse(string $url): ?array
    {
        $max = $this->maxLength ?? $this->configuredMaxLength();
        if ($url === '' || strlen($url) > $max || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }
        if (! preg_match('~^https://([A-Za-z0-9.-]+)(/[^?#]*)?(?:\?([^#]*))?(?:#.*)?$~i', $url, $m)) {
            return null; // another scheme, userinfo, a port, an IP literal, a percent-encoded host...
        }
        $host = strtolower($m[1]);
        if (! preg_match(self::HOST, $host) || preg_match('/\.\d+$/', $host)) {
            return null; // a trailing dot, an empty label, an IPv4 address
        }
        $host = preg_replace('/^(?:www|m|mobile)\./', '', $host);
        $pairs = [];
        foreach (explode('&', $m[3] ?? '') as $piece) {
            if ($piece === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $piece, 2), 2, '');
            $name = urldecode($name);
            $value = urldecode($value);
            if (preg_match('/[\x00-\x1f\x7f]/', $name.$value)) {
                return null; // %0A, %0D, %00... in a query: never a deep link
            }
            $pairs[] = [$name, $value];
        }

        return ['host' => $host, 'path' => ($m[2] ?? '') === '' ? '/' : $m[2], 'pairs' => $pairs];
    }

    private function configuredMaxLength(): int
    {
        try {
            $max = (int) config('safety.deep_links.max_url_length', self::DEFAULT_MAX_LENGTH);
        } catch (\Throwable) {
            $max = self::DEFAULT_MAX_LENGTH;
        }

        return $max > 0 ? $max : self::DEFAULT_MAX_LENGTH;
    }

    /** @param  list<string>  $hosts */
    private function hostMatches(array $hosts, string $host): bool
    {
        foreach ($hosts as $h) {
            if ($host === $h || (str_starts_with($h, '*.') && ($host === substr($h, 2) || str_ends_with($host, substr($h, 1))))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $app
     * @param  array<string, mixed>  $route
     * @param  array{host: string, path: string, pairs: list<array{0: string, 1: string}>}  $parts
     * @return array{ios: ?string, android: ?string}|null
     */
    private function addresses(array $app, array $route, array $parts, string $url): ?array
    {
        if (! preg_match($route['path'], $parts['path'], $m)) {
            return null;
        }
        $values = [];
        foreach ($m as $name => $value) {
            if (is_string($name) && $value !== '' && ! in_array($name, self::BUILT_IN, true)) {
                $values[$name] = $value;
            }
        }
        foreach ($route['query'] ?? [] as $name => $pattern) {
            foreach ($parts['pairs'] as [$n, $v]) {
                if ($n === $name) {
                    if (! preg_match($pattern, $v)) {
                        return null;
                    }
                    $values += [$name => $v];
                    break;
                }
            }
        }
        foreach ($route['trim_start'] ?? [] as $name => $chars) {
            if (isset($values[$name])) {
                $values[$name] = ltrim($values[$name], $chars);
            }
        }
        foreach ($route['required'] ?? [] as $name) {
            if (($values[$name] ?? '') === '') {
                return null;
            }
        }
        $ctx = ['values' => $values, 'route' => $route, 'parts' => $parts];

        $ios = null;
        if (isset($route['ios'])) {
            $ios = $this->render($route['ios'], $ctx);
            if ($ios === null || ! $this->safeAddress($ios)) {
                return null;
            }
        }
        $android = null;
        $spec = $route['android'] ?? null;
        if (is_array($spec) && isset($spec['intent'])) {
            $body = $this->render($spec['intent'], $ctx);
            if ($body === null) {
                return null;
            }
            $android = 'intent://'.$body.'#Intent;scheme='.$spec['scheme'].';package='.$app['android_package'].';S.browser_fallback_url='.rawurlencode($url).';end';
            if (! preg_match('~^intent://[^#;\x00-\x20\x7f"\'<>\\\\`^{|}]*#Intent;scheme=[a-z][a-z0-9+.-]*;package=[A-Za-z0-9_.]+;S\.browser_fallback_url=[A-Za-z0-9%_.\x7e-]+;end$~', $android)) {
                return null;
            }
        } elseif (is_array($spec) && isset($spec['url'])) {
            $android = $this->render($spec['url'], $ctx);
            if ($android === null || ! preg_match('~^market://[A-Za-z0-9._\x7e%/?&=+-]*$~', $android)) {
                return null;
            }
        }
        if ($ios === null && $android === null) {
            return null;
        }

        return ['ios' => $ios, 'android' => $android];
    }

    /** Fill a template; every piece that comes from the destination is percent-encoded. Null when it cannot be done. */
    private function render(string $template, array $ctx): ?string
    {
        $failed = false;
        $out = preg_replace_callback('/\{([a-z_]+)\}/i', function (array $m) use ($ctx, &$failed): string {
            $name = $m[1];
            switch ($name) {
                case 'host':
                    return $ctx['parts']['host'];
                case 'path':
                    $segments = [];
                    foreach (explode('/', ltrim($ctx['parts']['path'], '/')) as $segment) {
                        $decoded = rawurldecode($segment);
                        if (preg_match('/[\x00-\x1f\x7f]/', $decoded)) {
                            $failed = true;

                            return '';
                        }
                        $segments[] = rawurlencode($decoded);
                    }

                    return implode('/', $segments);
                case 'query':
                    if (empty($ctx['route']['query_passthrough'])) {
                        $failed = true;

                        return '';
                    }
                    $pairs = [];
                    foreach ($ctx['parts']['pairs'] as [$n, $v]) {
                        if (preg_match('/^[A-Za-z0-9_.\-]{1,40}$/', $n) && strlen($v) <= 200 && count($pairs) < 20) {
                            $pairs[] = rawurlencode($n).'='.rawurlencode($v);
                        }
                    }

                    return $pairs ? '?'.implode('&', $pairs) : '';
                case 'args':
                    $pairs = [];
                    foreach ($ctx['route']['args'] ?? [] as $outName => $valueName) {
                        if (($ctx['values'][$valueName] ?? '') !== '') {
                            $pairs[] = rawurlencode($outName).'='.rawurlencode($ctx['values'][$valueName]);
                        }
                    }

                    return $pairs ? '?'.implode('&', $pairs) : '';
                default:
                    if (! array_key_exists($name, $ctx['values'])) {
                        $failed = true; // an unknown placeholder, or a value this destination did not carry

                        return '';
                    }

                    return rawurlencode($ctx['values'][$name]);
            }
        }, $template);

        return $failed || $out === null ? null : $out;
    }

    /** The shape every iOS address has to have after it is built. */
    private function safeAddress(string $address): bool
    {
        if (! preg_match('~^([a-z][a-z0-9+.-]{1,31}):[^\x00-\x20\x7f"\'<>\\\\`^{|};#]*$~i', $address, $m)) {
            return false;
        }

        return ! in_array(strtolower($m[1]), self::BLOCKED_SCHEMES, true);
    }

    /**
     * One entry of the table, or null when it is not usable.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private function clean(array $entry): ?array
    {
        $name = $entry['name'] ?? null;
        if (! is_string($name) || trim($name) === '' || ! is_array($entry['hosts'] ?? null) || ! is_array($entry['routes'] ?? null)) {
            return null;
        }
        $hosts = [];
        foreach ($entry['hosts'] as $host) {
            $host = is_string($host) ? strtolower(trim($host)) : '';
            if (! preg_match(self::HOST, $host)) {
                return null;
            }
            $hosts[] = $host;
        }
        $package = $entry['android_package'] ?? null;
        if ($package !== null && (! is_string($package) || ! preg_match(self::PACKAGE, $package))) {
            return null;
        }
        $routes = [];
        foreach ($entry['routes'] as $route) {
            if (is_array($route) && ($clean = $this->cleanRoute($route, is_string($package) ? $package : null)) !== null) {
                $routes[] = $clean;
            }
        }

        return $hosts && $routes ? ['name' => $name, 'hosts' => $hosts, 'android_package' => $package, 'routes' => $routes] : null;
    }

    /** A path pattern has to match the whole path: its body starts with ^ and ends with $ or \z (\z is what the shipped table uses). */
    private function anchored(string $pattern): bool
    {
        $delimiter = $pattern[0] ?? '';
        $end = $delimiter === '' ? false : strrpos($pattern, $delimiter);
        if ($end === false || $end < 1) {
            return false;
        }
        $body = substr($pattern, 1, $end - 1);

        return str_starts_with($body, '^') && (str_ends_with($body, '$') || str_ends_with($body, '\z'));
    }

    /**
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>|null
     */
    private function cleanRoute(array $route, ?string $package): ?array
    {
        if (! is_string($route['path'] ?? null) || @preg_match($route['path'], '') === false || ! $this->anchored($route['path'])) {
            return null;
        }
        $out = ['path' => $route['path'], 'ios' => null, 'android' => null];
        if (isset($route['hosts'])) {
            if (! is_array($route['hosts']) || ! $route['hosts']) {
                return null;
            }
            $out['hosts'] = array_map(fn ($h) => is_string($h) ? strtolower($h) : '', $route['hosts']);
        }
        foreach ($route['query'] ?? [] as $name => $pattern) {
            if (! is_string($name) || ! is_string($pattern) || @preg_match($pattern, '') === false) {
                return null;
            }
            $out['query'][$name] = $pattern;
        }
        foreach (['required', 'args', 'trim_start'] as $key) {
            if (isset($route[$key])) {
                if (! is_array($route[$key])) {
                    return null;
                }
                $out[$key] = $route[$key];
            }
        }
        $out['query_passthrough'] = ! empty($route['query_passthrough']);
        if (is_string($route['ios'] ?? null)) {
            if (! preg_match('/^([a-z][a-z0-9+.-]{1,31}):/i', $route['ios'], $m) || in_array(strtolower($m[1]), self::BLOCKED_SCHEMES, true)) {
                return null;
            }
            $out['ios'] = $route['ios'];
        }
        $android = $route['android'] ?? null;
        if (is_array($android) && is_string($android['intent'] ?? null)) {
            $scheme = $android['scheme'] ?? 'https';
            if ($package === null || ! is_string($scheme) || ! preg_match(self::SCHEME, $scheme) || in_array($scheme, self::BLOCKED_SCHEMES, true)) {
                return null;
            }
            $out['android'] = ['intent' => $android['intent'], 'scheme' => $scheme];
        } elseif (is_array($android) && is_string($android['url'] ?? null)) {
            if (! str_starts_with($android['url'], 'market://')) {
                return null;
            }
            $out['android'] = ['url' => $android['url']];
        }

        return $out['ios'] !== null || $out['android'] !== null ? $out : null;
    }
}
