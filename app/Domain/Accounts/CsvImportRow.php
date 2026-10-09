<?php

namespace App\Domain\Accounts;

/**
 * One parsed CSV row, always produced — valid or not — so the
 * preview-and-confirm step (ui.md §10) can show every row and the exact
 * reason any of them is rejected. The legacy importer silently swallowed
 * bad rows instead; this type exists to make that impossible: there is no
 * code path that drops a row on the floor.
 */
final class CsvImportRow
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly int $line,
        public readonly string $rawName,
        public readonly string $rawEmail,
        public readonly bool $admin,
        public readonly array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array{line: int, name: string, email: string, admin: bool, valid: bool, errors: list<string>}
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'name' => $this->rawName,
            'email' => $this->rawEmail,
            'admin' => $this->admin,
            'valid' => $this->isValid(),
            'errors' => $this->errors,
        ];
    }
}
