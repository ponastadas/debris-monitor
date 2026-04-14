import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AuthLayout from '../layouts/AuthLayout';
import { useAuth } from '../contexts/AuthContext';
import { useToast } from '../contexts/ToastContext';

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
  transition: 'border-color 0.15s',
};

const labelStyle = {
  display: 'block',
  fontSize: 11,
  letterSpacing: '0.1em',
  color: '#8b949e',
  textTransform: 'uppercase',
  marginBottom: 6,
};

export default function Login() {
  const { login }    = useAuth();
  const toast        = useToast();
  const navigate     = useNavigate();
  const [form, setForm]     = useState({ email: '', password: '' });
  const [errors, setErrors] = useState({});
  const [loading, setLoading] = useState(false);

  const set = (field) => (e) => setForm((f) => ({ ...f, [field]: e.target.value }));

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrors({});
    setLoading(true);
    try {
      const user = await login(form.email, form.password);
      navigate('/', { replace: true });
    } catch (err) {
      if (err.type === 'VALIDATION') {
        setErrors(err.details ?? {});
      } else if (err.type === 'FORBIDDEN' && err.code === 'USER_SUSPENDED') {
        toast.error('Your account has been suspended. Please contact support.');
      } else {
        toast.error(err.message ?? 'Login failed. Please try again.');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <AuthLayout title="Sign In" subtitle="Access your orbital monitoring dashboard">
      <form onSubmit={handleSubmit} noValidate>
        <div style={{ marginBottom: 18 }}>
          <label style={labelStyle}>Email</label>
          <input
            type="email"
            value={form.email}
            onChange={set('email')}
            placeholder="you@example.com"
            autoComplete="email"
            required
            style={{
              ...inputStyle,
              borderColor: errors.email ? '#f85149' : undefined,
            }}
            onFocus={(e) => (e.target.style.borderColor = '#00d4ff')}
            onBlur={(e) => (e.target.style.borderColor = errors.email ? '#f85149' : 'rgba(48,54,61,0.8)')}
          />
          {errors.email && (
            <p style={{ color: '#f85149', fontSize: 11, marginTop: 4 }}>{errors.email[0]}</p>
          )}
        </div>

        <div style={{ marginBottom: 24 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
            <label style={{ ...labelStyle, margin: 0 }}>Password</label>
            <Link to="/forgot-password" style={{ color: '#00d4ff', fontSize: 11, textDecoration: 'none' }}>
              Forgot password?
            </Link>
          </div>
          <input
            type="password"
            value={form.password}
            onChange={set('password')}
            placeholder="••••••••"
            autoComplete="current-password"
            required
            style={{
              ...inputStyle,
              borderColor: errors.password ? '#f85149' : undefined,
            }}
            onFocus={(e) => (e.target.style.borderColor = '#00d4ff')}
            onBlur={(e) => (e.target.style.borderColor = errors.password ? '#f85149' : 'rgba(48,54,61,0.8)')}
          />
          {errors.password && (
            <p style={{ color: '#f85149', fontSize: 11, marginTop: 4 }}>{errors.password[0]}</p>
          )}
        </div>

        <button
          type="submit"
          disabled={loading}
          style={{
            width: '100%',
            background: loading ? 'rgba(0,212,255,0.1)' : 'rgba(0,212,255,0.15)',
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
            transition: 'all 0.15s',
          }}
        >
          {loading ? 'AUTHENTICATING...' : 'SIGN IN'}
        </button>

        <p style={{ textAlign: 'center', marginTop: 20, color: '#8b949e', fontSize: 12 }}>
          No account?{' '}
          <Link to="/register" style={{ color: '#00d4ff', textDecoration: 'none' }}>
            Create one
          </Link>
        </p>
      </form>
    </AuthLayout>
  );
}
