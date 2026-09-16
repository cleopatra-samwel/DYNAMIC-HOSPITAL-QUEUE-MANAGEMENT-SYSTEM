import { useEffect, useState } from 'react';
import { Alert, Button, Checkbox, DatePicker, Descriptions, Divider, Form, Input, Modal, Radio, Select, Spin, Typography, message } from 'antd';
import dayjs from 'dayjs';
import apiClient from '../services/apiClient';
import { formatStatusLabel } from '../utils/formatLabel';
import { groupByCategory } from '../utils/labTestCategories';

const { Text } = Typography;
const { TextArea } = Input;

// Reuses the app's existing brand maroon (ConfigProvider's colorPrimary in
// App.jsx, also hardcoded on every dashboard's stat-card border) — no new
// color introduced.
const CATEGORY_HEADER_STYLE = {
  background: '#8c1d2d',
  color: '#fff',
  padding: '6px 12px',
  fontWeight: 600,
  borderRadius: 4,
  marginBottom: 8,
};

const WAITING_STATUSES = ['WAITING', 'ON_HOLD'];

/**
 * Unified "View" entry point (Structured Laboratory Request Form's
 * follow-up project) — one modal, opened from a single "View" button on
 * every queue table (Registration/Doctor/Laboratory/Pharmacy), replacing
 * the scattered Complete/Request Laboratory/Send to Pharmacy/Forward to
 * Doctor buttons that used to sit directly on each row. Call/Start
 * Service stay as separate quick-access row buttons — nothing here
 * duplicates those, it only offers them again INLINE when a ticket isn't
 * far enough along yet for the full form to make sense (see the
 * status-gated footer button below).
 *
 * Branches entirely on the ticket's OWN department code, not on a
 * `role` prop — the caller already only renders the "View" button for
 * departments this staff member can act on (DepartmentRoles), so by the
 * time this opens, dept_code reliably says which form to show.
 *
 * Every action closes the modal and calls `onChanged` to refresh the
 * underlying table, rather than trying to keep this modal's own state in
 * sync with a status transition it just caused — reopening "View" shows
 * the next stage fresh.
 */
export default function TicketViewModal({ ticket, open, onClose, onChanged, onTicketCalled }) {
  const deptCode = ticket?.service?.department?.dept_code;
  const visitId = ticket?.service?.visit?.id;
  const serviceId = ticket?.service?.id;
  const patient = ticket?.service?.visit?.patient;

  const [record, setRecord] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [quickActing, setQuickActing] = useState(false);
  const [form] = Form.useForm();

  // Doctor + Registration share these (target department / doctor pick).
  const [departments, setDepartments] = useState([]);
  const [doctors, setDoctors] = useState([]);
  const departmentsByCode = Object.fromEntries(departments.map((d) => [d.dept_code, d]));

  // Laboratory request checklist — Doctor (editable) and Laboratory Staff (read-only).
  const [labCatalog, setLabCatalog] = useState([]);
  const [selectedTestIds, setSelectedTestIds] = useState([]);
  const [otherText, setOtherText] = useState('');

  // Laboratory Staff's own choice of where to send the patient next.
  const [labForwardTarget, setLabForwardTarget] = useState('CONS');

  // Pharmacy's own free-text note — services.notes, not a clinical_records field.
  const [dispensingNotes, setDispensingNotes] = useState('');

  const isInService = ticket?.status === 'IN_SERVICE';
  const isWaiting = WAITING_STATUSES.includes(ticket?.status);
  const isCalled = ticket?.status === 'CALLED';

  useEffect(() => {
    if (!open || !visitId) return;
    setLoading(true);
    setError('');
    apiClient.get(`/visits/${visitId}/clinical-record`)
      .then(({ data }) => {
        setRecord(data.clinical_record);
        form.setFieldsValue(data.clinical_record);
      })
      .catch((err) => setError(err.response?.data?.message || 'Could not load the clinical record.'))
      .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, visitId]);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/departments').then(({ data }) => setDepartments(data.departments)).catch(() => {});
    if (deptCode === 'REG' || deptCode === 'CONS') {
      apiClient.get('/doctors').then(({ data }) => setDoctors(data.doctors)).catch(() => {});
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, deptCode]);

  useEffect(() => {
    if (!open || !serviceId || (deptCode !== 'CONS' && deptCode !== 'LAB')) return;
    apiClient.get('/lab-test-catalog').then(({ data }) => setLabCatalog(data.lab_tests.filter((t) => t.active))).catch(() => {});
    apiClient.get(`/services/${serviceId}/requested-tests`)
      .then(({ data }) => {
        setSelectedTestIds(data.lab_test_catalog_ids);
        setOtherText(data.other || '');
      })
      .catch(() => {});
  }, [open, serviceId, deptCode]);

  useEffect(() => {
    if (!open) return;
    setDispensingNotes(ticket?.service?.notes || '');
    setLabForwardTarget('CONS');
    if (deptCode === 'REG' && patient) {
      form.setFieldsValue({
        name: patient.name,
        date_of_birth: patient.date_of_birth ? dayjs(patient.date_of_birth) : null,
        gender: patient.gender,
        contact: patient.contact,
        patient_type: 'Normal',
        payment_method: 'Cash',
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const toggleTest = (id, checked) => {
    setSelectedTestIds((prev) => (checked ? [...prev, id] : prev.filter((t) => t !== id)));
  };

  const catalogGroups = groupByCategory(labCatalog);
  const selectedGroups = catalogGroups
    .map((group) => ({ category: group.category, items: group.items.filter((i) => selectedTestIds.includes(i.id)) }))
    .filter((group) => group.items.length > 0);

  const runQuickAction = async (action) => {
    setQuickActing(true);
    try {
      await apiClient.patch(`/queue-tickets/${ticket.id}/${action}`);
      message.success(action === 'call' ? `${ticket.queue_number} called.` : `${ticket.queue_number} moved to in-service.`);
      if (action === 'call') onTicketCalled?.(ticket.id);
      onChanged?.();
      onClose();
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not update this ticket.');
    } finally {
      setQuickActing(false);
    }
  };

  const submitRegistration = async () => {
    try {
      const values = await form.validateFields();
      setSubmitting(true);
      const patientChanged = values.name !== patient.name
        || values.date_of_birth.format('YYYY-MM-DD') !== patient.date_of_birth
        || values.gender !== patient.gender
        || values.contact !== patient.contact;
      if (patientChanged) {
        await apiClient.put(`/patients/${patient.id}`, {
          name: values.name,
          date_of_birth: values.date_of_birth.format('YYYY-MM-DD'),
          gender: values.gender,
          contact: values.contact,
        });
      }
      if (values.chief_complaint) {
        await apiClient.patch(`/visits/${visitId}/clinical-record`, { chief_complaint: values.chief_complaint });
      }
      await apiClient.patch(`/visits/${visitId}/registration-details`, {
        patient_type: values.patient_type,
        payment_method: values.payment_method,
        insurance_provider: values.payment_method === 'Insurance' ? values.insurance_provider : undefined,
        insurance_ref: values.payment_method === 'Insurance' ? values.insurance_ref : undefined,
      });
      await apiClient.post(`/visits/${visitId}/services`, {
        department_id: values.department_id,
        doctor_id: values.doctor_id || undefined,
      });
      message.success(`${values.name} registered and forwarded to their next department.`);
      onChanged?.();
      onClose();
    } catch (err) {
      if (err?.errorFields) return;
      message.error(err.response?.data?.message || 'Could not complete registration.');
    } finally {
      setSubmitting(false);
    }
  };

  const submitDoctor = async () => {
    try {
      const values = await form.validateFields([
        'doctor_symptoms_notes', 'doctor_preliminary_diagnosis', 'referral_target',
        'final_diagnosis', 'treatment_plan', 'patient_signature_name', 'patient_signature_phone',
      ]);
      setSubmitting(true);
      await apiClient.patch(`/visits/${visitId}/clinical-record`, values);
      await apiClient.put(`/services/${serviceId}/requested-tests`, {
        lab_test_catalog_ids: selectedTestIds,
        other: otherText || null,
      });

      if (values.referral_target === 'laboratory') {
        await apiClient.post(`/visits/${visitId}/services`, { department_id: departmentsByCode.LAB.id });
      } else if (values.referral_target === 'pharmacy') {
        await apiClient.post(`/visits/${visitId}/services`, { department_id: departmentsByCode.PHARM.id });
      } else {
        await apiClient.patch(`/queue-tickets/${ticket.id}/complete`);
      }

      message.success('Saved and forwarded.');
      onChanged?.();
      onClose();
    } catch (err) {
      if (err?.errorFields) return;
      message.error(err.response?.data?.message || 'Could not save and forward.');
    } finally {
      setSubmitting(false);
    }
  };

  const submitLab = async () => {
    try {
      const values = await form.validateFields(['lab_results_notes']);
      setSubmitting(true);
      await apiClient.patch(`/visits/${visitId}/clinical-record`, values);
      await apiClient.post(`/visits/${visitId}/services`, { department_id: departmentsByCode[labForwardTarget].id });
      message.success('Saved and forwarded.');
      onChanged?.();
      onClose();
    } catch (err) {
      if (err?.errorFields) return;
      message.error(err.response?.data?.message || 'Could not save and forward.');
    } finally {
      setSubmitting(false);
    }
  };

  const submitPharmacy = async () => {
    setSubmitting(true);
    try {
      if (dispensingNotes) {
        await apiClient.patch(`/services/${serviceId}/notes`, { notes: dispensingNotes });
      }
      await apiClient.patch(`/queue-tickets/${ticket.id}/complete`);
      try {
        await apiClient.patch(`/visits/${visitId}/complete`);
        message.success('Completed and visit closed.');
      } catch (closeError) {
        message.warning(`Completed, but the visit could not be closed yet: ${closeError.response?.data?.message || 'other services are still open.'}`);
      }
      onChanged?.();
      onClose();
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not complete this ticket.');
    } finally {
      setSubmitting(false);
    }
  };

  const submitByDept = { REG: submitRegistration, CONS: submitDoctor, LAB: submitLab, PHARM: submitPharmacy };

  const footer = [
    <Button key="close" onClick={onClose}>Close</Button>,
  ];
  if (isWaiting) {
    footer.push(<Button key="call" type="primary" loading={quickActing} onClick={() => runQuickAction('call')}>Call</Button>);
  } else if (isCalled) {
    footer.push(<Button key="start" type="primary" loading={quickActing} onClick={() => runQuickAction('start-service')}>Start Service</Button>);
  } else if (isInService) {
    footer.push(<Button key="submit" type="primary" loading={submitting} onClick={submitByDept[deptCode]}>Submit &amp; Forward</Button>);
  }

  const summary = (
    <Descriptions column={2} size="small" bordered style={{ marginBottom: 16 }}>
      <Descriptions.Item label="Patient">{patient?.name || '—'}</Descriptions.Item>
      <Descriptions.Item label="Ticket">{ticket?.queue_number}</Descriptions.Item>
      <Descriptions.Item label="Department">{ticket?.service?.department?.dept_name}</Descriptions.Item>
      <Descriptions.Item label="Priority">{ticket?.priority_level?.name || '—'}</Descriptions.Item>
      <Descriptions.Item label="Status">{formatStatusLabel(ticket?.status)}</Descriptions.Item>
      <Descriptions.Item label="Payment Status">{ticket?.service?.visit?.payment_status || 'Pending'}</Descriptions.Item>
    </Descriptions>
  );

  return (
    <Modal
      title={`${deptCode === 'REG' ? 'Register Patient' : 'View'} — ${ticket?.queue_number || ''}`}
      open={open}
      onCancel={onClose}
      footer={footer}
      width={680}
      destroyOnHidden
    >
      {loading ? <Spin /> : error ? <Alert type="error" showIcon message={error} /> : record && (
        <>
          {summary}

          <Form form={form} layout="vertical">
            <Descriptions column={1} size="small" bordered style={{ marginBottom: 16 }}>
              <Descriptions.Item label="Chief Complaint (Registration)">
                {record.chief_complaint || <Text type="secondary">Not recorded</Text>}
              </Descriptions.Item>
            </Descriptions>

            {deptCode === 'REG' && (
              isInService ? (
                <>
                  <Divider orientation="left" plain>Patient Details</Divider>
                  <Form.Item label="Full Name" name="name" rules={[{ required: true, message: 'Full name is required' }]}>
                    <Input />
                  </Form.Item>
                  <Form.Item label="Date of Birth" name="date_of_birth" rules={[{ required: true, message: 'Date of birth is required' }]}>
                    <DatePicker style={{ width: '100%' }} disabledDate={(current) => current && current > dayjs().endOf('day')} format="YYYY-MM-DD" />
                  </Form.Item>
                  <Form.Item label="Gender" name="gender" rules={[{ required: true, message: 'Gender is required' }]}>
                    <Select options={[{ label: 'Male', value: 'Male' }, { label: 'Female', value: 'Female' }, { label: 'Other', value: 'Other' }]} />
                  </Form.Item>
                  <Form.Item label="Contact" name="contact" rules={[{ required: true, message: 'Contact is required' }]}>
                    <Input />
                  </Form.Item>
                  <Form.Item label="Chief Complaint (optional)" name="chief_complaint">
                    <TextArea rows={2} placeholder="Brief reason for the visit, if the patient mentions one" />
                  </Form.Item>
                  <Form.Item label="Patient Category" name="patient_type" rules={[{ required: true }]}>
                    <Select options={[{ label: 'Normal', value: 'Normal' }, { label: 'Emergency', value: 'Emergency' }]} />
                  </Form.Item>
                  <Form.Item label="Required Department" name="department_id" rules={[{ required: true, message: 'Department is required' }]}>
                    <Select options={departments.filter((d) => d.dept_code !== 'REG').map((d) => ({ label: d.dept_name, value: d.id }))} />
                  </Form.Item>
                  <Form.Item noStyle shouldUpdate={(prev, cur) => prev.department_id !== cur.department_id}>
                    {() => (departmentsByCode.CONS && form.getFieldValue('department_id') === departmentsByCode.CONS.id ? (
                      <Form.Item label="Preferred Doctor" name="doctor_id">
                        <Select allowClear placeholder="No preference — any available doctor" options={doctors.map((d) => ({ label: d.name, value: d.id }))} />
                      </Form.Item>
                    ) : null)}
                  </Form.Item>
                  <Form.Item label="Payment Method" name="payment_method" rules={[{ required: true }]}>
                    <Select options={[{ label: 'Cash', value: 'Cash' }, { label: 'Insurance', value: 'Insurance' }]} />
                  </Form.Item>
                  <Form.Item noStyle shouldUpdate={(prev, cur) => prev.payment_method !== cur.payment_method}>
                    {() => form.getFieldValue('payment_method') === 'Insurance' && (
                      <>
                        <Form.Item label="Insurance Provider" name="insurance_provider" rules={[{ required: true, message: 'Insurance provider is required' }]}>
                          <Input />
                        </Form.Item>
                        <Form.Item label="Insurance Card No." name="insurance_ref" rules={[{ required: true, message: 'Insurance card number is required' }]}>
                          <Input />
                        </Form.Item>
                      </>
                    )}
                  </Form.Item>
                </>
              ) : (
                <Descriptions column={1} size="small" bordered>
                  <Descriptions.Item label="Date of Birth">{patient?.date_of_birth ? dayjs(patient.date_of_birth).format('DD MMM YYYY') : '—'}</Descriptions.Item>
                  <Descriptions.Item label="Gender">{patient?.gender || '—'}</Descriptions.Item>
                  <Descriptions.Item label="Contact">{patient?.contact || '—'}</Descriptions.Item>
                </Descriptions>
              )
            )}

            {deptCode === 'CONS' && (
              <>
                <Divider orientation="left" plain>Consultation</Divider>
                {isInService ? (
                  <>
                    <Form.Item label="Symptoms" name="doctor_symptoms_notes">
                      <TextArea rows={2} placeholder="e.g. Fever 38.5C, mild headache" />
                    </Form.Item>
                    <Form.Item label="Preliminary Diagnosis" name="doctor_preliminary_diagnosis">
                      <TextArea rows={2} />
                    </Form.Item>
                  </>
                ) : (
                  <Descriptions column={1} size="small" bordered style={{ marginBottom: 16 }}>
                    <Descriptions.Item label="Symptoms">{record.doctor_symptoms_notes || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                    <Descriptions.Item label="Preliminary Diagnosis">{record.doctor_preliminary_diagnosis || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                  </Descriptions>
                )}

                <Divider orientation="left" plain>Request Laboratory</Divider>
                {isInService && (
                  <Text type="secondary" style={{ display: 'block', marginBottom: 12 }}>
                    Tick at least one test (or write one under Others) before choosing Laboratory as the referral below.
                  </Text>
                )}
                {catalogGroups.map((group) => {
                  const items = isInService ? group.items : group.items.filter((i) => selectedTestIds.includes(i.id));
                  if (!isInService && items.length === 0) return null;
                  return (
                    <div key={group.category} style={{ marginBottom: 12 }}>
                      <div style={CATEGORY_HEADER_STYLE}>{group.category}</div>
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 4, paddingLeft: 4 }}>
                        {items.map((item) => (
                          isInService ? (
                            <Checkbox key={item.id} checked={selectedTestIds.includes(item.id)} onChange={(e) => toggleTest(item.id, e.target.checked)}>
                              {item.name}
                            </Checkbox>
                          ) : (
                            <Checkbox key={item.id} checked disabled>{item.name}</Checkbox>
                          )
                        ))}
                      </div>
                    </div>
                  );
                })}
                {isInService ? (
                  <Form.Item label="Others" style={{ marginTop: 8 }}>
                    <TextArea rows={2} placeholder="Any test not on the list above" value={otherText} onChange={(e) => setOtherText(e.target.value)} />
                  </Form.Item>
                ) : otherText && (
                  <Descriptions column={1} size="small" bordered style={{ marginBottom: 16 }}>
                    <Descriptions.Item label="Others">{otherText}</Descriptions.Item>
                  </Descriptions>
                )}

                {isInService ? (
                  <Form.Item label="Referral" name="referral_target" rules={[{ required: true, message: 'Choose where this patient goes next' }]}>
                    <Select
                      options={[
                        { label: 'Laboratory', value: 'laboratory' },
                        { label: 'Pharmacy', value: 'pharmacy' },
                        { label: 'None — complete, no further service', value: 'none' },
                      ]}
                    />
                  </Form.Item>
                ) : (
                  <Descriptions column={1} size="small" bordered style={{ marginBottom: 16 }}>
                    <Descriptions.Item label="Referral">{record.referral_target || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                  </Descriptions>
                )}

                {record.lab_results_notes && (
                  <>
                    <Divider orientation="left" plain>Laboratory Results</Divider>
                    <Descriptions column={1} size="small" bordered style={{ marginBottom: 16 }}>
                      <Descriptions.Item label="Results">{record.lab_results_notes}</Descriptions.Item>
                    </Descriptions>
                  </>
                )}

                <Divider orientation="left" plain>Final Review &amp; Signature</Divider>
                {isInService ? (
                  <>
                    <Form.Item label="Final Diagnosis" name="final_diagnosis" extra="Required before forwarding to Pharmacy.">
                      <TextArea rows={2} />
                    </Form.Item>
                    <Form.Item label="Treatment Plan" name="treatment_plan">
                      <TextArea rows={2} />
                    </Form.Item>
                    <Form.Item label="Patient Signature — Name" name="patient_signature_name">
                      <Input />
                    </Form.Item>
                    <Form.Item label="Patient Signature — Phone" name="patient_signature_phone">
                      <Input />
                    </Form.Item>
                    {record.signed_at && <Text type="secondary">Signed at {new Date(record.signed_at).toLocaleString()}</Text>}
                  </>
                ) : (
                  <Descriptions column={1} size="small" bordered>
                    <Descriptions.Item label="Final Diagnosis">{record.final_diagnosis || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                    <Descriptions.Item label="Treatment Plan">{record.treatment_plan || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                  </Descriptions>
                )}
              </>
            )}

            {deptCode === 'LAB' && (
              <>
                <Divider orientation="left" plain>Doctor's Request</Divider>
                {selectedGroups.length === 0 && !otherText ? (
                  <Text type="secondary">Not recorded</Text>
                ) : (
                  <>
                    {selectedGroups.map((group) => (
                      <div key={group.category} style={{ marginBottom: 12 }}>
                        <div style={CATEGORY_HEADER_STYLE}>{group.category}</div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 4, paddingLeft: 4 }}>
                          {group.items.map((item) => <Checkbox key={item.id} checked disabled>{item.name}</Checkbox>)}
                        </div>
                      </div>
                    ))}
                    {otherText && (
                      <Descriptions column={1} size="small" bordered style={{ marginBottom: 16 }}>
                        <Descriptions.Item label="Others">{otherText}</Descriptions.Item>
                      </Descriptions>
                    )}
                  </>
                )}

                <Divider orientation="left" plain>Results</Divider>
                {isInService ? (
                  <>
                    <Form.Item label="Lab Results" name="lab_results_notes" rules={[{ required: true, message: 'Results are required before forwarding' }]}>
                      <TextArea rows={4} placeholder="e.g. Malaria (MRDT): positive. FBC: mild anemia." />
                    </Form.Item>
                    <Form.Item label="Forward To">
                      <Radio.Group value={labForwardTarget} onChange={(e) => setLabForwardTarget(e.target.value)}>
                        <Radio.Button value="CONS">Doctor</Radio.Button>
                        <Radio.Button value="PHARM">Pharmacy</Radio.Button>
                      </Radio.Group>
                    </Form.Item>
                  </>
                ) : (
                  <Descriptions column={1} size="small" bordered>
                    <Descriptions.Item label="Results">{record.lab_results_notes || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                  </Descriptions>
                )}
              </>
            )}

            {deptCode === 'PHARM' && (
              <>
                <Divider orientation="left" plain>Diagnosis &amp; Treatment (reference)</Divider>
                <Descriptions column={1} size="small" bordered style={{ marginBottom: 16 }}>
                  <Descriptions.Item label="Final Diagnosis">{record.final_diagnosis || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                  <Descriptions.Item label="Treatment Plan">{record.treatment_plan || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                </Descriptions>

                <Divider orientation="left" plain>Dispensing Notes</Divider>
                {isInService ? (
                  <Form.Item label="Notes (optional)">
                    <TextArea rows={3} placeholder="e.g. Substituted Amoxicillin for Ampiclox — patient allergy noted." value={dispensingNotes} onChange={(e) => setDispensingNotes(e.target.value)} />
                  </Form.Item>
                ) : (
                  <Text>{ticket?.service?.notes || <Text type="secondary">Not recorded</Text>}</Text>
                )}
              </>
            )}
          </Form>
        </>
      )}
    </Modal>
  );
}
