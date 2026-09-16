import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import axios from 'axios';

// /broadcasting/auth lives at the app root, not under /api — this app's
// VITE_API_BASE_URL always ends in "/api" (see apiClient.js), so strip it
// to get the root the Reverb auth endpoint hangs off of.
const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api';
const APP_ROOT = API_BASE.replace(/\/api\/?$/, '');

window.Pusher = Pusher;

let echoInstance = null;

const connectionListeners = new Set();

function notifyConnectionListeners(state) {
  connectionListeners.forEach((listener) => listener(state));
}

/**
 * A single shared Echo/Reverb connection for the whole app — private
 * channel subscriptions (department.*) need a Bearer token, but Echo's
 * default authorizer sends cookies, not an Authorization header, which is
 * useless against this app's Sanctum Bearer-token auth. This custom
 * authorizer posts to /broadcasting/auth itself, attaching whatever token
 * is currently in localStorage at subscribe time (not just at Echo
 * construction time, since login/logout can happen after Echo already
 * exists).
 */
export function getEcho() {
  if (echoInstance) return echoInstance;

  echoInstance = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST || 'localhost',
    wsPort: import.meta.env.VITE_REVERB_PORT || 8080,
    wssPort: import.meta.env.VITE_REVERB_PORT || 8080,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel) => ({
      authorize: (socketId, callback) => {
        const token = localStorage.getItem('auth_token');
        axios
          .post(
            `${APP_ROOT}/broadcasting/auth`,
            { socket_id: socketId, channel_name: channel.name },
            {
              headers: {
                Accept: 'application/json',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
              },
            }
          )
          .then((response) => callback(false, response.data))
          .catch((error) => callback(true, error));
      },
    }),
  });

  const pusher = echoInstance.connector.pusher;
  pusher.connection.bind('connected', () => notifyConnectionListeners('connected'));
  pusher.connection.bind('connecting', () => notifyConnectionListeners('connecting'));
  pusher.connection.bind('unavailable', () => notifyConnectionListeners('reconnecting'));
  pusher.connection.bind('failed', () => notifyConnectionListeners('reconnecting'));
  pusher.connection.bind('disconnected', () => notifyConnectionListeners('reconnecting'));

  return echoInstance;
}

/** Subscribe to connection state changes: 'connecting' | 'connected' | 'reconnecting'. Returns an unsubscribe function. */
export function onConnectionStateChange(listener) {
  connectionListeners.add(listener);
  return () => connectionListeners.delete(listener);
}

export function currentConnectionState() {
  return echoInstance?.connector?.pusher?.connection?.state || 'connecting';
}
