<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Services\Account\AccountMailer;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// `role` is intentionally NOT fillable: privileges can only be granted by
// server-side code (the pdl:create-admin command), never by request input.
// `pending_email` is only written by the email-change flow.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = [
        'role' => 'customer',
        'pending_email' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isCustomer(): bool
    {
        return $this->role === UserRole::Customer;
    }

    /** Branded email, sent synchronously (see AccountMailer). */
    public function sendEmailVerificationNotification(): void
    {
        app(AccountMailer::class)->verification($this);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        app(AccountMailer::class)->passwordReset($this, $token);
    }
}
