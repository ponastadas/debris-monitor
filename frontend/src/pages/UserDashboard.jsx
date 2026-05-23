import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import ReactGA from 'react-ga4';
import { useAuth } from '../contexts/AuthContext';
import { useToast } from '../contexts/ToastContext';
import client from '../api/client';

const TABS = ['API KEYS', 'BILLING', 'PROFILE'];

const S = {
  page: {
    minHeight: '100vh',
    background: '#0d1117',
    fontFamily: "'JetBrains Mono', monospace",
    color: '#e6edf3',
  },
  header: {
    borderBottom: '1px solid rgba(48,54,61,0.6)',
    padding: '14px 24px',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  logo: {
    fontFamily: "'Orbitron', sans-serif",
    fontSize: 13,
    fontWeight: 700,
    letterSpacing: '0.2em',
    color: '#00d4ff',
  },
  main: { maxWidth: 900, margin: '0 auto', padding: '32px 24px' },
  tabBar: { display: 'flex', gap: 0, marginBottom: 28, borderBottom: '1px solid rgba(48,54,61,0.6)' },
  card: {
    background: '#161b22',
    border: '1px solid rgba(48,54,61,0.6)',
    borderRadius: 6,
    padding: '20px 24px',
    marginBottom: 16,
  },
  btn: (primary) => ({
    background: primary ? 'rgba(0,212,255,0.15)' : 'rgba(48,54,61,0.4)',
    border: `1px solid ${primary ? 'rgba(0,212,255,0.4)' : 'rgba(48,54,61,0.8)'}`,
    borderRadius: 4,
    color: primary ? '#00d4ff' : '#8b949e',
    fontFamily: "'Orbitron', sans-serif",
    fontSize: 11,
    fontWeight: 700,
    letterSpacing: '0.08em',
    padding: '8px 14px',
    cursor: 'pointer',
    textTransform: 'uppercase',
  }),
  input: {
    background: '#010409',
    border: '1px solid rgba(48,54,61,0.8)',
    borderRadius: 4,
    color: '#e6edf3',
    fontFamily: "'JetBrains Mono', monospace",
    fontSize: 13,
    padding: '8px 12px',
    outline: 'none',
  },
};

// ── API Keys Tab ─────────────────────────────────────────────────────────────

function ApiKeysTab() {
  const toast = useToast();
  const [keys, setKeys]           = useState([]);
  const [keyName, setKeyName]     = useState('');
  const [newKey, setNewKey]       = useState(null);
  const [loading, setLoading]     = useState(true);
  const [creating, setCreating]   = useState(false);

  useEffect(() => {
    client.get('/keys')
      .then((r) => setKeys(r.data.data ?? r.data))
      .catch(() => toast.error('Failed to load API keys'))
      .finally(() => setLoading(false));
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const create = async () => {
    if (!keyName.trim()) return;
    setCreating(true);
    try {
      const r = await client.post('/keys', { name: keyName.trim() });
      const created = r.data.data ?? r.data;
      setNewKey(created.key);
      setKeys((k) => [created, ...k]);
      setKeyName('');
    } catch (err) {
      toast.error(err.message ?? 'Failed to create key');
    } finally {
      setCreating(false);
    }
  };

  const revoke = async (id) => {
    try {
      await client.delete(`/keys/${id}`);
      setKeys((k) => k.filter((key) => key.id !== id));
      toast.success('API key revoked');
    } catch (err) {
      toast.error(err.message ?? 'Failed to revoke key');
    }
  };

  if (loading) return <p style={{ color: '#8b949e', fontSize: 13 }}>Loading...</p>;

  return (
    <div>
      {/* New key banner */}
      {newKey && (
        <div style={{ ...S.card, borderColor: 'rgba(63,185,80,0.4)', background: 'rgba(63,185,80,0.05)', marginBottom: 20 }}>
          <p style={{ color: '#3fb950', fontSize: 12, marginBottom: 8 }}>
            ✓ New API key created — copy it now, it won't be shown again.
          </p>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
            <code style={{ flex: 1, background: '#010409', padding: '8px 12px', borderRadius: 4, color: '#e6edf3', fontSize: 12, overflowX: 'auto' }}>
              {newKey}
            </code>
            <button style={S.btn(true)} onClick={() => { navigator.clipboard.writeText(newKey); toast.success('Copied!'); }}>
              COPY
            </button>
            <button style={S.btn(false)} onClick={() => setNewKey(null)}>DISMISS</button>
          </div>
        </div>
      )}

      {/* Create form */}
      <div style={{ ...S.card, marginBottom: 24 }}>
        <p style={{ fontSize: 11, color: '#8b949e', letterSpacing: '0.1em', textTransform: 'uppercase', marginBottom: 12 }}>
          Create New API Key
        </p>
        <div style={{ display: 'flex', gap: 8 }}>
          <input
            style={{ ...S.input, flex: 1 }}
            placeholder="Key name (e.g. production-server)"
            value={keyName}
            onChange={(e) => setKeyName(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && create()}
          />
          <button style={S.btn(true)} onClick={create} disabled={creating}>
            {creating ? '...' : 'CREATE'}
          </button>
        </div>
      </div>

      {/* Key list */}
      {keys.length === 0 ? (
        <p style={{ color: '#8b949e', fontSize: 13 }}>No API keys yet.</p>
      ) : (
        keys.map((key) => (
          <div key={key.id} style={{ ...S.card, display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16 }}>
            <div>
              <div style={{ fontSize: 13, marginBottom: 4 }}>{key.name}</div>
              <div style={{ fontSize: 11, color: '#8b949e' }}>
                Tier: <span style={{ color: '#00d4ff' }}>{key.tier ?? 'free'}</span>
                {' · '}
                {key.daily_limit ? `${key.daily_limit.toLocaleString()} calls/day` : 'Unlimited'}
                {key.last_used_at ? ` · Last used ${new Date(key.last_used_at).toLocaleDateString()}` : ' · Never used'}
              </div>
            </div>
            <button style={{ ...S.btn(false), color: '#f85149', borderColor: 'rgba(248,81,73,0.3)' }} onClick={() => revoke(key.id)}>
              REVOKE
            </button>
          </div>
        ))
      )}
    </div>
  );
}

// ── Entitlement badge ─────────────────────────────────────────────────────────

function EntitlementBadge({ label, value, ok }) {
  return (
    <div>
      <div style={{ fontSize: 9, color: '#484f58', letterSpacing: '0.1em', marginBottom: 3, textTransform: 'uppercase' }}>{label}</div>
      <div style={{ fontSize: 12, color: ok ? '#3fb950' : '#8b949e' }}>{value}</div>
    </div>
  );
}

// ── Billing Tab ───────────────────────────────────────────────────────────────

function BillingTab() {
  const toast                         = useToast();
  const { refreshUser }               = useAuth();
  const [billing, setBilling]         = useState(null);
  const [history, setHistory]         = useState([]);
  const [loading, setLoading]         = useState(true);
  const [subscribing, setSubscribing] = useState(null);

  const loadBilling = () =>
    Promise.all([
      client.get('/billing/plan'),
      client.get('/billing/history'),
    ])
      .then(([planRes, histRes]) => {
        setBilling(planRes.data.data);
        setHistory(histRes.data.data ?? []);
      })
      .catch(() => toast.error('Failed to load billing info'))
      .finally(() => setLoading(false));

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { loadBilling(); }, []);

  const subscribe = async (plan) => {
    setSubscribing(plan);
    try {
      await client.post('/billing/subscribe', { plan });
      ReactGA.event({ category: 'Billing', action: 'subscribe', label: plan });
      toast.success(`Subscribed to ${plan} plan!`);
      await loadBilling();
      await refreshUser();
    } catch (err) {
      toast.error(err.message ?? 'Subscription failed.');
    } finally {
      setSubscribing(null);
    }
  };

  const cancel = async () => {
    if (!confirm('Cancel your subscription? You will be moved to the free tier at the end of the current period.')) return;
    try {
      await client.post('/billing/cancel');
      toast.success('Subscription canceled.');
      await loadBilling();
      await refreshUser();
    } catch (err) {
      toast.error(err.message ?? 'Cancellation failed.');
    }
  };

  if (loading) return <p style={{ color: '#8b949e', fontSize: 13 }}>Loading...</p>;

  const currentPlan  = billing?.plan ?? 'free';
  const entitlements = billing?.entitlements ?? {};
  const plans        = billing?.available_plans ?? [];

  const reqLimit = entitlements.requests_per_day;
  const reqLabel = reqLimit == null ? 'Unlimited' : `${reqLimit.toLocaleString()}/day`;
  const satLimit = entitlements.satellite_limit;

  return (
    <div>
      {/* ── Current plan + entitlements ── */}
      <div style={{ ...S.card, marginBottom: 24 }}>
        <div style={{ fontSize: 11, color: '#8b949e', textTransform: 'uppercase', letterSpacing: '0.1em', marginBottom: 10 }}>
          Current Plan
        </div>
        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16 }}>
          <div>
            <span style={{
              fontFamily: "'Orbitron', sans-serif", fontSize: 18, color: '#00d4ff',
              textTransform: 'uppercase', letterSpacing: '0.1em',
            }}>
              {billing?.plan_label ?? 'Free'}
            </span>
            {billing?.current_period_end && (
              <div style={{ fontSize: 11, color: '#8b949e', marginTop: 4 }}>
                Renews {new Date(billing.current_period_end).toLocaleDateString()}
              </div>
            )}
            {billing?.canceled_at && (
              <div style={{ fontSize: 11, color: '#f85149', marginTop: 4 }}>
                Canceled — access until {new Date(billing.current_period_end).toLocaleDateString()}
              </div>
            )}

            {/* Entitlement summary */}
            <div style={{ display: 'flex', gap: 24, marginTop: 14, flexWrap: 'wrap' }}>
              <EntitlementBadge label="API Calls" value={reqLabel} ok />
              <EntitlementBadge
                label="Alerts"
                value={entitlements.can_receive_alerts ? 'Enabled' : 'Disabled'}
                ok={entitlements.can_receive_alerts}
              />
              <EntitlementBadge
                label="Webhooks"
                value={entitlements.webhooks_enabled ? 'Enabled' : 'Disabled'}
                ok={entitlements.webhooks_enabled}
              />
              {satLimit != null && (
                <EntitlementBadge label="Satellite limit" value={`${satLimit} max`} ok={false} />
              )}
            </div>
          </div>

          {currentPlan !== 'free' && billing?.status === 'active' && (
            <button
              style={{ ...S.btn(false), color: '#f85149', borderColor: 'rgba(248,81,73,0.3)', flexShrink: 0 }}
              onClick={cancel}
            >
              CANCEL
            </button>
          )}
        </div>
      </div>

      {/* ── Upgrade plan cards (sourced from API) ── */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: 16, marginBottom: 28 }}>
        {plans.map((plan) => {
          const isCurrent = currentPlan === plan.key;
          return (
            <div key={plan.key} style={{
              background: isCurrent ? 'rgba(0,212,255,0.04)' : '#161b22',
              border: `1px solid ${isCurrent ? 'rgba(0,212,255,0.4)' : 'rgba(48,54,61,0.6)'}`,
              borderRadius: 6,
              padding: '20px',
            }}>
              <div style={{ fontFamily: "'Orbitron', sans-serif", fontSize: 13, color: isCurrent ? '#00d4ff' : '#e6edf3', marginBottom: 4 }}>
                {plan.label}
              </div>
              <div style={{ fontSize: 18, color: '#e6edf3', marginBottom: 14 }}>{plan.price_formatted}</div>
              <ul style={{ listStyle: 'none', padding: 0, margin: '0 0 16px', fontSize: 12, color: '#8b949e', lineHeight: 1.9 }}>
                <li>✓ {plan.requests_label}</li>
                <li>{plan.webhooks_enabled ? '✓ Webhooks' : '— No webhooks'}</li>
                <li>{plan.can_receive_alerts ? '✓ Conjunction alerts' : '— No alerts'}</li>
                <li>✓ Unlimited satellites</li>
              </ul>
              {isCurrent ? (
                <div style={{ fontSize: 11, color: '#3fb950', textAlign: 'center' }}>CURRENT PLAN</div>
              ) : (
                <button
                  style={{ ...S.btn(true), width: '100%', textAlign: 'center' }}
                  onClick={() => subscribe(plan.key)}
                  disabled={subscribing === plan.key}
                >
                  {subscribing === plan.key ? 'SUBSCRIBING...' : `UPGRADE TO ${plan.label.toUpperCase()}`}
                </button>
              )}
            </div>
          );
        })}
      </div>

      <p style={{ fontSize: 11, color: '#484f58', marginBottom: 28 }}>
        ⚡ Mock billing mode — no charges will be made. Stripe integration coming soon.
      </p>

      {/* ── Payment history ── */}
      {history.length > 0 && (
        <div>
          <p style={{ fontSize: 11, color: '#8b949e', textTransform: 'uppercase', letterSpacing: '0.1em', marginBottom: 12 }}>
            Payment History
          </p>
          <div style={S.card}>
            {history.map((p, i) => (
              <div
                key={p.id}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  padding: '10px 0',
                  borderBottom: i < history.length - 1 ? '1px solid rgba(48,54,61,0.4)' : 'none',
                  fontSize: 12,
                }}
              >
                <div>
                  <div style={{ color: '#e6edf3', marginBottom: 2 }}>{p.description}</div>
                  <div style={{ color: '#484f58', fontSize: 11 }}>
                    {new Date(p.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}
                  </div>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <div style={{ color: p.status === 'refunded' ? '#f85149' : '#3fb950' }}>
                    {p.status === 'refunded' ? '−' : ''}{p.formatted}
                  </div>
                  {p.status !== 'succeeded' && (
                    <div style={{ fontSize: 10, color: '#8b949e', textTransform: 'uppercase' }}>{p.status}</div>
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

// ── Profile Tab ───────────────────────────────────────────────────────────────

function ProfileTab({ user }) {
  const toast                     = useToast();
  const { refreshUser }           = useAuth();
  const [form, setForm]           = useState({ name: user?.name ?? '', email: user?.email ?? '' });
  const [pwForm, setPwForm]       = useState({ current_password: '', password: '', password_confirmation: '' });
  const [loading, setLoading]     = useState(false);
  const [pwLoading, setPwLoading] = useState(false);

  const updateProfile = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      await client.patch('/auth/me', form);
      await refreshUser();
      toast.success('Profile updated.');
    } catch (err) {
      toast.error(err.message ?? 'Update failed.');
    } finally {
      setLoading(false);
    }
  };

  const updatePassword = async (e) => {
    e.preventDefault();
    setPwLoading(true);
    try {
      await client.patch('/auth/password', pwForm);
      setPwForm({ current_password: '', password: '', password_confirmation: '' });
      toast.success('Password updated.');
    } catch (err) {
      toast.error(err.message ?? 'Password update failed.');
    } finally {
      setPwLoading(false);
    }
  };

  return (
    <div className="dash-profile-grid">
      <div style={S.card}>
        <p style={{ fontSize: 11, color: '#8b949e', textTransform: 'uppercase', letterSpacing: '0.1em', marginBottom: 16 }}>
          Profile
        </p>
        <form onSubmit={updateProfile}>
          {[['Name', 'name', 'text'], ['Email', 'email', 'email']].map(([label, field, type]) => (
            <div key={field} style={{ marginBottom: 14 }}>
              <label style={{ display: 'block', fontSize: 11, color: '#8b949e', marginBottom: 5 }}>{label}</label>
              <input
                type={type}
                value={form[field]}
                onChange={(e) => setForm((f) => ({ ...f, [field]: e.target.value }))}
                style={{ ...S.input, width: '100%', boxSizing: 'border-box' }}
              />
            </div>
          ))}
          <button style={S.btn(true)} type="submit" disabled={loading}>
            {loading ? 'SAVING...' : 'SAVE'}
          </button>
        </form>
      </div>

      <div style={S.card}>
        <p style={{ fontSize: 11, color: '#8b949e', textTransform: 'uppercase', letterSpacing: '0.1em', marginBottom: 16 }}>
          Change Password
        </p>
        <form onSubmit={updatePassword}>
          {[
            ['Current Password', 'current_password'],
            ['New Password', 'password'],
            ['Confirm New Password', 'password_confirmation'],
          ].map(([label, field]) => (
            <div key={field} style={{ marginBottom: 14 }}>
              <label style={{ display: 'block', fontSize: 11, color: '#8b949e', marginBottom: 5 }}>{label}</label>
              <input
                type="password"
                value={pwForm[field]}
                onChange={(e) => setPwForm((f) => ({ ...f, [field]: e.target.value }))}
                style={{ ...S.input, width: '100%', boxSizing: 'border-box' }}
              />
            </div>
          ))}
          <button style={S.btn(true)} type="submit" disabled={pwLoading}>
            {pwLoading ? 'UPDATING...' : 'UPDATE'}
          </button>
        </form>
      </div>
    </div>
  );
}

// ── Main Dashboard ────────────────────────────────────────────────────────────

const RESPONSIVE = `
  .dash-page { overflow-x: hidden; }
  .dash-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    border-bottom: 1px solid rgba(48,54,61,0.6);
    padding: 12px 16px;
    box-sizing: border-box;
    width: 100%;
  }
  .dash-header-left { display: flex; align-items: center; gap: 12px; }
  .dash-header-right { display: flex; align-items: center; gap: 8px; min-width: 0; }
  .dash-user-email {
    font-size: 12px;
    color: #8b949e;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 180px;
  }
  .dash-profile-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
  @media (max-width: 600px) {
    .dash-user-email { display: none; }
    .dash-profile-grid { grid-template-columns: 1fr; }
    .dash-main { padding: 20px 16px !important; }
  }
`;

export default function UserDashboard() {
  const { user, logout }    = useAuth();
  const navigate            = useNavigate();
  const [activeTab, setTab] = useState(0);

  return (
    <div style={S.page} className="dash-page">
      <style>{`@import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=JetBrains+Mono:wght@400;500;700&display=swap');`}</style>
      <style>{RESPONSIVE}</style>

      <header className="dash-header">
        <div className="dash-header-left">
          <span style={S.logo}>◈ SATVIEW</span>
          <button style={{ ...S.btn(false), fontSize: 10 }} onClick={() => navigate('/')}>
            ← BACK TO APP
          </button>
        </div>
        <div className="dash-header-right">
          <span className="dash-user-email">{user?.email}</span>
          <button style={{ ...S.btn(false), fontSize: 10, color: '#f85149', borderColor: 'rgba(248,81,73,0.3)' }} onClick={logout}>
            SIGN OUT
          </button>
        </div>
      </header>

      <main style={S.main} className="dash-main">
        <h1 style={{ fontFamily: "'Orbitron', sans-serif", fontSize: 18, fontWeight: 700, letterSpacing: '0.1em', marginBottom: 24 }}>
          DASHBOARD
        </h1>

        {/* Tab bar */}
        <div style={S.tabBar}>
          {TABS.map((tab, i) => (
            <button
              key={tab}
              onClick={() => setTab(i)}
              style={{
                background: 'none',
                border: 'none',
                borderBottom: `2px solid ${i === activeTab ? '#00d4ff' : 'transparent'}`,
                color: i === activeTab ? '#00d4ff' : '#8b949e',
                fontFamily: "'Orbitron', sans-serif",
                fontSize: 11,
                fontWeight: 700,
                letterSpacing: '0.1em',
                padding: '10px 16px',
                cursor: 'pointer',
                marginBottom: -1,
              }}
            >
              {tab}
            </button>
          ))}
        </div>

        {activeTab === 0 && <ApiKeysTab />}
        {activeTab === 1 && <BillingTab user={user} />}
        {activeTab === 2 && <ProfileTab user={user} />}
      </main>
    </div>
  );
}
