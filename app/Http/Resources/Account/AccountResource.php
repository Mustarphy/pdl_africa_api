<?php

namespace App\Http\Resources\Account;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in customer's own account. Never includes the password hash,
 * remember token, role or internal ids.
 *
 * @mixin User
 */
class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'pending_email' => $this->pending_email,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
