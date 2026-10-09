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
