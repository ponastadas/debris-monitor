import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useToast } from '../../contexts/ToastContext';
import adminClient from '../../api/adminClient';

const STATUS_STYLE = {
  published: { bg: 'rgba(63,185,80,0.12)', border: 'rgba(63,185,80,0.4)', color: '#3fb950' },
  draft:     { bg: 'rgba(210,153,34,0.12)', border: 'rgba(210,153,34,0.4)', color: '#d29922' },
};

function StatusBadge({ status }) {
  const s = STATUS_STYLE[status] ?? {};
  return (
    <span style={{
      background: s.bg, border: `1px solid ${s.border}`, borderRadius: 3,
      color: s.color, fontSize: 9, fontWeight: 700, letterSpacing: '0.1em',
      padding: '2px 7px', textTransform: 'uppercase',
    }}>
      {status}
    </span>
  );
}

export default function AdminPages() {
  const navigate            = useNavigate();
  const toast               = useToast();
  const [pages, setPages]   = useState([]);
  const [loading, setLoading] = useState(true);

  const load = () => {
    setLoading(true);
    adminClient.get('/admin/pages')
      .then((res) => setPages(res.data.data ?? []))
      .catch(() => toast.error('Failed to load pages.'))
      .finally(() => setLoading(false));
  };

  // eslint-disable-next-line react-hooks/set-state-in-effect, react-hooks/exhaustive-deps
  useEffect(load, []);

  const togglePublish = async (page) => {
    const endpoint = page.status === 'published'
      ? `/admin/pages/${page.id}/unpublish`
      : `/admin/pages/${page.id}/publish`;
    try {
      const res = await adminClient.post(endpoint);
      setPages((ps) => ps.map((p) => p.id === page.id ? res.data.data : p));
      toast.success(page.status === 'published' ? 'Page unpublished.' : 'Page published.');
    } catch (err) {
      toast.error(err.message ?? 'Action failed.');
    }
  };

  const deletePage = async (page) => {
    if (!confirm(`Delete "${page.title}"? This cannot be undone.`)) return;
    try {
      await adminClient.delete(`/admin/pages/${page.id}`);
      setPages((ps) => ps.filter((p) => p.id !== page.id));
      toast.success('Page deleted.');
    } catch (err) {
      toast.error(err.message ?? 'Delete failed.');
    }
  };

  const thStyle = { fontSize: 10, color: '#8b949e', letterSpacing: '0.1em', textTransform: 'uppercase', padding: '10px 12px', textAlign: 'left', borderBottom: '1px solid rgba(48,54,61,0.6)', whiteSpace: 'nowrap' };
  const tdStyle = { fontSize: 12, padding: '11px 12px', borderBottom: '1px solid rgba(48,54,61,0.3)', verticalAlign: 'middle' };

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 20 }}>
        <h1 style={{ fontFamily: "'Orbitron', sans-serif", fontSize: 16, fontWeight: 700, letterSpacing: '0.15em', textTransform: 'uppercase', margin: 0 }}>
          Pages
        </h1>
        <button
          onClick={() => navigate('/admin/pages/new')}
          style={{
            background: 'rgba(0,212,255,0.12)', border: '1px solid rgba(0,212,255,0.4)',
            borderRadius: 4, color: '#00d4ff', fontFamily: "'Orbitron', sans-serif",
            fontSize: 10, fontWeight: 700, letterSpacing: '0.1em', padding: '8px 16px',
            cursor: 'pointer', textTransform: 'uppercase',
          }}
        >
          + New Page
        </button>
      </div>

      <div style={{ background: '#161b22', border: '1px solid rgba(48,54,61,0.6)', borderRadius: 6, overflow: 'hidden' }}>
        {loading ? (
          <p style={{ padding: 20, color: '#8b949e', fontSize: 13 }}>Loading…</p>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr>
                {['Title', 'Slug', 'Status', 'Updated', 'Actions'].map((h) => (
                  <th key={h} style={thStyle}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {pages.map((p) => (
                <tr key={p.id}>
                  <td style={tdStyle}>{p.title}</td>
                  <td style={{ ...tdStyle, color: '#484f58', fontSize: 11 }}>/pages/{p.slug}</td>
                  <td style={tdStyle}><StatusBadge status={p.status} /></td>
                  <td style={{ ...tdStyle, color: '#484f58', fontSize: 11 }}>
                    {new Date(p.updated_at).toLocaleDateString()}
                  </td>
                  <td style={{ ...tdStyle, whiteSpace: 'nowrap' }}>
                    <button
                      onClick={() => navigate(`/admin/pages/${p.id}/edit`)}
                      style={{ background: 'none', border: '1px solid rgba(48,54,61,0.8)', borderRadius: 4, color: '#8b949e', fontSize: 10, padding: '4px 8px', cursor: 'pointer', marginRight: 4 }}
                    >
                      EDIT
                    </button>
                    <button
                      onClick={() => togglePublish(p)}
                      style={{
                        background: 'none',
                        border: `1px solid ${p.status === 'published' ? 'rgba(210,153,34,0.4)' : 'rgba(63,185,80,0.4)'}`,
                        borderRadius: 4,
                        color: p.status === 'published' ? '#d29922' : '#3fb950',
                        fontSize: 10, padding: '4px 8px', cursor: 'pointer', marginRight: 4,
                      }}
                    >
                      {p.status === 'published' ? 'UNPUBLISH' : 'PUBLISH'}
                    </button>
                    <button
                      onClick={() => deletePage(p)}
                      style={{ background: 'none', border: '1px solid rgba(248,81,73,0.3)', borderRadius: 4, color: '#f85149', fontSize: 10, padding: '4px 8px', cursor: 'pointer' }}
                    >
                      DELETE
                    </button>
                  </td>
                </tr>
              ))}
              {!pages.length && (
                <tr><td colSpan={5} style={{ ...tdStyle, textAlign: 'center', color: '#484f58' }}>No pages yet.</td></tr>
              )}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
