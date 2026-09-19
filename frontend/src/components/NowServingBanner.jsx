import { useEffect, useState } from 'react';
import { Alert } from 'antd';
import apiClient from '../services/apiClient';
import { getEcho } from '../services/echo';

/**
 * "NOW SERVING" indicator — Doctor-only (mounted from DoctorQueuePage.jsx,
 * not the shared DepartmentQueuePage.jsx that Laboratory/Pharmacy also
 * use). Sourced live from the same department.{id} Echo channel
 * DepartmentQueuePage already subscribes to elsewhere — no new realtime
 * subsystem. TicketCalled/TicketStatusChanged deliberately omit patient
 * name (same payload fans out to the public waiting-display), so the
 * queue number shows immediately and a quick follow-up fetch enriches it.
 */
export default function NowServingBanner({ deptCode }) {
  const [department, setDepartment] = useState(null);
  const [ticket, setTicket] = useState(null);

  const fetchTicket = (departmentId, status, ticketId) => {
    apiClient.get('/queue-tickets', { params: { department_id: departmentId, status } })
      .then(({ data }) => {
        const match = ticketId ? data.data.find((t) => t.id === ticketId) : data.data[0];
        if (match) setTicket(match);
      })
      .catch(() => {});
  };

  useEffect(() => {
    apiClient.get('/departments').then(({ data }) => {
      const mine = data.departments.find((d) => d.dept_code === deptCode);
      setDepartment(mine || null);
      if (mine) fetchTicket(mine.id, 'CALLED');
    }).catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [deptCode]);

  useEffect(() => {
    if (!department?.id) return undefined;

    const echo = getEcho();
    const channel = echo.private(`department.${department.id}`);

    const handleEvent = (payload) => {
      if (['CALLED', 'IN_SERVICE'].includes(payload.status)) {
        setTicket((prev) => ({
          ...(prev?.id === payload.ticket_id ? prev : {}),
          id: payload.ticket_id,
          queue_number: payload.queue_number,
          status: payload.status,
        }));
        fetchTicket(department.id, payload.status, payload.ticket_id);
      } else {
        setTicket((prev) => (prev?.id === payload.ticket_id ? null : prev));
      }
    };

    channel.listen('.TicketCalled', handleEvent);
    channel.listen('.TicketStatusChanged', handleEvent);

    return () => echo.leave(`department.${department.id}`);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [department?.id]);

  if (!ticket) return null;

  return (
    <Alert
      type="info"
      showIcon
      message={`NOW SERVING: ${ticket.queue_number} – ${ticket.service?.visit?.patient?.name || 'Patient'}`}
      style={{ marginBottom: 16 }}
    />
  );
}
