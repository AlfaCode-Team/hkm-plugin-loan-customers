<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\LoanCustomers\API\Contracts\CustomerServiceContract;
use Plugins\LoanCustomers\Application\Services\CustomerService;
use Plugins\LoanCustomers\Infrastructure\Http\Controllers\CustomerController;
use Plugins\LoanCustomers\Infrastructure\Persistence\CustomerRepository;
use Plugins\LoanSync\API\Contracts\SyncRevisionContract;

/**
 * Customers — owns 'customers.management'.
 *
 * Every binding is resolved per request, so the DatabasePort it receives is the
 * request's own tenant database, never the central one.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'customers.management';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return ['database.management', 'sync.offline'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [CustomerServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(
            CustomerRepository::class,
            static fn($c): CustomerRepository => new CustomerRepository($c->make(DatabasePort::class)),
        );

        $container->bind(
            CustomerServiceContract::class,
            static fn($c): CustomerService => new CustomerService(
                customers:   $c->make(CustomerRepository::class),
                revisions:   $c->make(SyncRevisionContract::class),
                transaction: $c->make(TransactionManager::class),
                events:      $c->make(EventBus::class),
            ),
        );

        $container->bind(
            CustomerController::class,
            static fn($c): CustomerController => new CustomerController($c->make(CustomerServiceContract::class)),
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Routes are declared in module.json; nothing to hook.
    }
}
