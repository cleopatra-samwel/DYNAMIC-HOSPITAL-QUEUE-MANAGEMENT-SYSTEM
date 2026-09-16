import { useEffect, useState } from 'react';
import { Button, Form, Input, Modal, Table, Tag, Upload, message } from 'antd';
import { UploadOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import apiClient from '../../services/apiClient';

/**
 * Administrator-only. Every label seeded here starts as a short silent
 * placeholder .wav (see AudioClipSeeder on the backend) — this page is
 * how those get replaced with real recordings, without any code change:
 * uploading a label that already exists just overwrites it in place.
 */
export default function AudioClipLibraryPage() {
  const [clips, setClips] = useState([]);
  const [loading, setLoading] = useState(true);
  const [modalOpen, setModalOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [form] = Form.useForm();

  const load = () => {
    setLoading(true);
    apiClient.get('/audio-clips')
      .then(({ data }) => setClips(data.audio_clips))
      .catch(() => message.error('Could not load audio clips.'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  const submitUpload = async () => {
    try {
      const values = await form.validateFields();
      setSaving(true);
      const formData = new FormData();
      formData.append('label', values.label);
      formData.append('language', values.language || 'en');
      formData.append('file', values.file.file.originFileObj);

      await apiClient.post('/audio-clips', formData, { headers: { 'Content-Type': 'multipart/form-data' } });
      message.success(`Clip "${values.label}" uploaded.`);
      setModalOpen(false);
      form.resetFields();
      load();
    } catch (error) {
      if (error?.errorFields) return;
      message.error(error.response?.data?.message || 'Could not upload this clip.');
    } finally {
      setSaving(false);
    }
  };

  const columns = [
    { title: 'Label', dataIndex: 'label' },
    { title: 'Language', dataIndex: 'language', render: (v) => <Tag>{v}</Tag> },
    { title: 'Updated', dataIndex: 'updated_at', render: (v) => dayjs(v).format('DD MMM YYYY, HH:mm') },
    {
      title: 'Preview',
      render: (_, clip) => <audio controls src={clip.url} style={{ height: 32 }} />,
    },
  ];

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}>
        <Button type="primary" icon={<UploadOutlined />} onClick={() => setModalOpen(true)}>Upload / Replace Clip</Button>
      </div>

      <Table rowKey="id" columns={columns} dataSource={clips} loading={loading} />

      <Modal
        title="Upload / Replace Audio Clip"
        open={modalOpen}
        onCancel={() => setModalOpen(false)}
        onOk={submitUpload}
        confirmLoading={saving}
        okText="Upload"
        destroyOnHidden
      >
        <Form form={form} layout="vertical">
          <Form.Item
            label="Label"
            name="label"
            rules={[{ required: true, message: 'Label is required' }, { pattern: /^[a-z0-9_]+$/, message: 'Lowercase letters, digits, and underscores only (e.g. digit_7, dept_lab)' }]}
            extra="Uploading a label that already exists replaces it in place."
          >
            <Input placeholder="e.g. digit_7, letter_c, dept_lab, phrase_patient" />
          </Form.Item>
          <Form.Item label="Language" name="language" initialValue="en">
            <Input placeholder="en" />
          </Form.Item>
          <Form.Item
            label="Audio File (.mp3 or .wav)"
            name="file"
            rules={[{ required: true, message: 'A file is required' }]}
            valuePropName="file"
          >
            <Upload beforeUpload={() => false} maxCount={1} accept=".mp3,.wav">
              <Button icon={<UploadOutlined />}>Choose File</Button>
            </Upload>
          </Form.Item>
        </Form>
      </Modal>
    </div>
  );
}
