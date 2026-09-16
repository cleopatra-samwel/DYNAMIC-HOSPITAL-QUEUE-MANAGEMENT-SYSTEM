import { useEffect, useState } from 'react';
import { Button, Checkbox, Modal, Table, Tag, message } from 'antd';
import { EditOutlined } from '@ant-design/icons';
import apiClient from '../../services/apiClient';

/**
 * Administrator's "Users & Roles" page — grant/revoke which roles a
 * staff account holds. Deliberately minimal, per spec: no create-user,
 * no password reset here, just role assignment on existing accounts.
 * This is what populates each user's OWN role list for their sidebar
 * dropdown (see DashboardLayout's role Select).
 */
export default function UsersRolesPage() {
  const [users, setUsers] = useState([]);
  const [availableRoles, setAvailableRoles] = useState([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState(null);
  const [selectedRoles, setSelectedRoles] = useState([]);
  const [saving, setSaving] = useState(false);

  const load = () => {
    setLoading(true);
    apiClient.get('/users')
      .then(({ data }) => { setUsers(data.users); setAvailableRoles(data.available_roles); })
      .catch(() => message.error('Could not load users.'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  const openEdit = (user) => {
    setEditing(user);
    setSelectedRoles(user.roles);
  };

  const submit = async () => {
    if (selectedRoles.length === 0) {
      message.warning('At least one role is required.');
      return;
    }
    setSaving(true);
    try {
      await apiClient.patch(`/users/${editing.id}/roles`, { roles: selectedRoles });
      message.success(`Roles updated for ${editing.name}.`);
      setEditing(null);
      load();
    } catch (error) {
      message.error(error.response?.data?.message || 'Could not update roles.');
    } finally {
      setSaving(false);
    }
  };

  const columns = [
    { title: 'Name', dataIndex: 'name' },
    { title: 'Email', dataIndex: 'email' },
    {
      title: 'Roles',
      dataIndex: 'roles',
      render: (roles) => roles.map((role) => <Tag key={role} color="blue">{role}</Tag>),
    },
    {
      title: 'Action',
      render: (_, user) => <Button size="small" icon={<EditOutlined />} onClick={() => openEdit(user)}>Edit Roles</Button>,
    },
  ];

  return (
    <div>
      <Table rowKey="id" columns={columns} dataSource={users} loading={loading} />

      <Modal
        title={editing ? `Edit Roles — ${editing.name}` : ''}
        open={!!editing}
        onCancel={() => setEditing(null)}
        onOk={submit}
        confirmLoading={saving}
        okText="Save"
        destroyOnHidden
      >
        <Checkbox.Group
          style={{ display: 'flex', flexDirection: 'column', gap: 8 }}
          value={selectedRoles}
          onChange={setSelectedRoles}
          options={availableRoles.map((role) => ({ label: role, value: role }))}
        />
      </Modal>
    </div>
  );
}
