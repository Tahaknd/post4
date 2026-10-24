<?php

namespace App\Console\Commands;

use App\Actions\Users\CreateUser;
use App\Console\Concerns\ReadsPassword;
use App\Domain\Users\UserRules;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;

class UserCreate extends Command
{
    use ReadsPassword;

    protected $signature = 'user:create
        {email : Email address (stored lowercased)}
        {--name= : Display name (default: the part of the email before the @)}
        {--admin : Make the user an administrator}
        {--password-file= : Read the password from the first line of this file, or "-" for standard input (never pass it as an argument)}';

    protected $description = 'Creates a user (headless setup; works while registration is closed)';

    public function handle(CreateUser $createUser): int
    {
        $email = User::normaliseEmail((string) $this->argument('email'));

        if (User::query()->where('email', $email)->exists()) {
            $this->components->error("A user with the email {$email} already exists.");

            return self::FAILURE;
        }

        $password = $this->readPassword();

        if ($password === null) {
            return self::INVALID;
        }

        $name = trim((string) ($this->option('name') ?: strstr($email, '@', before_needle: true)));

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            ['name' => UserRules::name(), 'email' => UserRules::email(), 'password' => UserRules::password()],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::INVALID;
        }

        try {
            $user = $createUser->handle($name, $email, $password, admin: (bool) $this->option('admin'));
        } catch (UniqueConstraintViolationException) {
            // Created by someone else between the check above and the insert.
            $this->components->error("A user with the email {$email} already exists.");

            return self::FAILURE;
        }

        $this->components->info(sprintf('Created %s #%d (%s).', $user->is_admin ? 'administrator' : 'user', $user->id, $user->email));

        return self::SUCCESS;
    }
}
