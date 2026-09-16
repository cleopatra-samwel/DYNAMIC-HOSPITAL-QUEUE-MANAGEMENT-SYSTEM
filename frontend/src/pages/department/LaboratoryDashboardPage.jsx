import DepartmentStatsPage from './DepartmentStatsPage';

export default function LaboratoryDashboardPage() {
  return (
    <DepartmentStatsPage
      deptCode="LAB"
      statCards={[
        { key: 'waiting', label: 'Waiting', path: '/laboratory/queue' },
        { key: 'in_service', label: 'In Service', path: '/laboratory/queue' },
        { key: 'completed_today', label: 'Completed Today', path: '/laboratory/test-results' },
      ]}
    />
  );
}
