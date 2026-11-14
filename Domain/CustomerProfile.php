<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\Domain;

/**
 * Everything about a borrower that a person may set. Immutable: a change is a
 * new profile, produced by {@see with()}, so the entity can compare and record
 * what actually moved.
 *
 * Only the fields a customer carries are here. The system fields — id, rev,
 * device, the three timestamps and the tombstone — belong to {@see Customer}.
 * `next_of_kin` is absent on purpose: it is a legacy shape that new records
 * never write, and the entity keeps any stored copy untouched.
 */
final readonly class CustomerProfile
{
    private const int NAME_MAX = 120;

    public function __construct(
        public string $firstName,
        public ?string $lastName = null,
        public ?string $customerNumber = null,
        public ?UgandanPhone $phone = null,
        public ?UgandanPhone $altPhone = null,
        public ?NationalId $nationalId = null,
        public ?string $gender = null,
        public ?string $dateOfBirth = null,
        public ?string $occupation = null,
        public ?string $businessType = null,
        public Address $address = new Address(),
        public ?int $branchId = null,
        public ?int $fieldOfficerId = null,
        public int $savingsBalance = 0,
        public ?string $photoPath = null,
        public ?Guarantor $guarantor = null,
        public DocumentSet $documents = new DocumentSet(),
        public CustomerStatus $status = CustomerStatus::Active,
        public ?string $notes = null,
        public ?string $registeredOn = null,
        public ?int $registeredBy = null,
    ) {
        if (trim($firstName) === '' || mb_strlen($firstName) > self::NAME_MAX) {
            throw new \DomainException('A customer needs a first name.');
        }

        // Integer UGX, never a float. A savings balance cannot be negative.
        if ($savingsBalance < 0) {
            throw new \DomainException('The savings balance cannot be negative.');
        }
    }

    /** Lists and receipts read this, so it is derived, never typed in. */
    public function fullName(): string
    {
        return trim($this->firstName . ' ' . ($this->lastName ?? ''));
    }

    /** @param array<string, mixed> $changes property name => new value */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
