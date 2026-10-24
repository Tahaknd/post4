<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\LoginThrottle;
use App\Http\Controllers\Controller;
use App\Http\NextUrl;
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
use SensitiveParameter;

class LoginController extends Controller
{
    /** Shown for an unknown email and for a wrong password alike: the answer must not reveal which accounts exist. */
    public const string FAILED_MESSAGE = 'These credentials do not match our records.';

    /** Hash that stands in for the password of a missing user (see store()). One per worker process. */
    private static ?string $dummyHash = null;

    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            // Only true until the first account exists: the page then offers "Create the first account".
            'canRegister' => User::query()->doesntExist(),
            // The page the visitor was heading for; sanitised here and again after the post.
            'next' => NextUrl::sanitise($request->query('next')),
        ]);
    }

    public function store(LoginRequest $request, LoginThrottle $throttle): RedirectResponse
    {
        $email = (string) $request->validated('email');
        $password = (string) $request->validated('password');
        $ip = (string) $request->ip();

        // Counted before anything is verified (atomically), so parallel guesses cannot all get through.
        if (($wait = $throttle->hit($email, $ip)) !== null) {
            $message = "Too many sign-in attempts. Try again in {$wait} seconds.";

            // An Inertia form stays on the page and shows the message; other clients get the 429.
            if ($request->header('X-Inertia')) {
                throw ValidationException::withMessages(['email' => $message]);
            }

            throw new ThrottleRequestsException($message, headers: ['Retry-After' => $wait]);
        }

        $user = $this->authenticate($email, $password);

        if ($user === null) {
            throw ValidationException::withMessages(['email' => self::FAILED_MESSAGE]);
        }

        $throttle->succeeded($email, $ip);

        // Login::migrate() and an explicit regenerate: a new session id, the old one destroyed (no fixation).
        Auth::login($user, remember: false);
        $request->session()->regenerate();

        return redirect(NextUrl::sanitise($request->input('next')) ?? route('home', absolute: false));
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
     * One lookup, one password hash, on every path: a missing user is checked against a dummy hash of the
     * same cost, so neither the work done nor the response time says whether the email exists. The dummy is
     * made before the lookup so the first request of a worker is no different for a hit and a miss.
     * There is no "remember me": the session cookie is the only credential and it expires with the session.
     */
    private function authenticate(string $email, #[SensitiveParameter] string $password): ?User
    {
        $dummy = self::$dummyHash ??= Hash::make('dummy-password-for-timing');

        $user = User::query()->where('email', $email)->first();

        $matches = Hash::check($password, $user?->password ?? $dummy);

        return $user !== null && $matches ? $user : null;
    }
}
