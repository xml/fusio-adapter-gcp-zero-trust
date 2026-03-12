<?php

use Fusio\Adapter\GcpZeroTrust\Auth\CachedIdTokenProvider;
use Fusio\Adapter\GcpZeroTrust\Connection\GoogleCloudRunHttpConnection;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container) {
    $services = $container->services();
    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(CachedIdTokenProvider::class);

    $services->set(GoogleCloudRunHttpConnection::class)
        ->tag('fusio.connection');
};
