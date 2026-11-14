<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\Domain;

/**
 * The four levels of Uganda's administrative tree, stored as names.
 *
 *   district → subcounty → parish → village
 *
 * A level may only be filled when the level above it is, because a village
 * with no parish cannot be placed on the map a field officer is working from.
 * County is not part of an address and is deliberately absent.
 *
 * The constructor performs no validation: it is what a stored row is read into.
 * Use {@see of()} for anything a person typed.
 */
final readonly class Address
{
    private const int MAX_LENGTH = 120;

    public function __construct(
        public ?string $district = null,
        public ?string $subcounty = null,
        public ?string $parish = null,
        public ?string $village = null,
    ) {
    }

    /** @throws \DomainException when a level is filled under an empty level above it */
    public static function of(?string $district, ?string $subcounty, ?string $parish, ?string $village): self
    {
        $address = new self(
            self::clean($district, 'District'),
            self::clean($subcounty, 'Subcounty'),
            self::clean($parish, 'Parish'),
            self::clean($village, 'Village'),
        );

        if ($address->subcounty !== null && $address->district === null) {
            throw new \DomainException('Choose the district before the subcounty.');
        }

        if ($address->parish !== null && $address->subcounty === null) {
            throw new \DomainException('Choose the subcounty before the parish.');
        }

        if ($address->village !== null && $address->parish === null) {
            throw new \DomainException('Choose the parish before the village.');
        }

        return $address;
    }

    public function isEmpty(): bool
    {
        return $this->district === null && $this->subcounty === null
            && $this->parish === null && $this->village === null;
    }

    /**
     * The one-line form the `location` column and the list rows carry:
     * the most specific level first, e.g. "Kyanamukaaka, Kyanamukaaka, Masaka, Masaka".
     */
    public function summary(): ?string
    {
        $parts = array_values(array_filter(
            [$this->village, $this->parish, $this->subcounty, $this->district],
            static fn(?string $part): bool => $part !== null,
        ));

        return $parts === [] ? null : implode(', ', $parts);
    }

    /** @return array{district: ?string, subcounty: ?string, parish: ?string, village: ?string, summary: ?string} */
    public function toArray(): array
    {
        return [
            'district'  => $this->district,
            'subcounty' => $this->subcounty,
            'parish'    => $this->parish,
            'village'   => $this->village,
            'summary'   => $this->summary(),
        ];
    }

    private static function clean(?string $value, string $label): ?string
    {
        $value = $value === null ? '' : trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new \DomainException(sprintf('%s must be %d characters or fewer.', $label, self::MAX_LENGTH));
        }

        return $value;
    }
}
