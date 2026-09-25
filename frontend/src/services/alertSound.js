let audioContext = null;

/**
 * Three short beeps for the overdue-wait alert — generated with the Web
 * Audio API so there is no audio file to ship. Silently does nothing if the
 * browser blocks audio (it only starts after the user has interacted with
 * the page, which a logged-in dashboard always has).
 */
export function playAlertSound() {
  try {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) return;

    audioContext = audioContext || new AudioContextClass();
    if (audioContext.state === 'suspended') audioContext.resume();

    [0, 0.35, 0.7].forEach((offset) => {
      const start = audioContext.currentTime + offset;
      const oscillator = audioContext.createOscillator();
      const gain = audioContext.createGain();

      oscillator.type = 'sine';
      oscillator.frequency.value = 880;
      gain.gain.setValueAtTime(0.0001, start);
      gain.gain.exponentialRampToValueAtTime(0.3, start + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.25);

      oscillator.connect(gain);
      gain.connect(audioContext.destination);
      oscillator.start(start);
      oscillator.stop(start + 0.27);
    });
  } catch {
    // Audio unavailable — the on-screen notification still shows.
  }
}
