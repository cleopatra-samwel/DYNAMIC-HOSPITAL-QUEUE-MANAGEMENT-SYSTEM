import { useEffect, useState } from 'react';
import { Empty, Spin, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import apiClient from '../../services/apiClient';

/**
 * Administrator-only. Backed by the scheduled queue:check-long-waiting
 * command — a suppression window (30 min) keeps this list from being
 * spammed with the same overdue ticket over and over (see
 * CheckLongWaitingTickets on the backend).
 */
export default function LongWaitingAlertsPage() {
  const [alerts, setAlerts] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    apiClient.get('/notifications', { params: { type: 'LONG_WAIT_ALERT' } })
      .then(({ data }) => setAlerts(data.notifications))
      .finally(() => setLoading(false));
  }, []);

  const columns = [
    { title: 'Ticket', render: (_, n) => n.queue_ticket?.queue_number || '—' },
    { title: 'Department', render: (_, n) => n.department?.dept_name || '—' },
    { title: 'Priority', render: (_, n) => n.queue_ticket?.priority_level?.name || '—' },
    { title: 'Alert', dataIndex: 'message' },
    {
      title: 'Raised',
      dataIndex: 'created_at',
      render: (v) => <Tag color="volcano">{dayjs(v).format('DD MMM YYYY, HH:mm')}</Tag>,
    },
  ];

  return loading ? <Spin /> : alerts.length === 0
    ? <Empty description="No active long-waiting alerts" />
    : <Table rowKey="id" columns={columns} dataSource={alerts} pagination={false} />;
}
