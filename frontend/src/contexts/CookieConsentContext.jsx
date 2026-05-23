import { createContext, useCallback, useContext, useEffect, useState } from 'react';

const CONSENT_KEY     = 'dm_cookie_consent';
const CONSENT_VERSION = 1;

function readStored() {
  try {
    const raw = localStorage.getItem(CONSENT_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    // If the consent schema version changed we must re-ask
    if (parsed.v !== CONSENT_VERSION) return null;
    return parsed;
  } catch {
    return null;
  }
}

function persist(prefs) {
  const record = {
    v: CONSENT_VERSION,
    necessary: true,
    analytics: !!prefs.analytics,
    marketing: !!prefs.marketing,
    ts: new Date().toISOString(),
  };
  localStorage.setItem(CONSENT_KEY, JSON.stringify(record));
  return record;
}

const ConsentContext = createContext(null);

export function CookieConsentProvider({ children }) {
  const [consent, setConsent]           = useState(() => readStored());
  const [showBanner, setShowBanner]     = useState(() => readStored() === null);
  const [showSettings, setShowSettings] = useState(false);

  // Activate GA4 only when analytics consent is explicitly given
  useEffect(() => {
    const gaId = import.meta.env.VITE_GA_MEASUREMENT_ID;
    if (!gaId || !consent?.analytics) return;
    // Dynamically import so GA4 is never bundled before consent
    import('react-ga4').then(({ default: ReactGA }) => {
      ReactGA.initialize(gaId);
    });
  }, [consent?.analytics]);

  const acceptAll = useCallback(() => {
    const record = persist({ analytics: true, marketing: true });
    setConsent(record);
    setShowBanner(false);
    setShowSettings(false);
  }, []);

  const rejectNonEssential = useCallback(() => {
    const record = persist({ analytics: false, marketing: false });
    setConsent(record);
    setShowBanner(false);
    setShowSettings(false);
  }, []);

  const saveCustom = useCallback((prefs) => {
    const record = persist(prefs);
    setConsent(record);
    setShowBanner(false);
    setShowSettings(false);
  }, []);

  const openSettings = useCallback(() => {
    setShowSettings(true);
    setShowBanner(false);
  }, []);

  const closeSettings = useCallback(() => {
    setShowSettings(false);
    // If consent was never given, put the banner back
    if (!readStored()) setShowBanner(true);
  }, []);

  return (
    <ConsentContext.Provider value={{
      consent,
      showBanner,
      showSettings,
      acceptAll,
      rejectNonEssential,
      saveCustom,
      openSettings,
      closeSettings,
    }}>
      {children}
    </ConsentContext.Provider>
  );
}

// eslint-disable-next-line react-refresh/only-export-components
export function useCookieConsent() {
  const ctx = useContext(ConsentContext);
  if (!ctx) throw new Error('useCookieConsent must be inside <CookieConsentProvider>');
  return ctx;
}
