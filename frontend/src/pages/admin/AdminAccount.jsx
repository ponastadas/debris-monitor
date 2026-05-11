import { useState } from 'react';
import adminClient from '../../api/adminClient';
import { useAdminAuth } from '../../contexts/AdminAuthContext';
import { downloadRecoveryCodesPdf } from '../../utils/downloadRecoveryPdf';

// ── Shared styles ─────────────────────────────────────────────────────────────

const card = {
  background: '#161b22',
  border: '1px solid rgba(48,54,61,0.6)',
  borderRadius: 6,
  padding: '24px 28px',
  marginBottom: 20,
};

const label = {
  display: 'block',
  fontSize: 10,
  color: '#8b949e',
  textTransform: 'uppercase',
  letterSpacing: '0.1em',
  marginBottom: 6,
};

const inputStyle = {
  width: '100%',
  background: '#010409',
  border: '1px solid rgba(48,54,61,0.8)',
  borderRadius: 4,
  color: '#e6edf3',
  fontFamily: "'JetBrains Mono', monospace",
  fontSize: 13,
  padding: '10px 12px',
  outline: 'none',
  boxSizing: 'border-box',
};

const btn = (variant = 'primary') => ({
  background: variant === 'danger'
    ? 'rgba(248,81,73,0.12)'
    : variant === 'ghost'
      ? 'none'
      : 'rgba(0,212,255,0.12)',
  border: variant === 'danger'
    ? '1px solid rgba(248,81,73,0.4)'
    : variant === 'ghost'
      ? 'none'
      : '1px solid rgba(0,212,255,0.4)',
  borderRadius: 4,
  color: variant === 'danger' ? '#f85149' : variant === 'ghost' ? '#8b949e' : '#00d4ff',
  fontFamily: "'JetBrains Mono', monospace",
  fontSize: 11,
  letterSpacing: '0.1em',
  padding: '9px 18px',
  cursor: 'pointer',
});

function StatusBadge({ enabled }) {
  return (
    <span style={{
      display: 'inline-block',
      padding: '3px 10px',
      borderRadius: 3,
      fontSize: 10,
      letterSpacing: '0.1em',
      fontWeight: 600,
      background: enabled ? 'rgba(63,185,80,0.15)' : 'rgba(139,148,158,0.15)',
      color: enabled ? '#3fb950' : '#8b949e',
      border: `1px solid ${enabled ? 'rgba(63,185,80,0.3)' : 'rgba(139,148,158,0.3)'}`,
    }}>
      {enabled ? 'ENABLED' : 'NOT CONFIGURED'}
    </span>
  );
}

function Alert({ type, children }) {
  const colors = {
    error:   { bg: 'rgba(248,81,73,0.1)',  border: 'rgba(248,81,73,0.3)',  color: '#f85149' },
    success: { bg: 'rgba(63,185,80,0.1)',  border: 'rgba(63,185,80,0.3)', color: '#3fb950' },
    info:    { bg: 'rgba(0,212,255,0.08)', border: 'rgba(0,212,255,0.25)', color: '#00d4ff' },
  };
  const c = colors[type] ?? colors.info;
  return (
    <div style={{
      background: c.bg, border: `1px solid ${c.border}`, borderRadius: 4,
      color: c.color, fontSize: 12, padding: '10px 14px', marginBottom: 16, lineHeight: 1.5,
    }}>
      {children}
    </div>
  );
}

// ── Setup flow ─────────────────────────────────────────────────────────────────

function MfaSetup({ onComplete, onCancel }) {
  const [phase, setPhase]         = useState('loading'); // loading | qr | confirm | done
  const [qrCode, setQrCode]       = useState('');
  const [secret, setSecret]       = useState('');
  const [code, setCode]           = useState('');
  const [recoveryCodes, setRecoveryCodes] = useState([]);
  const [error, setError]         = useState('');
  const [loading, setLoading]     = useState(false);
  const [copied, setCopied]       = useState(false);

  // Fetch QR on mount
  useState(() => {
    adminClient.get('/admin/auth/mfa/setup')
      .then((res) => {
        setQrCode(res.data.data.qr_code);
        setSecret(res.data.data.secret);
        setPhase('qr');
      })
      .catch(() => setError('Failed to start MFA setup.'));
  });

  const handleConfirm = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const res = await adminClient.post('/admin/auth/mfa/confirm', { code });
      setRecoveryCodes(res.data.data.recovery_codes);
      setPhase('done');
    } catch (err) {
      setError(err.message ?? 'Invalid code.');
      setCode('');
    } finally {
      setLoading(false);
    }
  };

  const copyRecoveryCodes = () => {
    navigator.clipboard.writeText(recoveryCodes.join('\n'));
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  if (phase === 'loading') {
    return <p style={{ color: '#8b949e', fontSize: 13 }}>Generating…</p>;
  }

  if (phase === 'done') {
    return (
      <div>
        <Alert type="success">
          MFA enabled. Save these recovery codes somewhere safe — they will not be shown again.
        </Alert>
        <div style={{
          background: '#010409',
          border: '1px solid rgba(48,54,61,0.8)',
          borderRadius: 4,
          padding: '14px 16px',
          marginBottom: 16,
          display: 'grid',
          gridTemplateColumns: '1fr 1fr',
          gap: '6px 24px',
        }}>
          {recoveryCodes.map((c) => (
            <span key={c} style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 13, color: '#e6edf3', letterSpacing: '0.05em' }}>
              {c}
            </span>
          ))}
        </div>
        <div style={{ display: 'flex', gap: 10 }}>
          <button onClick={copyRecoveryCodes} style={btn('ghost')}>
            {copied ? '✓ Copied' : 'Copy codes'}
          </button>
          <button onClick={() => downloadRecoveryCodesPdf(recoveryCodes)} style={btn('ghost')}>
            ↓ Download PDF
          </button>
          <button onClick={onComplete} style={btn('primary')}>Done</button>
        </div>
      </div>
    );
  }

  if (phase === 'qr') {
    return (
      <div>
        <p style={{ fontSize: 12, color: '#8b949e', marginTop: 0, lineHeight: 1.6 }}>
          Scan the QR code with your authenticator app (Google Authenticator, Authy, 1Password, etc.), then enter the 6-digit code to confirm.
        </p>

        {qrCode && (
          <div style={{ textAlign: 'center', marginBottom: 20 }}>
            <img
              src={`data:image/svg+xml;base64,${qrCode}`}
              alt="MFA QR Code"
              style={{ width: 180, height: 180, background: '#fff', borderRadius: 4, padding: 8 }}
            />
          </div>
        )}

        <details style={{ marginBottom: 16 }}>
          <summary style={{ fontSize: 11, color: '#8b949e', cursor: 'pointer', letterSpacing: '0.05em' }}>
            Can't scan? Enter manually
          </summary>
          <div style={{
            background: '#010409', border: '1px solid rgba(48,54,61,0.8)',
            borderRadius: 4, padding: '10px 12px', marginTop: 8,
            fontFamily: "'JetBrains Mono', monospace", fontSize: 12, color: '#e6edf3',
            letterSpacing: '0.08em', wordBreak: 'break-all',
          }}>
            {secret}
          </div>
        </details>

        {error && <Alert type="error">{error}</Alert>}

        <form onSubmit={(e) => { setPhase('confirm'); e.preventDefault(); }}>
          <button type="submit" style={btn()}>Next →</button>
          <button type="button" onClick={onCancel} style={{ ...btn('ghost'), marginLeft: 10 }}>Cancel</button>
        </form>
      </div>
    );
  }

  // phase === 'confirm'
  return (
    <form onSubmit={handleConfirm}>
      <p style={{ fontSize: 12, color: '#8b949e', marginTop: 0, lineHeight: 1.6 }}>
        Enter the 6-digit code from your authenticator app to complete setup.
      </p>

      <div style={{ marginBottom: 20 }}>
        <label style={label}>Authentication Code</label>
        <input
          type="text"
          inputMode="numeric"
          value={code}
          onChange={(e) => setCode(e.target.value)}
          required
          maxLength={6}
          placeholder="000000"
          autoFocus
          style={{ ...inputStyle, textAlign: 'center', letterSpacing: '0.3em' }}
        />
      </div>

      {error && <Alert type="error">{error}</Alert>}

      <div style={{ display: 'flex', gap: 10 }}>
        <button type="submit" disabled={loading} style={btn()}>
          {loading ? '…' : 'Enable MFA'}
        </button>
        <button type="button" onClick={() => setPhase('qr')} style={btn('ghost')}>← Back</button>
      </div>
    </form>
  );
}

// ── Disable flow ──────────────────────────────────────────────────────────────

function MfaDisable({ onComplete, onCancel }) {
  const [password, setPassword] = useState('');
  const [error, setError]       = useState('');
  const [loading, setLoading]   = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      await adminClient.delete('/admin/auth/mfa', { data: { password } });
      onComplete();
    } catch (err) {
      setError(err.message ?? 'Failed to disable MFA.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <Alert type="info">
        Disabling MFA reduces account security. You will need your password to confirm.
      </Alert>

      <div style={{ marginBottom: 20 }}>
        <label style={label}>Current Password</label>
        <input
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          required
          autoFocus
          autoComplete="current-password"
          style={inputStyle}
        />
      </div>

      {error && <Alert type="error">{error}</Alert>}

      <div style={{ display: 'flex', gap: 10 }}>
        <button type="submit" disabled={loading} style={btn('danger')}>
          {loading ? '…' : 'Disable MFA'}
        </button>
        <button type="button" onClick={onCancel} style={btn('ghost')}>Cancel</button>
      </div>
    </form>
  );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function AdminAccount() {
  const { admin, logout } = useAdminAuth();

  // 'idle' | 'setup' | 'disable'
  const [action, setAction] = useState('idle');
  // Track mfa_enabled locally so UI updates without a full page refresh
  const [mfaEnabled, setMfaEnabled] = useState(admin?.mfa_enabled ?? false);

  return (
    <div style={{ maxWidth: 600 }}>
      <h1 style={{
        fontFamily: "'Orbitron', sans-serif",
        fontSize: 16,
        fontWeight: 700,
        letterSpacing: '0.2em',
        color: '#e6edf3',
        marginBottom: 24,
        marginTop: 0,
      }}>
        ACCOUNT
      </h1>

      {/* ── Profile card ── */}
      <div style={card}>
        <h2 style={{ fontSize: 11, color: '#8b949e', letterSpacing: '0.15em', textTransform: 'uppercase', marginTop: 0, marginBottom: 16 }}>
          Profile
        </h2>
        <div style={{ display: 'grid', gridTemplateColumns: '120px 1fr', rowGap: 10, fontSize: 13 }}>
          <span style={{ color: '#8b949e' }}>Name</span>
          <span style={{ color: '#e6edf3' }}>{admin?.name ?? '—'}</span>
          <span style={{ color: '#8b949e' }}>Email</span>
          <span style={{ color: '#e6edf3' }}>{admin?.email ?? '—'}</span>
          <span style={{ color: '#8b949e' }}>Last login</span>
          <span style={{ color: '#e6edf3' }}>
            {admin?.last_login_at
              ? new Date(admin.last_login_at).toLocaleString()
              : '—'}
          </span>
        </div>
      </div>

      {/* ── MFA card ── */}
      <div style={card}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
          <h2 style={{ fontSize: 11, color: '#8b949e', letterSpacing: '0.15em', textTransform: 'uppercase', margin: 0 }}>
            Two-Factor Authentication
          </h2>
          <StatusBadge enabled={mfaEnabled} />
        </div>

        {action === 'idle' && (
          <>
            <p style={{ fontSize: 12, color: '#8b949e', lineHeight: 1.6, marginTop: 0, marginBottom: 16 }}>
              {mfaEnabled
                ? 'Your account is protected with TOTP-based two-factor authentication.'
                : 'Add an extra layer of security by requiring a time-based code on login.'}
            </p>
            {mfaEnabled ? (
              <button onClick={() => setAction('disable')} style={btn('danger')}>
                Disable MFA
              </button>
            ) : (
              <button onClick={() => setAction('setup')} style={btn()}>
                Set up MFA
              </button>
            )}
          </>
        )}

        {action === 'setup' && (
          <MfaSetup
            onComplete={() => { setMfaEnabled(true); setAction('idle'); }}
            onCancel={() => setAction('idle')}
          />
        )}

        {action === 'disable' && (
          <MfaDisable
            onComplete={() => { setMfaEnabled(false); setAction('idle'); }}
            onCancel={() => setAction('idle')}
          />
        )}
      </div>

      {/* ── Danger zone ── */}
      <div style={{ ...card, borderColor: 'rgba(248,81,73,0.2)' }}>
        <h2 style={{ fontSize: 11, color: '#f85149', letterSpacing: '0.15em', textTransform: 'uppercase', marginTop: 0, marginBottom: 16 }}>
          Session
        </h2>
        <p style={{ fontSize: 12, color: '#8b949e', lineHeight: 1.6, marginTop: 0, marginBottom: 16 }}>
          Sign out of the admin panel. All other active sessions will remain valid.
        </p>
        <button onClick={logout} style={btn('danger')}>Sign Out</button>
      </div>
    </div>
  );
}
