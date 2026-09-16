import DepartmentStatsPage from './DepartmentStatsPage';

export default function DoctorDashboardPage() {
  return (
    <DepartmentStatsPage
      deptCode="CONS"
      statCards={[
        { key: 'waiting', label: 'Waiting', path: '/doctor/queue' },
        { key: 'in_service', label: 'In Consultation', path: '/doctor/queue' },
        { key: 'emergency_unconfirmed', label: 'Emergency Pending Confirmation', path: '/doctor/emergency' },
        { key: 'completed_today', label: 'Completed Today', path: '/doctor/queue' },
      ]}
    />
  );
}
