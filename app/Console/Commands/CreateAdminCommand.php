<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * The only way to create an administrator. Passwords are typed at a hidden
 * prompt (never passed as arguments, so they stay out of shell history).
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'pdl:create-admin {email : Email address of the administrator}';

    protected $description = 'Create a PDL Africa administrator, or grant admin role to an existing user';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->error('Please provide a valid email address.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if ($user?->isAdmin()) {
            $this->info("{$email} is already an administrator.");

            return self::SUCCESS;
        }
        if ($user) {
            if (! confirm("Grant administrator access to the existing account {$email}?", default: false)) {
                return self::FAILURE;
            }
            $user->forceFill(['role' => UserRole::Admin])->save();
            $this->info("{$email} is now an administrator.");

            return self::SUCCESS;
        }

        $name = text('Name', required: true, validate: fn ($v) => mb_strlen($v) > 120 ? 'Name is too long.' : null);
        $secret = password(
            'Password (min 12 characters, mixed case, a number and a symbol)',
            required: true,
            validate: fn ($v) => Validator::make(['password' => $v], ['password' => [Password::defaults()]])->errors()->first('password') ?: null,
        );
        $confirmation = password('Confirm password', required: true);
        if (! hash_equals($secret, $confirmation)) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $secret]);
        $user->forceFill(['role' => UserRole::Admin, 'email_verified_at' => now()])->save();
        $this->info("Administrator {$email} created.");

        return self::SUCCESS;
    }
}
