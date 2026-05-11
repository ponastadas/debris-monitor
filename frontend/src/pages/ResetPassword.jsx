import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import AuthLayout from '../layouts/AuthLayout';
import { useToast } from '../contexts/ToastContext';
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

export default function ResetPassword() {
  const [searchParams]          = useSearchParams();
  const toast                   = useToast();
  const navigate                = useNavigate();
  const [form, setForm]         = useState({ password: '', password_confirmation: '' });
  const [errors, setErrors]     = useState({});
  const [loading, setLoading]   = useState(false);

  const token = searchParams.get('token') ?? '';
  const email = searchParams.get('email') ?? '';

  const set = (field) => (e) => setForm((f) => ({ ...f, [field]: e.target.value }));

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrors({});
    setLoading(true);
    try {
      await client.post('/auth/reset-password', {
        token,
        email,
        password: form.password,
        password_confirmation: form.password_confirmation,
      });
      toast.success('Password reset successfully. Please sign in.');
      navigate('/login', { replace: true });
    } catch (err) {
      if (err.type === 'VALIDATION') {
        setErrors(err.details ?? {});
      } else {
        toast.error(err.message ?? 'Reset failed. The link may have expired.');
      }
    } finally {
      setLoading(false);
    }
  };

  if (!token || !email) {
    return (
      <AuthLayout title="Invalid Link">
        <p style={{ color: '#f85149', fontSize: 13, textAlign: 'center' }}>
          This reset link is invalid or has expired.
        </p>
        <p style={{ textAlign: 'center', marginTop: 16 }}>
          <Link to="/forgot-password" style={{ color: '#00d4ff', fontSize: 12, textDecoration: 'none' }}>
            Request a new link
          </Link>
        </p>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout title="New Password" subtitle={`Resetting password for ${email}`}>
      <form onSubmit={handleSubmit} noValidate>
        <div style={{ marginBottom: 16 }}>
          <label style={{
            display: 'block', fontSize: 11, letterSpacing: '0.1em',
            color: '#8b949e', textTransform: 'uppercase', marginBottom: 6,
          }}>
            New Password
          </label>
          <input
            type="password"
            value={form.password}
            onChange={set('password')}
            placeholder="Min 8 chars, letters + numbers"
            autoComplete="new-password"
            style={{ ...inputStyle, borderColor: errors.password ? '#f85149' : undefined }}
            onFocus={(e) => (e.target.style.borderColor = '#00d4ff')}
            onBlur={(e) => (e.target.style.borderColor = errors.password ? '#f85149' : 'rgba(48,54,61,0.8)')}
          />
          {errors.password && <p style={{ color: '#f85149', fontSize: 11, marginTop: 4 }}>{errors.password[0]}</p>}
        </div>

        <div style={{ marginBottom: 24 }}>
          <label style={{
            display: 'block', fontSize: 11, letterSpacing: '0.1em',
            color: '#8b949e', textTransform: 'uppercase', marginBottom: 6,
          }}>
            Confirm Password
          </label>
          <input
            type="password"
            value={form.password_confirmation}
            onChange={set('password_confirmation')}
            placeholder="••••••••"
            autoComplete="new-password"
            style={{ ...inputStyle }}
            onFocus={(e) => (e.target.style.borderColor = '#00d4ff')}
            onBlur={(e) => (e.target.style.borderColor = 'rgba(48,54,61,0.8)')}
          />
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
          {loading ? 'RESETTING...' : 'RESET PASSWORD'}
        </button>
      </form>
    </AuthLayout>
  );
}
