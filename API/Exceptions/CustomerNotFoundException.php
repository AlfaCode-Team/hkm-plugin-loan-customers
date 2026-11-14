<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\API\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\HttpStatusAware;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;

/**
 * No live customer has that id. A tombstoned customer is reported the same way,
 * because to a caller a deleted row no longer exists.
 */
final class CustomerNotFoundException extends ServiceException implements HttpStatusAware
{
    public function httpStatus(): int
    {
        return 404;
    }
}
