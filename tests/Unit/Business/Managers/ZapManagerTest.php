<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Managers;

use App\Business\Managers\ZapManager;
use BlueFission\Connections\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ZapManagerTest extends TestCase
{
    public function testManagerDefinitionLoadsWithoutExecutingAnIntegration(): void
    {
        $this->assertTrue(class_exists(ZapManager::class));
    }

    public function testExampleCanBeIncludedWithoutExecutingOrExiting(): void
    {
        ob_start();
        require dirname(__DIR__, 4) . '/examples/zap-manager.php';
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function testUnavailableCredentialsReturnAStableFailure(): void
    {
        $connection = $this->connection();
        $connection->expects($this->never())->method('open');

        $response = (new ZapManager('', $connection))->searchZaps('example');

        $this->assertFalse($response->ok());
        $this->assertSame('Zapier API credentials are unavailable.', $response->error());
        $this->assertSame('credentials_unavailable', $response->meta()['code']);
    }

    public function testSearchUsesTheInjectedConnectionAndReturnsDecodedData(): void
    {
        $connection = $this->successfulConnection('{"zaps":[{"id":"zap-1"}]}');
        $connection->expects($this->once())
            ->method('config')
            ->with($this->callback(function (array $config): bool {
                $this->assertSame('GET', $config['method']);
                $this->assertSame('https://api.zapier.com/v1/zaps?search=weekly+report', $config['target']);
                $this->assertStringStartsWith('Basic ', $config['headers']['Authorization']);

                return true;
            }))
            ->willReturnSelf();
        $connection->expects($this->once())->method('query')->with(null)->willReturnSelf();

        $response = (new ZapManager('secret', $connection))->searchZaps('weekly report');

        $this->assertTrue($response->ok());
        $this->assertSame([['id' => 'zap-1']], $response->data()['zaps']);
        $this->assertSame('zapier', $response->meta()['provider']);
    }

    public function testPutUsesTheConnectionCustomMethodWithoutEmbeddingCredentials(): void
    {
        $connection = $this->successfulConnection('{"id":"zap-1"}');
        $connection->expects($this->once())
            ->method('config')
            ->with($this->callback(function (array $config): bool {
                $this->assertSame('POST', $config['method']);
                $this->assertSame(
                    'https://api.zapier.com/v1/zaps/zap%2F1/steps/trigger%20key/config',
                    $config['target']
                );

                return true;
            }))
            ->willReturnSelf();
        $connection->expects($this->once())
            ->method('option')
            ->with(CURLOPT_CUSTOMREQUEST, 'PUT')
            ->willReturnSelf();
        $connection->expects($this->once())
            ->method('query')
            ->with(['enabled' => true])
            ->willReturnSelf();

        $response = (new ZapManager('secret', $connection))->configureZap(
            'zap/1',
            'trigger key',
            ['enabled' => true]
        );

        $this->assertTrue($response->ok());
    }

    public function testTransportFailureReturnsAStableFailure(): void
    {
        $connection = $this->connection();
        $connection->method('config')->willReturnSelf();
        $connection->method('open')->willReturnSelf();
        $connection->method('query')->willReturnSelf();
        $connection->method('status')->willReturn('HTTP request failed: (503)');
        $connection->method('result')->willReturn('{"error":"unavailable"}');
        $connection->expects($this->once())->method('close')->willReturnSelf();

        $response = (new ZapManager('secret', $connection))->createZap('Example');

        $this->assertFalse($response->ok());
        $this->assertSame('Zapier request failed.', $response->error());
        $this->assertSame('HTTP request failed: (503)', $response->meta()['connection_status']);
    }

    public function testConnectionExceptionReturnsAStableFailure(): void
    {
        $connection = $this->connection();
        $connection->method('config')->willThrowException(new \RuntimeException('unavailable'));
        $connection->expects($this->once())->method('close')->willReturnSelf();

        $response = (new ZapManager('secret', $connection))->createZap('Example');

        $this->assertFalse($response->ok());
        $this->assertSame('Zapier request failed.', $response->error());
        $this->assertSame(\RuntimeException::class, $response->meta()['exception']);
    }

    public function testInvalidJsonReturnsAStableFailure(): void
    {
        $connection = $this->successfulConnection('not-json');
        $connection->method('query')->willReturnSelf();

        $response = (new ZapManager('secret', $connection))->createZap('Example');

        $this->assertFalse($response->ok());
        $this->assertSame('Zapier returned an invalid JSON response.', $response->error());
        $this->assertSame('response_invalid', $response->meta()['code']);
    }

    /** @return Curl&MockObject */
    private function successfulConnection(string $result): Curl
    {
        $connection = $this->connection();
        $connection->method('config')->willReturnSelf();
        $connection->method('open')->willReturnSelf();
        $connection->method('status')->willReturn(Curl::STATUS_SUCCESS);
        $connection->method('result')->willReturn($result);
        $connection->expects($this->once())->method('close')->willReturnSelf();

        return $connection;
    }

    /** @return Curl&MockObject */
    private function connection(): Curl
    {
        return $this->getMockBuilder(Curl::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['config', 'open', 'query', 'result', 'status', 'close', 'option'])
            ->getMock();
    }
}
