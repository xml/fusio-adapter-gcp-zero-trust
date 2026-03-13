# fusio/adapter-gcp-zero-trust

Fusio adapter for calling Cloud Run services with automatic Google-signed ID token injection.

Provides a **Connection** type and a matching **Action** type that work together:

- **Connection** (`Google Cloud Run Zero Trust HTTP`) — returns a Guzzle HTTP client with ID token middleware
- **Action** (`Google Cloud Run HTTP`) — proxies Fusio requests through that connection to a downstream Cloud Run service

## Install

```bash
composer require fusio/adapter-gcp-zero-trust
php bin/fusio system:register "Fusio\Adapter\GcpZeroTrust\Adapter"
```

## Setup

### Step 1: Create a Connection

**Backend > API > Connections > Create**

Select type: **Google Cloud Run Zero Trust HTTP**

| Field | Description |
|-------|-------------|
| **Base URL** | Target service URL, e.g. `https://my-service-abc123.run.app` |
| **Audience** | Cloud Run audience for the ID token. Usually the same as the base URL. Must be the `.run.app` URL (custom domains are not supported as audience). |
| **Header Mode** | `Authorization` or `X-Serverless-Authorization` (see below) |
| **Timeout** | HTTP timeout in seconds (default: 30) |
| **Enable Logging** | Log outbound requests with method, path, status code, and duration. Never logs tokens or bodies. |

### Step 2: Create an Action

**Backend > API > Actions > Create**

Select type: **Google Cloud Run HTTP**

| Field | Description |
|-------|-------------|
| **Connection** | Select the connection created in Step 1 (dropdown of all configured connections) |
| **Method** | `AUTO` (forwards the inbound HTTP method), or a fixed method: `GET`, `POST`, `PUT`, `PATCH`, `DELETE` |
| **Path** | Relative path on the target service, e.g. `/api/v1/users`. Supports `{{param}}` placeholders resolved from URI fragments. |
| **Forward Headers** | Comma-separated list of inbound headers to forward, e.g. `X-Request-Id, Accept-Language` |

### Step 3: Bind the Action to an Operation

**Backend > API > Operations**

Point a route to the action. The action will:

1. Load the configured connection (which returns a Guzzle client with ID token middleware)
2. Forward the HTTP method, path, query parameters, and request body to the downstream service
3. Return the downstream response (status code, headers, and body) back through Fusio

## Header modes

- **Authorization** — Sets `Authorization: Bearer <id-token>`. Use when the target service has no app-level auth.
- **X-Serverless-Authorization** — Sets `X-Serverless-Authorization: Bearer <id-token>`, leaving `Authorization` free for app-level auth (e.g. Fusio Bearer tokens). Cloud Run validates this header for IAM, strips it before forwarding to the container, and passes `Authorization` through untouched. [Cloud Run docs](https://cloud.google.com/run/docs/authenticating/service-to-service).

## How authentication works

The adapter uses [Google Application Default Credentials](https://cloud.google.com/docs/authentication/application-default-credentials) to fetch ID tokens:

- **On Cloud Run**: Uses the metadata server automatically. The calling service's service account must have `roles/run.invoker` on the target service.
- **Locally**: Uses credentials from `gcloud auth application-default login`.

Tokens are cached in-process for ~55 minutes (tokens are valid for 1 hour) using `google/auth`'s `FetchAuthTokenCache` with `MemoryCacheItemPool`.

## Example: Dockerfile for a custom Fusio image

```dockerfile
FROM fusio/fusio:5.2.5

WORKDIR /var/www/html/fusio

RUN composer require fusio/adapter-gcp-zero-trust:^0.2 \
    --no-interaction --no-progress

RUN php bin/fusio system:register \
    "Fusio\\Adapter\\GcpZeroTrust\\Adapter"
```

## Requirements

- PHP >= 8.1
- Fusio Engine ^6.0 (ships with `fusio/fusio` v5.2+)
- `google/auth` ^1.50
- `guzzlehttp/guzzle` ^7.0

## Development

```bash
make install    # Install dependencies
make test       # Run tests (PHPUnit)
make lint       # Static analysis (phpstan level 6)
```

## License

Apache-2.0
