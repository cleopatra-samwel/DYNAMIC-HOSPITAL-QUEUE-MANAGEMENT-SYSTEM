import { useEffect, useState } from 'react';
import { Alert, Card, Col, Row, Spin, Statistic } from 'antd';
import { CheckCircleOutlined, ClockCircleOutlined, SafetyCertificateOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import apiClient from '../../services/apiClient';
import OpenDisplayBoardButton from '../../components/OpenDisplayBoardButton';

const cardStyle = { borderLeft: '4px solid #8c1d2d' };

/**
 * Only "Pending Payments" links out (to /billing/pending-payments) —
 * Billing has no page breaking down already-verified cash/insurance
 * totals by patient, so those two cards stay red-bordered but
 * non-clickable rather than pointing at a page that doesn't actually
 * show that data.
 */
export default function BillingDashboardPage() {
  const navigate = useNavigate();
  const [stats, setStats] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    apiClient.get('/payments/stats')
      .then(({ data }) => setStats(data))
      .catch(() => setError('Could not load billing stats.'))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <Spin />;
  if (error) return <Alert type="error" showIcon message={error} />;

  return (
    <>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}>
        <OpenDisplayBoardButton deptCode="BILL" />
      </div>
      <Row gutter={16}>
        <Col xs={24} sm={8}>
          <Card hoverable style={{ ...cardStyle, cursor: 'pointer' }} onClick={() => navigate('/billing/pending-payments')}>
            <Statistic title="Pending Payments" value={stats.pending_payments} valueStyle={{ color: '#8c1d2d' }} prefix={<ClockCircleOutlined />} />
          </Card>
        </Col>
        <Col xs={24} sm={8}>
          <Card style={cardStyle}>
            <Statistic title="Cash Collected Today" value={stats.cash_collected_today} precision={2} valueStyle={{ color: '#1a7f37' }} prefix={<CheckCircleOutlined />} />
          </Card>
        </Col>
        <Col xs={24} sm={8}>
          <Card style={cardStyle}>
            <Statistic title="Insurance Claims Verified Today" value={stats.insurance_claims_verified_today} prefix={<SafetyCertificateOutlined />} />
          </Card>
        </Col>
      </Row>
    </>
  );
}
