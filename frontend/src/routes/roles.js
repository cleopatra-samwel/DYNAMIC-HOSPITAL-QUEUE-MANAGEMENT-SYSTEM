// Mirrors backend App\Enums\StaffRole. Keep dashboard slugs in sync with
// routes/api.php on the backend (used there to build /{slug}/ping routes).
export const DASHBOARD_ROUTE_BY_ROLE = {
  Administrator: '/admin',
  'Registration Staff': '/registration',
  Doctor: '/doctor',
  'Laboratory Staff': '/laboratory',
  'Pharmacy Staff': '/pharmacy',
  'Cashier/Billing Staff': '/billing',
};

/**
 * Multi-role fix — activeRole (from the login/me response, backend-
 * computed and authoritative — see User::defaultActiveRole()/
 * currentActiveRole()) is now the real source of truth for "which
 * dashboard should this session land on", not array order within
 * `roles`. The old roles-array heuristic below only remains as a
 * fallback for the rare case activeRole is missing entirely.
 */
export function dashboardPathForRoles(roles = [], activeRole = null) {
  if (activeRole && DASHBOARD_ROUTE_BY_ROLE[activeRole]) {
    return DASHBOARD_ROUTE_BY_ROLE[activeRole];
  }

  // Administrator holds every role (so they can open any dashboard), but
  // should still land on their own by default regardless of array order.
  if (roles.includes('Administrator')) {
    return DASHBOARD_ROUTE_BY_ROLE.Administrator;
  }

  for (const role of roles) {
    if (DASHBOARD_ROUTE_BY_ROLE[role]) {
      return DASHBOARD_ROUTE_BY_ROLE[role];
    }
  }
  return '/login';
}