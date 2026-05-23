import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import ReactGA from 'react-ga4';
import client from '../api/client';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser]       = useState(null);
  const [loading, setLoading] = useState(true); // true until initial session check completes
  const navigate              = useNavigate();

  // Restore session on mount. Impersonation tokens are tab-scoped in sessionStorage;
  // normal tokens live in localStorage. Either is enough to hydrate the session.
  useEffect(() => {
    const token = sessionStorage.getItem('dm_token') || localStorage.getItem('dm_token');
    if (token) {
      client.get('/auth/me')
        .then((res) => setUser(res.data.data))
        .catch(() => {
          localStorage.removeItem('dm_token');
          setUser(null);
        })
        .finally(() => setLoading(false));
    } else {
      setLoading(false);
    }
  }, []);

  const login = useCallback(async (email, password) => {
    const res = await client.post('/auth/login', { email, password });
    const { token, user: userData } = res.data.data;
    localStorage.setItem('dm_token', token);
    setUser(userData);
    ReactGA.event({ category: 'Auth', action: 'login' });
    return userData;
  }, []);

  const register = useCallback(async (name, email, password, passwordConfirmation) => {
    const res = await client.post('/auth/register', {
      name,
      email,
      password,
      password_confirmation: passwordConfirmation,
    });
    const { token, user: userData } = res.data.data;
    localStorage.setItem('dm_token', token);
    setUser(userData);
    ReactGA.event({ category: 'Auth', action: 'sign_up' });
    return userData;
  }, []);

  const logout = useCallback(async () => {
    try {
      await client.post('/auth/logout');
    } catch {
      // Swallow — token may already be invalid
    } finally {
      sessionStorage.removeItem('dm_token');
      localStorage.removeItem('dm_token');
      setUser(null);
      navigate('/login');
    }
  }, [navigate]);

  const refreshUser = useCallback(async () => {
    const res = await client.get('/auth/me');
    setUser(res.data.data);
    return res.data.data;
  }, []);

  return (
    <AuthContext.Provider value={{ user, loading, login, register, logout, refreshUser }}>
      {children}
    </AuthContext.Provider>
  );
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
  return ctx;
}
