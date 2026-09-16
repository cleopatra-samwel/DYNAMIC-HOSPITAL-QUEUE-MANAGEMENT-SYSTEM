<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['first_name', 'last_name', 'name', 'email', 'phone', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;
    // HasRoles is a TRAIT, not a base class — `parent::hasRole()` cannot
    // reach a trait method (parent:: only resolves real class inheritance;
    // calling it here throws BadMethodCallException, confirmed the hard
    // way when the full test suite hit it live). Aliasing it under a
    // different name is the correct way to override hasRole() below while
    // still being able to call Spatie's original implementation.
    use HasRoles {
        HasRoles::hasRole as spatieHasRole;
    }

    protected $guard_name = 'web';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Multi-role support — a user can be GRANTED several Spatie roles
     * (unchanged, that's Spatie's normal model_has_roles pivot, already
     * in use everywhere via assignRole()/hasRole()), but only ONE is
     * ACTIVE for any given login session/device at a time. The active
     * role is carried on the Sanctum token itself (as a single-ability
     * token, the ability string being the role name) rather than on the
     * user row, so switching role on one device never silently affects
     * another device's session — see AuthController::setActiveRole().
     *
     * This override is the ONLY place that enforces it: every existing
     * hasRole()/hasAnyRole() call across the whole app (dozens of
     * controllers, the `role:` middleware, DepartmentRoles::userCanActOn)
     * goes through here unchanged, because hasAnyRole() itself just calls
     * $this->hasRole() internally (see Spatie's HasRoles trait) — so
     * overriding this one method is sufficient to make every existing
     * authorization check active-role-aware, with zero changes needed
     * anywhere else in the codebase.
     *
     * When there's no current access token at all (e.g. every existing
     * test in this app, which uses plain actingAs() rather than a real
     * Sanctum token with abilities) this falls straight through to
     * Spatie's normal unrestricted behavior — which is exactly why the
     * pre-existing test suite needed zero changes to keep passing.
     *
     * Structured as a mirror of Spatie's own recursive array-flattening
     * (not a flat foreach) deliberately: hasAnyRole(...$roles) is
     * variadic, so every controller's actual call shape in this app —
     * $user->hasAnyRole(['X', 'Y']) — passes ONE array argument, which
     * variadic capture wraps as [['X', 'Y']] (an array containing one
     * array). Spatie's original hasRole() unwraps that correctly because
     * its is_array() branch recurses via $this->hasRole($role, $guard)
     * for each element rather than assuming each element is already a
     * string. A flat single-level loop (an earlier version of this
     * method) silently failed hasAnyRole() entirely — confirmed the hard
     * way against the real test suite, not assumed.
     */
    public function hasRole($roles, ?string $guard = null): bool
    {
        $token = $this->currentAccessToken();

        if ($token === null) {
            return $this->spatieHasRole($roles, $guard);
        }

        if (is_string($roles) && str_contains($roles, '|')) {
            $roles = explode('|', $roles);
        }

        if (is_array($roles)) {
            foreach ($roles as $role) {
                if ($this->hasRole($role, $guard)) {
                    return true;
                }
            }

            return false;
        }

        if (is_string($roles)) {
            return $this->tokenCan($roles) && $this->spatieHasRole($roles, $guard);
        }

        // Any other accepted type (Role instance, Collection, BackedEnum,
        // int/uid) — never actually used in this app (confirmed via
        // grep), but stays correct: the real Spatie check must pass AND
        // its name must be the currently active token ability.
        return $this->spatieHasRole($roles, $guard) && $this->tokenCan((string) $roles);
    }

    /** The real, unscoped Spatie grant check — used only where "is this role genuinely granted at all" matters regardless of which one is currently active (switching roles, listing roles to grant/revoke). Never use this for authorization decisions. */
    public function reallyHasRole(string $role): bool
    {
        return $this->spatieHasRole($role);
    }

    /** Administrator first if granted (most-privileged), else whichever role was granted first — used to pick the initial active role right after login. */
    public function defaultActiveRole(): ?string
    {
        $names = $this->getRoleNames();

        return $names->contains('Administrator') ? 'Administrator' : $names->first();
    }

    /** The role actually active for THIS session right now — reads it back off the current token (set at login/switch), falling back to the default only when there's no token in play at all (e.g. plain actingAs() in tests). Use this for reporting (me(), formatUser()); hasRole() has its own inline check since it needs a specific role to test against, not "which one is active". */
    public function currentActiveRole(): ?string
    {
        $token = $this->currentAccessToken();

        if ($token === null) {
            return $this->defaultActiveRole();
        }

        foreach ($this->getRoleNames() as $role) {
            if ($this->tokenCan($role)) {
                return $role;
            }
        }

        return null;
    }
}