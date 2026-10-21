<?php
declare(strict_types=1);
namespace AddOns\Meridia\Domain;

use BlueFission\{Arr, Obj, Str};
use BlueFission\Services\Mapping;
use InvalidArgumentException;

/** Copies route identity; never retains or executes its callable. */
class RouteCandidate extends Obj
{
    protected $_immutable = true;
    public readonly string $target;
    public readonly string $uri;

    public function __construct(
        public readonly string $id,
        public readonly RouteSource $source,
        Mapping $mapping,
        string $uri,
        public readonly string $capability,
        public readonly array $intents = [],
        public readonly int $priority = 0,
        public readonly ?string $next = null
    ) {
        foreach ([$id, $mapping->name, $capability, ...($next === null ? [] : [$next])] as $label) {
            if (!Str::make($label)->matches('/^[a-zA-Z0-9._-]{1,128}$/D')) {
                throw new InvalidArgumentException('meridia_route_invalid');
            }
        }
        $this->uri = self::normalizeUri($uri);
        if ($mapping->method !== 'get' || '/' . Str::make($mapping->path)->trim('/')->val() !== Str::make($this->uri)->split('?')->get(0)
            || !Arr::isList($intents) || Arr::size($intents) > 32 || $priority < 0 || $priority > 100) {
            throw new InvalidArgumentException('meridia_route_invalid');
        }
        foreach ($intents as $intent) {
            if (!Str::is($intent) || !Str::make($intent)->matches('/^[a-zA-Z0-9._-]{1,128}$/D')) {
                throw new InvalidArgumentException('meridia_intent_invalid');
            }
        }
        $this->target = $mapping->name;
        $this->_data = ['version' => 1, 'id' => $id, 'target' => $this->target, 'uri' => $this->uri,
            'source_id' => $source->id, 'revision' => $source->revision, 'capability' => $capability,
            'intents' => $intents, 'priority' => $priority, 'next' => $next];
        parent::__construct();
    }

    /** Strict relative URI boundary. No authority, fragments, arrays or duplicate keys. */
    public static function normalizeUri(string $uri): string
    {
        if (Str::len($uri) > 2048 || !Str::make($uri)->matches('/^\/[a-zA-Z0-9_\/-]*(?:\?[a-zA-Z0-9_.~%=&-]+)?$/D')
            || Str::has($uri, '//')) {
            throw new InvalidArgumentException('meridia_uri_invalid');
        }
        $parts = Str::make($uri)->splitBy('/\?/', 2);
        if (!$parts->hasKey(1)) { return $parts->get(0); }
        $query = Arr::make([]);
        foreach (Str::make($parts->get(1))->split('&') as $pair) {
            $entry = Str::make($pair)->splitBy('/=/', 2);
            if ($entry->size() !== 2 || !Str::make($entry->get(0))->matches('/^[a-zA-Z0-9_-]+$/D')
                || $query->hasKey($entry->get(0)) || Str::make($entry->get(1))->matches('/%(?![0-9a-fA-F]{2})/')) {
                throw new InvalidArgumentException('meridia_query_invalid');
            }
            // Native percent encoding is the wire-format boundary, not a router.
            $query->set($entry->get(0), rawurlencode(rawurldecode($entry->get(1))));
        }
        return $parts->get(0) . '?' . $query->keys()->sort(static fn ($a, $b) => strcmp((string) $a, (string) $b))
            ->map(static fn ($key) => $key . '=' . $query->get($key))->join('&')->val();
    }
}
