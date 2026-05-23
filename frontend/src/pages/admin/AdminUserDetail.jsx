import { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useToast } from '../../contexts/ToastContext';
import adminClient from '../../api/adminClient';

const BADGE_STATUS = {
  active:    { bg: 'rgba(63,185,80,0.12)',  border: 'rgba(63,185,80,0.4)',  color: '#3fb950' },
  suspended: { bg: 'rgba(248,81,73,0.12)', border: 'rgba(248,81,73,0.4)', color: '#f85149' },
};

const PLAN_COLOR = {
  enterprise: '#3fb950', pro: '#00d4ff', starter: '#d29922', free: '#484f58',
};

function StatusBadge({ status }) {
  const s = BADGE_STATUS[status] ?? {};
  return (
    <span style={{
      background: s.bg, border: `1px solid ${s.border}`, borderRadius: 3,
      color: s.color, fontSize: 10, fontWeight: 700, letterSpacing: '0.1em',
      padding: '3px 8px', textTransform: 'uppercase',
    }}>
      {status}
    </span>
  );
}

function InfoRow({ label, children }) {
  return (
    <div style={{ display: 'flex', borderBottom: '1px solid rgba(48,54,61,0.3)', padding: '10px 0' }}>
      <span style={{ width: 160, fontSize: 10, color: '#484f58', textTransform: 'uppercase', letterSpacing: '0.1em', flexShrink: 0 }}>
        {label}
      </span>
      <span style={{ fontSize: 12, color: '#e6edf3' }}>{children}</span>
    </div>
  );
}

function Card({ title, children }) {
  return (
    <div style={{ background: '#161b22', border: '1px solid rgba(48,54,61,0.6)', borderRadius: 6, padding: '20px 24px', marginBottom: 20 }}>
      <h3 style={{ fontFamily: "'Orbitron', sans-serif", fontSize: 11, fontWeight: 700, color: '#8b949e', letterSpacing: '0.15em', textTransform: 'uppercase', marginBottom: 16, marginTop: 0 }}>
        {title}
      </h3>
      {children}
    </div>
  );
}

const inputStyle = {
  width: '100%', background: '#010409', border: '1px solid rgba(48,54,61,0.8)',
  borderRadius: 4, color: '#e6edf3', fontFamily: "'JetBrains Mono', monospace",
  fontSize: 12, padding: '8px 10px', outline: 'none', boxSizing: 'border-box',
};

export default function AdminUserDetail() {
  const { id }        = useParams();
  const navigate      = useNavigate();
  const toast         = useToast();
  const [user, setUser]     = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [form, setForm]     = useState({ name: '', status: '' });

  useEffect(() => {
    setLoading(true);
    adminClient.get(`/admin/users/${id}`)
      .then((res) => {
        const u = res.data.data;
        setUser(u);
        setForm({ name: u.name, status: u.status });
      })
      .catch(() => toast.error('Failed to load user.'))
      .finally(() => setLoading(false));
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  const save = async () => {
    setSaving(true);
    try {
      const res = await adminClient.patch(`/admin/users/${id}`, form);
      setUser((u) => ({ ...u, ...res.data.data }));
      setForm((f) => ({ ...f, name: res.data.data.name, status: res.data.data.status }));
      toast.success('User updated.');
    } catch (err) {
      toast.error(err.message ?? 'Update failed.');
    } finally {
      setSaving(false);
    }
  };

  const impersonate = async () => {
    try {
      const res = await adminClient.post(`/admin/users/${id}/impersonate`);
      localStorage.setItem('dm_impersonate_pending', res.data.data.token);
      window.open('/', '_blank');
      toast.info(`Impersonating ${user.name}`);
    } catch (err) {
      toast.error(err.message ?? 'Impersonation failed.');
    }
  };

  if (loading) {
    return <p style={{ color: '#484f58', fontSize: 13 }}>Loading…</p>;
  }

  if (!user) return null;

  return (
    <div>
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 24 }}>
        <button
          onClick={() => navigate('/admin/users')}
          style={{ background: 'none', border: 'none', color: '#484f58', fontSize: 12, cursor: 'pointer', padding: 0, fontFamily: "'JetBrains Mono', monospace" }}
        >
          ← Users
        </button>
        <h1 style={{ fontFamily: "'Orbitron', sans-serif", fontSize: 15, fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase', margin: 0 }}>
          {user.name}
        </h1>
        <StatusBadge status={user.status} />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20 }}>

        {/* Profile */}
        <Card title="Profile">
          <InfoRow label="Name">{user.name}</InfoRow>
          <InfoRow label="Email">{user.email}</InfoRow>
          <InfoRow label="Joined">{new Date(user.created_at).toLocaleDateString()}</InfoRow>
          {user.suspended_at && (
            <InfoRow label="Suspended">{new Date(user.suspended_at).toLocaleDateString()}</InfoRow>
          )}
        </Card>

        {/* Account */}
        <Card title="Account">
          <InfoRow label="Plan">
            <span style={{ color: PLAN_COLOR[user.subscription_plan] ?? '#484f58', textTransform: 'uppercase', fontSize: 10, fontWeight: 700 }}>
              {user.subscription_plan}
            </span>
          </InfoRow>
          <InfoRow label="Sub status">{user.subscription_status}</InfoRow>
          <InfoRow label="API keys">{user.api_keys_count ?? 0}</InfoRow>
        </Card>

        {/* Edit */}
        <Card title="Edit">
          <div style={{ marginBottom: 14 }}>
            <label style={{ display: 'block', fontSize: 10, color: '#8b949e', textTransform: 'uppercase', letterSpacing: '0.1em', marginBottom: 6 }}>
              Name
            </label>
            <input
              type="text"
              value={form.name}
              onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
              maxLength={100}
              style={inputStyle}
            />
            <p style={{ fontSize: 10, color: '#484f58', margin: '4px 0 0' }}>
              Display name only. Email cannot be changed by admins.
            </p>
          </div>
          <div style={{ marginBottom: 18 }}>
            <label style={{ display: 'block', fontSize: 10, color: '#8b949e', textTransform: 'uppercase', letterSpacing: '0.1em', marginBottom: 6 }}>
              Status
            </label>
            <select
              value={form.status}
              onChange={(e) => setForm((f) => ({ ...f, status: e.target.value }))}
              style={inputStyle}
            >
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
            </select>
          </div>
          <button
            onClick={save} disabled={saving}
            style={{
              background: 'rgba(0,212,255,0.12)', border: '1px solid rgba(0,212,255,0.4)',
              borderRadius: 4, color: '#00d4ff', fontFamily: "'Orbitron', sans-serif",
              fontSize: 11, fontWeight: 700, letterSpacing: '0.08em', padding: '10px 18px',
              cursor: saving ? 'not-allowed' : 'pointer', textTransform: 'uppercase',
            }}
          >
            {saving ? '...' : 'Save Changes'}
          </button>
        </Card>

        {/* Actions */}
        <Card title="Actions">
          <p style={{ fontSize: 11, color: '#484f58', marginBottom: 16, marginTop: 0 }}>
            Impersonation opens a new tab with a 1-hour scoped session. The token is never visible in the URL.
          </p>
          <button
            onClick={impersonate}
            style={{
              background: 'none', border: '1px solid rgba(0,212,255,0.3)',
              borderRadius: 4, color: '#00d4ff', fontFamily: "'JetBrains Mono', monospace",
              fontSize: 11, padding: '8px 14px', cursor: 'pointer',
            }}
          >
            Impersonate User
          </button>
        </Card>

      </div>

      {/* API Keys */}
      {user.api_keys?.length > 0 && (
        <Card title="API Keys">
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr>
                {['Name', 'Tier', 'Last Used', 'Created'].map((h) => (
                  <th key={h} style={{ fontSize: 10, color: '#8b949e', letterSpacing: '0.1em', textTransform: 'uppercase', padding: '6px 10px', textAlign: 'left', borderBottom: '1px solid rgba(48,54,61,0.5)' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {user.api_keys.map((k) => (
                <tr key={k.id}>
                  <td style={{ fontSize: 12, padding: '8px 10px', borderBottom: '1px solid rgba(48,54,61,0.3)' }}>{k.name}</td>
                  <td style={{ fontSize: 10, padding: '8px 10px', borderBottom: '1px solid rgba(48,54,61,0.3)', color: PLAN_COLOR[k.tier] ?? '#484f58', textTransform: 'uppercase' }}>{k.tier}</td>
                  <td style={{ fontSize: 11, padding: '8px 10px', borderBottom: '1px solid rgba(48,54,61,0.3)', color: '#484f58' }}>{k.last_used ? new Date(k.last_used).toLocaleDateString() : '—'}</td>
                  <td style={{ fontSize: 11, padding: '8px 10px', borderBottom: '1px solid rgba(48,54,61,0.3)', color: '#484f58' }}>{new Date(k.created_at).toLocaleDateString()}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
    </div>
  );
}
