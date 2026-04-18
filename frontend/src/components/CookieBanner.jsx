import { useState } from 'react';
import { useCookieConsent } from '../contexts/CookieConsentContext';

const CATEGORIES = [
  {
    key: 'necessary',
    label: 'Strictly Necessary',
    description: 'Required for authentication, session management, and guest rate limiting. Cannot be disabled.',
    locked: true,
  },
  {
    key: 'analytics',
    label: 'Analytics',
    description: 'Google Analytics 4 — helps us understand how the site is used. No personally identifiable information is shared.',
    locked: false,
  },
  {
    key: 'marketing',
    label: 'Marketing',
    description: 'Not currently used. Reserved for future advertising or remarketing features.',
    locked: false,
  },
];

function SettingsModal({ onClose }) {
  const { consent, saveCustom } = useCookieConsent();
  const [prefs, setPrefs] = useState({
    analytics: consent?.analytics ?? false,
    marketing: consent?.marketing ?? false,
  });

  return (
    <div style={{
      position: 'fixed', inset: 0, background: 'rgba(1,4,9,0.88)',
      display: 'flex', alignItems: 'center', justifyContent: 'center',
      zIndex: 10000, fontFamily: "'JetBrains Mono', monospace",
    }}>
      <div style={{
        background: '#161b22', border: '1px solid rgba(0,212,255,0.2)',
        borderTop: '2px solid #00d4ff', borderRadius: 8,
        padding: '28px 28px 24px', width: 440, maxWidth: '92vw',
      }}>
        <h2 style={{ fontFamily: "'Orbitron', sans-serif", fontSize: 13, fontWeight: 700, color: '#e6edf3', marginBottom: 4, marginTop: 0, letterSpacing: '0.1em' }}>
          Cookie Preferences
        </h2>
        <p style={{ fontSize: 11, color: '#8b949e', lineHeight: 1.6, marginBottom: 20 }}>
          Choose which cookie categories you allow. Strictly necessary cookies cannot be disabled.
        </p>

        {CATEGORIES.map((cat) => (
          <div key={cat.key} style={{
            display: 'flex', gap: 14, padding: '14px 0',
            borderBottom: '1px solid rgba(48,54,61,0.5)',
          }}>
            <div style={{ flexShrink: 0, paddingTop: 2 }}>
              <label style={{ display: 'flex', alignItems: 'center', cursor: cat.locked ? 'not-allowed' : 'pointer' }}>
                <input
                  type="checkbox"
                  checked={cat.locked ? true : prefs[cat.key]}
                  disabled={cat.locked}
                  onChange={(e) => setPrefs((p) => ({ ...p, [cat.key]: e.target.checked }))}
                  style={{ accentColor: '#00d4ff', width: 16, height: 16 }}
                />
              </label>
            </div>
            <div>
              <div style={{ fontSize: 11, fontWeight: 700, color: cat.locked ? '#8b949e' : '#e6edf3', marginBottom: 4, letterSpacing: '0.04em' }}>
                {cat.label}
                {cat.locked && <span style={{ marginLeft: 8, fontSize: 9, color: '#484f58', letterSpacing: '0.12em' }}>ALWAYS ON</span>}
              </div>
              <div style={{ fontSize: 11, color: '#484f58', lineHeight: 1.5 }}>{cat.description}</div>
            </div>
          </div>
        ))}

        <div style={{ display: 'flex', gap: 8, marginTop: 20 }}>
          <button
            onClick={() => saveCustom(prefs)}
            style={{
              flex: 1, background: 'rgba(0,212,255,0.12)', border: '1px solid rgba(0,212,255,0.4)',
              borderRadius: 4, color: '#00d4ff', fontFamily: "'Orbitron', sans-serif",
              fontSize: 10, fontWeight: 700, letterSpacing: '0.1em', padding: '10px',
              cursor: 'pointer', textTransform: 'uppercase',
            }}
          >
            Save Preferences
          </button>
          <button
            onClick={onClose}
            style={{
              background: 'rgba(48,54,61,0.4)', border: '1px solid rgba(48,54,61,0.8)',
              borderRadius: 4, color: '#8b949e', fontFamily: "'JetBrains Mono', monospace",
              fontSize: 11, padding: '10px 14px', cursor: 'pointer',
            }}
          >
            Cancel
          </button>
        </div>
      </div>
    </div>
  );
}

export default function CookieBanner() {
  const { showBanner, showSettings, acceptAll, rejectNonEssential, openSettings, closeSettings } = useCookieConsent();

  if (!showBanner && !showSettings) return null;

  if (showSettings) return <SettingsModal onClose={closeSettings} />;

  return (
    <div style={{
      position: 'fixed', bottom: 0, left: 0, right: 0,
      background: '#161b22', borderTop: '1px solid rgba(0,212,255,0.2)',
      padding: '16px 24px', zIndex: 9999,
      fontFamily: "'JetBrains Mono', monospace",
      display: 'flex', alignItems: 'center', gap: 20, flexWrap: 'wrap',
    }}>
      <div style={{ flex: 1, minWidth: 260 }}>
        <span style={{ fontSize: 9, fontFamily: "'Orbitron', sans-serif", color: '#00d4ff', letterSpacing: '0.2em', display: 'block', marginBottom: 4 }}>
          COOKIE NOTICE
        </span>
        <span style={{ fontSize: 11, color: '#8b949e', lineHeight: 1.5 }}>
          We use strictly necessary cookies to operate the service, and optional analytics cookies (with your consent) to improve it.{' '}
          <a href="/pages/cookie-policy" style={{ color: 'rgba(0,212,255,0.6)', textDecoration: 'none' }}>Cookie Policy</a>
        </span>
      </div>

      <div style={{ display: 'flex', gap: 8, flexShrink: 0, flexWrap: 'wrap' }}>
        <button
          onClick={rejectNonEssential}
          style={{
            background: 'rgba(48,54,61,0.4)', border: '1px solid rgba(48,54,61,0.8)',
            borderRadius: 4, color: '#8b949e', fontFamily: "'JetBrains Mono', monospace",
            fontSize: 10, letterSpacing: '0.08em', padding: '8px 14px', cursor: 'pointer',
          }}
        >
          Reject Non-Essential
        </button>
        <button
          onClick={openSettings}
          style={{
            background: 'rgba(48,54,61,0.4)', border: '1px solid rgba(0,212,255,0.25)',
            borderRadius: 4, color: 'rgba(0,212,255,0.7)', fontFamily: "'JetBrains Mono', monospace",
            fontSize: 10, letterSpacing: '0.08em', padding: '8px 14px', cursor: 'pointer',
          }}
        >
          Customize
        </button>
        <button
          onClick={acceptAll}
          style={{
            background: 'rgba(0,212,255,0.12)', border: '1px solid rgba(0,212,255,0.4)',
            borderRadius: 4, color: '#00d4ff', fontFamily: "'Orbitron', sans-serif",
            fontSize: 10, fontWeight: 700, letterSpacing: '0.1em', padding: '8px 14px',
            cursor: 'pointer', textTransform: 'uppercase',
          }}
        >
          Accept All
        </button>
      </div>
    </div>
  );
}
