<?php

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ends a customer's other sign-ins after a password change or reset.
 *
 * Rotating the remember token kills "keep me signed in" cookies. Sanctum's
 * AuthenticateSession middleware already rejects any session created with
 * the old password hash; with the database session driver (the default)
 * those sessions are also deleted outright.
 */
class SessionRevoker
{
    public function revoke(User $user, ?string $exceptSessionId = null): void
    {
        $user->setRememberToken(Str::random(60));
        $user->save();

        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($exceptSessionId, fn ($query, $id) => $query->where('id', '!=', $id))
            ->delete();
    }
}
