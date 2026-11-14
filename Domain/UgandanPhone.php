<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\Domain;

/**
 * A Ugandan mobile number.
 *
 * Held in one canonical shape — `07XXXXXXXX`, ten digits — so that a search,
 * a duplicate check and a printed receipt all read the same number the same way.
 * Input may arrive as `+256 772 123 456`, `256772123456`, `772123456` or with
 * dashes and brackets; {@see from()} folds all of them to the canonical form.
 *
 * Mobile only. Landlines are not accepted by {@see from()}.
 *
 * Rows that are already in the database were written by handsets with no
 * validation, so reading one must never fail: {@see fromStored()} keeps a value
 * it cannot normalise, marked as not canonical, instead of throwing.
 */
final readonly class UgandanPhone
{
    private const string CANONICAL = '/^07\d{8}$/';

    private function __construct(
        private string $value,
        private bool $canonical,
    ) {
    }

    /** @throws \DomainException when the input is not a Ugandan mobile number */
    public static function from(string $input): self
    {
        $stripped = preg_replace('/[\s\-().]+/', '', trim($input)) ?? '';

        if (str_starts_with($stripped, '+')) {
            $stripped = substr($stripped, 1);
        }

        if ($stripped === '' || !ctype_digit($stripped)) {
            throw new \DomainException('Enter a Ugandan mobile number, for example 0772 123 456.');
        }

        if (str_starts_with($stripped, '256')) {
            $stripped = '0' . substr($stripped, 3);
        } elseif (strlen($stripped) === 9 && $stripped[0] === '7') {
            $stripped = '0' . $stripped;
        }

        if (preg_match(self::CANONICAL, $stripped) !== 1) {
            throw new \DomainException('Enter a Ugandan mobile number, for example 0772 123 456.');
        }

        return new self($stripped, true);
    }

    /**
     * Read a stored value without ever failing. A value that cannot be
     * normalised is kept verbatim and reported as not canonical.
     */
    public static function fromStored(string $stored): self
    {
        try {
            return self::from($stored);
        } catch (\DomainException) {
            return new self(trim($stored), false);
        }
    }

    /**
     * The digits a partial search should match against. Strips everything that
     * is not a digit and folds a leading country code 256 to the trunk 0, so
     * `+256 772`, `256772` and `0772` all search for the same thing.
     */
    public static function searchDigits(string $query): string
    {
        $digits = preg_replace('/\D+/', '', $query) ?? '';

        if (str_starts_with($digits, '256')) {
            $digits = '0' . substr($digits, 3);
        }

        return $digits;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isCanonical(): bool
    {
        return $this->canonical;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
