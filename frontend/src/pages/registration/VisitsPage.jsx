import { useEffect, useState } from 'react';
import { Input, Select, Space, Table, Tag, message } from 'antd';
import { SearchOutlined } from '@ant-design/icons';
import { useLocation } from 'react-router-dom';
import dayjs from 'dayjs';
import apiClient from '../../services/apiClient';
import { formatStatusLabel } from '../../utils/formatLabel';

export default function VisitsPage() {
  const location = useLocation();
  const [visits, setVisits] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filters, setFilters] = useState({
    q: '',
    patient_type: location.state?.patientType,
    date: location.state?.date,
  });

  const loadVisits = (params = filters) => {
    setLoading(true);
    const query = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== ''));
    apiClient.get('/visits', { params: query })
      .then(({ data }) => setVisits(data.data))
      .catch(() => message.error('Could not load visits.'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { loadVisits(filters); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, []);

  const updateFilter = (patch) => {
    const next = { ...filters, ...patch };
    setFilters(next);
    loadVisits(next);
  };

  const columns = [
    { title: 'Visit Date', dataIndex: 'visit_date', render: (value) => dayjs(value).format('DD MMM YYYY') },
    { title: 'Patient', render: (_, visit) => `${visit.patient?.name} (${visit.patient?.patient_number})` },
    { title: 'Ticket', render: (_, visit) => visit.services?.[0]?.queue_ticket?.queue_number || '—' },
    { title: 'Department', render: (_, visit) => visit.services?.[0]?.department?.dept_name || '—' },
    {
      title: 'Category',
      dataIndex: 'patient_type',
      render: (value) => <Tag color={value === 'Emergency' ? 'red' : 'blue'}>{value}</Tag>,
    },
    { title: 'Payment Method', dataIndex: 'payment_method' },
    { title: 'Status', dataIndex: 'overall_status', render: (value) => formatStatusLabel(value) },
  ];

  return (
    <div>
      <Space wrap style={{ marginBottom: 16 }}>
        <Input.Search
          placeholder="Search by patient name or patient number"
          allowClear
          prefix={<SearchOutlined />}
          style={{ width: 280 }}
          defaultValue={filters.q}
          onSearch={(value) => updateFilter({ q: value })}
        />
        <Select
          placeholder="Category"
          allowClear
          style={{ width: 160 }}
          value={filters.patient_type}
          options={[{ label: 'Normal', value: 'Normal' }, { label: 'Emergency', value: 'Emergency' }]}
          onChange={(value) => updateFilter({ patient_type: value })}
        />
        {filters.date && (
          <Tag closable onClose={() => updateFilter({ date: undefined })}>Date: {dayjs(filters.date).format('DD MMM YYYY')}</Tag>
        )}
      </Space>
      <Table rowKey="id" columns={columns} dataSource={visits} loading={loading} />
    </div>
  );
}
