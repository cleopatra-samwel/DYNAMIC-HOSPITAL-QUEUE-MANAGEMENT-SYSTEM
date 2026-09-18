import { useEffect, useState } from 'react';
import { Button, Input, Space, Table, Tag, message } from 'antd';
import { SearchOutlined, SoundOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import apiClient from '../../services/apiClient';
import { getEcho } from '../../services/echo';
import useConnectionState from '../../hooks/useConnectionState';
import useAnnouncementPlayer from '../../hooks/useAnnouncementPlayer';
import { ConnectedBadge } from '../../components/ConnectionIndicator';
import { formatStatusLabel } from '../../utils/formatLabel';
import TicketViewModal from '../../components/TicketViewModal';

const STATUS_COLORS = {
  CALLED: 'blue',
  IN_SERVICE: 'geekblue',
};

/**
 * "Register Patient" — replaces the old search-existing-patients page. A
 * ticket only lands here once it's been called (see QueuePage's "Call"
 * button, which still does the priority-based calling); Register is the
 * only action that lives here now, not on Queue.
 *
 * The registration form inside TicketViewModal only renders once the
 * ticket is IN_SERVICE (not CALLED), and ServiceFlowController::store's
 * Registration-handoff authorization requires IN_SERVICE too, so clicking
 * Register on a CALLED row silently runs the CALLED -> IN_SERVICE
 * transition first, then opens the (unmodified) existing modal already
 * showing the pre-filled form. An IN_SERVICE row (e.g. the modal was
 * closed earlier without submitting) just reopens directly.
 */
export default function RegisterPatientPage() {
  const [department, setDepartment] = useState(null);
  const [tickets, setTickets] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [actingId, setActingId] = useState(null);
  const [viewingTicket, setViewingTicket] = useState(null);
  const { audioEnabled, enableAudio } = useAnnouncementPlayer();

  const loadTickets = (departmentId, q = search) => {
    if (!departmentId) return;
    setLoading(true);
    const params = { department_id: departmentId };
    if (q) params.q = q;
    apiClient.get('/queue-tickets', { params })
      .then(({ data }) => setTickets(data.data.filter((t) => ['CALLED', 'IN_SERVICE'].includes(t.status))))
      .catch(() => message.error('Could not load patients awaiting registration.'))
      .finally(() => setLoading(false));
  };

  const refresh = () => {
    if (department) loadTickets(department.id);
  };

  useEffect(() => {
    apiClient.get('/departments').then(({ data }) => {
      const reg = data.departments.find((d) => d.dept_code === 'REG');
      setDepartment(reg || null);
      loadTickets(reg?.id);
    }).catch(() => message.error('Could not load department.'));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const connectionState = useConnectionState(refresh);

  useEffect(() => {
    if (!department?.id) return undefined;

    const echo = getEcho();
    const channel = echo.private(`department.${department.id}`);

    // A ticket entering CALLED needs to show up here, and one being
    // forwarded (COMPLETED) needs to drop off — either way a refetch is
    // simpler and safer than trying to patch this list's membership
    // in place.
    const handleChange = () => refresh();

    channel.listen('.TicketCalled', handleChange);
    channel.listen('.TicketStatusChanged', handleChange);

    return () => echo.leave(`department.${department.id}`);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [department?.id]);

  const openRegister = async (ticket) => {
    if (ticket.status === 'IN_SERVICE') {
      setViewingTicket(ticket);
      return;
    }
    setActingId(ticket.id);
    try {
      const { data } = await apiClient.patch(`/queue-tickets/${ticket.id}/start-service`);
      setViewingTicket(data.ticket);
    } catch (error) {
      message.error(error.response?.data?.message || 'Could not open registration for this ticket.');
    } finally {
      setActingId(null);
    }
  };

  const columns = [
    { title: 'Ticket', dataIndex: 'queue_number' },
    { title: 'Patient', render: (_, t) => t.service?.visit?.patient?.name || '—' },
    { title: 'Contact', render: (_, t) => t.service?.visit?.patient?.contact || '—' },
    { title: 'Status', dataIndex: 'status', render: (value) => <Tag color={STATUS_COLORS[value] || 'default'}>{formatStatusLabel(value)}</Tag> },
    { title: 'Called At', dataIndex: 'called_at', render: (value) => (value ? dayjs(value).format('DD MMM HH:mm') : '—') },
    {
      title: 'Action',
      render: (_, ticket) => (
        <Button size="small" type="primary" loading={actingId === ticket.id} onClick={() => openRegister(ticket)}>Register</Button>
      ),
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
      />
    </div>
  );
}
