<?php

namespace Hop\Safety;

/**
 * The answer of DeepLinks::resolve(): the app a destination belongs to, the address that opens it on each platform
 * and the page to show when the app is not there.
 *
 * `fallback` is always the destination exactly as it was given, an https address DeepLinks has checked. `ios` and
 * `android` are null when the app has no address for that platform (TikTok profiles have none for iOS, Google Play
 * pages none for iOS).
 */
final readonly class DeepLink
{
    public const IOS = 'ios';

    public const ANDROID = 'android';

    public function __construct(
        public string $app,
        public string $name,
        public string $fallback,
        public ?string $ios,
        public ?string $android,
    ) {}

    /** The address for 'ios' or 'android'; null for any other platform or when that platform has none. */
    public function forPlatform(string $platform): ?string
    {
        return match ($platform) {
            self::IOS => $this->ios,
            self::ANDROID => $this->android,
            default => null,
        };
    }

    /** @return array{app: string, name: string, fallback: string, ios: ?string, android: ?string} */
    public function toArray(): array
    {
        return ['app' => $this->app, 'name' => $this->name, 'fallback' => $this->fallback, 'ios' => $this->ios, 'android' => $this->android];
    }
}
