import { useEffect, useRef, useState } from 'react';

const NAV_STYLE = `
  .dm-navbar {
    display: flex;
    align-items: center;
    height: 44px;
    padding: 0 16px;
    background: rgba(2, 10, 22, 0.98);
    border-bottom: 1px solid rgba(0,212,255,0.12);
    flex-shrink: 0;
    position: relative;
    z-index: 100;
    font-family: 'JetBrains Mono', monospace;
  }

  .dm-navbar-brand {
    font-family: 'Orbitron', sans-serif;
    font-size: 9px;
    font-weight: 900;
    letter-spacing: 3px;
    color: #00d4ff;
    white-space: nowrap;
    margin-right: 12px;
    flex-shrink: 0;
  }

  .dm-navbar-tabs {
    display: flex;
    gap: 6px;
    flex: 1;
    justify-content: center;
  }

  .dm-navbar-auth {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-shrink: 0;
  }

  .dm-hamburger {
    display: none;
    background: none;
    border: 1px solid rgba(0,212,255,0.25);
    border-radius: 4px;
    color: rgba(0,212,255,0.7);
    cursor: pointer;
    padding: 5px 10px;
    font-size: 14px;
    line-height: 1;
    margin-left: auto;
    font-family: monospace;
    transition: border-color 0.15s, color 0.15s;
  }

  .dm-hamburger:hover { border-color: rgba(0,212,255,0.55); color: #00d4ff; }

  .dm-mobile-menu {
    position: absolute;
    top: 44px;
    left: 0;
    right: 0;
    background: rgba(2, 8, 20, 0.99);
    border-bottom: 1px solid rgba(0,212,255,0.12);
    flex-direction: column;
    z-index: 200;
    display: none;
    box-shadow: 0 8px 24px rgba(0,0,0,0.6);
  }

  .dm-mobile-menu.open { display: flex; }

  .dm-mobile-item {
    padding: 12px 20px;
    font-size: 10px;
    letter-spacing: 2px;
    color: rgba(0,212,255,0.55);
    cursor: pointer;
    background: none;
    border: none;
    text-align: left;
    font-family: 'JetBrains Mono', monospace;
    text-decoration: none;
    display: block;
    width: 100%;
    box-sizing: border-box;
    transition: background 0.1s, color 0.1s;
    border-left: 2px solid transparent;
  }

  .dm-mobile-item:hover {
    background: rgba(0,212,255,0.07);
    color: rgba(0,212,255,0.85);
  }

  .dm-mobile-item.dm-active-view {
    color: #00d4ff;
    border-left-color: #00d4ff;
  }

  .dm-mobile-item.dm-danger { color: rgba(248,81,73,0.65); }
  .dm-mobile-item.dm-danger:hover { background: rgba(248,81,73,0.07); color: rgba(248,81,73,0.9); }

  .dm-mobile-divider {
    height: 1px;
    background: rgba(0,212,255,0.08);
    margin: 4px 16px;
  }

  @media (max-width: 768px) {
    .dm-navbar-tabs { display: none; }
    .dm-navbar-auth { display: none; }
    .dm-hamburger { display: block; }
  }
`;

const tabStyle = (active) => ({
  padding: '5px 12px',
  fontSize: 9,
  letterSpacing: 2,
  fontFamily: "'JetBrains Mono', monospace",
  background: active ? 'rgba(0,212,255,0.15)' : 'rgba(0,212,255,0.05)',
  border: `1px solid ${active ? '#00d4ff' : 'rgba(0,212,255,0.2)'}`,
  borderRadius: 4,
  color: active ? '#00d4ff' : 'rgba(0,212,255,0.5)',
  cursor: 'pointer',
  transition: 'all 0.15s',
  whiteSpace: 'nowrap',
});

const authBtnStyle = (primary) => ({
  padding: '5px 12px',
  fontSize: 9,
  letterSpacing: 2,
  fontFamily: "'JetBrains Mono', monospace",
  background: primary ? 'rgba(0,212,255,0.12)' : 'rgba(0,212,255,0.03)',
  border: `1px solid ${primary ? '#00d4ff' : 'rgba(0,212,255,0.2)'}`,
  borderRadius: 4,
  color: primary ? '#00d4ff' : 'rgba(0,212,255,0.5)',
  cursor: 'pointer',
  transition: 'all 0.15s',
  textDecoration: 'none',
  display: 'inline-block',
  lineHeight: 'normal',
  whiteSpace: 'nowrap',
});

const VIEWS = [
  { key: 'catalog', label: 'CATALOG' },
  { key: 'tracker', label: 'TRACKER' },
  { key: 'alerts',  label: 'ALERTS'  },
];

export default function NavBar({ view, onViewChange, user, logout }) {
  const [menuOpen, setMenuOpen] = useState(false);
  const navRef = useRef(null);

  useEffect(() => {
    if (!menuOpen) return;
    function handler(e) {
      if (navRef.current && !navRef.current.contains(e.target)) {
        setMenuOpen(false);
      }
    }
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [menuOpen]);

  function changeView(key) {
    onViewChange(key);
    setMenuOpen(false);
  }

  function handleLogout() {
    logout();
    setMenuOpen(false);
  }

  return (
    <>
      <style>{NAV_STYLE}</style>
      <nav className="dm-navbar" ref={navRef}>
        <span className="dm-navbar-brand">DEBRIS.MONITOR</span>

        <div className="dm-navbar-tabs">
          {VIEWS.map(({ key, label }) => (
            <button key={key} style={tabStyle(view === key)} onClick={() => changeView(key)}>
              {label}
            </button>
          ))}
        </div>

        <div className="dm-navbar-auth">
          {user ? (
            <>
              <a href="/dashboard" style={authBtnStyle(true)}>DASHBOARD</a>
              <button
                onClick={handleLogout}
                style={{ ...authBtnStyle(false), border: '1px solid rgba(248,81,73,0.25)', color: 'rgba(248,81,73,0.6)' }}
              >
                SIGN OUT
              </button>
            </>
          ) : (
            <>
              <a href="/register" style={authBtnStyle(true)}>REGISTER</a>
              <a href="/login"    style={authBtnStyle(false)}>SIGN IN</a>
            </>
          )}
        </div>

        <button
          className="dm-hamburger"
          onClick={() => setMenuOpen(o => !o)}
          aria-label={menuOpen ? 'Close menu' : 'Open menu'}
        >
          {menuOpen ? '✕' : '☰'}
        </button>

        <div className={`dm-mobile-menu${menuOpen ? ' open' : ''}`}>
          {VIEWS.map(({ key, label }) => (
            <button
              key={key}
              className={`dm-mobile-item${view === key ? ' dm-active-view' : ''}`}
              onClick={() => changeView(key)}
            >
              {label}
            </button>
          ))}
          <div className="dm-mobile-divider" />
          {user ? (
            <>
              <a href="/dashboard" className="dm-mobile-item" onClick={() => setMenuOpen(false)}>
                DASHBOARD
              </a>
              <button className="dm-mobile-item dm-danger" onClick={handleLogout}>
                SIGN OUT
              </button>
            </>
          ) : (
            <>
              <a href="/register" className="dm-mobile-item" onClick={() => setMenuOpen(false)}>
                REGISTER
              </a>
              <a href="/login" className="dm-mobile-item" onClick={() => setMenuOpen(false)}>
                SIGN IN
              </a>
            </>
          )}
        </div>
      </nav>
    </>
  );
}
