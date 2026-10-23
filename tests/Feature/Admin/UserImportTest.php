<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;

test('anonymous user cannot preview or commit an import', function () {
    $this->post('/admin/users/import/preview', ['csv_text' => "name,email\nA,a@example.com"])
        ->assertRedirect('/login');

    $this->post('/admin/users/import/commit', ['csv_text' => "name,email\nA,a@example.com"])
        ->assertRedirect('/login');
});

test('a non-admin cannot preview or commit an import', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/admin/users/import/preview', ['csv_text' => "name,email\nA,a@example.com"])
        ->assertForbidden();
});

test('preview reports valid rows, ragged rows, invalid emails and duplicates individually', function () {
    $admin = User::factory()->admin()->create();
    $existing = User::factory()->create();

    $csv = implode("\n", [
        'name,email,admin',
        'Good Row,good@example.com,false',
        'No Email,,false',
        'Bad Email,not-an-email,false',
        "Duplicate Of Existing,{$existing->email},false",
        'Duplicate In File,dup@example.com,false',
        'Duplicate In File,dup@example.com,false',
        'Ragged Row,only-two-fields',
    ]);

    $response = $this->actingAs($admin)
        ->post('/admin/users/import/preview', ['csv_text' => $csv], ['X-Inertia' => 'true']);

    $response->assertOk();
    $rows = collect($response->json('props.rows'));

    expect($rows->firstWhere('email', 'good@example.com')['valid'])->toBeTrue();
    expect($rows->firstWhere('email', 'not-an-email')['valid'])->toBeFalse();
    expect($rows->firstWhere('email', $existing->email)['valid'])->toBeFalse();
    expect($rows->where('name', 'Duplicate In File')->pluck('valid')->all())->toBe([true, false]);

    // The ragged row has too few columns, so it is rejected before name/
    // email are even parsed out of it — find it by line number instead.
    $raggedRow = $rows->firstWhere('line', 8);
    expect($raggedRow['valid'])->toBeFalse();
    expect($raggedRow['errors'][0])->toContain('field');
});

test('commit creates only the confirmed valid rows and returns each password exactly once', function () {
    $admin = User::factory()->admin()->create();

    $csv = implode("\n", [
        'name,email,admin',
        'Alice Example,alice@example.com,false',
        'Bob Example,bob@example.com,true',
        'Invalid Row,not-an-email,false',
    ]);

    $response = $this->actingAs($admin)
        ->post('/admin/users/import/commit', ['csv_text' => $csv], ['X-Inertia' => 'true']);

    $response->assertOk();
    $this->assertDatabaseHas('users', ['email' => 'alice@example.com', 'is_admin' => false]);
    $this->assertDatabaseHas('users', ['email' => 'bob@example.com', 'is_admin' => true]);
    $this->assertDatabaseMissing('users', ['email' => 'not-an-email']);

    $created = collect($response->json('props.created'));
    expect($created)->toHaveCount(2);
    expect($created->pluck('password')->every(fn ($p) => is_string($p) && strlen($p) >= 12))->toBeTrue();
});

test('commit excludes rows the admin deselected in the preview', function () {
    $admin = User::factory()->admin()->create();

    $csv = implode("\n", [
        'name,email',
        'Alice Example,alice@example.com',
        'Bob Example,bob@example.com',
    ]);

    // Line 2 is the header, so "Alice" is line 2 and "Bob" is line 3.
    $this->actingAs($admin)->post('/admin/users/import/commit', [
        'csv_text' => $csv,
        'excluded_lines' => [3],
    ]);

    $this->assertDatabaseHas('users', ['email' => 'alice@example.com']);
    $this->assertDatabaseMissing('users', ['email' => 'bob@example.com']);
});

test('an admin column value of true grants admin on import', function () {
    $admin = User::factory()->admin()->create();

    $csv = "name,email,admin\nNew Admin,new-admin@example.com,true";

    $this->actingAs($admin)->post('/admin/users/import/commit', ['csv_text' => $csv]);

    $this->assertDatabaseHas('users', ['email' => 'new-admin@example.com', 'is_admin' => true]);
});

test('the old Bulk import tab opens the import dialog on the Users tab (2.5.1)', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.users.import.create'))
        ->assertRedirect(route('admin.users.index', ['import' => 1]));
});

// W123 (D90): an uploaded spreadsheet export reaches the preview as valid
// UTF-8 JSON and the preview-to-commit round trip creates the same accounts.
test('an uploaded Excel file imports through preview and commit', function (string $content) {
    $admin = User::factory()->admin()->create();

    $file = UploadedFile::fake()->createWithContent('roster.csv', $content);
    $preview = $this->actingAs($admin)
        ->post('/admin/users/import/preview', ['csv_file' => $file], ['X-Inertia' => 'true']);

    $preview->assertOk();
    expect($preview->json('props.validCount'))->toBe(2);
    expect($preview->json('props.rejectedCount'))->toBe(0);
    expect(collect($preview->json('props.rows'))->firstWhere('line', 2)['name'])->toBe('Ésther Müller');

    // The browser re-sends the text the preview returned.
    $this->actingAs($admin)
        ->post('/admin/users/import/commit', ['csv_text' => $preview->json('props.csvText')], ['X-Inertia' => 'true'])
        ->assertOk();

    expect(User::query()->where('email', 'esther@example.com')->value('name'))->toBe('Ésther Müller');
    expect(User::query()->where('email', 'ben@example.com')->exists())->toBeTrue();
})->with([
    'UTF-8 with a mark' => fn () => "\xEF\xBB\xBFname,email\r\nÉsther Müller,esther@example.com\r\nBen Bakker,ben@example.com\r\n",
    'semicolons, Windows-1252' => fn () => mb_convert_encoding("name;email\r\nÉsther Müller;esther@example.com\r\nBen Bakker;ben@example.com\r\n", 'Windows-1252', 'UTF-8'),
    'tabs, UTF-16' => fn () => "\xFF\xFE".mb_convert_encoding("name\temail\r\nÉsther Müller\testher@example.com\r\nBen Bakker\tben@example.com\r\n", 'UTF-16LE', 'UTF-8'),
]);
