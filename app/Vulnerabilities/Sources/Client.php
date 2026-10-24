<?php

namespace App\Vulnerabilities\Sources;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP to the public vulnerability databases: a clear User-Agent, short timeouts, one retry on
 * network errors and 5xx. Only component identifiers are ever sent (names, versions, purl, CPE,
 * vulnerability ids), never a product, organisation or person.
 */
final class Client
{
    public static function request(string $source = ''): PendingRequest
    {
        // The NVD often takes 20 s or more to answer: a longer timeout and no retry there.
        $slow = $source === 'nvd';

        // HTTP/2 and gzip when libcurl has them: the NVD answers HTTP/1.1 clients very slowly
        // (a 550 kB answer took over a minute instead of 5 s), OSV recommends HTTP/2.
        $options = self::http2() ? ['version' => 2.0] : [];

        return Http::withUserAgent((string) config('sbom.checks.user_agent'))
            ->withOptions($options)
            ->withHeaders(['Accept-Encoding' => 'gzip'])
            ->acceptJson()
            ->timeout($slow ? (int) config('sbom.sources.nvd.timeout', 45) : (int) config('sbom.checks.timeout', 20))
            ->connectTimeout(8)
            ->retry($slow ? 1 : 2, 800, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }

    public static function http2(): bool
    {
        if (! function_exists('curl_version') || ! defined('CURL_VERSION_HTTP2')) {
            return false;
        }
        $version = curl_version();

        return is_array($version) && ((int) $version['features'] & CURL_VERSION_HTTP2) !== 0;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @return array<mixed>
     */
    public static function getJson(string $source, string $url, array $query = [], array $headers = []): array
    {
        try {
            // An empty query array would make Guzzle drop the query string already in $url.
            $response = self::request($source)->withHeaders($headers)->get($url, $query === [] ? null : $query);
        } catch (ConnectionException) {
            throw new SourceException($source, 'network');
        }

        return self::json($source, $response);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<mixed>
     */
    public static function postJson(string $source, string $url, array $body): array
    {
        try {
            $response = self::request($source)->asJson()->post($url, $body);
        } catch (ConnectionException) {
            throw new SourceException($source, 'network');
        }

        return self::json($source, $response);
    }

    /** @return array<mixed> */
    public static function json(string $source, Response $response): array
    {
        if ($response->status() === 429 || ($source === 'nvd' && $response->status() === 403)) {
            // The server refused for now: reported like any failure (our own NVD pacing defers instead).
            throw new SourceException($source, 'rate_limited');
        }
        if ($response->status() === 404) {
            return [];
        }
        if (! $response->successful()) {
            throw new SourceException($source, 'http_'.$response->status());
        }
        $data = json_decode($response->body(), true, 128);
        if (! is_array($data)) {
            throw new SourceException($source, 'bad_response');
        }

        return $data;
    }
}
