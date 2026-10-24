<?php
declare(strict_types=1);
namespace AddOns\Keryx\Business;

use BlueFission\Arr;
use BlueFission\Connections\Curl;
use BlueFission\Services\Service;
use BlueFission\SimpleClients\Cloud\HttpClient;
use BlueFission\Str;
use InvalidArgumentException;
use RuntimeException;

/** Host-configured native transport. Never accepts an agent-provided origin. */
final class Gateway extends Service
{
    public const MAX_BYTES = 1048576;
    private readonly string $origin;

    public function __construct(string $origin, private readonly ?HttpClient $client = null, bool $developmentHttp = false)
    {
        parent::__construct();
        $parts = parse_url($origin);
        if (!Arr::is($parts) || !Str::isNotEmpty($parts['host'] ?? '')
            || !Arr::has($developmentHttp ? ['https', 'http'] : ['https'], $parts['scheme'] ?? '', true)
            || Arr::hasKey($parts, 'user') || Arr::hasKey($parts, 'pass')
            || Arr::hasKey($parts, 'query') || Arr::hasKey($parts, 'fragment')
            || !Arr::has(['', '/'], $parts['path'] ?? '', true)
            || Str::make($origin)->matches('/[\x00-\x20\x7f\\\\]/')) {
            throw new InvalidArgumentException('keryx_origin_invalid');
        }
        $this->origin = Str::trim($origin, '/');
    }

    public function fetch(string $path): array
    {
        if (!Arr::has(['/v1/messages/summary', '/v1/capabilities'], $path, true)
            && !Str::make($path)->matches('/^\/v1\/repos\/directory\?limit=500&filter=[a-z0-9._-]{1,80}$/D')) {
            throw new InvalidArgumentException('keryx_read_not_admitted');
        }
        if ($this->client !== null) {
            $response = $this->client->request('GET', $this->origin . $path, ['Accept' => 'application/json']);
        } else {
            // The native client owns open/query/observed status/finally-close.
            // A native Curl option bounds wire bytes before they accumulate.
            $buffer = '';
            $oversized = false;
            $curl = new Curl(['timeout' => 5, 'connect_timeout' => 2, 'verify_ssl' => true]);
            $curl->option(CURLOPT_FOLLOWLOCATION, false);
            $curl->option(CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            $curl->option(CURLOPT_SSL_VERIFYPEER, true);
            $curl->option(CURLOPT_SSL_VERIFYHOST, 2);
            $curl->option(CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use (&$buffer, &$oversized): int {
                if (strlen($buffer) + strlen($chunk) > self::MAX_BYTES) {
                    $oversized = true;
                    return 0;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            });
            $response = (new HttpClient($curl))->request('GET', $this->origin . $path, ['Accept' => 'application/json']);
            // With WRITEFUNCTION, successful curl_exec returns true (cast to '1').
            if ($oversized || ($response['body'] ?? '') !== '1') {
                throw new RuntimeException('keryx_transport_incomplete');
            }
            $response['body'] = $buffer;
        }
        if (!Str::is($response['body'] ?? null) || strlen($response['body']) > self::MAX_BYTES) {
            throw new RuntimeException('keryx_response_invalid');
        }
        return $response;
    }
}
