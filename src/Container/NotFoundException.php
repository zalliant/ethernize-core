<?php

declare(strict_types=1);

namespace Ethernize\Core\Container;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
