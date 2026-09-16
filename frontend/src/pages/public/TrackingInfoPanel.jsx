import { CloseOutlined, PhoneOutlined } from '@ant-design/icons';
import { Empty, List, Tag } from 'antd';
import dayjs from 'dayjs';
import BrandHeader from '../../components/BrandHeader';
import { formatStatusLabel } from '../../utils/formatLabel';

const NOTIFICATION_TYPE_COLORS = {
  WAITING: 'gold',
  APPROACHING: 'orange',
  CALLED: 'blue',
  TRANSFERRED: 'purple',
  COMPLETED: 'green',
};

const NEXT_STEP_HINTS = {
  WAITING: (dept) => `You are waiting for ${dept || 'your department'}.`,
  CALLED: (dept) => `You have been called — please proceed to ${dept || 'the counter shown on screen'}.`,
  IN_SERVICE: (dept) => `You are currently being seen in ${dept || 'your department'}.`,
  ON_HOLD: () => 'Your case is on hold — please wait, you will be called again.',
  TRANSFERRED: (dept) => `You are being transferred to ${dept || 'your next department'}.`,
  COMPLETED: () => 'Your visit is complete. Thank you.',
  NO_SHOW: () => 'You were marked as not present — please check in again at Registration.',
  CANCELLED: () => 'This ticket was cancelled.',
};

/**
 * Slide-in panel for the public tracking page — same toggle/drawer
 * pattern as staff dashboards' RoleSidebar (identical .role-sidebar*
 * CSS), different content: this visit's own notification history,
 * a plain-language "what happens next", and the hospital's support
 * number. Nothing here is patient-identifying — notifications are
 * already scoped server-side to this one visit (see TrackingController).
 */
export default function TrackingInfoPanel({ open, onClose, notifications, status, department }) {
  const hint = (NEXT_STEP_HINTS[status] || (() => 'Please wait — your status will update automatically.'))(department);

  return (
    <>
      <div className={`role-sidebar__overlay${open ? ' is-open' : ''}`} onClick={onClose} aria-hidden="true" />
      <aside className={`role-sidebar${open ? ' is-open' : ''}`} aria-hidden={!open}>
        <button type="button" className="role-sidebar__close" aria-label="Close menu" onClick={onClose}><CloseOutlined /></button>
        <BrandHeader compact subtitle="Your Visit" />

        <div className="tracking-info-panel__section">
          <h4>What happens next</h4>
          <p style={{ margin: 0 }}>{hint}</p>
        </div>

        <div className="tracking-info-panel__section" style={{ flex: 1 }}>
          <h4>Your Updates</h4>
          {notifications?.length > 0 ? (
            <List
              size="small"
              dataSource={notifications}
              renderItem={(notification) => (
                <List.Item>
                  <List.Item.Meta
                    title={<Tag color={NOTIFICATION_TYPE_COLORS[notification.type] || 'default'}>{formatStatusLabel(notification.type)}</Tag>}
                    description={notification.message}
                  />
                  <span style={{ color: '#8c8c8c', fontSize: 12 }}>{dayjs(notification.created_at).format('HH:mm')}</span>
                </List.Item>
              )}
            />
          ) : (
            <Empty description="No updates yet" />
          )}
        </div>

        <div className="tracking-info-panel__section">
          <h4>Need help?</h4>
          <p style={{ margin: 0 }}><PhoneOutlined /> Support/Emergency: +255 22 215 1367</p>
        </div>
      </aside>
    </>
  );
}
