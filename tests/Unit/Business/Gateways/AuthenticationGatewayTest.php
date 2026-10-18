<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Gateways;

use App\Business\Gateways\AuthenticationGateway;
use App\Business\Gateways\AdminAuthenticationGateway;
use App\Business\Presentation\AuthenticationFailureResponse;
use App\Domain\User\AuthenticationRequired;
use BlueFission\BlueCore\Auth;
use BlueFission\Services\Application;
use BlueFission\Services\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuthenticationGatewayTest extends TestCase
{
    public function testContainerConstructionDoesNotResolveTheAuthenticator(): void
    {
        $application = new class extends Application {
            public bool $authenticatorRequested = false;

            public function getDynamicInstance(string $class)
            {
                if ($class === Auth::class) {
                    $this->authenticatorRequested = true;
                    throw new RuntimeException('private-construction-detail');
                }
                return parent::getDynamicInstance($class);
            }
        };
        $gateway = $application->getDynamicInstance(AuthenticationGateway::class);
        $this->assertInstanceOf(AuthenticationGateway::class, $gateway);
        $this->assertFalse($application->authenticatorRequested);
    }

    public static function gateways(): array
    {
        return [[AuthenticationGateway::class], [AdminAuthenticationGateway::class]];
    }

    /** @dataProvider gateways */
    public function testDeniedGatewayStopsTheRealRouterBeforeItsEffect(string $gatewayClass): void
    {
        $auth = $this->getMockBuilder(Auth::class)->disableOriginalConstructor()
            ->onlyMethods(['isAuthenticated', 'setSession', 'destroySession'])->getMock();
        $auth->expects($this->once())->method('isAuthenticated')->willReturn(false);
        $auth->expects($this->never())->method('setSession');
        $auth->expects($this->once())->method('destroySession');
        $this->route(new $gatewayClass(static fn (): Auth => $auth), false);
    }

    /** @dataProvider gateways */
    public function testSuccessfulAuthenticationAndSessionEstablishmentAllowTheRoute(string $gatewayClass): void
    {
        $auth = $this->getMockBuilder(Auth::class)->disableOriginalConstructor()
            ->onlyMethods(['isAuthenticated', 'setSession', 'destroySession'])->getMock();
        $auth->expects($this->once())->method('isAuthenticated')->willReturn(true);
        $auth->expects($this->once())->method('setSession')->willReturn(true);
        $auth->expects($this->never())->method('destroySession');
        $this->route(new $gatewayClass(static fn (): Auth => $auth), true);
    }

    public static function failures(): array
    {
        return [['false'], ['null'], ['read_exception'], ['write_exception'], ['cleanup_exception']];
    }

    /** @dataProvider failures */
    public function testSessionFailuresNeverAuthorizeOrExposeProviderErrors(string $failure): void
    {
        $auth = $this->getMockBuilder(Auth::class)->disableOriginalConstructor()
            ->onlyMethods(['isAuthenticated', 'setSession', 'destroySession'])->getMock();
        if ($failure === 'read_exception') {
            $auth->method('isAuthenticated')->willThrowException(new RuntimeException('private-provider-detail'));
            $auth->expects($this->never())->method('setSession');
        } else {
            $auth->method('isAuthenticated')->willReturn(true);
            if ($failure === 'write_exception') {
                $auth->method('setSession')->willThrowException(new RuntimeException('private-provider-detail'));
            } else {
                $auth->method('setSession')->willReturn($failure === 'null' ? null : false);
            }
        }
        $cleanup = $auth->expects($this->once())->method('destroySession');
        if ($failure === 'cleanup_exception') {
            $cleanup->willThrowException(new RuntimeException('private-cleanup-detail'));
        }
        $gateway = new AuthenticationGateway(static fn (): Auth => $auth);
        $arguments = ['role' => 'administrator', 'authenticated' => true];
        try {
            $gateway->process(new Request(), $arguments);
            $this->fail('A failed session must stop processing.');
        } catch (AuthenticationRequired $error) {
            $this->assertSame('authentication_required', $error->getMessage());
            $this->assertSame(401, $error->getCode());
            $this->assertNull($error->getPrevious());
        }
    }

    public static function invalidProviders(): array
    {
        return [[null], [new \stdClass()], ['exception']];
    }

    /** @dataProvider invalidProviders */
    public function testInvalidOrFailedProviderResolutionIsDenied(mixed $provider): void
    {
        $gateway = new AuthenticationGateway(static function () use ($provider) {
            if ($provider === 'exception') {
                throw new RuntimeException('private-provider-detail');
            }
            return $provider;
        });
        $this->route($gateway, false);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAuthenticationDenialHasAStableHttpResponse(): void
    {
        ob_start();
        (new AuthenticationFailureResponse())->send();
        $body = ob_get_clean();
        $this->assertSame(401, http_response_code());
        $this->assertSame(['ok' => false, 'error' => 'authentication_required'], json_decode($body, true));
    }

    public function testBothConfiguredGatewaysUseTheHostBoundary(): void
    {
        $config = require dirname(__DIR__, 4) . '/common/config/app.php';
        $this->assertSame('\\' . AuthenticationGateway::class, $config['gateways']['auth']);
        $this->assertSame('\\' . AdminAuthenticationGateway::class, $config['gateways']['admin:auth']);
        $source = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        $this->assertStringContainsString('catch (AuthenticationRequired', $source);
        $this->assertStringContainsString('(new AuthenticationFailureResponse())->send()', $source);
    }

    private function route(object $gateway, bool $allowed): void
    {
        $server = $_SERVER;
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_PORT'] = 80;
        $_SERVER['REQUEST_URI'] = '/protected-test';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $executed = false;
        $application = new class($gateway) extends Application {
            public function __construct(private object $gatewayInstance)
            {
                parent::__construct(['name' => 'gateway-proof-' . bin2hex(random_bytes(6))]);
                $this->_arguments = ['_method' => 'get', 'service' => 'app', 'behavior' => '', 'data' => []];
            }

            public function resolve(string $class)
            {
                return $this->gatewayInstance;
            }
        };
        $application->gateway('protected', $gateway::class);
        $application->map('get', '/protected-test', static function () use (&$executed): string {
            $executed = true;
            return 'effect';
        })->gateway('protected');
        ob_start();
        try {
            $application->process()->run();
            $this->assertTrue($allowed, 'Denied gateway returned and allowed dispatch.');
        } catch (AuthenticationRequired $error) {
            $this->assertFalse($allowed);
        } finally {
            $output = ob_get_clean();
            $_SERVER = $server;
        }
        $this->assertSame($allowed, $executed);
        $this->assertSame($allowed ? 'effect' : '', $output);
    }
}
