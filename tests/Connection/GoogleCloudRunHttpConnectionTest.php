<?php

declare(strict_types=1);

namespace Fusio\Adapter\GcpZeroTrust\Tests\Connection;

use Fusio\Adapter\GcpZeroTrust\Auth\CachedIdTokenProvider;
use Fusio\Adapter\GcpZeroTrust\Connection\GoogleCloudRunHttpConnection;
use Fusio\Engine\Parameters;
use Google\Auth\FetchAuthTokenInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class GoogleCloudRunHttpConnectionTest extends TestCase
{
    private function createConnection(array $tokenData = ['id_token' => 'fake-gcp-id-token']): GoogleCloudRunHttpConnection
    {
        $credentials = $this->createStub(FetchAuthTokenInterface::class);
        $credentials->method('fetchAuthToken')->willReturn($tokenData);

        $provider = $this->createStub(CachedIdTokenProvider::class);
        $provider->method('forAudience')->willReturn($credentials);

        return new GoogleCloudRunHttpConnection($provider);
    }

    private function makeConfig(array $overrides = []): Parameters
    {
        return new Parameters(array_merge([
            'base_url'    => 'https://my-service.run.app',
            'audience'    => 'https://my-service.run.app',
            'header_mode' => 'authorization',
            'timeout'     => '10',
            'log_enabled' => 'false',
        ], $overrides));
    }

    public function testGetName(): void
    {
        $connection = $this->createConnection();
        $this->assertSame('Google Cloud Run Zero Trust HTTP', $connection->getName());
    }

    public function testGetConnectionReturnsClient(): void
    {
        $connection = $this->createConnection();
        $client = $connection->getConnection($this->makeConfig());

        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame('https://my-service.run.app/', (string) $client->getConfig('base_uri'));
    }

    public function testAuthorizationHeaderMode(): void
    {
        $connection = $this->createConnection();
        $client = $connection->getConnection($this->makeConfig([
            'header_mode' => 'authorization',
        ]));

        // Use a mock handler to capture the outgoing request
        $history = [];
        $mock = new MockHandler([new Response(200)]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        // We need to test through the actual client returned by getConnection,
        // so we'll intercept by making a request and checking the handler stack.
        // Instead, let's test the client directly since it has our middleware.
        $this->sendRequestAndAssert($client, function (array $history) {
            $request = $history[0]['request'];
            $this->assertSame('Bearer fake-gcp-id-token', $request->getHeaderLine('Authorization'));
            $this->assertFalse($request->hasHeader('X-Serverless-Authorization'));
        });
    }

    public function testXServerlessAuthorizationHeaderMode(): void
    {
        $connection = $this->createConnection();
        $client = $connection->getConnection($this->makeConfig([
            'header_mode' => 'x-serverless-authorization',
        ]));

        $this->sendRequestAndAssert($client, function (array $history) {
            $request = $history[0]['request'];
            $this->assertSame('Bearer fake-gcp-id-token', $request->getHeaderLine('X-Serverless-Authorization'));
            // Authorization should NOT have the GCP token
            $this->assertFalse($request->hasHeader('Authorization'));
        });
    }

    public function testPreservesAppLevelAuthorizationHeader(): void
    {
        $connection = $this->createConnection();
        $client = $connection->getConnection($this->makeConfig([
            'header_mode' => 'x-serverless-authorization',
        ]));

        $this->sendRequestAndAssert($client, function (array $history) {
            $request = $history[0]['request'];
            // GCP token on X-Serverless-Authorization
            $this->assertSame('Bearer fake-gcp-id-token', $request->getHeaderLine('X-Serverless-Authorization'));
            // App-level auth preserved on Authorization
            $this->assertSame('Bearer fusio-app-token', $request->getHeaderLine('Authorization'));
        }, ['Authorization' => 'Bearer fusio-app-token']);
    }

    public function testSkipsTokenWhenEmpty(): void
    {
        $connection = $this->createConnection(['id_token' => '']);
        $client = $connection->getConnection($this->makeConfig());

        $this->sendRequestAndAssert($client, function (array $history) {
            $request = $history[0]['request'];
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertFalse($request->hasHeader('X-Serverless-Authorization'));
        });
    }

    public function testSkipsTokenWhenMissing(): void
    {
        $connection = $this->createConnection([]);
        $client = $connection->getConnection($this->makeConfig());

        $this->sendRequestAndAssert($client, function (array $history) {
            $request = $history[0]['request'];
            $this->assertFalse($request->hasHeader('Authorization'));
        });
    }

    public function testLoggingEnabled(): void
    {
        $connection = $this->createConnection();
        $client = $connection->getConnection($this->makeConfig([
            'log_enabled' => 'true',
        ]));

        // Capture error_log output
        $logOutput = $this->captureErrorLog(function () use ($client) {
            $this->sendRequestAndAssert($client, function (array $history) {
                // Request completed
                $this->assertCount(1, $history);
            });
        });

        $this->assertNotEmpty($logOutput);
        $logEntry = json_decode($logOutput[0], true);
        $this->assertSame('fusio-gcp-zero-trust', $logEntry['component']);
        $this->assertSame('GET', $logEntry['method']);
        $this->assertSame('/test', $logEntry['path']);
        $this->assertSame(200, $logEntry['status_code']);
        $this->assertArrayHasKey('duration_ms', $logEntry);
    }

    public function testLoggingDisabled(): void
    {
        $connection = $this->createConnection();
        $client = $connection->getConnection($this->makeConfig([
            'log_enabled' => 'false',
        ]));

        $logOutput = $this->captureErrorLog(function () use ($client) {
            $this->sendRequestAndAssert($client, function (array $history) {
                $this->assertCount(1, $history);
            });
        });

        $this->assertEmpty($logOutput);
    }

    /**
     * Sends a test request through the client and runs assertions on the captured history.
     *
     * The client returned by getConnection() has our auth middleware in its handler stack,
     * but we can't easily inject a mock handler into it. Instead, we rely on the fact that
     * getConnection() builds the client with HandlerStack::create() which uses the default
     * cURL handler. To avoid real HTTP calls, we need a different approach.
     *
     * We replace the client's handler by creating a new client that wraps the original
     * handler stack with a mock backend.
     */
    private function sendRequestAndAssert(Client $client, callable $assertions, array $extraHeaders = []): void
    {
        // Get the handler stack from the client config
        $originalHandler = $client->getConfig('handler');

        // Create a mock handler for the backend
        $mock = new MockHandler([new Response(200, [], 'ok')]);

        // Build a new stack that uses our mock as the base handler
        // but preserves all the middleware from the original stack
        $stack = new HandlerStack($mock);

        // We need to extract middleware from the original stack.
        // Since HandlerStack doesn't expose its middleware directly,
        // we'll reconstruct the connection with a testable approach.
        //
        // Actually, the simplest approach: the original client's handler
        // is a HandlerStack. We can resolve it and replace the inner handler.
        // But HandlerStack::setHandler() exists for this purpose.
        if ($originalHandler instanceof HandlerStack) {
            $originalHandler->setHandler($mock);
        }

        // Add history middleware to capture the final request
        $history = [];
        $originalHandler->push(Middleware::history($history), 'test_history');

        try {
            $client->get('/test', [
                'headers' => $extraHeaders,
            ]);
        } finally {
            // Clean up the history middleware so it doesn't affect other tests
            $originalHandler->remove('test_history');
        }

        $assertions($history);
    }

    /**
     * Captures error_log output during the execution of a callable.
     *
     * @return string[] Lines written to error_log
     */
    private function captureErrorLog(callable $fn): array
    {
        $logFile = tempnam(sys_get_temp_dir(), 'phpunit_log_');
        $previousLogFile = ini_set('error_log', $logFile);

        try {
            $fn();
            $content = file_get_contents($logFile);
            if ($content === false || $content === '') {
                return [];
            }
            // error_log prepends a timestamp, extract just the JSON part
            $lines = array_filter(explode("\n", trim($content)));
            return array_map(function (string $line) {
                // Strip the PHP error_log prefix (timestamp + message)
                // Format: [DD-Mon-YYYY HH:MM:SS TZ] {json}
                if (preg_match('/\{.*\}$/', $line, $matches)) {
                    return $matches[0];
                }
                return $line;
            }, $lines);
        } finally {
            ini_set('error_log', $previousLogFile ?: '');
            @unlink($logFile);
        }
    }
}
