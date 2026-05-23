import { useEffect, useState } from 'react';
import { useToast } from '../../contexts/ToastContext';
import adminClient from '../../api/adminClient';

const PLAN_COLOR = { enterprise: '#3fb950', pro: '#00d4ff', starter: '#d29922', free: '#484f58' };
const STATUS_COLOR = { active: '#3fb950', canceled: '#f85149', past_due: '#d29922' };

export default function AdminSubscriptions() {
  const toast                 = useToast();
  const [subs, setSubs]       = useState([]);
  const [meta, setMeta]       = useState({});
  const [page, setPage]       = useState(1);
  const [filters, setFilters] = useState({ plan: '', status: '' });
  const [loading, setLoading] = useState(true);

  const load = async (p = page) => {
    setLoading(true);
    try {
      const params = new URLSearchParams({ page: p, ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)) });
      const r = await adminClient.get(`/admin/subscriptions?${params}`);
      const payload = r.data.data;
      setSubs(payload.data ?? []);
      setMeta({ total: payload.total, lastPage: payload.last_page, currentPage: payload.current_page });
    } catch (err) {
      toast.error(err.message ?? 'Failed to load subscriptions.');
    } finally {
      setLoading(false);
    }
  };

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load(1); setPage(1); }, [filters]);

  const thStyle = { fontSize: 10, color: '#8b949e', letterSpacing: '0.1em', textTransform: 'uppercase', padding: '10px 12px', textAlign: 'left', borderBottom: '1px solid rgba(48,54,61,0.6)', whiteSpace: 'nowrap' };
  const tdStyle = { fontSize: 12, padding: '12px', borderBottom: '1px solid rgba(48,54,61,0.3)', verticalAlign: 'middle' };

  return (
    <div>
      <h1 style={{ fontFamily: "'Orbitron', sans-serif", fontSize: 16, fontWeight: 700, letterSpacing: '0.15em', textTransform: 'uppercase', marginBottom: 20 }}>
        Subscriptions
      </h1>

      {/* Filters */}
      <div style={{ display: 'flex', gap: 10, marginBottom: 20 }}>
        {[
          ['plan',   ['', 'free', 'starter', 'pro', 'enterprise']],
          ['status', ['', 'active', 'canceled', 'past_due']],
        ].map(([field, opts]) => (
          <select
            key={field}
            value={filters[field]}
            onChange={(e) => setFilters((f) => ({ ...f, [field]: e.target.value }))}
            style={{
              background: '#161b22', border: '1px solid rgba(48,54,61,0.6)', borderRadius: 4,
              color: '#e6edf3', fontFamily: "'JetBrains Mono', monospace",
              fontSize: 12, padding: '8px 12px', outline: 'none',
            }}
          >
            {opts.map((o) => <option key={o} value={o}>{o || `All ${field}s`}</option>)}
          </select>
        ))}
      </div>

      <div style={{ background: '#161b22', border: '1px solid rgba(48,54,61,0.6)', borderRadius: 6, overflow: 'hidden' }}>
        {loading ? (
          <p style={{ padding: 20, color: '#8b949e', fontSize: 13 }}>Loading...</p>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr>
                {['User', 'Email', 'Plan', 'Status', 'Started', 'Renews / Ends', 'Canceled'].map((h) => (
                  <th key={h} style={thStyle}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {subs.map((s) => (
                <tr key={s.id}>
                  <td style={tdStyle}>{s.user_name ?? '—'}</td>
                  <td style={{ ...tdStyle, color: '#8b949e' }}>{s.user_email ?? '—'}</td>
                  <td style={{ ...tdStyle, color: PLAN_COLOR[s.plan] ?? '#e6edf3', fontWeight: 700, textTransform: 'uppercase', fontSize: 10 }}>
                    {s.plan}
                  </td>
                  <td style={{ ...tdStyle, color: STATUS_COLOR[s.status] ?? '#8b949e', textTransform: 'uppercase', fontSize: 10 }}>
                    {s.status}
                  </td>
                  <td style={{ ...tdStyle, color: '#8b949e', fontSize: 11 }}>
                    {s.current_period_start ? new Date(s.current_period_start).toLocaleDateString() : '—'}
                  </td>
                  <td style={{ ...tdStyle, color: '#8b949e', fontSize: 11 }}>
                    {s.current_period_end ? new Date(s.current_period_end).toLocaleDateString() : '—'}
                  </td>
                  <td style={{ ...tdStyle, color: s.canceled_at ? '#f85149' : '#484f58', fontSize: 11 }}>
                    {s.canceled_at ? new Date(s.canceled_at).toLocaleDateString() : '—'}
                  </td>
                </tr>
              ))}
              {!subs.length && (
                <tr><td colSpan={7} style={{ ...tdStyle, textAlign: 'center', color: '#484f58' }}>No subscriptions found.</td></tr>
              )}
            </tbody>
          </table>
        )}
      </div>

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
    </div>
  );
}
