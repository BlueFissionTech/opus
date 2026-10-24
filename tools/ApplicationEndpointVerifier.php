<?php

declare(strict_types=1);

namespace Opus\Tools;

use BlueFission\Arr;
use BlueFission\Connections\Curl;
use BlueFission\Str;
use InvalidArgumentException;
use Throwable;

/** Qualifies the published profile contract, not containers or privileged workflows. */
final class ApplicationEndpointVerifier
{
    private const MAX_RESPONSE_BYTES = 16384;

    public function verify(string $origin, string $profile = 'api'): array
    {
        // Native URL parsing is the non-hookable transport validation boundary.
        $parts = parse_url($origin);
        if ($parts === false || filter_var($origin, FILTER_VALIDATE_URL) === false
            || preg_match('/[\x00-\x20\x7f]/', $origin)
            || !Arr::has(['http', 'https'], $parts['scheme'] ?? '', true)
            || !Arr::has(['', '/'], $parts['path'] ?? '', true)
            || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('application_probe_origin_invalid');
        }
        if (!Arr::has(['api', 'dashboard'], $profile, true)) {
            throw new InvalidArgumentException('application_probe_profile_invalid');
        }

        $result = ['version' => 1, 'ok' => false, 'profile' => $profile,
            'scope' => 'public_endpoints', 'checks' => []];
        $endpoints = [
            'health' => ['/health', ['ok' => true, 'status' => 'live']],
            'ready' => ['/ready', ['ok' => true, 'status' => 'ready']],
            'profile' => ['/api/info', ['ok' => true, 'profile' => $profile]],
        ];
        foreach ($endpoints as $name => [$path, $expected]) {
            $response = $this->request(rtrim($origin, '/') . $path);
            $reason = $response['reason'];
            if ($reason === null && $response['status'] !== 200) {
                $reason = 'http_status_unexpected';
            }
            if ($reason === null && !Str::matches($response['type'], '/^application\/json(?:\s*;.*)?$/iD')) {
                $reason = 'content_type_invalid';
            }
            if ($reason === null) {
                try {
                    $body = json_decode($response['body'], true, 8, JSON_THROW_ON_ERROR);
                    if (!Arr::is($body) || Arr::size($body) !== Arr::size($expected)) {
                        $reason = 'response_mismatch';
                    } else {
                        foreach ($expected as $key => $value) {
                            if (($body[$key] ?? null) !== $value) {
                                $reason = 'response_mismatch';
                            }
                        }
                    }
                } catch (Throwable) {
                    $reason = 'response_invalid';
                }
            }
            $result['checks'][] = ['name' => $name, 'http_status' => $response['status'],
                'ok' => $reason === null, 'reason' => $reason ?? 'verified'];
            if ($reason !== null) {
                return $result;
            }
        }
        $result['ok'] = true;
        return $result;
    }

    private function request(string $url): array
    {
        $response = ['status' => 0, 'type' => '', 'body' => '', 'reason' => 'transport_unavailable'];
        if (!extension_loaded('curl')) {
            $response['reason'] = 'curl_unavailable';
            return $response;
        }
        $client = null;
        $oversize = false;
        try {
            $client = new Curl(['target' => $url, 'method' => 'get', 'timeout' => 5,
                'connect_timeout' => 2, 'verify_ssl' => true, 'verbose' => false,
                'headers' => ['Accept: application/json', 'Cache-Control: no-cache']]);
            // Preserve the exact endpoint: the locked Curl GET helper appends a query path.
            $client->option(CURLOPT_URL, $url)
                ->option(CURLOPT_HTTPGET, true)
                ->option(CURLOPT_FOLLOWLOCATION, false)
                ->option(CURLOPT_SSL_VERIFYPEER, true)
                ->option(CURLOPT_SSL_VERIFYHOST, 2)
                ->option(CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use (&$response, &$oversize): int {
                    // cURL's byte-count contract must not pass through application filters.
                    if (strlen($response['body']) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                        $oversize = true;
                        return 0;
                    }
                    $response['body'] .= $chunk;
                    return strlen($chunk);
                });
            $client->open()->query();
            $handle = $client->connection();
            $response['status'] = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $response['type'] = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
            if (curl_errno($handle) === 0 && $client->result() !== false) {
                $response['reason'] = null;
            }
        } catch (Throwable) {
            // Neither native transport errors nor observer exceptions enter the receipt.
        } finally {
            try { $client?->close(); } catch (Throwable) { $response['reason'] = 'transport_unavailable'; }
        }
        if ($oversize) {
            $response['reason'] = 'response_too_large';
        }
        return $response;
    }
}
