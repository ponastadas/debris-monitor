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

  const login = useCallback(async (email, password) => {
    const res = await adminClient.post('/admin/auth/login', { email, password });
    const { token, admin: adminData } = res.data.data;
    localStorage.setItem('dm_admin_token', token);
    setAdmin(adminData);
    return adminData;
  }, []);

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
    <AdminAuthContext.Provider value={{ admin, loading, login, logout }}>
      {children}
    </AdminAuthContext.Provider>
  );
}

export function useAdminAuth() {
  const ctx = useContext(AdminAuthContext);
  if (!ctx) throw new Error('useAdminAuth must be used inside <AdminAuthProvider>');
  return ctx;
}
