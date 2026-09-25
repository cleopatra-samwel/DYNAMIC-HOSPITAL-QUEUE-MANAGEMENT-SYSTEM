import { useEffect, useState } from 'react';
import { useSelector } from 'react-redux';
import { Button, Input, Space, Table, Tag, Tooltip, message } from 'antd';
import { SearchOutlined, SoundOutlined, ThunderboltOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import apiClient from '../../services/apiClient';
import { getEcho } from '../../services/echo';
import useConnectionState from '../../hooks/useConnectionState';
import useAnnouncementPlayer from '../../hooks/useAnnouncementPlayer';
import { ConnectedBadge } from '../../components/ConnectionIndicator';
import { formatStatusLabel } from '../../utils/formatLabel';
import { canActOnDepartment } from '../../utils/departmentRoles';
import TicketViewModal from '../../components/TicketViewModal';

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

const ACTION_LABELS = {
  call: 'called',
  'start-service': 'moved to in-service',
  'no-show': 'marked as no-show',
  cancel: 'cancelled',
};

/**
 * Shared "Queue" page for the Doctor, Laboratory, and Pharmacy dashboards
 * (the "Dashboard" stat cards are a separate page — DepartmentStatsPage).
 * Every row and action here comes from the Laravel API — nothing is
 * mocked, and every action re-fetches the queue afterward so the screen
 * always reflects real committed database state.
 *
 * Call/Start Service/Mark No-Show/Cancel stay as direct row buttons —
 * fast, no clinical content involved. Anything that fills in clinical
 * information or forwards the patient onward (Complete, Request
 * Laboratory, Send to Pharmacy, Forward to Doctor, ...) now lives behind
 * one "View" button, which opens TicketViewModal scoped to this
 * department's role automatically.
 *
 * `actionMode` swaps in an alternate Action column; Pharmacy doesn't pass
 * it and keeps the 5-button row above. Options:
 *   - 'call-and-secondary' (Doctor): "Call" while waiting, then "Called" +
 *     a second button (`secondaryActionLabel`, e.g. "Consult") once called.
 *   - 'call-only' (Laboratory's "Queue" sidebar item): "Call" while
 *     waiting, then a disabled "Called" label — no further action here,
 *     the actual work happens on the "Perform Test" page instead.
 *   - 'review-only' (Laboratory's "Perform Test" sidebar item): no Call
 *     button at all — just `secondaryActionLabel` (e.g. "Review") once the
 *     ticket has already been called elsewhere.
 * All three show "View" once COMPLETED. The secondary/review button
 * silently fires start-service first (if needed) so the modal opens
 * directly on the fillable form instead of stopping on an intermediate
 * "Start Service" screen.
 */
export default function DepartmentQueuePage({ deptCode, showPaymentStatus = false, extraColumns = [], actionMode = 'full', secondaryActionLabel = 'Consult' }) {
  const activeRole = useSelector((state) => state.auth.user?.active_role);
  const [department, setDepartment] = useState(null);
  const [tickets, setTickets] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [actingId, setActingId] = useState(null);
  const [callingNext, setCallingNext] = useState(false);
  const [viewingTicket, setViewingTicket] = useState(null);
  const { audioEnabled, enableAudio, playAnnouncement } = useAnnouncementPlayer();

  const loadTickets = (departmentId, q = search) => {
    if (!departmentId) return;
    setLoading(true);
    const params = { department_id: departmentId };
    if (q) params.q = q;
    apiClient.get('/queue-tickets', { params })
      .then(({ data }) => setTickets(data.data))
      .catch(() => message.error('Could not load the queue.'))
      .finally(() => setLoading(false));
  };

  const refresh = () => {
    if (department) loadTickets(department.id);
  };

  useEffect(() => {
    apiClient.get('/departments').then(({ data }) => {
      const mine = data.departments.find((d) => d.dept_code === deptCode);
      setDepartment(mine || null);
      loadTickets(mine?.id);
    }).catch(() => message.error('Could not load department.'));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [deptCode]);

  // One-time full refetch on reconnect — WS events missed during a drop
  // aren't individually replayed, so don't trust local state alone.
  const connectionState = useConnectionState(refresh);

  // Live updates: patch just the affected ticket instead of a full
  // reload, so another staff member's action (e.g. someone else calling a
  // patient) shows up instantly without a visible reload flicker. This
  // never replaces the "Call Next"/action buttons' own HTTP calls —
  // WebSockets here are receive-only.
  useEffect(() => {
    if (!department?.id) return undefined;

    const echo = getEcho();
    const channel = echo.private(`department.${department.id}`);

    const patchTicket = (payload) => {
      setTickets((prev) => prev.map((t) => (t.id === payload.ticket_id ? { ...t, status: payload.status } : t)));
    };
    const patchPriorities = (payload) => {
      const scoreByTicketId = Object.fromEntries(payload.tickets.map((t) => [t.ticket_id, t.priority_score]));
      setTickets((prev) => prev.map((t) => (t.id in scoreByTicketId ? { ...t, priority_score: scoreByTicketId[t.id] } : t)));
    };

    channel.listen('.TicketCalled', patchTicket);
    channel.listen('.TicketStatusChanged', patchTicket);
    channel.listen('.PriorityRecalculated', patchPriorities);

    return () => {
      echo.leave(`department.${department.id}`);
    };
  }, [department?.id]);

  const callNextPatient = async () => {
    if (!department) return;
    setCallingNext(true);
    try {
      const { data } = await apiClient.post(`/departments/${department.id}/call-next`);
      if (data.ticket) {
        message.success(`Called ${data.ticket.queue_number} (${data.ticket.service?.visit?.patient?.name || 'patient'}).`);
        playAnnouncement(data.ticket.id);
      } else {
        message.info(data.message || 'No patients waiting in this department.');
      }
      refresh();
    } catch (error) {
      message.error(error.response?.data?.message || 'Could not call the next patient.');
    } finally {
      setCallingNext(false);
    }
  };

  const runAction = async (ticket, action) => {
    setActingId(ticket.id);
    try {
      await apiClient.patch(`/queue-tickets/${ticket.id}/${action}`);
      message.success(`Ticket ${ticket.queue_number} ${ACTION_LABELS[action] || 'updated'}.`);
      if (action === 'call') playAnnouncement(ticket.id);
      refresh();
    } catch (error) {
      message.error(error.response?.data?.message || 'Could not update ticket.');
    } finally {
      setActingId(null);
    }
  };

  // Used by the "Consult"/"Review" secondary button — moves a CALLED
  // ticket to IN_SERVICE first (if not already there) so TicketViewModal
  // opens straight on the fillable consultation form, not the "Start
  // Service" screen.
  const startAndView = async (ticket) => {
    if (ticket.status === 'IN_SERVICE') {
      setViewingTicket(ticket);
      return;
    }
    setActingId(ticket.id);
    try {
      const { data } = await apiClient.patch(`/queue-tickets/${ticket.id}/start-service`);
      refresh();
      setViewingTicket(data.ticket);
    } catch (error) {
      message.error(error.response?.data?.message || 'Could not open the consultation for this ticket.');
    } finally {
      setActingId(null);
    }
  };

  const columns = [
    { title: 'Ticket', dataIndex: 'queue_number' },
    { title: 'Patient', render: (_, t) => t.service?.visit?.patient?.name || '—' },
    // Opt-in, per-caller extra columns (e.g. Laboratory's Patient ID/Age/
    // Gender/Referring Doctor/Requested Tests) — Doctor/Pharmacy pass
    // nothing here and see exactly the same table as before.
    ...extraColumns,
    { title: 'Priority', render: (_, t) => t.priority_level?.name || '—' },
    {
      title: 'Score',
      render: (_, t) => t.status === 'WAITING'
        ? <Tooltip title="Staff-only priority score — never shown on the public waiting-area display"><strong>{t.priority_score}</strong></Tooltip>
        : '—',
    },
    { title: 'Status', dataIndex: 'status', render: (value) => <Tag color={STATUS_COLORS[value] || 'default'}>{formatStatusLabel(value)}</Tag> },
    ...(showPaymentStatus ? [{
      title: 'Payment Status',
      render: (_, t) => {
        const status = t.service?.visit?.payment_status || 'Pending';
        return <Tag color={status === 'Verified' ? 'green' : 'orange'}>{status}</Tag>;
      },
    }] : []),
    { title: 'Created', dataIndex: 'created_at', render: (value) => dayjs(value).format('DD MMM HH:mm') },
    {
      title: 'Action',
      render: (_, ticket) => {
        const busy = actingId === ticket.id;
        const isTerminal = ['COMPLETED', 'CANCELLED', 'NO_SHOW', 'TRANSFERRED'].includes(ticket.status);
        // Mirrors DepartmentRoles::userCanActOn — always true in practice
        // here (each role only ever reaches its own department's queue
        // route), but checked explicitly rather than assumed, same as the
        // Registration cross-department queue view.
        const canAct = canActOnDepartment(activeRole, department?.dept_code);
        if (!canAct) {
          return (
            <Tooltip title="Your active role cannot act on this department's tickets">
              {ticket.status === 'CALLED' ? <Button size="small" disabled>Called</Button> : <span style={{ color: '#8c8c8c' }}>—</span>}
            </Tooltip>
          );
        }
        if (actionMode === 'call-and-secondary') {
          if (['WAITING', 'ON_HOLD'].includes(ticket.status)) {
            return <Button size="small" loading={busy} onClick={() => runAction(ticket, 'call')}>Call</Button>;
          }
          if (['CALLED', 'IN_SERVICE'].includes(ticket.status)) {
            return (
              <Space wrap>
                <Button size="small" disabled>Called</Button>
                <Button size="small" type="primary" loading={busy} onClick={() => startAndView(ticket)}>{secondaryActionLabel}</Button>
              </Space>
            );
          }
          if (ticket.status === 'COMPLETED') {
            return <Button size="small" onClick={() => setViewingTicket(ticket)}>View</Button>;
          }
          return <span style={{ color: '#8c8c8c' }}>—</span>;
        }
        if (actionMode === 'call-only') {
          if (['WAITING', 'ON_HOLD'].includes(ticket.status)) {
            return <Button size="small" loading={busy} onClick={() => runAction(ticket, 'call')}>Call</Button>;
          }
          if (['CALLED', 'IN_SERVICE'].includes(ticket.status)) {
            return <Button size="small" disabled>Called</Button>;
          }
          if (ticket.status === 'COMPLETED') {
            return <Button size="small" onClick={() => setViewingTicket(ticket)}>View</Button>;
          }
          return <span style={{ color: '#8c8c8c' }}>—</span>;
        }
        if (actionMode === 'review-only') {
          if (['CALLED', 'IN_SERVICE'].includes(ticket.status)) {
            return <Button size="small" type="primary" loading={busy} onClick={() => startAndView(ticket)}>{secondaryActionLabel}</Button>;
          }
          if (ticket.status === 'COMPLETED') {
            return <Button size="small" onClick={() => setViewingTicket(ticket)}>View</Button>;
          }
          return <span style={{ color: '#8c8c8c' }}>—</span>;
        }
        return (
          <Space wrap>
            <Button size="small" disabled={!['WAITING', 'ON_HOLD'].includes(ticket.status)} loading={busy} onClick={() => runAction(ticket, 'call')}>{ticket.status === 'CALLED' ? 'Called' : 'Call'}</Button>
            <Button size="small" disabled={ticket.status !== 'CALLED'} loading={busy} onClick={() => runAction(ticket, 'start-service')}>Start Service</Button>
            <Button size="small" type="primary" onClick={() => setViewingTicket(ticket)}>View</Button>
            <Button size="small" disabled={ticket.status !== 'CALLED'} loading={busy} onClick={() => runAction(ticket, 'no-show')}>Mark No-Show</Button>
            <Button size="small" danger disabled={isTerminal} loading={busy} onClick={() => runAction(ticket, 'cancel')}>Cancel</Button>
          </Space>
        );
      },
    },
  ];

  return (
    <div>
      <Space wrap style={{ marginBottom: 16 }}>
        <Input.Search
          placeholder="Search ticket or patient"
          allowClear
          prefix={<SearchOutlined />}
          style={{ width: 260 }}
          onSearch={(value) => { setSearch(value); loadTickets(department?.id, value); }}
        />
        <Button
          type="primary"
          icon={<ThunderboltOutlined />}
          disabled={!department}
          loading={callingNext}
          onClick={callNextPatient}
        >
          Call Next Patient
        </Button>
        {!audioEnabled && (
          <Button icon={<SoundOutlined />} onClick={enableAudio}>Enable Sound</Button>
        )}
        <ConnectedBadge state={connectionState} />
      </Space>
      <Table rowKey="id" columns={columns} dataSource={tickets} loading={loading} />

      <TicketViewModal
        ticket={viewingTicket}
        open={!!viewingTicket}
        onClose={() => setViewingTicket(null)}
        onChanged={refresh}
        onTicketCalled={playAnnouncement}
        secondaryActionLabel={secondaryActionLabel}
      />
    </div>
  );
}
