<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Middleware\HealthCheckMiddleware;
use App\Infrastructure\KvStore;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise;
use PHPUnit\Framework\TestCase;
use PDO;

class HealthCheckMiddlewareTest extends TestCase
{
    private PDO $pdo;
    private KvStore $kv;
    private HealthCheckMiddleware $middleware;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE kv_store (k TEXT PRIMARY KEY, v TEXT)');
        $this->kv = new KvStore($this->pdo);
        $this->middleware = new HealthCheckMiddleware($this->kv);
    }

    public function testFatalErrorDisablesSync(): void
    {
        $handler = function ($request, $options) {
            return Promise\Create::promiseFor(new Response(401));
        };

        $mw = $this->middleware;
        $stack = $mw($handler);
        
        $request = new Request('GET', 'releases/123');
        $promise = $stack($request, []);
        $promise->wait();

        $this->assertEquals('1', $this->kv->get('sync:global_disabled'));
        $this->assertNotNull($this->kv->get('sync:last_fatal_error'));
    }

    public function testSuccessResetsFailures(): void
    {
        $this->kv->set('sync:consecutive_failures', '5');
        
        $handler = function ($request, $options) {
            return Promise\Create::promiseFor(new Response(200));
        };

        $mw = $this->middleware;
        $stack = $mw($handler);
        
        $request = new Request('GET', 'releases/123');
        $stack($request, [])->wait();

        $this->assertEquals('0', $this->kv->get('sync:consecutive_failures'));
    }

    public function testMiddlewareBlocksWhenDisabled(): void
    {
        $this->kv->set('sync:global_disabled', '1');
        
        $handler = function ($request, $options) {
            return Promise\Create::promiseFor(new Response(200));
        };

        $mw = $this->middleware;
        $stack = $mw($handler);
        
        $request = new Request('GET', 'releases/123');
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Global sync is disabled');
        
        $stack($request, [])->wait();
    }
}
