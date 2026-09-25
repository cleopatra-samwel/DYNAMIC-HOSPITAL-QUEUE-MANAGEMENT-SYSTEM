import { useEffect, useState } from 'react';
import { Empty, List, Spin, Tag, Typography } from 'antd';
import { WarningOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import apiClient from '../../services/apiClient';

const { Text } = Typography;

/**
 * Shared "Notification" page for every staff role. Shows ONLY overdue-wait
 * alerts — patients who waited past the expected time without being called
 * — for the departments the signed-in user serves (same feed as the bell
 * in the ribbon, plus the ones since resolved).
 */
export default function NotificationPage() {
  const [alerts, setAlerts] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    apiClient.get('/notifications/staff-alerts', { params: { include_resolved: 1 } })
      .then(({ data }) => setAlerts(data.alerts))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <Spin />;
  if (!alerts.length) return <Empty description="No overdue patients" />;

  return (
    <List
      itemLayout="horizontal"
      dataSource={alerts}
      renderItem={(alert) => (
        <List.Item extra={alert.queue_ticket?.status === 'WAITING' ? <Tag color="volcano">Still waiting</Tag> : <Tag>Called</Tag>}>
          <List.Item.Meta
            avatar={<WarningOutlined style={{ fontSize: 18, color: '#d92b3a' }} />}
            title={alert.message}
            description={<Text type="secondary">{alert.department?.dept_name} · {dayjs(alert.created_at).format('DD MMM YYYY, HH:mm')}</Text>}
          />
        </List.Item>
      )}
    />
  );
}
