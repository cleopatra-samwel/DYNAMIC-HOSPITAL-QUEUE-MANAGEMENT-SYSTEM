import { useEffect, useState } from 'react';
import { Alert, Spin, Typography } from 'antd';
import { useSelector } from 'react-redux';
import apiClient from '../../services/apiClient';

const { Paragraph } = Typography;

/**
 * Placeholder body for every role dashboard until each phase builds real
 * screens on top of it (Registration form in Phase 2, queue list in
 * Phase 3, etc). Pinging the role-guarded endpoint here doubles as a
 * live proof that role-based API access control is actually working,
 * not just hidden in the UI.
 */
export default function DashboardHome({ pingSlug }) {
  const user = useSelector((state) => state.auth.user);
  const [status, setStatus] = useState('loading');
  const [message, setMessage] = useState('');

  useEffect(() => {
    let cancelled = false;
    apiClient
      .get(`/${pingSlug}/ping`)
      .then(({ data }) => {
        if (!cancelled) {
          setStatus('success');
          setMessage(data.message);
        }
      })
      .catch((error) => {
        if (!cancelled) {
          setStatus('error');
          setMessage(error.response?.data?.message || 'Request failed.');
        }
      });
    return () => {
      cancelled = true;
    };
  }, [pingSlug]);

  return (
    <div>
      <Paragraph>
        Welcome, <strong>{user?.name}</strong>. This is an empty shell — the real screen for this role is built
        in a later phase.
      </Paragraph>
      {status === 'loading' && <Spin />}
      {status === 'success' && <Alert type="success" showIcon message={message} />}
      {status === 'error' && <Alert type="error" showIcon message={message} />}
    </div>
  );
}
