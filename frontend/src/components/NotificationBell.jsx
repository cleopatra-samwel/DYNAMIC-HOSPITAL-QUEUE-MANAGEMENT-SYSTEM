import { useEffect, useRef, useState } from 'react';
import { Badge, Empty, List, Popover, notification } from 'antd';
import { BellOutlined, WarningOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import apiClient from '../services/apiClient';
import { playAlertSound } from '../services/alertSound';

const POLL_INTERVAL_MS = 20000;
const MAX_POPUPS_AT_ONCE = 3;

/**
 * Bell in the dashboard ribbon. Lists patients who have been WAITING past
 * the expected time without being called (raised server-side by
 * queue:check-overdue-waiting, scoped to the departments this user serves)
 * and, when a NEW one appears while the dashboard is open, plays an alert
 * sound and shows a notification at the top of the screen. An alert leaves
 * the list by itself once the patient is called.
 */
export default function NotificationBell() {
  const [alerts, setAlerts] = useState([]);
  const [api, contextHolder] = notification.useNotification();
  const seenIds = useRef(new Set());
  const firstLoad = useRef(true);

  useEffect(() => {
    let cancelled = false;

    const load = () => {
      apiClient.get('/notifications/staff-alerts')
        .then(({ data }) => {
          if (cancelled) return;
          setAlerts(data.alerts);

          // Alerts already open when the page loads just fill the bell — only ones raised afterwards ring.
          const fresh = data.alerts.filter((alert) => !seenIds.current.has(alert.id));
          data.alerts.forEach((alert) => seenIds.current.add(alert.id));

          if (firstLoad.current) {
            firstLoad.current = false;
            return;
          }

          if (fresh.length > 0) {
            playAlertSound();
            fresh.slice(0, MAX_POPUPS_AT_ONCE).forEach((alert) => {
              api.warning({
                key: `overdue-${alert.id}`,
                message: `Patient ${alert.queue_ticket?.queue_number} is overdue`,
                description: alert.message,
                placement: 'top',
                duration: 15,
                icon: <WarningOutlined style={{ color: '#d92b3a' }} />,
              });
            });
          }
        })
        .catch(() => {});
    };

    load();
    const timer = setInterval(load, POLL_INTERVAL_MS);
    return () => { cancelled = true; clearInterval(timer); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const content = alerts.length === 0
    ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No overdue patients" />
    : (
      <List
        size="small"
        style={{ width: 320, maxHeight: 360, overflowY: 'auto' }}
        dataSource={alerts}
        renderItem={(alert) => (
          <List.Item>
            <List.Item.Meta
              title={`${alert.queue_ticket?.queue_number} · ${alert.department?.dept_name || ''}`}
              description={`Waiting ${dayjs().diff(dayjs(alert.queue_ticket?.created_at), 'minute')} min — not yet called`}
            />
          </List.Item>
        )}
      />
    );

  return (
    <>
      {contextHolder}
      <Popover content={content} title="Waiting too long" trigger="click" placement="bottomRight">
        <button type="button" aria-label="Notifications">
          <Badge count={alerts.length} size="small" offset={[2, -2]}>
            <BellOutlined style={{ color: '#fff', fontSize: 18 }} />
          </Badge>
        </button>
      </Popover>
    </>
  );
}
