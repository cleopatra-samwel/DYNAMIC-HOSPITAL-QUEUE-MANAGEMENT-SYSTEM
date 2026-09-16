import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Card, Result, Spin, Tag } from 'antd';
import { MenuOutlined } from '@ant-design/icons';
import axios from 'axios';
import { getEcho } from '../../services/echo';
import useConnectionState from '../../hooks/useConnectionState';
import ConnectionIndicator from '../../components/ConnectionIndicator';
import HeroCarousel from '../../components/HeroCarousel';
import TrackingInfoPanel from './TrackingInfoPanel';
import { formatStatusLabel } from '../../utils/formatLabel';

const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api';

const STATUS_LABELS = {
  WAITING: 'Waiting',
  CALLED: 'You are being called — please proceed',
  IN_SERVICE: 'In service',
  COMPLETED: 'Completed',
  NO_SHOW: 'Marked as no-show',
  CANCELLED: 'Cancelled',
  ON_HOLD: 'On hold',
  TRANSFERRED: 'Transferred',
};

const STATUS_COLORS = {
  WAITING: 'gold',
  CALLED: 'blue',
  IN_SERVICE: 'geekblue',
  COMPLETED: 'green',
  NO_SHOW: 'volcano',
  CANCELLED: 'default',
  ON_HOLD: 'orange',
  TRANSFERRED: 'purple',
};

/**
 * Public patient tracking page — no login, reached via the opaque
 * tracking_token in the URL (QR code or SMS link, Phase 8). Always
 * fetches fresh on mount (a closed tab reopening is just a normal mount)
 * and again on every relevant WebSocket event or reconnect, since a
 * missed event during a signal drop must not leave this screen showing
 * stale status.
 *
 * The core status logic below (fetch/subscribe/render queue_number,
 * status, position, estimated wait) is unchanged from Phase 7/8 — this
 * revision only adds the rotating hero background (relocated from the
 * now-removed shared staff landing page, see HeroCarousel) and a
 * hamburger panel for the notification history/next-step hint/support
 * contact (previously an always-visible "Updates" card, now moved into
 * that slide-in panel to keep the main view uncluttered against the new
 * background). Still zero patient-identifying data, still public, still
 * rate-limited server-side (Phase 7/10) — nothing about the underlying
 * /api/track/{token} call changed.
 */
export default function TrackingPage() {
  const { token } = useParams();
  const [status, setStatus] = useState(null);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [panelOpen, setPanelOpen] = useState(false);

  const fetchStatus = () => {
    axios.get(`${API_BASE}/track/${token}`)
      .then(({ data }) => { setStatus(data); setNotFound(false); })
      .catch((error) => { if (error.response?.status === 404) setNotFound(true); })
      .finally(() => setLoading(false));
  };

  const connectionState = useConnectionState(fetchStatus);

  useEffect(() => {
    fetchStatus();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]);

  useEffect(() => {
    const echo = getEcho();
    const channel = echo.channel(`visit.${token}`);

    channel.listen('.TicketCalled', fetchStatus);
    channel.listen('.TicketStatusChanged', fetchStatus);

    return () => echo.leave(`visit.${token}`);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]);

  if (loading) {
    return <div style={{ display: 'flex', justifyContent: 'center', marginTop: 80 }}><Spin size="large" /></div>;
  }

  if (notFound) {
    return <Result status="404" title="Tracking link not found" subTitle="This link may be incorrect or the visit no longer exists." />;
  }

  return (
    <div className="tracking-page">
      <HeroCarousel />

      <button type="button" className="tracking-menu-toggle" aria-label="Open menu" onClick={() => setPanelOpen(true)}>
        <MenuOutlined />
      </button>

      <TrackingInfoPanel
        open={panelOpen}
        onClose={() => setPanelOpen(false)}
        notifications={status.notifications}
        status={status.status}
        department={status.department}
      />

      <div style={{ maxWidth: 480, margin: '40px auto', padding: '0 16px', width: '100%' }}>
        <ConnectionIndicator state={connectionState} />
        <Card>
          <p style={{ color: '#8c8c8c', marginBottom: 4 }}>Department</p>
          <h2 style={{ marginTop: 0 }}>{status.department || 'Registration'}</h2>

          <p style={{ color: '#8c8c8c', marginBottom: 4 }}>Your queue number</p>
          <h1 style={{ fontSize: 48, margin: 0 }}>{status.queue_number || '—'}</h1>

          <div style={{ margin: '16px 0' }}>
            <Tag color={STATUS_COLORS[status.status] || 'default'} style={{ fontSize: 16, padding: '4px 12px' }}>
              {STATUS_LABELS[status.status] || formatStatusLabel(status.status) || 'Registered'}
            </Tag>
          </div>

          {status.position !== null && status.position !== undefined && (
            <div>
              <p style={{ marginBottom: 4 }}>
                {status.position === 0 ? "You're next." : `${status.position} patient(s) ahead of you.`}
                {status.estimated_wait_minutes !== null && status.estimated_wait_minutes !== undefined && (
                  <> Estimated wait: ~{status.estimated_wait_minutes} min.</>
                )}
              </p>
              {status.queue_numbers_ahead?.length > 0 && (
                <p style={{ color: '#8c8c8c', fontSize: 13 }}>
                  Ahead of you: {status.queue_numbers_ahead.join(', ')}
                </p>
              )}
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}
