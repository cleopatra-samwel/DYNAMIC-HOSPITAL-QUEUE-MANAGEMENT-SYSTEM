import { useState } from 'react';
import { Button, message } from 'antd';
import { DesktopOutlined } from '@ant-design/icons';
import apiClient from '../services/apiClient';

/**
 * Opens this department's public Waiting Display board
 * (/waiting-display/:departmentId) in a new tab — the screen that plays
 * voice announcements when a ticket is called. Without that page open
 * somewhere, calling a ticket updates its status but nothing announces
 * it out loud, which is easy to miss for a department that's never had
 * one open before (e.g. Registration, once self-check-in gave it real
 * callable tickets). Pass a known departmentId directly, or a deptCode to
 * resolve one.
 */
export default function OpenDisplayBoardButton({ departmentId, deptCode }) {
  const [loading, setLoading] = useState(false);

  const open = async () => {
    let id = departmentId;

    if (!id) {
      setLoading(true);
      try {
        const { data } = await apiClient.get('/departments');
        id = data.departments.find((d) => d.dept_code === deptCode)?.id;
      } catch {
        message.error('Could not open the display board.');
        return;
      } finally {
        setLoading(false);
      }
    }

    if (!id) {
      message.error('Could not find this department.');
      return;
    }

    window.open(`/waiting-display/${id}`, '_blank', 'noopener');
  };

  return (
    <Button icon={<DesktopOutlined />} loading={loading} onClick={open}>
      Open Display Board
    </Button>
  );
}
