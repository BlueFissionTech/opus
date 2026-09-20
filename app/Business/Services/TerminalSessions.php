<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Business\Presentation\CommandResultPresenter;
use BlueFission\Arr;
use BlueFission\Net\HTTP;
use BlueFission\Str;
use BlueFission\Wise\Cmd\CommandResult;
use Closure;
use InvalidArgumentException;
use SplObjectStorage;
use Throwable;

/** Transport ownership only; Wise retains parsing, policy, tools and execution. */
final class TerminalSessions
{
    private SplObjectStorage $sessions;
    private Closure $hostFactory;
    private Closure $contextResolver;

    public function __construct(
        callable $hostFactory,
        callable $contextResolver,
        private int $maxSessions = 128,
        private int $maxFrameBytes = 16384
    ) {
        if ($maxSessions < 1 || $maxFrameBytes < 1) {
            throw new InvalidArgumentException('terminal_limits_invalid');
        }
        $this->sessions = new SplObjectStorage();
        $this->hostFactory = Closure::fromCallable($hostFactory);
        $this->contextResolver = Closure::fromCallable($contextResolver);
    }

    public function open(object $connection): void
    {
        if ($this->sessions->contains($connection)) {
            return;
        }
        if ($this->sessions->count() >= $this->maxSessions) {
            $this->reject($connection, 'terminal_capacity_exceeded');
            return;
        }
        // Reserve capacity before invoking host callbacks, which may close or reenter.
        $this->sessions[$connection] = ['host' => null, 'identity' => null, 'pending' => null, 'busy' => true];
        try {
            $context = ($this->contextResolver)($connection);
            if (!$this->sessions->contains($connection)) {
                return;
            }
            $identity = $this->identity($context);
            if ($identity === null) {
                $this->reject($connection, 'terminal_authorization_required');
                return;
            }
            $host = ($this->hostFactory)($connection, $context);
            if (!$this->sessions->contains($connection)) {
                return;
            }
            if (!$host instanceof WiseCommandHost) {
                throw new InvalidArgumentException('terminal_host_invalid');
            }
            foreach ($this->sessions as $existing) {
                if ($this->sessions[$existing]['host'] === $host) {
                    throw new InvalidArgumentException('terminal_host_must_be_connection_scoped');
                }
            }
            $this->sessions[$connection] = ['host' => $host, 'identity' => $identity, 'pending' => null, 'busy' => false];
            $this->send($connection, ['type' => 'ready']);
        } catch (Throwable) {
            $this->reject($connection, 'terminal_runtime_unavailable');
        }
    }

    public function message(object $connection, mixed $message): void
    {
        if (!$this->sessions->contains($connection)) {
            $this->reject($connection, 'terminal_session_required');
            return;
        }
        $session = $this->sessions[$connection];
        if ($session['busy']) {
            $this->reject($connection, 'terminal_request_in_progress');
            return;
        }
        $session['busy'] = true;
        $this->sessions[$connection] = $session;
        try {
            $context = ($this->contextResolver)($connection);
            if (!$this->sessions->contains($connection)) {
                return;
            }
            if ($this->identity($context) !== $session['identity']) {
                $this->reject($connection, 'terminal_authorization_changed');
                return;
            }
            $frame = $this->frame($message);
            if ($frame === null) {
                $this->invalid($connection, 'terminal_frame_invalid');
                return;
            }
            $confirmation = $frame['type'] === 'confirm';
            if ($confirmation && ($session['pending'] === null || !hash_equals($session['pending'], $frame['token']))) {
                $this->invalid($connection, 'terminal_continuation_invalid');
                return;
            }
            if (!$confirmation && $session['pending'] !== null) {
                $this->invalid($connection, 'terminal_confirmation_pending');
                return;
            }
            // Consume ownership before user code; exceptions must not replay effects.
            $session['pending'] = null;
            $this->sessions[$connection] = $session;
            $result = $confirmation
                ? $session['host']->resume($frame['token'], $frame['approved'], $context)
                : $session['host']->execute($frame['input'], $context);
            if (!$this->sessions->contains($connection)) {
                return;
            }
            $session['busy'] = false;
            $session['pending'] = $result->confirmationRequired() ? $result->continuationToken() : null;
            if ($result->confirmationRequired() && !Str::isNotEmpty($session['pending'])) {
                throw new InvalidArgumentException('terminal_continuation_required');
            }
            $this->sessions[$connection] = $session;
            $this->send($connection, ['type' => 'result', 'accepted' => true, 'result' => $result->toArray()]);
        } catch (Throwable) {
            $this->reject($connection, 'terminal_execution_failed');
        }
    }

    public function close(object $connection): void
    {
        if ($this->sessions->contains($connection)) {
            $this->sessions->detach($connection);
        }
    }

    public function error(object $connection): void
    {
        $this->reject($connection, 'terminal_transport_failed');
    }

    public function sessionCount(): int
    {
        return $this->sessions->count();
    }

    private function identity(mixed $context): ?array
    {
        if (!Arr::is($context)) {
            return null;
        }
        $actor = Arr::getPath($context, 'actor.id');
        $agent = $context['agent_id'] ?? null;
        $tenant = $context['tenant_id'] ?? null;
        $profile = $context['wise_profile'] ?? null;
        if (!Str::is($actor) || Str::isEmpty(Str::trim($actor))
            || !Str::is($agent) || Str::isEmpty(Str::trim($agent))
            || ($tenant !== null && (!Str::is($tenant) || Str::isEmpty(Str::trim($tenant))))
            || !Arr::is($profile) || ($profile['type'] ?? null) !== 'user'
            || ($profile['principal_id'] ?? null) !== $actor
            || ($profile['tenant_id'] ?? null) !== $tenant
        ) {
            return null;
        }
        return [$actor, $tenant, $agent];
    }

    private function frame(mixed $message): ?array
    {
        // Bound bytes before allocating the decoded frame.
        if (!Str::is($message) || strlen($message) > $this->maxFrameBytes) {
            return null;
        }
        $frame = HTTP::jsonDecode($message, true);
        if (!Arr::is($frame)) {
            return null;
        }
        $type = $frame['type'] ?? null;
        $keys = $type === 'command' ? ['type', 'input'] : ['type', 'token', 'approved'];
        if (Arr::size($frame) !== Arr::size($keys)) {
            return null;
        }
        foreach ($frame as $key => $value) {
            if (!Arr::has($keys, $key, true)) {
                return null;
            }
        }
        if ($type === 'command') {
            return Str::is($frame['input'] ?? null) && Str::isNotEmpty(Str::trim($frame['input'])) ? $frame : null;
        }
        return $type === 'confirm' && Str::is($frame['token'] ?? null)
            && Str::isNotEmpty($frame['token']) && is_bool($frame['approved'] ?? null) ? $frame : null;
    }

    private function invalid(object $connection, string $code): void
    {
        $session = $this->sessions[$connection];
        $session['busy'] = false;
        $this->sessions[$connection] = $session;
        $result = (new CommandResultPresenter())->present(CommandResult::invalid('Terminal request rejected.', [$code]));
        $this->send($connection, ['type' => 'result', 'accepted' => false, 'result' => $result->toArray()]);
    }

    private function reject(object $connection, string $code): void
    {
        $this->close($connection);
        try {
            $this->send($connection, ['type' => 'error', 'code' => $code]);
        } catch (Throwable) {
            // A broken transport must still release the session.
        } finally {
            try { $connection->close(); } catch (Throwable) { }
        }
    }

    private function send(object $connection, array $frame): void
    {
        $encoded = HTTP::jsonEncode($frame);
        if (!Str::is($encoded) || $encoded === '') {
            throw new InvalidArgumentException('terminal_result_not_serializable');
        }
        $connection->send($encoded);
    }
}
