<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\LoanCustomers\API\DTO\CustomerSearch;
use Plugins\LoanCustomers\Domain\Address;
use Plugins\LoanCustomers\Domain\Customer;
use Plugins\LoanCustomers\Domain\CustomerProfile;
use Plugins\LoanCustomers\Domain\CustomerStatus;
use Plugins\LoanCustomers\Domain\DocumentSet;
use Plugins\LoanCustomers\Domain\Guarantor;
use Plugins\LoanCustomers\Domain\NationalId;
use Plugins\LoanCustomers\Domain\UgandanPhone;

/**
 * The `customers` table, and the only place that knows its columns.
 *
 * Reads never throw on a bad row. A JSON column that does not decode is kept
 * verbatim on the entity and surfaced as a data issue, so one corrupt blob
 * cannot take down the page of customers it sits among.
 *
 * Writes do not touch `device_id` or `created_at` once a row exists: those
 * describe who created it, and an edit at a counter does not change that.
 */
final class CustomerRepository
{
    /** Legacy shard 0 — ids below this are the ones a web-created row may take. */
    /**
     * THE SERVER'S OWN ID WINDOW.
     *
     * Ids are partitioned so that no two minters can collide without
     * coordinating: shard 0 is the LEGACY range (pre-sharding rows, and
     * everything the Firestore import brings over), shards 1..999_998 belong
     * to handsets, and shard 999_999 is the server. Web rows are minted here.
     *
     * They used to be minted in the LEGACY range as MAX(id)+1 — the same range
     * the import writes into. Create a loan on the web today, import a v1 loan
     * carrying that id next week, and one silently overwrites the other.
     *
     * Mirrors ShardClaim::SERVER_FLOOR / SERVER_CEILING in the Sync module.
     * Duplicated rather than imported, because a module may not reach into
     * another module's Domain.
     */
    private const int SERVER_FLOOR   = 999999000000;
    private const int SERVER_CEILING = 999999999999;

    /** Lowest id a web-created customer may take. */
    /** The first id the SERVER may mint — the floor of its own shard. */
    private const int FIRST_ID = self::SERVER_FLOOR;

    /**
     * Shown when a stored row has no usable first name. A rendering fallback
     * only — see the write path, which never persists it.
     */
    public const string NAME_PLACEHOLDER = 'Unnamed customer';

    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    public function find(int $id, bool $includeDeleted = false): ?Customer
    {
        try {
            $row = $this->db->queryOne(
                'SELECT * FROM customers WHERE id = :id' . ($includeDeleted ? '' : ' AND deleted_at IS NULL'),
                ['id' => $id],
            );
        } catch (\Throwable $e) {
            throw $this->failed('read', $e);
        }

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return array{items: list<Customer>, total: int}
     */
    public function search(CustomerSearch $search): array
    {
        [$where, $params] = $this->criteria($search);

        try {
            $count = $this->db->queryOne(
                'SELECT COUNT(*) AS n FROM customers WHERE ' . $where,
                $params,
            );

            $rows = $this->db->query(
                sprintf(
                    'SELECT * FROM customers WHERE %s ORDER BY full_name ASC, id ASC%s',
                    $where,
                    SqlDialect::of($this->db)->page($search->perPage, $search->offset()),
                ),
                $params,
            );
        } catch (\Throwable $e) {
            throw $this->failed('search', $e);
        }

        return [
            'items' => array_map(fn(array $row): Customer => $this->hydrate($row), $rows),
            'total' => (int) ($count['n'] ?? 0),
        ];
    }

    /**
     * The next id in the legacy shard 0 range: one above the highest id already
     * there, tombstones included, because an id is never reused.
     *
     * MUST be called inside the transaction that inserts the row, after the rev
     * is reserved, so two writers cannot read the same maximum.
     */
    public function nextId(): int
    {
        try {
            $row = $this->db->queryOne(
                'SELECT MAX(id) AS top FROM customers WHERE id >= :floor AND id <= :ceiling',
                ['floor' => self::SERVER_FLOOR, 'ceiling' => self::SERVER_CEILING],
            );
        } catch (\Throwable $e) {
            throw $this->failed('mint an id for', $e);
        }

        $top = $row === null || $row['top'] === null ? 0 : (int) $row['top'];
        $next = max(self::FIRST_ID - 1, $top) + 1;

        if ($next > self::SERVER_CEILING) {
            throw new RepositoryException(
                'The web id range is exhausted.',
                layer: 'repository.customers',
            );
        }

        return $next;
    }

    public function insert(Customer $customer): void
    {
        $row = $this->dehydrate($customer) + [
            'id'         => $customer->id(),
            'device_id'  => null,
            'created_at' => $this->format($customer->createdAt()),
        ];

        $columns = array_keys($row);

        try {
            $this->db->execute(
                sprintf(
                    'INSERT INTO customers (%s) VALUES (%s)',
                    implode(', ', $columns),
                    implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)),
                ),
                $row,
            );
        } catch (\Throwable $e) {
            throw $this->failed('insert', $e);
        }
    }

    public function save(Customer $customer): void
    {
        // device_id and created_at are not in the SET list on purpose.
        $row = $this->dehydrate($customer);

        $assignments = implode(', ', array_map(
            static fn(string $c): string => sprintf('%s = :%s', $c, $c),
            array_keys($row),
        ));

        try {
            $this->db->execute(
                sprintf('UPDATE customers SET %s WHERE id = :where_id', $assignments),
                $row + ['where_id' => $customer->id()],
            );
        } catch (\Throwable $e) {
            throw $this->failed('update', $e);
        }
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function criteria(CustomerSearch $search): array
    {
        $where  = ['deleted_at IS NULL'];
        $params = [];

        if ($search->branchId !== null) {
            $where[]          = 'branch_id = :branch_id';
            $params['branch_id'] = $search->branchId;
        }

        if ($search->fieldOfficerId !== null) {
            $where[]                  = 'field_officer_id = :officer_id';
            $params['officer_id']     = $search->fieldOfficerId;
        }

        if ($search->status !== null) {
            $where[]          = 'status = :status';
            $params['status'] = $search->status->value;
        }

        if ($search->term !== null) {
            $like = '%' . $this->escapeLike(mb_strtolower($search->term)) . '%';

            // Name and customer number match on the text as typed. The phone
            // columns match on digits only, and only from three digits up, so a
            // stray "7" does not return every customer in the book.
            $clauses = [
                "LOWER(full_name) LIKE :term_name ESCAPE '!'",
                "LOWER(COALESCE(customer_number, '')) LIKE :term_number ESCAPE '!'",
            ];
            $params['term_name']   = $like;
            $params['term_number'] = $like;

            $digits = UgandanPhone::searchDigits($search->term);

            if (strlen($digits) >= 3) {
                $phone = '%' . $this->escapeLike($digits) . '%';

                $clauses[]            = "COALESCE(phone, '') LIKE :phone_main ESCAPE '!'";
                $clauses[]            = "COALESCE(alt_phone, '') LIKE :phone_alt ESCAPE '!'";
                $params['phone_main'] = $phone;
                $params['phone_alt']  = $phone;
            }

            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        return [implode(' AND ', $where), $params];
    }

    /** Escape LIKE wildcards with '!' so user input is matched literally. */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Customer
    {
        $preserved = [];

        $phone    = $this->nullableText($row['phone'] ?? null);
        $altPhone = $this->nullableText($row['alt_phone'] ?? null);
        $national = $this->nullableText($row['national_id'] ?? null);

        $guarantor = $this->readJson($row, 'guarantor', $preserved,
            static fn(string $raw): Guarantor => Guarantor::fromJson($raw));

        $documents = $this->readJson($row, 'documents', $preserved,
            static fn(string $raw): DocumentSet => DocumentSet::fromJson($raw));

        $nextOfKin = $this->nullableText($row['next_of_kin'] ?? null);

        if ($nextOfKin !== null) {
            // Never editable through this module, so always kept as stored.
            $preserved['next_of_kin'] = $nextOfKin;
        }

        // `first_name` is NOT NULL, but a handset can have written an empty
        // one, and CustomerProfile refuses to be built without a name. The
        // placeholder below exists so a LIST still renders — but it must never
        // reach the database: an unrelated edit to the phone number would
        // otherwise rename a real person to "Unnamed customer" and there would
        // be nothing left to say what they were called.
        if ($this->nullableText($row['first_name'] ?? null) === null) {
            $preserved['first_name'] = (string) ($row['first_name'] ?? '');
        }

        $profile = new CustomerProfile(
            // first_name is NOT NULL, but a handset may have written an empty one.
            // The list must still render; a save will write the name the user sends.
            firstName:       $this->nullableText($row['first_name'] ?? null) ?? self::NAME_PLACEHOLDER,
            lastName:        $this->nullableText($row['last_name'] ?? null),
            customerNumber:  $this->nullableText($row['customer_number'] ?? null),
            phone:           $phone === null ? null : UgandanPhone::fromStored($phone),
            altPhone:        $altPhone === null ? null : UgandanPhone::fromStored($altPhone),
            nationalId:      $national === null ? null : NationalId::fromStored($national),
            gender:          $this->nullableText($row['gender'] ?? null),
            dateOfBirth:     $this->nullableText($row['date_of_birth'] ?? null),
            occupation:      $this->nullableText($row['occupation'] ?? null),
            businessType:    $this->nullableText($row['business_type'] ?? null),
            address:         new Address(
                $this->nullableText($row['district'] ?? null),
                $this->nullableText($row['subcounty'] ?? null),
                $this->nullableText($row['parish'] ?? null),
                $this->nullableText($row['village'] ?? null),
            ),
            branchId:        $this->nullableInt($row['branch_id'] ?? null),
            fieldOfficerId:  $this->nullableInt($row['field_officer_id'] ?? null),
            savingsBalance:  max(0, (int) ($row['savings_balance'] ?? 0)),
            photoPath:       $this->nullableText($row['photo_path'] ?? null),
            guarantor:       $guarantor,
            documents:       $documents ?? new DocumentSet(),
            status:          CustomerStatus::tryFrom((string) ($row['status'] ?? '')) ?? CustomerStatus::Active,
            notes:           $this->nullableText($row['notes'] ?? null),
            registeredOn:    $this->nullableText($row['registered_on'] ?? null),
            registeredBy:    $this->nullableInt($row['registered_by'] ?? null),
        );

        $utc = new \DateTimeZone('UTC');

        return Customer::reconstitute(
            id:        (int) $row['id'],
            profile:   $profile,
            preserved: $preserved,
            deviceId:  $this->nullableText($row['device_id'] ?? null),
            rev:       (int) ($row['rev'] ?? 0),
            createdAt: new \DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at'], $utc),
            deletedAt: $this->nullableText($row['deleted_at'] ?? null) === null
                ? null
                : new \DateTimeImmutable((string) $row['deleted_at'], $utc),
        );
    }

    /**
     * Decode a JSON column. A value that will not decode is recorded in
     * `$preserved` as raw text and the profile sees null, so the list shows the
     * gap and a later save writes the original text back.
     *
     * @template T
     * @param array<string, mixed>   $row
     * @param array<string, string>  $preserved
     * @param callable(string): T    $read
     * @return T|null
     */
    private function readJson(array $row, string $column, array &$preserved, callable $read): mixed
    {
        $raw = $this->nullableText($row[$column] ?? null);

        if ($raw === null) {
            return null;
        }

        try {
            return $read($raw);
        } catch (\Throwable) {
            $preserved[$column] = $raw;

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dehydrate(Customer $customer): array
    {
        $p         = $customer->profile();
        $preserved = $customer->preserved();

        $guarantor = $preserved['guarantor'] ?? $this->encodeOrNull(
            $p->guarantor === null ? null : $p->guarantor->toArray(),
        );

        $documents = $preserved['documents'] ?? $this->encodeOrNull(
            $p->documents->items === [] ? null : $p->documents->toArray(),
        );

        return [
            'customer_number'  => $p->customerNumber,
            // The placeholder is a rendering convenience, never a stored name.
            // If the caller supplied a real one it wins; otherwise whatever was
            // stored goes back unchanged.
            'first_name'       => $p->firstName === self::NAME_PLACEHOLDER
                ? ($preserved['first_name'] ?? $p->firstName)
                : $p->firstName,
            'last_name'        => $p->lastName,
            'full_name'        => $p->firstName === self::NAME_PLACEHOLDER
                ? trim((string) ($preserved['first_name'] ?? '') . ' ' . ($p->lastName ?? ''))
                : $p->fullName(),
            'phone'            => $p->phone?->value(),
            'alt_phone'        => $p->altPhone?->value(),
            'national_id'      => $p->nationalId?->value(),
            'gender'           => $p->gender,
            'date_of_birth'    => $p->dateOfBirth,
            'occupation'       => $p->occupation,
            'business_type'    => $p->businessType,
            'district'         => $p->address->district,
            'subcounty'        => $p->address->subcounty,
            'parish'           => $p->address->parish,
            'village'          => $p->address->village,
            'location'         => $p->address->summary(),
            'branch_id'        => $p->branchId,
            'field_officer_id' => $p->fieldOfficerId,
            'savings_balance'  => $p->savingsBalance,
            'photo_path'       => $p->photoPath,
            'guarantor'        => $guarantor,
            'next_of_kin'      => $preserved['next_of_kin'] ?? null,
            'documents'        => $documents,
            'status'           => $p->status->value,
            'notes'            => $p->notes,
            'registered_on'    => $p->registeredOn,
            'registered_by'    => $p->registeredBy,
            'rev'              => $customer->rev(),
            'updated_at'       => $this->format($customer->updatedAt()),
            'deleted_at'       => $customer->deletedAt() === null ? null : $this->format($customer->deletedAt()),
        ];
    }

    /** @param array<string, mixed>|null $value */
    private function encodeOrNull(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function format(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function failed(string $verb, \Throwable $e): RepositoryException
    {
        return new RepositoryException(
            sprintf('Failed to %s a customer.', $verb),
            layer: 'repository.customers',
            previous: $e,
        );
    }
}
