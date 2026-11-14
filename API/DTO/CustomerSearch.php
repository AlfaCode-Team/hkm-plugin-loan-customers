<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\API\DTO;

use Plugins\LoanCustomers\Domain\CustomerStatus;

/**
 * A listing request. Every filter is optional and they combine with AND.
 *
 * `term` matches, case-insensitively, against the full name, the customer
 * number and the phone numbers. A term with at least three digits in it is also
 * matched as a PARTIAL phone number, so a walk-in who gives "0772" or "772 12"
 * finds the row without the full number being typed.
 */
final readonly class CustomerSearch
{
    public const int DEFAULT_PER_PAGE = 25;
    public const int MAX_PER_PAGE     = 100;
    public const int MAX_TERM         = 120;

    public function __construct(
        public ?string $term = null,
        public ?int $branchId = null,
        public ?int $fieldOfficerId = null,
        public ?CustomerStatus $status = null,
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {
    }

    /**
     * Read the query string. Malformed values are dropped rather than rejected:
     * a list page should show something, and a bad filter is not a reason to
     * return an error to a field officer standing at a counter.
     *
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $term = isset($query['q']) && is_scalar($query['q']) ? trim((string) $query['q']) : '';

        return new self(
            term:           $term === '' ? null : mb_substr($term, 0, self::MAX_TERM),
            branchId:       self::positiveInt($query['branch_id'] ?? null),
            fieldOfficerId: self::positiveInt($query['field_officer_id'] ?? null),
            status:         isset($query['status']) && is_string($query['status'])
                                ? CustomerStatus::tryFrom(strtolower(trim($query['status'])))
                                : null,
            page:           max(1, self::positiveInt($query['page'] ?? null) ?? 1),
            perPage:        min(self::MAX_PER_PAGE, max(1, self::positiveInt($query['per_page'] ?? null) ?? self::DEFAULT_PER_PAGE)),
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);

        return $int === false || $int < 1 ? null : $int;
    }
}
