import DepartmentStatsPage from './DepartmentStatsPage';

export default function DoctorDashboardPage() {
  return (
    <DepartmentStatsPage
      deptCode="CONS"
      statCards={[
        { key: 'from_registration', label: 'Patients from Registration', path: '/doctor/registration-queue' },
        { key: 'lab_results_ready', label: 'Laboratory Results Ready', path: '/doctor/laboratory-queue' },
        { key: 'completed_today', label: 'Completed Today', path: '/doctor/queue' },
      ]}
    />
  );
}
