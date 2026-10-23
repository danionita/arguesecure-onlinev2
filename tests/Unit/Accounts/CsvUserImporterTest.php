<?php

use App\Domain\Accounts\CsvImportRow;
use App\Domain\Accounts\CsvUserImporter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Uniqueness-against-existing-users checks need the real users table, so
// this otherwise-pure-domain test still needs the database — tests/Pest.php
// only binds TestCase/RefreshDatabase to the Feature directory by default.
uses(TestCase::class, RefreshDatabase::class);

test('parses a well-formed CSV into valid rows', function () {
    $importer = new CsvUserImporter;

    $rows = $importer->parse("name,email\nAda Lovelace,ada@example.com\nAlan Turing,alan@example.com");

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toBeInstanceOf(CsvImportRow::class);
    expect($rows[0]->isValid())->toBeTrue();
    expect($rows[0]->rawEmail)->toBe('ada@example.com');
    expect($rows[1]->admin)->toBeFalse();
});

test('accepts header aliases for name, email and admin', function () {
    $importer = new CsvUserImporter;

    $rows = $importer->parse("Full Name,E-Mail,Is Admin\nAda Lovelace,ada@example.com,true");

    expect($rows[0]->isValid())->toBeTrue();
    expect($rows[0]->admin)->toBeTrue();
});

test('rejects every row with a clear reason when the header is unrecognisable', function () {
    $importer = new CsvUserImporter;

    $rows = $importer->parse("foo,bar\nAda,ada@example.com");

    expect($rows)->toHaveCount(1);
    expect($rows[0]->isValid())->toBeFalse();
    expect($rows[0]->errors[0])->toContain('header row');
});

test('rejects a ragged row instead of silently coercing it', function () {
    $importer = new CsvUserImporter;

    $rows = $importer->parse("name,email,admin\nAda Lovelace,ada@example.com");

    expect($rows[0]->isValid())->toBeFalse();
    expect($rows[0]->errors[0])->toContain('field');
});

test('rejects a missing name and a malformed email with distinct reasons', function () {
    $importer = new CsvUserImporter;

    $rows = $importer->parse("name,email\n,not-an-email");

    expect($rows[0]->isValid())->toBeFalse();
    expect($rows[0]->errors)->toContain('Name is required.');
    expect($rows[0]->errors)->toContain('Email is not a valid address.');
});

test('rejects a duplicate email within the same file, keeping the first occurrence valid', function () {
    $importer = new CsvUserImporter;

    $rows = $importer->parse("name,email\nFirst,dup@example.com\nSecond,dup@example.com");

    expect($rows[0]->isValid())->toBeTrue();
    expect($rows[1]->isValid())->toBeFalse();
    expect($rows[1]->errors[0])->toContain('Duplicate');
});

test('rejects an email that already belongs to an existing account', function () {
    $existing = User::factory()->create();
    $importer = new CsvUserImporter;

    $rows = $importer->parse("name,email\nSomeone,{$existing->email}");

    expect($rows[0]->isValid())->toBeFalse();
    expect($rows[0]->errors[0])->toContain('already exists');
});

test('commit creates a user per valid row with a unique generated password each', function () {
    $importer = new CsvUserImporter;
    $rows = $importer->parse("name,email\nAda Lovelace,ada@example.com\nAlan Turing,alan@example.com");

    $created = $importer->commit($rows);

    expect($created)->toHaveCount(2);
    expect($created[0]['password'])->not->toBe($created[1]['password']);
    expect(User::query()->count())->toBe(2);
});

test('commit ignores invalid rows even if passed in', function () {
    $importer = new CsvUserImporter;
    $rows = $importer->parse("name,email\n,not-an-email\nAda Lovelace,ada@example.com");

    $created = $importer->commit($rows);

    expect($created)->toHaveCount(1);
    expect($created[0]['user']->email)->toBe('ada@example.com');
});

// W123 (D90): the three ways a spreadsheet saves a CSV. Each case imports
// the same two people, so "same as the plain file" is the assertion.
dataset('spreadsheet CSV encodings', [
    'plain comma' => fn () => "name,email\nÉsther Müller,esther@example.com\nBen Bakker,ben@example.com",
    'UTF-8 with a byte-order mark (Excel "CSV UTF-8")' => fn () => "\xEF\xBB\xBFname,email\nÉsther Müller,esther@example.com\nBen Bakker,ben@example.com",
    'semicolons (Excel in a Dutch or German locale)' => fn () => "name;email\r\nÉsther Müller;esther@example.com\r\nBen Bakker;ben@example.com\r\n",
    'tabs' => fn () => "name\temail\nÉsther Müller\testher@example.com\nBen Bakker\tben@example.com",
    'Windows-1252 (Excel "CSV")' => fn () => mb_convert_encoding("name;email\r\nÉsther Müller;esther@example.com\r\nBen Bakker;ben@example.com", 'Windows-1252', 'UTF-8'),
    'UTF-16 little-endian (Excel "Unicode Text")' => fn () => "\xFF\xFE".mb_convert_encoding("name\temail\r\nÉsther Müller\testher@example.com\r\nBen Bakker\tben@example.com", 'UTF-16LE', 'UTF-8'),
]);

test('imports the same people however the spreadsheet saved the file', function (string $csv) {
    $rows = (new CsvUserImporter)->parse($csv);

    expect($rows)->toHaveCount(2);
    expect(array_map(fn (CsvImportRow $r): bool => $r->isValid(), $rows))->toBe([true, true]);
    expect($rows[0]->rawName)->toBe('Ésther Müller');
    expect($rows[0]->rawEmail)->toBe('esther@example.com');
    expect($rows[1]->rawEmail)->toBe('ben@example.com');
})->with('spreadsheet CSV encodings');

test('normalise returns valid UTF-8 and is idempotent', function (string $csv) {
    $once = CsvUserImporter::normalise($csv);

    expect(mb_check_encoding($once, 'UTF-8'))->toBeTrue();
    expect(str_starts_with($once, "\xEF\xBB\xBF"))->toBeFalse();
    expect(CsvUserImporter::normalise($once))->toBe($once);
})->with('spreadsheet CSV encodings');

test('the delimiter comes from the header line, not from commas inside a data field', function () {
    $rows = (new CsvUserImporter)->parse("name;email\n\"Bakker, Ben\";ben@example.com");

    expect($rows[0]->isValid())->toBeTrue();
    expect($rows[0]->rawName)->toBe('Bakker, Ben');
});

test('an unreadable header says which delimiter it tried', function () {
    $rows = (new CsvUserImporter)->parse("foo;bar\nAda;ada@example.com");

    expect($rows[0]->errors[0])->toContain('header row')->toContain('semicolons');
    expect((new CsvUserImporter)->parse("foo,bar\nAda,ada@example.com")[0]->errors[0])->toContain('commas');
});

test('a header with no delimiter at all still reports the header error, read as commas', function () {
    $rows = (new CsvUserImporter)->parse("name\nAda");

    expect($rows)->toHaveCount(1);
    expect($rows[0]->errors[0])->toContain('header row')->toContain('commas');
});

test('a tie between delimiters in the header is read as commas', function () {
    $rows = (new CsvUserImporter)->parse("name,email;admin\nAda,ada@example.com;true");

    expect($rows[0]->errors[0])->toContain('header row')->toContain('commas');
});

test('the admin column is read under a semicolon delimiter too', function () {
    $rows = (new CsvUserImporter)->parse("name;email;admin\nAda;ada@example.com;yes\nAlan;alan@example.com;no");

    expect($rows[0]->admin)->toBeTrue();
    expect($rows[1]->admin)->toBeFalse();
});
