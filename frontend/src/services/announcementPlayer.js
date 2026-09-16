import { Howl } from 'howler';
import axios from 'axios';

const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api';

// A FIFO queue of full announcement sequences (not individual clips) — two
// patients called at nearly the same moment must never have their audio
// overlap/talk over each other, so the whole board only ever plays one
// ticket's sequence at a time, queuing the rest.
const announcementQueue = [];
let isPlaying = false;

function playClip(url) {
  return new Promise((resolve) => {
    if (!url) {
      // Graceful fallback for a label with no uploaded clip yet — skip
      // silently rather than breaking the rest of the sequence.
      resolve();
      return;
    }
    const howl = new Howl({ src: [url], html5: true, onend: resolve, onloaderror: resolve, onplayerror: resolve });
    howl.play();
  });
}

async function playSequence(sequence) {
  for (const clip of sequence) {
    // eslint-disable-next-line no-await-in-loop
    await playClip(clip.url);
  }
}

async function processQueue() {
  if (isPlaying) return;
  isPlaying = true;

  while (announcementQueue.length > 0) {
    const sequence = announcementQueue.shift();
    // eslint-disable-next-line no-await-in-loop
    await playSequence(sequence);
  }

  isPlaying = false;
}

/** Fetches the announcement sequence for a called ticket and queues it for playback, never interrupting an announcement already in progress. */
export function enqueueAnnouncement(ticketId) {
  axios.get(`${API_BASE}/queue-tickets/${ticketId}/announcement`)
    .then(({ data }) => {
      announcementQueue.push(data.sequence);
      processQueue();
    })
    .catch(() => {});
}

/**
 * Plays a clip immediately, synchronously within whatever user gesture
 * calls this (an "Enable Sound"/"Enable Announcements" button click) —
 * not just a flag flip. Some browsers (mobile Safari in particular) only
 * grant audio permission to the exact <audio> element that actually
 * played during the gesture, not to the page as a whole — a later
 * WebSocket-triggered Howl instance created outside any gesture can
 * still get silently blocked even after an earlier unrelated click.
 * Playing something for real here is the actual unlock.
 *
 * @param {{ silent?: boolean }} [options] - `silent: true` still plays the
 * clip (required for the unlock to count) but at volume 0, for the staff
 * Call buttons where hearing the ring on this click — before any patient
 * has actually been called — is confusing. The waiting-area kiosk board
 * wants the opposite: it calls this with no options so the ring is
 * audible, doubling as proof-of-life that the speaker isn't muted, since
 * no one is there to click anything else on that unattended screen.
 * @returns {Promise<boolean>} whether the clip actually reported playing.
 */
export function unlockAudio({ silent = false } = {}) {
  return new Promise((resolve) => {
    const howl = new Howl({
      src: [`${API_BASE.replace(/\/api\/?$/, '')}/storage/audio-clips/sfx_ring.mp3`],
      html5: true,
      volume: silent ? 0 : 1,
      onplay: () => resolve(true),
      onloaderror: () => resolve(false),
      onplayerror: () => resolve(false),
    });
    howl.play();
  });
}
