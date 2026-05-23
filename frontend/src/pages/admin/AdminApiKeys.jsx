import { useEffect, useState } from 'react';
import { useToast } from '../../contexts/ToastContext';
import adminClient from '../../api/adminClient';

const TIER_COLOR = { enterprise: '#3fb950', pro: '#00d4ff', starter: '#d29922', free: '#484f58' };

export default function AdminApiKeys() {
  const toast               = useToast();
  const [keys, setKeys]     = useState([]);
  const [meta, setMeta]     = useState({});
  const [page, setPage]     = useState(1);
  const [filters, setFilters] = useState({ tier: '' });
  const [loading, setLoading] = useState(true);

  const load = async (p = page) => {
    setLoading(true);
    try {
      const params = new URLSearchParams({ page: p, ...(filters.tier ? { tier: filters.tier } : {}) });
      const r = await adminClient.get(`/admin/api-keys?${params}`);
      const payload = r.data.data;
      setKeys(payload.data ?? []);
      setMeta({ total: payload.total, lastPage: payload.last_page, currentPage: payload.current_page });
    } catch (err) {
      toast.error(err.message ?? 'Failed to load API keys.');
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
        API Keys
      </h1>

      <div style={{ display: 'flex', gap: 10, marginBottom: 20 }}>
        <select
          value={filters.tier}
          onChange={(e) => setFilters({ tier: e.target.value })}
          style={{ background: '#161b22', border: '1px solid rgba(48,54,61,0.6)', borderRadius: 4, color: '#e6edf3', fontFamily: "'JetBrains Mono', monospace", fontSize: 12, padding: '8px 12px', outline: 'none' }}
        >
          {['', 'free', 'starter', 'pro', 'enterprise'].map((o) => <option key={o} value={o}>{o || 'All tiers'}</option>)}
        </select>
      </div>

      <div style={{ background: '#161b22', border: '1px solid rgba(48,54,61,0.6)', borderRadius: 6, overflow: 'hidden' }}>
        {loading ? (
          <p style={{ padding: 20, color: '#8b949e', fontSize: 13 }}>Loading...</p>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr>
                {['User', 'Key Name', 'Key Prefix', 'Tier', 'Daily Limit', 'Usage Today', 'Last Used'].map((h) => (
                  <th key={h} style={thStyle}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {keys.map((k) => (
                <tr key={k.id}>
                  <td style={tdStyle}>
                    <div style={{ fontSize: 12 }}>{k.user_name ?? '—'}</div>
                    <div style={{ fontSize: 10, color: '#8b949e' }}>{k.user_email ?? '—'}</div>
                  </td>
                  <td style={tdStyle}>{k.name}</td>
                  <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 11, color: '#8b949e' }}>{k.key_prefix}</td>
                  <td style={{ ...tdStyle, color: TIER_COLOR[k.tier] ?? '#484f58', textTransform: 'uppercase', fontSize: 10, fontWeight: 700 }}>{k.tier}</td>
                  <td style={{ ...tdStyle, color: '#8b949e', fontSize: 11 }}>{k.daily_limit ? k.daily_limit.toLocaleString() : '∞'}</td>
                  <td style={{ ...tdStyle, color: k.usage_today > 0 ? '#e6edf3' : '#484f58' }}>{k.usage_today.toLocaleString()}</td>
                  <td style={{ ...tdStyle, color: '#8b949e', fontSize: 11 }}>
                    {k.last_used_at ? new Date(k.last_used_at).toLocaleDateString() : 'Never'}
                  </td>
                </tr>
              ))}
              {!keys.length && (
                <tr><td colSpan={7} style={{ ...tdStyle, textAlign: 'center', color: '#484f58' }}>No API keys found.</td></tr>
              )}
            </tbody>
          </table>
        )}
      </div>

      {meta.lastPage > 1 && (
        <div style={{ display: 'flex', gap: 8, marginTop: 16, alignItems: 'center' }}>
          <button disabled={page <= 1} onClick={() => { const p = page - 1; setPage(p); load(p); }}
            style={{ background: 'rgba(48,54,61,0.4)', border: '1px solid rgba(48,54,61,0.8)', borderRadius: 4, color: '#8b949e', fontSize: 11, padding: '6px 12px', cursor: page <= 1 ? 'not-allowed' : 'pointer' }}>← PREV</button>
          <span style={{ fontSize: 11, color: '#8b949e' }}>Page {meta.currentPage} of {meta.lastPage} · {meta.total} total</span>
          <button disabled={page >= meta.lastPage} onClick={() => { const p = page + 1; setPage(p); load(p); }}
            style={{ background: 'rgba(48,54,61,0.4)', border: '1px solid rgba(48,54,61,0.8)', borderRadius: 4, color: '#8b949e', fontSize: 11, padding: '6px 12px', cursor: page >= meta.lastPage ? 'not-allowed' : 'pointer' }}>NEXT →</button>
        </div>
      )}
    </div>
  );
}
