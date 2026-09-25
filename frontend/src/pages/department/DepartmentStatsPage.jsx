import { Card, Col, Row, Spin } from 'antd';
import { Statistic } from 'antd';
import { useNavigate } from 'react-router-dom';
import OpenDisplayBoardButton from '../../components/OpenDisplayBoardButton';
import { STAT_COLORS, STAT_ICONS, useDepartmentStats } from './departmentStats';

const cardStyle = {};

/**
 * The "Dashboard" sidebar item for Doctor/Laboratory/Pharmacy — just the
 * summary cards, fetched live from GET /departments/{id}/stats. statCards:
 * [{ key, label, path }], key indexing into the stats response. path is
 * optional — a card whose figure has no dedicated page to drill into
 * (none currently) simply isn't clickable, same convention as
 * BillingDashboardPage.
 */
export default function DepartmentStatsPage({ deptCode, statCards }) {
  const navigate = useNavigate();
  const { department, stats, loading } = useDepartmentStats(deptCode);

  if (loading) return <Spin />;

  return (
    <>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}>
        <OpenDisplayBoardButton departmentId={department?.id} />
      </div>
      <Row gutter={[16, 16]}>
        {statCards.map((card) => (
          <Col xs={24} sm={12} md={8} lg={6} key={card.key}>
            <Card
              hoverable={!!card.path}
              className="stat-card" style={card.path ? { ...cardStyle, cursor: 'pointer' } : cardStyle}
              onClick={card.path ? () => navigate(card.path) : undefined}
            >
              <Statistic
                title={card.label}
                value={stats?.[card.key] ?? '—'}
                valueStyle={STAT_COLORS[card.key] ? { color: STAT_COLORS[card.key] } : undefined}
                prefix={STAT_ICONS[card.key]}
              />
            </Card>
          </Col>
        ))}
      </Row>
    </>
  );
}
