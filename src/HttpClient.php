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

namespace Gocanto\HttpClient;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class HttpClient extends Client
{
    public const VERSION = '1.0.0';

    /** @var LoggerInterface */
    private $logger;
    /** @var array Default request options */
    private $config;

    /**
     * @param array $config
     * @param LoggerInterface|null $logger
     */
    public function __construct(array $config = [], LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $config['on_stats'] = $config['on_stats'] ?? $this->addStatsListener();

        parent::__construct($config);
    }

    /**
     * @return callable
     */
    private function addStatsListener() : callable
    {
        return function (TransferStats $stats) {
            $this->logger->info('Request stats summary.', [
                'method' => $stats->getRequest()->getMethod(),
                'stats' => $stats->getHandlerStats(),
            ]);
        };
    }

    /**
     * @param int $times
     * @param array $options
     * @return HttpClient
     */
    public function retry($times = 5, array $options = []): HttpClient
    {
        $handler = $options['handler'] ?? $this->getConfig('handler');
        $handler->push(Middleware::retry($this->decider($times), $this->delay($options)));

        $config = $this->getConfig();
        $config['handler'] = $handler;

        $new = clone $this;
        $new->config = $config;

        return $new;
    }

    /**
     * @param callable $callback
     * @param array $options
     * @return HttpClient
     */
    public function onRetry(callable $callback, array $options = []): HttpClient
    {
        $new = clone $this;

        $decider = function (
            $retries,
            RequestInterface $request,
            ResponseInterface $response = null,
            RequestException $exception = null
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

        $new->config = $config;

        return $new;
    }

    /**
     * @param $retryTotal
     * @return callable
     */
    private function decider($retryTotal): callable
    {
        return function (
            $retries,
            RequestInterface $request,
            ResponseInterface $response = null,
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

    /**
     * @param RequestInterface $request
     * @param int $retries
     * @param int $retryTotal
     * @param ResponseInterface|null $response
     * @param RequestException|null $exception
     */
    private function warning(
        RequestInterface $request,
        int $retries,
        int $retryTotal,
        ?ResponseInterface $response,
        ?RequestException $exception
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

    /**
     * By default uses an exponentially increasing delay between retries.
     *
     * @param array $options
     * @return callable
     */
    private function delay(array $options): callable
    {
        $customDelay = $options['delay'] ?? null;

        return function ($numberOfRetries) use ($customDelay) {
            if ($customDelay !== null) {
                return (int)$customDelay;
            }

            return (2 ** ($numberOfRetries - 1)) * 200;
        };
    }

    /**
     * @param array $headers
     * @return HttpClient
     */
    public function withHeaders(array $headers): HttpClient
    {
        $middleware = function (callable $handler) use ($headers) {
            return function (
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
        $new->config = $config;

        return $new;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }
}
