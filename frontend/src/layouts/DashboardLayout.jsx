import { useState } from 'react';
import { Breadcrumb, Select, message } from 'antd';
import { HomeOutlined, LogoutOutlined, MenuOutlined } from '@ant-design/icons';
import { useDispatch, useSelector } from 'react-redux';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import { logoutUser, switchRole } from '../store/slices/authSlice';
import { DASHBOARD_ROUTE_BY_ROLE } from '../routes/roles';
import BrandHeader from '../components/BrandHeader';
import ConnectionIndicator from '../components/ConnectionIndicator';
import NotificationBell from '../components/NotificationBell';
import useConnectionState from '../hooks/useConnectionState';

/**
 * menuItems: [{ key, label, icon, path, breadcrumb: string[] }]
 * The active item and breadcrumb are derived from the current route, since
 * a role can now have several sidebar pages instead of just one.
 *
 * The role dropdown shows only THIS user's own granted roles (user.roles,
 * from the login/me response) — never the full static list of all 6
 * system roles. Picking a different one calls PATCH /auth/active-role
 * (via the switchRole thunk) then navigates to that role's own dashboard,
 * which mounts an entirely different DashboardLayout instance with that
 * role's own menuItems — the sidebar swaps automatically, no extra
 * plumbing needed, since each role already lives under its own protected
 * route tree. A single-role user never sees the dropdown at all — there
 * is nothing to switch to.
 */
export default function DashboardLayout({ menuItems }) {
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [switching, setSwitching] = useState(false);
  const dispatch = useDispatch();
  const navigate = useNavigate();
  const location = useLocation();
  const user = useSelector((state) => state.auth.user);

  const activeItem = menuItems.find((item) => location.pathname.startsWith(item.path)) || menuItems[0];
  const connectionState = useConnectionState();
  const roles = user?.roles || [];

  const handleLogout = () => {
    dispatch(logoutUser()).finally(() => navigate('/login', { replace: true }));
  };

  const handleRoleSwitch = async (role) => {
    if (role === user?.active_role) return;
    setSwitching(true);
    try {
      await dispatch(switchRole(role)).unwrap();
      navigate(DASHBOARD_ROUTE_BY_ROLE[role] || '/', { replace: true });
    } catch (error) {
      message.error(typeof error === 'string' ? error : 'Could not switch role.');
    } finally {
      setSwitching(false);
    }
  };

  return <div className="app-frame dashboard-frame">
    <ConnectionIndicator state={connectionState} />
    <BrandHeader right={<div className="header-actions"><NotificationBell /></div>} />
    <div className="dashboard-body">
      <aside className={`dashboard-sidebar ${sidebarOpen ? 'is-open' : ''}`}>
        {roles.length > 1 && (
          <Select
            className="role-selector"
            aria-label="Switch active role"
            value={user?.active_role}
            loading={switching}
            disabled={switching}
            onChange={handleRoleSwitch}
            options={roles.map((role) => ({ label: role, value: role }))}
            style={{ width: '100%' }}
          />
        )}
        <nav className="sidebar-nav">{menuItems.map((item) => <button className={item.key === activeItem.key ? 'active' : ''} key={item.key} type="button" onClick={() => navigate(item.path)}>{item.icon}<span>{item.label}</span></button>)}</nav>
        <button type="button" className="sidebar-logout" onClick={handleLogout}><LogoutOutlined /><span>Sign out</span></button>
      </aside>
      <main className="dashboard-content"><div className="breadcrumb-row"><button type="button" className="dashboard-menu-toggle" aria-label="Toggle sidebar" onClick={() => setSidebarOpen(!sidebarOpen)}><MenuOutlined /></button><div className="breadcrumb-bar"><Breadcrumb items={[{ title: <HomeOutlined /> }, ...(activeItem.breadcrumb || []).map((title) => ({ title }))]} /></div></div><section className="dashboard-panel"><Outlet /></section></main>
    </div>
  </div>;
}
