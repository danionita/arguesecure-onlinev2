<?php

namespace App\Domain\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Parses a pasted or uploaded CSV of accounts to bulk-create, and commits
 * the rows the admin confirmed. Two-step by design (parse → preview →
 * commit) per ui.md §10: "a preview-and-confirm step listing every row
 * that will be created and every row that will be rejected with the
 * reason." Never writes the uploaded file to disk — the controller passes
 * this class the raw string content only (security.md §7).
 */
class CsvUserImporter
{
    /** Header aliases accepted for each logical column, matched case-insensitively. */
    private const NAME_HEADERS = ['name', 'full name', 'fullname'];

    private const EMAIL_HEADERS = ['email', 'e-mail', 'email address'];

    private const ADMIN_HEADERS = ['admin', 'is_admin', 'is admin'];

    /**
     * @return list<CsvImportRow>
     */
    public function parse(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $lines = array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ''));

        if ($lines === []) {
            return [];
        }

        $header = str_getcsv(array_shift($lines));
        $normalizedHeader = array_map(
            fn (string $column): string => strtolower(trim($column)),
            $header,
        );

        $nameIndex = $this->findColumn($normalizedHeader, self::NAME_HEADERS);
        $emailIndex = $this->findColumn($normalizedHeader, self::EMAIL_HEADERS);
        $adminIndex = $this->findColumn($normalizedHeader, self::ADMIN_HEADERS);

        if ($nameIndex === null || $emailIndex === null) {
            // No usable header at all — every row is rejected with the
            // same reason rather than guessing column positions.
            return array_map(
                fn (string $line, int $i): CsvImportRow => new CsvImportRow(
                    line: $i + 2,
                    rawName: '',
                    rawEmail: '',
                    admin: false,
                    errors: ['CSV must have a header row containing "name" and "email" columns.'],
                ),
                $lines,
                array_keys($lines),
            );
        }

        $expectedColumns = count($header);
        $seenEmails = [];
        $existingEmails = null;
        $rows = [];

        foreach ($lines as $i => $line) {
            $lineNumber = $i + 2; // +1 for header, +1 for 1-indexing
            $fields = str_getcsv($line);

            $errors = [];

            if (count($fields) !== $expectedColumns) {
                $rows[] = new CsvImportRow(
                    line: $lineNumber,
                    rawName: '',
                    rawEmail: '',
                    admin: false,
                    errors: ["Row has {$this->pluralize(count($fields), 'field')}, expected {$expectedColumns}."],
                );

                continue;
            }

            $name = trim($fields[$nameIndex] ?? '');
            $email = trim($fields[$emailIndex] ?? '');
            $admin = $adminIndex !== null ? $this->parseBool($fields[$adminIndex] ?? '') : false;

            if ($name === '') {
                $errors[] = 'Name is required.';
            }

            if ($email === '') {
                $errors[] = 'Email is required.';
            } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Email is not a valid address.';
            } else {
                $normalizedEmail = Str::lower($email);

                if (isset($seenEmails[$normalizedEmail])) {
                    $errors[] = "Duplicate of row {$seenEmails[$normalizedEmail]} in this file.";
                } else {
                    $seenEmails[$normalizedEmail] = $lineNumber;

                    $existingEmails ??= User::query()->pluck('email')
                        ->map(fn (string $e): string => Str::lower($e))
                        ->flip()
                        ->all();

                    if (isset($existingEmails[$normalizedEmail])) {
                        $errors[] = 'An account with this email already exists.';
                    }
                }
            }

            $rows[] = new CsvImportRow(
                line: $lineNumber,
                rawName: $name,
                rawEmail: $email,
                admin: $admin,
                errors: $errors,
            );
        }

        return $rows;
    }

    /**
     * Create one user per valid row. Returns each created user paired with
     * its generated plaintext password (for the credential sheet), which
     * is never stored anywhere beyond this return value.
     *
     * @param  list<CsvImportRow>  $rows
     * @return list<array{user: User, password: string}>
     */
    public function commit(array $rows): array
    {
        $validRows = array_values(array_filter($rows, fn (CsvImportRow $row): bool => $row->isValid()));

        return DB::transaction(function () use ($validRows): array {
            $created = [];

            foreach ($validRows as $row) {
                $password = TemporaryPassword::generate();

                $user = new User;
                $user->name = $row->rawName;
                $user->email = $row->rawEmail;
                $user->password = Hash::make($password);
                $user->is_admin = $row->admin;
                $user->is_active = true;
                $user->save();

                $created[] = ['user' => $user, 'password' => $password];
            }

            return $created;
        });
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $aliases
     */
    private function findColumn(array $header, array $aliases): ?int
    {
        foreach ($aliases as $alias) {
            $index = array_search($alias, $header, true);

            if ($index !== false) {
                return $index;
            }
        }

        return null;
    }

    private function parseBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'admin'], true);
    }

    private function pluralize(int $count, string $noun): string
    {
        return "{$count} {$noun}".($count === 1 ? '' : 's');
    }
}
