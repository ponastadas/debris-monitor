import { Link } from 'react-router-dom';
import { useCookieConsent } from '../contexts/CookieConsentContext';

const LEGAL_LINKS = [
  { to: '/pages/privacy-policy', label: 'Privacy' },
  { to: '/pages/cookie-policy',  label: 'Cookies' },
  { to: '/pages/terms',          label: 'Terms' },
  { to: '/pages/about',          label: 'About' },
  { to: '/pages/contact',        label: 'Contact' },
];

export default function Footer() {
  const { openSettings } = useCookieConsent();

  return (
    <footer style={{
      borderTop: '1px solid rgba(48,54,61,0.5)',
      padding: '12px 24px',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      gap: 12,
      flexWrap: 'wrap',
      fontFamily: "'JetBrains Mono', monospace",
      fontSize: 10,
      color: '#484f58',
      background: '#010409',
    }}>
      <span style={{ letterSpacing: '0.08em' }}>
        © {new Date().getFullYear()} Debris Monitor
      </span>

      <nav style={{ display: 'flex', gap: 16, flexWrap: 'wrap', alignItems: 'center' }}>
        {LEGAL_LINKS.map(({ to, label }) => (
          <Link
            key={to}
            to={to}
            style={{ color: '#484f58', textDecoration: 'none', letterSpacing: '0.08em' }}
            onMouseOver={(e) => (e.currentTarget.style.color = '#8b949e')}
            onMouseOut={(e) => (e.currentTarget.style.color = '#484f58')}
          >
            {label}
          </Link>
        ))}
        <button
          onClick={openSettings}
          style={{
            background: 'none', border: 'none', color: '#484f58',
            fontFamily: "'JetBrains Mono', monospace", fontSize: 10,
            letterSpacing: '0.08em', cursor: 'pointer', padding: 0,
          }}
          onMouseOver={(e) => (e.currentTarget.style.color = '#8b949e')}
          onMouseOut={(e) => (e.currentTarget.style.color = '#484f58')}
        >
          Cookie Settings
        </button>
      </nav>
    </footer>
  );
}
