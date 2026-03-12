<?php

declare(strict_types=1);

namespace Fusio\Adapter\GcpZeroTrust;

use Fusio\Engine\AdapterInterface;

class Adapter implements AdapterInterface
{
    public function getContainerFile(): string
    {
        return __DIR__ . '/../resources/container.php';
    }
}
