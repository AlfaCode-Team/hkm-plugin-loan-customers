<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\API\DTO;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\LoanCustomers\Domain\Address;
use Plugins\LoanCustomers\Domain\CustomerProfile;
use Plugins\LoanCustomers\Domain\CustomerStatus;
use Plugins\LoanCustomers\Domain\DocumentSet;
use Plugins\LoanCustomers\Domain\Guarantor;
use Plugins\LoanCustomers\Domain\NationalId;
use Plugins\LoanCustomers\Domain\UgandanPhone;

/**
 * A write request, parsed and validated into typed values.
 *
 * Parsing is where every field-level error is found, and all of them are
 * reported together as a {@see ValidationException}. Only keys PRESENT in the
 * request are touched: an update that omits `phone` leaves the phone alone, and
 * an update that sends `phone: null` clears it. `next_of_kin`, `id`, `rev`,
 * `device_id` and the timestamps are ignored whatever they contain — none of them
 * is a thing a person sets through this API.
 */
final readonly class CustomerInput
{
    /** input key => [profile property, max length, required] */
    private const array TEXT = [
        'first_name'      => ['firstName', 120],
        'last_name'       => ['lastName', 120],
        'customer_number' => ['customerNumber', 32],
        'gender'          => ['gender', 16],
        'occupation'      => ['occupation', 120],
        'business_type'   => ['businessType', 120],
        'photo_path'      => ['photoPath', 255],
        'notes'           => ['notes', 5000],
    ];

    /** input key => profile property */
    private const array DATES = [
        'date_of_birth' => 'dateOfBirth',
        'registered_on' => 'registeredOn',
    ];

    /** input key => profile property */
    private const array POSITIVE_INTS = [
        'branch_id'        => 'branchId',
        'field_officer_id' => 'fieldOfficerId',
        'registered_by'    => 'registeredBy',
    ];

    /**
     * @param array<string, mixed> $fields  CustomerProfile property => typed value, only those supplied
     * @param list<string>         $touched input keys that were supplied
     */
    private function __construct(
        private array $fields,
        private array $touched,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @throws ValidationException
     */
    public static function fromArray(array $data, bool $creating): self
    {
        $fields = [];
        $touched = [];
        $errors = [];

        foreach (self::TEXT as $key => [$property, $max]) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $touched[] = $key;
            $value = self::scalarText($data[$key]);

            if ($value !== null && mb_strlen($value) > $max) {
                $errors[$key] = sprintf('Must be %d characters or fewer.', $max);
                continue;
            }

            $fields[$property] = $value;
        }

        if (array_key_exists('first_name', $data) && !isset($fields['firstName'])) {
            $errors['first_name'] = 'A first name is required.';
        }

        if ($creating && !array_key_exists('first_name', $data)) {
            $errors['first_name'] = 'A first name is required.';
        }

        foreach (self::DATES as $key => $property) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $touched[] = $key;

            try {
                $fields[$property] = self::date($data[$key]);
            } catch (\DomainException $e) {
                $errors[$key] = $e->getMessage();
            }
        }

        foreach (self::POSITIVE_INTS as $key => $property) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $touched[] = $key;
            $value = $data[$key];

            if ($value === null || $value === '') {
                $fields[$property] = null;
                continue;
            }

            $int = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

            if ($int === false || $int < 1) {
                $errors[$key] = 'Must be a whole number greater than zero.';
                continue;
            }

            $fields[$property] = $int;
        }

        self::parseOrRecord($errors, 'phone', $data, $touched, $fields, 'phone',
            static fn(string $v): UgandanPhone => UgandanPhone::from($v));
        self::parseOrRecord($errors, 'alt_phone', $data, $touched, $fields, 'altPhone',
            static fn(string $v): UgandanPhone => UgandanPhone::from($v));
        self::parseOrRecord($errors, 'national_id', $data, $touched, $fields, 'nationalId',
            static fn(string $v): NationalId => NationalId::from($v));

        if (array_key_exists('address', $data)) {
            $touched[] = 'address';

            try {
                $fields['address'] = self::address($data['address']);
            } catch (\DomainException $e) {
                $errors['address'] = $e->getMessage();
            }
        }

        if (array_key_exists('guarantor', $data)) {
            $touched[] = 'guarantor';

            try {
                $fields['guarantor'] = self::guarantor($data['guarantor']);
            } catch (\DomainException $e) {
                $errors['guarantor'] = $e->getMessage();
            }
        }

        if (array_key_exists('documents', $data)) {
            $touched[] = 'documents';

            try {
                $fields['documents'] = $data['documents'] === null
                    ? new DocumentSet()
                    : (is_array($data['documents']) ? DocumentSet::fromArray($data['documents']) : throw new \DomainException('Documents must be a list.'));
            } catch (\DomainException $e) {
                $errors['documents'] = $e->getMessage();
            }
        }

        if (array_key_exists('status', $data)) {
            $touched[] = 'status';
            $status = is_string($data['status']) ? CustomerStatus::tryFrom(strtolower(trim($data['status']))) : null;

            if ($status === null) {
                $errors['status'] = 'Status must be active, inactive or blacklisted.';
            } else {
                $fields['status'] = $status;
            }
        }

        if (array_key_exists('savings_balance', $data)) {
            $touched[] = 'savings_balance';
            $raw = $data['savings_balance'];
            $int = is_int($raw) ? $raw : (is_string($raw) && ctype_digit(trim($raw)) ? (int) trim($raw) : null);

            if ($int === null || $int < 0) {
                $errors['savings_balance'] = 'Savings is whole Ugandan shillings, zero or more.';
            } else {
                $fields['savingsBalance'] = $int;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($fields, $touched);
    }

    /** The profile for a new customer. Validated by {@see fromArray()} with creating = true. */
    public function toProfile(): CustomerProfile
    {
        try {
            return new CustomerProfile(...$this->fields);
        } catch (\DomainException $e) {
            throw new ValidationException(['profile' => $e->getMessage()]);
        }
    }

    /** The profile an existing customer becomes: its current values, with these applied. */
    public function applyTo(CustomerProfile $current): CustomerProfile
    {
        try {
            return $current->with($this->fields);
        } catch (\DomainException $e) {
            throw new ValidationException(['profile' => $e->getMessage()]);
        }
    }

    /** @return list<string> the input keys this request set */
    public function touched(): array
    {
        return $this->touched;
    }

    private static function scalarText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function date(mixed $value): ?string
    {
        $text = self::scalarText($value);

        if ($text === null) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        if ($parsed === false || $parsed->format('Y-m-d') !== $text) {
            throw new \DomainException('Use the date format YYYY-MM-DD.');
        }

        return $text;
    }

    /** @param array<string, mixed> $data @throws \DomainException */
    private static function address(mixed $value): Address
    {
        if ($value === null) {
            return new Address();
        }

        if (!is_array($value)) {
            throw new \DomainException('Address must be an object with district, subcounty, parish and village.');
        }

        return Address::of(
            self::addressPart($value, 'district'),
            self::addressPart($value, 'subcounty'),
            self::addressPart($value, 'parish'),
            self::addressPart($value, 'village'),
        );
    }

    /** @param array<string, mixed> $value */
    private static function addressPart(array $value, string $key): ?string
    {
        $part = $value[$key] ?? null;

        return is_scalar($part) ? (string) $part : null;
    }

    private static function guarantor(mixed $value): ?Guarantor
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw new \DomainException('Guarantor must be an object.');
        }

        return Guarantor::fromArray($value, strict: true);
    }

    /**
     * Parse an optional value-object field, recording a field error rather than
     * throwing, so the other fields of the same request are still reported.
     *
     * @param array<string, string> $errors
     * @param array<string, mixed>                       $data
     * @param list<string>                               $touched
     * @param array<string, mixed>                       $fields
     * @param callable(string): object                   $make
     */
    private static function parseOrRecord(
        array &$errors,
        string $key,
        array $data,
        array &$touched,
        array &$fields,
        string $property,
        callable $make,
    ): void {
        if (!array_key_exists($key, $data)) {
            return;
        }

        $touched[] = $key;
        $text = self::scalarText($data[$key]);

        if ($text === null) {
            $fields[$property] = null;
            return;
        }

        try {
            $fields[$property] = $make($text);
        } catch (\DomainException $e) {
            $errors[$key] = $e->getMessage();
        }
    }
}
