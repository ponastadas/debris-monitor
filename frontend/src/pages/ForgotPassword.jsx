import { useState } from 'react';
import { Link } from 'react-router-dom';
import AuthLayout from '../layouts/AuthLayout';
import client from '../api/client';

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

export default function ForgotPassword() {
  const [email, setEmail]       = useState('');
  const [loading, setLoading]   = useState(false);
  const [sent, setSent]         = useState(false);
  const [error, setError]       = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      await client.post('/auth/forgot-password', { email });
      setSent(true);
    } catch (err) {
      if (err.type === 'VALIDATION') {
        setError(err.details?.email?.[0] ?? 'Invalid email.');
      } else {
        setError(err.message ?? 'Something went wrong.');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <AuthLayout title="Reset Password" subtitle="We'll send a reset link to your email">
      {sent ? (
        <div style={{ textAlign: 'center' }}>
          <div style={{ fontSize: 32, marginBottom: 12 }}>✓</div>
          <p style={{ color: '#3fb950', fontSize: 13, marginBottom: 20 }}>
            If that email is registered, a reset link has been sent.
          </p>
          <Link to="/login" style={{ color: '#00d4ff', fontSize: 12, textDecoration: 'none' }}>
            ← Back to sign in
          </Link>
        </div>
      ) : (
        <form onSubmit={handleSubmit} noValidate>
          <div style={{ marginBottom: 20 }}>
            <label style={{
              display: 'block', fontSize: 11, letterSpacing: '0.1em',
              color: '#8b949e', textTransform: 'uppercase', marginBottom: 6,
            }}>
              Email
            </label>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="you@example.com"
              autoComplete="email"
              required
              style={{ ...inputStyle, borderColor: error ? '#f85149' : undefined }}
              onFocus={(e) => (e.target.style.borderColor = '#00d4ff')}
              onBlur={(e) => (e.target.style.borderColor = error ? '#f85149' : 'rgba(48,54,61,0.8)')}
            />
            {error && <p style={{ color: '#f85149', fontSize: 11, marginTop: 4 }}>{error}</p>}
          </div>

          <button
            type="submit"
            disabled={loading}
            style={{
              width: '100%',
              background: 'rgba(0,212,255,0.15)',
              border: '1px solid rgba(0,212,255,0.4)',
              borderRadius: 4,
              color: '#00d4ff',
              fontFamily: "'Orbitron', sans-serif",
              fontSize: 12,
              fontWeight: 700,
              letterSpacing: '0.1em',
              padding: '12px',
              cursor: loading ? 'not-allowed' : 'pointer',
              textTransform: 'uppercase',
            }}
          >
            {loading ? 'SENDING...' : 'SEND RESET LINK'}
          </button>

          <p style={{ textAlign: 'center', marginTop: 20, color: '#8b949e', fontSize: 12 }}>
            <Link to="/login" style={{ color: '#00d4ff', textDecoration: 'none' }}>← Back to sign in</Link>
          </p>
        </form>
      )}
    </AuthLayout>
  );
}
