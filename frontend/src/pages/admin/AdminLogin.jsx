import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAdminAuth } from '../../contexts/AdminAuthContext';

export default function AdminLogin() {
  const { login }               = useAdminAuth();
  const navigate                = useNavigate();
  const [email, setEmail]       = useState('');
  const [password, setPassword] = useState('');
  const [error, setError]       = useState('');
  const [loading, setLoading]   = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      await login(email, password);
      navigate('/admin', { replace: true });
    } catch (err) {
      setError(err.details?.email?.[0] ?? err.message ?? 'Login failed.');
    } finally {
      setLoading(false);
    }
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
        width: 360,
        background: '#161b22',
        border: '1px solid rgba(48,54,61,0.6)',
        borderTop: '2px solid #00d4ff',
        borderRadius: 8,
        padding: '32px 28px',
      }}>
        <div style={{ textAlign: 'center', marginBottom: 28 }}>
          <div style={{
            fontFamily: "'Orbitron', sans-serif",
            fontSize: 13,
            fontWeight: 700,
            letterSpacing: '0.25em',
            color: '#00d4ff',
            marginBottom: 4,
          }}>
            ◈ DEBRIS MONITOR
          </div>
          <div style={{ fontSize: 9, color: '#484f58', letterSpacing: '0.2em' }}>
            ADMIN ACCESS
          </div>
        </div>

        <form onSubmit={handleSubmit}>
          <div style={{ marginBottom: 16 }}>
            <label style={{
              display: 'block', fontSize: 10, color: '#8b949e',
              textTransform: 'uppercase', letterSpacing: '0.1em', marginBottom: 6,
            }}>
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
            <label style={{
              display: 'block', fontSize: 10, color: '#8b949e',
              textTransform: 'uppercase', letterSpacing: '0.1em', marginBottom: 6,
            }}>
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

          {error && (
            <div style={{
              background: 'rgba(248,81,73,0.1)',
              border: '1px solid rgba(248,81,73,0.3)',
              borderRadius: 4,
              color: '#f85149',
              fontSize: 12,
              padding: '10px 12px',
              marginBottom: 16,
            }}>
              {error}
            </div>
          )}

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
            {loading ? '...' : 'SIGN IN'}
          </button>
        </form>
      </div>
    </div>
  );
}
