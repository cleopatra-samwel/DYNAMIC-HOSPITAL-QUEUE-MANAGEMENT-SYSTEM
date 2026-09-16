# Hospital Queue Management System — Backend

Laravel 13 REST API. PostgreSQL, Sanctum (token auth), Reverb (real-time, wired
from Phase 7), Spatie Permission (roles).

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` — set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` for your local
PostgreSQL instance, then:

```bash
php artisan migrate
php artisan db:seed
php artisan serve
```

Confirm it's alive: `curl http://localhost:8000/api/ping`

## Roles

Staff roles live in `App\Enums\StaffRole` and are seeded via
`database/seeders/RoleSeeder.php` using Spatie's `roles` table (not a custom
`role_id` column — see the note in chat about the ERD/Spatie discrepancy).
Patients never get a `users` row or a role; they track visits without login.

Guard a route to one role:

```php
Route::middleware(['auth:sanctum', 'role:Doctor'])->group(function () {
    // ...
});
```

## Testing role isolation directly via the API

```bash
# Log in as pharmacy staff
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"pharmacy.staff@hospital.test","password":"password"}'

# Use the returned token against an admin-only route — expect 403
curl http://localhost:8000/api/admin/ping -H "Authorization: Bearer <token>"
```



Role	Email	Password
Administrator	administrator@hospital.test	password
Registration Staff	registration.staff@hospital.test	password
Doctor	doctor@hospital.test	password
Laboratory Staff	laboratory.staff@hospital.test	password
Pharmacy Staff	pharmacy.staff@hospital.test	password
Cashier/Billing Staff	cashierbilling.staff@hospital.test.	password
Muhimu: kama unataka kuongeza akaunti mpya au kubadilisha password, ni kwenye faili hii hii (StaffUserSeeder.php) — kisha ukimbie php artisan db:seed --class="Database\Seeders\StaffUserSeeder". Kumbuka pia comment kwenye mstari wa 15: hizi ni akaunti za demo/majaribio tu — zinatakiwa ziondolewe au zibadilishwe kabla ya mfumo kwenda live (Phase 12), kwa sababu password moja moja kwa kila akaunti si salama kwa uzalishaji halisi.

## Deployment — required before going live

**`APP_DEBUG` must be `false` in every non-local environment.** The local
`.env` in this repo correctly has `APP_DEBUG=true` for development — do NOT
change that file to "fix" this. Instead, whatever deploys this app
(staging/production `.env`, a hosting platform's environment variables,
CI/CD secrets, etc.) must set `APP_DEBUG=false` there. With it left `true` in
production, any unhandled exception — not just on one route, on every route —
renders the full stack trace, file paths, and exception class straight into
the HTTP response. Verify on a deployed environment with:

```bash
curl -s https://your-domain/api/some/route/that/errors | grep -i '"file"'
# must return nothing
```

Two of this app's endpoints (`GET /api/track/{trackingToken}` and
`GET /api/waiting-display/{department}`) carry no authentication at all, so
they're additionally hardened against this specific case regardless of
`APP_DEBUG` — see the `ThrottleRequestsException` handling in
`bootstrap/app.php`'s `withExceptions()`. That's a defense-in-depth backstop
for those two routes specifically, not a substitute for setting `APP_DEBUG`
correctly — every other route in the app still fully depends on it.


