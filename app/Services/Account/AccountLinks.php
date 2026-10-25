<?php

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Links sent in account emails. Each one opens a storefront page that posts
 * the signed parameters back to the matching API route, where the signature
 * (HMAC with APP_KEY, built into Laravel) and expiry are checked.
 *
 * Signatures are relative (path + query only): the storefront reaches the API
 * through its own proxy, so the host the API sees can differ from APP_URL.
 */
class AccountLinks
{
    public const VALID = 'valid';

    public const EXPIRED = 'expired';

    public const INVALID = 'invalid';

    /** Verifies the account's current email address. */
    public function verification(User $user): string
    {
        return $this->signed('/account/verify-email', 'api.v1.account.verification.verify', [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);
    }

    /** Confirms a requested new address (sent to that address). */
    public function emailChange(User $user): string
    {
        return $this->signed('/account/confirm-email', 'api.v1.account.email.confirm', [
            'id' => $user->getKey(),
            'hash' => sha1((string) $user->pending_email),
        ]);
    }

    /**
     * Password reset page. The token travels in the URL fragment, which
     * browsers never send to a server (no access logs, no Referer leaks).
     */
    public function passwordReset(User $user, #[\SensitiveParameter] string $token): string
    {
        return $this->frontend('/account/reset-password').'#'.http_build_query([
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]);
    }

    public function frontend(string $path): string
    {
        return config('account.frontend_url').$path;
    }

    /** @return self::VALID|self::EXPIRED|self::INVALID */
    public function check(Request $request): string
    {
        if (! URL::hasCorrectSignature($request, absolute: false)) {
            return self::INVALID;
        }

        return URL::signatureHasNotExpired($request) ? self::VALID : self::EXPIRED;
    }

    /** @param  array<string, int|string>  $parameters */
    private function signed(string $page, string $route, array $parameters): string
    {
        $url = URL::temporarySignedRoute(
            $route,
            now()->addMinutes(config('account.link_expire_minutes')),
            $parameters,
            absolute: false,
        );
        parse_str((string) parse_url($url, PHP_URL_QUERY), $signature);

        return $this->frontend($page).'?'.http_build_query([...$parameters, ...$signature]);
    }
}
