<?php
declare(strict_types=1);
namespace AddOns\Meridia\Business;

use AddOns\Meridia\Domain\{CrawlDiscovery, ResolutionContext, ResolutionResult, RouteCandidate};
use BlueFission\{Arr, DevElation, Func, Str};
use BlueFission\Services\Service;
use InvalidArgumentException;
use Throwable;

class RouteService extends Service
{
    private Arr $routes;
    private Func $clock;
    private static bool $observing = false;

    public function __construct(array $routes, callable $clock)
    {
        parent::__construct();
        $this->routes = Arr::make([]);
        if (!Arr::isList($routes) || Arr::size($routes) > 128) {
            throw new InvalidArgumentException('meridia_catalog_invalid');
        }
        foreach ($routes as $route) {
            if (!$route instanceof RouteCandidate || $this->routes->hasKey($route->id)) {
                throw new InvalidArgumentException('meridia_catalog_invalid');
            }
            $this->routes->set($route->id, $route);
        }
        $this->clock = Func::make($clock);
    }

    public function catalog(ResolutionContext $context): array
    {
        if (!$context->admitted || self::$observing) { return []; }
        $now = ($this->clock)();
        return $this->routes->filter(fn ($route) =>
            $this->scoped($route, $context) && $this->follow($route, $context, $now)->status === 'resolved')
            ->map(static fn ($route) => $route->toArray())->values()->toArray();
    }

    public function crawlPolicy(): CrawlDiscovery { return new CrawlDiscovery(); }

    public function resolve(string $kind, string $value, ResolutionContext $context): ResolutionResult
    {
        if (self::$observing) { return new ResolutionResult('denied'); }
        try {
            $result = $this->select($kind, $value, $context);
        } catch (Throwable) { $result = new ResolutionResult('invalid'); }
        self::$observing = true;
        try {
            DevElation::do('meridia.resolve.result', [['version' => 1, 'status' => $result->status, 'effects' => 'none']]);
        } catch (Throwable) {
            // An observer cannot replace the route or disclose the request.
        } finally { self::$observing = false; }
        return $result;
    }

    private function select(string $kind, string $value, ResolutionContext $context): ResolutionResult
    {
        if (!$context->admitted) { return new ResolutionResult('denied'); }
        if (!Arr::has(['url', 'intent'], $kind, true)) { return new ResolutionResult('invalid'); }
        if ($kind === 'url') { $value = RouteCandidate::normalizeUri($value); }
        elseif (!Str::make($value)->matches('/^[a-zA-Z0-9._-]{1,128}$/D')) { return new ResolutionResult('invalid'); }
        $matches = $this->routes->filter(fn ($route) => $this->scoped($route, $context)
            && ($kind === 'url' ? $route->uri === $value : Arr::has($route->intents, $value, true)))->values();
        if ($matches->size() === 0) { return new ResolutionResult('no_match'); }
        $matches->sort(static fn ($a, $b) => $b->priority <=> $a->priority);
        $priority = $matches->get(0)->priority;
        $matches = $matches->filter(static fn ($route) => $route->priority === $priority)->values();
        if ($matches->size() !== 1) { return new ResolutionResult('ambiguous'); }
        return $this->follow($matches->get(0), $context, ($this->clock)());
    }

    private function follow(RouteCandidate $route, ResolutionContext $context, int $now): ResolutionResult
    {
        $seen = Arr::make([]);
        while (true) {
            if (!$this->scoped($route, $context)) { return new ResolutionResult('invalid_target'); }
            $denial = $this->eligible($route, $context, $now);
            if ($denial !== null) { return new ResolutionResult($denial); }
            if ($seen->hasKey($route->id)) { return new ResolutionResult('loop'); }
            $seen->set($route->id, true);
            if ($route->next === null) { return new ResolutionResult('resolved', $route); }
            $route = $this->routes->get($route->next);
            if (!$route instanceof RouteCandidate) { return new ResolutionResult('invalid_target'); }
        }
    }

    private function scoped(RouteCandidate $route, ResolutionContext $context): bool
    {
        return $route->source->application === $context->application && $route->source->tenant === $context->tenant;
    }

    private function eligible(RouteCandidate $route, ResolutionContext $context, int $now): ?string
    {
        if (!Arr::has($context->capabilities, $route->capability, true)) { return 'denied'; }
        return $route->source->expiresAt <= $now ? 'stale' : null;
    }
}
