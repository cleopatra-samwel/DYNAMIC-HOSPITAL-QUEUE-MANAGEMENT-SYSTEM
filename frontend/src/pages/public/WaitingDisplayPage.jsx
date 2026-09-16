import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Button, Space, Tag, message } from 'antd';
import { SoundOutlined } from '@ant-design/icons';
import axios from 'axios';
import { getEcho } from '../../services/echo';
import useConnectionState from '../../hooks/useConnectionState';
import ConnectionIndicator from '../../components/ConnectionIndicator';
import { enqueueAnnouncement, unlockAudio } from '../../services/announcementPlayer';

const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api';

/**
 * Public waiting-area kiosk board — no login, meant to run full-screen on
 * a TV/monitor in the department's waiting area. Shows queue_number +
 * status only, nothing patient-identifying, nothing staff-only
 * (priority_score never appears here — see WaitingDisplayController and
 * the TicketCalled/TicketStatusChanged broadcast payloads).
 */
export default function WaitingDisplayPage() {
  const { departmentId } = useParams();
  const [departmentName, setDepartmentName] = useState('');
  const [tickets, setTickets] = useState([]);
  const [audioEnabled, setAudioEnabled] = useState(false);
  const audioEnabledRef = useRef(false);

  const fetchBoard = () => {
    axios.get(`${API_BASE}/waiting-display/${departmentId}`)
      .then(({ data }) => { setDepartmentName(data.department_name); setTickets(data.tickets); })
      .catch(() => {});
  };

  const connectionState = useConnectionState(fetchBoard);

  useEffect(() => {
    fetchBoard();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [departmentId]);

  useEffect(() => {
    const echo = getEcho();
    const channel = echo.channel(`waiting-display.${departmentId}`);

    const announceIfCalled = (payload) => {
      fetchBoard();
      // Browsers block audio until a user gesture unlocks it on this page
      // (see the "Enable Announcements" button) — skip queuing before that.
      if (payload.status === 'CALLED' && audioEnabledRef.current) {
        enqueueAnnouncement(payload.ticket_id);
      }
    };

    // Both events can bring a ticket to CALLED: TicketCalled from "Call
    // Next Patient" (PriorityEngine), and TicketStatusChanged from the
    // manual per-ticket "Call" action (TicketTransitionService) — a staff
    // member calling one specific patient by hand must announce them just
    // as much as the priority-driven auto-call does.
    channel.listen('.TicketCalled', announceIfCalled);
    channel.listen('.TicketStatusChanged', announceIfCalled);

    return () => echo.leave(`waiting-display.${departmentId}`);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [departmentId]);

  // Actually plays a real clip here, synchronously within this click —
  // not just a flag flip. A later WebSocket-triggered announcement plays
  // outside any user gesture, and some browsers (mobile Safari
  // especially) only grant audio permission to an element that ACTUALLY
  // played during the gesture. This also doubles as an audible self-test:
  // if you don't hear the ring right now, the device/browser is silent
  // or muted — that's not a bug this app can fix from here.
  const enableAudio = () => {
    unlockAudio().then((played) => {
      if (!played) {
        message.error('Could not play sound — check this device/browser is not muted or on silent mode, then try again.');
        return;
      }
      audioEnabledRef.current = true;
      setAudioEnabled(true);
    });
  };

  const nowServing = tickets.find((t) => t.status === 'CALLED');
  const nextUp = tickets.filter((t) => t.status === 'WAITING').slice(0, 3);

  return (
    <div style={{ padding: 40, background: '#001529', minHeight: '100vh', color: '#fff' }}>
      <ConnectionIndicator state={connectionState} />
      <Space align="center" style={{ marginBottom: 24 }}>
        <h1 style={{ color: '#fff', fontSize: 36, margin: 0 }}>{departmentName || 'Waiting Area'}</h1>
        {!audioEnabled && (
          <Button icon={<SoundOutlined />} onClick={enableAudio}>Enable Announcements</Button>
        )}
      </Space>

      <div style={{ marginBottom: 40 }}>
        <p style={{ color: '#8c8c8c', fontSize: 22, marginBottom: 8 }}>Now Serving</p>
        <div style={{ fontSize: 96, fontWeight: 700, lineHeight: 1 }}>
          {nowServing ? nowServing.queue_number : '—'}
        </div>
      </div>

      <div>
        <p style={{ color: '#8c8c8c', fontSize: 20, marginBottom: 8 }}>Next</p>
        {nextUp.length === 0 ? (
          <p style={{ color: '#8c8c8c' }}>No patients currently waiting.</p>
        ) : (
          <Space size="large" wrap>
            {nextUp.map((ticket) => (
              <Tag key={ticket.queue_number} color="gold" style={{ fontSize: 32, padding: '12px 24px' }}>
                {ticket.queue_number}
              </Tag>
            ))}
          </Space>
        )}
      </div>
    </div>
  );
}
