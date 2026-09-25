import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Card, Empty, List, Result, Spin, Tag } from 'antd';
import { MenuOutlined } from '@ant-design/icons';
import axios from 'axios';
import { getEcho } from '../../services/echo';
import useConnectionState from '../../hooks/useConnectionState';
import ConnectionIndicator from '../../components/ConnectionIndicator';
import HeroCarousel from '../../components/HeroCarousel';
import TrackingInfoPanel from './TrackingInfoPanel';
import dayjs from 'dayjs';
import { formatStatusLabel } from '../../utils/formatLabel';
import useTrackingLanguage from '../../utils/trackingI18n';

const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api';

const NOTIFICATION_TYPE_COLORS = {
  WAITING: 'gold',
  APPROACHING: 'orange',
  CALLED: 'blue',
  TRANSFERRED: 'purple',
  COMPLETED: 'green',
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
  const [view, setView] = useState('home');
  const { language, setLanguage, t } = useTrackingLanguage();

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
    return <Result status="404" title={t.notFoundTitle} subTitle={t.notFoundSub} />;
  }

  const hint = (t.hints[status.status] || t.hints.DEFAULT)(status.department);
  const ahead = status.queue_numbers_ahead;
  const behind = status.queue_numbers_behind;

  return (
    <div className="tracking-page">
      <HeroCarousel />

      <button type="button" className="tracking-menu-toggle" aria-label={t.openMenu} onClick={() => setPanelOpen(true)}>
        <MenuOutlined />
      </button>

      <TrackingInfoPanel
        open={panelOpen}
        onClose={() => setPanelOpen(false)}
        view={view}
        onNavigate={setView}
        language={language}
        onLanguageChange={setLanguage}
        t={t}
      />

      <div style={{ maxWidth: 480, margin: '40px auto', padding: '0 16px', width: '100%' }}>
        <ConnectionIndicator state={connectionState} label={t.reconnecting} />

        {view === 'home' && (
          <Card className="tracking-card">
            <p className="tracking-card__label">{t.department}</p>
            <h2 style={{ marginTop: 0, color: '#fff' }}>{status.department || t.registration}</h2>

            <p className="tracking-card__label">{t.yourQueueNumber}</p>
            <h1 style={{ fontSize: 48, margin: 0, color: '#fff' }}>{status.queue_number || '—'}</h1>

            <div style={{ margin: '16px 0' }}>
              <Tag color={STATUS_COLORS[status.status] || 'default'} style={{ fontSize: 16, padding: '4px 12px' }}>
                {t.statusLabels[status.status] || formatStatusLabel(status.status) || t.registered}
              </Tag>
            </div>

            {status.position !== null && status.position !== undefined && (
              <p style={{ marginBottom: 12 }}>
                {status.position === 0 ? t.next : t.patientsAhead(status.position)}
                {status.estimated_wait_minutes !== null && status.estimated_wait_minutes !== undefined && (
                  <> {t.estimatedWait(status.estimated_wait_minutes)}</>
                )}
              </p>
            )}

            <p style={{ margin: 0, color: '#b8c7e6' }}>{hint}</p>
          </Card>
        )}

        {view === 'status' && (
          <>
            <Card className="tracking-card" style={{ marginBottom: 16 }}>
              <p className="tracking-card__label">{t.yourQueueNumber}</p>
              <h1 style={{ fontSize: 40, margin: 0, color: '#fff' }}>{status.queue_number || '—'}</h1>
            </Card>

            {ahead === null || ahead === undefined ? (
              <Card style={{ marginBottom: 16 }}>{t.statusUnavailable}</Card>
            ) : (
              <>
                <Card title={`${t.aheadOfYou} (${ahead.length})`} style={{ marginBottom: 16 }}>
                  {ahead.length > 0
                    ? ahead.map((number) => <span key={number} className="queue-chip">{number}</span>)
                    : t.nobodyAhead}
                </Card>
                <Card title={`${t.behindYou} (${behind?.length ?? 0})`} style={{ marginBottom: 16 }}>
                  {behind?.length > 0
                    ? behind.map((number) => <span key={number} className="queue-chip">{number}</span>)
                    : t.nobodyBehind}
                </Card>
              </>
            )}

            <Card title={t.yourUpdates}>
              {status.notifications?.length > 0 ? (
                <List
                  size="small"
                  dataSource={status.notifications}
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
                <Empty description={t.noUpdates} />
              )}
            </Card>
          </>
        )}
      </div>
    </div>
  );
}
