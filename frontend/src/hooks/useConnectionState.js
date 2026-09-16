import { useEffect, useState } from 'react';
import { getEcho, onConnectionStateChange, currentConnectionState } from '../services/echo';

/**
 * Tracks the shared Echo/Reverb connection state so any screen can show a
 * "Reconnecting..." indicator instead of silently going stale. Also fires
 * `onReconnect` exactly once per reconnect (transitioning INTO 'connected'
 * from something else, not the initial connect) — callers use this for the
 * one-time full refetch Phase 7 requires instead of trusting that every
 * WebSocket event during the gap was actually received.
 */
export default function useConnectionState(onReconnect) {
  const [state, setState] = useState(currentConnectionState());

  useEffect(() => {
    getEcho();
    // Only a 'connected' that follows an EARLIER 'connected' counts as a
    // reconnect — the very first connect (connecting -> connected) must
    // not fire onReconnect, only a real drop-and-recover afterward.
    let hasConnectedBefore = false;

    const unsubscribe = onConnectionStateChange((next) => {
      setState((prev) => {
        if (next === 'connected' && prev !== 'connected' && hasConnectedBefore) {
          onReconnect?.();
        }
        if (next === 'connected') {
          hasConnectedBefore = true;
        }
        return next;
      });
    });

    return unsubscribe;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return state;
}
