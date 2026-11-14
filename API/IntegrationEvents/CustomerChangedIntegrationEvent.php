<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * Published after commit, never inside the transaction. Primitives only: a
 * subscriber in another module cannot see this module's value objects.
 *
 * Names: customer.created, customer.updated, customer.deleted.
 */
final readonly class CustomerChangedIntegrationEvent implements IntegrationEventContract
{
    public function __construct(
        public string $action,
        public int $customerId,
        public int $rev,
        public string $occurredAt,
    ) {
    }

    public function name(): string
    {
        return 'customer.' . $this->action;
    }

    public function version(): string
    {
        return '1.0';
    }

    /** @return array{customer_id: int, rev: int, occurred_at: string} */
    public function payload(): array
    {
        return [
            'customer_id' => $this->customerId,
            'rev'         => $this->rev,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
