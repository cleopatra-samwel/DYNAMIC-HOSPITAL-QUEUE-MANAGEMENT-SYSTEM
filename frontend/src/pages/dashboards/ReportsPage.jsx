import { useEffect, useState } from 'react';
import { Button, Card, Col, DatePicker, Descriptions, Empty, Modal, Row, Select, Spin, Table, message } from 'antd';
import { DownloadOutlined, EyeOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import apiClient from '../../services/apiClient';

const REPORTS = [
  { key: 'daily-patients', title: 'Daily Patients', description: 'Total visits, Normal vs Emergency, and completed count for a given day.' },
  { key: 'waiting-time', title: 'Waiting-Time', description: 'Average and maximum wait (created → called) per department.' },
  { key: 'department', title: 'Department', description: 'Served/waiting tickets, average priority score at call, and no-shows for one department.', needsDepartment: true },
  { key: 'queue-performance', title: 'Queue Performance', description: 'System-wide served, waiting, no-show, and cancelled totals.' },
  { key: 'emergency', title: 'Emergency', description: 'Emergency visit counts, confirmation status, and average time to being called.' },
];

const LABEL_OVERRIDES = {
  date: 'Date',
  department_id: 'Department ID',
  department_name: 'Department',
};

function labelFor(key) {
  return LABEL_OVERRIDES[key] || key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatValue(value) {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  return String(value);
}

/**
 * Administrator-only. Every report shares one View/Download flow: a Modal
 * (this app's established pattern for viewing something without leaving
 * the page — see e.g. InsuranceCardsPage's release modal, PatientsPage's
 * details modal) shows the figures, and Download PDF fetches the exact
 * same report as a blob and hands it to the browser — no separate
 * "generated document" UI pattern invented for this.
 */
export default function ReportsPage() {
  const [departments, setDepartments] = useState([]);
  const [dates, setDates] = useState(() => Object.fromEntries(REPORTS.map((r) => [r.key, dayjs()])));
  const [selectedDepartment, setSelectedDepartment] = useState(null);
  const [viewing, setViewing] = useState(null); // { key, title, data }
  const [loadingKey, setLoadingKey] = useState(null);
  const [downloading, setDownloading] = useState(false);

  useEffect(() => {
    apiClient.get('/departments').then(({ data }) => setDepartments(data.departments)).catch(() => {});
  }, []);

  const paramsFor = (key) => {
    const date = dates[key]?.format('YYYY-MM-DD');
    const params = { date };
    if (key === 'department') {
      if (!selectedDepartment) return null;
      params.department_id = selectedDepartment;
    }
    return params;
  };

  const view = async (report) => {
    const params = paramsFor(report.key);
    if (params === null) {
      message.warning('Choose a department first.');
      return;
    }
    setLoadingKey(report.key);
    try {
      const { data } = await apiClient.get(`/reports/${report.key}`, { params });
      setViewing({ key: report.key, title: report.title, data });
    } catch (error) {
      message.error(error.response?.data?.message || 'Could not load this report.');
    } finally {
      setLoadingKey(null);
    }
  };

  const downloadPdf = async () => {
    if (!viewing) return;
    setDownloading(true);
    try {
      const params = paramsFor(viewing.key);
      const response = await apiClient.get(`/reports/${viewing.key}/pdf`, { params, responseType: 'blob' });
      const url = window.URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
      window.open(url, '_blank');
    } catch {
      message.error('Could not download this report.');
    } finally {
      setDownloading(false);
    }
  };

  const renderReportBody = (data) => {
    const scalarEntries = Object.entries(data).filter(([, v]) => !Array.isArray(v) && typeof v !== 'object');
    const listEntries = Object.entries(data).filter(([, v]) => Array.isArray(v));

    return (
      <>
        <Descriptions bordered size="small" column={1} style={{ marginBottom: listEntries.length ? 16 : 0 }}>
          {scalarEntries.map(([key, value]) => (
            <Descriptions.Item key={key} label={labelFor(key)}>{formatValue(value)}</Descriptions.Item>
          ))}
        </Descriptions>

        {listEntries.map(([key, rows]) => (
          <div key={key} style={{ marginBottom: 16 }}>
            <h4>{labelFor(key)}</h4>
            {rows.length === 0 ? (
              <Empty description="No data" />
            ) : (
              <Table
                rowKey={(row, i) => row.department_id ?? i}
                size="small"
                pagination={false}
                dataSource={rows}
                columns={Object.keys(rows[0]).map((col) => ({ title: labelFor(col), dataIndex: col, render: formatValue }))}
              />
            )}
          </div>
        ))}
      </>
    );
  };

  return (
    <div>
      <Row gutter={[16, 16]}>
        {REPORTS.map((report) => (
          <Col xs={24} md={12} xl={8} key={report.key}>
            <Card title={report.title} size="small">
              <p style={{ color: '#8c8c8c', minHeight: 44 }}>{report.description}</p>
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                <DatePicker
                  value={dates[report.key]}
                  onChange={(value) => setDates((prev) => ({ ...prev, [report.key]: value || dayjs() }))}
                  allowClear={false}
                />
                {report.needsDepartment && (
                  <Select
                    placeholder="Department"
                    style={{ minWidth: 140 }}
                    options={departments.map((d) => ({ label: d.dept_name, value: d.id }))}
                    value={selectedDepartment}
                    onChange={setSelectedDepartment}
                  />
                )}
                <Button icon={<EyeOutlined />} loading={loadingKey === report.key} onClick={() => view(report)}>
                  View
                </Button>
              </div>
            </Card>
          </Col>
        ))}
      </Row>

      <Modal
        title={viewing?.title}
        open={!!viewing}
        onCancel={() => setViewing(null)}
        footer={[
          <Button key="download" icon={<DownloadOutlined />} loading={downloading} onClick={downloadPdf}>
            Download PDF
          </Button>,
          <Button key="close" type="primary" onClick={() => setViewing(null)}>Close</Button>,
        ]}
        width={640}
        destroyOnHidden
      >
        {viewing ? renderReportBody(viewing.data) : <Spin />}
      </Modal>
    </div>
  );
}
