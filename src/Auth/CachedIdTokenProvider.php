<?php

declare(strict_types=1);

namespace Fusio\Adapter\GcpZeroTrust\Auth;

use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\Cache\MemoryCacheItemPool;
use Google\Auth\FetchAuthTokenCache;
use Google\Auth\FetchAuthTokenInterface;

class CachedIdTokenProvider
{
    /**
     * Returns cache-wrapped credentials for a specific audience.
     *
     * Uses ApplicationDefaultCredentials which works on Cloud Run (metadata server)
     * and locally (gcloud auth application-default login).
     */
    public function forAudience(string $audience): FetchAuthTokenInterface
    {
        $credentials = ApplicationDefaultCredentials::getIdTokenCredentials($audience);

        return new FetchAuthTokenCache(
            $credentials,
            ['lifetime' => 3300], // Refresh before the 1h token expiry
            new MemoryCacheItemPool()
        );
    }
}
