<?php

declare(strict_types=1);

namespace OpenHandle\Internal;

use OpenHandle\OpenHandleReferenceError;
use OpenHandle\ReferenceMismatchError;

/**
 * Local, deterministic resolution of resource references.
 *
 * @internal
 */
final class References
{
    private const NUMERIC_ID = '/^[0-9]+\z/';
    private const INSTAGRAM_NAME = '/^[A-Za-z0-9._]{1,30}\z/';
    private const TIKTOK_NAME = '/^[A-Za-z0-9._]{2,24}\z/';
    private const TWITTER_NAME = '/^[A-Za-z0-9_]{1,15}\z/';
    private const REDDIT_NAME = '/^[A-Za-z0-9_-]{1,100}\z/';
    private const REDDIT_POST_ID = '/^[a-z0-9]+\z/';
    private const SHORTCODE = '/^[A-Za-z0-9_-]+\z/';

    private const REDDIT_HOSTS = ['reddit.com', 'old.reddit.com', 'new.reddit.com', 'm.reddit.com', 'redd.it'];
    private const TIKTOK_HOSTS = ['tiktok.com', 'm.tiktok.com'];
    private const TIKTOK_SHORT_HOSTS = ['vm.tiktok.com', 'vt.tiktok.com'];
    private const TWITTER_HOSTS = ['x.com', 'twitter.com', 'mobile.twitter.com'];
    private const SUPPORTED_HOSTS = [
        ...self::REDDIT_HOSTS,
        'instagram.com',
        ...self::TIKTOK_HOSTS,
        ...self::TIKTOK_SHORT_HOSTS,
        ...self::TWITTER_HOSTS,
    ];

    private const INSTAGRAM_RESERVED = ['p', 'reel', 'reels', 'tv', 'explore', 'accounts', 'direct', 'stories'];
    private const TWITTER_RESERVED = ['home', 'explore', 'search', 'settings', 'messages', 'notifications', 'i', 'intent', 'share'];

    /**
     * Resolve a selector call: a raw positional string or exactly one explicit reference.
     */
    public static function selector(
        mixed $reference,
        mixed $username,
        mixed $id,
        mixed $url,
        string $platform,
        string $resource,
    ): string {
        $provided = array_filter(
            ['raw' => $reference, 'username' => $username, 'id' => $id, 'url' => $url],
            static fn(mixed $value): bool => $value !== null,
        );
        if (count($provided) !== 1) {
            throw new OpenHandleReferenceError(
                'A reference must be a positional string or exactly one of the username, id, or url named arguments.',
            );
        }
        $kind = array_key_first($provided);
        $value = $provided[$kind];
        if (is_int($value) || is_float($value)) {
            throw new OpenHandleReferenceError('Platform IDs are opaque strings; numeric reference values are not accepted.');
        }
        if (!is_string($value)) {
            throw new OpenHandleReferenceError('Reference values must be strings.');
        }

        return self::resolve($kind, $value, $platform, $resource);
    }

    public static function resolve(string $kind, string $value, string $platform, string $resource): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new OpenHandleReferenceError('Reference values must not be empty.');
        }

        return match ($kind) {
            'raw' => match (true) {
                self::looksLikeSupportedSocialUrl($value) => self::urlReference($value, $platform, $resource),
                $resource === 'profile' => self::usernameReference($value, $platform),
                default => $value,
            },
            'username' => $resource === 'profile'
                ? self::usernameReference($value, $platform)
                : throw new OpenHandleReferenceError("The {$resource} resource does not accept username references."),
            'id' => $value,
            'url' => self::urlReference($value, $platform, $resource),
            default => throw new OpenHandleReferenceError("Unknown reference kind {$kind}."),
        };
    }

    private static function urlReference(string $value, string $platform, string $resource): string
    {
        [$resolvedPlatform, $resolvedResource, $identifier] = self::socialUrl($value);
        if ($resolvedPlatform !== $platform || $resolvedResource !== $resource) {
            throw new ReferenceMismatchError($platform, $resource, $resolvedPlatform, $resolvedResource);
        }

        return $identifier;
    }

    private static function looksLikeSupportedSocialUrl(string $reference): bool
    {
        $value = strtolower(trim($reference));
        $scheme = strpos($value, '://');
        if ($scheme !== false) {
            $value = substr($value, $scheme + 3);
        }
        $separators = array_filter(
            [strpos($value, '/'), strpos($value, '?'), strpos($value, '#')],
            static fn(int|false $index): bool => $index !== false,
        );
        if ($scheme === false && $separators === []) {
            return false;
        }
        $authority = $separators === [] ? $value : substr($value, 0, min($separators));
        $credentials = strrpos($authority, '@');
        if ($credentials !== false) {
            $authority = substr($authority, $credentials + 1);
        }
        $port = strrpos($authority, ':');
        if ($port !== false) {
            $authority = substr($authority, 0, $port);
        }

        return in_array(self::withoutWww($authority), self::SUPPORTED_HOSTS, true);
    }

    private static function usernameReference(string $reference, string $platform): string
    {
        $username = str_starts_with($reference, '@') ? substr($reference, 1) : $reference;
        $pattern = match ($platform) {
            'reddit' => self::REDDIT_NAME,
            'instagram' => self::INSTAGRAM_NAME,
            'tiktok' => self::TIKTOK_NAME,
            default => self::TWITTER_NAME,
        };
        if (preg_match($pattern, $username) !== 1) {
            throw new OpenHandleReferenceError("Invalid {$platform} username.");
        }

        return "@{$username}";
    }

    /**
     * @return array{string, string, string}
     */
    private static function socialUrl(string $reference): array
    {
        $target = str_contains($reference, '://') ? $reference : "https://{$reference}";
        $parsed = parse_url($target);
        if ($parsed === false) {
            throw new OpenHandleReferenceError('Invalid social URL.');
        }
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || isset($parsed['user']) || isset($parsed['pass'])) {
            throw new OpenHandleReferenceError('Social URLs must use HTTP or HTTPS and cannot contain credentials.');
        }

        $host = self::withoutWww(strtolower($parsed['host'] ?? ''));
        $parts = array_values(array_filter(
            explode('/', $parsed['path'] ?? ''),
            static fn(string $part): bool => $part !== '',
        ));

        if (in_array($host, self::REDDIT_HOSTS, true)) {
            return self::redditUrl($host, $parts);
        }
        if ($host === 'instagram.com') {
            return self::instagramUrl($parts);
        }
        if (self::isTikTokShortLink($host, $parts)) {
            throw new OpenHandleReferenceError('TikTok short links are not resolved locally. Use fetch($url) instead.');
        }
        if (in_array($host, self::TIKTOK_HOSTS, true)) {
            return self::tiktokUrl($parts);
        }
        if (in_array($host, self::TWITTER_HOSTS, true)) {
            return self::twitterUrl($parts);
        }

        throw new OpenHandleReferenceError("Unsupported social domain {$host}.");
    }

    /**
     * @param list<string> $parts
     *
     * @return array{string, string, string}
     */
    private static function instagramUrl(array $parts): array
    {
        $count = count($parts);
        if ($count === 2 && in_array($parts[0], ['p', 'reel', 'tv'], true) && self::matches(self::SHORTCODE, $parts[1])) {
            return ['instagram', 'post', $parts[1]];
        }
        if ($count === 3 && $parts[0] === 'stories' && $parts[1] === 'highlights' && self::matches(self::NUMERIC_ID, $parts[2])) {
            return ['instagram', 'highlight', $parts[2]];
        }
        if ($count === 3 && $parts[0] === 'stories' && self::matches(self::INSTAGRAM_NAME, $parts[1]) && self::matches(self::NUMERIC_ID, $parts[2])) {
            return ['instagram', 'story', $parts[2]];
        }
        if ($count === 1 && self::matches(self::INSTAGRAM_NAME, $parts[0]) && !in_array(strtolower($parts[0]), self::INSTAGRAM_RESERVED, true)) {
            return ['instagram', 'profile', "@{$parts[0]}"];
        }

        throw new OpenHandleReferenceError('Unsupported Instagram URL.');
    }

    /**
     * @param list<string> $parts
     */
    private static function isTikTokShortLink(string $host, array $parts): bool
    {
        if (in_array($host, self::TIKTOK_SHORT_HOSTS, true)) {
            return count($parts) === 1 && self::matches(self::SHORTCODE, $parts[0]);
        }

        return in_array($host, self::TIKTOK_HOSTS, true)
            && count($parts) === 2
            && $parts[0] === 't'
            && self::matches(self::SHORTCODE, $parts[1]);
    }

    /**
     * @param list<string> $parts
     *
     * @return array{string, string, string}
     */
    private static function tiktokUrl(array $parts): array
    {
        $first = $parts[0] ?? '';
        $username = str_starts_with($first, '@') ? substr($first, 1) : '';
        if (!self::matches(self::TIKTOK_NAME, $username)) {
            throw new OpenHandleReferenceError('Unsupported TikTok URL.');
        }
        if (count($parts) === 3 && $parts[1] === 'video' && self::matches(self::NUMERIC_ID, $parts[2])) {
            return ['tiktok', 'post', $parts[2]];
        }
        if (count($parts) === 1) {
            return ['tiktok', 'profile', "@{$username}"];
        }

        throw new OpenHandleReferenceError('Unsupported TikTok URL.');
    }

    /**
     * @param list<string> $parts
     *
     * @return array{string, string, string}
     */
    private static function twitterUrl(array $parts): array
    {
        $count = count($parts);
        if ($count >= 3 && strtolower($parts[1]) === 'status' && self::matches(self::TWITTER_NAME, $parts[0]) && self::matches(self::NUMERIC_ID, $parts[2])) {
            return ['twitter', 'post', $parts[2]];
        }
        if ($count === 4 && $parts[0] === 'i' && $parts[1] === 'web' && $parts[2] === 'status' && self::matches(self::NUMERIC_ID, $parts[3])) {
            return ['twitter', 'post', $parts[3]];
        }
        if ($count === 1 && self::matches(self::TWITTER_NAME, $parts[0]) && !in_array(strtolower($parts[0]), self::TWITTER_RESERVED, true)) {
            return ['twitter', 'profile', "@{$parts[0]}"];
        }

        throw new OpenHandleReferenceError('Unsupported Twitter URL.');
    }

    /**
     * @param list<string> $parts
     *
     * @return array{string, string, string}
     */
    private static function redditUrl(string $host, array $parts): array
    {
        $count = count($parts);
        if ($host === 'redd.it' && $count === 1 && self::matches(self::REDDIT_POST_ID, $parts[0])) {
            return ['reddit', 'post', $parts[0]];
        }
        if ($count === 2 && in_array($parts[0], ['u', 'user'], true) && self::matches(self::REDDIT_NAME, $parts[1])) {
            return ['reddit', 'profile', "@{$parts[1]}"];
        }
        if ($count === 2 && $parts[0] === 'r' && self::matches(self::REDDIT_NAME, $parts[1])) {
            return ['reddit', 'subreddit', $parts[1]];
        }
        if ($count >= 4 && $parts[0] === 'r' && $parts[2] === 'comments' && self::matches(self::REDDIT_POST_ID, $parts[3])) {
            return ['reddit', 'post', $parts[3]];
        }
        if ($count >= 2 && $parts[0] === 'comments' && self::matches(self::REDDIT_POST_ID, $parts[1])) {
            return ['reddit', 'post', $parts[1]];
        }

        throw new OpenHandleReferenceError('Unsupported Reddit URL.');
    }

    private static function matches(string $pattern, string $value): bool
    {
        return preg_match($pattern, $value) === 1;
    }

    private static function withoutWww(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
