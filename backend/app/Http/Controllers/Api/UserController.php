<?php

namespace App\Http\Controllers\Api;

use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Administrator's "Users & Roles" page — grant/revoke which roles a
 * staff account holds. Deliberately minimal, per spec: no create-user,
 * no password reset here, just role assignment on existing accounts.
 * This is what populates each user's OWN role list for their sidebar
 * dropdown (see AuthController::formatUser's `roles` field, sourced from
 * the same Spatie grants this controller writes).
 */
class UserController extends Controller
{
    private function assertAdministrator(Request $request): void
    {
        abort_unless($request->user()->hasRole('Administrator'), 403, 'Only Administrators may manage users and roles.');
    }

    public function index(Request $request)
    {
        $this->assertAdministrator($request);

        $users = User::with('roles')->orderBy('name')->get()->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->fullName(),
            'email' => $user->email,
            'is_active' => $user->is_active,
            'roles' => $user->getRoleNames(),
        ]);

        return response()->json([
            'users' => $users,
            'available_roles' => StaffRole::values(),
        ]);
    }

    /**
     * Replaces this user's full set of granted roles with exactly the
     * given list (syncRoles, not additive) — at least one role required,
     * so an Administrator can't accidentally strand an account with no
     * dashboard to land on at all.
     */
    public function updateRoles(Request $request, User $user)
    {
        $this->assertAdministrator($request);

        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'in:'.implode(',', StaffRole::values())],
        ]);

        $user->syncRoles($data['roles']);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->fullName(),
                'email' => $user->email,
                'roles' => $user->fresh()->getRoleNames(),
            ],
        ]);
    }
}
