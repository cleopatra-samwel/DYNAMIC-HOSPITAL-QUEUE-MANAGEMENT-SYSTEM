import { useEffect, useState } from 'react';
import { Button, Table, Tag, message } from 'antd';
import { useSelector } from 'react-redux';
import dayjs from 'dayjs';
import apiClient from '../../services/apiClient';

export default function EmergencyPage() {
  const [visits, setVisits] = useState([]);
  const [loading, setLoading] = useState(true);
  const [confirmingId, setConfirmingId] = useState(null);
  // Checked against active_role, not just "is Doctor among this user's
  // GRANTED roles" — a multi-role user viewing this page while active as
  // a different role would otherwise see a Confirm button that just
  // 403s, since the Sanctum token's ability is scoped to active_role
  // (see User::hasRole()'s override on the backend).
  const isDoctor = useSelector((state) => state.auth.user?.active_role === 'Doctor');

  const loadVisits = () => {
    setLoading(true);
    apiClient.get('/visits', { params: { patient_type: 'Emergency' } })
      .then(({ data }) => setVisits(data.data))
      .catch(() => message.error('Could not load emergency visits.'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { loadVisits(); }, []);

  const handleConfirm = async (visit) => {
    setConfirmingId(visit.id);
    try {
      await apiClient.patch(`/visits/${visit.id}/confirm-emergency`);
      message.success('Emergency confirmed.');
      loadVisits();
    } catch (error) {
      message.error(error.response?.data?.message || 'Could not confirm emergency.');
    } finally {
      setConfirmingId(null);
    }
  };

  const columns = [
    { title: 'Visit Date', dataIndex: 'visit_date', render: (value) => dayjs(value).format('DD MMM YYYY') },
    { title: 'Patient', render: (_, visit) => `${visit.patient?.name} (${visit.patient?.patient_number})` },
    { title: 'Ticket', render: (_, visit) => visit.services?.[0]?.queue_ticket?.queue_number || '—' },
    { title: 'Department', render: (_, visit) => visit.services?.[0]?.department?.dept_name || '—' },
    {
      title: 'Status',
      render: (_, visit) => visit.emergency_confirmed
        ? <Tag color="green">Confirmed by Doctor</Tag>
        : <Tag color="orange">Pending Clinical Confirmation</Tag>,
    },
    {
      title: 'Action',
      render: (_, visit) => {
        if (visit.emergency_confirmed) return null;
        if (!isDoctor) return <Button size="small" disabled>Awaiting Doctor</Button>;
        return (
          <Button size="small" type="primary" loading={confirmingId === visit.id} onClick={() => handleConfirm(visit)}>
            Confirm
          </Button>
        );
      },
    },
  ];

  return <Table rowKey="id" columns={columns} dataSource={visits} loading={loading} />;
}
