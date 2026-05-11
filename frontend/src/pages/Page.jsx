import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { marked } from 'marked';
import DOMPurify from 'dompurify';
import client from '../api/client';
import Footer from '../components/Footer';

// Configure marked: no HTML passthrough in source, renderer uses safe defaults
marked.setOptions({ gfm: true, breaks: false });

function renderMarkdown(content) {
  const rawHtml = marked.parse(content ?? '');
  return DOMPurify.sanitize(rawHtml);
}

export default function Page() {
  const { slug } = useParams();
  const [page, setPage]       = useState(null);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);

  useEffect(() => {
    setLoading(true);
    setNotFound(false);
    client.get(`/pages/${slug}`)
      .then((res) => setPage(res.data.data))
      .catch((err) => {
        if (err.type === 'SERVER_ERROR' || err.status === 404) setNotFound(true);
      })
      .finally(() => setLoading(false));
  }, [slug]);

  // Update document title for SEO
  useEffect(() => {
    if (page) {
      document.title = page.meta_title || `${page.title} — SatView`;
    }
    return () => { document.title = 'SatView'; };
  }, [page]);

  return (
    <div style={{
      minHeight: '100vh',
      background: '#0d1117',
      color: '#e6edf3',
      fontFamily: "'JetBrains Mono', monospace",
      display: 'flex',
      flexDirection: 'column',
    }}>
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@700&family=JetBrains+Mono:wght@400;500&display=swap');
        .page-content h1 { font-family: 'Orbitron', sans-serif; font-size: 22px; color: #e6edf3; margin: 32px 0 16px; letter-spacing: 0.04em; }
        .page-content h2 { font-family: 'Orbitron', sans-serif; font-size: 15px; color: #e6edf3; margin: 28px 0 12px; letter-spacing: 0.04em; }
        .page-content h3 { font-size: 13px; color: #e6edf3; margin: 20px 0 8px; }
        .page-content p  { line-height: 1.7; color: #8b949e; margin: 0 0 14px; font-size: 13px; }
        .page-content a  { color: #00d4ff; text-decoration: none; }
        .page-content a:hover { text-decoration: underline; }
        .page-content ul, .page-content ol { color: #8b949e; font-size: 13px; line-height: 1.7; padding-left: 22px; margin-bottom: 14px; }
        .page-content li { margin-bottom: 4px; }
        .page-content table { border-collapse: collapse; width: 100%; margin-bottom: 20px; font-size: 12px; }
        .page-content th { background: rgba(0,212,255,0.06); color: #00d4ff; border: 1px solid rgba(48,54,61,0.8); padding: 8px 12px; text-align: left; }
        .page-content td { border: 1px solid rgba(48,54,61,0.5); padding: 8px 12px; color: #8b949e; }
        .page-content hr { border: none; border-top: 1px solid rgba(48,54,61,0.6); margin: 28px 0; }
        .page-content code { background: rgba(0,212,255,0.06); border: 1px solid rgba(0,212,255,0.1); border-radius: 3px; padding: 1px 5px; font-size: 12px; color: '#00d4ff'; }
        .page-content blockquote { border-left: 3px solid rgba(0,212,255,0.3); padding-left: 16px; margin: 16px 0; color: #484f58; }
        .page-content strong { color: #e6edf3; }
      `}</style>

      {/* Top bar */}
      <div style={{
        borderBottom: '1px solid rgba(48,54,61,0.6)',
        padding: '14px 24px',
        display: 'flex',
        alignItems: 'center',
        gap: 20,
      }}>
        <Link to="/" style={{ textDecoration: 'none' }}>
          <span style={{
            fontFamily: "'Orbitron', sans-serif",
            fontSize: 11, fontWeight: 700, letterSpacing: '0.25em', color: '#00d4ff',
          }}>
            ◈ SATVIEW
          </span>
        </Link>
      </div>

      {/* Content */}
      <div style={{ flex: 1, maxWidth: 760, margin: '0 auto', padding: '40px 24px', width: '100%' }}>
        {loading && (
          <p style={{ color: '#484f58', fontSize: 13 }}>Loading…</p>
        )}

        {!loading && notFound && (
          <div style={{ textAlign: 'center', paddingTop: 60 }}>
            <div style={{ fontFamily: "'Orbitron', sans-serif", fontSize: 13, color: '#484f58', letterSpacing: '0.2em', marginBottom: 12 }}>
              PAGE NOT FOUND
            </div>
            <Link to="/" style={{ fontSize: 12, color: 'rgba(0,212,255,0.6)', textDecoration: 'none' }}>
              ← Back to app
            </Link>
          </div>
        )}

        {!loading && page && (
          <article>
            {page.meta_description && (
              <p style={{ fontSize: 12, color: '#484f58', marginBottom: 32, fontStyle: 'italic' }}>
                {page.meta_description}
              </p>
            )}
            <div
              className="page-content"
              dangerouslySetInnerHTML={{ __html: renderMarkdown(page.content) }}
            />
            <p style={{ marginTop: 40, fontSize: 11, color: '#484f58' }}>
              Last updated: {new Date(page.updated_at).toLocaleDateString('en-GB', { year: 'numeric', month: 'long', day: 'numeric' })}
            </p>
          </article>
        )}
      </div>

      <Footer />
    </div>
  );
}
