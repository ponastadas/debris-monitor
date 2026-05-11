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
    flex: 1;
  }

  .dm-hamburger {
    display: flex;
    align-items: center;
    gap: 7px;
    background: rgba(0,212,255,0.06);
    border: 1px solid rgba(0,212,255,0.5);
    border-radius: 4px;
    color: #00d4ff;
    cursor: pointer;
    padding: 6px 14px;
    font-size: 11px;
    letter-spacing: 2px;
    line-height: 1;
    font-family: 'JetBrains Mono', monospace;
    transition: background 0.15s, border-color 0.15s;
  }

  .dm-hamburger:hover {
    background: rgba(0,212,255,0.12);
    border-color: #00d4ff;
  }

  .dm-hamburger-icon {
    font-size: 15px;
    line-height: 1;
  }

  .dm-menu {
    position: absolute;
    top: 44px;
    right: 0;
    width: 200px;
    background: rgba(2, 8, 20, 0.99);
    border: 1px solid rgba(0,212,255,0.12);
    border-top: none;
    flex-direction: column;
    z-index: 200;
    display: none;
    box-shadow: 0 8px 24px rgba(0,0,0,0.6);
  }

  .dm-menu.open { display: flex; }

  .dm-menu-item {
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

  .dm-menu-item:hover {
    background: rgba(0,212,255,0.07);
    color: rgba(0,212,255,0.85);
  }

  .dm-menu-item.dm-active-view {
    color: #00d4ff;
    border-left-color: #00d4ff;
  }

  .dm-menu-item.dm-danger { color: rgba(248,81,73,0.65); }
  .dm-menu-item.dm-danger:hover { background: rgba(248,81,73,0.07); color: rgba(248,81,73,0.9); }

  .dm-menu-divider {
    height: 1px;
    background: rgba(0,212,255,0.08);
    margin: 4px 16px;
  }
`;

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
        <span className="dm-navbar-brand">SATVIEW.EU</span>

        <button
          className="dm-hamburger"
          onClick={() => setMenuOpen(o => !o)}
          aria-label={menuOpen ? 'Close menu' : 'Open menu'}
        >
          <span className="dm-hamburger-icon">{menuOpen ? '✕' : '☰'}</span>
          <span>{menuOpen ? 'CLOSE' : 'MENU'}</span>
        </button>

        <div className={`dm-menu${menuOpen ? ' open' : ''}`}>
          {VIEWS.map(({ key, label }) => (
            <button
              key={key}
              className={`dm-menu-item${view === key ? ' dm-active-view' : ''}`}
              onClick={() => changeView(key)}
            >
              {label}
            </button>
          ))}
          <div className="dm-menu-divider" />
          {user ? (
            <>
              <a href="/dashboard" className="dm-menu-item" onClick={() => setMenuOpen(false)}>
                DASHBOARD
              </a>
              <button className="dm-menu-item dm-danger" onClick={handleLogout}>
                SIGN OUT
              </button>
            </>
          ) : (
            <>
              <a href="/register" className="dm-menu-item" onClick={() => setMenuOpen(false)}>
                REGISTER
              </a>
              <a href="/login" className="dm-menu-item" onClick={() => setMenuOpen(false)}>
                SIGN IN
              </a>
            </>
          )}
        </div>
      </nav>
    </>
  );
}
