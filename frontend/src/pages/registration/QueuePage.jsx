import { useEffect, useState } from 'react';
import { useSelector } from 'react-redux';
import { useLocation } from 'react-router-dom';
import { Button, Input, Select, Space, Table, Tag, Tooltip, message } from 'antd';
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

export default function QueuePage() {
  const activeRole = useSelector((state) => state.auth.user?.active_role);
  const location = useLocation();
  const [tickets, setTickets] = useState([]);
  const [loading, setLoading] = useState(true);
  const [departments, setDepartments] = useState([]);
  const [priorityLevels, setPriorityLevels] = useState([]);
  // q may arrive pre-filled via navigate(..., { state: { q } }) — e.g. the
  // Patients page's "View Visits" modal jumping straight to a specific
  // in-progress ticket instead of making staff retype/search for it.
  const [filters, setFilters] = useState({ q: location.state?.q || '', department_id: undefined, status: undefined, priority_level_id: undefined });
  const [actingId, setActingId] = useState(null);
  const [callingNext, setCallingNext] = useState(false);
  const [viewingTicket, setViewingTicket] = useState(null);
  const { audioEnabled, enableAudio, playAnnouncement } = useAnnouncementPlayer();

  const loadTickets = (params = filters) => {
    setLoading(true);
    const query = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== ''));
    apiClient.get('/queue-tickets', { params: query })
      .then(({ data }) => setTickets(data.data))
      .catch(() => message.error('Could not load the queue.'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    loadTickets(filters);
    apiClient.get('/departments').then(({ data }) => setDepartments(data.departments)).catch(() => {});
    apiClient.get('/priority-levels').then(({ data }) => setPriorityLevels(data.priority_levels)).catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // One-time full refetch on reconnect — same reasoning as
  // DepartmentQueuePage: don't trust that every WS event during a drop
  // was actually received.
  const connectionState = useConnectionState(() => loadTickets());

  // This view spans every department (unlike DepartmentQueuePage, which is
  // scoped to one), so it subscribes to ALL of them rather than a single
  // channel — routes/channels.php grants Registration Staff read-only
  // listen access to any department.{id} channel for exactly this reason.
  // Same live-patch approach: update the affected row in place, never a
  // full reload, and never touch the "Call Next"/action buttons' own HTTP
  // calls.
  useEffect(() => {
    if (departments.length === 0) return undefined;

    const echo = getEcho();
    const channels = departments.map((d) => echo.private(`department.${d.id}`));

    const patchTicket = (payload) => {
      setTickets((prev) => prev.map((t) => (t.id === payload.ticket_id ? { ...t, status: payload.status } : t)));
    };
    const patchPriorities = (payload) => {
      const scoreByTicketId = Object.fromEntries(payload.tickets.map((t) => [t.ticket_id, t.priority_score]));
      setTickets((prev) => prev.map((t) => (t.id in scoreByTicketId ? { ...t, priority_score: scoreByTicketId[t.id] } : t)));
    };

    channels.forEach((channel) => {
      channel.listen('.TicketCalled', patchTicket);
      channel.listen('.TicketStatusChanged', patchTicket);
      channel.listen('.PriorityRecalculated', patchPriorities);
    });

    return () => departments.forEach((d) => echo.leave(`department.${d.id}`));
  }, [departments]);

  const updateFilter = (patch) => {
    const next = { ...filters, ...patch };
    setFilters(next);
    loadTickets(next);
  };

  const callNextPatient = async () => {
    setCallingNext(true);
    try {
      const { data } = await apiClient.post(`/departments/${filters.department_id}/call-next`);
      if (data.ticket) {
        message.success(`Called ${data.ticket.queue_number} (${data.ticket.service?.visit?.patient?.name || 'patient'}).`);
        playAnnouncement(data.ticket.id);
      } else {
        message.info(data.message || 'No patients waiting in this department.');
      }
      loadTickets();
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
      loadTickets();
    } catch (error) {
      message.error(error.response?.data?.message || 'Could not update ticket.');
    } finally {
      setActingId(null);
    }
  };

  const columns = [
    { title: 'Ticket', dataIndex: 'queue_number' },
    { title: 'Patient', render: (_, t) => t.service?.visit?.patient?.name || '—' },
    { title: 'Department', render: (_, t) => t.service?.department?.dept_name || '—' },
    { title: 'Priority', render: (_, t) => t.priority_level?.name || '—' },
    {
      title: 'Score',
      render: (_, t) => t.status === 'WAITING'
        ? <Tooltip title="Staff-only priority score — never shown on the public waiting-area display"><strong>{t.priority_score}</strong></Tooltip>
        : '—',
    },
    { title: 'Status', dataIndex: 'status', render: (value) => <Tag color={STATUS_COLORS[value] || 'default'}>{formatStatusLabel(value)}</Tag> },
    { title: 'Created', dataIndex: 'created_at', render: (value) => dayjs(value).format('DD MMM HH:mm') },
    {
      title: 'Action',
      render: (_, ticket) => {
        const busy = actingId === ticket.id;
        const isTerminal = ['COMPLETED', 'CANCELLED', 'NO_SHOW', 'TRANSFERRED'].includes(ticket.status);
        // This view spans every department (unlike each clinical role's own
        // queue page, which is scoped to one), so unlike those pages a
        // button here can't be assumed permitted just by which route you're
        // on — mirror DepartmentRoles::userCanActOn per row instead of
        // rendering a button set that would just 403.
        const canAct = canActOnDepartment(activeRole, ticket.service?.department?.dept_code);
        if (!canAct) {
          return (
            <Tooltip title="Your active role cannot act on this department's tickets">
              <span style={{ color: '#8c8c8c' }}>—</span>
            </Tooltip>
          );
        }
        // Only ever REG tickets reach this branch — DepartmentRoles maps
        // Registration Staff to REG exclusively, so canAct is never true
        // for any other department here. "Register Patient" (not the
        // generic "View" DepartmentQueuePage uses for other roles) names
        // what this actually does: record the patient's details and which
        // department they need, then forward them there.
        return (
          <Space wrap>
            {['WAITING', 'ON_HOLD'].includes(ticket.status) && (
              <Button size="small" loading={busy} onClick={() => runAction(ticket, 'call')}>Call</Button>
            )}
            {ticket.status === 'CALLED' && (
              <Button size="small" loading={busy} onClick={() => runAction(ticket, 'start-service')}>Start Service</Button>
            )}
            <Button size="small" type="primary" onClick={() => setViewingTicket(ticket)}>Register Patient</Button>
            {ticket.status === 'CALLED' && (
              <Button size="small" loading={busy} onClick={() => runAction(ticket, 'no-show')}>Mark No-Show</Button>
            )}
            {!isTerminal && (
              <Button size="small" danger loading={busy} onClick={() => runAction(ticket, 'cancel')}>Cancel</Button>
            )}
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
          style={{ width: 240 }}
          defaultValue={filters.q}
          onSearch={(value) => updateFilter({ q: value })}
        />
        <Select
          placeholder="Department"
          allowClear
          style={{ width: 180 }}
          options={departments.map((d) => ({ label: d.dept_name, value: d.id }))}
          onChange={(value) => updateFilter({ department_id: value })}
        />
        <Select
          placeholder="Status"
          allowClear
          style={{ width: 150 }}
          options={Object.keys(STATUS_COLORS).map((s) => ({ label: formatStatusLabel(s), value: s }))}
          onChange={(value) => updateFilter({ status: value })}
        />
        <Select
          placeholder="Priority"
          allowClear
          style={{ width: 150 }}
          options={priorityLevels.map((p) => ({ label: p.name, value: p.id }))}
          onChange={(value) => updateFilter({ priority_level_id: value })}
        />
        <Tooltip title={filters.department_id ? '' : 'Pick a department above first'}>
          <Button
            type="primary"
            icon={<ThunderboltOutlined />}
            disabled={!filters.department_id}
            loading={callingNext}
            onClick={callNextPatient}
          >
            Call Next Patient
          </Button>
        </Tooltip>
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
        onChanged={loadTickets}
        onTicketCalled={playAnnouncement}
      />
    </div>
  );
}
