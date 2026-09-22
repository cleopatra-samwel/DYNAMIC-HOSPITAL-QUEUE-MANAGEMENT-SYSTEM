import { useEffect, useState } from 'react';
import { Alert, Button, Card, Checkbox, DatePicker, Descriptions, Divider, Form, Input, InputNumber, Modal, Radio, Select, Spin, Table, Tag, Typography, message } from 'antd';
import dayjs from 'dayjs';
import apiClient from '../services/apiClient';
import { formatStatusLabel } from '../utils/formatLabel';
import { groupByCategory } from '../utils/labTestCategories';
import { ageFromDob } from '../utils/age';

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

const NEXT_ACTION_LABELS = {
  laboratory: 'Sent to Laboratory',
  pharmacy: 'Sent to Pharmacy',
  refer: 'Referred to Another Department',
  followup: 'Follow-up',
  none: 'Completed — no further service',
};

const LAB_RESULT_STATUS_COLORS = { Normal: 'green', Abnormal: 'orange', Critical: 'red' };

const LAB_RESULT_COLUMNS = [
  { title: 'Test', dataIndex: 'name' },
  { title: 'Result', dataIndex: 'result_value', render: (v) => v || '—' },
  { title: 'Reference Range', dataIndex: 'reference_range', render: (v) => v || '—' },
  { title: 'Status', dataIndex: 'status', render: (v) => (v ? <Tag color={LAB_RESULT_STATUS_COLORS[v]}>{v}</Tag> : '—') },
];

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
export default function TicketViewModal({ ticket, open, onClose, onChanged, onTicketCalled, secondaryActionLabel = 'Consult' }) {
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

  // Structured per-test results — labTestRows is the full requested-tests
  // list (id/name/category/result_value/reference_range/status) from the
  // server; testResults is Laboratory Staff's in-progress edits, keyed by
  // row id, merged in on submit.
  const [labTestRows, setLabTestRows] = useState([]);
  const [testResults, setTestResults] = useState({});

  // Laboratory Staff's own choice of where to send the patient next.
  const [labForwardTarget, setLabForwardTarget] = useState('CONS');

  // Doctor's medication prescription checklist — Doctor (editable) and
  // Pharmacy Staff (read-only), mirroring the lab test checklist above.
  const [medicationCatalog, setMedicationCatalog] = useState([]);
  const [selectedMedicationIds, setSelectedMedicationIds] = useState([]);
  const [medicationOtherText, setMedicationOtherText] = useState('');

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
        setLabTestRows(data.tests || []);
        setTestResults(Object.fromEntries((data.tests || []).map((t) => [t.id, {
          result_value: t.result_value, reference_range: t.reference_range, status: t.status,
        }])));
      })
      .catch(() => {});
  }, [open, serviceId, deptCode]);

  const updateTestResult = (rowId, field, value) => {
    setTestResults((prev) => ({ ...prev, [rowId]: { ...prev[rowId], [field]: value } }));
  };

  // Doctor prescribes from here (CONS); Pharmacy Staff reads it back
  // read-only once the ticket reaches them (PHARM) — same
  // origin-service resolution as the lab checklist above, just for
  // medication (see Service::pharmacyRequestOriginService).
  useEffect(() => {
    if (!open || !serviceId || (deptCode !== 'CONS' && deptCode !== 'PHARM')) return;
    apiClient.get('/medication-catalog').then(({ data }) => setMedicationCatalog(data.medications.filter((m) => m.active))).catch(() => {});
    apiClient.get(`/services/${serviceId}/prescribed-medications`)
      .then(({ data }) => {
        setSelectedMedicationIds(data.medication_catalog_ids);
        setMedicationOtherText(data.other || '');
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

  const toggleMedication = (id, checked) => {
    setSelectedMedicationIds((prev) => (checked ? [...prev, id] : prev.filter((m) => m !== id)));
  };

  const selectedMedications = medicationCatalog.filter((m) => selectedMedicationIds.includes(m.id));

  // Whether lab data actually exists for this visit yet — purely
  // informational (drives whether the read-only "Laboratory Results" card
  // has anything to show), never used to decide what's EDITABLE.
  const hasLabResults = labTestRows.length > 0 || !!record?.lab_results_notes;

  // Which button actually opened this form (see DepartmentQueuePage's
  // secondaryActionLabel: "Consult" on Queue/Registration Queue for a
  // fresh intake, "Result" on Laboratory Queue once the patient is back
  // from Laboratory) — this, not whether lab data happens to exist yet,
  // is what decides the CONS form's layout while it's actively being
  // filled in. A doctor may open ANY CONS ticket via "Consult" (e.g. to
  // request more tests during what's technically a second visit), so
  // Vitals/Consultation/Request Laboratory must stay editable there
  // regardless of prior lab history.
  const isResultReview = secondaryActionLabel === 'Result';

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
        'temperature', 'blood_pressure', 'weight', 'pulse_rate',
        'final_diagnosis', 'treatment_plan', 'patient_signature_name', 'patient_signature_phone',
      ]);

      // Conditional sub-fields aren't in the fixed list above since they
      // only exist in the DOM for one Next Action choice at a time —
      // validated separately, only for whichever one is actually selected.
      let extra = {};
      if (values.referral_target === 'refer') {
        extra = await form.validateFields(['refer_department_id', 'referral_reason', 'referral_notes']);
      } else if (values.referral_target === 'followup') {
        extra = await form.validateFields(['follow_up_date', 'follow_up_instructions']);
      }

      setSubmitting(true);
      await apiClient.patch(`/visits/${visitId}/clinical-record`, {
        ...values,
        ...(values.referral_target === 'followup' ? {
          follow_up_date: extra.follow_up_date.format('YYYY-MM-DD'),
          follow_up_instructions: extra.follow_up_instructions,
        } : {}),
      });
      await apiClient.put(`/services/${serviceId}/requested-tests`, {
        lab_test_catalog_ids: selectedTestIds,
        other: otherText || null,
      });
      await apiClient.put(`/services/${serviceId}/prescribed-medications`, {
        medication_catalog_ids: selectedMedicationIds,
        other: medicationOtherText || null,
      });

      if (values.referral_target === 'laboratory') {
        await apiClient.post(`/visits/${visitId}/services`, { department_id: departmentsByCode.LAB.id });
      } else if (values.referral_target === 'pharmacy') {
        await apiClient.post(`/visits/${visitId}/services`, { department_id: departmentsByCode.PHARM.id });
      } else if (values.referral_target === 'refer') {
        await apiClient.post(`/visits/${visitId}/services`, {
          department_id: extra.refer_department_id,
          referral: { reason: extra.referral_reason, notes: extra.referral_notes || undefined },
        });
      } else {
        // 'followup' and 'none' both complete this ticket directly, with
        // no further service — follow-up date/instructions were already
        // saved on the clinical record above.
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

  const doSubmitLab = async () => {
    try {
      const values = await form.validateFields(['lab_results_notes']);
      setSubmitting(true);
      await apiClient.patch(`/visits/${visitId}/clinical-record`, values);

      if (labTestRows.length > 0) {
        await apiClient.put(`/services/${serviceId}/requested-tests/results`, {
          results: labTestRows.map((row) => ({
            id: row.id,
            result_value: testResults[row.id]?.result_value || null,
            reference_range: testResults[row.id]?.reference_range || null,
            status: testResults[row.id]?.status || null,
          })),
        });
      }

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

  // "Verify Results" — a confirmation gate, not a new persisted ticket
  // status (the lifecycle stays WAITING/CALLED/IN_SERVICE/COMPLETED as
  // everywhere else in the app). Prevents accidentally sending results
  // with a requested test left blank without at least a deliberate
  // "send anyway."
  const submitLab = () => {
    const pendingNames = labTestRows
      .filter((row) => !testResults[row.id]?.result_value && !testResults[row.id]?.status)
      .map((row) => row.name);

    if (pendingNames.length > 0) {
      Modal.confirm({
        title: 'Some tests are still pending',
        content: `No result has been entered for: ${pendingNames.join(', ')}. Send to the doctor anyway?`,
        okText: 'Send Anyway',
        okButtonProps: { danger: true },
        cancelText: 'Go Back',
        onOk: doSubmitLab,
      });
      return;
    }

    doSubmitLab();
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
    // REG's and Doctor's forms always end in a forward action here —
    // Laboratory/Pharmacy keep "Submit & Forward" since their own referral
    // options include a genuine "complete, no further service" case.
    footer.push(<Button key="submit" type="primary" loading={submitting} onClick={submitByDept[deptCode]}>{['REG', 'CONS'].includes(deptCode) ? 'Forward' : 'Submit & Forward'}</Button>);
  }

  const summary = (
    <Descriptions column={2} size="small" bordered style={{ marginBottom: 16 }}>
      <Descriptions.Item label="Patient">{patient?.name || '—'}</Descriptions.Item>
      <Descriptions.Item label="Patient No.">{patient?.patient_number || '—'}</Descriptions.Item>
      <Descriptions.Item label="Ticket">{ticket?.queue_number}</Descriptions.Item>
      <Descriptions.Item label="Department">{ticket?.service?.department?.dept_name}</Descriptions.Item>
      {/* Not shown for REG — this is still just the self-check-in default
          ("Normal"), not a real priority yet. The Patient Category field
          below is what staff actually set it from. */}
      {deptCode !== 'REG' && <Descriptions.Item label="Priority">{ticket?.priority_level?.name || '—'}</Descriptions.Item>}
      <Descriptions.Item label="Status">{formatStatusLabel(ticket?.status)}</Descriptions.Item>
      {/* Not shown for REG — payment is always still "Pending" at this
          stage (Registration itself never requires payment; verification
          happens once the patient reaches a department PaymentGate
          actually gates, e.g. Consultation/Laboratory/Pharmacy). */}
      {deptCode !== 'REG' && <Descriptions.Item label="Payment Status">{ticket?.service?.visit?.payment_status || 'Pending'}</Descriptions.Item>}
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
            {/* Not shown for REG — it's the same chief_complaint the
                editable "Chief Complaint (optional)" field below already
                covers, so showing it twice here would be redundant.
                Doctor/Laboratory/Pharmacy still see it as read-only
                reference for why the patient came in. */}
            {deptCode !== 'REG' && (
              <Descriptions column={1} size="small" bordered style={{ marginBottom: 16 }}>
                <Descriptions.Item label="Chief Complaint (Registration)">
                  {record.chief_complaint || <Text type="secondary">Not recorded</Text>}
                </Descriptions.Item>
              </Descriptions>
            )}

            {deptCode === 'REG' && (
              isInService ? (
                <>
                  <Card title="Patient Details" size="small" style={{ marginBottom: 16 }}>
                    <Form.Item label="Full Name" name="name" rules={[{ required: true, message: 'Full name is required' }]} style={{ marginBottom: 10 }}>
                      <Input />
                    </Form.Item>
                    <Form.Item label="Date of Birth" name="date_of_birth" rules={[{ required: true, message: 'Date of birth is required' }]} style={{ marginBottom: 10 }}>
                      <DatePicker style={{ width: '100%' }} disabledDate={(current) => current && current > dayjs().endOf('day')} format="YYYY-MM-DD" />
                    </Form.Item>
                    <Form.Item label="Gender" name="gender" rules={[{ required: true, message: 'Gender is required' }]} style={{ marginBottom: 10 }}>
                      <Select options={[{ label: 'Male', value: 'Male' }, { label: 'Female', value: 'Female' }, { label: 'Other', value: 'Other' }]} />
                    </Form.Item>
                    <Form.Item label="Contact" name="contact" rules={[{ required: true, message: 'Contact is required' }]} style={{ marginBottom: 10 }}>
                      <Input />
                    </Form.Item>
                    <Form.Item label="Chief Complaint (optional)" name="chief_complaint" style={{ marginBottom: 0 }}>
                      <TextArea rows={2} placeholder="Brief reason for the visit, if the patient mentions one" />
                    </Form.Item>
                  </Card>

                  <Card title="Patient Category" size="small" style={{ marginBottom: 16 }}>
                    <Form.Item label="Patient Category" name="patient_type" rules={[{ required: true }]} style={{ marginBottom: 10 }}>
                      <Select options={[{ label: 'Normal', value: 'Normal' }, { label: 'Emergency', value: 'Emergency' }]} />
                    </Form.Item>
                    <Form.Item label="Required Department" name="department_id" rules={[{ required: true, message: 'Department is required' }]} style={{ marginBottom: 0 }}>
                      <Select options={departments.filter((d) => d.dept_code !== 'REG').map((d) => ({ label: d.dept_name, value: d.id }))} />
                    </Form.Item>
                    <Form.Item noStyle shouldUpdate={(prev, cur) => prev.department_id !== cur.department_id}>
                      {() => (departmentsByCode.CONS && form.getFieldValue('department_id') === departmentsByCode.CONS.id ? (
                        <Form.Item label="Preferred Doctor" name="doctor_id" style={{ marginBottom: 0, marginTop: 10 }}>
                          <Select allowClear placeholder="No preference — any available doctor" options={doctors.map((d) => ({ label: d.name, value: d.id }))} />
                        </Form.Item>
                      ) : null)}
                    </Form.Item>
                  </Card>

                  <Card title="Payment Method" size="small">
                    <Form.Item label="Payment Method" name="payment_method" rules={[{ required: true }]} style={{ marginBottom: 0 }}>
                      <Select options={[{ label: 'Cash', value: 'Cash' }, { label: 'Insurance', value: 'Insurance' }]} />
                    </Form.Item>
                    <Form.Item noStyle shouldUpdate={(prev, cur) => prev.payment_method !== cur.payment_method}>
                      {() => form.getFieldValue('payment_method') === 'Insurance' && (
                        <>
                          <Form.Item label="Insurance Provider" name="insurance_provider" rules={[{ required: true, message: 'Insurance provider is required' }]} style={{ marginBottom: 10, marginTop: 10 }}>
                            <Input />
                          </Form.Item>
                          <Form.Item label="Insurance Card No." name="insurance_ref" rules={[{ required: true, message: 'Insurance card number is required' }]} style={{ marginBottom: 0 }}>
                            <Input />
                          </Form.Item>
                        </>
                      )}
                    </Form.Item>
                  </Card>
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
                <Card title="Vital Signs" size="small" style={{ marginBottom: 16 }}>
                  {isInService && !isResultReview ? (
                    <>
                      <Form.Item label="Temperature (°C)" name="temperature" style={{ marginBottom: 10 }}>
                        <InputNumber style={{ width: '100%' }} step={0.1} min={30} max={45} />
                      </Form.Item>
                      <Form.Item label="Blood Pressure" name="blood_pressure" style={{ marginBottom: 10 }}>
                        <Input placeholder="e.g. 120/80" />
                      </Form.Item>
                      <Form.Item label="Weight (kg)" name="weight" style={{ marginBottom: 10 }}>
                        <InputNumber style={{ width: '100%' }} step={0.1} min={0} max={500} />
                      </Form.Item>
                      <Form.Item label="Pulse Rate (bpm)" name="pulse_rate" style={{ marginBottom: 0 }}>
                        <InputNumber style={{ width: '100%' }} min={0} max={300} />
                      </Form.Item>
                    </>
                  ) : (record.temperature || record.blood_pressure || record.weight || record.pulse_rate) ? (
                    <Descriptions column={2} size="small" bordered>
                      <Descriptions.Item label="Temperature">{record.temperature ? `${record.temperature} °C` : '—'}</Descriptions.Item>
                      <Descriptions.Item label="Blood Pressure">{record.blood_pressure || '—'}</Descriptions.Item>
                      <Descriptions.Item label="Weight">{record.weight ? `${record.weight} kg` : '—'}</Descriptions.Item>
                      <Descriptions.Item label="Pulse Rate">{record.pulse_rate ? `${record.pulse_rate} bpm` : '—'}</Descriptions.Item>
                    </Descriptions>
                  ) : (
                    <Text type="secondary">Not recorded</Text>
                  )}
                </Card>

                <Card title="Consultation" size="small" style={{ marginBottom: 16 }}>
                  {isInService && !isResultReview ? (
                    <>
                      <Form.Item label="Symptoms" name="doctor_symptoms_notes">
                        <TextArea rows={2} placeholder="e.g. Fever 38.5C, mild headache" />
                      </Form.Item>
                      <Form.Item label="Preliminary Diagnosis" name="doctor_preliminary_diagnosis" style={{ marginBottom: 0 }}>
                        <TextArea rows={2} />
                      </Form.Item>
                    </>
                  ) : (
                    <Descriptions column={1} size="small" bordered>
                      <Descriptions.Item label="Symptoms">{record.doctor_symptoms_notes || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                      <Descriptions.Item label="Preliminary Diagnosis">{record.doctor_preliminary_diagnosis || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                    </Descriptions>
                  )}
                </Card>

                {/* While actively filling the form in, shown/hidden by
                    which button opened it ("Consult" vs "Result" — see
                    isResultReview above), not by whether lab data happens
                    to already exist. For a completed ticket's read-only
                    history, falls back to whether there's actually
                    something to show instead — Laboratory Results below
                    already covers a completed round-trip, so an empty
                    checklist card isn't shown alongside it. */}
                {(isInService ? !isResultReview : !hasLabResults) && (
                  <Card title="Request Laboratory" size="small" style={{ marginBottom: 16 }}>
                    {isInService && (
                      <Text type="secondary" style={{ display: 'block', marginBottom: 12 }}>
                        Tick at least one test (or write one under Others) before choosing "Send to Laboratory" below.
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
                      <Form.Item label="Others" style={{ marginTop: 8, marginBottom: 0 }}>
                        <TextArea rows={2} placeholder="Any test not on the list above" value={otherText} onChange={(e) => setOtherText(e.target.value)} />
                      </Form.Item>
                    ) : otherText ? (
                      <Descriptions column={1} size="small" bordered style={{ marginTop: 8 }}>
                        <Descriptions.Item label="Others">{otherText}</Descriptions.Item>
                      </Descriptions>
                    ) : null}
                  </Card>
                )}

                {/* Only on "Result" review while actively filling the form
                    in — "Consult" is the fresh-intake flow and has no lab
                    data to show yet regardless of history. Historical
                    (completed-ticket) view stays data-driven. */}
                {(isInService ? isResultReview : hasLabResults) && (
                  <Card title="Laboratory Results" size="small" style={{ marginBottom: 16 }}>
                    {labTestRows.length > 0 && (
                      <Table
                        size="small"
                        pagination={false}
                        rowKey="id"
                        columns={LAB_RESULT_COLUMNS}
                        dataSource={labTestRows}
                        style={{ marginBottom: record.lab_results_notes ? 16 : 0 }}
                      />
                    )}
                    {record.lab_results_notes && (
                      <Descriptions column={1} size="small" bordered>
                        <Descriptions.Item label="Overall Summary">{record.lab_results_notes}</Descriptions.Item>
                      </Descriptions>
                    )}
                  </Card>
                )}

                {/* Same rule as Laboratory Results above — only on "Result"
                    review while active; historical view stays as-is. */}
                {(isInService ? isResultReview : true) && (
                <Card title="Prescribe Medication" size="small" style={{ marginBottom: 16 }}>
                  {isInService ? (
                    <>
                      <Text type="secondary" style={{ display: 'block', marginBottom: 12 }}>
                        Tick what the patient should be dispensed — this list is sent to Billing to charge and to Pharmacy to dispense once forwarded there.
                      </Text>
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 4, marginBottom: 8 }}>
                        {medicationCatalog.map((med) => (
                          <Checkbox key={med.id} checked={selectedMedicationIds.includes(med.id)} onChange={(e) => toggleMedication(med.id, e.target.checked)}>
                            {med.name}
                          </Checkbox>
                        ))}
                      </div>
                      <Form.Item label="Others" style={{ marginTop: 8, marginBottom: 0 }}>
                        <TextArea rows={2} placeholder="Any medication not on the list above" value={medicationOtherText} onChange={(e) => setMedicationOtherText(e.target.value)} />
                      </Form.Item>
                    </>
                  ) : (selectedMedications.length > 0 || medicationOtherText) ? (
                    <Descriptions column={1} size="small" bordered>
                      <Descriptions.Item label="Prescribed">
                        {[...selectedMedications.map((m) => m.name), medicationOtherText ? `Others: ${medicationOtherText}` : null].filter(Boolean).join(', ')}
                      </Descriptions.Item>
                    </Descriptions>
                  ) : (
                    <Text type="secondary">Not recorded</Text>
                  )}
                </Card>
                )}

                <Card title="Next Action" size="small" style={{ marginBottom: 16 }}>
                  {isInService ? (
                    <>
                      <Form.Item label="Next Action" name="referral_target" rules={[{ required: true, message: 'Choose the next action for this patient' }]}>
                        <Select
                          options={[
                            { label: 'Send to Laboratory', value: 'laboratory' },
                            { label: 'Send to Pharmacy', value: 'pharmacy' },
                            { label: 'Refer to Another Department', value: 'refer' },
                            { label: 'Follow-up', value: 'followup' },
                            { label: 'Complete Consultation', value: 'none' },
                          ]}
                        />
                      </Form.Item>
                      <Form.Item noStyle shouldUpdate={(prev, cur) => prev.referral_target !== cur.referral_target}>
                        {() => {
                          const action = form.getFieldValue('referral_target');
                          if (action === 'refer') {
                            return (
                              <>
                                <Form.Item label="Refer To Department" name="refer_department_id" rules={[{ required: true, message: 'Choose a department' }]}>
                                  <Select options={departments.filter((d) => d.dept_code !== 'REG' && d.dept_code !== 'CONS').map((d) => ({ label: d.dept_name, value: d.id }))} />
                                </Form.Item>
                                <Form.Item label="Reason for Referral" name="referral_reason" rules={[{ required: true, message: 'Reason is required' }]}>
                                  <TextArea rows={2} />
                                </Form.Item>
                                <Form.Item label="Additional Notes (optional)" name="referral_notes" style={{ marginBottom: 0 }}>
                                  <TextArea rows={2} />
                                </Form.Item>
                              </>
                            );
                          }
                          if (action === 'followup') {
                            return (
                              <>
                                <Form.Item label="Follow-up Date" name="follow_up_date" rules={[{ required: true, message: 'Follow-up date is required' }]}>
                                  <DatePicker style={{ width: '100%' }} disabledDate={(current) => current && current < dayjs().startOf('day')} format="YYYY-MM-DD" />
                                </Form.Item>
                                <Form.Item label="Follow-up Instructions" name="follow_up_instructions" rules={[{ required: true, message: 'Instructions are required' }]} style={{ marginBottom: 0 }}>
                                  <TextArea rows={2} />
                                </Form.Item>
                              </>
                            );
                          }
                          return null;
                        }}
                      </Form.Item>
                    </>
                  ) : (
                    <Descriptions column={1} size="small" bordered>
                      <Descriptions.Item label="Next Action">{record.referral_target ? (NEXT_ACTION_LABELS[record.referral_target] || record.referral_target) : <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                      {record.follow_up_date && (
                        <Descriptions.Item label="Follow-up">{dayjs(record.follow_up_date).format('DD MMM YYYY')} — {record.follow_up_instructions}</Descriptions.Item>
                      )}
                    </Descriptions>
                  )}
                </Card>

                <Card title="Final Review &amp; Signature" size="small">
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
                      <Form.Item label="Patient Signature — Phone" name="patient_signature_phone" style={{ marginBottom: record.signed_at ? 10 : 0 }}>
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
                </Card>
              </>
            )}

            {deptCode === 'LAB' && (
              <>
                <Descriptions column={2} size="small" bordered style={{ marginBottom: 16 }}>
                  <Descriptions.Item label="Age">{ageFromDob(patient?.date_of_birth) ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="Gender">{patient?.gender || '—'}</Descriptions.Item>
                  <Descriptions.Item label="Phone Number">{patient?.contact || '—'}</Descriptions.Item>
                  <Descriptions.Item label="Date/Time">{ticket?.created_at ? dayjs(ticket.created_at).format('DD MMM YYYY, HH:mm') : '—'}</Descriptions.Item>
                  {/* Both computed server-side (QueueTicketController::attachLabExtras)
                      — Service.doctor_id is only ever an optional
                      pre-selection, never who actually treated the patient. */}
                  <Descriptions.Item label="Referring Doctor">{ticket?.referring_doctor || '—'}</Descriptions.Item>
                  <Descriptions.Item label="Referring Department">{ticket?.service?.previousService?.department?.dept_name || '—'}</Descriptions.Item>
                </Descriptions>

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

                {/* Why this patient was sent — Doctor's own clinical
                    reasoning, not shown to Lab anywhere else. */}
                {(record.doctor_symptoms_notes || record.doctor_preliminary_diagnosis) && (
                  <Descriptions column={1} size="small" bordered style={{ marginBottom: 16 }}>
                    <Descriptions.Item label="Reason for Laboratory Investigation">{record.doctor_symptoms_notes || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                    <Descriptions.Item label="Doctor's Notes">{record.doctor_preliminary_diagnosis || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                  </Descriptions>
                )}

                <Divider orientation="left" plain>Results</Divider>
                {isInService ? (
                  <>
                    {labTestRows.length > 0 ? (
                      <div style={{ marginBottom: 16 }}>
                        {labTestRows.map((row) => (
                          <div key={row.id} style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 8, flexWrap: 'wrap' }}>
                            <div style={{ minWidth: 160 }}>{row.name}</div>
                            <Input
                              style={{ width: 160 }}
                              placeholder="Result"
                              value={testResults[row.id]?.result_value || ''}
                              onChange={(e) => updateTestResult(row.id, 'result_value', e.target.value)}
                            />
                            <Input
                              style={{ width: 140 }}
                              placeholder="Reference range"
                              value={testResults[row.id]?.reference_range || ''}
                              onChange={(e) => updateTestResult(row.id, 'reference_range', e.target.value)}
                            />
                            <Select
                              style={{ width: 130 }}
                              placeholder="Status"
                              allowClear
                              value={testResults[row.id]?.status || undefined}
                              onChange={(value) => updateTestResult(row.id, 'status', value)}
                              options={[{ label: 'Normal', value: 'Normal' }, { label: 'Abnormal', value: 'Abnormal' }, { label: 'Critical', value: 'Critical' }]}
                            />
                          </div>
                        ))}
                      </div>
                    ) : (
                      <Text type="secondary" style={{ display: 'block', marginBottom: 12 }}>
                        No specific tests were requested — record an overall summary below.
                      </Text>
                    )}
                    <Form.Item label="Overall Summary (optional)" name="lab_results_notes">
                      <TextArea rows={3} placeholder="Any narrative context not captured per-test above" />
                    </Form.Item>
                    <Form.Item label="Forward To">
                      <Radio.Group value={labForwardTarget} onChange={(e) => setLabForwardTarget(e.target.value)}>
                        <Radio.Button value="CONS">Doctor</Radio.Button>
                        <Radio.Button value="PHARM">Pharmacy</Radio.Button>
                      </Radio.Group>
                    </Form.Item>
                  </>
                ) : (
                  <>
                    {labTestRows.length > 0 && (
                      <Table
                        size="small"
                        pagination={false}
                        rowKey="id"
                        columns={LAB_RESULT_COLUMNS}
                        dataSource={labTestRows}
                        style={{ marginBottom: 16 }}
                      />
                    )}
                    <Descriptions column={1} size="small" bordered>
                      <Descriptions.Item label="Overall Summary">{record.lab_results_notes || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                    </Descriptions>
                  </>
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

                <Divider orientation="left" plain>Doctor's Prescription</Divider>
                {selectedMedications.length === 0 && !medicationOtherText ? (
                  <Text type="secondary" style={{ display: 'block', marginBottom: 16 }}>Not recorded</Text>
                ) : (
                  <div style={{ marginBottom: 16 }}>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                      {selectedMedications.map((med) => <Checkbox key={med.id} checked disabled>{med.name}</Checkbox>)}
                    </div>
                    {medicationOtherText && (
                      <Descriptions column={1} size="small" bordered style={{ marginTop: 8 }}>
                        <Descriptions.Item label="Others">{medicationOtherText}</Descriptions.Item>
                      </Descriptions>
                    )}
                  </div>
                )}

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
