<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\LoginThrottle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    /** Shown for an unknown email and for a wrong password alike: the answer must not reveal which accounts exist. */
    public const string FAILED_MESSAGE = 'These credentials do not match our records.';

    /** Cached hash that stands in for the password of a missing user (see verifies()). */
    private static ?string $dummyHash = null;

    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            // Only true until the first account exists: the page then offers "Create the first account".
            'canRegister' => User::query()->doesntExist(),
        ]);
    }

    public function store(LoginRequest $request, LoginThrottle $throttle): RedirectResponse
    {
        $email = (string) $request->validated('email');
        $password = (string) $request->validated('password');
        $ip = (string) $request->ip();

        if (($wait = $throttle->blockedFor($email, $ip)) !== null) {
            throw new ThrottleRequestsException(
                "Too many sign-in attempts. Try again in {$wait} seconds.",
                headers: ['Retry-After' => $wait],
            );
        }

        if (! $this->verifies($email, $password)) {
            $throttle->recordFailure($email, $ip);

            throw ValidationException::withMessages(['email' => self::FAILED_MESSAGE]);
        }

        $throttle->clear($email, $ip);

        // Auth::attempt already migrated the session; regenerating again is cheap and keeps the
        // fixation guarantee independent of the guard's internals.
        $request->session()->regenerate();

        return redirect()->intended(route('home', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // After invalidate(): the flag is stored in the NEW session, which the next page response reads.
        // It makes the browser drop the encrypted history entries of the signed-in pages.
        Inertia::clearHistory();

        return redirect()->route('login');
    }

    /**
     * Signs the user in without "remember me": the session cookie is the only credential and it expires with
     * the session. A missing user still costs one password hash, so response time does not tell an unknown email from a
     * wrong password.
     */
    private function verifies(string $email, string $password): bool
    {
        if (! User::query()->where('email', $email)->exists()) {
            Hash::check($password, self::$dummyHash ??= Hash::make('dummy-password-for-timing'));

            return false;
        }

        return Auth::attempt(['email' => $email, 'password' => $password], remember: false);
    }
}
