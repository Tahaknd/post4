<?php

namespace App\Console\Commands;

use App\Actions\Users\UserRules;
use App\Console\Concerns\ReadsPassword;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * There is no e-mailed reset flow (no mail server in the stack): this command is the recovery path.
 * Open sessions of the user end by themselves: signed-in pages run `auth.session`, which compares the
 * password hash stored in the session with the current one.
 */
class UserResetPassword extends Command
{
    use ReadsPassword;

    protected $signature = 'user:reset-password
        {email : Email address of an existing user}
        {--password-file= : Read the password from the first line of this file, or "-" for standard input (never pass it as an argument)}';

    protected $description = 'Sets a new password for a user and signs them out everywhere';

    public function handle(): int
    {
        $email = User::normaliseEmail((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No user with the email {$email}.");

            return self::FAILURE;
        }

        $password = $this->readPassword();

        if ($password === null) {
            return self::INVALID;
        }

        $validator = Validator::make(['password' => $password], ['password' => UserRules::password()]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::INVALID;
        }

        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

        $this->components->info("Password of {$user->email} changed.");

        return self::SUCCESS;
    }
}
