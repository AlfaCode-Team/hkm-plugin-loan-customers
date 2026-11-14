<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\API\Contracts;

use Plugins\LoanCustomers\API\DTO\CustomerInput;
use Plugins\LoanCustomers\API\DTO\CustomerSearch;

/**
 * The published face of the Customers module: what other modules and the HTTP
 * controller may ask of a borrower.
 *
 * Every write takes a new sync rev in the same transaction as the row it stamps.
 * A delete is a tombstone: the row stays, `deleted_at` is set, and it is pulled
 * by handsets like any other change.
 *
 * Reads return JSON-ready arrays. Tombstoned rows are never returned by any
 * method on this contract.
 */
interface CustomerServiceContract
{
    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(CustomerSearch $search): array;

    /**
     * @return array<string, mixed>
     * @throws \Plugins\LoanCustomers\API\Exceptions\CustomerNotFoundException
     */
    public function find(int $id): array;

    /** @return array<string, mixed> */
    public function create(CustomerInput $input): array;

    /**
     * @return array<string, mixed>
     * @throws \Plugins\LoanCustomers\API\Exceptions\CustomerNotFoundException
     */
    public function update(int $id, CustomerInput $input): array;

    /**
     * Tombstone the customer. Deleting one that is already deleted succeeds and
     * takes no new rev.
     *
     * @return array<string, mixed>
     * @throws \Plugins\LoanCustomers\API\Exceptions\CustomerNotFoundException
     */
    public function delete(int $id): array;
}
