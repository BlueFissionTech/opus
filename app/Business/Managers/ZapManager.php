<?php

declare(strict_types=1);

namespace App\Business\Managers;

use BlueFission\Arr;
use BlueFission\Connections\Curl;
use BlueFission\Net\HTTP;
use BlueFission\SimpleClients\Contracts\ClientResponse;
use BlueFission\Str;
use Throwable;

final class ZapManager
{
    private const API_URL = 'https://api.zapier.com/v1/';

    private string $apiKey;
    private string $apiUrl;
    private Curl $connection;

    public function __construct(string $apiKey, ?Curl $connection = null, string $apiUrl = self::API_URL)
    {
        $this->apiKey = Str::make($apiKey)->trim()->val();
        $this->apiUrl = Str::make($apiUrl)->trim()->trim('/')->append('/')->val();
        $this->connection = $connection ?? new Curl();
    }

    public function searchZaps(string $query): ClientResponse
    {
        return $this->request('GET', 'zaps', ['search' => $query]);
    }

    public function createZap(string $name): ClientResponse
    {
        return $this->request('POST', 'zaps', [
            'name' => $name,
            'paused' => false,
        ]);
    }

    public function configureZap(string $zapId, string $stepKey, array $config): ClientResponse
    {
        $endpoint = Str::make('zaps/')
            ->append(HTTP::pathSegment($zapId))
            ->append('/steps/')
            ->append(HTTP::pathSegment($stepKey))
            ->append('/config')
            ->val();

        return $this->request('PUT', $endpoint, $config);
    }

    private function request(string $method, string $endpoint, array $data = []): ClientResponse
    {
        if (Str::isEmpty($this->apiKey)) {
            return ClientResponse::failure(
                'Zapier API credentials are unavailable.',
                0,
                ['provider' => 'zapier', 'code' => 'credentials_unavailable']
            );
        }

        $method = Str::make($method)->upper()->val();
        $target = Str::make($this->apiUrl)->append(Str::make($endpoint)->trim('/')->val());
        if ($method === 'GET' && Arr::isNotEmpty($data)) {
            $target->append('?')->append(HTTP::query($data));
        }

        try {
            $connectionMethod = $method === 'GET' ? 'GET' : 'POST';
            $this->connection->config([
                'target' => $target->val(),
                'method' => $connectionMethod,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($this->apiKey . ':'),
                ],
            ]);

            if ($method !== 'GET' && $method !== 'POST') {
                $this->connection->option(CURLOPT_CUSTOMREQUEST, $method);
            }

            $this->connection->open();
            $this->connection->query($method === 'GET' ? null : $data);

            $status = (string) $this->connection->status();
            $result = $this->connection->result();
            if ($status !== Curl::STATUS_SUCCESS || !Str::is($result)) {
                return ClientResponse::failure(
                    'Zapier request failed.',
                    0,
                    ['provider' => 'zapier', 'connection_status' => $status]
                );
            }

            $decoded = HTTP::jsonDecode((string) $result, true);
            if ($decoded === null && Str::make((string) $result)->trim()->val() !== 'null') {
                return ClientResponse::failure(
                    'Zapier returned an invalid JSON response.',
                    0,
                    ['provider' => 'zapier', 'code' => 'response_invalid']
                );
            }

            return ClientResponse::success($decoded, 200, ['provider' => 'zapier']);
        } catch (Throwable $exception) {
            return ClientResponse::failure(
                'Zapier request failed.',
                0,
                ['provider' => 'zapier', 'exception' => $exception::class]
            );
        } finally {
            try {
                $this->connection->close();
            } catch (Throwable) {
                // Cleanup cannot replace the stable integration response contract.
            }
        }
    }
}
