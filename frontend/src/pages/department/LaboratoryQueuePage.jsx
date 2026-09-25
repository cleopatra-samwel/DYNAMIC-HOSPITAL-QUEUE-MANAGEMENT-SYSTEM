import DepartmentQueuePage from './DepartmentQueuePage';
import NowServingBanner from '../../components/NowServingBanner';
import { ageFromDob } from '../../utils/age';

const LAB_EXTRA_COLUMNS = [
  { title: 'Patient ID', render: (_, t) => t.service?.visit?.patient?.patient_number || '—' },
  { title: 'Age', render: (_, t) => ageFromDob(t.service?.visit?.patient?.date_of_birth) ?? '—' },
  { title: 'Gender', render: (_, t) => t.service?.visit?.patient?.gender || '—' },
  // Both computed server-side (QueueTicketController::attachLabExtras) —
  // Service.doctor_id is only ever an optional pre-selection, never who
  // actually treated the patient, so this is derived from the originating
  // Consultation ticket's CALLED event instead.
  { title: 'Referring Doctor', render: (_, t) => t.referring_doctor || '—' },
  { title: 'Requested Tests', render: (_, t) => t.requested_test_names || '—' },
];

// The "Queue" sidebar item only — just calls patients in. Actually
// reviewing/recording results happens on the separate "Perform Test" page
// (LaboratoryPerformTestPage.jsx) once a ticket has been called here.
export default function LaboratoryQueuePage() {
  return (
    <>
      <NowServingBanner deptCode="LAB" />
      <DepartmentQueuePage deptCode="LAB" extraColumns={LAB_EXTRA_COLUMNS} actionMode="call-only" />
    </>
  );
}
