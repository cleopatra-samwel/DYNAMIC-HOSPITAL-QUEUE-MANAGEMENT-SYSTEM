import { Empty } from 'antd';

/**
 * Placeholder — no audit log backend exists yet (that's Phase 10, not
 * built as of this fix). This page only exists so the sidebar entry the
 * spec asked for has somewhere real to navigate to; it's honestly
 * labeled as not-yet-implemented rather than faking data.
 */
export default function AuditLogPage() {
  return <Empty description="Audit Log is not implemented yet — this is a placeholder for a future phase." />;
}
