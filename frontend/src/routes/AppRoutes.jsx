import { Navigate, Route, Routes } from 'react-router-dom';
import { useSelector } from 'react-redux';
import { DashboardOutlined, TeamOutlined, AlertOutlined, UnorderedListOutlined, BellOutlined, MedicineBoxOutlined, ExperimentOutlined, SafetyCertificateOutlined, DollarOutlined, BarChartOutlined, ClockCircleOutlined, SoundOutlined, AuditOutlined, UserSwitchOutlined, ExperimentFilled, MedicineBoxFilled, SolutionOutlined, SettingOutlined } from '@ant-design/icons';
import LoginPage from '../pages/login/LoginPage';
import TrackingPage from '../pages/public/TrackingPage';
import WaitingDisplayPage from '../pages/public/WaitingDisplayPage';
import CheckInPage from '../pages/public/CheckInPage';
import AdminDashboardPage from '../pages/dashboards/AdminDashboardPage';
import AdminOpenHoldsPage from '../pages/dashboards/AdminOpenHoldsPage';
import AudioClipLibraryPage from '../pages/dashboards/AudioClipLibraryPage';
import LabTestCatalogPage from '../pages/dashboards/LabTestCatalogPage';
import MedicationCatalogPage from '../pages/dashboards/MedicationCatalogPage';
import LongWaitingAlertsPage from '../pages/dashboards/LongWaitingAlertsPage';
import ReportsPage from '../pages/dashboards/ReportsPage';
import AuditLogPage from '../pages/dashboards/AuditLogPage';
import UsersRolesPage from '../pages/dashboards/UsersRolesPage';
import DashboardLayout from '../layouts/DashboardLayout';
import RegistrationDashboardPage from '../pages/registration/RegistrationDashboardPage';
import RegisterPatientPage from '../pages/registration/RegisterPatientPage';
import VisitsPage from '../pages/registration/VisitsPage';
import EmergencyPage from '../pages/registration/EmergencyPage';
import InsuranceCardsPage from '../pages/registration/InsuranceCardsPage';
import QueuePage from '../pages/registration/QueuePage';
import NotificationPage from '../pages/registration/NotificationPage';
import BillingDashboardPage from '../pages/billing/BillingDashboardPage';
import PendingPaymentsPage from '../pages/billing/PendingPaymentsPage';
import BillingQueuePage from '../pages/billing/BillingQueuePage';
import DoctorDashboardPage from '../pages/department/DoctorDashboardPage';
import DoctorQueuePage from '../pages/department/DoctorQueuePage';
import DoctorSettingsPage from '../pages/department/DoctorSettingsPage';
import LaboratoryDashboardPage from '../pages/department/LaboratoryDashboardPage';
import LaboratoryQueuePage from '../pages/department/LaboratoryQueuePage';
import LaboratoryPerformTestPage from '../pages/department/LaboratoryPerformTestPage';
import PharmacyDashboardPage from '../pages/department/PharmacyDashboardPage';
import PharmacyQueuePage from '../pages/department/PharmacyQueuePage';
import PharmacyDispensingPage from '../pages/department/PharmacyDispensingPage';
import ProtectedRoute from './protectedRoutes';
import { dashboardPathForRoles } from './roles';

// Administrator gets the exact same labeled-sidebar pattern every other
// role already uses (DashboardLayout + a menu array) — these used to
// live as small unlabeled icon buttons in the header, inconsistent with
// everyone else. Open Holds (Phase 5/6) was previously left off this
// list and only reachable by direct URL — now linked here too.
const ADMIN_MENU_ITEMS = [
  { key: 'dashboard', label: 'Dashboard', icon: <DashboardOutlined />, path: '/admin/dashboard', breadcrumb: ['Administration', 'Dashboard'] },
  { key: 'users-roles', label: 'Users & Roles', icon: <UserSwitchOutlined />, path: '/admin/users-roles', breadcrumb: ['Administration', 'Users & Roles'] },
  { key: 'reports', label: 'Reports & Analytics', icon: <BarChartOutlined />, path: '/admin/reports', breadcrumb: ['Administration', 'Reports & Analytics'] },
  { key: 'long-waiting-alerts', label: 'Long-Waiting Alerts', icon: <ClockCircleOutlined />, path: '/admin/long-waiting-alerts', breadcrumb: ['Administration', 'Long-Waiting Alerts'] },
  { key: 'open-holds', label: 'Open Holds', icon: <AlertOutlined />, path: '/admin/open-holds', breadcrumb: ['Administration', 'Open Holds'] },
  { key: 'audio-clips', label: 'Audio Clip Library', icon: <SoundOutlined />, path: '/admin/audio-clips', breadcrumb: ['Administration', 'Audio Clip Library'] },
  { key: 'lab-test-catalog', label: 'Lab Test Catalog', icon: <ExperimentFilled />, path: '/admin/lab-test-catalog', breadcrumb: ['Administration', 'Lab Test Catalog'] },
  { key: 'medication-catalog', label: 'Medication Catalog', icon: <MedicineBoxFilled />, path: '/admin/medication-catalog', breadcrumb: ['Administration', 'Medication Catalog'] },
  { key: 'audit-log', label: 'Audit Log', icon: <AuditOutlined />, path: '/admin/audit-log', breadcrumb: ['Administration', 'Audit Log'] },
];

// Dashboard listed first, but Queue stays the post-login landing page (see
// the "/registration" redirect below) — Queue is still where Registration
// Staff spend most of their time, this only changes the sidebar's visual
// order, not which page you land on.
const REGISTRATION_MENU_ITEMS = [
  { key: 'dashboard', label: 'Dashboard', icon: <DashboardOutlined />, path: '/registration/dashboard', breadcrumb: ['Registration', 'Dashboard'] },
  { key: 'queue', label: 'Queue', icon: <UnorderedListOutlined />, path: '/registration/queue', breadcrumb: ['Registration', 'Queue'] },
  { key: 'register-patient', label: 'Register Patient', icon: <TeamOutlined />, path: '/registration/register-patient', breadcrumb: ['Registration', 'Register Patient'] },
  { key: 'emergency', label: 'Emergency', icon: <AlertOutlined />, path: '/registration/emergency', breadcrumb: ['Registration', 'Emergency'] },
  { key: 'insurance-cards', label: 'Insurance Cards', icon: <SafetyCertificateOutlined />, path: '/registration/insurance-cards', breadcrumb: ['Registration', 'Insurance Cards'] },
  { key: 'notifications', label: 'Notification', icon: <BellOutlined />, path: '/registration/notifications', breadcrumb: ['Registration', 'Notification'] },
];

// Phase 6: insurance card items removed — Cashier/Billing no longer
// touches cards, only payment verification.
const BILLING_MENU_ITEMS = [
  { key: 'dashboard', label: 'Dashboard', icon: <DashboardOutlined />, path: '/billing/dashboard', breadcrumb: ['Billing', 'Dashboard'] },
  { key: 'queue', label: 'Queue', icon: <UnorderedListOutlined />, path: '/billing/queue', breadcrumb: ['Billing', 'Queue'] },
  { key: 'pending-payments', label: 'Pending Payments', icon: <DollarOutlined />, path: '/billing/pending-payments', breadcrumb: ['Billing', 'Pending Payments'] },
  { key: 'notifications', label: 'Notification', icon: <BellOutlined />, path: '/billing/notifications', breadcrumb: ['Billing', 'Notification'] },
];

// Doctor, Laboratory Staff, and Pharmacy Staff each get their OWN sidebar
// shape — fewer, different items than Registration Staff's, not a reuse
// of that structure.
//
// Queue / Registration Queue / Laboratory Queue / New Patients all render
// the exact same CONS queue (DoctorQueuePage) — intentionally kept as 4
// separate sidebar entries rather than collapsed into one, per explicit
// sign-off, even though the underlying data is identical across all four.
const DOCTOR_MENU_ITEMS = [
  { key: 'dashboard', label: 'Dashboard', icon: <DashboardOutlined />, path: '/doctor/dashboard', breadcrumb: ['Doctor', 'Dashboard'] },
  // "Queue" removed from the sidebar per explicit request — the route
  // itself (/doctor/queue) stays, since DoctorDashboardPage's stat cards
  // still link to it; Registration Queue/Laboratory Queue show identical
  // data via the same underlying page either way.
  { key: 'registration-queue', label: 'Registration Queue', icon: <SolutionOutlined />, path: '/doctor/registration-queue', breadcrumb: ['Doctor', 'Registration Queue'] },
  { key: 'laboratory-queue', label: 'Laboratory Queue', icon: <ExperimentOutlined />, path: '/doctor/laboratory-queue', breadcrumb: ['Doctor', 'Laboratory Queue'] },
  { key: 'emergency', label: 'Emergency Confirmations', icon: <AlertOutlined />, path: '/doctor/emergency', breadcrumb: ['Doctor', 'Emergency Confirmations'] },
  { key: 'notifications', label: 'Notification', icon: <BellOutlined />, path: '/doctor/notifications', breadcrumb: ['Doctor', 'Notification'] },
  { key: 'settings', label: 'Settings', icon: <SettingOutlined />, path: '/doctor/settings', breadcrumb: ['Doctor', 'Settings'] },
];

// Queue / Patient Details / Perform Test all render the exact same
// "Queue" (call-only) and "Perform Test" (review-only) are now two
// genuinely different Action columns on the same underlying ticket data —
// see DepartmentQueuePage's actionMode prop.
const LABORATORY_MENU_ITEMS = [
  { key: 'dashboard', label: 'Dashboard', icon: <DashboardOutlined />, path: '/laboratory/dashboard', breadcrumb: ['Laboratory', 'Dashboard'] },
  { key: 'queue', label: 'Queue', icon: <UnorderedListOutlined />, path: '/laboratory/queue', breadcrumb: ['Laboratory', 'Queue'] },
  { key: 'perform-test', label: 'Perform Test', icon: <ExperimentOutlined />, path: '/laboratory/perform-test', breadcrumb: ['Laboratory', 'Perform Test'] },
  { key: 'notifications', label: 'Notifications', icon: <BellOutlined />, path: '/laboratory/notifications', breadcrumb: ['Laboratory', 'Notifications'] },
];

const PHARMACY_MENU_ITEMS = [
  { key: 'dashboard', label: 'Dashboard', icon: <DashboardOutlined />, path: '/pharmacy/dashboard', breadcrumb: ['Pharmacy', 'Dashboard'] },
  { key: 'queue', label: 'Pharmacy Queue', icon: <MedicineBoxOutlined />, path: '/pharmacy/queue', breadcrumb: ['Pharmacy', 'Queue'] },
  { key: 'dispensing', label: 'Dispensing', icon: <ExperimentOutlined />, path: '/pharmacy/dispensing', breadcrumb: ['Pharmacy', 'Dispensing'] },
  { key: 'notifications', label: 'Notification', icon: <BellOutlined />, path: '/pharmacy/notifications', breadcrumb: ['Pharmacy', 'Notification'] },
];

function RoleHomeRedirect() {
  const user = useSelector((state) => state.auth.user);
  return <Navigate to={dashboardPathForRoles(user?.roles, user?.active_role)} replace />;
}

export default function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />

      {/* Phase 7 — public, no login: patient tracking page and the
          waiting-area kiosk board. */}
      <Route path="/track/:token" element={<TrackingPage />} />
      <Route path="/waiting-display/:departmentId" element={<WaitingDisplayPage />} />

      {/* Self check-in — static entrance QR, same URL for every patient,
          not to be confused with the per-visit tracking QR above. */}
      <Route path="/check-in" element={<CheckInPage />} />

      <Route element={<ProtectedRoute allowedRole="Administrator" />}>
        <Route path="/admin" element={<Navigate to="/admin/dashboard" replace />} />
        <Route element={<DashboardLayout menuItems={ADMIN_MENU_ITEMS} />}>
          <Route path="/admin/dashboard" element={<AdminDashboardPage />} />
          <Route path="/admin/users-roles" element={<UsersRolesPage />} />
          <Route path="/admin/reports" element={<ReportsPage />} />
          <Route path="/admin/long-waiting-alerts" element={<LongWaitingAlertsPage />} />
          <Route path="/admin/open-holds" element={<AdminOpenHoldsPage />} />
          <Route path="/admin/audio-clips" element={<AudioClipLibraryPage />} />
          <Route path="/admin/lab-test-catalog" element={<LabTestCatalogPage />} />
          <Route path="/admin/medication-catalog" element={<MedicationCatalogPage />} />
          <Route path="/admin/audit-log" element={<AuditLogPage />} />
        </Route>
      </Route>

      <Route element={<ProtectedRoute allowedRole="Registration Staff" />}>
        {/* Queue, not Dashboard, is where the actual shift starts — see
            REGISTRATION_MENU_ITEMS above. */}
        <Route path="/registration" element={<Navigate to="/registration/queue" replace />} />
        <Route element={<DashboardLayout menuItems={REGISTRATION_MENU_ITEMS} />}>
          <Route path="/registration/dashboard" element={<RegistrationDashboardPage />} />
          <Route path="/registration/register-patient" element={<RegisterPatientPage />} />
          <Route path="/registration/visits" element={<VisitsPage />} />
          <Route path="/registration/emergency" element={<EmergencyPage />} />
          <Route path="/registration/insurance-cards" element={<InsuranceCardsPage />} />
          <Route path="/registration/queue" element={<QueuePage />} />
          <Route path="/registration/notifications" element={<NotificationPage />} />
        </Route>
      </Route>

      <Route element={<ProtectedRoute allowedRole="Doctor" />}>
        <Route path="/doctor" element={<Navigate to="/doctor/dashboard" replace />} />
        <Route element={<DashboardLayout menuItems={DOCTOR_MENU_ITEMS} />}>
          <Route path="/doctor/dashboard" element={<DoctorDashboardPage />} />
          <Route path="/doctor/queue" element={<DoctorQueuePage />} />
          <Route path="/doctor/registration-queue" element={<DoctorQueuePage />} />
          <Route path="/doctor/laboratory-queue" element={<DoctorQueuePage secondaryActionLabel="Result" />} />
          <Route path="/doctor/emergency" element={<EmergencyPage />} />
          <Route path="/doctor/notifications" element={<NotificationPage />} />
          <Route path="/doctor/settings" element={<DoctorSettingsPage />} />
        </Route>
      </Route>

      <Route element={<ProtectedRoute allowedRole="Laboratory Staff" />}>
        <Route path="/laboratory" element={<Navigate to="/laboratory/dashboard" replace />} />
        <Route element={<DashboardLayout menuItems={LABORATORY_MENU_ITEMS} />}>
          <Route path="/laboratory/dashboard" element={<LaboratoryDashboardPage />} />
          <Route path="/laboratory/queue" element={<LaboratoryQueuePage />} />
          <Route path="/laboratory/perform-test" element={<LaboratoryPerformTestPage />} />
          <Route path="/laboratory/notifications" element={<NotificationPage />} />
        </Route>
      </Route>

      <Route element={<ProtectedRoute allowedRole="Pharmacy Staff" />}>
        <Route path="/pharmacy" element={<Navigate to="/pharmacy/dashboard" replace />} />
        <Route element={<DashboardLayout menuItems={PHARMACY_MENU_ITEMS} />}>
          <Route path="/pharmacy/dashboard" element={<PharmacyDashboardPage />} />
          <Route path="/pharmacy/queue" element={<PharmacyQueuePage />} />
          <Route path="/pharmacy/dispensing" element={<PharmacyDispensingPage />} />
          <Route path="/pharmacy/notifications" element={<NotificationPage />} />
        </Route>
      </Route>

      <Route element={<ProtectedRoute allowedRole="Cashier/Billing Staff" />}>
        <Route path="/billing" element={<Navigate to="/billing/dashboard" replace />} />
        <Route element={<DashboardLayout menuItems={BILLING_MENU_ITEMS} />}>
          <Route path="/billing/dashboard" element={<BillingDashboardPage />} />
          <Route path="/billing/queue" element={<BillingQueuePage />} />
          <Route path="/billing/pending-payments" element={<PendingPaymentsPage />} />
          <Route path="/billing/notifications" element={<NotificationPage />} />
        </Route>
      </Route>

      <Route path="/unauthorized" element={<div className="p-10">You do not have access to that page.</div>} />
      <Route path="/" element={<RoleHomeRedirect />} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
