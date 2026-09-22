import DepartmentQueuePage from './DepartmentQueuePage';

// "Call" while waiting, then "Called" + "Dispense" once called — Dispense
// silently starts service (if needed) so TicketViewModal opens straight on
// the PHARM medicine/dispensing form instead of stopping on an intermediate
// "Start Service" screen.
export default function PharmacyQueuePage() {
  return (
    <DepartmentQueuePage deptCode="PHARM" showPaymentStatus actionMode="call-and-secondary" secondaryActionLabel="Dispense" />
  );
}
