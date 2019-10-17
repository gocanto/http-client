<?php
namespace Tests\Unit\Http;

use Gocanto\BetterHttpClient\HttpClient;
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
