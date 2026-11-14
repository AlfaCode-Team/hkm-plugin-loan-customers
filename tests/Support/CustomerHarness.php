<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanCustomers\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\LoanCustomers\API\DTO\CustomerInput;
use Plugins\LoanCustomers\Application\Services\CustomerService;
use Plugins\LoanCustomers\Infrastructure\Persistence\CustomerRepository;
use Plugins\LoanSync\Application\Services\SyncService;
use Plugins\LoanSync\Infrastructure\Persistence\DeviceRepository;
use Plugins\LoanSync\Infrastructure\Persistence\EntityRepository;
use Plugins\LoanSync\Infrastructure\Persistence\OperationLog;
use Plugins\LoanSync\Infrastructure\Persistence\RevisionRepository;
use Plugins\LoanSync\Infrastructure\Persistence\ShardRepository;
use Tests\Unit\Plugins\LoanCustomers\Support\TestDatabase;

/**
 * A real tenant database on disk, migrated by the real migration runner, with
 * the real adapter, the real Customers service and the real Sync revision
 * counter in front of it. Nothing here is a fake DatabasePort.
 */
final class CustomerHarness
{
    public readonly DatabasePort $db;
    public readonly CustomerService $customers;
    public readonly SyncService $sync;
    public readonly TestDatabase $database;

    public function __construct()
    {
        // SQLite unless HKM_TEST_DB says otherwise. The same tests then run
        // against MySQL, PostgreSQL or SQL Server without a second suite.
        //
        // The schema is assembled from the PLUGINS that own each table, so
        // this suite tests against the same migrations a deployment runs.
        $this->database = TestDatabase::provision('loan_customers', [
            \Plugins\LoanSync\Provider::class,
            \Plugins\LoanCustomers\Provider::class,
        ]);
        $this->db = $this->database->port;

        $identity = new Identity(
            userId:      'officer-1',
            tenantId:    'tenant-test',
            roles:       ['admin'],
            permissions: [],
            tokenType:   'jwt',
        );

        $revisions = new RevisionRepository($this->db);

        $this->customers = new CustomerService(
            customers:   new CustomerRepository($this->db),
            revisions:   $revisions,
            transaction: new TransactionManager($this->db),
        );

        $this->sync = new SyncService(
            entities:    new EntityRepository($this->db),
            revisions:   $revisions,
            operations:  new OperationLog($this->db),
            shards:      new ShardRepository($this->db),
            devices:     new DeviceRepository($this->db),
            transaction: new TransactionManager($this->db),
            identity:    $identity,
        );
    }

    /**
     * A valid create request. Overrides are merged over it.
     *
     * @param array<string, mixed> $overrides
     */
    public static function create(array $overrides = []): CustomerInput
    {
        return CustomerInput::fromArray(array_merge([
            'first_name'     => 'Aminah',
            'last_name'      => 'Nakato',
            'phone'          => '0772000001',
            'branch_id'      => 1,
            'status'         => 'active',
            'registered_on'  => '2026-11-13',
        ], $overrides), creating: true);
    }

    /** @param array<string, mixed> $changes */
    public static function update(array $changes): CustomerInput
    {
        return CustomerInput::fromArray($changes, creating: false);
    }

    public function destroy(): void
    {
        $this->database->destroy();
    }
}
