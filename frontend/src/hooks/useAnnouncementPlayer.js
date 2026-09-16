import { useCallback, useRef, useState } from 'react';
import { message } from 'antd';
import { enqueueAnnouncement, unlockAudio } from '../services/announcementPlayer';

/**
 * Lets a staff dashboard (Doctor/Laboratory/Pharmacy/Registration queue
 * pages) play a just-called ticket's announcement immediately, in the same
 * tab the call action happened — an ADDITIONAL playback trigger alongside
 * the Waiting-Display kiosk page's own independent playback, not a
 * replacement for it. Reuses the exact Howler-based FIFO player and
 * browser-autoplay unlock gesture the kiosk page already built (see
 * announcementPlayer.js) rather than duplicating that logic.
 *
 * The unlock is per-tab (a browser only grants audio permission to the
 * gesture that actually played something), so every dashboard that wants
 * this needs its own instance of this hook and its own "Enable Sound"
 * click, same one-time-per-session pattern as the kiosk page.
 */
export default function useAnnouncementPlayer() {
  const [audioEnabled, setAudioEnabled] = useState(false);
  const audioEnabledRef = useRef(false);

  // Silent unlock — these are the staff Call buttons, not the unattended
  // kiosk board, so hearing the ring here (before any patient has actually
  // been called) would be confusing rather than reassuring. See
  // unlockAudio()'s doc comment for why the kiosk board plays it audibly.
  const enableAudio = useCallback(() => {
    unlockAudio({ silent: true }).then((played) => {
      if (!played) {
        message.error('Could not play sound — check this device/browser is not muted or on silent mode, then try again.');
        return;
      }
      audioEnabledRef.current = true;
      setAudioEnabled(true);
    });
  }, []);

  // Fires a visible toast every time it actually queues audio — a muted
  // physical speaker looks identical to "nothing happened" from a code
  // bug, so staff need this to know it fired and they can check the
  // waiting-area kiosk board instead.
  const playAnnouncement = useCallback((ticketId) => {
    if (!audioEnabledRef.current || !ticketId) return;
    enqueueAnnouncement(ticketId);
    message.success('🔊 Announcement played');
  }, []);

  return { audioEnabled, enableAudio, playAnnouncement };
}
