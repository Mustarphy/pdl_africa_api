<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\AccountLinks;
use App\Services\Account\AccountMailer;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly AccountLinks $links,
        private readonly AccountMailer $mailer,
    ) {}

    /**
     * Opened from the emailed link (via the storefront). Works without being
     * signed in, e.g. on another device: the signature, expiry and the hash
     * of the current address together prove the link is genuine.
     */
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $check = $this->links->check($request);
        $user = $check === AccountLinks::INVALID ? null
            : User::where('role', UserRole::Customer->value)->find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return $this->linkError(AccountLinks::INVALID);
        }
        if ($user->hasVerifiedEmail()) {
            return response()->json(['data' => ['status' => 'already_verified']]);
        }
        if ($check === AccountLinks::EXPIRED) {
            return $this->linkError(AccountLinks::EXPIRED);
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return response()->json(['data' => ['status' => 'verified']]);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return response()->json(['data' => ['status' => 'already_verified']]);
        }
        if (! $this->mailer->verification($user, resend: true)) {
            return response()->json([
                'message' => 'We could not send the email right now. Please try again in a few minutes.',
            ], 503);
        }

        return response()->json(['data' => ['status' => 'sent']]);
    }

    private function linkError(string $reason): JsonResponse
    {
        return $reason === AccountLinks::EXPIRED
            ? response()->json(['message' => 'This verification link has expired.', 'reason' => 'expired'], 410)
            : response()->json(['message' => 'This verification link is invalid.', 'reason' => 'invalid'], 403);
    }
}
