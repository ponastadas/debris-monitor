import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import adminClient from '../api/adminClient';

const AdminAuthContext = createContext(null);

export function AdminAuthProvider({ children }) {
  const [admin, setAdmin]     = useState(null);
  const [loading, setLoading] = useState(true);
  const navigate              = useNavigate();

  // Restore admin session on mount
  useEffect(() => {
    const token = localStorage.getItem('dm_admin_token');
    if (token) {
      adminClient.get('/admin/auth/me')
        .then((res) => setAdmin(res.data.data))
        .catch(() => {
          localStorage.removeItem('dm_admin_token');
          setAdmin(null);
        })
        .finally(() => setLoading(false));
    } else {
      setLoading(false);
    }
  }, []);

  /**
   * Step 1 of login: submit credentials.
   * Returns the raw data object — caller inspects it to decide next step:
   * - { mfa_required: true, mfa_token }       → call verifyMfa()
   * - { mfa_setup_required: true, setup_token } → show forced setup flow (no MFA configured)
   * - { token, admin }                          → (not emitted currently — kept for safety)
   */
  const login = useCallback(async (email, password) => {
    const res = await adminClient.post('/admin/auth/login', { email, password });
    return res.data.data;
  }, []);

  /** Persist session after any successful auth step (MFA verify or forced setup finalize). */
  const completeLogin = useCallback((token, adminData) => {
    localStorage.setItem('dm_admin_token', token);
    setAdmin(adminData);
  }, []);

  /**
   * Step 2a — MFA verify: submit the TOTP code (or recovery code) together
   * with the mfa_token from step 1. On success, stores the session token.
   */
  const verifyMfa = useCallback(async (mfaToken, code) => {
    const res = await adminClient.post('/admin/auth/mfa/verify', {
      mfa_token: mfaToken,
      code,
    });
    const { token, admin: adminData } = res.data.data;
    completeLogin(token, adminData);
    return res.data.data; // caller may want recovery_codes if present
  }, [completeLogin]);

  /**
   * Step 2b — forced setup init: exchange a setup_token for a QR code + plain secret.
   */
  const setupMfaInit = useCallback(async (setupToken) => {
    const res = await adminClient.post('/admin/auth/mfa/setup-init', { setup_token: setupToken });
    return res.data.data; // { qr_code, secret }
  }, []);

  /**
   * Step 2c — forced setup finalize: verify the TOTP code, enable MFA, and get a session token.
   */
  const setupMfaFinalize = useCallback(async (setupToken, code) => {
    const res = await adminClient.post('/admin/auth/mfa/setup-finalize', {
      setup_token: setupToken,
      code,
    });
    const { token, admin: adminData } = res.data.data;
    completeLogin(token, adminData);
    return res.data.data; // caller wants recovery_codes
  }, [completeLogin]);

  const logout = useCallback(async () => {
    try {
      await adminClient.post('/admin/auth/logout');
    } catch {
      // Swallow — token may already be invalid
    } finally {
      localStorage.removeItem('dm_admin_token');
      setAdmin(null);
      navigate('/admin/login');
    }
  }, [navigate]);

  return (
    <AdminAuthContext.Provider value={{ admin, loading, login, verifyMfa, setupMfaInit, setupMfaFinalize, logout }}>
      {children}
    </AdminAuthContext.Provider>
  );
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAdminAuth() {
  const ctx = useContext(AdminAuthContext);
  if (!ctx) throw new Error('useAdminAuth must be used inside <AdminAuthProvider>');
  return ctx;
}
