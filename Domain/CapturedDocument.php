<?php

declare(strict_types=1);

namespace Plugins\LoanCustomers\Domain;

/**
 * One captured document: a photograph of an ID, an agreement, a form. The file
 * itself lives in the StoragePort; this records what it is and where it went.
 */
final readonly class CapturedDocument
{
    public function __construct(
        public string $kind,
        public string $path,
        public ?string $capturedAt = null,
    ) {
    }

    /** @param array<string, mixed> $data @throws \DomainException */
    public static function fromArray(array $data): self
    {
        $kind = trim((string) ($data['kind'] ?? ''));
        $path = trim((string) ($data['path'] ?? ''));

        if ($kind === '' || mb_strlen($kind) > 32) {
            throw new \DomainException('Each document needs a kind of 32 characters or fewer.');
        }

        // A stored path is a key into the StoragePort, never a filesystem path.
        // Refuse anything that could climb out of it.
        if ($path === '' || mb_strlen($path) > 255 || str_starts_with($path, '/')
            || str_contains($path, '\\') || in_array('..', explode('/', $path), true)) {
            throw new \DomainException('Each document needs a relative storage path.');
        }

        $capturedAt = isset($data['capturedAt']) && $data['capturedAt'] !== ''
            ? (string) $data['capturedAt']
            : null;

        return new self($kind, $path, $capturedAt);
    }

    /** @return array{kind: string, path: string, capturedAt: ?string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'path' => $this->path, 'capturedAt' => $this->capturedAt];
    }
}
