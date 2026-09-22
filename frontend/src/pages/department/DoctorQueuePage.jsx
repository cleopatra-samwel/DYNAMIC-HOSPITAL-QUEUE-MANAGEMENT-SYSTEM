import DepartmentQueuePage from './DepartmentQueuePage';
import NowServingBanner from '../../components/NowServingBanner';

// Reused verbatim by Registration Queue/Laboratory Queue/New Patients
// sidebar items (see AppRoutes.jsx) — all four show the exact same CONS
// queue, just under different labels/paths. secondaryActionLabel lets the
// Laboratory Queue route say "Result" instead of the default "Consult",
// since that's where the doctor opens the form to read back lab results.
export default function DoctorQueuePage({ secondaryActionLabel = 'Consult' }) {
  return (
    <>
      <NowServingBanner deptCode="CONS" />
      <DepartmentQueuePage deptCode="CONS" showPaymentStatus actionMode="call-and-secondary" secondaryActionLabel={secondaryActionLabel} />
    </>
  );
}
