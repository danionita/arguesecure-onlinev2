<?php

use App\Domain\Mail\MailTemplates;
use App\Models\MailTemplate;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Editable wording for each message kind. A template is plain text with
 * placeholders from a fixed list; it is never evaluated.
 */

function wording(array $overrides = []): array
{
    return array_merge(['subject' => 'Hi {{name}}', 'body' => 'Hello {{name}}, open {{set_password_link}}'], $overrides);
}

test('only an admin edits, previews or resets a template', function () {
    $this->put('/admin/email/templates/invitation', wording())->assertRedirect('/login');
    $this->delete('/admin/email/templates/invitation')->assertRedirect('/login');
    $this->postJson('/admin/email/templates/invitation/preview', wording())->assertUnauthorized();

    $user = User::factory()->create();
    $this->actingAs($user)->put('/admin/email/templates/invitation', wording())->assertForbidden();
    $this->actingAs($user)->delete('/admin/email/templates/invitation')->assertForbidden();
    $this->actingAs($user)->postJson('/admin/email/templates/invitation/preview', wording())->assertForbidden();

    expect(MailTemplate::query()->count())->toBe(0);
});

test('a fresh install has the shipped wording and nothing stored', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin/email')->assertInertia(fn (Assert $page) => $page
        ->has('templates', 5)
        ->where('templates.0.kind', 'invitation')
        ->where('templates.0.customised', false)
        ->where('templates.0.subject', MailTemplates::kinds()['invitation']['subject']));
    expect(MailTemplate::query()->count())->toBe(0);
});

test('an admin saves wording, it is used, and reset returns the default', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/email/templates/invitation', wording())->assertSessionHasNoErrors();
    expect(MailTemplates::render('invitation', ['name' => 'Alex', 'set_password_link' => 'https://x.test/a'])['subject'])->toBe('Hi Alex');

    $this->actingAs($admin)->delete('/admin/email/templates/invitation')->assertRedirect(route('admin.mail.edit'));
    expect(MailTemplate::query()->count())->toBe(0)
        ->and(MailTemplates::render('invitation', ['app_name' => 'App'])['subject'])->toBe('You have an account on App');
});

test('a placeholder the kind does not offer is refused', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/email/templates/invitation', wording(['body' => 'Hello {{verify_link}}']))
        ->assertSessionHasErrors('body');
    $this->actingAs($admin)->put('/admin/email/templates/invitation', wording(['subject' => '{{ password }}']))
        ->assertSessionHasErrors('subject');
    $this->actingAs($admin)->put('/admin/email/templates/invitation', wording(['body' => '{{ name }} {{set_password_link}}']))
        ->assertSessionHasNoErrors();
});

test('a subject can carry neither a link nor the summary', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/email/templates/invitation', wording(['subject' => 'Go {{set_password_link}}']))->assertSessionHasErrors('subject');
    $this->actingAs($admin)->put('/admin/email/templates/verify-email', wording(['subject' => '{{verify_link}}', 'body' => '{{verify_link}}']))->assertSessionHasErrors('subject');
    $this->actingAs($admin)->put('/admin/email/templates/notification-digest', wording(['subject' => '{{summary}}', 'body' => '{{summary}} {{open_link}}']))->assertSessionHasErrors('subject');
    $this->actingAs($admin)->put('/admin/email/templates/invitation', wording(['subject' => 'Hi {{name}} at {{app_name}}']))->assertSessionHasNoErrors();

    // Even a row stored some other way renders those placeholders as nothing.
    MailTemplate::query()->updateOrCreate(['kind' => 'invitation'], ['subject' => 'Go {{set_password_link}}!', 'body' => '{{set_password_link}}']);
    expect(MailTemplates::render('invitation', ['set_password_link' => 'https://x.test/secret'])['subject'])->toBe('Go !');
});

test('only a web address becomes a clickable link', function () {
    $out = MailTemplates::render('invitation', ['name' => 'A', 'set_password_link' => 'javascript:alert(1)']);

    expect($out['html'])->not->toContain('<a ')
        ->and($out['html'])->toContain('javascript:alert(1)');

    expect(MailTemplates::render('invitation', ['set_password_link' => 'https://x.test/a'])['html'])->toContain('<a href="https://x.test/a">');
});

test('the one-time link cannot be edited out of a message that needs it', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/email/templates/invitation', wording(['body' => 'Hello {{name}}']))->assertSessionHasErrors('body');
    $this->actingAs($admin)->put('/admin/email/templates/verify-email', wording(['body' => 'Hello {{name}}']))->assertSessionHasErrors('body');
    $this->actingAs($admin)->put('/admin/email/templates/notification-digest', wording(['body' => '{{summary}}']))->assertSessionHasErrors('body');
    $this->actingAs($admin)->postJson('/admin/email/templates/invitation/preview', wording(['body' => 'Hello']))->assertUnprocessable();
});

test('saving the shipped wording keeps no row', function () {
    $admin = User::factory()->admin()->create();
    $default = MailTemplates::kinds()['invitation'];

    $this->actingAs($admin)->put('/admin/email/templates/invitation', ['subject' => $default['subject'], 'body' => $default['body']])
        ->assertSessionHasNoErrors();

    expect(MailTemplate::query()->count())->toBe(0);
});

test('the shipped wording typed back with CRLF breaks keeps no row either', function () {
    $admin = User::factory()->admin()->create();
    $default = MailTemplates::kinds()['invitation'];

    $this->actingAs($admin)->put('/admin/email/templates/invitation', [
        'subject' => $default['subject'],
        'body' => str_replace("\n", "\r\n", $default['body']),
    ])->assertSessionHasNoErrors();

    expect(MailTemplate::query()->count())->toBe(0);
});

test('the subject is one line and both texts have a size limit', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/email/templates/invitation', wording(['subject' => "Hi\r\nBcc: x@y.test"]))
        ->assertSessionHasErrors('subject');
    $this->actingAs($admin)->put('/admin/email/templates/invitation', wording(['subject' => str_repeat('a', MailTemplates::SUBJECT_MAX + 1)]))
        ->assertSessionHasErrors('subject');
    $this->actingAs($admin)->put('/admin/email/templates/invitation', wording(['body' => str_repeat('a', MailTemplates::BODY_MAX + 1)]))
        ->assertSessionHasErrors('body');
    $this->actingAs($admin)->put('/admin/email/templates/invitation', wording(['body' => '']))
        ->assertSessionHasErrors('body');
});

test('an unknown kind is a 404 and builds nothing from its name', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/email/templates/nope', wording())->assertNotFound();
    $this->actingAs($admin)->postJson('/admin/email/templates/nope/preview', wording())->assertNotFound();
    $this->actingAs($admin)->delete('/admin/email/templates/nope')->assertNotFound();
    expect(MailTemplate::query()->count())->toBe(0);
});

test('values are escaped in the html part and a value cannot add a header or a placeholder', function () {
    $out = MailTemplates::render('invitation', [
        'name' => '<script>alert(1)</script> {{app_name}}',
        'app_name' => "App\r\nBcc: evil@x.test",
        'set_password_link' => 'https://x.test/?a=1&b="2"',
        'expires_days' => '7',
    ]);

    expect($out['html'])->not->toContain('<script>')
        ->and($out['html'])->toContain('&lt;script&gt;')
        ->and($out['html'])->toContain('href="https://x.test/?a=1&amp;b=&quot;2&quot;"')
        // Substituted once: a value that looks like a placeholder is just text.
        ->and($out['text'])->toContain('{{app_name}}')
        ->and($out['subject'])->not->toContain("\n")
        ->and($out['subject'])->toBe('You have an account on App Bcc: evil@x.test');
});

test('the footer cannot be edited away and an admin text is never evaluated', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/email/templates/invitation', wording(['body' => '{{ name }} <?php echo 1; ?> @php(echo 2) {!! 3 !!} {{7*7}}']))
        ->assertSessionHasErrors('body');
    $this->actingAs($admin)->put('/admin/email/templates/invitation', wording(['body' => 'Hi {{name}} {{set_password_link}} <?php echo 1; ?> @php echo 2 {!! 3 !!}']))
        ->assertSessionHasNoErrors();

    $out = MailTemplates::render('invitation', ['name' => 'Alex']);

    expect($out['text'])->toContain('<?php echo 1; ?>')
        ->and($out['text'])->toContain('@php echo 2')
        ->and($out['text'])->toContain(MailTemplates::kinds()['invitation']['footer'])
        ->and($out['html'])->toContain(e(MailTemplates::kinds()['invitation']['footer']));
});

test('the preview renders unsaved wording with samples and stores nothing', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson('/admin/email/templates/reminder/preview', wording(['subject' => 'Hey {{name}}', 'body' => 'Go {{set_password_link}}']))
        ->assertOk()
        ->assertJsonPath('subject', 'Hey Alex Example')
        ->assertJsonPath('text', fn ($t) => str_contains($t, 'Go https://example.org/set-password/abc123'));

    expect(MailTemplate::query()->count())->toBe(0);
});
