import { useEffect, useState } from 'react';
import { Alert, Button, Divider, Empty, Form, Input, InputNumber, Modal, Select, Space, Spin, Table, Tag, Typography, message } from 'antd';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import apiClient from '../../services/apiClient';
import { getEcho } from '../../services/echo';
import useConnectionState from '../../hooks/useConnectionState';
import { ConnectedBadge } from '../../components/ConnectionIndicator';

const { Text } = Typography;
const BILLABLE_DEPT_CODES = ['CONS', 'LAB', 'PHARM'];

/** Every service across Consultation/Laboratory/Pharmacy still awaiting a verified payment — charged by selecting itemized catalog entries (or a manual "Other" line) instead of typing one flat amount. */
export default function PendingPaymentsPage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [target, setTarget] = useState(null);
  const [saving, setSaving] = useState(false);
  const [billableDeptIds, setBillableDeptIds] = useState([]);
  const [labTests, setLabTests] = useState([]);
  const [medications, setMedications] = useState([]);
  const [items, setItems] = useState([]);
  const [catalogSelection, setCatalogSelection] = useState(null);
  const [otherLabel, setOtherLabel] = useState('');
  const [otherAmount, setOtherAmount] = useState(null);
  const [form] = Form.useForm();

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
    apiClient.get('/lab-test-catalog').then(({ data }) => setLabTests(data.lab_tests.filter((t) => t.active))).catch(() => {});
    apiClient.get('/medication-catalog').then(({ data }) => setMedications(data.medications.filter((m) => m.active))).catch(() => {});
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

  const catalogForRow = (row) => (row.dept_code === 'LAB' ? labTests : row.dept_code === 'PHARM' ? medications : []);
  const catalogTypeForRow = (row) => (row.dept_code === 'LAB' ? 'lab_test' : row.dept_code === 'PHARM' ? 'medication' : null);

  const openVerify = (row) => {
    form.resetFields();
    form.setFieldsValue({ method: row.payment_method });
    // Convenient default for Laboratory: pre-select whatever the Doctor
    // ticked on the Request Laboratory checklist — still freely
    // add/removable below, in case what was actually performed differs
    // from what was originally requested.
    const preSelected = row.dept_code === 'LAB'
      ? labTests
        .filter((t) => row.requested_test_catalog_ids?.includes(t.id))
        .map((t) => ({
          key: `lab_test-${t.id}-${Date.now()}`,
          catalog_type: 'lab_test',
          catalog_item_id: t.id,
          custom_label: null,
          label: t.name,
          amount: Number(t.price),
        }))
      : [];
    setItems(preSelected);
    setCatalogSelection(null);
    setOtherLabel('');
    setOtherAmount(null);
    setTarget(row);
  };

  const addCatalogItem = () => {
    const catalog = catalogForRow(target);
    const entry = catalog.find((c) => c.id === catalogSelection);
    if (!entry) return;
    setItems((prev) => [...prev, {
      key: `${catalogTypeForRow(target)}-${entry.id}-${Date.now()}`,
      catalog_type: catalogTypeForRow(target),
      catalog_item_id: entry.id,
      custom_label: null,
      label: entry.name,
      amount: Number(entry.price),
    }]);
    setCatalogSelection(null);
  };

  const addOtherItem = () => {
    if (!otherLabel || otherAmount === null) return;
    setItems((prev) => [...prev, {
      key: `other-${Date.now()}`,
      catalog_type: 'other',
      catalog_item_id: null,
      custom_label: otherLabel,
      label: otherLabel,
      amount: Number(otherAmount),
    }]);
    setOtherLabel('');
    setOtherAmount(null);
  };

  const removeItem = (key) => setItems((prev) => prev.filter((i) => i.key !== key));

  const total = items.reduce((sum, i) => sum + i.amount, 0);

  const submitVerify = async () => {
    try {
      const values = await form.validateFields();
      if (items.length === 0) {
        message.warning('Add at least one item before verifying.');
        return;
      }
      setSaving(true);
      await apiClient.post(`/services/${target.service_id}/payments/verify`, {
        ...values,
        items: items.map(({ catalog_type, catalog_item_id, custom_label, amount }) => ({ catalog_type, catalog_item_id, custom_label, amount })),
      });
      message.success(`Payment verified for ${target.patient_name} (Total: ${total.toLocaleString()}).`);
      setTarget(null);
      load();
    } catch (error) {
      if (error?.errorFields) return;
      message.error(error.response?.data?.message || 'Could not verify payment.');
    } finally {
      setSaving(false);
    }
  };

  const method = Form.useWatch('method', form);

  const columns = [
    { title: 'Patient', dataIndex: 'patient_name' },
    { title: 'Department', dataIndex: 'department' },
    { title: 'Payment Method', dataIndex: 'payment_method', render: (v) => <Tag color={v === 'Insurance' ? 'blue' : 'gold'}>{v}</Tag> },
    { title: 'Created', dataIndex: 'created_at', render: (v) => dayjs(v).format('DD MMM YYYY, HH:mm') },
    {
      title: 'Action',
      render: (_, row) => (
        <Button size="small" type="primary" onClick={() => openVerify(row)}>
          {row.payment_method === 'Insurance' ? 'Verify Insurance' : 'Confirm Payment'}
        </Button>
      ),
    },
  ];

  const itemColumns = [
    { title: 'Item', dataIndex: 'label' },
    { title: 'Amount', dataIndex: 'amount', render: (v) => v.toLocaleString() },
    {
      title: '',
      render: (_, item) => <Button size="small" danger icon={<DeleteOutlined />} onClick={() => removeItem(item.key)} />,
    },
  ];

  if (loading) return <Spin />;

  return (
    <div>
      <div style={{ marginBottom: 12 }}><ConnectedBadge state={connectionState} /></div>
      {rows.length === 0
        ? <Empty description="No pending payments" />
        : <Table rowKey="service_id" columns={columns} dataSource={rows} pagination={false} />}

      <Modal
        title={target ? `${target.payment_method === 'Insurance' ? 'Verify Insurance' : 'Confirm Payment'} — ${target.patient_name}` : ''}
        open={!!target}
        onCancel={() => setTarget(null)}
        onOk={submitVerify}
        confirmLoading={saving}
        okText={`Verify (Total: ${total.toLocaleString()})`}
        width={560}
        destroyOnHidden
      >
        {target && target.dept_code === 'LAB' && (target.requested_test_catalog_ids?.length > 0 || target.requested_tests_other) && (
          <Alert
            type="info"
            showIcon
            style={{ marginBottom: 16 }}
            message="Doctor requested"
            description={[
              ...labTests.filter((t) => target.requested_test_catalog_ids?.includes(t.id)).map((t) => t.name),
              target.requested_tests_other ? `Others: ${target.requested_tests_other}` : null,
            ].filter(Boolean).join(', ')}
          />
        )}

        {target && target.dept_code !== 'LAB' && (target.final_diagnosis || target.treatment_plan) && (
          <Alert
            type="info"
            showIcon
            style={{ marginBottom: 16 }}
            message="Prescribed / diagnosis"
            description={[target.final_diagnosis, target.treatment_plan].filter(Boolean).join(' — ')}
          />
        )}

        <Form form={form} layout="vertical">
          <Form.Item name="method" hidden><Input /></Form.Item>
          {method === 'Insurance' && (
            <>
              <Form.Item label="Insurance Provider" name="insurance_provider" rules={[{ required: true, message: 'Insurance provider is required' }]}>
                <Input />
              </Form.Item>
              <Form.Item label="Insurance Ref/Claim No." name="insurance_ref" rules={[{ required: true, message: 'Insurance reference is required' }]}>
                <Input />
              </Form.Item>
            </>
          )}
        </Form>

        <Divider orientation="left" plain>Charge Items</Divider>

        {target && catalogTypeForRow(target) && (
          <Space.Compact style={{ width: '100%', marginBottom: 8 }}>
            <Select
              showSearch
              style={{ width: '70%' }}
              placeholder={target.dept_code === 'LAB' ? 'Search lab tests…' : 'Search medications…'}
              value={catalogSelection}
              onChange={setCatalogSelection}
              optionFilterProp="label"
              options={catalogForRow(target).map((c) => ({ label: `${c.name} — ${Number(c.price).toLocaleString()}`, value: c.id }))}
            />
            <Button icon={<PlusOutlined />} onClick={addCatalogItem} disabled={!catalogSelection}>Add</Button>
          </Space.Compact>
        )}

        <Space.Compact style={{ width: '100%', marginBottom: 16 }}>
          <Input
            style={{ width: '45%' }}
            placeholder="Other — custom label"
            value={otherLabel}
            onChange={(e) => setOtherLabel(e.target.value)}
          />
          <InputNumber
            style={{ width: '30%' }}
            placeholder="Amount"
            min={0}
            precision={2}
            value={otherAmount}
            onChange={setOtherAmount}
          />
          <Button icon={<PlusOutlined />} onClick={addOtherItem} disabled={!otherLabel || otherAmount === null}>Add</Button>
        </Space.Compact>

        {items.length === 0 ? (
          <Empty description="No items added yet" image={Empty.PRESENTED_IMAGE_SIMPLE} />
        ) : (
          <Table rowKey="key" size="small" pagination={false} columns={itemColumns} dataSource={items} />
        )}

        <div style={{ textAlign: 'right', marginTop: 12 }}>
          <Text strong>Total: {total.toLocaleString()}</Text>
        </div>
      </Modal>
    </div>
  );
}
