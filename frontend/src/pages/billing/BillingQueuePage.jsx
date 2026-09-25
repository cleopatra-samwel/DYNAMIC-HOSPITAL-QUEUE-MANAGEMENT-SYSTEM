import DepartmentQueuePage from '../department/DepartmentQueuePage';

// Cashier's own queue: "Call" while waiting, then "Called" + "Verify Payment",
// which opens Pending Payments on that ticket's service. "For" shows which
// department the patient is paying for.
const BILLING_EXTRA_COLUMNS = [
  { title: 'Paying For', render: (_, t) => t.service?.billing_for?.department?.dept_name || '—' },
];

export default function BillingQueuePage() {
  return <DepartmentQueuePage deptCode="BILL" actionMode="call-and-pay" extraColumns={BILLING_EXTRA_COLUMNS} />;
}
