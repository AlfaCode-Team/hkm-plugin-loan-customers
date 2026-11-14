<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\Domain;

/**
 * The documents captured for one borrower, stored as a JSON list in the
 * `documents` column. A list that does not decode, or any item that is not a
 * valid document, makes the whole column unreadable rather than yielding a
 * partial list that a later save would write back shorter.
 */
final readonly class DocumentSet
{
    public const int MAX = 50;

    /** @param list<CapturedDocument> $items */
    public function __construct(
        public array $items = [],
    ) {
        if (count($items) > self::MAX) {
            throw new \DomainException(sprintf('A customer can carry at most %d documents.', self::MAX));
        }

        if (!array_is_list($items)) {
            throw new \DomainException('Documents must be a list.');
        }
    }

    /** @param list<mixed> $rows @throws \DomainException */
    public static function fromArray(array $rows): self
    {
        if (!array_is_list($rows)) {
            throw new \DomainException('Documents must be a list.');
        }

        $items = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \DomainException('Each document must be an object.');
            }

            $items[] = CapturedDocument::fromArray($row);
        }

        return new self($items);
    }

    /** @throws \DomainException */
    public static function fromJson(string $json): self
    {
        try {
            $rows = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \DomainException('Stored JSON is malformed: ' . $e->getMessage(), previous: $e);
        }

        if (!is_array($rows)) {
            throw new \DomainException('Stored documents are not a list.');
        }

        return self::fromArray($rows);
    }

    /** @return list<array{kind: string, path: string, capturedAt: ?string}> */
    public function toArray(): array
    {
        return array_map(static fn(CapturedDocument $d): array => $d->toArray(), $this->items);
    }
}
