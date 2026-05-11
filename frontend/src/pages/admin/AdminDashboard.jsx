import { useEffect, useState } from 'react';
import { useToast } from '../../contexts/ToastContext';
import adminClient from '../../api/adminClient';

function StatCard({ label, value, sub, color }) {
  return (
    <div style={{
      background: '#161b22',
      border: `1px solid rgba(48,54,61,0.6)`,
      borderTop: `2px solid ${color ?? '#00d4ff'}`,
      borderRadius: 6,
      padding: '18px 20px',
    }}>
      <div style={{ fontSize: 10, color: '#8b949e', letterSpacing: '0.12em', textTransform: 'uppercase', marginBottom: 8 }}>
        {label}
      </div>
      <div style={{ fontSize: 26, color: color ?? '#e6edf3', fontFamily: "'Orbitron', sans-serif", letterSpacing: '0.05em' }}>
        {value ?? '—'}
      </div>
      {sub && <div style={{ fontSize: 10, color: '#484f58', marginTop: 4 }}>{sub}</div>}
    </div>
  );
}

function SignupChart({ data }) {
  if (!data?.length) return null;
  const max = Math.max(...data.map((d) => d.count), 1);
  return (
    <div style={{
      background: '#161b22',
      border: '1px solid rgba(48,54,61,0.6)',
      borderRadius: 6,
      padding: '18px 20px',
    }}>
      <div style={{ fontSize: 10, color: '#8b949e', letterSpacing: '0.12em', textTransform: 'uppercase', marginBottom: 16 }}>
        Signups — Last 30 Days
      </div>
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 2, height: 60 }}>
        {data.map((d) => (
          <div
            key={d.date}
            title={`${d.date}: ${d.count}`}
            style={{
              flex: 1,
              height: `${Math.max((d.count / max) * 100, 4)}%`,
              background: 'rgba(0,212,255,0.5)',
              borderRadius: '2px 2px 0 0',
              minHeight: 2,
            }}
          />
        ))}
      </div>
    </div>
  );
}

function PlanBadge({ plan }) {
  const colors = { starter: '#d29922', pro: '#00d4ff', enterprise: '#3fb950', free: '#484f58' };
  return (
    <span style={{
      background: `${colors[plan] ?? '#484f58'}20`,
      border: `1px solid ${colors[plan] ?? '#484f58'}60`,
      borderRadius: 3,
      color: colors[plan] ?? '#484f58',
      fontSize: 9,
      fontWeight: 700,
      letterSpacing: '0.1em',
      padding: '2px 6px',
      textTransform: 'uppercase',
    }}>
      {plan}
    </span>
  );
}

function CatalogStatus({ catalog }) {
  if (!catalog) return null;

  const synced = catalog.synced_at
    ? new Date(catalog.synced_at).toLocaleString()
    : null;

  const typeColors = { satellite: '#388bfd', debris: '#f85149', rocket_body: '#d29922' };
  const typeLabels = { satellite: 'Satellites', debris: 'Debris', rocket_body: 'Rocket Bodies' };

  return (
    <div style={{
      background: '#161b22',
      border: '1px solid rgba(48,54,61,0.6)',
      borderRadius: 6,
      padding: '18px 20px',
    }}>
      <div style={{ fontSize: 10, color: '#8b949e', letterSpacing: '0.12em', textTransform: 'uppercase', marginBottom: 16 }}>
        Satellite Catalog
      </div>

      <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginBottom: 12 }}>
        <span style={{ fontSize: 26, color: catalog.total > 0 ? '#e6edf3' : '#484f58', fontFamily: "'Orbitron', sans-serif" }}>
          {catalog.total.toLocaleString()}
        </span>
        <span style={{ fontSize: 11, color: '#8b949e' }}>objects with TLE</span>
      </div>

      {Object.entries(catalog.by_type ?? {}).map(([type, count]) => (
        <div key={type} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
          <span style={{ fontSize: 10, color: typeColors[type] ?? '#8b949e' }}>
            {typeLabels[type] ?? type}
          </span>
          <span style={{ fontSize: 11, color: '#e6edf3' }}>{Number(count).toLocaleString()}</span>
        </div>
      ))}

      {catalog.total === 0 && (
        <div style={{ fontSize: 10, color: '#f85149', marginBottom: 8 }}>
          Catalog empty — run <code style={{ fontFamily: 'monospace', background: '#0d1117', padding: '1px 4px' }}>make sync-catalog</code>
        </div>
      )}

      <div style={{ marginTop: 12, fontSize: 10, color: '#484f58' }}>
        {synced ? `Last sync: ${synced}` : 'Never synced'}
      </div>
    </div>
  );
}

export default function AdminDashboard() {
  const toast               = useToast();
  const [stats, setStats]   = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    adminClient.get('/admin/dashboard')
      .then((r) => setStats(r.data.data))
      .catch(() => toast.error('Failed to load dashboard stats'))
      .finally(() => setLoading(false));
  }, []);

  const mrr = stats ? `$${(stats.mrr_cents / 100).toLocaleString('en-US', { minimumFractionDigits: 0 })}` : null;

  return (
    <div>
      <h1 style={{
        fontFamily: "'Orbitron', sans-serif",
        fontSize: 16, fontWeight: 700, letterSpacing: '0.15em',
        color: '#e6edf3', textTransform: 'uppercase', marginBottom: 24,
      }}>
        Dashboard
      </h1>

      {loading ? (
        <p style={{ color: '#8b949e', fontSize: 13 }}>Loading...</p>
      ) : (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: 16, marginBottom: 24 }}>
            <StatCard label="Active Users"       value={stats?.active_users}          color="#3fb950" />
            <StatCard label="MRR"                value={mrr}                          color="#00d4ff" sub="mock billing" />
            <StatCard label="API Calls Today"    value={stats?.total_api_calls_today?.toLocaleString()} color="#d29922" />
            <StatCard label="New Signups Today"  value={stats?.new_signups_today}     color="#8b949e" />
            <StatCard label="Suspended Accounts" value={stats?.suspended_users}       color="#f85149" />
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 16 }}>
            <SignupChart data={stats?.signups_last_30_days} />

            {/* Plan distribution */}
            <div style={{
              background: '#161b22', border: '1px solid rgba(48,54,61,0.6)',
              borderRadius: 6, padding: '18px 20px',
            }}>
              <div style={{ fontSize: 10, color: '#8b949e', letterSpacing: '0.12em', textTransform: 'uppercase', marginBottom: 16 }}>
                Active Plans
              </div>
              {Object.entries(stats?.plan_distribution ?? {}).map(([plan, count]) => (
                <div key={plan} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                  <PlanBadge plan={plan} />
                  <span style={{ fontSize: 13, color: '#e6edf3' }}>{count}</span>
                </div>
              ))}
              {!Object.keys(stats?.plan_distribution ?? {}).length && (
                <p style={{ fontSize: 11, color: '#484f58' }}>No active subscriptions yet.</p>
              )}
            </div>
          </div>

          <div style={{ marginTop: 16 }}>
            <CatalogStatus catalog={stats?.catalog} />
          </div>
        </>
      )}
    </div>
  );
}
