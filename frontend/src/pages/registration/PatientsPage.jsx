import { useEffect, useState } from 'react';
import { Button, Descriptions, Input, Modal, Table, message } from 'antd';
import { SearchOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import apiClient from '../../services/apiClient';

/**
 * Search/view of already-registered patients. Registering a NEW patient
 * no longer happens here — every patient starts as a self check-in (see
 * CheckInPage), landing directly in Registration's own queue with a real
 * ticket (see QueuePage's "Complete Registration" action).
 */
export default function PatientsPage() {
  const [patients, setPatients] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [viewPatient, setViewPatient] = useState(null);

  const loadPatients = (q = search) => {
    setLoading(true);
    apiClient.get('/patients', { params: q ? { q } : {} })
      .then(({ data }) => setPatients(data.data))
      .catch(() => message.error('Could not load patients.'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    loadPatients('');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const columns = [
    { title: 'Patient No.', dataIndex: 'patient_number' },
    { title: 'Name', dataIndex: 'name' },
    { title: 'Date of Birth', dataIndex: 'date_of_birth', render: (value) => dayjs(value).format('DD MMM YYYY') },
    { title: 'Gender', dataIndex: 'gender' },
    { title: 'Contact', dataIndex: 'contact' },
    {
      title: 'Action',
      render: (_, record) => <Button size="small" onClick={() => setViewPatient(record)}>View</Button>,
    },
  ];

  return (
    <div>
      <div style={{ marginBottom: 16 }}>
        <Input.Search
          placeholder="Search by name or patient number"
          allowClear
          prefix={<SearchOutlined />}
          style={{ maxWidth: 320 }}
          onSearch={(value) => { setSearch(value); loadPatients(value); }}
        />
      </div>

      <Table rowKey="id" columns={columns} dataSource={patients} loading={loading} />

      <Modal title="Patient Details" open={!!viewPatient} onCancel={() => setViewPatient(null)} footer={null}>
        {viewPatient && (
          <Descriptions column={1} bordered size="small">
            <Descriptions.Item label="Patient No.">{viewPatient.patient_number}</Descriptions.Item>
            <Descriptions.Item label="Name">{viewPatient.name}</Descriptions.Item>
            <Descriptions.Item label="Date of Birth">{dayjs(viewPatient.date_of_birth).format('DD MMM YYYY')}</Descriptions.Item>
            <Descriptions.Item label="Gender">{viewPatient.gender}</Descriptions.Item>
            <Descriptions.Item label="Contact">{viewPatient.contact}</Descriptions.Item>
          </Descriptions>
        )}
      </Modal>
    </div>
  );
}
