<?php

declare(strict_types=1);

/*
 * This file is part of the Gocanto http-client package.
 *
 * (c) Gustavo Ocanto <gustavoocanto@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Http;

use Gocanto\HttpClient\HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use PHPUnit\Framework\TestCase;
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

        $this->assertInstanceOf(LoggerInterface::class, $client->getLogger());
    }

    /**
     * @test
     * @throws GuzzleException
     */
    public function itAllowsDecidersCallback()
    {
        $client = $this->getHttpClient();

        $client->onRetry($this->decider(5), ['delay' => 0])
            ->request('GET', 'http://non-existent.example.com');

        $this->assertInstanceOf(LoggerInterface::class, $client->getLogger());
    }

    /**
     * @test
     * @throws GuzzleException
     */
    public function itAllowsSettingHeadersOnDemand()
    {
        $stream = fopen('php://temp', 'r+');
        $client = new HttpClient(['debug' => $stream]);

        $client->withHeaders([
            'X-GUS-1' => 'gustavo',
            'X-GUS-2' => 'ocanto',
        ])->head('google.com');

        fseek($stream, 0);
        $output = stream_get_contents($stream);

        $this->assertStringContainsString('X-GUS-1: gustavo', $output);
        $this->assertStringContainsString('X-GUS-2: ocanto', $output);
    }

    /**
     * @return HttpClient
     */
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

    /**
     * @param int $retryTotal
     * @return callable
     */
    private function decider(int $retryTotal) : callable
    {
        return function (
            $retries,
            Request $request,
            Response $response = null,
            RequestException $exception = null
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
