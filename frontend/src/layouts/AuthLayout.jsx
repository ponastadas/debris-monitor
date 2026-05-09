export default function AuthLayout({ children, title, subtitle }) {
  return (
    <div style={{
      minHeight: '100vh',
      background: '#0d1117',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      padding: '24px 16px',
      fontFamily: "'JetBrains Mono', monospace",
    }}>
      {/* Subtle grid background */}
      <div style={{
        position: 'fixed', inset: 0, pointerEvents: 'none',
        backgroundImage: 'linear-gradient(rgba(0,212,255,0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(0,212,255,0.03) 1px, transparent 1px)',
        backgroundSize: '40px 40px',
      }} />

      <div style={{
        position: 'relative',
        width: '100%',
        maxWidth: 420,
      }}>
        {/* Logo */}
        <div style={{ textAlign: 'center', marginBottom: 32 }}>
          <div style={{
            fontFamily: "'Orbitron', sans-serif",
            fontSize: 13,
            fontWeight: 700,
            letterSpacing: '0.3em',
            color: '#00d4ff',
            textTransform: 'uppercase',
            marginBottom: 4,
          }}>
            ◈ SATVIEW
          </div>
          <div style={{ color: '#8b949e', fontSize: 11, letterSpacing: '0.15em' }}>
            ORBITAL CONJUNCTION INTELLIGENCE
          </div>
        </div>

        {/* Card */}
        <div style={{
          background: '#0d1117',
          border: '1px solid rgba(0,212,255,0.2)',
          borderRadius: 8,
          padding: '32px 28px',
          boxShadow: '0 0 40px rgba(0,212,255,0.05), 0 16px 48px rgba(0,0,0,0.4)',
        }}>
          {title && (
            <h1 style={{
              fontFamily: "'Orbitron', sans-serif",
              fontSize: 16,
              fontWeight: 700,
              letterSpacing: '0.15em',
              color: '#e6edf3',
              textTransform: 'uppercase',
              marginBottom: subtitle ? 6 : 24,
              margin: 0,
            }}>
              {title}
            </h1>
          )}
          {subtitle && (
            <p style={{ color: '#8b949e', fontSize: 12, marginTop: 6, marginBottom: 24 }}>
              {subtitle}
            </p>
          )}
          {children}
        </div>
      </div>

      {/* Font imports */}
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=JetBrains+Mono:wght@400;500;700&display=swap');
      `}</style>
    </div>
  );
}
