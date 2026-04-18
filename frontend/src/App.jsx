import { useEffect, useState } from 'react';
import { BrowserRouter, Navigate, Outlet, Route, Routes, useLocation } from 'react-router-dom';
import ReactGA from 'react-ga4';

import { AuthProvider, useAuth } from './contexts/AuthContext';
import { AdminAuthProvider } from './contexts/AdminAuthContext';
import { ToastProvider } from './contexts/ToastContext';
import { CookieConsentProvider } from './contexts/CookieConsentContext';
import ProtectedRoute from './components/ProtectedRoute';
import AdminRoute from './components/AdminRoute';
import CookieBanner from './components/CookieBanner';
import Footer from './components/Footer';
import AdminLayout from './layouts/AdminLayout';

// Auth pages
import Login from './pages/Login';
import Register from './pages/Register';
import ForgotPassword from './pages/ForgotPassword';
import ResetPassword from './pages/ResetPassword';

// App pages
import UserDashboard from './pages/UserDashboard';
import Page from './pages/Page';

// Admin pages
import AdminLogin from './pages/admin/AdminLogin';
import AdminDashboard from './pages/admin/AdminDashboard';
import AdminUsers from './pages/admin/AdminUsers';
import AdminUserDetail from './pages/admin/AdminUserDetail';
import AdminSubscriptions from './pages/admin/AdminSubscriptions';
import AdminPayments from './pages/admin/AdminPayments';
import AdminApiKeys from './pages/admin/AdminApiKeys';
import AdminAuditLog from './pages/admin/AdminAuditLog';
import AdminAccount from './pages/admin/AdminAccount';
import AdminPages from './pages/admin/AdminPages';
import AdminPageEdit from './pages/admin/AdminPageEdit';

// Existing globe/tracker views
import ConjunctionAlerts from './ConjunctionAlerts';
import DebrisMonitor from './DebrisMonitor';
import SatelliteTracker from './satellite-tracker';

// Initialise guest session ID at module load time (runs once when the bundle loads).
// Sent as X-Guest-ID on all unauthenticated API requests for per-guest quota tracking.
if (!localStorage.getItem('dm_guest_id')) {
  localStorage.setItem('dm_guest_id', crypto.randomUUID());
}

// ── GA4 page tracking (only when analytics consent is active) ───────────────

function RouteTracker() {
  const location = useLocation();
  useEffect(() => {
    const consent = (() => {
      try { return JSON.parse(localStorage.getItem('dm_cookie_consent')); } catch { return null; }
    })();
    if (import.meta.env.VITE_GA_MEASUREMENT_ID && consent?.analytics) {
      ReactGA.send({ hitType: 'pageview', page: location.pathname + location.search });
    }
  }, [location]);
  return null;
}

// ── Impersonation token handler ──────────────────────────────────────────────
// Admin panel writes the token to localStorage('dm_impersonate_pending') then opens
// the app in a new tab. On load this handler picks up the pending token, moves it
// to sessionStorage (tab-scoped, gone when tab closes) and clears the pending key.
// The token never appears in the URL.

function ImpersonationHandler({ children }) {
  useEffect(() => {
    const token = localStorage.getItem('dm_impersonate_pending');
    if (token) {
      localStorage.removeItem('dm_impersonate_pending');
      sessionStorage.setItem('dm_token', token);
      window.location.reload();
    }
  }, []);

  return children;
}

// ── Alerts gates ─────────────────────────────────────────────────────────────

const gateWrap = {
  display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
  height: '100%', background: '#0d1117', gap: 16,
  fontFamily: "'JetBrains Mono', monospace",
};

const gateTitle = {
  color: '#00d4ff', fontSize: 11, letterSpacing: 3,
  fontFamily: "'Orbitron', sans-serif",
};

const gateBody = {
  color: 'rgba(0,212,255,0.5)', fontSize: 12, textAlign: 'center',
  maxWidth: 380, lineHeight: 1.8,
};

const gateBtn = (primary) => ({
  padding: '9px 22px', fontSize: 11, letterSpacing: 2,
  background: primary ? 'rgba(0,212,255,0.12)' : 'transparent',
  border: `1px solid ${primary ? '#00d4ff' : 'rgba(0,212,255,0.3)'}`,
  borderRadius: 4,
  color: primary ? '#00d4ff' : 'rgba(0,212,255,0.6)',
  textDecoration: 'none',
  fontFamily: "'JetBrains Mono', monospace",
});

/** Guest: not signed in at all */
function AlertsAuthGate() {
  return (
    <div style={gateWrap}>
      <div style={gateTitle}>CONJUNCTION ALERTS</div>
      <div style={gateBody}>
        Ongoing monitoring for your watched satellites.<br />
        Sign in or create a free account to get started.
      </div>
      <div style={{ display: 'flex', gap: 12, marginTop: 8 }}>
        <a href="/register" style={gateBtn(true)}>CREATE FREE ACCOUNT</a>
        <a href="/login"    style={gateBtn(false)}>SIGN IN</a>
      </div>
    </div>
  );
}

/** Signed in but on a free plan — show upgrade prompt */
function AlertsUpgradeGate({ plan }) {
  return (
    <div style={gateWrap}>
      <div style={gateTitle}>CONJUNCTION ALERTS</div>
      <div style={gateBody}>
        Real-time conjunction monitoring for your watched satellites.<br />
        Available on Starter, Pro, and Enterprise plans.
      </div>
      <div style={{
        background: 'rgba(0,212,255,0.05)', border: '1px solid rgba(0,212,255,0.15)',
        borderRadius: 6, padding: '14px 24px', textAlign: 'center',
      }}>
        <div style={{ color: '#8b949e', fontSize: 9, letterSpacing: 2, textTransform: 'uppercase', marginBottom: 6 }}>
          Current plan
        </div>
        <div style={{ color: '#e6edf3', fontSize: 13, letterSpacing: 2, fontFamily: "'Orbitron', sans-serif" }}>
          {(plan ?? 'FREE').toUpperCase()}
        </div>
      </div>
      <div style={{ display: 'flex', gap: 12, marginTop: 4 }}>
        <a href="/dashboard" style={gateBtn(true)}>VIEW PLANS</a>
      </div>
    </div>
  );
}

// ── Auth nav button style ─────────────────────────────────────────────────────

const authNavBtn = (primary) => ({
  padding: '6px 14px',
  fontSize: 9,
  letterSpacing: 2,
  fontFamily: "'JetBrains Mono', monospace",
  background: primary ? 'rgba(0,212,255,0.12)' : 'rgba(0,212,255,0.03)',
  border: `1px solid ${primary ? '#00d4ff' : 'rgba(0,212,255,0.2)'}`,
  borderRadius: 4,
  color: primary ? '#00d4ff' : 'rgba(0,212,255,0.5)',
  cursor: 'pointer',
  textDecoration: 'none',
  display: 'inline-block',
  lineHeight: 'normal',
});

// ── Main Globe App (existing view-switcher) ───────────────────────────────────

function MainApp() {
  const { user, loading, logout } = useAuth();
  const [view, setView]       = useState('catalog');
  const [trackId, setTrackId] = useState('25544');

  function handleTrack(noradId) {
    setTrackId(noradId);
    setView('tracker');
  }

  return (
    <div style={{ position: 'relative', width: '100vw', height: '100vh', display: 'flex', flexDirection: 'column' }}>

      {/* Globe canvas fills remaining space */}
      <div style={{ flex: 1, position: 'relative' }}>

        {/* Auth buttons — top left corner */}
        <div style={{
          position: 'absolute', top: 16, left: 16, zIndex: 100,
          display: 'flex', gap: 8, alignItems: 'center',
        }}>
          {user ? (
            <>
              <a href="/dashboard" style={authNavBtn(true)}>DASHBOARD</a>
              <button onClick={logout} style={{ ...authNavBtn(false), border: '1px solid rgba(248,81,73,0.25)', color: 'rgba(248,81,73,0.6)' }}>
                SIGN OUT
              </button>
            </>
          ) : (
            <>
              <a href="/register" style={authNavBtn(true)}>REGISTER</a>
              <a href="/login"    style={authNavBtn(false)}>SIGN IN</a>
            </>
          )}
        </div>

        {/* View toggle — top right, clears the tracker panel */}
        <div style={{
          position: 'absolute', top: 16, right: 360, zIndex: 100,
          display: 'flex', gap: 8, fontFamily: "'JetBrains Mono', monospace",
        }}>
          {[
            { key: 'catalog', label: 'CATALOG' },
            { key: 'tracker', label: 'TRACKER' },
            { key: 'alerts',  label: 'ALERTS'  },
          ].map(({ key, label }) => (
            <button
              key={key}
              onClick={() => setView(key)}
              style={{
                padding: '6px 14px', fontSize: 9, letterSpacing: 2,
                background: view === key ? 'rgba(0,212,255,0.15)' : 'rgba(0,212,255,0.05)',
                border: `1px solid ${view === key ? '#00d4ff' : 'rgba(0,212,255,0.2)'}`,
                borderRadius: 4,
                color: view === key ? '#00d4ff' : 'rgba(0,212,255,0.5)',
                cursor: 'pointer',
              }}
            >
              {label}
            </button>
          ))}
        </div>

        {view === 'catalog' && <DebrisMonitor onTrack={handleTrack} />}
        {view === 'tracker' && <SatelliteTracker initialNoradId={trackId} />}
        {view === 'alerts' && (
          loading
            ? (
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%', background: '#020810' }}>
                <div style={{ color: 'rgba(0,212,255,0.4)', fontFamily: "'JetBrains Mono', monospace", fontSize: 10, letterSpacing: 2 }}>
                  LOADING…
                </div>
              </div>
            )
            : (user
                ? (user.can_view_alerts
                    ? <ConjunctionAlerts onTrack={handleTrack} />
                    : <AlertsUpgradeGate plan={user.subscription_plan} />)
                : <AlertsAuthGate />)
        )}
      </div>

      <Footer />
      <CookieBanner />
    </div>
  );
}

// ── Root ─────────────────────────────────────────────────────────────────────

export default function App() {
  return (
    <BrowserRouter>
      <CookieConsentProvider>
        <ToastProvider>
          <AuthProvider>
            <RouteTracker />
            <Routes>
              {/* Public auth routes */}
              <Route path="/login"            element={<Login />} />
              <Route path="/register"         element={<Register />} />
              <Route path="/forgot-password"  element={<ForgotPassword />} />
              <Route path="/reset-password"   element={<ResetPassword />} />

              {/* Public CMS pages */}
              <Route path="/pages/:slug" element={<Page />} />

              {/* Protected user routes */}
              <Route path="/dashboard" element={
                <ProtectedRoute><UserDashboard /></ProtectedRoute>
              } />

              {/* Main globe app — public, no auth required */}
              <Route path="/" element={
                <ImpersonationHandler>
                  <MainApp />
                </ImpersonationHandler>
              } />

              {/* Admin panel — single AdminAuthProvider wraps login + protected routes */}
              <Route element={<AdminAuthProvider><Outlet /></AdminAuthProvider>}>
                <Route path="/admin/login" element={<AdminLogin />} />
                <Route path="/admin" element={<AdminRoute><AdminLayout /></AdminRoute>}>
                  <Route index element={<AdminDashboard />} />
                  <Route path="users"              element={<AdminUsers />} />
                  <Route path="users/:id"          element={<AdminUserDetail />} />
                  <Route path="subscriptions"      element={<AdminSubscriptions />} />
                  <Route path="payments"           element={<AdminPayments />} />
                  <Route path="api-keys"           element={<AdminApiKeys />} />
                  <Route path="pages"              element={<AdminPages />} />
                  <Route path="pages/new"          element={<AdminPageEdit />} />
                  <Route path="pages/:id/edit"     element={<AdminPageEdit />} />
                  <Route path="audit-log"          element={<AdminAuditLog />} />
                  <Route path="account"            element={<AdminAccount />} />
                </Route>
              </Route>

              {/* Catch-all */}
              <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
          </AuthProvider>
        </ToastProvider>
      </CookieConsentProvider>
    </BrowserRouter>
  );
}
