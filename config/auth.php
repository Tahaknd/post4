<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    |--------------------------------------------------------------------------
    | Sign-in throttle
    |--------------------------------------------------------------------------
    |
    | Attempts allowed per fixed window (App\Domain\Auth\LoginThrottle). Over any budget the answer is 429 +
    | Retry-After until the window ends, even for a correct password; an account is never locked, only rated.
    |
    |  - email_ip_limit: per (email, ip). The tight one: five wrong guesses and that pair waits.
    |  - ip_limit: per ip across all emails. Larger because an office behind one NAT address shares it.
    |  - email_limit: per email across all ips, so a botnet cannot get email_ip_limit tries from every
    |    address forever. It is also the budget a stranger can spend to make the owner wait for the window
    |    (rate limiting the victim, not locking them), so it sits well above the others: 30 per 15 minutes
    |    is ~2900 guesses a day, nothing against a 12+ character password.
    |
    | The ip is the one Laravel resolves. Behind a reverse proxy TRUSTED_PROXIES MUST be set (see
    | config/proxies.php) or every client shares the proxy's address and ONE ip budget; Docker Desktop's NAT
    | gives a developer machine the same single address. There is deliberately no off switch.
    |
    */

    'login_throttle' => [
        'email_ip_limit' => (int) env('LOGIN_THROTTLE_EMAIL_IP_LIMIT', 5),
        'ip_limit' => (int) env('LOGIN_THROTTLE_IP_LIMIT', 20),
        'email_limit' => (int) env('LOGIN_THROTTLE_EMAIL_LIMIT', 30),
        'window_seconds' => (int) env('LOGIN_THROTTLE_WINDOW_SECONDS', 900),
    ],

];
