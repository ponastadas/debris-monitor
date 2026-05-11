import { Navigate } from 'react-router-dom';
import { useAdminAuth } from '../contexts/AdminAuthContext';

export default function AdminRoute({ children }) {
  const { admin, loading } = useAdminAuth();

  if (loading) {
    return (
      <div style={{
        minHeight: '100vh', background: '#0d1117',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        color: '#00d4ff', fontFamily: "'JetBrains Mono', monospace", fontSize: 13,
      }}>
        AUTHENTICATING...
      </div>
    );
  }

  if (!admin) return <Navigate to="/admin/login" replace />;

  return children;
}
