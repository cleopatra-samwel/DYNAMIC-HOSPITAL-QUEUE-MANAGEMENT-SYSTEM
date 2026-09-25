import { useEffect, useMemo, useState } from 'react';
import { Alert, Button, Input, InputNumber, Modal, Select, Space, Table, message } from 'antd';
import {
  ArrowLeftOutlined, CheckOutlined, DeleteOutlined, DollarOutlined, HeartOutlined,
  InfoCircleOutlined, MedicineBoxOutlined, PlusOutlined, PrinterOutlined, UserOutlined, WalletOutlined,
} from '@ant-design/icons';
import dayjs from 'dayjs';
import apiClient from '../../services/apiClient';

const CURRENCY = 'TZS';
const money = (value) => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });

const SECTION_TITLES = { PHARM: 'Tests Performed & Prescribed Medicines', LAB: 'Requested Tests', CONS: 'Consultation Charges' };
const CATALOG_TYPES = { LAB: 'lab_test', PHARM: 'medication' };

/** Same starting point as before the redesign: pre-select what the Doctor requested (Lab) or prescribed (Pharmacy). */
function initialItems(target, labTests, medications) {
  const now = Date.now();
  if (target.dept_code === 'LAB') {
    return labTests
      .filter((t) => target.requested_test_catalog_ids?.includes(t.id))
      .map((t) => ({ key: `lab_test-${t.id}-${now}`, catalog_type: 'lab_test', catalog_item_id: t.id, custom_label: null, label: t.name, unit_price: Number(t.price), quantity: 1 }));
  }
  if (target.dept_code === 'PHARM') {
    // Payment is taken once, before Pharmacy: the tests done earlier in the visit + every medicine the doctor prescribed.
    const tests = labTests
      .filter((t) => target.requested_test_catalog_ids?.includes(t.id))
      .map((t) => ({ key: `lab_test-${t.id}-${now}`, catalog_type: 'lab_test', catalog_item_id: t.id, custom_label: null, label: t.name, detail: 'Laboratory test', unit_price: Number(t.price), quantity: 1 }));
    const medicines = medications
      .filter((m) => target.prescribed_medication_catalog_ids?.includes(m.id))
      .map((m) => {
        const line = target.prescribed_medications?.find((p) => p.medication_catalog_id === m.id);
        const detail = [line?.dosage, line?.frequency, line?.duration].filter(Boolean).join(' · ');
        return { key: `medication-${m.id}-${now}`, catalog_type: 'medication', catalog_item_id: m.id, custom_label: null, label: m.name, detail: detail || 'Medicine', unit_price: Number(m.price), quantity: line?.quantity || 1 };
      });
    return [...tests, ...medicines];
  }
  return [];
}

function printReceipt({ target, items, receipt }) {
  const rows = items
    .map((item, index) => `<tr><td>${index + 1}</td><td>${item.label}</td><td style="text-align:right">${item.quantity}</td><td style="text-align:right">${money(item.unit_price)}</td><td style="text-align:right">${money(item.quantity * item.unit_price)}</td></tr>`)
    .join('');
  const html = `<html><head><title>Receipt</title><style>body{font-family:Arial,sans-serif;padding:24px}table{width:100%;border-collapse:collapse;margin:12px 0}td,th{border:1px solid #ccc;padding:6px;text-align:left}h2,h3{margin:4px 0}</style></head><body>
    <h2>Muhimbili National Hospital</h2><h3>Payment Receipt</h3>
    <p>Patient: ${target.patient_name} ${target.patient_number ? `(${target.patient_number})` : ''}<br/>Department: ${target.department}<br/>Date: ${dayjs(receipt.paidAt).format('DD MMM YYYY, HH:mm')}<br/>Method: ${receipt.method}</p>
    <table><tr><th>#</th><th>Item</th><th>Qty</th><th>Unit Price (${CURRENCY})</th><th>Total (${CURRENCY})</th></tr>${rows}</table>
    <p><strong>Total: ${CURRENCY} ${money(receipt.total)}</strong><br/>Paid: ${CURRENCY} ${money(receipt.received)}<br/>Change: ${CURRENCY} ${money(receipt.change)}</p>
    </body></html>`;
  const win = window.open('', '_blank', 'width=720,height=800');
  if (!win) {
    message.error('Allow pop-ups to print the receipt.');
    return;
  }
  win.document.write(html);
  win.document.close();
  win.focus();
  win.print();
}

/**
 * Cashier Billing form — opened from Pending Payments / the Billing Queue
 * ("Confirm Payment"). Shows what the Doctor recorded (patient, diagnosis,
 * prescribed medicines / requested tests), lets the cashier adjust
 * quantities and add charge lines, then takes the payment at the end via
 * Process Payment. The payment is only sent to the API by Process Payment;
 * Back to Queue leaves without paying, Complete Billing closes after it.
 */
export default function CashierBillingModal({ target, labTests, medications, onClose }) {
  const [items, setItems] = useState([]);
  const [method, setMethod] = useState('Cash');
  const [insuranceProvider, setInsuranceProvider] = useState('');
  const [insuranceRef, setInsuranceRef] = useState('');
  const [received, setReceived] = useState(null);
  const [catalogSelection, setCatalogSelection] = useState(null);
  const [otherLabel, setOtherLabel] = useState('');
  const [otherAmount, setOtherAmount] = useState(null);
  const [processing, setProcessing] = useState(false);
  const [receipt, setReceipt] = useState(null);

  useEffect(() => {
    if (!target) return;
    setItems(initialItems(target, labTests, medications));
    setMethod(target.payment_method || 'Cash');
    setInsuranceProvider('');
    setInsuranceRef('');
    setReceived(null);
    setCatalogSelection(null);
    setOtherLabel('');
    setOtherAmount(null);
    setReceipt(null);
    // Only re-seed when a different service is opened, not when catalogs finish loading mid-edit.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [target?.service_id]);

  const catalogType = target ? CATALOG_TYPES[target.dept_code] : null;
  const catalog = catalogType === 'lab_test' ? labTests : catalogType === 'medication' ? medications : [];

  const total = useMemo(() => items.reduce((sum, item) => sum + item.quantity * item.unit_price, 0), [items]);
  const isCash = method === 'Cash';
  const paidAmount = receipt ? receipt.received : isCash ? Number(received || 0) : 0;
  const balance = receipt ? 0 : Math.max(total - paidAmount, 0);
  const change = isCash ? Math.max(paidAmount - total, 0) : 0;

  const canProcess = !receipt && items.length > 0 && total > 0
    && (isCash ? Number(received || 0) >= total : insuranceProvider.trim() !== '' && insuranceRef.trim() !== '');

  const addCatalogItem = () => {
    const entry = catalog.find((c) => c.id === catalogSelection);
    if (!entry) return;
    setItems((prev) => [...prev, { key: `${catalogType}-${entry.id}-${Date.now()}`, catalog_type: catalogType, catalog_item_id: entry.id, custom_label: null, label: entry.name, unit_price: Number(entry.price), quantity: 1 }]);
    setCatalogSelection(null);
  };

  const addOtherItem = () => {
    if (!otherLabel || otherAmount === null) return;
    setItems((prev) => [...prev, { key: `other-${Date.now()}`, catalog_type: 'other', catalog_item_id: null, custom_label: otherLabel, label: otherLabel, unit_price: Number(otherAmount), quantity: 1 }]);
    setOtherLabel('');
    setOtherAmount(null);
  };

  const setQuantity = (key, quantity) => setItems((prev) => prev.map((item) => (item.key === key ? { ...item, quantity: quantity || 1 } : item)));
  const removeItem = (key) => setItems((prev) => prev.filter((item) => item.key !== key));

  const processPayment = async () => {
    setProcessing(true);
    try {
      await apiClient.post(`/services/${target.service_id}/payments/verify`, {
        method,
        insurance_provider: isCash ? null : insuranceProvider,
        insurance_ref: isCash ? null : insuranceRef,
        items: items.map(({ catalog_type, catalog_item_id, custom_label, quantity, unit_price }) => ({
          catalog_type,
          catalog_item_id,
          custom_label,
          amount: Math.round(quantity * unit_price * 100) / 100,
        })),
      });
      setReceipt({ total, received: isCash ? Number(received) : total, change, method, paidAt: new Date().toISOString() });
      message.success(`Payment processed for ${target.patient_name}.`);
    } catch (error) {
      message.error(error.response?.data?.message || 'Could not process payment.');
    } finally {
      setProcessing(false);
    }
  };

  const columns = [
    { title: '#', width: 48, render: (_, __, index) => index + 1 },
    { title: 'Item', render: (_, item) => <div><strong>{item.label}</strong>{item.detail && <div className="billing-small">{item.detail}</div>}</div> },
    {
      title: 'Quantity',
      width: 110,
      render: (_, item) => (receipt ? item.quantity : <InputNumber size="small" min={1} value={item.quantity} onChange={(value) => setQuantity(item.key, value)} />),
    },
    { title: `Unit Price (${CURRENCY})`, align: 'right', render: (_, item) => money(item.unit_price) },
    { title: `Total (${CURRENCY})`, align: 'right', render: (_, item) => money(item.quantity * item.unit_price) },
    ...(receipt ? [] : [{ title: '', width: 48, render: (_, item) => <Button size="small" danger icon={<DeleteOutlined />} onClick={() => removeItem(item.key)} /> }]),
  ];

  const notes = target ? [
    target.dept_code === 'PHARM' && target.prescribed_medications_other ? `Other medicines: ${target.prescribed_medications_other}` : null,
    target.dept_code === 'LAB' && target.requested_tests_other ? `Other tests: ${target.requested_tests_other}` : null,
    target.treatment_plan ? `Treatment plan: ${target.treatment_plan}` : null,
  ].filter(Boolean) : [];

  return (
    <Modal
      open={!!target}
      onCancel={() => onClose(!!receipt)}
      width={960}
      destroyOnHidden
      maskClosable={false}
      title={(
        <div className="billing-title">
          <span className="billing-title__icon"><DollarOutlined /></span>
          <div>
            <div className="billing-title__main">Cashier Billing</div>
            <div className="billing-title__sub">View patient, diagnosis and medicines, then process payment.</div>
          </div>
        </div>
      )}
      footer={(
        <div className="billing-footer">
          <Button icon={<ArrowLeftOutlined />} onClick={() => onClose(!!receipt, true)}>Back to Queue</Button>
          <Space>
            <Button icon={<PrinterOutlined />} disabled={!receipt} onClick={() => printReceipt({ target, items, receipt })}>Print Receipt</Button>
            <Button type="primary" icon={<CheckOutlined />} disabled={!receipt} onClick={() => onClose(true)}>Complete Billing</Button>
          </Space>
        </div>
      )}
    >
      {target && (
        <div className="billing-grid">
          <div>
            <div className="billing-info">
              <div className="billing-info__patient">
                <span className="billing-avatar"><UserOutlined /></span>
                <div>
                  <div className="billing-label">Patient Name</div>
                  <div className="billing-strong">{target.patient_name}</div>
                  <div className="billing-small">Patient ID: {target.patient_number || '—'}</div>
                  <div className="billing-small">Phone: {target.patient_contact || '—'}</div>
                </div>
              </div>
              <div className="billing-info__diagnosis">
                <HeartOutlined className="billing-info__icon" />
                <div>
                  <div className="billing-label">Diagnosis</div>
                  <div className="billing-strong">{target.final_diagnosis || 'Not recorded yet'}</div>
                  <div className="billing-small">Doctor: {target.doctor_name || '—'}</div>
                  <div className="billing-small">Date: {target.consulted_at ? dayjs(target.consulted_at).format('DD MMM YYYY  HH:mm') : '—'}</div>
                </div>
              </div>
            </div>

            <h4 className="billing-section"><MedicineBoxOutlined /> {SECTION_TITLES[target.dept_code] || 'Charges'}</h4>
            <Table rowKey="key" size="small" pagination={false} columns={columns} dataSource={items} locale={{ emptyText: 'No items added yet' }} />

            {!receipt && (
              <div style={{ marginTop: 12 }}>
                {catalogType && (
                  <Space.Compact style={{ width: '100%', marginBottom: 8 }}>
                    <Select
                      showSearch
                      style={{ width: '70%' }}
                      placeholder={catalogType === 'lab_test' ? 'Search lab tests…' : 'Search medications…'}
                      value={catalogSelection}
                      onChange={setCatalogSelection}
                      optionFilterProp="label"
                      options={catalog.map((c) => ({ label: `${c.name} — ${money(c.price)}`, value: c.id }))}
                    />
                    <Button icon={<PlusOutlined />} onClick={addCatalogItem} disabled={!catalogSelection}>Add</Button>
                  </Space.Compact>
                )}
                <Space.Compact style={{ width: '100%' }}>
                  <Input style={{ width: '45%' }} placeholder="Other — custom label" value={otherLabel} onChange={(e) => setOtherLabel(e.target.value)} />
                  <InputNumber style={{ width: '30%' }} placeholder="Amount" min={0} precision={2} value={otherAmount} onChange={setOtherAmount} />
                  <Button icon={<PlusOutlined />} onClick={addOtherItem} disabled={!otherLabel || otherAmount === null}>Add</Button>
                </Space.Compact>
              </div>
            )}

            {notes.length > 0 && <Alert style={{ marginTop: 12 }} type="info" showIcon message={notes.join(' · ')} />}
            <div className="billing-note"><InfoCircleOutlined /> Please verify the medicines and quantities before confirming payment.</div>
          </div>

          <div className="billing-summary">
            <h4 className="billing-section"><DollarOutlined /> Payment Summary</h4>
            <div className="billing-row"><span>Total Amount</span><strong>{CURRENCY} {money(total)}</strong></div>
            <div className="billing-row"><span>Paid Amount</span><strong style={{ color: '#1668dc' }}>{CURRENCY} {money(receipt ? receipt.received : paidAmount)}</strong></div>
            <div className="billing-row"><span>Balance</span><strong style={{ color: balance > 0 ? '#d92b3a' : '#1a7f37' }}>{CURRENCY} {money(balance)}</strong></div>

            <hr className="billing-divider" />

            <h4 className="billing-section"><WalletOutlined /> Payment Method</h4>
            <Select
              style={{ width: '100%', marginBottom: 12 }}
              value={method}
              disabled={!!receipt}
              onChange={setMethod}
              options={[{ label: 'Cash', value: 'Cash' }, { label: 'Insurance', value: 'Insurance' }]}
            />

            {isCash ? (
              <>
                <InputNumber
                  style={{ width: '100%', marginBottom: 12 }}
                  placeholder={`Amount Received (${CURRENCY})`}
                  min={0}
                  precision={2}
                  disabled={!!receipt}
                  value={receipt ? receipt.received : received}
                  onChange={setReceived}
                />
                <div className="billing-change"><span>Change</span><strong>{CURRENCY} {money(receipt ? receipt.change : change)}</strong></div>
              </>
            ) : (
              <>
                <Input style={{ marginBottom: 8 }} placeholder="Insurance Provider" disabled={!!receipt} value={insuranceProvider} onChange={(e) => setInsuranceProvider(e.target.value)} />
                <Input style={{ marginBottom: 12 }} placeholder="Insurance Ref/Claim No." disabled={!!receipt} value={insuranceRef} onChange={(e) => setInsuranceRef(e.target.value)} />
              </>
            )}

            <Button type="primary" size="large" block icon={<CheckOutlined />} loading={processing} disabled={!canProcess} onClick={processPayment}>
              {receipt ? 'Payment Processed' : 'Process Payment'}
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
