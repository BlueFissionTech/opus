<?php
declare(strict_types=1);
namespace AddOns\Versa\Business;

use AddOns\Versa\Domain\{Channel, SendRequest, SendApproval, DispatchResult};
use App\Domain\Agents\AgentRuntimeContext;
use BlueFission\{Arr, Func, Str};
use BlueFission\Services\Service;
use BlueFission\SimpleClients\Contracts\{ClientInterface, ClientRequest};
use InvalidArgumentException;
use Throwable;

/** Host authority/attempt boundary; no provider, signature or delivery engine. */
class MessagingService extends Service
{
    private Arr $channels;
    private Arr $receipts;
    private Func $context;
    private Func $clients;
    private Func $review;
    private static bool $busy = false;

    public function __construct(array $channels, callable $context, callable $clients, callable $review)
    {
        parent::__construct();
        if (!Arr::isList($channels) || Arr::size($channels) > 1000) {
            throw new InvalidArgumentException('Invalid channel configuration.');
        }
        $this->channels = Arr::make([]); $this->receipts = Arr::make([]);
        $this->context = Func::make($context); $this->clients = Func::make($clients); $this->review = Func::make($review);
        foreach ($channels as $channel) {
            if (!$channel instanceof Channel || !$this->id($channel->id)
                || !$this->identityValue($channel->application) || !$this->identityValue($channel->tenant)
                || !Arr::isList($channel->participants) || Arr::size($channel->participants) > 1000) {
                throw new InvalidArgumentException('Invalid channel configuration.');
            }
            foreach ($channel->participants as $participant) {
                if (!$this->identityValue($participant)) { throw new InvalidArgumentException('Invalid participant configuration.'); }
            }
            $key = $this->key([$channel->application, $channel->tenant, $channel->id]);
            if ($this->channels->hasKey($key)) { throw new InvalidArgumentException('Duplicate channel configuration.'); }
            $this->channels->set($key, $channel);
        }
    }

    public function catalog(): array
    {
        if (self::$busy) { return []; }
        self::$busy = true;
        try {
            $host = ($this->context)(); $out = [];
            foreach ($this->channels->toArray() as $channel) {
                if ($this->allowed($channel, $host, 'versa.read')) {
                    $out[] = ['id' => $channel->id, 'send' => 'injected_transport',
                        'status' => 'attempt_only', 'notifications' => $channel->notifications,
                        'inbound' => 'unavailable', 'live_delivery' => 'unqualified'];
                }
            }
            $current = ($this->context)();
            return $host instanceof AgentRuntimeContext && $current instanceof AgentRuntimeContext
                && $host->toArray() === $current->toArray() ? $out : [];
        } catch (Throwable) { return []; }
        finally { self::$busy = false; }
    }

    public function status(string $channelId, string $requestId): DispatchResult
    {
        if (self::$busy) { return new DispatchResult('denied'); }
        if (!$this->id($channelId) || !$this->id($requestId)) { return new DispatchResult('invalid'); }
        self::$busy = true;
        try {
            $host = ($this->context)(); $channel = $this->channel($channelId, $host);
            if ($channel === null || !$this->allowed($channel, $host, 'versa.read')) { return new DispatchResult('denied'); }
            $scope = $this->scope($host);
            $entry = $this->receipts->get($this->key([$scope[0], $scope[1], $channelId, $requestId]));
            if (!$this->current($channel, $scope, 'versa.read')
                || ($entry !== null && $entry['actor'] !== $scope[2])) { return new DispatchResult('denied'); }
            return $entry['result'] ?? new DispatchResult('not_found');
        } catch (Throwable) { return new DispatchResult('denied'); }
        finally { self::$busy = false; }
    }

    public function send(SendRequest $request): DispatchResult
    {
        if (self::$busy) { return new DispatchResult('denied'); }
        self::$busy = true;
        $dispatched = false;
        try {
            if (!$this->validRequest($request)) { return new DispatchResult('invalid'); }
            $host = ($this->context)(); $channel = $this->channel($request->channel, $host);
            if ($channel === null || !$this->allowed($channel, $host, 'versa.send')
                || ($request->notification && (!$channel->notifications || !Arr::has($host->capabilities(), 'versa.notify', true)))) {
                return new DispatchResult('denied');
            }
            $scope = $this->scope($host);
            $key = $this->key([$scope[0], $scope[1], $request->channel, $request->id]);
            $digest = $this->key([$request->text, $request->media, $request->notification]);
            $entry = $this->receipts->get($key);
            if ($entry !== null) {
                if ($entry['actor'] !== $scope[2] || !$this->currentSend($channel, $scope, $request)) { return new DispatchResult('denied'); }
                return $entry['digest'] === $digest ? $entry['result'] : new DispatchResult('conflict');
            }
            if ($this->receipts->size() >= 1000) { return new DispatchResult('capacity'); }
            $approval = ($this->review)($request, $host);
            if (!$approval instanceof SendApproval || $approval->request !== $request
                || [$approval->application, $approval->tenant, $approval->actor, $approval->correlation] !== $scope
                || !$this->currentSend($channel, $scope, $request)) { return new DispatchResult('denied'); }
            $client = ($this->clients)($channel);
            if (!$client instanceof ClientInterface) { return new DispatchResult('unavailable'); }
            $capabilities = $client->capabilities();
            if (!Arr::has($capabilities->actions(), 'send', true)) { return new DispatchResult('unavailable'); }
            // This is the injected binding's body, not any public provider protocol.
            $expected = ['method' => 'POST', 'url' => 'messages', 'headers' => [], 'query' => [],
                'body' => ['channel' => $channel->id, 'text' => $request->text, 'media' => $request->media,
                    'notification' => $request->notification, 'idempotency_key' => $key, 'correlation_id' => $scope[3]],
                'options' => []];
            $native = new ClientRequest($expected);
            if ($native->toArray() !== $expected || !$this->currentSend($channel, $scope, $request)) { return new DispatchResult('denied'); }
            // Reserve before crossing the external boundary. Neither a timeout nor
            // a negative HTTP response proves that no external effect occurred.
            $result = new DispatchResult('uncertain', 'unknown', $scope[3]);
            $this->receipts->set($key, ['actor' => $scope[2], 'digest' => $digest, 'result' => $result]);
            try {
                $dispatched = true;
                $response = $client->send($native);
                if ($response->ok()) { $result = new DispatchResult('submitted', 'unknown', $scope[3]); }
            } catch (Throwable) { /* Preserve uncertain dispatch; never expose transport errors. */ }
            $this->receipts->set($key, ['actor' => $scope[2], 'digest' => $digest, 'result' => $result]);
            return $this->currentSend($channel, $scope, $request) ? $result : new DispatchResult('denied', 'unknown');
        } catch (Throwable) { return new DispatchResult('denied', $dispatched ? 'unknown' : 'none'); }
        finally { self::$busy = false; }
    }

    private function channel(string $id, mixed $host): ?Channel
    {
        return $host instanceof AgentRuntimeContext
            ? $this->channels->get($this->key([$host->applicationId(), $host->tenantId(), $id])) : null;
    }

    private function allowed(Channel $channel, mixed $host, string $capability): bool
    {
        return $host instanceof AgentRuntimeContext && $this->identityValue($host->correlationId())
            && $this->identityValue(Arr::getPath($host->actor(), 'id'))
            && $host->applicationId() === $channel->application && $host->tenantId() === $channel->tenant
            && Arr::has($channel->participants, Arr::getPath($host->actor(), 'id'), true)
            && Arr::has($host->activeAddOns(), 'versa', true)
            && Arr::getPath($host->lifecycleStates(), 'versa') === 'active'
            && Arr::has($host->capabilities(), $capability, true);
    }

    private function current(Channel $channel, array $scope, string $capability): bool
    {
        $current = ($this->context)();
        return $this->allowed($channel, $current, $capability) && $this->scope($current) === $scope;
    }

    private function currentSend(Channel $channel, array $scope, SendRequest $request): bool
    {
        $current = ($this->context)();
        return $this->allowed($channel, $current, 'versa.send') && $this->scope($current) === $scope
            && (!$request->notification || ($channel->notifications && Arr::has($current->capabilities(), 'versa.notify', true)));
    }

    private function scope(AgentRuntimeContext $host): array
    {
        return [$host->applicationId(), $host->tenantId(), Arr::getPath($host->actor(), 'id'), $host->correlationId()];
    }

    private function validRequest(SendRequest $r): bool
    {
        if (!$this->id($r->channel) || !$this->id($r->id) || $r->text === '' || strlen($r->text) > 8192
            || !Str::make($r->text)->matches('//u') || Str::make($r->text)->matches('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u')
            || !Arr::isList($r->media) || Arr::size($r->media) > 4) { return false; }
        foreach ($r->media as $media) {
            if (!Arr::is($media) || Arr::size($media) !== 3 || !$this->id($media['id'] ?? null)
                || !Str::is($media['mime'] ?? null) || !Str::make($media['mime'])->matches('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/D')
                || strlen($media['mime']) > 100 || !is_int($media['bytes'] ?? null) || $media['bytes'] < 0 || $media['bytes'] > 5242880) { return false; }
        }
        return true;
    }

    private function id(mixed $id): bool
    {
        return Str::is($id) && Str::make($id)->matches('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/D');
    }

    private function identityValue(mixed $value): bool
    {
        return Str::is($value) && strlen($value) <= 256 && Str::make($value)->trim()->isNotEmpty()
            && Str::make($value)->matches('//u') && !Str::make($value)->matches('/[\x00-\x1F\x7F]/u');
    }

    private function key(array $parts): string
    {
        // Typed tuple serialization prevents delimiter and null/empty collisions.
        return Str::make(serialize($parts))->encrypt('sha256')->val();
    }
}
