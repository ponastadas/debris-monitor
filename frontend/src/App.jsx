import { useEffect, useState } from 'react';
import { BrowserRouter, Navigate, Route, Routes, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import ReactGA from 'react-ga4';

import { AuthProvider, useAuth } from './contexts/AuthContext';
import { ToastProvider } from './contexts/ToastContext';
import ProtectedRoute from './components/ProtectedRoute';
import AdminRoute from './components/AdminRoute';
import AdminLayout from './layouts/AdminLayout';

// Auth pages
import Login from './pages/Login';
import Register from './pages/Register';
import ForgotPassword from './pages/ForgotPassword';
import ResetPassword from './pages/ResetPassword';

// App pages
import UserDashboard from './pages/UserDashboard';

// Admin pages
import AdminDashboard from './pages/admin/AdminDashboard';
import AdminUsers from './pages/admin/AdminUsers';
import AdminSubscriptions from './pages/admin/AdminSubscriptions';
import AdminPayments from './pages/admin/AdminPayments';
import AdminApiKeys from './pages/admin/AdminApiKeys';

// Existing globe/tracker views
import ConjunctionAlerts from './ConjunctionAlerts';
import DebrisMonitor from './DebrisMonitor';
import SatelliteTracker from './satellite-tracker';

// Initialise guest session ID at module load time (runs once when the bundle loads).
// Sent as X-Guest-ID on all unauthenticated API requests for per-guest quota tracking.
if (!localStorage.getItem('dm_guest_id')) {
  localStorage.setItem('dm_guest_id', crypto.randomUUID());
}

// ── GA4 page tracking ────────────────────────────────────────────────────────

function RouteTracker() {
  const location = useLocation();
  useEffect(() => {
    if (import.meta.env.VITE_GA_MEASUREMENT_ID) {
      ReactGA.send({ hitType: 'pageview', page: location.pathname + location.search });
    }
  }, [location]);
  return null;
}

// ── Impersonation token handler ──────────────────────────────────────────────
// When admin opens ?impersonate=<token> the app picks it up, stores it, and reloads clean.

function ImpersonationHandler({ children }) {
  const [params] = useSearchParams();
  const navigate = useNavigate();

  useEffect(() => {
    const token = params.get('impersonate');
    if (token) {
      localStorage.setItem('dm_token', token);
      navigate('/', { replace: true });
      window.location.reload();
    }
  }, []);

  return children;
}

// ── Alerts gate — shown to unauthenticated visitors ──────────────────────────

function AlertsAuthGate() {
  return (
    <div style={{
      display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
      height: '100vh', background: '#0d1117', gap: 16,
      fontFamily: "'JetBrains Mono', monospace",
    }}>
      <div style={{ color: '#00d4ff', fontSize: 11, letterSpacing: 3, fontFamily: "'Orbitron', sans-serif" }}>
        CONJUNCTION ALERTS
      </div>
      <div style={{ color: 'rgba(0,212,255,0.5)', fontSize: 12, textAlign: 'center', maxWidth: 340, lineHeight: 1.6 }}>
        Alerts notify you when tracked satellites have upcoming conjunctions.
        Sign in or create a free account to get started.
      </div>
      <div style={{ display: 'flex', gap: 12, marginTop: 8 }}>
        <a href="/register" style={{
          padding: '9px 22px', fontSize: 11, letterSpacing: 2,
          background: 'rgba(0,212,255,0.12)', border: '1px solid #00d4ff',
          borderRadius: 4, color: '#00d4ff', textDecoration: 'none',
        }}>CREATE FREE ACCOUNT</a>
        <a href="/login" style={{
          padding: '9px 22px', fontSize: 11, letterSpacing: 2,
          background: 'transparent', border: '1px solid rgba(0,212,255,0.3)',
          borderRadius: 4, color: 'rgba(0,212,255,0.6)', textDecoration: 'none',
        }}>SIGN IN</a>
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
  const { user, logout }      = useAuth();
  const [view, setView]       = useState('catalog');
  const [trackId, setTrackId] = useState('25544');

  function handleTrack(noradId) {
    setTrackId(noradId);
    setView('tracker');
  }

  return (
    <div style={{ position: 'relative', width: '100vw', height: '100vh' }}>

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
      {view === 'alerts'  && (user ? <ConjunctionAlerts onTrack={handleTrack} /> : <AlertsAuthGate />)}
    </div>
  );
}

// ── Root ─────────────────────────────────────────────────────────────────────

export default function App() {
  return (
    <BrowserRouter>
      <ToastProvider>
        <AuthProvider>
          <RouteTracker />
          <Routes>
            {/* Public auth routes */}
            <Route path="/login"            element={<Login />} />
            <Route path="/register"         element={<Register />} />
            <Route path="/forgot-password"  element={<ForgotPassword />} />
            <Route path="/reset-password"   element={<ResetPassword />} />

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

            {/* Admin panel — admin role required */}
            <Route path="/admin" element={
              <AdminRoute><AdminLayout /></AdminRoute>
            }>
              <Route index element={<AdminDashboard />} />
              <Route path="users"         element={<AdminUsers />} />
              <Route path="subscriptions" element={<AdminSubscriptions />} />
              <Route path="payments"      element={<AdminPayments />} />
              <Route path="api-keys"      element={<AdminApiKeys />} />
            </Route>

            {/* Catch-all */}
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </AuthProvider>
      </ToastProvider>
    </BrowserRouter>
  );
}
