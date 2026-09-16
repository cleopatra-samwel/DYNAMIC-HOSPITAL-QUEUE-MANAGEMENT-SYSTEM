import { useEffect, useState } from 'react';
import { AlertOutlined, CheckCircleOutlined, ClockCircleOutlined, DollarOutlined, SolutionOutlined } from '@ant-design/icons';
import apiClient from '../../services/apiClient';

export const STAT_ICONS = {
  waiting: <ClockCircleOutlined />,
  in_service: <SolutionOutlined />,
  completed_today: <CheckCircleOutlined />,
  urgent: <AlertOutlined />,
  emergency_unconfirmed: <AlertOutlined />,
  pending_payment: <DollarOutlined />,
};

export const STAT_COLORS = {
  in_service: '#8c1d2d',
  completed_today: '#1a7f37',
  urgent: '#8c1d2d',
  emergency_unconfirmed: '#8c1d2d',
  pending_payment: '#b8860b',
};

/** Resolves a department by dept_code, then fetches its live stats. */
export function useDepartmentStats(deptCode) {
  const [department, setDepartment] = useState(null);
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    apiClient.get('/departments').then(({ data }) => {
      if (cancelled) return;
      const mine = data.departments.find((d) => d.dept_code === deptCode) || null;
      setDepartment(mine);
      if (!mine) {
        setLoading(false);
        return;
      }
      apiClient.get(`/departments/${mine.id}/stats`)
        .then(({ data: statsData }) => { if (!cancelled) setStats(statsData); })
        .finally(() => { if (!cancelled) setLoading(false); });
    }).catch(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [deptCode]);

  return { department, stats, loading };
}
