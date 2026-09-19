import DepartmentQueuePage from './DepartmentQueuePage';
import NowServingBanner from '../../components/NowServingBanner';

// Reused verbatim by Registration Queue/Laboratory Queue/New Patients
// sidebar items (see AppRoutes.jsx) — all four show the exact same CONS
// queue, just under different labels/paths.
export default function DoctorQueuePage() {
  return (
    <>
      <NowServingBanner deptCode="CONS" />
      <DepartmentQueuePage deptCode="CONS" showPaymentStatus actionMode="call-and-secondary" />
    </>
  );
}
