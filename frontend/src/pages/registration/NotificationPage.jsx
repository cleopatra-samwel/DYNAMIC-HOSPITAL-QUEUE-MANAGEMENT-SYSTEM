import { useEffect, useState } from 'react';
import { Empty, List, Spin, Typography } from 'antd';
import { BellOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import apiClient from '../../services/apiClient';

const { Text } = Typography;

export default function NotificationPage() {
  const [notifications, setNotifications] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    apiClient.get('/queue-events/recent')
      .then(({ data }) => setNotifications(data.notifications))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <Spin />;
  if (!notifications.length) return <Empty description="No notifications yet" />;

  return (
    <List
      itemLayout="horizontal"
      dataSource={notifications}
      renderItem={(item) => (
        <List.Item>
          <List.Item.Meta
            avatar={<BellOutlined style={{ fontSize: 18, color: '#8c1d2d' }} />}
            title={item.message}
            description={<Text type="secondary">{item.performed_by ? `${item.performed_by} · ` : ''}{dayjs(item.event_time).format('DD MMM YYYY, HH:mm')}</Text>}
          />
        </List.Item>
      )}
    />
  );
}
