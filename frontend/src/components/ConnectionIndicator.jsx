import { Tag } from 'antd';
import { LoadingOutlined, WifiOutlined } from '@ant-design/icons';

/**
 * Small non-blocking banner — never covers the screen or blocks
 * interaction, just tells the viewer their data might be a beat behind
 * while the live connection recovers.
 */
export default function ConnectionIndicator({ state }) {
  if (state === 'connected') return null;

  return (
    <div style={{ position: 'fixed', top: 12, right: 12, zIndex: 1000 }}>
      <Tag icon={<LoadingOutlined spin />} color="warning">
        Reconnecting...
      </Tag>
    </div>
  );
}

export function ConnectedBadge({ state }) {
  if (state !== 'connected') return null;
  return (
    <Tag icon={<WifiOutlined />} color="success" style={{ marginLeft: 8 }}>
      Live
    </Tag>
  );
}
