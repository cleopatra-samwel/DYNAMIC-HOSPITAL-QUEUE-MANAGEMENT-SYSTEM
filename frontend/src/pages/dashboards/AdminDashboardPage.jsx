import { useEffect, useState } from 'react';
import { Card, Col, Row, Statistic } from 'antd';
import { BarChartOutlined, ClockCircleOutlined, FieldTimeOutlined, TeamOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import apiClient from '../../services/apiClient';

const cardStyle = { cursor: 'pointer' };

/**
 * Administrator's own Dashboard — just the 4 summary cards wired to
 * GET /api/reports/system-summary (Phase 9). Deliberately nothing else
 * on this page (no carousel, no marketing copy) — the hero carousel that
 * used to live on the shared staff landing page was relocated to the
 * public TrackingPage instead, not duplicated here.
 *
 * Each card drills into the closest matching page: Patients Today/
 * Currently Waiting/Served Today all break down further on Reports &
 * Analytics (the only page with that detail); Longest Waiting has an
 * exact match in Long-Waiting Alerts.
 */
export default function AdminDashboardPage() {
  const navigate = useNavigate();
  const [summary, setSummary] = useState(null);

  useEffect(() => {
    apiClient.get('/reports/system-summary').then(({ data }) => setSummary(data)).catch(() => {});
  }, []);

  return (
    <Row gutter={[16, 16]}>
      <Col xs={12} md={6}>
        <Card hoverable className="stat-card" style={cardStyle} onClick={() => navigate('/admin/reports')}>
          <Statistic title="Patients Today" value={summary?.total_patients_today ?? 0} prefix={<TeamOutlined />} />
        </Card>
      </Col>
      <Col xs={12} md={6}>
        <Card hoverable className="stat-card" style={cardStyle} onClick={() => navigate('/admin/reports')}>
          <Statistic title="Currently Waiting" value={summary?.currently_waiting ?? 0} prefix={<ClockCircleOutlined />} />
        </Card>
      </Col>
      <Col xs={12} md={6}>
        <Card hoverable className="stat-card" style={cardStyle} onClick={() => navigate('/admin/reports')}>
          <Statistic title="Served Today" value={summary?.total_served_today ?? 0} prefix={<BarChartOutlined />} />
        </Card>
      </Col>
      <Col xs={12} md={6}>
        <Card hoverable className="stat-card" style={cardStyle} onClick={() => navigate('/admin/long-waiting-alerts')}>
          <Statistic
            title="Longest Waiting"
            value={summary?.longest_currently_waiting ? summary.longest_currently_waiting.waiting_minutes : 0}
            suffix="min"
            prefix={<FieldTimeOutlined />}
          />
        </Card>
      </Col>
    </Row>
  );
}
