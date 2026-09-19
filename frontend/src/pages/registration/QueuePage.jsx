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
  const [filters, setFilters] = useState({ q: location.state?.q || '', priority_level_id: undefined });
  const [actingId, setActingId] = useState(null);
  const [callingNext, setCallingNext] = useState(false);
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

  // Registration Staff only ever act on REG department tickets
  // (DepartmentRoles maps them to REG exclusively — see canAct below), so
  // this toolbar button always targets REG, not a picked department.
  const callNextPatient = async () => {
    const regDepartment = departments.find((d) => d.dept_code === 'REG');
    if (!regDepartment) return;
    setCallingNext(true);
    try {
      const { data } = await apiClient.post(`/departments/${regDepartment.id}/call-next`);
      if (data.ticket) {
        message.success(`Called ${data.ticket.queue_number} (${data.ticket.service?.visit?.patient?.name || 'patient'}).`);
        playAnnouncement(data.ticket.id);
      } else {
        message.info(data.message || 'No patients waiting.');
      }
      loadTickets();
    } catch (error) {
      message.error(error.response?.data?.message || 'Could not call the next patient.');
    } finally {
      setCallingNext(false);
    }
  };

  // Row "Call" buttons don't call THIS ticket — they trigger the same
  // priority-based PriorityEngine::callNext() as the (now-removed)
  // standalone "Call Next Patient" button did, scoped to this row's
  // department. Whichever WAITING/ON_HOLD row's button is clicked, the
  // backend still picks the actual highest-priority ticket — clicking a
  // specific row is a trigger, not a way to skip the priority order (see
  // DepartmentController::callNext). This deliberately differs from the
  // per-ticket PATCH /queue-tickets/{id}/call used by Doctor/Laboratory/
  // Pharmacy's row buttons, which calls that exact ticket with no
  // priority check at all.
  const callNextInDepartment = async (ticket) => {
    const departmentId = ticket.service?.department?.id;
    if (!departmentId) return;
    setActingId(ticket.id);
    try {
      const { data } = await apiClient.post(`/departments/${departmentId}/call-next`);
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
        // "Call" is the only action this column ever shows — once CALLED
        // (or any later status), the row is informational only (Register
        // lives exclusively on the "Register Patient" page).
        if (['WAITING', 'ON_HOLD'].includes(ticket.status)) {
          return (
            <Button size="small" loading={busy} onClick={() => callNextInDepartment(ticket)}>Call</Button>
          );
        }
        return <span style={{ color: '#8c8c8c' }}>—</span>;
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
          placeholder="Priority"
          allowClear
          style={{ width: 150 }}
          options={priorityLevels.map((p) => ({ label: p.name, value: p.id }))}
          onChange={(value) => updateFilter({ priority_level_id: value })}
        />
        <Button
          type="primary"
          icon={<ThunderboltOutlined />}
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
    </div>
  );
}
