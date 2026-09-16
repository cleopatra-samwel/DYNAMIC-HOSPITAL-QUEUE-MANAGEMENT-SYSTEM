import { useEffect, useState } from 'react';
import { Button, Form, Input, InputNumber, Modal, Switch, Table, message } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import apiClient from '../../services/apiClient';

/** Administrator-only. Same pattern as LabTestCatalogPage — the price list Cashier/Billing Staff selects from for an itemized Pharmacy charge. */
export default function MedicationCatalogPage() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [saving, setSaving] = useState(false);
  const [form] = Form.useForm();

  const load = () => {
    setLoading(true);
    apiClient.get('/medication-catalog')
      .then(({ data }) => setItems(data.medications))
      .catch(() => message.error('Could not load the medication catalog.'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  const openAdd = () => {
    setEditing(null);
    form.resetFields();
    setModalOpen(true);
  };

  const openEdit = (item) => {
    setEditing(item);
    form.setFieldsValue({ name: item.name, price: Number(item.price), active: item.active });
    setModalOpen(true);
  };

  const submit = async () => {
    try {
      const values = await form.validateFields();
      setSaving(true);
      if (editing) {
        await apiClient.put(`/medication-catalog/${editing.id}`, values);
        message.success(`"${values.name}" updated.`);
      } else {
        await apiClient.post('/medication-catalog', { name: values.name, price: values.price });
        message.success(`"${values.name}" added.`);
      }
      setModalOpen(false);
      load();
    } catch (error) {
      if (error?.errorFields) return;
      message.error(error.response?.data?.message || 'Could not save this medication.');
    } finally {
      setSaving(false);
    }
  };

  const toggleActive = async (item) => {
    try {
      await apiClient.put(`/medication-catalog/${item.id}`, { active: !item.active });
      load();
    } catch {
      message.error('Could not update this medication.');
    }
  };

  const columns = [
    { title: 'Name', dataIndex: 'name' },
    { title: 'Price', dataIndex: 'price', render: (v) => Number(v).toLocaleString() },
    {
      title: 'Active',
      dataIndex: 'active',
      render: (active, item) => <Switch checked={active} onChange={() => toggleActive(item)} />,
    },
    {
      title: 'Action',
      render: (_, item) => <Button size="small" onClick={() => openEdit(item)}>Edit</Button>,
    },
  ];

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}>
        <Button type="primary" icon={<PlusOutlined />} onClick={openAdd}>Add Medication</Button>
      </div>

      <Table rowKey="id" columns={columns} dataSource={items} loading={loading} />

      <Modal
        title={editing ? `Edit — ${editing.name}` : 'Add Medication'}
        open={modalOpen}
        onCancel={() => setModalOpen(false)}
        onOk={submit}
        confirmLoading={saving}
        okText="Save"
        destroyOnHidden
      >
        <Form form={form} layout="vertical">
          <Form.Item label="Name" name="name" rules={[{ required: true, message: 'Name is required' }]}>
            <Input placeholder="e.g. Paracetamol 500mg" />
          </Form.Item>
          <Form.Item label="Price" name="price" rules={[{ required: true, message: 'Price is required' }]}>
            <InputNumber style={{ width: '100%' }} min={0} precision={2} />
          </Form.Item>
          {editing && (
            <Form.Item label="Active" name="active" valuePropName="checked">
              <Switch />
            </Form.Item>
          )}
        </Form>
      </Modal>
    </div>
  );
}
