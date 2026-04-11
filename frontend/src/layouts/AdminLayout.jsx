import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';

const NAV_ITEMS = [
  { to: '/admin',              label: 'Dashboard', icon: '⬡', end: true },
  { to: '/admin/users',        label: 'Users',     icon: '◉' },
  { to: '/admin/subscriptions',label: 'Subscriptions', icon: '◈' },
  { to: '/admin/payments',     label: 'Payments',  icon: '◆' },
  { to: '/admin/api-keys',     label: 'API Keys',  icon: '◐' },
];

export default function AdminLayout() {
  const { user, logout } = useAuth();
  const navigate         = useNavigate();

  return (
    <div style={{
      minHeight: '100vh',
      background: '#0d1117',
      display: 'flex',
      fontFamily: "'JetBrains Mono', monospace",
      color: '#e6edf3',
    }}>
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=JetBrains+Mono:wght@400;500;700&display=swap');
        .admin-nav-link { display:flex; align-items:center; gap:10px; padding:10px 16px; border-radius:4px; text-decoration:none; color:#8b949e; font-size:12px; letter-spacing:0.08em; transition:all 0.12s; }
        .admin-nav-link:hover { background:rgba(0,212,255,0.06); color:#e6edf3; }
        .admin-nav-link.active { background:rgba(0,212,255,0.1); color:#00d4ff; border-left:2px solid #00d4ff; }
      `}</style>

      {/* Sidebar */}
      <aside style={{
        width: 200,
        minHeight: '100vh',
        background: '#010409',
        borderRight: '1px solid rgba(48,54,61,0.6)',
        display: 'flex',
        flexDirection: 'column',
        flexShrink: 0,
      }}>
        {/* Logo */}
        <div style={{
          padding: '20px 16px 16px',
          borderBottom: '1px solid rgba(48,54,61,0.6)',
        }}>
          <div style={{
            fontFamily: "'Orbitron', sans-serif",
            fontSize: 11,
            fontWeight: 700,
            letterSpacing: '0.25em',
            color: '#00d4ff',
          }}>
            ◈ DEBRIS MONITOR
          </div>
          <div style={{ fontSize: 9, color: '#484f58', letterSpacing: '0.15em', marginTop: 3 }}>
            ADMIN PANEL
          </div>
        </div>

        {/* Nav */}
        <nav style={{ flex: 1, padding: '12px 8px' }}>
          {NAV_ITEMS.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className="admin-nav-link"
            >
              <span style={{ fontSize: 14 }}>{item.icon}</span>
              {item.label}
            </NavLink>
          ))}
        </nav>

        {/* Footer */}
        <div style={{ padding: '12px 8px', borderTop: '1px solid rgba(48,54,61,0.6)' }}>
          <button
            onClick={() => navigate('/')}
            className="admin-nav-link"
            style={{
              width: '100%', background: 'none', border: 'none',
              cursor: 'pointer', textAlign: 'left', display: 'flex',
              alignItems: 'center', gap: 10, padding: '10px 16px',
              borderRadius: 4, color: '#8b949e', fontSize: 12,
              letterSpacing: '0.08em',
            }}
          >
            <span>←</span> App
          </button>
          <button
            onClick={logout}
            style={{
              width: '100%', background: 'none', border: 'none',
              cursor: 'pointer', textAlign: 'left', display: 'flex',
              alignItems: 'center', gap: 10, padding: '10px 16px',
              borderRadius: 4, color: '#f85149', fontSize: 12,
              letterSpacing: '0.08em',
            }}
          >
            <span>⏻</span> Sign Out
          </button>
        </div>
      </aside>

      {/* Main content */}
      <main style={{ flex: 1, overflow: 'auto', padding: '28px 32px' }}>
        <Outlet />
      </main>
    </div>
  );
}
