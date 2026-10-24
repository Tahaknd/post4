<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Users\RegisterFirstUser;
use App\Actions\Users\RegistrationClosed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Registration exists only to create the first administrator. EnsureRegistrationOpen guards both routes;
 * RegisterFirstUser re-checks under a lock, which is what actually closes the race.
 */
class RegisterController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(RegisterRequest $request, RegisterFirstUser $registerFirstUser): RedirectResponse
    {
        try {
            $user = $registerFirstUser->handle(
                $request->validated('name'),
                $request->validated('email'),
                $request->validated('password'),
            );
        } catch (RegistrationClosed) {
            // Lost the race with another first registration.
            return redirect()->route('login')->with('status', 'Registration is closed.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('home');
    }
}
