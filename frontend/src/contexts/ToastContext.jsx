import { createContext, useCallback, useContext, useState } from 'react';

const ToastContext = createContext(null);

let toastIdCounter = 0;

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);

  const addToast = useCallback((message, type = 'info', duration = 4000) => {
    const id = ++toastIdCounter;
    setToasts((prev) => [...prev, { id, message, type }]);
    if (duration > 0) {
      setTimeout(() => removeToast(id), duration);
    }
    return id;
  }, []);

  const removeToast = useCallback((id) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  const toast = {
    success: (msg, dur) => addToast(msg, 'success', dur),
    error:   (msg, dur) => addToast(msg, 'error', dur),
    warn:    (msg, dur) => addToast(msg, 'warn', dur),
    info:    (msg, dur) => addToast(msg, 'info', dur),
  };

  const COLOR = {
    success: '#3fb950',
    error:   '#f85149',
    warn:    '#d29922',
    info:    '#00d4ff',
  };

  return (
    <ToastContext.Provider value={toast}>
      {children}
      <div style={{
        position: 'fixed', bottom: 24, right: 24,
        zIndex: 9999, display: 'flex', flexDirection: 'column', gap: 8,
        maxWidth: 360,
      }}>
        {toasts.map((t) => (
          <div key={t.id} style={{
            background: '#161b22',
            border: `1px solid ${COLOR[t.type]}`,
            borderLeft: `4px solid ${COLOR[t.type]}`,
            borderRadius: 6,
            padding: '10px 14px',
            color: '#e6edf3',
            fontFamily: "'JetBrains Mono', monospace",
            fontSize: 13,
            display: 'flex', alignItems: 'flex-start', gap: 10,
            boxShadow: `0 4px 20px rgba(0,0,0,0.4)`,
            animation: 'slideIn 0.2s ease',
          }}>
            <span style={{ color: COLOR[t.type], marginTop: 1 }}>
              {t.type === 'success' ? '✓' : t.type === 'error' ? '✕' : t.type === 'warn' ? '⚠' : 'ℹ'}
            </span>
            <span style={{ flex: 1 }}>{t.message}</span>
            <button onClick={() => removeToast(t.id)} style={{
              background: 'none', border: 'none', color: '#8b949e',
              cursor: 'pointer', fontSize: 16, lineHeight: 1, padding: 0,
            }}>×</button>
          </div>
        ))}
      </div>
      <style>{`@keyframes slideIn { from { opacity:0; transform:translateX(20px) } to { opacity:1; transform:translateX(0) } }`}</style>
    </ToastContext.Provider>
  );
}

export function useToast() {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast must be used inside <ToastProvider>');
  return ctx;
}
