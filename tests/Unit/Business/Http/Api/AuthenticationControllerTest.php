<?php

declare(strict_types=1);

namespace App\Business\Http\Api {
    function response(mixed $data, int $status = 200): mixed
    {
        return $data;
    }
}

namespace Tests\Unit\Business\Http\Api {
    use App\Business\Http\Api\AuthenticationController;
    use BlueFission\BlueCore\Auth as Authenticator;
    use BlueFission\Services\Request;
    use PHPUnit\Framework\MockObject\MockObject;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;

    final class AuthenticationControllerTest extends TestCase
    {
        public function testAlreadyAuthenticatedLoginReturnsBooleanSuccess(): void
        {
            $auth = $this->authenticator();
            $auth->expects($this->once())->method('isAuthenticated')->willReturn(true);
            $auth->expects($this->once())->method('setSession')->willReturn(true);
            $auth->expects($this->once())->method('status')->willReturn([]);
            $auth->expects($this->never())->method('authenticate');

            $response = $this->controller()->login($this->request(), $auth);

            $this->assertSame([], $response['status']);
            $this->assertTrue($response['data']);
            $this->assertIsBool($response['data']);
        }

        public function testFreshLoginReturnsTheSameBooleanSuccessContract(): void
        {
            $auth = $this->authenticator();
            $auth->expects($this->once())->method('isAuthenticated')->willReturn(false);
            $auth->expects($this->once())
                ->method('authenticate')
                ->with('operator', 'secret')
                ->willReturn(true);
            $auth->expects($this->once())->method('setSession')->willReturn(true);
            $auth->expects($this->once())->method('status')->willReturn([]);

            $response = $this->controller()->login($this->request(), $auth);

            $this->assertSame([], $response['status']);
            $this->assertTrue($response['data']);
            $this->assertIsBool($response['data']);
        }

        public function testFailedLoginReturnsBooleanFailure(): void
        {
            $auth = $this->authenticator();
            $auth->expects($this->once())->method('isAuthenticated')->willReturn(false);
            $auth->expects($this->once())->method('authenticate')->willReturn(false);
            $auth->expects($this->never())->method('setSession');
            $auth->expects($this->once())->method('status')->willReturn(['invalid_credentials']);

            $response = $this->controller()->login($this->request(), $auth);

            $this->assertSame(['invalid_credentials'], $response['status']);
            $this->assertFalse($response['data']);
            $this->assertIsBool($response['data']);
        }

        private function controller(): AuthenticationController
        {
            return (new ReflectionClass(AuthenticationController::class))->newInstanceWithoutConstructor();
        }

        private function request(): Request
        {
            $request = $this->getMockBuilder(Request::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['__get'])
                ->getMock();
            $request->method('__get')->willReturnMap([
                ['login', true],
                ['remember', false],
                ['username', 'operator'],
                ['password', 'secret'],
            ]);

            return $request;
        }

        private function authenticator(): Authenticator&MockObject
        {
            return $this->getMockBuilder(Authenticator::class)
                ->disableOriginalConstructor()
                ->onlyMethods([
                    'isAuthenticated',
                    'authenticate',
                    'setSession',
                    'status',
                    'config',
                ])
                ->getMock();
        }
    }
}
