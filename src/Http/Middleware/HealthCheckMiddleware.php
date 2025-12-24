<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\KvStore;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class HealthCheckMiddleware
{
    public function __construct(private readonly KvStore $kv)
    {
    }

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $disabled = $this->kv->get('sync:global_disabled', '0');
            if ($disabled === '1') {
                return new \GuzzleHttp\Promise\RejectedPromise(new \RuntimeException('Global sync is disabled due to previous fatal errors.'));
            }

            $promise = $handler($request, $options);

            return $promise->then(function (ResponseInterface $response) {
                $code = $response->getStatusCode();
                if ($code === 401 || $code === 403) {
                    $this->kv->set('sync:global_disabled', '1');
                    $this->kv->set('sync:last_fatal_error', 'HTTP ' . $code . ' on ' . date('Y-m-d H:i:s'));
                } elseif ($code >= 200 && $code < 300) {
                    // Reset consecutive failures on success (optional, or just track errors)
                    $this->kv->set('sync:consecutive_failures', '0');
                } else {
                    $this->kv->incr('sync:consecutive_failures');
                }
                return $response;
            });
        };
    }
}
