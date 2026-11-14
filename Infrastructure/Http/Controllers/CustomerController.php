<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\LoanCustomers\API\Contracts\CustomerServiceContract;
use Plugins\LoanCustomers\API\DTO\CustomerInput;
use Plugins\LoanCustomers\API\DTO\CustomerSearch;

/**
 * Translation only: parse the request, call the service, render the result.
 */
final class CustomerController
{
    public function __construct(
        private readonly CustomerServiceContract $customers,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::json($this->customers->list(CustomerSearch::fromQuery($request->queryAll())));
    }

    public function show(Request $request, string $id): Response
    {
        return Response::json($this->customers->find((int) $id));
    }

    public function store(Request $request): Response
    {
        return Response::json($this->customers->create(CustomerInput::fromArray($request->all(), creating: true)), 201);
    }

    public function update(Request $request, string $id): Response
    {
        return Response::json($this->customers->update((int) $id, CustomerInput::fromArray($request->all(), creating: false)));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->customers->delete((int) $id);

        return Response::empty(204);
    }
}
