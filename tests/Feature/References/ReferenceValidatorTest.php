<?php

use App\Domain\References\ReferenceValidator;

/*
 * Pure validate/normalize/derive logic (schema.md §3.12, security.md §7).
 * No HTTP, no database — config('arguesecure.references.kinds') is the
 * only external input, and it is committed config, not request data.
 */

test('kinds() lists every configured kind', function () {
    expect(ReferenceValidator::kinds())
        ->toBe(['cve', 'cwe', 'capec', 'attack_technique', 'control', 'url', 'other']);
});

test('isValidIdentifier validates each derived kind against its own pattern', function () {
    expect(ReferenceValidator::isValidIdentifier('cve', 'CVE-2024-3094'))->toBeTrue();
    expect(ReferenceValidator::isValidIdentifier('cve', 'CVE-24-3094'))->toBeFalse();
    expect(ReferenceValidator::isValidIdentifier('cve', 'not-a-cve'))->toBeFalse();

    expect(ReferenceValidator::isValidIdentifier('cwe', 'CWE-79'))->toBeTrue();
    expect(ReferenceValidator::isValidIdentifier('cwe', 'CWE-ABC'))->toBeFalse();

    expect(ReferenceValidator::isValidIdentifier('capec', 'CAPEC-163'))->toBeTrue();
    expect(ReferenceValidator::isValidIdentifier('capec', 'CAPEC-'))->toBeFalse();

    expect(ReferenceValidator::isValidIdentifier('attack_technique', 'T1566'))->toBeTrue();
    expect(ReferenceValidator::isValidIdentifier('attack_technique', 'T1566.001'))->toBeTrue();
    expect(ReferenceValidator::isValidIdentifier('attack_technique', 'T15'))->toBeFalse();
    expect(ReferenceValidator::isValidIdentifier('attack_technique', 'phishing'))->toBeFalse();
});

test('isValidIdentifier accepts any non-blank identifier for "other"', function () {
    expect(ReferenceValidator::isValidIdentifier('other', 'Vendor advisory 2024-07'))->toBeTrue();
    expect(ReferenceValidator::isValidIdentifier('other', '   '))->toBeFalse();
});

test('isValidUrl accepts only http(s)', function () {
    expect(ReferenceValidator::isValidUrl('https://example.com'))->toBeTrue();
    expect(ReferenceValidator::isValidUrl('http://example.com'))->toBeTrue();
    expect(ReferenceValidator::isValidUrl('javascript:alert(1)'))->toBeFalse();
    expect(ReferenceValidator::isValidUrl('ftp://example.com'))->toBeFalse();
});

test('normalizeIdentifier uppercases every kind except url', function () {
    expect(ReferenceValidator::normalizeIdentifier('cve', ' cve-2024-3094 '))->toBe('CVE-2024-3094');
    expect(ReferenceValidator::normalizeIdentifier('other', 'mixed Case Text'))->toBe('MIXED CASE TEXT');
    expect(ReferenceValidator::normalizeIdentifier('url', 'https://Example.com/Path'))
        ->toBe('https://Example.com/Path');
});

test('deriveUrl builds the correct link for each derived kind', function () {
    expect(ReferenceValidator::deriveUrl('cve', 'CVE-2024-3094'))
        ->toBe('https://nvd.nist.gov/vuln/detail/CVE-2024-3094');

    expect(ReferenceValidator::deriveUrl('cwe', 'CWE-79'))
        ->toBe('https://cwe.mitre.org/data/definitions/79.html');

    expect(ReferenceValidator::deriveUrl('capec', 'CAPEC-163'))
        ->toBe('https://capec.mitre.org/data/definitions/163.html');

    expect(ReferenceValidator::deriveUrl('attack_technique', 'T1566'))
        ->toBe('https://attack.mitre.org/techniques/T1566/');

    expect(ReferenceValidator::deriveUrl('attack_technique', 'T1566.001'))
        ->toBe('https://attack.mitre.org/techniques/T1566/001/');
});

test('deriveUrl refuses to derive a link for url/other — there is no $url parameter to accept one', function () {
    expect(fn () => ReferenceValidator::deriveUrl('url', 'https://example.com'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => ReferenceValidator::deriveUrl('other', 'Vendor advisory'))
        ->toThrow(InvalidArgumentException::class);
});
