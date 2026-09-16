import { Navigate, Outlet } from 'react-router-dom';
import { useSelector } from 'react-redux';
import { dashboardPathForRoles } from './roles';

/**
 * Guards a route to a signed-in user, and optionally to a specific role.
 * Enforced again server-side by the `role:` middleware on every API
 * route — this is UX only, not the security boundary.
 *
 * A role mismatch (manually navigating to another role's URL) redirects
 * to the user's OWN dashboard rather than a dead-end /unauthorized page —
 * there's always a real place for a logged-in user to land, so bouncing
 * them to their own role's home is more useful than a generic error.
 *
 * Checked against active_role, not just "is this role granted at all":
 * a multi-role user typing another GRANTED role's URL directly (bypassing
 * the sidebar dropdown) would otherwise see that role's whole
 * page/sidebar render fine while every action on it silently 403s,
 * because the backend's active-role token ability never actually
 * switched — only DashboardLayout's dropdown does that (it calls
 * PATCH /auth/active-role before navigating). Redirecting here whenever
 * the route's role isn't the CURRENTLY active one makes the dropdown the
 * only path that actually changes what a user is doing, so the UI can
 * never show a role the backend isn't simultaneously enforcing.
 */
export default function ProtectedRoute({ allowedRole }) {
  const { user, token } = useSelector((state) => state.auth);

  if (!token || !user) {
    return <Navigate to="/login" replace />;
  }

  if (allowedRole && (!user.roles?.includes(allowedRole) || user.active_role !== allowedRole)) {
    return <Navigate to={dashboardPathForRoles(user.roles, user.active_role)} replace />;
  }

  return <Outlet />;
}