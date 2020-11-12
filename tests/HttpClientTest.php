<?php

declare(strict_types=1);

namespace Gocanto\HttpClient\Tests;

use Gocanto\HttpClient\HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class HttpClientTest extends TestCase
{
    /**
     * @throws GuzzleException
     * @throws RuntimeException
     */
    public function testRetry(): void
    {
        $client = $this->getHttpClient();

        $client->retry(5, ['delay' => 0])
            ->request('GET', 'http://non-existent.example.com');
    }

    /**
     * @test
     * @throws GuzzleException
     */
    public function itAllowsDecidersCallback(): void
    {
        $client = $this->getHttpClient();

        $client->onRetry($this->decider(5), ['delay' => 0])
            ->request('GET', 'http://non-existent.example.com');
    }

    /**
     * @test
     * @throws GuzzleException
     */
    public function itAllowsSettingHeadersOnDemand(): void
    {
        $stream = fopen('php://temp', 'r+');
        $client = new HttpClient(['debug' => $stream]);

        $client->withHeaders([
            'X-GUS-1' => 'gustavo',
            'X-GUS-2' => 'ocanto',
        ])->head('google.com');

        fseek($stream, 0);
        $output = stream_get_contents($stream);

        self::assertStringContainsString('X-GUS-1: gustavo', $output);
        self::assertStringContainsString('X-GUS-2: ocanto', $output);
    }

    private function getHttpClient(): HttpClient
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->times(5);
        $logger->shouldReceive('info')->times(6);

        $client = new HttpClient();

        $client->setLogger($logger);
        $this->expectException(ConnectException::class);

        return $client;
    }

    private function decider(int $retryTotal) : callable
    {
        return function (
            $retries,
            RequestInterface $request,
            ResponseInterface $response = null,
            ConnectException $exception = null
        ) use (
            $retryTotal
        ) {
            if ($retries >= $retryTotal) {
                return false;
            }

            $shouldRetry = false;

            // Retry connection exceptions
            if ($exception instanceof ConnectException) {
                $shouldRetry = true;
            }

            // Retry on server errors
            if ($response && $response->getStatusCode() >= 500) {
                $shouldRetry = true;
            }

            if ($shouldRetry === true) {
                $this->warning($request, $retries, $retryTotal, $response, $exception);
            }

            return $shouldRetry;
        };
    }
}
