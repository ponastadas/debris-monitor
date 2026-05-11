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
};

const labelStyle = {
  display: 'block',
  fontSize: 11,
  letterSpacing: '0.1em',
  color: '#8b949e',
  textTransform: 'uppercase',
  marginBottom: 6,
};

function Field({ label, error, children }) {
  return (
    <div style={{ marginBottom: 16 }}>
      <label style={labelStyle}>{label}</label>
      {children}
      {error && <p style={{ color: '#f85149', fontSize: 11, marginTop: 4 }}>{error[0]}</p>}
    </div>
  );
}

export default function Register() {
  const { register } = useAuth();
  const toast        = useToast();
  const navigate     = useNavigate();

  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' });
  const [errors, setErrors] = useState({});
  const [loading, setLoading] = useState(false);

  const set = (field) => (e) => setForm((f) => ({ ...f, [field]: e.target.value }));

  const focusBorder = (hasError) => ({
    onFocus: (e) => (e.target.style.borderColor = '#00d4ff'),
    onBlur:  (e) => (e.target.style.borderColor = hasError ? '#f85149' : 'rgba(48,54,61,0.8)'),
  });

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrors({});
    setLoading(true);
    try {
      await register(form.name, form.email, form.password, form.password_confirmation);
      navigate('/', { replace: true });
    } catch (err) {
      if (err.type === 'VALIDATION') {
        setErrors(err.details ?? {});
      } else if (err.type === 'RATE_LIMIT') {
        toast.warn(err.message);
      } else {
        toast.error(err.message ?? 'Registration failed.');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <AuthLayout title="Create Account" subtitle="Start monitoring conjunctions on the free tier">
      <form onSubmit={handleSubmit} noValidate>
        <Field label="Name" error={errors.name}>
          <input
            type="text"
            value={form.name}
            onChange={set('name')}
            placeholder="Mission Control"
            autoComplete="name"
            style={{ ...inputStyle, borderColor: errors.name ? '#f85149' : undefined }}
            {...focusBorder(errors.name)}
          />
        </Field>

        <Field label="Email" error={errors.email}>
          <input
            type="email"
            value={form.email}
            onChange={set('email')}
            placeholder="you@example.com"
            autoComplete="email"
            style={{ ...inputStyle, borderColor: errors.email ? '#f85149' : undefined }}
            {...focusBorder(errors.email)}
          />
        </Field>

        <Field label="Password" error={errors.password}>
          <input
            type="password"
            value={form.password}
            onChange={set('password')}
            placeholder="Min 8 chars, letters + numbers"
            autoComplete="new-password"
            style={{ ...inputStyle, borderColor: errors.password ? '#f85149' : undefined }}
            {...focusBorder(errors.password)}
          />
        </Field>

        <Field label="Confirm Password" error={errors.password_confirmation}>
          <input
            type="password"
            value={form.password_confirmation}
            onChange={set('password_confirmation')}
            placeholder="••••••••"
            autoComplete="new-password"
            style={{ ...inputStyle, borderColor: errors.password_confirmation ? '#f85149' : undefined }}
            {...focusBorder(errors.password_confirmation)}
          />
        </Field>

        <button
          type="submit"
          disabled={loading}
          style={{
            width: '100%',
            marginTop: 8,
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
          }}
        >
          {loading ? 'CREATING ACCOUNT...' : 'CREATE ACCOUNT'}
        </button>

        <p style={{ textAlign: 'center', marginTop: 20, color: '#8b949e', fontSize: 12 }}>
          Already have an account?{' '}
          <Link to="/login" style={{ color: '#00d4ff', textDecoration: 'none' }}>Sign in</Link>
        </p>
      </form>
    </AuthLayout>
  );
}
