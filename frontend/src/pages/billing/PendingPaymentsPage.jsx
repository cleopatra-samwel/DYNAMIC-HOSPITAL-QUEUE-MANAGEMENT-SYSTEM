import { useEffect, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { Button, Empty, Spin, Table, Tag, message } from 'antd';
import dayjs from 'dayjs';
import apiClient from '../../services/apiClient';
import { getEcho } from '../../services/echo';
import useConnectionState from '../../hooks/useConnectionState';
import { ConnectedBadge } from '../../components/ConnectionIndicator';
import CashierBillingModal from './CashierBillingModal';

const BILLABLE_DEPT_CODES = ['CONS', 'LAB', 'PHARM'];

/** Every service across Consultation/Laboratory/Pharmacy still awaiting a verified payment. "Confirm Payment" opens the Cashier Billing form (CashierBillingModal). */
export default function PendingPaymentsPage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [target, setTarget] = useState(null);
  const [billableDeptIds, setBillableDeptIds] = useState([]);
  const [labTests, setLabTests] = useState([]);
  const [medications, setMedications] = useState([]);
  const [catalogsLoaded, setCatalogsLoaded] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();
  const autoOpenedRef = useRef(false);

  const load = () => {
    setLoading(true);
    apiClient.get('/payments/pending')
      .then(({ data }) => setRows(data.pending))
      .catch(() => message.error('Could not load pending payments.'))
      .finally(() => setLoading(false));
  };

  const connectionState = useConnectionState(load);

  useEffect(() => {
    load();
    apiClient.get('/departments').then(({ data }) => {
      setBillableDeptIds(data.departments.filter((d) => BILLABLE_DEPT_CODES.includes(d.dept_code)).map((d) => d.id));
    }).catch(() => {});
    Promise.allSettled([
      apiClient.get('/lab-test-catalog').then(({ data }) => setLabTests(data.lab_tests.filter((t) => t.active))),
      apiClient.get('/medication-catalog').then(({ data }) => setMedications(data.medications.filter((m) => m.active))),
    ]).then(() => setCatalogsLoaded(true));
  }, []);

  // Someone else verifying a payment (another Billing device, or a second
  // browser tab) must drop that row from this list immediately — this
  // list spans all three billable departments, so it subscribes to all
  // three private channels rather than just one.
  useEffect(() => {
    if (billableDeptIds.length === 0) return undefined;

    const echo = getEcho();
    const channels = billableDeptIds.map((id) => echo.private(`department.${id}`));

    const removeVerifiedRow = (payload) => {
      setRows((prev) => prev.filter((row) => row.service_id !== payload.service_id));
    };

    channels.forEach((channel) => channel.listen('.PaymentVerified', removeVerifiedRow));

    return () => billableDeptIds.forEach((id) => echo.leave(`department.${id}`));
  }, [billableDeptIds]);

  // Arriving from the Billing Queue's "Verify Payment" button: open the
  // billing form for that ticket's service once its row (and, for Laboratory
  // / Pharmacy, the catalog used to pre-select items) has loaded.
  useEffect(() => {
    const serviceId = location.state?.serviceId;
    if (!serviceId || autoOpenedRef.current || loading) return;

    const row = rows.find((r) => r.service_id === serviceId);
    if (!row) return;

    if (!catalogsLoaded) return;

    autoOpenedRef.current = true;
    setTarget(row);
  }, [rows, loading, catalogsLoaded, location.state]);

  // Called by the billing form: refresh the list if a payment went through,
  // and optionally go back to the Billing Queue.
  const closeBilling = (paid, backToQueue) => {
    setTarget(null);
    if (paid) load();
    if (backToQueue) navigate('/billing/queue');
  };

  const columns = [
    { title: 'Patient', dataIndex: 'patient_name' },
    { title: 'Department', dataIndex: 'department' },
    { title: 'Payment Method', dataIndex: 'payment_method', render: (v) => <Tag color={v === 'Insurance' ? 'blue' : 'gold'}>{v}</Tag> },
    { title: 'Created', dataIndex: 'created_at', render: (v) => dayjs(v).format('DD MMM YYYY, HH:mm') },
    {
      title: 'Action',
      render: (_, row) => (
        <Button size="small" type="primary" onClick={() => setTarget(row)}>
          {row.payment_method === 'Insurance' ? 'Verify Insurance' : 'Confirm Payment'}
        </Button>
      ),
    },
  ];

  // Only the very first load shows a bare spinner — a background refresh
  // must never unmount the billing form mid-payment.
  if (loading && !target) return <Spin />;

  return (
    <div>
      <div style={{ marginBottom: 12 }}><ConnectedBadge state={connectionState} /></div>
      {rows.length === 0
        ? <Empty description="No pending payments" />
        : <Table rowKey="service_id" columns={columns} dataSource={rows} pagination={false} />}

      <CashierBillingModal target={target} labTests={labTests} medications={medications} onClose={closeBilling} />
    </div>
  );
}
