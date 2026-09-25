import DepartmentQueuePage from './DepartmentQueuePage';

// The Pharmacy "Dispensing" sidebar item — a patient must already be Called
// (via the "Queue" page). "Review" opens the dispensing form: check the
// prescription, mark each medicine handed over, get the patient's signature,
// then complete.
export default function PharmacyDispensingPage() {
  return <DepartmentQueuePage deptCode="PHARM" showPaymentStatus actionMode="review-only" secondaryActionLabel="Review" />;
}
