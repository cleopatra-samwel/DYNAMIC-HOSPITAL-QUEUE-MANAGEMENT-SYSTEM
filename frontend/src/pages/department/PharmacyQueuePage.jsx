import DepartmentQueuePage from './DepartmentQueuePage';

// The Pharmacy "Queue" sidebar item: "Call" once the patient has paid at the
// cashier (disabled until then), then a disabled "Called" label. The actual
// dispensing happens on the "Dispensing" page (PharmacyDispensingPage).
export default function PharmacyQueuePage() {
  return <DepartmentQueuePage deptCode="PHARM" showPaymentStatus actionMode="call-only" />;
}
