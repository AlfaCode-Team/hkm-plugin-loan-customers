<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\Domain;

/**
 * The person who stands behind a borrower. Stored as one JSON document in the
 * `guarantor` column: {name, phone, relationship, address}.
 *
 * {@see fromArray()} is strict and is used for input. {@see fromJson()} takes a
 * stored document and is strict too: a column that does not decode is reported
 * by the repository as unreadable and kept verbatim, never silently reshaped.
 */
final readonly class Guarantor
{
    public function __construct(
        public string $name,
        public ?UgandanPhone $phone = null,
        public ?string $relationship = null,
        public ?string $address = null,
    ) {
    }

    /** @param array<string, mixed> $data @throws \DomainException */
    public static function fromArray(array $data, bool $strict = true): self
    {
        $name = self::text($data['name'] ?? null, 'Guarantor name', 120);

        if ($name === null) {
            throw new \DomainException('A guarantor needs a name.');
        }

        $rawPhone = self::text($data['phone'] ?? null, 'Guarantor phone', 32);
        $phone    = null;

        if ($rawPhone !== null) {
            $phone = $strict ? UgandanPhone::from($rawPhone) : UgandanPhone::fromStored($rawPhone);
        }

        return new self(
            name:         $name,
            phone:        $phone,
            relationship: self::text($data['relationship'] ?? null, 'Relationship', 60),
            address:      self::text($data['address'] ?? null, 'Guarantor address', 255),
        );
    }

    /** @throws \DomainException */
    public static function fromJson(string $json, bool $strict = true): self
    {
        $data = self::decode($json);

        return self::fromArray($data, $strict);
    }

    /** @return array{name: string, phone: ?string, relationship: ?string, address: ?string} */
    public function toArray(): array
    {
        return [
            'name'         => $this->name,
            'phone'        => $this->phone?->value(),
            'relationship' => $this->relationship,
            'address'      => $this->address,
        ];
    }

    /** @return array<string, mixed> @throws \DomainException */
    public static function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \DomainException('Stored JSON is malformed: ' . $e->getMessage(), previous: $e);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new \DomainException('Stored JSON is not an object.');
        }

        return $data;
    }

    private static function text(mixed $value, string $label, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            throw new \DomainException(sprintf('%s must be text.', $label));
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw new \DomainException(sprintf('%s must be %d characters or fewer.', $label, $max));
        }

        return $value;
    }
}
