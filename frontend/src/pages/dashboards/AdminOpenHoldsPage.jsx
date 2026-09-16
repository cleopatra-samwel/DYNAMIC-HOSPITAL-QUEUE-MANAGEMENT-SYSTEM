import { useEffect, useState } from 'react';
import { Alert, Badge, Button, Divider, Empty, Form, Input, InputNumber, Modal, Space, Spin, Table, Typography, message } from 'antd';
import dayjs from 'dayjs';
import apiClient from '../../services/apiClient';

const { Title } = Typography;

/**
 * Administrator-only visibility into every OnHold service older than the
 * threshold — so a Doctor's forgotten "I'll review this myself" hold
 * doesn't sit invisible forever. Nothing here auto-resolves anything; a
 * Doctor/Administrator still has to act via the service's resolve-hold
 * action (built into this same feature, not surfaced elsewhere yet).
 *
 * Doctor-Locked Tickets below is the same shape of problem for
 * registration's optional "Preferred Doctor" field: a ticket reserved for
 * a doctor who never calls it just sits WAITING, invisible to every other
 * doctor's callNext() — see PriorityEngine::callNext()'s doctor-scoping.
 * Same visibility pattern, plus a real action (unlike the holds table
 * above) since Administrator is the only one who CAN act on this one —
 * not even the assigned doctor may clear their own lock.
 */
export default function AdminOpenHoldsPage() {
  const [hours, setHours] = useState(2);
  const [holds, setHolds] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const [lockHours, setLockHours] = useState(2);
  const [locks, setLocks] = useState([]);
  const [locksLoading, setLocksLoading] = useState(true);
  const [locksError, setLocksError] = useState('');
  const [clearing, setClearing] = useState(null);
  const [saving, setSaving] = useState(false);
  const [form] = Form.useForm();

  const load = (h = hours) => {
    setLoading(true);
    apiClient.get('/services/open-holds', { params: { hours: h } })
      .then(({ data }) => setHolds(data.holds))
      .catch(() => setError('Could not load open holds.'))
      .finally(() => setLoading(false));
  };

  const loadLocks = (h = lockHours) => {
    setLocksLoading(true);
    apiClient.get('/services/doctor-locked', { params: { hours: h } })
      .then(({ data }) => setLocks(data.locks))
      .catch(() => setLocksError('Could not load doctor-locked tickets.'))
      .finally(() => setLocksLoading(false));
  };

  useEffect(() => {
    load();
    loadLocks();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const openClearDoctor = (lock) => {
    form.resetFields();
    setClearing(lock);
  };

  const submitClearDoctor = async () => {
    try {
      const values = await form.validateFields();
      setSaving(true);
      await apiClient.patch(`/services/${clearing.service_id}/clear-doctor`, { reason: values.reason });
      message.success(`Doctor assignment cleared for ticket ${clearing.ticket_number} — callable by any doctor again.`);
      setClearing(null);
      loadLocks();
    } catch (error) {
      if (error?.errorFields) return;
      message.error(error.response?.data?.message || 'Could not clear the doctor assignment.');
    } finally {
      setSaving(false);
    }
  };

  const holdColumns = [
    { title: 'Patient', dataIndex: 'patient_name' },
    { title: 'Department', dataIndex: 'department' },
    { title: 'Ticket', dataIndex: 'ticket_number' },
    { title: 'Held Since', dataIndex: 'held_since', render: (v) => dayjs(v).format('DD MMM YYYY, HH:mm') },
    { title: 'Hours Open', dataIndex: 'hours_open', render: (v) => <Badge color={v >= 6 ? 'red' : 'orange'} text={`${v}h`} /> },
  ];

  const lockColumns = [
    { title: 'Patient', dataIndex: 'patient_name' },
    { title: 'Department', dataIndex: 'department' },
    { title: 'Ticket', dataIndex: 'ticket_number' },
    { title: 'Assigned Doctor', dataIndex: 'doctor_name' },
    { title: 'Waiting Since', dataIndex: 'waiting_since', render: (v) => dayjs(v).format('DD MMM YYYY, HH:mm') },
    { title: 'Hours Waiting', dataIndex: 'hours_waiting', render: (v) => <Badge color={v >= 6 ? 'red' : 'orange'} text={`${v}h`} /> },
    {
      title: 'Action',
      render: (_, lock) => <Button size="small" onClick={() => openClearDoctor(lock)}>Clear Doctor</Button>,
    },
  ];

  return (
    <div>
      <Title level={5} style={{ marginTop: 0 }}>Open Holds</Title>
      <Space style={{ marginBottom: 16 }}>
        <span>Show holds older than</span>
        <InputNumber min={0} value={hours} onChange={(v) => { setHours(v ?? 0); load(v ?? 0); }} />
        <span>hours</span>
      </Space>
      {error && <Alert type="error" showIcon message={error} style={{ marginBottom: 16 }} />}
      {loading ? <Spin /> : holds.length === 0
        ? <Empty description="No forgotten holds right now" />
        : <Table rowKey="service_id" columns={holdColumns} dataSource={holds} pagination={false} />}

      <Divider />

      <Title level={5}>Doctor-Locked Tickets</Title>
      <Space style={{ marginBottom: 16 }}>
        <span>Show tickets waiting longer than</span>
        <InputNumber min={0} value={lockHours} onChange={(v) => { setLockHours(v ?? 0); loadLocks(v ?? 0); }} />
        <span>hours</span>
      </Space>
      {locksError && <Alert type="error" showIcon message={locksError} style={{ marginBottom: 16 }} />}
      {locksLoading ? <Spin /> : locks.length === 0
        ? <Empty description="No stale doctor-locked tickets right now" />
        : <Table rowKey="service_id" columns={lockColumns} dataSource={locks} pagination={false} />}

      <Modal
        title={clearing ? `Clear Doctor Assignment — ${clearing.ticket_number}` : ''}
        open={!!clearing}
        onCancel={() => setClearing(null)}
        onOk={submitClearDoctor}
        confirmLoading={saving}
        okText="Clear Doctor"
        destroyOnHidden
      >
        <p style={{ color: '#8c8c8c' }}>
          This ticket will become callable by any doctor in Consultation again — not just {clearing?.doctor_name}.
        </p>
        <Form form={form} layout="vertical">
          <Form.Item
            label="Reason"
            name="reason"
            rules={[{ required: true, message: 'A reason is required' }]}
          >
            <Input.TextArea rows={3} placeholder="e.g. Dr. X is on leave this week." />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  );
}
