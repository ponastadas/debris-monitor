import { useEffect, useState } from 'react';
import { useToast } from '../../contexts/ToastContext';
import adminClient from '../../api/adminClient';

// ── Event catalogue (mirrors AdminAuditLog PHP constants) ─────────────────────

const KNOWN_ACTIONS = [
  'login.success',
  'login.failed',
  'login.failed_inactive',
  'logout',
  'impersonation.started',
  'user.updated',
  'user.suspended',
  'user.activated',
  'payment.refunded',
  'subscription.updated',
  'api_key.revoked',
];

const ACTION_COLOR = {
  'login.success':          '#3fb950',
  'login.failed':           '#f85149',
  'login.failed_inactive':  '#d29922',
  'logout':                 '#8b949e',
  'impersonation.started':  '#d29922',
  'user.updated':           '#388bfd',
  'user.suspended':         '#f85149',
  'user.activated':         '#3fb950',
  'payment.refunded':       '#d29922',
  'subscription.updated':   '#8b949e',
  'api_key.revoked':        '#f85149',
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function actionColor(action) {
  return ACTION_COLOR[action] ?? '#8b949e';
}

function metaSummary(metadata) {
  if (!metadata || Object.keys(metadata).length === 0) return null;
  return Object.entries(metadata)
    .map(([k, v]) => `${k}: ${Array.isArray(v) ? v.join(', ') : v}`)
    .join('  ·  ');
}

function formatTs(iso) {
  const d = new Date(iso);
  const date = d.toLocaleDateString('en-GB', { year: 'numeric', month: 'short', day: 'numeric' });
  const time = d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  return { date, time };
}

// ── Shared styles (matching existing admin pages exactly) ─────────────────────

const thStyle = {
  fontSize: 10, color: '#8b949e', letterSpacing: '0.1em', textTransform: 'uppercase',
  padding: '10px 12px', textAlign: 'left', borderBottom: '1px solid rgba(48,54,61,0.6)',
  whiteSpace: 'nowrap',
};
const tdStyle = {
  fontSize: 12, padding: '12px', borderBottom: '1px solid rgba(48,54,61,0.3)', verticalAlign: 'middle',
};
const inputStyle = {
  background: '#161b22', border: '1px solid rgba(48,54,61,0.6)', borderRadius: 4,
  color: '#e6edf3', fontFamily: "'JetBrains Mono', monospace", fontSize: 12,
  padding: '8px 12px', outline: 'none',
};

// ── Component ─────────────────────────────────────────────────────────────────

export default function AdminAuditLog() {
  const toast = useToast();

  const [entries, setEntries]   = useState([]);
  const [meta, setMeta]         = useState({});
  const [page, setPage]         = useState(1);
  const [loading, setLoading]   = useState(true);

  // Filters
  const [action, setAction]     = useState('');
  const [from, setFrom]         = useState('');
  const [to, setTo]             = useState('');

  const load = async (p = page) => {
    setLoading(true);
    try {
      const params = new URLSearchParams({ page: p });
      if (action) params.set('action', action);
      if (from)   params.set('from', from);
      if (to)     params.set('to', to);

      const r       = await adminClient.get(`/admin/audit-log?${params}`);
      const payload = r.data.data;
      setEntries(payload.data ?? []);
      setMeta({ total: payload.total, lastPage: payload.last_page, currentPage: payload.current_page });
    } catch (err) {
      toast.error(err.message ?? 'Failed to load audit log.');
    } finally {
      setLoading(false);
    }
  };

  // Reload from page 1 whenever any filter changes
  useEffect(() => { setPage(1); load(1); }, [action, from, to]);

  const changePage = (p) => { setPage(p); load(p); };

  return (
    <div>
      <h1 style={{
        fontFamily: "'Orbitron', sans-serif", fontSize: 16, fontWeight: 700,
        letterSpacing: '0.15em', textTransform: 'uppercase', marginBottom: 20,
      }}>
        Audit Log
      </h1>

      {/* ── Filters ── */}
      <div style={{ display: 'flex', gap: 10, marginBottom: 20, flexWrap: 'wrap', alignItems: 'center' }}>
        {/* Action */}
        <select value={action} onChange={(e) => setAction(e.target.value)} style={inputStyle}>
          <option value="">All actions</option>
          {KNOWN_ACTIONS.map((a) => (
            <option key={a} value={a}>{a}</option>
          ))}
        </select>

        {/* Date from */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <span style={{ fontSize: 10, color: '#8b949e', letterSpacing: '0.1em' }}>FROM</span>
          <input
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            style={{ ...inputStyle, colorScheme: 'dark' }}
          />
        </div>

        {/* Date to */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <span style={{ fontSize: 10, color: '#8b949e', letterSpacing: '0.1em' }}>TO</span>
          <input
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            style={{ ...inputStyle, colorScheme: 'dark' }}
          />
        </div>

        {/* Clear filters */}
        {(action || from || to) && (
          <button
            onClick={() => { setAction(''); setFrom(''); setTo(''); }}
            style={{
              background: 'none', border: '1px solid rgba(48,54,61,0.6)', borderRadius: 4,
              color: '#8b949e', fontFamily: "'JetBrains Mono', monospace",
              fontSize: 11, padding: '8px 12px', cursor: 'pointer', letterSpacing: '0.05em',
            }}
          >
            CLEAR
          </button>
        )}

        {meta.total !== undefined && (
          <span style={{ marginLeft: 'auto', fontSize: 11, color: '#484f58' }}>
            {meta.total.toLocaleString()} {meta.total === 1 ? 'entry' : 'entries'}
          </span>
        )}
      </div>

      {/* ── Table ── */}
      <div style={{ background: '#161b22', border: '1px solid rgba(48,54,61,0.6)', borderRadius: 6, overflow: 'hidden' }}>
        {loading ? (
          <p style={{ padding: 20, color: '#8b949e', fontSize: 13 }}>Loading...</p>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr>
                {['Timestamp', 'Actor', 'Action', 'Target', 'Details'].map((h) => (
                  <th key={h} style={thStyle}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {entries.map((e) => {
                const { date, time } = formatTs(e.created_at);
                const summary        = metaSummary(e.metadata);
                const target         = e.target_type
                  ? `${e.target_type} #${e.target_id}`
                  : null;

                return (
                  <tr key={e.id} style={{ transition: 'background 0.08s' }}
                    onMouseEnter={(ev) => ev.currentTarget.style.background = 'rgba(0,212,255,0.025)'}
                    onMouseLeave={(ev) => ev.currentTarget.style.background = 'transparent'}
                  >
                    {/* Timestamp */}
                    <td style={{ ...tdStyle, whiteSpace: 'nowrap' }}>
                      <div style={{ fontSize: 12, color: '#e6edf3' }}>{time}</div>
                      <div style={{ fontSize: 10, color: '#484f58', marginTop: 2 }}>{date}</div>
                    </td>

                    {/* Actor */}
                    <td style={tdStyle}>
                      {e.admin_email ? (
                        <>
                          <div style={{ fontSize: 12 }}>{e.admin_name ?? e.admin_email}</div>
                          {e.admin_name && <div style={{ fontSize: 10, color: '#8b949e' }}>{e.admin_email}</div>}
                        </>
                      ) : (
                        <span style={{ fontSize: 11, color: '#484f58', fontStyle: 'italic' }}>unknown</span>
                      )}
                    </td>

                    {/* Action badge */}
                    <td style={{ ...tdStyle, whiteSpace: 'nowrap' }}>
                      <span style={{
                        display: 'inline-block',
                        fontSize: 10,
                        letterSpacing: '0.06em',
                        color: actionColor(e.action),
                        background: `${actionColor(e.action)}1a`,
                        border: `1px solid ${actionColor(e.action)}40`,
                        borderRadius: 3,
                        padding: '2px 7px',
                      }}>
                        {e.action}
                      </span>
                    </td>

                    {/* Target */}
                    <td style={{ ...tdStyle, color: '#8b949e', fontSize: 11, whiteSpace: 'nowrap' }}>
                      {target ?? <span style={{ color: '#484f58' }}>—</span>}
                    </td>

                    {/* Details */}
                    <td style={{ ...tdStyle, color: '#8b949e', fontSize: 11, maxWidth: 300 }}>
                      {summary
                        ? <span title={summary} style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{summary}</span>
                        : <span style={{ color: '#484f58' }}>—</span>
                      }
                    </td>
                  </tr>
                );
              })}

              {!entries.length && (
                <tr>
                  <td colSpan={5} style={{ ...tdStyle, textAlign: 'center', color: '#484f58' }}>
                    No audit log entries found.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        )}
      </div>

      {/* ── Pagination ── */}
      {meta.lastPage > 1 && (
        <div style={{ display: 'flex', gap: 8, marginTop: 16, alignItems: 'center' }}>
          <button
            disabled={page <= 1}
            onClick={() => changePage(page - 1)}
            style={{
              background: 'rgba(48,54,61,0.4)', border: '1px solid rgba(48,54,61,0.8)',
              borderRadius: 4, color: '#8b949e', fontSize: 11, padding: '6px 12px',
              cursor: page <= 1 ? 'not-allowed' : 'pointer',
            }}
          >
            ← PREV
          </button>
          <span style={{ fontSize: 11, color: '#8b949e' }}>
            Page {meta.currentPage} of {meta.lastPage} · {meta.total.toLocaleString()} total
          </span>
          <button
            disabled={page >= meta.lastPage}
            onClick={() => changePage(page + 1)}
            style={{
              background: 'rgba(48,54,61,0.4)', border: '1px solid rgba(48,54,61,0.8)',
              borderRadius: 4, color: '#8b949e', fontSize: 11, padding: '6px 12px',
              cursor: page >= meta.lastPage ? 'not-allowed' : 'pointer',
            }}
          >
            NEXT →
          </button>
        </div>
      )}
    </div>
  );
}
