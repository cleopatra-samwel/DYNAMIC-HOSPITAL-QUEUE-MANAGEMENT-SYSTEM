import DepartmentStatsPage from './DepartmentStatsPage';

export default function PharmacyDashboardPage() {
  return (
    <DepartmentStatsPage
      deptCode="PHARM"
      statCards={[
        { key: 'waiting', label: 'Waiting', path: '/pharmacy/queue' },
        { key: 'completed_today', label: 'Dispensed Today', path: '/pharmacy/queue' },
        { key: 'pending_payment', label: 'Pending Payment', path: '/pharmacy/queue' },
      ]}
    />
  );
}
