import { useSelector } from 'react-redux';
import { Card, Descriptions } from 'antd';

/** Read-only account info from Redux (state.auth.user, from the login/me response) — no API calls of its own. */
export default function DoctorSettingsPage() {
  const user = useSelector((state) => state.auth.user);

  return (
    <Card title="Account" size="small">
      <Descriptions column={1} bordered size="small">
        <Descriptions.Item label="Name">{user?.name || '—'}</Descriptions.Item>
        <Descriptions.Item label="Email">{user?.email || '—'}</Descriptions.Item>
        <Descriptions.Item label="Roles">{user?.roles?.join(', ') || '—'}</Descriptions.Item>
        <Descriptions.Item label="Active Role">{user?.active_role || '—'}</Descriptions.Item>
      </Descriptions>
    </Card>
  );
}
