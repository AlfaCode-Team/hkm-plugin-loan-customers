<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\Domain;

/**
 * A Ugandan National Identification Number, held upper-case with no spaces.
 *
 * The shape enforced here is 14 alphanumeric characters beginning with two
 * letters. That is the shape the NIRA card carries, and it is all this module
 * checks — it does not verify the number against the national register.
 *
 * As with {@see UgandanPhone}, a stored value is read with {@see fromStored()},
 * which never throws, so a legacy row with a free-text ID still lists.
 */
final readonly class NationalId
{
    private const string PATTERN = '/^[A-Z]{2}[A-Z0-9]{12}$/';

    private function __construct(
        private string $value,
    ) {
    }

    /** @throws \DomainException */
    public static function from(string $input): self
    {
        $normal = strtoupper((string) preg_replace('/\s+/', '', $input));

        if (preg_match(self::PATTERN, $normal) !== 1) {
            throw new \DomainException('A national ID is 14 characters: two letters followed by twelve letters or digits.');
        }

        return new self($normal);
    }

    public static function fromStored(string $stored): self
    {
        return new self(trim($stored));
    }

    public function value(): string
    {
        return $this->value;
    }
}
