import { useEffect, useState } from 'react';
import { Button, Form, Input, InputNumber, Modal, Select, Switch, Table, Tag, message } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import apiClient from '../../services/apiClient';
import { LAB_TEST_CATEGORIES } from '../../utils/labTestCategories';

/**
 * Administrator-only. The price list Cashier/Billing Staff selects from
 * when building an itemized Laboratory charge (see PendingPaymentsPage) —
 * same list/add/edit pattern as AudioClipLibraryPage, for consistency.
 */
export default function LabTestCatalogPage() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [saving, setSaving] = useState(false);
  const [form] = Form.useForm();

  const load = () => {
    setLoading(true);
    apiClient.get('/lab-test-catalog')
      .then(({ data }) => setItems(data.lab_tests))
      .catch(() => message.error('Could not load the lab test catalog.'))
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
    form.setFieldsValue({ name: item.name, category: item.category || undefined, price: Number(item.price), active: item.active });
    setModalOpen(true);
  };

  const submit = async () => {
    try {
      const values = await form.validateFields();
      setSaving(true);
      if (editing) {
        await apiClient.put(`/lab-test-catalog/${editing.id}`, values);
        message.success(`"${values.name}" updated.`);
      } else {
        await apiClient.post('/lab-test-catalog', { name: values.name, category: values.category, price: values.price });
        message.success(`"${values.name}" added.`);
      }
      setModalOpen(false);
      load();
    } catch (error) {
      if (error?.errorFields) return;
      message.error(error.response?.data?.message || 'Could not save this lab test.');
    } finally {
      setSaving(false);
    }
  };

  const toggleActive = async (item) => {
    try {
      await apiClient.put(`/lab-test-catalog/${item.id}`, { active: !item.active });
      load();
    } catch {
      message.error('Could not update this lab test.');
    }
  };

  const columns = [
    { title: 'Name', dataIndex: 'name' },
    { title: 'Category', dataIndex: 'category', render: (v) => (v ? <Tag>{v}</Tag> : <Tag color="default">Other</Tag>) },
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
        <Button type="primary" icon={<PlusOutlined />} onClick={openAdd}>Add Lab Test</Button>
      </div>

      <Table rowKey="id" columns={columns} dataSource={items} loading={loading} />

      <Modal
        title={editing ? `Edit — ${editing.name}` : 'Add Lab Test'}
        open={modalOpen}
        onCancel={() => setModalOpen(false)}
        onOk={submit}
        confirmLoading={saving}
        okText="Save"
        destroyOnHidden
      >
        <Form form={form} layout="vertical">
          <Form.Item label="Name" name="name" rules={[{ required: true, message: 'Name is required' }]}>
            <Input placeholder="e.g. CBC, Platelet Count" />
          </Form.Item>
          <Form.Item label="Category" name="category">
            <Select allowClear placeholder="No category (falls under 'Other')" options={LAB_TEST_CATEGORIES.map((c) => ({ label: c, value: c }))} />
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
