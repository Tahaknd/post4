<?php

use App\Actions\Users\CreateUser;
use App\Domain\Users\UserRules;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

const SECRET_PASSWORD = 'plaintext-secret-8f3a1c9e2b';

/** Whether $needle occurs in the arguments of the given frames (arrays searched recursively). */
function framesContain(array $frames, string $needle): bool
{
    $found = false;
    $walk = function (mixed $value) use (&$walk, &$found, $needle): void {
        if (is_string($value) && str_contains($value, $needle)) {
            $found = true;
        } elseif (is_array($value)) {
            array_walk($value, $walk);
        }
    };

    foreach ($frames as $frame) {
        $walk($frame['args'] ?? []);
    }

    return $found;
}

/** Frames that belong to application code (the ones this project controls). */
function applicationFrames(Throwable $e): array
{
    return array_values(array_filter($e->getTrace(), fn (array $f) => str_starts_with($f['class'] ?? '', 'App\\')));
}

it('does not put the password into the arguments of CreateUser frames when something throws below them', function () {
    User::creating(fn () => throw new RuntimeException('database exploded'));

    try {
        app(CreateUser::class)->handle('Ada', 'ada@example.test', SECRET_PASSWORD);
        $this->fail('expected an exception');
    } catch (RuntimeException $e) {
        $frames = applicationFrames($e);

        expect($frames)->not->toBeEmpty()
            ->and(framesContain($frames, SECRET_PASSWORD))->toBeFalse()
            ->and($e->getTraceAsString())->not->toContain(SECRET_PASSWORD);
    }
});

it('does not put the password into the arguments of the login controller frames', function () {
    User::factory()->create(['email' => 'ada@example.test']);
    $hasher = Hash::partialMock();
    $hasher->shouldReceive('make')->andReturn('$2y$04$abcdefghijklmnopqrstuuabcdefghijklmnopqrstuvwxyz01234');
    // Thrown when called, so the trace is the one of the real call path (an exception made here would not be).
    $hasher->shouldReceive('check')->andReturnUsing(fn () => throw new RuntimeException('hasher exploded'));

    try {
        $this->withoutExceptionHandling()->post('/login', ['email' => 'ada@example.test', 'password' => SECRET_PASSWORD]);
        $this->fail('expected an exception');
    } catch (RuntimeException $e) {
        $frames = applicationFrames($e);

        expect(collect($frames)->pluck('function')->all())->toContain('authenticate')
            ->and(framesContain($frames, SECRET_PASSWORD))->toBeFalse();
    }
});

it('does not put the password into the arguments of the password rule closure', function () {
    $rule = collect(UserRules::password())->first(fn ($r) => $r instanceof Closure);

    try {
        $rule('password', str_repeat('p', 80), fn () => throw new RuntimeException('fail callback threw'));
        $this->fail('expected an exception');
    } catch (RuntimeException $e) {
        expect(framesContain(applicationFrames($e), str_repeat('p', 80)))->toBeFalse();
    }
});

it('leaves no frame arguments at all once zend.exception_ignore_args is on', function () {
    // The setting is read at start-up, so it needs its own PHP process.
    $script = 'try { (function (string $secret) { throw new RuntimeException("boom"); })("'.SECRET_PASSWORD.'"); }'
        .' catch (RuntimeException $e) { echo json_encode(array_map(fn ($f) => array_key_exists("args", $f), $e->getTrace())); }';

    foreach (['0' => [true], '1' => [false]] as $setting => [$hasArgs]) {
        $output = shell_exec(escapeshellarg(PHP_BINARY).' -d zend.exception_ignore_args='.$setting.' -r '.escapeshellarg($script));

        expect(json_decode((string) $output, true))->toBe([$hasArgs]);
    }
});

it('turns zend.exception_ignore_args on in the PHP settings shipped with the image', function () {
    $ini = parse_ini_file(base_path('docker/zz-app.ini'));

    expect($ini['zend.exception_ignore_args'])->toBe('1');
});

it('rejects a password with a NUL byte instead of letting bcrypt throw (registration, user:create, reset)', function () {
    $bad = "correct horse\0battery";

    $this->post('/register', ['name' => 'A', 'email' => 'a@example.test', 'password' => $bad, 'password_confirmation' => $bad])
        ->assertSessionHasErrors('password');
    expect(User::count())->toBe(0);

    $file = tempnam(sys_get_temp_dir(), 'post4-pw-');
    file_put_contents($file, $bad);
    $this->artisan('user:create', ['email' => 'a@example.test', '--password-file' => $file])->assertExitCode(2);

    $user = User::factory()->create(['email' => 'b@example.test']);
    $this->artisan('user:reset-password', ['email' => 'b@example.test', '--password-file' => $file])->assertExitCode(2);
    unlink($file);

    expect(User::count())->toBe(1)->and(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('answers a login with a NUL byte in the password like any other failure', function () {
    User::factory()->create(['email' => 'ada@example.test']);

    $this->post('/login', ['email' => 'ada@example.test', 'password' => "pass\0word"])->assertSessionHasErrors('email');
});

it('never lets a validated password make bcrypt throw', function (string $password) {
    $rules = ['password' => UserRules::password()];

    expect(validator(['password' => $password], $rules)->passes())->toBeTrue();
    expect(fn () => Hash::make($password))->not->toThrow(Throwable::class);
})->with([
    'twelve ascii' => ['twelve chars'],
    'seventy-two ascii' => [str_repeat('a', 72)],
    'seventy-two bytes of multibyte' => [str_repeat('é', 36)],
    'spaces and symbols' => ['  p@ss w0rd !!  ok '],
]);

it('rejects passwords outside the 12 to 72 byte window', function (string $password) {
    expect(validator(['password' => $password], ['password' => UserRules::password()])->fails())->toBeTrue();
})->with([
    'eleven' => ['elevenchars'],
    'seventy-three bytes' => [str_repeat('a', 73)],
    'seventy-four bytes of multibyte' => [str_repeat('é', 37)],
    'NUL byte' => ["twelve chars\0"],
]);
