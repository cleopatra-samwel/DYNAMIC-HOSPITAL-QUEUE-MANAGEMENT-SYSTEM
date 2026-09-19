<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetPermissionCache();
    }

    /**
     * Spatie Permission caches role/permission lookups outside the DB
     * (Laravel's cache store), so RefreshDatabase's per-test transaction
     * rollback never clears it. Clearing once in setUp() is NOT enough —
     * confirmed the hard way: an earlier request within the SAME test
     * (e.g. Registration Staff creating a visit) can populate the cache
     * with a snapshot that then makes a LATER actingAs()'d role check in
     * that same test (e.g. Doctor calling a ticket) fail with a false
     * 403, even though the role was assigned correctly before either
     * request ran. Clearing again before every actingAs() closes that
     * gap regardless of what already ran earlier in the test.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->forgetPermissionCache();

        return parent::actingAs($user, $guard);
    }

    private function forgetPermissionCache(): void
    {
        $this->app[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
