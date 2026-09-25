import { useEffect, useState } from 'react';
import { useSelector } from 'react-redux';
import { Alert, Button, Card, Checkbox, DatePicker, Descriptions, Divider, Form, Input, InputNumber, Modal, Radio, Select, Spin, Table, Tabs, Tag, Typography, message } from 'antd';
import { ArrowLeftOutlined, CheckCircleOutlined, CheckOutlined, DeleteOutlined, EditOutlined, ExperimentOutlined, HeartOutlined, InfoCircleOutlined, MedicineBoxOutlined, ReloadOutlined, SaveOutlined, SendOutlined, TeamOutlined, UserOutlined } from '@ant-design/icons';
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

/** Big selectable tiles for "Forward Patient To" — a controlled Form.Item input (value = chosen key). */
function ForwardTiles({ value, onChange, options }) {
  return (
    <div className="forward-tiles">
      {options.map((option) => (
        <button type="button" key={option.key} className={`forward-tile${value === option.key ? ' is-selected' : ''}`} onClick={() => onChange?.(option.key)}>
          {option.icon}
          <strong>{option.title}</strong>
          <span>{option.subtitle}</span>
        </button>
      ))}
    </div>
  );
}

const RESULT_FORWARD_OPTIONS = [
  { key: 'pharmacy', title: 'Pharmacy', subtitle: 'For prescribed medicines', icon: <MedicineBoxOutlined /> },
  { key: 'refer', title: 'Another Department', subtitle: 'Select department', icon: <TeamOutlined /> },
  { key: 'followup', title: 'Follow-up', subtitle: 'Review on a set date', icon: <ReloadOutlined /> },
  { key: 'none', title: 'Complete Consultation', subtitle: 'No further action', icon: <CheckCircleOutlined /> },
];

const FORWARD_BUTTON_LABELS = {
  pharmacy: 'Forward to Pharmacy',
  refer: 'Refer Patient',
  followup: 'Schedule Follow-up',
  none: 'Complete Consultation',
};

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
  const currentUserName = useSelector((state) => state.auth.user?.name);
  const liveFinalDiagnosis = Form.useWatch('final_diagnosis', form);
  const livePreliminaryDiagnosis = Form.useWatch('doctor_preliminary_diagnosis', form);
  const forwardChoice = Form.useWatch('referral_target', form);

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

  // Perform Tests layout: which test is open in the "Enter Test Results" panel, and which tab is showing.
  const [activeTestId, setActiveTestId] = useState(null);
  const [labTab, setLabTab] = useState('ordered');
  const [savingResult, setSavingResult] = useState(false);

  // Doctor's medication prescription checklist — Doctor (editable) and
  // Pharmacy Staff (read-only), mirroring the lab test checklist above.
  const [medicationCatalog, setMedicationCatalog] = useState([]);
  const [selectedMedicationIds, setSelectedMedicationIds] = useState([]);
  const [medicationOtherText, setMedicationOtherText] = useState('');
  // Per-medicine dosage / frequency / duration / quantity, keyed by medication_catalog_id.
  const [medicationDetails, setMedicationDetails] = useState({});

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

  const isTestDone = (row) => !!(row.result_value || row.status);

  // "Save Result" — writes just this one test's result now (same endpoint
  // and validation the final Forward uses), so nothing is lost if the
  // modal is closed before forwarding.
  const saveTestResult = async () => {
    const row = labTestRows.find((r) => r.id === activeTestId);
    if (!row) return;
    const draft = testResults[row.id] || {};
    if (!draft.result_value && !draft.status) {
      message.warning('Enter a result value or a status first.');
      return;
    }
    setSavingResult(true);
    try {
      const { data } = await apiClient.put(`/services/${serviceId}/requested-tests/results`, {
        results: [{ id: row.id, result_value: draft.result_value || null, reference_range: draft.reference_range || null, status: draft.status || null }],
      });
      setLabTestRows(data.tests);
      setActiveTestId(null);
      message.success(`${row.name} result saved.`);
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not save this result.');
    } finally {
      setSavingResult(false);
    }
  };

  const resetTestResult = () => {
    const row = labTestRows.find((r) => r.id === activeTestId);
    if (!row) return;
    setTestResults((prev) => ({ ...prev, [row.id]: { result_value: row.result_value, reference_range: row.reference_range, status: row.status } }));
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
        setMedicationDetails(Object.fromEntries((data.medications || []).map((m) => [m.medication_catalog_id, {
          dosage: m.dosage, frequency: m.frequency, duration: m.duration, quantity: m.quantity,
        }])));
      })
      .catch(() => {});
  }, [open, serviceId, deptCode]);

  useEffect(() => {
    if (!open) return;
    setDispensingNotes(ticket?.service?.notes || '');
    setLabForwardTarget('CONS');
    setActiveTestId(null);
    setLabTab('ordered');
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
    if (checked) setMedicationDetails((prev) => ({ ...prev, [id]: prev[id] || { quantity: 1 } }));
  };

  const updateMedicationDetail = (id, field, value) => {
    setMedicationDetails((prev) => ({ ...prev, [id]: { ...prev[id], [field]: value } }));
  };

  // What goes to PUT /prescribed-medications — one entry per selected medicine, with its details.
  const medicationPayload = () => selectedMedicationIds.map((id) => ({
    id,
    dosage: medicationDetails[id]?.dosage || null,
    frequency: medicationDetails[id]?.frequency || null,
    duration: medicationDetails[id]?.duration || null,
    quantity: medicationDetails[id]?.quantity || 1,
  }));

  const selectedMedications = medicationCatalog.filter((m) => selectedMedicationIds.includes(m.id));

  // Whether lab data actually exists for this visit yet — purely
  // informational (drives whether the read-only "Laboratory Results" card
  // has anything to show), never used to decide what's EDITABLE.
  const hasLabResults = labTestRows.length > 0 || !!record?.lab_results_notes;
  const pendingLabTests = labTestRows.filter((row) => !isTestDone(row));
  const doneLabTests = labTestRows.filter(isTestDone);

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
  // The redesigned "Laboratory Results - Return to Doctor" form: only while the doctor is actively reviewing returned results.
  const isResultForm = deptCode === 'CONS' && isInService && isResultReview;

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
        medications: medicationPayload(),
        other: medicationOtherText || null,
        // Only the Result form has prescription notes; leaving this out keeps the saved notes untouched.
        notes: form.getFieldValue('prescription_notes'),
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
      if (err?.errorFields) {
        message.warning('Please complete the required fields (including where the patient is going).');
        return;
      }
      message.error(err.response?.data?.message || 'Could not save and forward.');
    } finally {
      setSubmitting(false);
    }
  };

  // Result form: keep what the doctor has typed so far without forwarding the patient.
  const saveDraft = async () => {
    setSubmitting(true);
    try {
      const values = form.getFieldsValue(['final_diagnosis', 'treatment_plan', 'patient_signature_name', 'patient_signature_phone']);
      await apiClient.patch(`/visits/${visitId}/clinical-record`, Object.fromEntries(Object.entries(values).filter(([, value]) => value !== undefined)));
      await apiClient.put(`/services/${serviceId}/prescribed-medications`, {
        medications: medicationPayload(),
        other: medicationOtherText || null,
        notes: form.getFieldValue('prescription_notes'),
      });
      message.success('Draft saved.');
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not save the draft.');
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
    ['CONS', 'LAB'].includes(deptCode)
      ? <Button key="close" icon={<ArrowLeftOutlined />} onClick={onClose}>Back to Queue</Button>
      : <Button key="close" onClick={onClose}>Close</Button>,
  ];
  if (isWaiting) {
    footer.push(<Button key="call" type="primary" loading={quickActing} onClick={() => runQuickAction('call')}>Call</Button>);
  } else if (isCalled) {
    footer.push(<Button key="start" type="primary" loading={quickActing} onClick={() => runQuickAction('start-service')}>Start Service</Button>);
  } else if (isInService) {
    // REG's and Doctor's forms always end in a forward action here —
    // Laboratory/Pharmacy keep "Submit & Forward" since their own referral
    // options include a genuine "complete, no further service" case.
    footer.push(<Button key="submit" type="primary" icon={deptCode === 'CONS' ? <CheckOutlined /> : undefined} loading={submitting} onClick={submitByDept[deptCode]}>{['REG', 'CONS'].includes(deptCode) ? 'Forward' : 'Submit & Forward'}</Button>);
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

  const chiefComplaintBlock = deptCode !== 'REG' && (
    <Descriptions column={1} size="small" bordered style={{ marginBottom: 16 }}>
      <Descriptions.Item label="Chief Complaint (Registration)">
        {record?.chief_complaint || <Text type="secondary">Not recorded</Text>}
      </Descriptions.Item>
    </Descriptions>
  );

  // Consultation header card (same look as Cashier Billing): who the patient
  // is, the working diagnosis, and who is consulting when.
  const consultInfo = (
    <div className="billing-info">
      <div className="billing-info__patient">
        <span className="billing-avatar"><UserOutlined /></span>
        <div>
          <div className="billing-label">Patient Name</div>
          <div className="billing-strong">{patient?.name || '—'}</div>
          <div className="billing-small">Patient ID: {patient?.patient_number || '—'}</div>
          <div className="billing-small">Phone: {patient?.contact || '—'}</div>
        </div>
      </div>
      <div className="billing-info__diagnosis">
        <HeartOutlined className="billing-info__icon" />
        <div>
          <div className="billing-label">Diagnosis</div>
          <div className="billing-strong">{liveFinalDiagnosis || livePreliminaryDiagnosis || record?.final_diagnosis || record?.doctor_preliminary_diagnosis || 'Not recorded yet'}</div>
          <div className="billing-small">Doctor: {currentUserName || '—'}</div>
          <div className="billing-small">Date: {dayjs(ticket?.called_at || undefined).format('DD MMM YYYY  HH:mm')}</div>
        </div>
      </div>
    </div>
  );

  return (
    <Modal
      title={isResultForm ? (
        <div className="billing-title">
          <span className="billing-title__icon"><ExperimentOutlined /></span>
          <div>
            <div className="billing-title__main">Laboratory Results – Return to Doctor</div>
            <div className="billing-title__sub">View patient laboratory results, add diagnosis, prescription and forward to next department.</div>
          </div>
          <Tag color="green" style={{ marginLeft: 'auto', marginRight: 24 }}>Results Received · {dayjs(ticket?.created_at).format('DD MMM YYYY HH:mm')}</Tag>
        </div>
      ) : deptCode === 'LAB' ? (
        <div className="billing-title">
          <span className="billing-title__icon"><ExperimentOutlined /></span>
          <div>
            <div className="billing-title__main">Laboratory – Perform Tests</div>
            <div className="billing-title__sub">View patient details, perform ordered tests and enter results.</div>
          </div>
        </div>
      ) : deptCode === 'CONS' ? (
        <div className="billing-title">
          <span className="billing-title__icon"><MedicineBoxOutlined /></span>
          <div>
            <div className="billing-title__main">Consultation — {ticket?.queue_number}</div>
            <div className="billing-title__sub">Review the patient, record the consultation, then choose where the patient goes next.</div>
          </div>
        </div>
      ) : `${deptCode === 'REG' ? 'Register Patient' : 'View'} — ${ticket?.queue_number || ''}`}
      open={open}
      onCancel={onClose}
      footer={isResultForm ? null : footer}
      width={isResultForm ? 1120 : deptCode === 'CONS' ? 960 : deptCode === 'LAB' ? 1000 : 680}
      destroyOnHidden
    >
      {loading ? <Spin /> : error ? <Alert type="error" showIcon message={error} /> : record && (
        <>
          {deptCode !== 'CONS' && deptCode !== 'LAB' && summary}

          <Form form={form} layout="vertical">
            {/* Not shown for REG — it's the same chief_complaint the
                editable "Chief Complaint (optional)" field below already
                covers, so showing it twice here would be redundant.
                Doctor/Laboratory/Pharmacy still see it as read-only
                reference for why the patient came in. */}
            {deptCode !== 'CONS' && deptCode !== 'LAB' && chiefComplaintBlock}

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

            {deptCode === 'CONS' && !isResultForm && (
              <div className="billing-grid">
                <div>
                  {consultInfo}
                  {chiefComplaintBlock}
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
                </div>
                <div className="billing-summary">
                  <h4 className="billing-section"><SendOutlined /> Patient Destination</h4>
                  <div className="billing-row"><span>Ticket</span><strong>{ticket?.queue_number}</strong></div>
                  <div className="billing-row"><span>Priority</span><strong>{ticket?.priority_level?.name || '—'}</strong></div>
                  <div className="billing-row"><span>Status</span><strong>{formatStatusLabel(ticket?.status)}</strong></div>
                  <div className="billing-row"><span>Payment Status</span><strong>{ticket?.service?.visit?.payment_status || 'Pending'}</strong></div>
                  <div className="billing-row"><span>Lab Tests Requested</span><strong>{selectedTestIds.length + (otherText ? 1 : 0)}</strong></div>
                  <div className="billing-row"><span>Medicines Prescribed</span><strong>{selectedMedicationIds.length + (medicationOtherText ? 1 : 0)}</strong></div>
                  <hr className="billing-divider" />
                <div className="consult-destination">
                  {isInService ? (
                    <>
                      <Form.Item label="Where Is The Patient Going?" name="referral_target" rules={[{ required: true, message: 'Choose the next action for this patient' }]}>
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
                </div>

                </div>
              </div>
            )}

            {isResultForm && (
              <div className="billing-grid" style={{ gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 1fr)' }}>
                <div>
                  <div className="billing-info">
                    <div className="billing-info__patient">
                      <span className="billing-avatar"><UserOutlined /></span>
                      <div>
                        <div className="billing-label">Patient Name</div>
                        <div className="billing-strong">{patient?.name || '—'}</div>
                        <div className="billing-small">Patient ID: {patient?.patient_number || '—'}</div>
                        <div className="billing-small">Age: {ageFromDob(patient?.date_of_birth) ?? '—'} &nbsp;|&nbsp; Gender: {patient?.gender || '—'}</div>
                      </div>
                    </div>
                    <div className="billing-info__diagnosis">
                      <div>
                        <div className="billing-small"><strong>Queue No:</strong> {ticket?.queue_number}</div>
                        <div className="billing-small"><strong>Department:</strong> {ticket?.service?.department?.dept_name || '—'}</div>
                        <div className="billing-small"><strong>Referred From:</strong> {ticket?.service?.previousService?.department?.dept_name || '—'}</div>
                        <div className="billing-small"><strong>Visit Date:</strong> {dayjs(ticket?.service?.visit?.visit_date || ticket?.created_at).format('DD MMM YYYY')}</div>
                      </div>
                    </div>
                  </div>

                  <h4 className="billing-section"><ExperimentOutlined /> Laboratory Results</h4>
                  <Text type="secondary" style={{ display: 'block', marginBottom: 8 }}>Tests ordered by the doctor and results from the laboratory.</Text>
                  <Table
                    rowKey="id"
                    size="small"
                    pagination={false}
                    dataSource={labTestRows}
                    locale={{ emptyText: 'No specific tests were requested.' }}
                    columns={[
                      { title: '#', width: 48, render: (_, __, index) => index + 1 },
                      { title: 'Test Name', render: (_, row) => <div><strong>{row.name}</strong><div className="billing-small">{row.category}</div></div> },
                      { title: 'Result', dataIndex: 'result_value', render: (v) => v || '—' },
                      { title: 'Reference Range', dataIndex: 'reference_range', render: (v) => v || '—' },
                      { title: 'Status', dataIndex: 'status', render: (v) => (v ? <Tag color={LAB_RESULT_STATUS_COLORS[v]}>{v}</Tag> : '—') },
                    ]}
                  />
                  {labTestRows.length > 0 && (
                    labTestRows.every((row) => row.status === 'Normal')
                      ? <Alert style={{ marginTop: 10 }} type="success" showIcon message="All results are within normal range. Continue with clinical correlation." />
                      : labTestRows.some((row) => row.status === 'Abnormal' || row.status === 'Critical')
                        ? <Alert style={{ marginTop: 10 }} type="warning" showIcon message="Some results are abnormal or critical. Review before prescribing." />
                        : null
                  )}
                  {record.lab_results_notes && (
                    <Descriptions column={1} size="small" bordered style={{ marginTop: 10 }}>
                      <Descriptions.Item label="Laboratory Remarks">{record.lab_results_notes}</Descriptions.Item>
                    </Descriptions>
                  )}
                </div>

                <div>
                  <Card size="small" title="Diagnosis (From Consultation)" style={{ marginBottom: 16 }}>
                    <div className="billing-note" style={{ marginTop: 0, marginBottom: 12 }}>
                      <div>
                        <strong>{record.doctor_preliminary_diagnosis || 'No preliminary diagnosis recorded'}</strong>
                        {record.doctor_symptoms_notes && <div className="billing-small">{record.doctor_symptoms_notes}</div>}
                      </div>
                    </div>
                    <Form.Item label="Final Diagnosis" name="final_diagnosis" extra="Required before forwarding to Pharmacy." style={{ marginBottom: 0 }}>
                      <TextArea rows={2} />
                    </Form.Item>
                  </Card>

                  <Card
                    size="small"
                    title="Add / Update Prescription"
                    style={{ marginBottom: 16 }}
                    extra={(
                      <Select
                        showSearch
                        value={null}
                        placeholder="+ Add Medicine"
                        style={{ width: 180 }}
                        optionFilterProp="label"
                        options={medicationCatalog.filter((med) => !selectedMedicationIds.includes(med.id)).map((med) => ({ label: med.name, value: med.id }))}
                        onChange={(id) => id && toggleMedication(id, true)}
                      />
                    )}
                  >
                    <Table
                      rowKey="id"
                      size="small"
                      pagination={false}
                      dataSource={selectedMedications}
                      locale={{ emptyText: 'No medicine added yet.' }}
                      columns={[
                        { title: '#', width: 40, render: (_, __, index) => index + 1 },
                        { title: 'Medicine Name', dataIndex: 'name' },
                        { title: 'Dosage', width: 96, render: (_, med) => <Input size="small" placeholder="e.g. 500 mg" value={medicationDetails[med.id]?.dosage || ''} onChange={(e) => updateMedicationDetail(med.id, 'dosage', e.target.value)} /> },
                        { title: 'Frequency', width: 96, render: (_, med) => <Input size="small" placeholder="e.g. 2x daily" value={medicationDetails[med.id]?.frequency || ''} onChange={(e) => updateMedicationDetail(med.id, 'frequency', e.target.value)} /> },
                        { title: 'Duration', width: 90, render: (_, med) => <Input size="small" placeholder="e.g. 3 days" value={medicationDetails[med.id]?.duration || ''} onChange={(e) => updateMedicationDetail(med.id, 'duration', e.target.value)} /> },
                        { title: 'Qty', width: 76, render: (_, med) => <InputNumber size="small" min={1} style={{ width: 64 }} value={medicationDetails[med.id]?.quantity || 1} onChange={(value) => updateMedicationDetail(med.id, 'quantity', value || 1)} /> },
                        { title: '', width: 40, render: (_, med) => <Button size="small" danger icon={<DeleteOutlined />} onClick={() => toggleMedication(med.id, false)} /> },
                      ]}
                    />
                    <Input
                      style={{ marginTop: 8 }}
                      placeholder="Other medication not in the list (optional)"
                      value={medicationOtherText}
                      onChange={(e) => setMedicationOtherText(e.target.value)}
                    />
                    <Form.Item label="Prescription Notes (Optional)" name="prescription_notes" style={{ margin: '12px 0 0' }}>
                      <TextArea rows={2} maxLength={500} showCount placeholder="e.g. Take medicines after food. If symptoms persist, return after 3 days." />
                    </Form.Item>
                  </Card>

                  <Card size="small" title="Doctor's Recommendation" style={{ marginBottom: 16 }}>
                    <Form.Item name="treatment_plan" extra="Required before forwarding to Pharmacy." style={{ marginBottom: 12 }}>
                      <TextArea rows={2} maxLength={500} showCount placeholder="e.g. Patient is stable. Continue treatment and review if symptoms persist." />
                    </Form.Item>
                    <Form.Item label="Patient Signature — Name" name="patient_signature_name" style={{ marginBottom: 8 }}>
                      <Input />
                    </Form.Item>
                    <Form.Item label="Patient Signature — Phone" name="patient_signature_phone" style={{ marginBottom: record.signed_at ? 8 : 0 }}>
                      <Input />
                    </Form.Item>
                    {record.signed_at && <Text type="secondary">Signed at {new Date(record.signed_at).toLocaleString()}</Text>}
                  </Card>

                  <Card size="small" title="Forward Patient To">
                    <Form.Item name="referral_target" rules={[{ required: true, message: 'Choose where the patient goes next' }]}>
                      <ForwardTiles options={RESULT_FORWARD_OPTIONS} />
                    </Form.Item>
                    {forwardChoice === 'refer' && (
                      <>
                        <Form.Item label="Refer To Department" name="refer_department_id" rules={[{ required: true, message: 'Choose a department' }]}>
                          <Select options={departments.filter((d) => d.dept_code !== 'REG' && d.dept_code !== 'CONS').map((d) => ({ label: d.dept_name, value: d.id }))} />
                        </Form.Item>
                        <Form.Item label="Reason for Referral" name="referral_reason" rules={[{ required: true, message: 'Reason is required' }]}>
                          <TextArea rows={2} />
                        </Form.Item>
                        <Form.Item label="Additional Notes (optional)" name="referral_notes">
                          <TextArea rows={2} />
                        </Form.Item>
                      </>
                    )}
                    {forwardChoice === 'followup' && (
                      <>
                        <Form.Item label="Follow-up Date" name="follow_up_date" rules={[{ required: true, message: 'Follow-up date is required' }]}>
                          <DatePicker style={{ width: '100%' }} disabledDate={(current) => current && current < dayjs().startOf('day')} format="YYYY-MM-DD" />
                        </Form.Item>
                        <Form.Item label="Follow-up Instructions" name="follow_up_instructions" rules={[{ required: true, message: 'Instructions are required' }]}>
                          <TextArea rows={2} />
                        </Form.Item>
                      </>
                    )}
                    <div className="result-actions">
                      <Button icon={<SaveOutlined />} loading={submitting} onClick={saveDraft}>Save Draft</Button>
                      <Button type="primary" icon={<SendOutlined />} loading={submitting} onClick={submitDoctor}>{FORWARD_BUTTON_LABELS[forwardChoice] || 'Forward'}</Button>
                      <Button onClick={onClose}>Cancel</Button>
                    </div>
                  </Card>
                </div>
              </div>
            )}

            {deptCode === 'LAB' && (
              <>
                <div className="lab-top">
                  <div className="billing-info">
                    <div className="billing-info__patient">
                      <span className="billing-avatar"><UserOutlined /></span>
                      <div>
                        <div className="billing-label">Patient Name</div>
                        <div className="billing-strong">{patient?.name || '—'}</div>
                        <div className="billing-small">Patient ID: {patient?.patient_number || '—'}</div>
                        <div className="billing-small">Age: {ageFromDob(patient?.date_of_birth) ?? '—'} &nbsp;|&nbsp; Gender: {patient?.gender || '—'}</div>
                      </div>
                    </div>
                    <div className="billing-info__diagnosis">
                      <div>
                        <div className="billing-small"><strong>Queue No:</strong> {ticket?.queue_number}</div>
                        {/* Referring doctor is computed server-side
                            (QueueTicketController::attachLabExtras) —
                            Service.doctor_id is only ever an optional
                            pre-selection, never who actually treated the patient. */}
                        <div className="billing-small"><strong>Department:</strong> {ticket?.service?.previousService?.department?.dept_name || '—'}</div>
                        <div className="billing-small"><strong>Doctor:</strong> {ticket?.referring_doctor || '—'}</div>
                        <div className="billing-small"><strong>Visit Date:</strong> {dayjs(ticket?.service?.visit?.visit_date || ticket?.created_at).format('DD MMM YYYY')}</div>
                        <Tag color={pendingLabTests.length === 0 && labTestRows.length > 0 ? 'green' : 'blue'} style={{ marginTop: 6 }}>
                          {pendingLabTests.length === 0 && labTestRows.length > 0 ? 'Results Recorded' : 'Tests Assigned'}
                        </Tag>
                      </div>
                    </div>
                  </div>

                  <div className="billing-summary" style={{ marginTop: 0 }}>
                    <h4 className="billing-section"><MedicineBoxOutlined /> Consultation Summary</h4>
                    <div className="billing-row" style={{ alignItems: 'flex-start' }}><span>Diagnosis</span><strong style={{ textAlign: 'right' }}>{record.final_diagnosis || record.doctor_preliminary_diagnosis || '—'}</strong></div>
                    <div className="billing-row" style={{ alignItems: 'flex-start' }}><span>Treatment Plan</span><strong style={{ textAlign: 'right' }}>{record.treatment_plan || '—'}</strong></div>
                    <div className="billing-row" style={{ alignItems: 'flex-start' }}><span>Notes</span><strong style={{ textAlign: 'right' }}>{record.doctor_symptoms_notes || '—'}</strong></div>
                  </div>
                </div>

                <div className="billing-grid" style={{ marginTop: 16 }}>
                  <div>
                    <Tabs
                      activeKey={labTab}
                      onChange={setLabTab}
                      items={[
                        {
                          key: 'ordered',
                          label: `Ordered Tests (${pendingLabTests.length})`,
                          children: (
                            <>
                              <h4 className="billing-section" style={{ marginTop: 4 }}>Tests Ordered by Doctor</h4>
                              <Text type="secondary" style={{ display: 'block', marginBottom: 10 }}>Perform the tests below and enter the results.</Text>
                              <Table
                                rowKey="id"
                                size="small"
                                pagination={false}
                                dataSource={pendingLabTests}
                                locale={{ emptyText: labTestRows.length > 0 ? 'All ordered tests have results.' : 'No specific tests were requested.' }}
                                columns={[
                                  { title: '#', width: 48, render: (_, __, index) => index + 1 },
                                  { title: 'Test Name', render: (_, row) => <div><strong>{row.name}</strong><div className="billing-small">{row.category}</div></div> },
                                  { title: 'Priority', render: () => <Tag color={ticket?.priority_level?.name === 'Critical' ? 'red' : 'blue'}>{ticket?.priority_level?.name || 'Normal'}</Tag> },
                                  { title: 'Status', render: () => <Tag color="gold">Pending</Tag> },
                                  ...(isInService ? [{ title: 'Action', render: (_, row) => <Button size="small" type="primary" icon={<EditOutlined />} onClick={() => setActiveTestId(row.id)}>Enter Result</Button> }] : []),
                                ]}
                              />
                              {otherText && <Alert style={{ marginTop: 10 }} type="info" showIcon message={`Other tests requested: ${otherText}`} />}
                            </>
                          ),
                        },
                        {
                          key: 'completed',
                          label: `Completed Tests (${doneLabTests.length})`,
                          children: (
                            <Table
                              rowKey="id"
                              size="small"
                              pagination={false}
                              dataSource={doneLabTests}
                              locale={{ emptyText: 'No results entered yet.' }}
                              columns={[
                                ...LAB_RESULT_COLUMNS,
                                ...(isInService ? [{ title: 'Action', render: (_, row) => <Button size="small" icon={<EditOutlined />} onClick={() => setActiveTestId(row.id)}>Edit</Button> }] : []),
                              ]}
                            />
                          ),
                        },
                        {
                          key: 'history',
                          label: 'Patient History',
                          children: (
                            <Descriptions column={1} size="small" bordered>
                              <Descriptions.Item label="Chief Complaint (Registration)">{record.chief_complaint || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                              <Descriptions.Item label="Reason for Laboratory Investigation">{record.doctor_symptoms_notes || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                              <Descriptions.Item label="Doctor's Notes">{record.doctor_preliminary_diagnosis || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                              <Descriptions.Item label="Vital Signs">
                                {(record.temperature || record.blood_pressure || record.weight || record.pulse_rate)
                                  ? [record.temperature && `Temp ${record.temperature} °C`, record.blood_pressure && `BP ${record.blood_pressure}`, record.weight && `Weight ${record.weight} kg`, record.pulse_rate && `Pulse ${record.pulse_rate} bpm`].filter(Boolean).join(' · ')
                                  : <Text type="secondary">Not recorded</Text>}
                              </Descriptions.Item>
                            </Descriptions>
                          ),
                        },
                      ]}
                    />
                    <div className="billing-note" style={{ alignItems: 'flex-start' }}>
                      <InfoCircleOutlined style={{ marginTop: 3 }} />
                      <div>
                        <strong>Important</strong>
                        <ul style={{ margin: '4px 0 0', paddingLeft: 18 }}>
                          <li>Enter accurate results based on the laboratory findings.</li>
                          <li>If a test was not performed, leave its status empty and explain why in the remarks.</li>
                          <li>Results will be visible to the doctor once you forward the form.</li>
                        </ul>
                      </div>
                    </div>
                  </div>

                  <div className="billing-summary">
                    <h4 className="billing-section"><ExperimentOutlined /> Enter Test Results</h4>
                    {isInService ? (
                      <>
                        {labTestRows.length > 0 ? (
                          <>
                            <div className="billing-label" style={{ marginBottom: 4 }}>Select Test</div>
                            <Select
                              style={{ width: '100%', marginBottom: 12 }}
                              placeholder="Choose a test"
                              value={activeTestId ?? undefined}
                              onChange={setActiveTestId}
                              options={labTestRows.map((row) => ({ label: `${row.name}${isTestDone(row) ? ' ✓' : ''}`, value: row.id }))}
                            />
                            {activeTestId ? (
                              <>
                                <div className="billing-label" style={{ marginBottom: 4 }}>Result</div>
                                <Input
                                  style={{ marginBottom: 8 }}
                                  placeholder="Result value"
                                  value={testResults[activeTestId]?.result_value || ''}
                                  onChange={(e) => updateTestResult(activeTestId, 'result_value', e.target.value)}
                                />
                                <Input
                                  style={{ marginBottom: 8 }}
                                  placeholder="Reference range"
                                  value={testResults[activeTestId]?.reference_range || ''}
                                  onChange={(e) => updateTestResult(activeTestId, 'reference_range', e.target.value)}
                                />
                                <Select
                                  style={{ width: '100%', marginBottom: 12 }}
                                  placeholder="Status"
                                  allowClear
                                  value={testResults[activeTestId]?.status || undefined}
                                  onChange={(value) => updateTestResult(activeTestId, 'status', value)}
                                  options={[{ label: 'Normal', value: 'Normal' }, { label: 'Abnormal', value: 'Abnormal' }, { label: 'Critical', value: 'Critical' }]}
                                />
                                <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
                                  <Button type="primary" block icon={<SaveOutlined />} loading={savingResult} onClick={saveTestResult}>Save Result</Button>
                                  <Button block icon={<ReloadOutlined />} onClick={resetTestResult}>Reset</Button>
                                </div>
                              </>
                            ) : (
                              <Text type="secondary" style={{ display: 'block', marginBottom: 12 }}>Pick a test above (or press Enter Result on the left) to record its result.</Text>
                            )}
                          </>
                        ) : (
                          <Text type="secondary" style={{ display: 'block', marginBottom: 12 }}>
                            No specific tests were requested — record an overall summary below.
                          </Text>
                        )}
                        <hr className="billing-divider" />
                        <Form.Item label="Remarks / Interpretation" name="lab_results_notes">
                          <TextArea rows={3} placeholder="Any narrative context not captured per-test above" />
                        </Form.Item>
                        <Form.Item label="Forward To" style={{ marginBottom: 0 }}>
                          <Radio.Group value={labForwardTarget} onChange={(e) => setLabForwardTarget(e.target.value)}>
                            <Radio.Button value="CONS">Doctor</Radio.Button>
                            <Radio.Button value="PHARM">Pharmacy</Radio.Button>
                          </Radio.Group>
                        </Form.Item>
                      </>
                    ) : (
                      <Descriptions column={1} size="small" bordered>
                        <Descriptions.Item label="Remarks / Interpretation">{record.lab_results_notes || <Text type="secondary">Not recorded</Text>}</Descriptions.Item>
                      </Descriptions>
                    )}
                  </div>
                </div>
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
                    <Table
                      rowKey="id"
                      size="small"
                      pagination={false}
                      dataSource={selectedMedications}
                      columns={[
                        { title: '#', width: 40, render: (_, __, index) => index + 1 },
                        { title: 'Medicine Name', dataIndex: 'name' },
                        { title: 'Dosage', render: (_, med) => medicationDetails[med.id]?.dosage || '—' },
                        { title: 'Frequency', render: (_, med) => medicationDetails[med.id]?.frequency || '—' },
                        { title: 'Duration', render: (_, med) => medicationDetails[med.id]?.duration || '—' },
                        { title: 'Quantity', render: (_, med) => medicationDetails[med.id]?.quantity || 1 },
                      ]}
                    />
                    {record.prescription_notes && (
                      <Descriptions column={1} size="small" bordered style={{ marginTop: 8 }}>
                        <Descriptions.Item label="Prescription Notes">{record.prescription_notes}</Descriptions.Item>
                      </Descriptions>
                    )}
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
