import { useEffect, useState } from 'react';
import { useToast } from '../../contexts/ToastContext';
import adminClient from '../../api/adminClient';

const BADGE = {
  role: {
    admin: { bg: 'rgba(0,212,255,0.15)', border: 'rgba(0,212,255,0.4)', color: '#00d4ff' },
    user:  { bg: 'rgba(72,79,88,0.3)',  border: 'rgba(72,79,88,0.6)',  color: '#8b949e' },
  },
  status: {
    active:    { bg: 'rgba(63,185,80,0.12)',  border: 'rgba(63,185,80,0.4)',  color: '#3fb950' },
    suspended: { bg: 'rgba(248,81,73,0.12)', border: 'rgba(248,81,73,0.4)', color: '#f85149' },
  },
  plan: {
    enterprise: { color: '#3fb950' }, pro: { color: '#00d4ff' },
    starter: { color: '#d29922' },    free: { color: '#484f58' },
  },
};

function Badge({ type, value }) {
  const style = BADGE[type]?.[value] ?? {};
  return (
    <span style={{
      background: style.bg, border: `1px solid ${style.border}`,
      borderRadius: 3, color: style.color,
      fontSize: 9, fontWeight: 700, letterSpacing: '0.1em',
      padding: '2px 6px', textTransform: 'uppercase',
    }}>
      {value}
    </span>
  );
}

function EditModal({ user, onClose, onSaved }) {
  const toast                   = useToast();
  const [form, setForm]         = useState({ status: user.status });
  const [loading, setLoading]   = useState(false);

  const save = async () => {
    setLoading(true);
    try {
      const r = await adminClient.patch(`/admin/users/${user.id}`, form);
      onSaved(r.data.data);
      toast.success('User updated.');
      onClose();
    } catch (err) {
      toast.error(err.message ?? 'Update failed.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div style={{
      position: 'fixed', inset: 0, background: 'rgba(1,4,9,0.85)',
      display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 100,
    }}>
      <div style={{
        background: '#161b22', border: '1px solid rgba(0,212,255,0.2)',
        borderRadius: 8, padding: '24px', width: 360,
        fontFamily: "'JetBrains Mono', monospace",
      }}>
        <h3 style={{ fontFamily: "'Orbitron', sans-serif", fontSize: 13, color: '#e6edf3', marginBottom: 4 }}>
          Edit User
        </h3>
        <p style={{ fontSize: 11, color: '#8b949e', marginBottom: 20 }}>{user.email}</p>

        {[['Status', 'status', ['active', 'suspended']]].map(([label, field, opts]) => (
          <div key={field} style={{ marginBottom: 16 }}>
            <label style={{ display: 'block', fontSize: 10, color: '#8b949e', textTransform: 'uppercase', letterSpacing: '0.1em', marginBottom: 6 }}>{label}</label>
            <select
              value={form[field]}
              onChange={(e) => setForm((f) => ({ ...f, [field]: e.target.value }))}
              style={{
                width: '100%', background: '#010409', border: '1px solid rgba(48,54,61,0.8)',
                borderRadius: 4, color: '#e6edf3', fontFamily: "'JetBrains Mono', monospace",
                fontSize: 12, padding: '8px 10px', outline: 'none',
              }}
            >
              {opts.map((o) => <option key={o} value={o}>{o}</option>)}
            </select>
          </div>
        ))}

        <div style={{ display: 'flex', gap: 8, marginTop: 20 }}>
          <button
            onClick={save} disabled={loading}
            style={{
              flex: 1, background: 'rgba(0,212,255,0.15)', border: '1px solid rgba(0,212,255,0.4)',
              borderRadius: 4, color: '#00d4ff', fontFamily: "'Orbitron', sans-serif",
              fontSize: 11, fontWeight: 700, letterSpacing: '0.08em', padding: '10px',
              cursor: loading ? 'not-allowed' : 'pointer', textTransform: 'uppercase',
            }}
          >
            {loading ? '...' : 'SAVE'}
          </button>
          <button
            onClick={onClose}
            style={{
              flex: 1, background: 'rgba(48,54,61,0.4)', border: '1px solid rgba(48,54,61,0.8)',
              borderRadius: 4, color: '#8b949e', fontFamily: "'Orbitron', sans-serif",
              fontSize: 11, fontWeight: 700, letterSpacing: '0.08em', padding: '10px',
              cursor: 'pointer', textTransform: 'uppercase',
            }}
          >
            CANCEL
          </button>
        </div>
      </div>
    </div>
  );
}

export default function AdminUsers() {
  const toast               = useToast();
  const [users, setUsers]   = useState([]);
  const [meta, setMeta]     = useState({});
  const [page, setPage]     = useState(1);
  const [filters, setFilters] = useState({ search: '', status: '' });
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState(null);

  const load = async (p = page) => {
    setLoading(true);
    try {
      const params = new URLSearchParams({ page: p, ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)) });
      const r = await adminClient.get(`/admin/users?${params}`);
      const payload = r.data.data;
      setUsers(payload.data ?? []);
      setMeta({ total: payload.total, lastPage: payload.last_page, currentPage: payload.current_page });
    } catch (err) {
      toast.error(err.message ?? 'Failed to load users.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(1); setPage(1); }, [filters]);

  const impersonate = async (user) => {
    try {
      const r = await adminClient.post(`/admin/users/${user.id}/impersonate`);
      const { token } = r.data.data;
      // Open app in new tab with impersonation token
      const url = `${window.location.origin}/?impersonate=${token}`;
      window.open(url, '_blank');
      toast.info(`Impersonating ${user.name}`);
    } catch (err) {
      toast.error(err.message ?? 'Impersonation failed.');
    }
  };

  const thStyle = { fontSize: 10, color: '#8b949e', letterSpacing: '0.1em', textTransform: 'uppercase', padding: '10px 12px', textAlign: 'left', borderBottom: '1px solid rgba(48,54,61,0.6)', whiteSpace: 'nowrap' };
  const tdStyle = { fontSize: 12, padding: '12px', borderBottom: '1px solid rgba(48,54,61,0.3)', verticalAlign: 'middle' };

  return (
    <div>
      <h1 style={{ fontFamily: "'Orbitron', sans-serif", fontSize: 16, fontWeight: 700, letterSpacing: '0.15em', textTransform: 'uppercase', marginBottom: 20 }}>
        Users
      </h1>

      {/* Filters */}
      <div style={{ display: 'flex', gap: 10, marginBottom: 20, flexWrap: 'wrap' }}>
        {[
          ['search', 'text', 'Search name or email…', null],
          ['status', 'select', null, ['', 'active', 'suspended']],
        ].map(([field, type, placeholder, opts]) => (
          <div key={field}>
            {type === 'text' ? (
              <input
                value={filters[field]}
                onChange={(e) => setFilters((f) => ({ ...f, [field]: e.target.value }))}
                placeholder={placeholder}
                style={{
                  background: '#161b22', border: '1px solid rgba(48,54,61,0.6)', borderRadius: 4,
                  color: '#e6edf3', fontFamily: "'JetBrains Mono', monospace", fontSize: 12,
                  padding: '8px 12px', outline: 'none', width: 220,
                }}
              />
            ) : (
              <select
                value={filters[field]}
                onChange={(e) => setFilters((f) => ({ ...f, [field]: e.target.value }))}
                style={{
                  background: '#161b22', border: '1px solid rgba(48,54,61,0.6)', borderRadius: 4,
                  color: '#e6edf3', fontFamily: "'JetBrains Mono', monospace", fontSize: 12,
                  padding: '8px 12px', outline: 'none', textTransform: 'capitalize',
                }}
              >
                {opts.map((o) => <option key={o} value={o}>{o || `All ${field}s`}</option>)}
              </select>
            )}
          </div>
        ))}
      </div>

      {/* Table */}
      <div style={{ background: '#161b22', border: '1px solid rgba(48,54,61,0.6)', borderRadius: 6, overflow: 'hidden' }}>
        {loading ? (
          <p style={{ padding: 20, color: '#8b949e', fontSize: 13 }}>Loading...</p>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr>
                {['Name', 'Email', 'Status', 'Plan', 'Joined', 'Actions'].map((h) => (
                  <th key={h} style={thStyle}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {users.map((u) => (
                <tr key={u.id} style={{ ':hover': { background: 'rgba(48,54,61,0.3)' } }}>
                  <td style={tdStyle}>{u.name}</td>
                  <td style={{ ...tdStyle, color: '#8b949e' }}>{u.email}</td>
                  <td style={tdStyle}><Badge type="status" value={u.status} /></td>
                  <td style={{ ...tdStyle, color: BADGE.plan[u.subscription_plan]?.color ?? '#484f58', textTransform: 'uppercase', fontSize: 10 }}>
                    {u.subscription_plan}
                  </td>
                  <td style={{ ...tdStyle, color: '#8b949e', fontSize: 11 }}>
                    {new Date(u.created_at).toLocaleDateString()}
                  </td>
                  <td style={{ ...tdStyle, whiteSpace: 'nowrap' }}>
                    <button
                      onClick={() => setEditing(u)}
                      style={{ background: 'none', border: '1px solid rgba(48,54,61,0.8)', borderRadius: 4, color: '#8b949e', fontSize: 10, padding: '4px 8px', cursor: 'pointer', marginRight: 6 }}
                    >
                      EDIT
                    </button>
                    <button
                      onClick={() => impersonate(u)}
                      style={{ background: 'none', border: '1px solid rgba(0,212,255,0.3)', borderRadius: 4, color: '#00d4ff', fontSize: 10, padding: '4px 8px', cursor: 'pointer' }}
                    >
                      IMPERSONATE
                    </button>
                  </td>
                </tr>
              ))}
              {!users.length && (
                <tr><td colSpan={6} style={{ ...tdStyle, textAlign: 'center', color: '#484f58' }}>No users found.</td></tr>
              )}
            </tbody>
          </table>
        )}
      </div>

      {/* Pagination */}
      {meta.lastPage > 1 && (
        <div style={{ display: 'flex', gap: 8, marginTop: 16, alignItems: 'center' }}>
          <button disabled={page <= 1} onClick={() => { const p = page - 1; setPage(p); load(p); }}
            style={{ background: 'rgba(48,54,61,0.4)', border: '1px solid rgba(48,54,61,0.8)', borderRadius: 4, color: '#8b949e', fontSize: 11, padding: '6px 12px', cursor: page <= 1 ? 'not-allowed' : 'pointer' }}>
            ← PREV
          </button>
          <span style={{ fontSize: 11, color: '#8b949e' }}>Page {meta.currentPage} of {meta.lastPage} · {meta.total} total</span>
          <button disabled={page >= meta.lastPage} onClick={() => { const p = page + 1; setPage(p); load(p); }}
            style={{ background: 'rgba(48,54,61,0.4)', border: '1px solid rgba(48,54,61,0.8)', borderRadius: 4, color: '#8b949e', fontSize: 11, padding: '6px 12px', cursor: page >= meta.lastPage ? 'not-allowed' : 'pointer' }}>
            NEXT →
          </button>
        </div>
      )}

      {editing && (
        <EditModal
          user={editing}
          onClose={() => setEditing(null)}
          onSaved={(updated) => setUsers((us) => us.map((u) => u.id === updated.id ? updated : u))}
        />
      )}
    </div>
  );
}
