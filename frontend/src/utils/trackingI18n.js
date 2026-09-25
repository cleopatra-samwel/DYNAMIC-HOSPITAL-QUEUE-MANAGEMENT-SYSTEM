import { useCallback, useState } from 'react';

const STORAGE_KEY = 'tracking-language';

/**
 * English / Kiswahili strings for the public tracking page. Kept as a
 * plain dictionary (no i18n library) since only this one public page is
 * bilingual. Notification messages shown under "Your Updates" come from
 * the backend already-written in English and are displayed as-is.
 */
const DICTIONARY = {
  en: {
    home: 'Home',
    status: 'Status',
    language: 'Language',
    yourVisit: 'Your Visit',
    openMenu: 'Open menu',
    closeMenu: 'Close menu',
    department: 'Department',
    yourQueueNumber: 'Your queue number',
    registration: 'Registration',
    registered: 'Registered',
    next: "You're next.",
    patientsAhead: (n) => `${n} patient(s) ahead of you.`,
    estimatedWait: (n) => `Estimated wait: ~${n} min.`,
    whatHappensNext: 'What happens next',
    needHelp: 'Need help?',
    support: 'Support/Emergency',
    aheadOfYou: 'Ahead of you',
    behindYou: 'Behind you',
    nobodyAhead: 'Nobody is ahead of you — you are next.',
    nobodyBehind: 'Nobody is behind you yet.',
    statusUnavailable: 'The queue list is only shown while you are waiting.',
    yourUpdates: 'Your Updates',
    noUpdates: 'No updates yet',
    notFoundTitle: 'Tracking link not found',
    notFoundSub: 'This link may be incorrect or the visit no longer exists.',
    reconnecting: 'Reconnecting...',
    statusLabels: {
      WAITING: 'Waiting',
      CALLED: 'You are being called — please proceed',
      IN_SERVICE: 'In service',
      COMPLETED: 'Completed',
      NO_SHOW: 'Marked as no-show',
      CANCELLED: 'Cancelled',
      ON_HOLD: 'On hold',
      TRANSFERRED: 'Transferred',
    },
    hints: {
      WAITING: (dept) => `You are waiting for ${dept || 'your department'}.`,
      CALLED: (dept) => `You have been called — please proceed to ${dept || 'the counter shown on screen'}.`,
      IN_SERVICE: (dept) => `You are currently being seen in ${dept || 'your department'}.`,
      ON_HOLD: () => 'Your case is on hold — please wait, you will be called again.',
      TRANSFERRED: (dept) => `You are being transferred to ${dept || 'your next department'}.`,
      COMPLETED: () => 'Your visit is complete. Thank you.',
      NO_SHOW: () => 'You were marked as not present — please check in again at Registration.',
      CANCELLED: () => 'This ticket was cancelled.',
      DEFAULT: () => 'Please wait — your status will update automatically.',
    },
  },
  sw: {
    home: 'Mwanzo',
    status: 'Hali',
    language: 'Lugha',
    yourVisit: 'Ziara Yako',
    openMenu: 'Fungua menyu',
    closeMenu: 'Funga menyu',
    department: 'Idara',
    yourQueueNumber: 'Namba yako ya foleni',
    registration: 'Usajili',
    registered: 'Umesajiliwa',
    next: 'Wewe ndiye unayefuata.',
    patientsAhead: (n) => `Wagonjwa ${n} wako mbele yako.`,
    estimatedWait: (n) => `Muda wa kusubiri: takriban dakika ${n}.`,
    whatHappensNext: 'Kinachofuata',
    needHelp: 'Unahitaji msaada?',
    support: 'Msaada/Dharura',
    aheadOfYou: 'Walio mbele yako',
    behindYou: 'Walio nyuma yako',
    nobodyAhead: 'Hakuna aliye mbele yako — wewe ndiye unayefuata.',
    nobodyBehind: 'Bado hakuna aliye nyuma yako.',
    statusUnavailable: 'Orodha ya foleni inaonekana tu wakati unasubiri.',
    yourUpdates: 'Taarifa Zako',
    noUpdates: 'Bado hakuna taarifa',
    notFoundTitle: 'Kiungo cha ufuatiliaji hakijapatikana',
    notFoundSub: 'Kiungo hiki kinaweza kuwa si sahihi au ziara haipo tena.',
    reconnecting: 'Inaunganisha tena...',
    statusLabels: {
      WAITING: 'Unasubiri',
      CALLED: 'Unaitwa — tafadhali sogea mbele',
      IN_SERVICE: 'Unahudumiwa',
      COMPLETED: 'Umekamilika',
      NO_SHOW: 'Umewekwa kama hukuwepo',
      CANCELLED: 'Imeghairiwa',
      ON_HOLD: 'Imesimamishwa',
      TRANSFERRED: 'Umehamishwa',
    },
    hints: {
      WAITING: (dept) => `Unasubiri ${dept || 'idara yako'}.`,
      CALLED: (dept) => `Umeitwa — tafadhali nenda ${dept || 'kwenye kaunta inayoonekana kwenye skrini'}.`,
      IN_SERVICE: (dept) => `Kwa sasa unahudumiwa ${dept || 'kwenye idara yako'}.`,
      ON_HOLD: () => 'Kesi yako imesimamishwa — tafadhali subiri, utaitwa tena.',
      TRANSFERRED: (dept) => `Unahamishiwa ${dept || 'idara inayofuata'}.`,
      COMPLETED: () => 'Ziara yako imekamilika. Asante.',
      NO_SHOW: () => 'Ulionekana hukuwepo — tafadhali jisajili tena Usajili.',
      CANCELLED: () => 'Tiketi hii imeghairiwa.',
      DEFAULT: () => 'Tafadhali subiri — hali yako itasasishwa yenyewe.',
    },
  },
};

function readStoredLanguage() {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    return stored === 'sw' ? 'sw' : 'en';
  } catch {
    return 'en';
  }
}

/** Current tracking-page language ('en' | 'sw'), its strings, and a setter that persists the choice. */
export default function useTrackingLanguage() {
  const [language, setLanguageState] = useState(readStoredLanguage);

  const setLanguage = useCallback((next) => {
    setLanguageState(next);
    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch {
      // Storage unavailable (private mode) — the choice just won't persist.
    }
  }, []);

  return { language, setLanguage, t: DICTIONARY[language] };
}
