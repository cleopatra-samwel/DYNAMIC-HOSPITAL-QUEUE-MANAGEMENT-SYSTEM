import DepartmentQueuePage from './DepartmentQueuePage';
import NowServingBanner from '../../components/NowServingBanner';
import { ageFromDob } from '../../utils/age';

const LAB_EXTRA_COLUMNS = [
  { title: 'Patient ID', render: (_, t) => t.service?.visit?.patient?.patient_number || '—' },
  { title: 'Age', render: (_, t) => ageFromDob(t.service?.visit?.patient?.date_of_birth) ?? '—' },
  { title: 'Gender', render: (_, t) => t.service?.visit?.patient?.gender || '—' },
  { title: 'Referring Doctor', render: (_, t) => t.referring_doctor || '—' },
  { title: 'Requested Tests', render: (_, t) => t.requested_test_names || '—' },
];

// The "Perform Test" sidebar item — a ticket must already be Called (via
// the "Queue" page) before it can be reviewed here. "Review" opens the
// patient's details plus the doctor's requested tests/notes, with a
// Forward button (inside that form) to send it on to the relevant
// department once results are recorded.
export default function LaboratoryPerformTestPage() {
  return (
    <>
      <NowServingBanner deptCode="LAB" />
      <DepartmentQueuePage deptCode="LAB" showPaymentStatus extraColumns={LAB_EXTRA_COLUMNS} actionMode="review-only" secondaryActionLabel="Review" />
    </>
  );
}
