<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Notified Bodies for the Radio Equipment Directive, as listed in NANDO (European Commission),
 * kept in resources/data/notified-bodies-red.json. NANDO has no documented machine-readable
 * export: cetome staff refresh the file by hand (docs/NOTIFIED-BODIES.md).
 *
 * The list feeds the Notified Body autocomplete of the Conformity step. A number missing from it
 * is accepted (a body notified since the file was retrieved) and shown with a warning.
 */
final class NotifiedBodies
{
    /** @var array{source: string, url: string, retrieved: string, bodies: list<array{number: string, name: string, country: string}>}|null */
    private static ?array $data = null;

    public static function path(): string
    {
        return resource_path('data/notified-bodies-red.json');
    }

    /** @return array{source: string, url: string, retrieved: string, bodies: list<array{number: string, name: string, country: string}>} */
    private static function data(): array
    {
        if (self::$data === null) {
            $raw = is_file(self::path()) ? json_decode((string) file_get_contents(self::path()), true) : null;
            $raw = is_array($raw) ? $raw : [];
            $bodies = [];
            foreach ((array) ($raw['bodies'] ?? []) as $body) {
                if (is_array($body) && preg_match('/^\d{4}$/', (string) ($body['number'] ?? '')) === 1) {
                    $bodies[] = ['number' => (string) $body['number'], 'name' => trim((string) ($body['name'] ?? '')), 'country' => trim((string) ($body['country'] ?? ''))];
                }
            }
            self::$data = [
                'source' => (string) ($raw['source'] ?? ''),
                'url' => (string) ($raw['url'] ?? 'https://webgate.ec.europa.eu/single-market-compliance-space/notified-bodies'),
                'retrieved' => (string) ($raw['retrieved'] ?? ''),
                'bodies' => $bodies,
            ];
        }

        return self::$data;
    }

    /** @return list<array{number: string, name: string, country: string}> */
    public static function all(): array
    {
        return self::data()['bodies'];
    }

    /** @return array{number: string, name: string, country: string}|null */
    public static function find(?string $number): ?array
    {
        foreach (self::all() as $body) {
            if ($body['number'] === $number) {
                return $body;
            }
        }

        return null;
    }

    /** True when a number is given and NANDO (as retrieved) does not list it. */
    public static function unknown(?string $number): bool
    {
        return filled($number) && self::find($number) === null;
    }

    /** The NANDO page of the RED notified bodies (opened by "Check in NANDO"). */
    public static function url(): string
    {
        $url = self::data()['url'];

        return str_starts_with($url, 'https://') ? $url : 'https://webgate.ec.europa.eu/single-market-compliance-space/notified-bodies';
    }

    /** "23 October 2026", the day the list was retrieved. */
    public static function retrieved(): string
    {
        $day = self::data()['retrieved'];

        return $day !== '' ? Carbon::parse($day)->locale(app()->getLocale())->isoFormat('D MMMM YYYY') : '';
    }

    /** "0123 · TÜV SÜD Product Service GmbH (Germany)" */
    public static function label(array $body): string
    {
        return $body['number'].' · '.$body['name'].($body['country'] !== '' ? ' ('.$body['country'].')' : '');
    }

    /** Forget the cached file (tests). */
    public static function flush(): void
    {
        self::$data = null;
    }
}
