<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\Domain;

/**
 * A borrower, as the server holds it.
 *
 * Two things about this entity are load-bearing and easy to break:
 *
 *  1. PRESERVED JSON. A JSON column the profile could not read is kept as the
 *     raw text it was stored as, and written back unchanged on the next save.
 *     Otherwise an unrelated edit to a customer whose guarantor blob is damaged
 *     would replace that blob with null, and the damage would be ours. A column
 *     is only overwritten when a person explicitly changes it.
 *
 *  2. REVISIONS ARE NOT HERE. The rev is the sync cursor and is assigned by the
 *     service inside the transaction that writes the row. The entity holds
 *     whatever rev it was last stamped with, and nothing more.
 */
final class Customer
{
    /** Columns that are JSON and therefore can be preserved as raw text. */
    public const array JSON_COLUMNS = ['guarantor', 'documents', 'next_of_kin'];

    /**
     * @param array<string, string> $preserved raw text of JSON columns the profile could not read
     */
    private function __construct(
        private readonly int $id,
        private CustomerProfile $profile,
        private array $preserved,
        private ?string $deviceId,
        private int $rev,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
        private ?\DateTimeImmutable $deletedAt,
    ) {
    }

    public static function create(int $id, CustomerProfile $profile, \DateTimeImmutable $now): self
    {
        return new self($id, $profile, [], null, 0, $now, $now, null);
    }

    /** @param array<string, string> $preserved */
    public static function reconstitute(
        int $id,
        CustomerProfile $profile,
        array $preserved,
        ?string $deviceId,
        int $rev,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $deletedAt,
    ): self {
        return new self($id, $profile, $preserved, $deviceId, $rev, $createdAt, $updatedAt, $deletedAt);
    }

    /**
     * Replace the profile.
     *
     * @param list<string> $touched the column names a person set in this change;
     *                              a JSON column named here is no longer preserved
     *
     * @throws \DomainException when the customer has been deleted
     */
    public function revise(CustomerProfile $next, array $touched, \DateTimeImmutable $now): void
    {
        $this->guardLive();

        foreach ($touched as $column) {
            unset($this->preserved[$column]);
        }

        $this->profile   = $next;
        $this->updatedAt = $now;
    }

    /** @throws \DomainException when the customer is already deleted */
    public function tombstone(\DateTimeImmutable $now): void
    {
        $this->guardLive();

        $this->deletedAt = $now;
        $this->updatedAt = $now;
    }

    public function stampRev(int $rev): void
    {
        $this->rev = $rev;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function profile(): CustomerProfile
    {
        return $this->profile;
    }

    /** @return array<string, string> */
    public function preserved(): array
    {
        return $this->preserved;
    }

    public function deviceId(): ?string
    {
        return $this->deviceId;
    }

    public function rev(): int
    {
        return $this->rev;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * The columns whose stored text could not be read, so a list can say so
     * instead of showing an empty guarantor as though none were ever recorded.
     * `next_of_kin` is excluded: it is always kept verbatim, by design.
     *
     * @return list<string>
     */
    public function dataIssues(): array
    {
        return array_values(array_filter(
            array_keys($this->preserved),
            static fn(string $column): bool => $column !== 'next_of_kin',
        ));
    }

    /** The legacy next-of-kin text as it was stored, or null for a new record. */
    public function nextOfKinRaw(): ?string
    {
        return $this->preserved['next_of_kin'] ?? null;
    }

    private function guardLive(): void
    {
        if ($this->deletedAt !== null) {
            throw new \DomainException('A deleted customer cannot be changed.');
        }
    }
}
