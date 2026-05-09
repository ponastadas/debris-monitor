import { useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAdminAuth } from '../../contexts/AdminAuthContext';
import { downloadRecoveryCodesPdf } from '../../utils/downloadRecoveryPdf';

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

const STEP_SUBTITLES = {
  credentials: 'ADMIN ACCESS',
  mfa:         'TWO-FACTOR AUTHENTICATION',
  setup:       'SECURE YOUR ACCOUNT',
  codes:       'SAVE YOUR RECOVERY CODES',
};

export default function AdminLogin() {
  const { login, verifyMfa, setupMfaInit, setupMfaFinalize } = useAdminAuth();
  const navigate = useNavigate();

  // step: 'credentials' | 'mfa' | 'setup' | 'codes'
  const [step, setStep]           = useState('credentials');
  const [mfaToken, setMfaToken]   = useState('');
  const [setupToken, setSetupToken] = useState('');

  const [email, setEmail]         = useState('');
  const [password, setPassword]   = useState('');
  const [code, setCode]           = useState('');
  const [showRecovery, setShowRecovery] = useState(false);

  // Forced MFA setup state
  const [qrCode, setQrCode]       = useState('');
  const [secret, setSecret]       = useState('');
  const [recoveryCodes, setRecoveryCodes] = useState([]);

  const [error, setError]         = useState('');
  const [loading, setLoading]     = useState(false);
  const codeRef                   = useRef(null);

  const handleCredentials = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const data = await login(email, password);
      if (data.mfa_required) {
        setMfaToken(data.mfa_token);
        setStep('mfa');
        setTimeout(() => codeRef.current?.focus(), 50);
      } else if (data.mfa_setup_required) {
        // Admin has no MFA — must set it up before getting a session token
        setSetupToken(data.setup_token);
        const setupData = await setupMfaInit(data.setup_token);
        setQrCode(setupData.qr_code);
        setSecret(setupData.secret);
        setStep('setup');
        setTimeout(() => codeRef.current?.focus(), 50);
      } else {
        navigate('/admin', { replace: true });
      }
    } catch (err) {
      setError(err.details?.email?.[0] ?? err.message ?? 'Login failed.');
    } finally {
      setLoading(false);
    }
  };

  const handleMfa = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      await verifyMfa(mfaToken, code);
      navigate('/admin', { replace: true });
    } catch (err) {
      setError(err.message ?? 'Invalid code.');
      setCode('');
      setTimeout(() => codeRef.current?.focus(), 50);
    } finally {
      setLoading(false);
    }
  };

  const handleSetupFinalize = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const data = await setupMfaFinalize(setupToken, code);
      setRecoveryCodes(data.recovery_codes ?? []);
      setStep('codes');
    } catch (err) {
      setError(err.message ?? 'Invalid code. Check your authenticator app.');
      setCode('');
      setTimeout(() => codeRef.current?.focus(), 50);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div style={{
      minHeight: '100vh',
      background: '#0d1117',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      fontFamily: "'JetBrains Mono', monospace",
    }}>
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@700&family=JetBrains+Mono:wght@400;500&display=swap');
      `}</style>

      <div style={{
        width: step === 'setup' ? 420 : 360,
        background: '#161b22',
        border: '1px solid rgba(48,54,61,0.6)',
        borderTop: '2px solid #00d4ff',
        borderRadius: 8,
        padding: '32px 28px',
        transition: 'width 0.2s',
      }}>
        {/* Header */}
        <div style={{ textAlign: 'center', marginBottom: 28 }}>
          <div style={{
            fontFamily: "'Orbitron', sans-serif",
            fontSize: 13,
            fontWeight: 700,
            letterSpacing: '0.25em',
            color: '#00d4ff',
            marginBottom: 4,
          }}>
            ◈ SATVIEW
          </div>
          <div style={{ fontSize: 9, color: '#484f58', letterSpacing: '0.2em' }}>
            {STEP_SUBTITLES[step]}
          </div>
        </div>

        {/* ── Step 1: credentials ── */}
        {step === 'credentials' && (
          <form onSubmit={handleCredentials}>
            <div style={{ marginBottom: 16 }}>
              <label style={{ display: 'block', fontSize: 10, color: '#8b949e', textTransform: 'uppercase', letterSpacing: '0.1em', marginBottom: 6 }}>
                Email
              </label>
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                autoComplete="username"
                style={inputStyle}
              />
            </div>

            <div style={{ marginBottom: 24 }}>
              <label style={{ display: 'block', fontSize: 10, color: '#8b949e', textTransform: 'uppercase', letterSpacing: '0.1em', marginBottom: 6 }}>
                Password
              </label>
              <input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                autoComplete="current-password"
                style={inputStyle}
              />
            </div>

            <ErrorBox message={error} />
            <SubmitButton loading={loading} label="SIGN IN" />
          </form>
        )}

        {/* ── Step 2: TOTP / recovery code ── */}
        {step === 'mfa' && (
          <form onSubmit={handleMfa}>
            <p style={{ fontSize: 11, color: '#8b949e', lineHeight: 1.6, marginBottom: 20, marginTop: 0 }}>
              {showRecovery
                ? 'Enter one of your recovery codes (XXXXX-XXXXX).'
                : 'Enter the 6-digit code from your authenticator app.'}
            </p>

            <div style={{ marginBottom: 24 }}>
              <label style={{ display: 'block', fontSize: 10, color: '#8b949e', textTransform: 'uppercase', letterSpacing: '0.1em', marginBottom: 6 }}>
                {showRecovery ? 'Recovery Code' : 'Authentication Code'}
              </label>
              <input
                ref={codeRef}
                type="text"
                inputMode={showRecovery ? 'text' : 'numeric'}
                value={code}
                onChange={(e) => setCode(e.target.value)}
                required
                autoComplete="one-time-code"
                placeholder={showRecovery ? 'XXXXX-XXXXX' : '000000'}
                maxLength={showRecovery ? 11 : 6}
                style={{ ...inputStyle, letterSpacing: showRecovery ? '0.05em' : '0.3em', textAlign: 'center' }}
              />
            </div>

            <ErrorBox message={error} />
            <SubmitButton loading={loading} label="VERIFY" />

            <button
              type="button"
              onClick={() => { setShowRecovery(!showRecovery); setCode(''); setError(''); }}
              style={ghostBtnStyle('rgba(0,212,255,0.5)')}
            >
              {showRecovery ? '← Use authenticator app' : 'Use a recovery code instead'}
            </button>

            <button
              type="button"
              onClick={() => { setStep('credentials'); setCode(''); setError(''); }}
              style={ghostBtnStyle('#484f58')}
            >
              ← Back to sign in
            </button>
          </form>
        )}

        {/* ── Step 3: forced MFA setup — scan QR then verify ── */}
        {step === 'setup' && (
          <form onSubmit={handleSetupFinalize}>
            <p style={{ fontSize: 11, color: '#8b949e', lineHeight: 1.6, marginBottom: 16, marginTop: 0 }}>
              Your account requires two-factor authentication. Scan the QR code
              with your authenticator app, then enter the 6-digit code to continue.
            </p>

            {qrCode && (
              <div style={{ textAlign: 'center', marginBottom: 16 }}>
                <img
                  src={`data:image/svg+xml;base64,${qrCode}`}
                  alt="MFA QR code"
                  style={{ width: 180, height: 180, border: '1px solid rgba(0,212,255,0.2)', borderRadius: 4, background: '#fff', padding: 4 }}
                />
              </div>
            )}

            {secret && (
              <div style={{ marginBottom: 16 }}>
                <div style={{ fontSize: 9, color: '#8b949e', letterSpacing: '0.1em', textTransform: 'uppercase', marginBottom: 4 }}>
                  Manual entry key
                </div>
                <div style={{
                  background: '#010409', border: '1px solid rgba(48,54,61,0.8)',
                  borderRadius: 4, padding: '8px 10px', fontSize: 11,
                  color: '#e6edf3', letterSpacing: '0.08em', wordBreak: 'break-all',
                }}>
                  {secret}
                </div>
              </div>
            )}

            <div style={{ marginBottom: 24 }}>
              <label style={{ display: 'block', fontSize: 10, color: '#8b949e', textTransform: 'uppercase', letterSpacing: '0.1em', marginBottom: 6 }}>
                Verification Code
              </label>
              <input
                ref={codeRef}
                type="text"
                inputMode="numeric"
                value={code}
                onChange={(e) => setCode(e.target.value)}
                required
                autoComplete="one-time-code"
                placeholder="000000"
                maxLength={6}
                style={{ ...inputStyle, letterSpacing: '0.3em', textAlign: 'center' }}
              />
            </div>

            <ErrorBox message={error} />
            <SubmitButton loading={loading} label="ENABLE & CONTINUE" />

            <button
              type="button"
              onClick={() => { setStep('credentials'); setCode(''); setError(''); }}
              style={ghostBtnStyle('#484f58')}
            >
              ← Back to sign in
            </button>
          </form>
        )}

        {/* ── Step 4: show recovery codes once ── */}
        {step === 'codes' && (
          <div>
            <p style={{ fontSize: 11, color: '#d29922', lineHeight: 1.6, marginBottom: 16, marginTop: 0 }}>
              Save these recovery codes now — they will not be shown again. Each code
              can be used once if you lose access to your authenticator app.
            </p>

            <div style={{
              background: '#010409', border: '1px solid rgba(210,153,34,0.3)',
              borderRadius: 4, padding: '12px 14px', marginBottom: 20,
              display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '6px 12px',
            }}>
              {recoveryCodes.map((c) => (
                <span key={c} style={{ fontSize: 12, color: '#e6edf3', letterSpacing: '0.08em', fontFamily: "'JetBrains Mono', monospace" }}>
                  {c}
                </span>
              ))}
            </div>

            <button
              type="button"
              onClick={() => downloadRecoveryCodesPdf(recoveryCodes)}
              style={{
                width: '100%',
                background: 'rgba(210,153,34,0.1)',
                border: '1px solid rgba(210,153,34,0.4)',
                borderRadius: 4,
                color: '#d29922',
                fontFamily: "'JetBrains Mono', monospace",
                fontSize: 11,
                letterSpacing: '0.1em',
                padding: '10px',
                cursor: 'pointer',
                marginBottom: 8,
              }}
            >
              ↓ Download as PDF
            </button>

            <button
              type="button"
              onClick={() => navigate('/admin', { replace: true })}
              style={{
                width: '100%',
                background: 'rgba(0,212,255,0.12)',
                border: '1px solid rgba(0,212,255,0.4)',
                borderRadius: 4,
                color: '#00d4ff',
                fontFamily: "'Orbitron', sans-serif",
                fontSize: 11,
                fontWeight: 700,
                letterSpacing: '0.15em',
                padding: '12px',
                cursor: 'pointer',
                textTransform: 'uppercase',
              }}
            >
              I'VE SAVED THEM — CONTINUE
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

function ghostBtnStyle(color) {
  return {
    width: '100%',
    background: 'none',
    border: 'none',
    color,
    fontFamily: "'JetBrains Mono', monospace",
    fontSize: 10,
    letterSpacing: '0.1em',
    cursor: 'pointer',
    marginTop: 8,
    padding: '4px 0',
  };
}

function ErrorBox({ message }) {
  if (!message) return null;
  return (
    <div style={{
      background: 'rgba(248,81,73,0.1)',
      border: '1px solid rgba(248,81,73,0.3)',
      borderRadius: 4,
      color: '#f85149',
      fontSize: 12,
      padding: '10px 12px',
      marginBottom: 16,
    }}>
      {message}
    </div>
  );
}

function SubmitButton({ loading, label }) {
  return (
    <button
      type="submit"
      disabled={loading}
      style={{
        width: '100%',
        background: loading ? 'rgba(0,212,255,0.05)' : 'rgba(0,212,255,0.12)',
        border: '1px solid rgba(0,212,255,0.4)',
        borderRadius: 4,
        color: '#00d4ff',
        fontFamily: "'Orbitron', sans-serif",
        fontSize: 11,
        fontWeight: 700,
        letterSpacing: '0.15em',
        padding: '12px',
        cursor: loading ? 'not-allowed' : 'pointer',
        textTransform: 'uppercase',
      }}
    >
      {loading ? '...' : label}
    </button>
  );
}
