<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Issue a Sanctum personal access token for a staff login.
     *
     * We use token auth (Authorization: Bearer ...) rather than Sanctum's
     * cookie-based SPA session, since the frontend and backend are not
     * guaranteed to share a top-level domain in every deployment. This
     * still uses Sanctum end to end, just its PAT half rather than the
     * SPA-cookie half.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        // One generic message for every rejection reason (no such email,
        // wrong password, deactivated account) — a distinct "deactivated"
        // message would let a caller enumerate which emails exist and are
        // merely disabled vs. never having existed at all. A deactivated
        // user who needs to know why should be told through a separate
        // support channel, not by the login response itself.
        if (
            ! $user
            || ! Auth::guard('web')->getProvider()->validateCredentials($user, $credentials)
            || ! $user->is_active
        ) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        // One live token per device: drop any previous token with the same name.
        $user->tokens()->where('name', 'spa-token')->delete();

        // The token's single ability IS the active role for this session —
        // see User::hasRole()'s override. Defaults to Administrator (if
        // granted) or whichever role was assigned first.
        $activeRole = $user->defaultActiveRole();
        $token = $user->createToken('spa-token', $activeRole ? [$activeRole] : [])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user, $activeRole),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->formatUser($request->user()),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Switch which of this user's GRANTED roles is active for the current
     * session/device. Only a role actually assigned to this user (checked
     * via reallyHasRole(), the unscoped Spatie grant — using hasRole()
     * here would be circular, since it's scoped to whatever's active
     * right now) may be switched to.
     */
    public function setActiveRole(Request $request)
    {
        $data = $request->validate(['role' => ['required', 'string']]);
        $user = $request->user();

        abort_unless($user->reallyHasRole($data['role']), 403, 'That role is not assigned to your account.');

        $token = $user->currentAccessToken();
        abort_if($token === null, 400, 'No active session token to switch on.');

        $token->forceFill(['abilities' => [$data['role']]])->save();

        return response()->json(['user' => $this->formatUser($user, $data['role'])]);
    }

    private function formatUser(User $user, ?string $activeRoleOverride = null): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->fullName(),
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'active_role' => $activeRoleOverride ?? $user->currentActiveRole(),
        ];
    }
}
