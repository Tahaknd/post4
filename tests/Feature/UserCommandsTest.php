<?php

use App\Actions\Users\CreateUser;
use App\Console\Commands\UserCreate;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * A password file in the system temp dir; call with null to remove every file made so far.
 */
function passwordFile(?string $contents): string
{
    static $files = [];

    if ($contents === null) {
        array_map('unlink', $files);
        $files = [];

        return '';
    }

    $path = tempnam(sys_get_temp_dir(), 'post4-pw-');
    file_put_contents($path, $contents);
    $files[] = $path;

    return $path;
}

afterEach(fn () => passwordFile(null));

it('creates a user from a hidden interactive prompt', function () {
    $this->artisan('user:create', ['email' => 'Ops@Example.TEST'])
        ->expectsQuestion('Password', 'correct horse battery')
        ->expectsQuestion('Confirm password', 'correct horse battery')
        ->expectsOutputToContain('Created user #')
        ->assertExitCode(0);

    $user = User::sole();

    expect($user->email)->toBe('ops@example.test')
        ->and($user->name)->toBe('ops')
        ->and($user->is_admin)->toBeFalse()
        ->and(Hash::check('correct horse battery', $user->password))->toBeTrue();
});

it('creates an administrator with --admin and a custom name', function () {
    $this->artisan('user:create', ['email' => 'root@example.test', '--admin' => true, '--name' => 'Root User', '--password-file' => passwordFile("correct horse battery\n")])
        ->expectsOutputToContain('Created administrator #')
        ->assertExitCode(0);

    $user = User::sole();

    expect($user->is_admin)->toBeTrue()
        ->and($user->name)->toBe('Root User')
        ->and(Hash::check('correct horse battery', $user->password))->toBeTrue();
});

it('works while registration is closed', function () {
    User::factory()->admin()->create();

    $this->artisan('user:create', ['email' => 'second@example.test', '--password-file' => passwordFile('correct horse battery')])
        ->assertExitCode(0);

    expect(User::count())->toBe(2)
        ->and(User::where('email', 'second@example.test')->sole()->is_admin)->toBeFalse();

    $this->get('/register')->assertRedirect('/login');
});

it('exits 1 with a clear message when the email already exists', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->artisan('user:create', ['email' => 'TAKEN@example.test', '--password-file' => passwordFile('correct horse battery')])
        ->expectsOutputToContain('A user with the email taken@example.test already exists.')
        ->assertExitCode(1);

    expect(User::count())->toBe(1);
});

it('applies the same validation as registration', function (string $email, string $password) {
    $this->artisan('user:create', ['email' => $email, '--password-file' => passwordFile($password)])
        ->assertExitCode(2);

    expect(User::count())->toBe(0);
})->with([
    'short password' => ['ok@example.test', 'short'],
    'bad email' => ['not-an-email', 'correct horse battery'],
    'password longer than bcrypt reads' => ['ok@example.test', str_repeat('x', 73)],
]);

it('keeps spaces in a password and drops only the line break', function () {
    $this->artisan('user:create', ['email' => 'sp@example.test', '--password-file' => passwordFile("  spaced password  \r\nsecond line ignored\n")])
        ->assertExitCode(0);

    expect(Hash::check('  spaced password  ', User::sole()->password))->toBeTrue();
});

it('refuses mismatching prompts and creates nothing', function () {
    $this->artisan('user:create', ['email' => 'ops@example.test'])
        ->expectsQuestion('Password', 'correct horse battery')
        ->expectsQuestion('Confirm password', 'something different')
        ->expectsOutputToContain('The passwords do not match.')
        ->assertExitCode(2);

    expect(User::count())->toBe(0);
});

it('refuses to run without a password source when not interactive', function () {
    $this->artisan('user:create', ['email' => 'ops@example.test', '--no-interaction' => true])
        ->expectsOutputToContain('No password source')
        ->assertExitCode(2);

    expect(User::count())->toBe(0);
});

it('fails clearly when the password file is missing or empty', function () {
    $this->artisan('user:create', ['email' => 'ops@example.test', '--password-file' => '/nonexistent/pw'])
        ->expectsOutputToContain('Cannot read the password file')
        ->assertExitCode(2);

    $this->artisan('user:create', ['email' => 'ops@example.test', '--password-file' => passwordFile('')])
        ->assertExitCode(2);
});

it('has no option that takes the password on the command line', function () {
    $options = array_keys($this->app->make(Kernel::class)->all()['user:create']->getDefinition()->getOptions());

    expect($options)->toContain('password-file')->not->toContain('password');
});

it('resets a password from a prompt', function () {
    $user = User::factory()->create(['email' => 'ada@example.test', 'password' => 'old old old old old']);

    $this->artisan('user:reset-password', ['email' => 'ADA@example.test'])
        ->expectsQuestion('Password', 'brand new password')
        ->expectsQuestion('Confirm password', 'brand new password')
        ->expectsOutputToContain('Password of ada@example.test changed.')
        ->assertExitCode(0);

    expect(Hash::check('brand new password', $user->fresh()->password))->toBeTrue();
});

it('exits 1 when resetting the password of an unknown email', function () {
    $this->artisan('user:reset-password', ['email' => 'nobody@example.test', '--password-file' => passwordFile('brand new password')])
        ->expectsOutputToContain('No user with the email nobody@example.test.')
        ->assertExitCode(1);
});

it('rejects a weak password on reset and keeps the old one', function () {
    $user = User::factory()->create(['password' => 'old old old old old']);

    $this->artisan('user:reset-password', ['email' => $user->email, '--password-file' => passwordFile('short')])
        ->assertExitCode(2);

    expect(Hash::check('old old old old old', $user->fresh()->password))->toBeTrue();
});

it('ends the sessions of a user whose password was reset', function () {
    $user = User::factory()->create(['password' => 'old old old old old']);

    // A signed-in session that carries the hash of the old password, as auth.session stores it.
    $this->actingAs($user)->withSession(['password_hash_web' => $user->getAuthPassword()])->get('/')->assertOk();

    $this->artisan('user:reset-password', ['email' => $user->email, '--password-file' => passwordFile('brand new password')])->assertExitCode(0);

    auth()->forgetGuards();
    $this->withSession(['password_hash_web' => $user->getAuthPassword()])
        ->actingAs($user->fresh())
        ->get('/')
        ->assertRedirect('/login');
});

it('reads the password from standard input with --password-file=-', function () {
    // The command reads STDIN through a seam, so a subclass supplies the stream a shell pipe would.
    $command = new class extends UserCreate
    {
        protected function readStandardInput(): ?string
        {
            return "piped correct horse\n";
        }
    };
    $this->app->make(Kernel::class)->registerCommand($command);

    $this->artisan('user:create', ['email' => 'pipe@example.test', '--password-file' => '-'])->assertExitCode(0);

    expect(Hash::check('piped correct horse', User::sole()->password))->toBeTrue();
});

it('fails when standard input is empty', function () {
    $command = new class extends UserCreate
    {
        protected function readStandardInput(): ?string
        {
            return null;
        }
    };
    $this->app->make(Kernel::class)->registerCommand($command);

    $this->artisan('user:create', ['email' => 'pipe@example.test', '--password-file' => '-'])
        ->expectsOutputToContain('Nothing to read on standard input.')
        ->assertExitCode(2);
});

it('warns when the password file is readable by group or others but still uses it', function () {
    $path = passwordFile('correct horse battery');
    chmod($path, 0644);

    $this->artisan('user:create', ['email' => 'loose@example.test', '--password-file' => $path])
        ->expectsOutputToContain('readable by group or others')
        ->assertExitCode(0);

    expect(User::count())->toBe(1);
});

it('does not warn about a private password file', function () {
    $path = passwordFile('correct horse battery');
    chmod($path, 0600);

    $this->artisan('user:create', ['email' => 'tight@example.test', '--password-file' => $path])
        ->doesntExpectOutputToContain('readable by group or others')
        ->assertExitCode(0);
});

it('creates every user inside the user-creation transaction (the lock is taken on Postgres)', function () {
    $level = null;
    User::creating(function () use (&$level) {
        $level = DB::transactionLevel();
    });

    app(CreateUser::class)->handle('Ada', 'ada@example.test', 'correct horse battery');

    expect($level)->toBeGreaterThan(0);
});
