<?php

declare(strict_types=1);

namespace Fusio\Adapter\GcpZeroTrust\Action;

use Fusio\Engine\ActionAbstract;
use Fusio\Engine\ContextInterface;
use Fusio\Engine\Form\BuilderInterface;
use Fusio\Engine\Form\ElementFactoryInterface;
use Fusio\Engine\ParametersInterface;
use Fusio\Engine\RequestInterface;
use Fusio\Engine\Request\HttpRequestContext;
use GuzzleHttp\Client;
use PSX\Http\Environment\HttpResponseInterface;

final class GoogleCloudRunHttpAction extends ActionAbstract
{
    public function getName(): string
    {
        return 'Google Cloud Run HTTP';
    }

    public function configure(BuilderInterface $builder, ElementFactoryInterface $elementFactory): void
    {
        $builder->add($elementFactory->newConnection(
            'connection',
            'Connection',
            'The zero-trust connection to the target Cloud Run service'
        ));

        $builder->add($elementFactory->newSelect(
            'method',
            'Method',
            [
                'AUTO' => 'AUTO (forward inbound method)',
                'GET' => 'GET',
                'POST' => 'POST',
                'PUT' => 'PUT',
                'PATCH' => 'PATCH',
                'DELETE' => 'DELETE',
            ],
            'HTTP method. AUTO forwards the inbound request method.'
        ));

        $builder->add($elementFactory->newInput(
            'path',
            'Path',
            'text',
            'Relative path on the target service, e.g. /api/v1/resource. Supports {{id}} placeholders from URI fragments.'
        ));

        $builder->add($elementFactory->newInput(
            'forward_headers',
            'Forward Headers',
            'text',
            'Comma-separated list of inbound headers to forward (e.g. X-Request-Id, Accept-Language)'
        ));
    }

    public function handle(RequestInterface $request, ParametersInterface $configuration, ContextInterface $context): HttpResponseInterface
    {
        /** @var Client $client */
        $client = $this->connector->getConnection($configuration->get('connection'));

        $method = $this->resolveMethod($request, (string) ($configuration->get('method') ?? 'AUTO'));
        $path = $this->resolvePath($request, (string) ($configuration->get('path') ?? '/'));

        $options = [
            'headers' => $this->buildForwardHeaders($request, (string) ($configuration->get('forward_headers') ?? '')),
            'http_errors' => false,
        ];

        // Forward query parameters
        $requestContext = $request->getContext();
        if ($requestContext instanceof HttpRequestContext) {
            $queryParams = $requestContext->getRequest()->getUri()->getParameters();
            if (!empty($queryParams)) {
                $options['query'] = $queryParams;
            }
        }

        // Forward body for methods that carry a payload
        $payload = $request->getPayload();
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $payload !== null) {
            $options['json'] = is_object($payload) && method_exists($payload, 'toArray')
                ? $payload->toArray()
                : $payload;
        }

        $response = $client->request($method, ltrim($path, '/'), $options);

        return $this->response->proxy($response);
    }

    private function resolveMethod(RequestInterface $request, string $configured): string
    {
        if ($configured !== 'AUTO') {
            return $configured;
        }

        $requestContext = $request->getContext();
        if ($requestContext instanceof HttpRequestContext) {
            return strtoupper($requestContext->getRequest()->getMethod());
        }

        return 'GET';
    }

    private function resolvePath(RequestInterface $request, string $pathTemplate): string
    {
        $requestContext = $request->getContext();
        if ($requestContext instanceof HttpRequestContext) {
            $parameters = $requestContext->getParameters();
            foreach ($parameters as $key => $value) {
                $pathTemplate = str_replace('{{' . $key . '}}', (string) $value, $pathTemplate);
            }
        }

        return $pathTemplate;
    }

    /**
     * @return array<string, string>
     */
    private function buildForwardHeaders(RequestInterface $request, string $forwardHeaders): array
    {
        $result = [];
        $names = array_filter(array_map('trim', explode(',', $forwardHeaders)));

        if (empty($names)) {
            return $result;
        }

        $requestContext = $request->getContext();
        if (!$requestContext instanceof HttpRequestContext) {
            return $result;
        }

        $psxRequest = $requestContext->getRequest();
        foreach ($names as $name) {
            $value = $psxRequest->getHeader($name);
            if ($value !== '') {
                $result[$name] = $value;
            }
        }

        return $result;
    }
}
