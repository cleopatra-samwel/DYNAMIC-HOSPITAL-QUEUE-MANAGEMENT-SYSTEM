import { useEffect, useState } from 'react';
import { Alert, Card, Col, Row, Spin, Statistic } from 'antd';
import { AlertOutlined, ClockCircleOutlined, SmileOutlined, SolutionOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import dayjs from 'dayjs';
import apiClient from '../../services/apiClient';
import OpenDisplayBoardButton from '../../components/OpenDisplayBoardButton';

const cardStyle = { cursor: 'pointer' };

export default function RegistrationDashboardPage() {
  const navigate = useNavigate();
  const [stats, setStats] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    apiClient.get('/visits/stats/today')
      .then(({ data }) => { if (!cancelled) setStats(data); })
      .catch(() => { if (!cancelled) setError('Could not load today’s stats.'); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  if (loading) return <Spin />;
  if (error) return <Alert type="error" showIcon message={error} />;

  return (
    <>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}>
        <OpenDisplayBoardButton deptCode="REG" />
      </div>
      <Row gutter={[16, 16]}>
        <Col xs={24} sm={12} md={6}>
          <Card hoverable className="stat-card" style={cardStyle} onClick={() => navigate('/registration/queue')}>
            <Statistic title="Waiting Queue" value={stats.waiting} valueStyle={{ color: '#b8860b' }} prefix={<ClockCircleOutlined />} />
          </Card>
        </Col>
        <Col xs={24} sm={12} md={6}>
          <Card hoverable className="stat-card" style={cardStyle} onClick={() => navigate('/registration/emergency')}>
            <Statistic title="Emergency" value={stats.emergency} valueStyle={{ color: '#8c1d2d' }} prefix={<AlertOutlined />} />
          </Card>
        </Col>
        <Col xs={24} sm={12} md={6}>
          <Card hoverable className="stat-card" style={cardStyle} onClick={() => navigate('/registration/visits', { state: { patientType: 'Normal' } })}>
            <Statistic title="Normal" value={stats.normal} valueStyle={{ color: '#8c1d2d' }} prefix={<SmileOutlined />} />
          </Card>
        </Col>
        <Col xs={24} sm={12} md={6}>
          <Card hoverable className="stat-card" style={cardStyle} onClick={() => navigate('/registration/visits', { state: { date: dayjs().format('YYYY-MM-DD') } })}>
            <Statistic title="Visit Today" value={stats.total} prefix={<SolutionOutlined />} />
          </Card>
        </Col>
      </Row>
    </>
  );
}
