import { useState } from 'react';
import { Button, DatePicker, Form, Input, Result, Select } from 'antd';
import axios from 'axios';
import BrandHeader from '../../components/BrandHeader';

const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api';

/**
 * Public self check-in — reached via a STATIC QR code posted at the
 * hospital entrance (same URL for everyone, unlike the per-visit tracking
 * QR from Phase 8 — do not confuse the two). No auth. Submitting this form
 * immediately creates a real Registration ticket (see
 * CheckInController::store) — the returned queue_number is shown right
 * away so the patient knows they're genuinely in the queue, not just on
 * an unordered list.
 */
export default function CheckInPage() {
  const [form] = Form.useForm();
  const [submitting, setSubmitting] = useState(false);
  const [queueNumber, setQueueNumber] = useState(null);
  const [error, setError] = useState('');

  const onFinish = async (values) => {
    setSubmitting(true);
    setError('');
    try {
      const { data } = await axios.post(`${API_BASE}/check-ins`, {
        name: values.name,
        date_of_birth: values.date_of_birth.format('YYYY-MM-DD'),
        gender: values.gender,
        contact: values.contact,
      });
      setQueueNumber(data.queue_number);
    } catch (err) {
      setError(err.response?.data?.message || 'Could not submit check-in. Please see a member of staff.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="app-frame login-frame">
      <BrandHeader subtitle="Self Check-In" />
      <main className="login-content">
        <section className="login-card">
          {queueNumber ? (
            <Result
              status="success"
              title={`Your queue number: ${queueNumber}`}
              subTitle="Please have a seat. You'll be called to the Registration counter shortly."
            />
          ) : (
            <>
              <h2 style={{ marginTop: 0 }}>Welcome — Please Check In</h2>
              <p className="login-subtitle">Fill in your details below. Staff will call you when it's your turn to register.</p>

              {error && <p style={{ color: '#cf1322', marginBottom: 16 }}>{error}</p>}

              <Form form={form} layout="vertical" requiredMark={false} onFinish={onFinish}>
                <Form.Item label="Full Name" name="name" rules={[{ required: true, message: 'Full name is required' }]}>
                  <Input placeholder="Full Name" />
                </Form.Item>
                <Form.Item label="Date of Birth" name="date_of_birth" rules={[{ required: true, message: 'Date of birth is required' }]}>
                  <DatePicker style={{ width: '100%' }} disabledDate={(current) => current && current.valueOf() > Date.now()} format="YYYY-MM-DD" placeholder="Date of Birth" />
                </Form.Item>
                <Form.Item label="Gender" name="gender" rules={[{ required: true, message: 'Gender is required' }]}>
                  <Select
                    placeholder="Select Gender"
                    options={[{ label: 'Male', value: 'Male' }, { label: 'Female', value: 'Female' }, { label: 'Other', value: 'Other' }]}
                  />
                </Form.Item>
                <Form.Item label="Contact (Phone Number)" name="contact" rules={[{ required: true, message: 'Contact is required' }]}>
                  <Input placeholder="e.g. 0700000000" />
                </Form.Item>
                <Button className="login-submit" type="primary" htmlType="submit" block loading={submitting}>
                  Check In
                </Button>
              </Form>
            </>
          )}
        </section>
      </main>
    </div>
  );
}
