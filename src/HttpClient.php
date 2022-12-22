<?php

declare(strict_types=1);

namespace Gocanto\HttpClient;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class HttpClient extends Client
{
    public const VERSION = '1.1.0';

    private LoggerInterface $logger;
    private array $config;

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $config['on_stats'] = $config['on_stats'] ?? $this->addStatsListener();

        parent::__construct($config);
    }

    private function addStatsListener(): callable
    {
        return fn (TransferStats $stats) => $this->logger->info('Request stats summary.', [
            'method' => $stats->getRequest()->getMethod(),
            'stats' => $stats->getHandlerStats(),
        ]);
    }

    public function retry($times = 5, array $options = []): HttpClient
    {
        $handler = $options['handler'] ?? $this->getConfig('handler');
        $handler->push(Middleware::retry($this->decider($times), $this->delay($options)));

        $config = $this->getConfig();
        $config['handler'] = $handler;

        $new = clone $this;
        $new->config = empty($config) ? [] : $config;

        return $new;
    }

    public function onRetry(callable $callback, array $options = []): HttpClient
    {
        $new = clone $this;

        $decider = static function (
            $retries,
            RequestInterface $request,
            ResponseInterface $response = null,
            ConnectException $exception = null
        ) use (
            $callback,
            $new
        ) {
            $bound = Closure::bind($callback, $new, static::class);

            return $bound($retries, $request, $response, $exception);
        };

        $handler = $options['handler'] ?? $this->getConfig('handler');
        $handler->push(Middleware::retry($decider, $this->delay($options)));

        $config = $this->getConfig();
        $config['handler'] = $handler;

        $new->config = empty($config) ? [] : $config;

        return $new;
    }

    private function decider(int $retryTotal): callable
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

    private function warning(
        RequestInterface $request,
        int $retries,
        int $retryTotal,
        ?ResponseInterface $response,
        ?ConnectException $exception
    ): void {
        $this->getLogger()->warning(
            sprintf(
                'Retrying %s %s %s/%s, %s',
                $request->getMethod(),
                $request->getUri(),
                $retries + 1,
                $retryTotal,
                $response ? 'status code: ' . $response->getStatusCode() :
                    $exception->getMessage()
            ),
            [
                'exception' => $exception,
                'request' => $request,
                'response' => $response,
            ]
        );
    }

    private function delay(array $options): callable
    {
        $customDelay = $options['delay'] ?? null;

        return static function ($numberOfRetries) use ($customDelay) {
            if ($customDelay !== null) {
                return (int)$customDelay;
            }

            return (2 ** ($numberOfRetries - 1)) * 200;
        };
    }

    public function withHeaders(array $headers): self
    {
        $middleware = static function (callable $handler) use ($headers) {
            return static function (
                RequestInterface $request,
                array $options
            ) use (
                $handler,
                $headers
            ) {
                foreach ($headers as $name => $value) {
                    $request = $request->withHeader($name, $value);
                }

                return $handler($request, $options);
            };
        };

        /** @var HandlerStack $handler */
        $handler = $this->getConfig('handler');
        $handler->push($middleware, 'dynamic_headers');

        $config = $this->getConfig();
        $config['handler'] = $handler;

        $new = clone $this;
        $new->config = empty($config) ? [] : $config;

        return $new;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }
}
