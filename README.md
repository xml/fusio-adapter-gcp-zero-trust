# fusio/adapter-gcp-zero-trust

Fusio adapter providing a Cloud Run zero-trust HTTP connection with automatic Google-signed ID token injection.

## What it does

Provides a custom Fusio Connection type ("Google Cloud Run Zero Trust HTTP") that returns a Guzzle HTTP client with middleware to:

1. Fetch a Google-signed ID token for the target service audience
2. Attach it as `Authorization` or `X-Serverless-Authorization` header on every outgoing request
3. Cache tokens in-process (~55 min lifetime, auto-refresh)
4. Optionally log outbound requests with structured JSON (method, path, status, duration — never tokens or bodies)

This enables Fusio to call other Cloud Run services in a [zero-trust networking model](https://cloud.google.com/run/docs/authenticating/service-to-service) where every service-to-service call authenticates with an ID token.

## Install

```bash
composer require fusio/adapter-gcp-zero-trust
php bin/fusio system:register "Fusio\Adapter\GcpZeroTrust\Adapter"
```

## Usage

In the Fusio backend UI:

**Backend → API → Connections → Create**

Select connection type: **Google Cloud Run Zero Trust HTTP**

### Configuration fields

| Field | Description |
|-------|-------------|
| **Base URL** | Target service URL, e.g. `https://my-service-abc123.run.app` |
| **Audience** | Cloud Run audience for the ID token. Usually the same as the base URL. Must be the `.run.app` URL — custom domains are not supported as audience. |
| **Header Mode** | `Authorization` or `X-Serverless-Authorization`. Use `X-Serverless-Authorization` when your actions also set app-level auth on the `Authorization` header (e.g. Fusio Bearer tokens). |
| **Timeout** | HTTP timeout in seconds (default: 30) |
| **Enable Logging** | Log outbound requests with method, path, status code, and duration. Never logs tokens or request/response bodies. |

### Header modes

- **Authorization** — Sets `Authorization: Bearer <id-token>`. Use when the target service has no app-level auth.
- **X-Serverless-Authorization** — Sets `X-Serverless-Authorization: Bearer <id-token>`, leaving `Authorization` free for app-level auth. Cloud Run validates this header for IAM, strips it before forwarding to the container, and passes `Authorization` through untouched. [Cloud Run docs](https://cloud.google.com/run/docs/authenticating/service-to-service).

## Example: Dockerfile for a custom Fusio image

```dockerfile
FROM fusio/fusio:5.2.5

WORKDIR /var/www/html/fusio

RUN composer require fusio/adapter-gcp-zero-trust:^0.1 \
    --no-interaction --no-progress

RUN php bin/fusio system:register \
    "Fusio\\Adapter\\GcpZeroTrust\\Adapter"
```

## How authentication works

The adapter uses [Google Application Default Credentials](https://cloud.google.com/docs/authentication/application-default-credentials) to fetch ID tokens:

- **On Cloud Run**: Uses the metadata server automatically. The calling service's service account must have `roles/run.invoker` on the target service.
- **Locally**: Uses credentials from `gcloud auth application-default login`. Useful for development, though the token's `email` claim will be your user account, not a service account.

Tokens are cached in-process for ~55 minutes (tokens are valid for 1 hour) using `google/auth`'s `FetchAuthTokenCache` with `MemoryCacheItemPool`.

## Requirements

- PHP >= 8.1
- Fusio Engine ^6.0 (ships with `fusio/fusio` v5.2+)
- `google/auth` ^1.50
- `guzzlehttp/guzzle` ^7.0

## Development

```bash
# Install dependencies
make install

# Run tests
make test

# Run static analysis (phpstan level 6)
make lint
```

## License

Apache-2.0
