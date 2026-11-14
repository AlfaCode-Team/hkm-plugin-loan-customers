<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use Plugins\LoanCustomers\API\Contracts\CustomerServiceContract;
use Plugins\LoanCustomers\API\DTO\CustomerInput;
use Plugins\LoanCustomers\API\DTO\CustomerSearch;
use Plugins\LoanCustomers\API\Exceptions\CustomerNotFoundException;
use Plugins\LoanCustomers\API\IntegrationEvents\CustomerChangedIntegrationEvent;
use Plugins\LoanCustomers\Domain\Customer;
use Plugins\LoanCustomers\Domain\CustomerProfile;
use Plugins\LoanCustomers\Infrastructure\Persistence\CustomerRepository;
use Plugins\LoanSync\API\Contracts\SyncRevisionContract;

/**
 * Borrower writes and reads.
 *
 * Every write follows the same shape, and the shape is the point:
 *
 *   begin
 *     rev  = revisions->next()       — FIRST, so the rev lock orders the write
 *     row  = read / mint id          — inside the lock, so ids cannot collide
 *     save(row stamped with rev)
 *   commit                           — the rev and the row land together or not at all
 *   dispatch the integration event   — only now, after commit
 *
 * Taking the rev before reading the row means a concurrent writer cannot slip a
 * change between the read and the stamp. A rollback also returns the counter, so
 * a "not found" update does not burn a rev no handset will ever see.
 */
final class CustomerService implements CustomerServiceContract
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly SyncRevisionContract $revisions,
        private readonly TransactionManager $transaction,
        private readonly ?EventBus $events = null,
    ) {
    }

    public function list(CustomerSearch $search): array
    {
        $page = $this->customers->search($search);

        return [
            'items'    => array_map(fn(Customer $c): array => $this->present($c), $page['items']),
            'total'    => $page['total'],
            'page'     => $search->page,
            'per_page' => $search->perPage,
        ];
    }

    public function find(int $id): array
    {
        $customer = $this->customers->find($id);

        if ($customer === null) {
            throw $this->notFound($id);
        }

        return $this->present($customer);
    }

    public function create(CustomerInput $input): array
    {
        $profile = $input->toProfile();
        $now     = $this->now();

        $this->transaction->begin();

        try {
            $rev      = $this->revisions->next();
            $customer = Customer::create($this->customers->nextId(), $profile, $now);

            $customer->stampRev($rev);
            $this->customers->insert($customer);

            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();

            throw new ServiceException('customer.create.failed', layer: 'service.customers', previous: $e);
        }

        $this->publish('created', $customer);

        return $this->present($customer);
    }

    public function update(int $id, CustomerInput $input): array
    {
        $now = $this->now();

        $this->transaction->begin();

        try {
            $rev      = $this->revisions->next();
            $customer = $this->customers->find($id);

            if ($customer === null) {
                throw $this->notFound($id);
            }

            $customer->revise($input->applyTo($customer->profile()), $input->touched(), $now);
            $customer->stampRev($rev);
            $this->customers->save($customer);

            $this->transaction->commit();
        } catch (CustomerNotFoundException $e) {
            $this->transaction->rollback();

            throw $e;
        } catch (\Throwable $e) {
            $this->transaction->rollback();

            throw new ServiceException('customer.update.failed', layer: 'service.customers', previous: $e);
        }

        $this->publish('updated', $customer);

        return $this->present($customer);
    }

    public function delete(int $id): array
    {
        $now = $this->now();

        $this->transaction->begin();

        try {
            $rev      = $this->revisions->next();
            $customer = $this->customers->find($id, includeDeleted: true);

            if ($customer === null) {
                throw $this->notFound($id);
            }

            if ($customer->isDeleted()) {
                // Already a tombstone. Keep the counter as it was: a second rev
                // would wake the row on every handset for no change at all.
                $this->transaction->rollback();

                return $this->present($customer);
            }

            $customer->tombstone($now);
            $customer->stampRev($rev);
            $this->customers->save($customer);

            $this->transaction->commit();
        } catch (CustomerNotFoundException $e) {
            $this->transaction->rollback();

            throw $e;
        } catch (\Throwable $e) {
            $this->transaction->rollback();

            throw new ServiceException('customer.delete.failed', layer: 'service.customers', previous: $e);
        }

        $this->publish('deleted', $customer);

        return $this->present($customer);
    }

    private function publish(string $action, Customer $customer): void
    {
        $this->events?->dispatch(new CustomerChangedIntegrationEvent(
            action:     $action,
            customerId: $customer->id(),
            rev:        $customer->rev(),
            occurredAt: $this->now()->format(\DateTimeInterface::RFC3339),
        ));
    }

    private function notFound(int $id): CustomerNotFoundException
    {
        return new CustomerNotFoundException(
            sprintf('Customer [%d] was not found.', $id),
            layer: 'service.customers',
            context: ['id' => $id],
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    private function present(Customer $customer): array
    {
        $p = $customer->profile();

        return [
            'id'              => $customer->id(),
            'rev'             => $customer->rev(),
            'customer_number' => $p->customerNumber,
            'first_name'      => $p->firstName,
            'last_name'       => $p->lastName,
            'full_name'       => $p->fullName(),
            'phone'           => $p->phone?->value(),
            'alt_phone'       => $p->altPhone?->value(),
            'national_id'     => $p->nationalId?->value(),
            'gender'          => $p->gender,
            'date_of_birth'   => $p->dateOfBirth,
            'occupation'      => $p->occupation,
            'business_type'   => $p->businessType,
            'address'         => $p->address->toArray(),
            'branch_id'       => $p->branchId,
            'field_officer_id' => $p->fieldOfficerId,
            'savings_balance' => $p->savingsBalance,
            'photo_path'      => $p->photoPath,
            'guarantor'       => $p->guarantor?->toArray(),
            'documents'       => $p->documents->toArray(),
            'next_of_kin'     => $this->legacyNextOfKin($customer->nextOfKinRaw()),
            'status'          => $p->status->value,
            'notes'           => $p->notes,
            'registered_on'   => $p->registeredOn,
            'registered_by'   => $p->registeredBy,
            'data_issues'     => $customer->dataIssues(),
            'created_at'      => $customer->createdAt()->format('Y-m-d H:i:s'),
            'updated_at'      => $customer->updatedAt()->format('Y-m-d H:i:s'),
            'deleted_at'      => $customer->deletedAt()?->format('Y-m-d H:i:s'),
        ];
    }

    /** The legacy shape, read for display only. Anything unreadable shows as null. */
    private function legacyNextOfKin(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
