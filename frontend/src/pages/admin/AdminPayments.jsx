import { useEffect, useState } from 'react';
import { useToast } from '../../contexts/ToastContext';
import adminClient from '../../api/adminClient';

function RefundModal({ payment, onClose, onRefunded }) {
  const toast               = useToast();
  const [amount, setAmount] = useState('');
  const [loading, setLoading] = useState(false);

  const submit = async () => {
    setLoading(true);
    try {
      const body = amount ? { amount: Math.round(parseFloat(amount) * 100) } : {};
      const r    = await adminClient.post(`/admin/payments/${payment.id}/refund`, body);
      onRefunded(r.data.data);
      toast.success('Refund issued successfully.');
      onClose();
    } catch (err) {
      toast.error(err.message ?? 'Refund failed.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(1,4,9,0.85)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 100 }}>
      <div style={{ background: '#161b22', border: '1px solid rgba(248,81,73,0.3)', borderRadius: 8, padding: '24px', width: 360, fontFamily: "'JetBrains Mono', monospace" }}>
        <h3 style={{ fontFamily: "'Orbitron', sans-serif", fontSize: 13, color: '#f85149', marginBottom: 4 }}>Issue Refund</h3>
        <p style={{ fontSize: 11, color: '#8b949e', marginBottom: 20 }}>
          {payment.user_email} · {payment.amount_formatted} · {payment.description}
        </p>

        <div style={{ marginBottom: 20 }}>
          <label style={{ display: 'block', fontSize: 10, color: '#8b949e', textTransform: 'uppercase', letterSpacing: '0.1em', marginBottom: 6 }}>
            Amount (leave blank for full refund)
          </label>
          <input
            type="number"
            step="0.01"
            min="0.01"
            max={payment.amount / 100}
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            placeholder={`Up to ${payment.amount_formatted}`}
            style={{
              width: '100%', background: '#010409', border: '1px solid rgba(48,54,61,0.8)',
              borderRadius: 4, color: '#e6edf3', fontFamily: "'JetBrains Mono', monospace",
              fontSize: 12, padding: '8px 10px', outline: 'none', boxSizing: 'border-box',
            }}
          />
        </div>

        <div style={{ display: 'flex', gap: 8 }}>
          <button
            onClick={submit} disabled={loading}
            style={{
              flex: 1, background: 'rgba(248,81,73,0.15)', border: '1px solid rgba(248,81,73,0.4)',
              borderRadius: 4, color: '#f85149', fontFamily: "'Orbitron', sans-serif",
              fontSize: 11, fontWeight: 700, letterSpacing: '0.08em', padding: '10px',
              cursor: loading ? 'not-allowed' : 'pointer', textTransform: 'uppercase',
            }}
          >
            {loading ? '...' : 'REFUND'}
          </button>
          <button onClick={onClose}
            style={{
              flex: 1, background: 'rgba(48,54,61,0.4)', border: '1px solid rgba(48,54,61,0.8)',
              borderRadius: 4, color: '#8b949e', fontFamily: "'Orbitron', sans-serif",
              fontSize: 11, fontWeight: 700, padding: '10px', cursor: 'pointer', textTransform: 'uppercase',
            }}
          >
            CANCEL
          </button>
        </div>
      </div>
    </div>
  );
}

const STATUS_COLOR = { succeeded: '#3fb950', refunded: '#d29922', failed: '#f85149' };

export default function AdminPayments() {
  const toast                     = useToast();
  const [payments, setPayments]   = useState([]);
  const [meta, setMeta]           = useState({});
  const [page, setPage]           = useState(1);
  const [statusFilter, setStatus] = useState('');
  const [refunding, setRefunding] = useState(null);
  const [loading, setLoading]     = useState(true);

  const load = async (p = page) => {
    setLoading(true);
    try {
      const params = new URLSearchParams({ page: p, ...(statusFilter ? { status: statusFilter } : {}) });
      const r = await adminClient.get(`/admin/payments?${params}`);
      const payload = r.data.data;
      setPayments(payload.data ?? []);
      setMeta({ total: payload.total, lastPage: payload.last_page, currentPage: payload.current_page });
    } catch (err) {
      toast.error(err.message ?? 'Failed to load payments.');
    } finally {
      setLoading(false);
    }
  };

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load(1); setPage(1); }, [statusFilter]);

  const thStyle = { fontSize: 10, color: '#8b949e', letterSpacing: '0.1em', textTransform: 'uppercase', padding: '10px 12px', textAlign: 'left', borderBottom: '1px solid rgba(48,54,61,0.6)', whiteSpace: 'nowrap' };
  const tdStyle = { fontSize: 12, padding: '12px', borderBottom: '1px solid rgba(48,54,61,0.3)', verticalAlign: 'middle' };

  return (
    <div>
      <h1 style={{ fontFamily: "'Orbitron', sans-serif", fontSize: 16, fontWeight: 700, letterSpacing: '0.15em', textTransform: 'uppercase', marginBottom: 20 }}>
        Payments
      </h1>

      <div style={{ display: 'flex', gap: 10, marginBottom: 20 }}>
        <select
          value={statusFilter}
          onChange={(e) => setStatus(e.target.value)}
          style={{ background: '#161b22', border: '1px solid rgba(48,54,61,0.6)', borderRadius: 4, color: '#e6edf3', fontFamily: "'JetBrains Mono', monospace", fontSize: 12, padding: '8px 12px', outline: 'none' }}
        >
          {['', 'succeeded', 'refunded', 'failed'].map((o) => <option key={o} value={o}>{o || 'All statuses'}</option>)}
        </select>
      </div>

      <div style={{ background: '#161b22', border: '1px solid rgba(48,54,61,0.6)', borderRadius: 6, overflow: 'hidden' }}>
        {loading ? (
          <p style={{ padding: 20, color: '#8b949e', fontSize: 13 }}>Loading...</p>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr>
                {['Customer', 'Description', 'Amount', 'Status', 'Date', 'Actions'].map((h) => (
                  <th key={h} style={thStyle}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {payments.map((p) => (
                <tr key={p.id}>
                  <td style={tdStyle}>
                    <div style={{ fontSize: 12 }}>{p.user_name ?? '—'}</div>
                    <div style={{ fontSize: 10, color: '#8b949e' }}>{p.user_email ?? '—'}</div>
                  </td>
                  <td style={{ ...tdStyle, color: '#8b949e', fontSize: 11 }}>{p.description}</td>
                  <td style={{ ...tdStyle, color: '#e6edf3', fontWeight: 700 }}>{p.amount_formatted}</td>
                  <td style={{ ...tdStyle, color: STATUS_COLOR[p.status] ?? '#8b949e', textTransform: 'uppercase', fontSize: 10 }}>
                    {p.status}
                    {p.refunded_at && <div style={{ fontSize: 9, color: '#484f58' }}>{new Date(p.refunded_at).toLocaleDateString()}</div>}
                  </td>
                  <td style={{ ...tdStyle, color: '#8b949e', fontSize: 11 }}>
                    {new Date(p.created_at).toLocaleDateString()}
                  </td>
                  <td style={tdStyle}>
                    {p.status === 'succeeded' && (
                      <button
                        onClick={() => setRefunding(p)}
                        style={{ background: 'none', border: '1px solid rgba(248,81,73,0.4)', borderRadius: 4, color: '#f85149', fontSize: 10, padding: '4px 8px', cursor: 'pointer' }}
                      >
                        REFUND
                      </button>
                    )}
                  </td>
                </tr>
              ))}
              {!payments.length && (
                <tr><td colSpan={6} style={{ ...tdStyle, textAlign: 'center', color: '#484f58' }}>No payments found.</td></tr>
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

      {refunding && (
        <RefundModal
          payment={refunding}
          onClose={() => setRefunding(null)}
          onRefunded={(updated) => setPayments((ps) => ps.map((p) => p.id === updated.id ? { ...p, ...updated } : p))}
        />
      )}
    </div>
  );
}
