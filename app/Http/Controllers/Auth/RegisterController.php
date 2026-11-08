<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Accounts\SelfRegistration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-registration. Both routes answer 404 while it is off. The answer to a
 * valid request is always the same page, whether or not the address already
 * has an account, and no account exists until the emailed link is followed.
 */
class RegisterController extends Controller
{
    public function create(): Response
    {
        abort_unless(SelfRegistration::enabled(), 404);

        return Inertia::render('Auth/Register', [
            'domains' => SelfRegistration::domains(),
            'sent' => session('registration_sent', false),
        ]);
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        SelfRegistration::request($validated['email']);

        return redirect()->route('register')->with('registration_sent', true);
    }
}
