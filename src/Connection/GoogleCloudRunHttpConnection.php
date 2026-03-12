<?php

declare(strict_types=1);

namespace Fusio\Adapter\GcpZeroTrust\Connection;

use Fusio\Adapter\GcpZeroTrust\Auth\CachedIdTokenProvider;
use Fusio\Engine\ConnectionInterface;
use Fusio\Engine\Form\BuilderInterface;
use Fusio\Engine\Form\ElementFactoryInterface;
use Fusio\Engine\ParametersInterface;
use Google\Auth\FetchAuthTokenInterface;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class GoogleCloudRunHttpConnection implements ConnectionInterface
{
    public function __construct(
        private CachedIdTokenProvider $tokenProvider
    ) {
    }

    public function getName(): string
    {
        return 'Google Cloud Run Zero Trust HTTP';
    }

    public function getConnection(ParametersInterface $config): Client
    {
        $baseUrl = rtrim((string) $config->get('base_url'), '/');
        $audience = (string) $config->get('audience');
        $headerMode = strtolower((string) ($config->get('header_mode') ?? 'authorization'));
        $timeout = (float) ($config->get('timeout') ?? 30);
        $logEnabled = filter_var(
            (string) ($config->get('log_enabled') ?? 'true'),
            FILTER_VALIDATE_BOOLEAN
        );

        $stack = HandlerStack::create();

        // Single middleware: fetch ID token and set the correct header directly.
        // We don't use Google's AuthTokenMiddleware because it always sets
        // the Authorization header, and a second middleware to move it to
        // X-Serverless-Authorization has a fatal ordering bug with Guzzle's
        // HandlerStack (last-pushed runs first on requests).
        $cachedCredentials = $this->tokenProvider->forAudience($audience);
        $stack->push(
            $this->gcpAuthMiddleware($cachedCredentials, $headerMode),
            'gcp_zero_trust_auth'
        );

        // Structured logging (safe: never logs tokens or bodies)
        if ($logEnabled) {
            $stack->push(
                $this->loggingMiddleware($baseUrl, $audience, $headerMode),
                'gcp_zero_trust_logging'
            );
        }

        return new Client([
            'base_uri'    => $baseUrl . '/',
            'handler'     => $stack,
            'timeout'     => $timeout,
            'http_errors' => false,
        ]);
    }

    public function configure(BuilderInterface $builder, ElementFactoryInterface $elementFactory): void
    {
        $builder->add($elementFactory->newInput(
            'base_url',
            'Base URL',
            'text',
            'Target base URL, e.g. https://service-xxxxx.run.app'
        ));

        $builder->add($elementFactory->newInput(
            'audience',
            'Audience',
            'text',
            'Cloud Run audience, usually the service URL or configured custom audience'
        ));

        $builder->add($elementFactory->newSelect(
            'header_mode',
            'Header Mode',
            [
                'authorization' => 'Authorization',
                'x-serverless-authorization' => 'X-Serverless-Authorization',
            ],
            'Which header carries the ID token. Use X-Serverless-Authorization to preserve the Authorization header for app-level auth (e.g. Fusio tokens).'
        ));

        $builder->add($elementFactory->newInput(
            'timeout',
            'Timeout',
            'number',
            'HTTP timeout in seconds (default: 30)'
        ));

        $builder->add($elementFactory->newCheckbox(
            'log_enabled',
            'Enable Logging',
            'Log outbound requests with method, path, status, and duration (never logs tokens or bodies)'
        ));
    }

    /**
     * Single Guzzle middleware that fetches a GCP ID token and sets it on the
     * configured header directly. This replaces the two-middleware approach
     * (AuthTokenMiddleware + header rewrite) which had a fatal ordering bug.
     *
     * - header_mode=authorization: sets Authorization: Bearer <token>
     * - header_mode=x-serverless-authorization: sets X-Serverless-Authorization: Bearer <token>
     *   (leaves Authorization untouched for app-level auth like Fusio tokens)
     */
    private function gcpAuthMiddleware(
        FetchAuthTokenInterface $credentials,
        string $headerMode
    ): callable {
        return function (callable $handler) use ($credentials, $headerMode) {
            return function (RequestInterface $request, array $options) use ($handler, $credentials, $headerMode) {
                $tokenData = $credentials->fetchAuthToken();
                $token = $tokenData['id_token'] ?? '';

                if ($token !== '') {
                    $header = $headerMode === 'x-serverless-authorization'
                        ? 'X-Serverless-Authorization'
                        : 'Authorization';
                    $request = $request->withHeader($header, 'Bearer ' . $token);
                }

                return $handler($request, $options);
            };
        };
    }

    /**
     * Structured JSON logging middleware.
     * Logs: component, base_url, audience, header_mode, method, path, status_code, duration_ms, error.
     * NEVER logs: token values, Authorization header, request/response bodies.
     */
    private function loggingMiddleware(string $baseUrl, string $audience, string $headerMode): callable
    {
        return function (callable $handler) use ($baseUrl, $audience, $headerMode) {
            return function (RequestInterface $request, array $options) use ($handler, $baseUrl, $audience, $headerMode) {
                $start = microtime(true);
                $logContext = [
                    'component'   => 'fusio-gcp-zero-trust',
                    'base_url'    => $baseUrl,
                    'audience'    => $audience,
                    'header_mode' => $headerMode,
                    'method'      => $request->getMethod(),
                    'path'        => $request->getUri()->getPath(),
                ];

                try {
                    $promise = $handler($request, $options);

                    return $promise->then(
                        function (ResponseInterface $response) use ($start, $logContext) {
                            $this->log(array_merge($logContext, [
                                'status_code' => $response->getStatusCode(),
                                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
                            ]));

                            return $response;
                        },
                        function ($reason) use ($start, $logContext) {
                            $this->log(array_merge($logContext, [
                                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
                                'error'       => $reason instanceof Throwable ? $reason::class : get_debug_type($reason),
                            ]));

                            return Create::rejectionFor($reason);
                        }
                    );
                } catch (Throwable $e) {
                    $this->log(array_merge($logContext, [
                        'duration_ms' => (int) ((microtime(true) - $start) * 1000),
                        'error'       => $e::class,
                    ]));

                    throw $e;
                }
            };
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(array $context): void
    {
        error_log(json_encode($context, JSON_UNESCAPED_SLASHES));
    }
}
