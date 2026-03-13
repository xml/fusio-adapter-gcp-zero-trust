<?php

use Fusio\Adapter\GcpZeroTrust\Action\GoogleCloudRunHttpAction;
use Fusio\Adapter\GcpZeroTrust\Auth\CachedIdTokenProvider;
use Fusio\Adapter\GcpZeroTrust\Connection\GoogleCloudRunHttpConnection;
use Fusio\Engine\Adapter\ServiceBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container) {
    $services = ServiceBuilder::build($container);
    $services->set(CachedIdTokenProvider::class);
    $services->set(GoogleCloudRunHttpConnection::class);
    $services->set(GoogleCloudRunHttpAction::class);
};
