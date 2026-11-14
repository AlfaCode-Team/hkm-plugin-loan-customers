<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\Domain;

enum CustomerStatus: string
{
    case Active      = 'active';
    case Inactive    = 'inactive';
    case Blacklisted = 'blacklisted';
}
