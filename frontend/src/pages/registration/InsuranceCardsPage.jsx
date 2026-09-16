import { useEffect, useState } from 'react';
import { Button, Empty, Form, Input, Modal, Spin, Table, Tabs, Tag, message } from 'antd';
import dayjs from 'dayjs';
import apiClient from '../../services/apiClient';
import { formatStatusLabel } from '../../utils/formatLabel';

export default function InsuranceCardsPage() {
  const [tab, setTab] = useState('pending_receipt');
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [releasing, setReleasing] = useState(null);
  const [saving, setSaving] = useState(false);
  const [confirmingVisitId, setConfirmingVisitId] = useState(null);
  const [form] = Form.useForm();

  const load = (status = tab) => {
    setLoading(true);
    const request = status === 'pending_receipt'
      ? apiClient.get('/insurance-cards/pending-receipt').then(({ data }) => data.pending_receipt)
      : apiClient.get('/insurance-cards', { params: { status } }).then(({ data }) => data.insurance_cards);

    request
      .then(setRows)
      .catch(() => message.error('Could not load insurance cards.'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [tab]);

  // Explicit staff confirmation that the card is physically in hand —
  // never automatic on registration. See PatientsPage's handleCreate.
  const confirmReceived = async (visitId) => {
    try {
      setConfirmingVisitId(visitId);
      await apiClient.post(`/visits/${visitId}/insurance-card`);
      message.success('Insurance card receipt confirmed.');
      load();
    } catch (error) {
      message.error(error.response?.data?.message || 'Could not confirm card receipt.');
    } finally {
      setConfirmingVisitId(null);
    }
  };

  const openRelease = (card) => {
    form.resetFields();
    setReleasing(card);
  };

  const submitRelease = async () => {
    try {
      const values = await form.validateFields();
      setSaving(true);
      await apiClient.patch(`/visits/${releasing.visit_id}/insurance-card/release`, values);
      message.success(`Card released for ${releasing.visit?.patient?.name}.`);
      setReleasing(null);
      load();
    } catch (error) {
      if (error?.errorFields) return;
      message.error(error.response?.data?.message || 'Could not release this card.');
    } finally {
      setSaving(false);
    }
  };

  const pendingReceiptColumns = [
    { title: 'Patient', dataIndex: 'patient_name', render: (v) => v || '—' },
    { title: 'Patient No.', dataIndex: 'patient_number', render: (v) => v || '—' },
    { title: 'Insurance Provider', dataIndex: 'insurance_provider', render: (v) => v || '—' },
    { title: 'Card No.', dataIndex: 'insurance_ref', render: (v) => v || '—' },
    { title: 'Registered At', dataIndex: 'created_at', render: (v) => dayjs(v).format('DD MMM YYYY, HH:mm') },
    {
      title: 'Action',
      render: (_, r) => (
        <Button
          size="small"
          type="primary"
          loading={confirmingVisitId === r.visit_id}
          onClick={() => confirmReceived(r.visit_id)}
        >
          Confirm Card Received
        </Button>
      ),
    },
  ];

  const custodyColumns = [
    { title: 'Patient', render: (_, c) => c.visit?.patient?.name || '—' },
    { title: 'Patient No.', render: (_, c) => c.visit?.patient?.patient_number || '—' },
    { title: 'Received By', render: (_, c) => c.received_by?.name || '—' },
    { title: 'Received At', dataIndex: 'received_at', render: (v) => dayjs(v).format('DD MMM YYYY, HH:mm') },
    { title: 'Visit Status', render: (_, c) => <Tag color={c.visit?.overall_status === 'COMPLETED' ? 'green' : 'orange'}>{c.visit?.overall_status ? formatStatusLabel(c.visit.overall_status) : '—'}</Tag> },
    {
      title: 'Action',
      render: (_, c) => (
        <Button
          size="small"
          type="primary"
          disabled={c.visit?.overall_status !== 'COMPLETED'}
          onClick={() => openRelease(c)}
        >
          Release Card
        </Button>
      ),
    },
  ];

  const releasedColumns = [
    { title: 'Patient', render: (_, c) => c.visit?.patient?.name || '—' },
    { title: 'Patient No.', render: (_, c) => c.visit?.patient?.patient_number || '—' },
    { title: 'Received By', render: (_, c) => c.received_by?.name || '—' },
    { title: 'Released By', render: (_, c) => c.released_by?.name || '—' },
    { title: 'Released At', dataIndex: 'released_at', render: (v) => dayjs(v).format('DD MMM YYYY, HH:mm') },
    { title: 'Sign-off', dataIndex: 'signoff_confirmation' },
  ];

  return (
    <div>
      <Tabs
        activeKey={tab}
        onChange={setTab}
        items={[
          { key: 'pending_receipt', label: 'Pending Receipt' },
          { key: 'in_custody', label: 'In Custody' },
          { key: 'released', label: 'Released' },
        ]}
      />
      {loading ? <Spin /> : rows.length === 0
        ? <Empty description="Nothing here yet" />
        : (
          <Table
            rowKey={tab === 'pending_receipt' ? 'visit_id' : 'id'}
            columns={tab === 'pending_receipt' ? pendingReceiptColumns : tab === 'in_custody' ? custodyColumns : releasedColumns}
            dataSource={rows}
            pagination={false}
          />
        )}

      <Modal
        title={releasing ? `Release Card — ${releasing.visit?.patient?.name}` : ''}
        open={!!releasing}
        onCancel={() => setReleasing(null)}
        onOk={submitRelease}
        confirmLoading={saving}
        okText="Release"
        destroyOnHidden
      >
        <Form form={form} layout="vertical">
          <Form.Item
            label="Sign-off Confirmation"
            name="signoff_confirmation"
            rules={[{ required: true, message: 'A sign-off confirmation is required' }]}
          >
            <Input.TextArea rows={3} placeholder="e.g. Card returned to patient in person." />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  );
}
