import DepartmentStatsPage from './DepartmentStatsPage';

export default function LaboratoryDashboardPage() {
  return (
    <DepartmentStatsPage
      deptCode="LAB"
      statCards={[
        { key: 'waiting', label: 'Patients Waiting', path: '/laboratory/queue' },
        { key: 'pending_tests', label: 'Pending Tests', path: '/laboratory/perform-test' },
        { key: 'completed_today', label: 'Completed Tests', path: '/laboratory/queue' },
      ]}
    />
  );
}
