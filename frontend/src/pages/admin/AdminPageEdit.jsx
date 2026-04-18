import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useToast } from '../../contexts/ToastContext';
import adminClient from '../../api/adminClient';

const inputStyle = {
  width: '100%', background: '#010409', border: '1px solid rgba(48,54,61,0.8)',
  borderRadius: 4, color: '#e6edf3', fontFamily: "'JetBrains Mono', monospace",
  fontSize: 12, padding: '8px 10px', outline: 'none', boxSizing: 'border-box',
};

const labelStyle = {
  display: 'block', fontSize: 10, color: '#8b949e',
  textTransform: 'uppercase', letterSpacing: '0.1em', marginBottom: 6,
};

const hintStyle = { fontSize: 10, color: '#484f58', margin: '3px 0 0' };

function slugify(str) {
  return str.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

export default function AdminPageEdit() {
  const { id }      = useParams();
  const navigate    = useNavigate();
  const toast       = useToast();
  const isNew       = !id;

  const [form, setForm]     = useState({
    title: '', slug: '', excerpt: '', content: '',
    meta_title: '', meta_description: '',
  });
  const [loading, setLoading]   = useState(!isNew);
  const [saving, setSaving]     = useState(false);
  const [slugTouched, setSlugTouched] = useState(false);

  useEffect(() => {
    if (isNew) return;
    adminClient.get(`/admin/pages/${id}`)
      .then((res) => {
        const p = res.data.data;
        setForm({
          title:            p.title,
          slug:             p.slug,
          excerpt:          p.excerpt ?? '',
          content:          p.content,
          meta_title:       p.meta_title ?? '',
          meta_description: p.meta_description ?? '',
        });
        setSlugTouched(true); // don't auto-overwrite slug on edit
      })
      .catch(() => toast.error('Failed to load page.'))
      .finally(() => setLoading(false));
  }, [id]);

  const handleTitleChange = (value) => {
    setForm((f) => ({
      ...f,
      title: value,
      // Auto-generate slug from title only while user hasn't manually edited it
      ...(slugTouched ? {} : { slug: slugify(value) }),
    }));
  };

  const save = async () => {
    if (!form.title.trim()) { toast.error('Title is required.'); return; }
    if (!form.content.trim()) { toast.error('Content is required.'); return; }
    setSaving(true);
    try {
      const payload = {
        title:            form.title,
        slug:             form.slug || undefined,
        excerpt:          form.excerpt || null,
        content:          form.content,
        meta_title:       form.meta_title || null,
        meta_description: form.meta_description || null,
      };
      if (isNew) {
        const res = await adminClient.post('/admin/pages', payload);
        toast.success('Page created.');
        navigate(`/admin/pages/${res.data.data.id}/edit`);
      } else {
        await adminClient.patch(`/admin/pages/${id}`, payload);
        toast.success('Page saved.');
      }
    } catch (err) {
      toast.error(err.message ?? 'Save failed.');
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <p style={{ color: '#484f58', fontSize: 13 }}>Loading…</p>;

  return (
    <div style={{ maxWidth: 800 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 24 }}>
        <button
          onClick={() => navigate('/admin/pages')}
          style={{ background: 'none', border: 'none', color: '#484f58', fontSize: 12, cursor: 'pointer', padding: 0, fontFamily: "'JetBrains Mono', monospace" }}
        >
          ← Pages
        </button>
        <h1 style={{ fontFamily: "'Orbitron', sans-serif", fontSize: 14, fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase', margin: 0 }}>
          {isNew ? 'New Page' : 'Edit Page'}
        </h1>
      </div>

      <div style={{ display: 'grid', gap: 16 }}>

        <div>
          <label style={labelStyle}>Title *</label>
          <input
            type="text"
            value={form.title}
            onChange={(e) => handleTitleChange(e.target.value)}
            maxLength={200}
            style={inputStyle}
          />
        </div>

        <div>
          <label style={labelStyle}>Slug</label>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <span style={{ fontSize: 11, color: '#484f58' }}>/pages/</span>
            <input
              type="text"
              value={form.slug}
              onChange={(e) => { setSlugTouched(true); setForm((f) => ({ ...f, slug: e.target.value })); }}
              maxLength={200}
              style={{ ...inputStyle, flex: 1 }}
              placeholder="auto-generated-from-title"
            />
          </div>
          <p style={hintStyle}>Lowercase letters, numbers, and hyphens only. Auto-generated from title if left empty.</p>
        </div>

        <div>
          <label style={labelStyle}>Excerpt</label>
          <textarea
            value={form.excerpt}
            onChange={(e) => setForm((f) => ({ ...f, excerpt: e.target.value }))}
            maxLength={500}
            rows={2}
            style={{ ...inputStyle, resize: 'vertical' }}
            placeholder="Short description shown in page listings and meta tags."
          />
        </div>

        <div>
          <label style={labelStyle}>Content * (Markdown)</label>
          <textarea
            value={form.content}
            onChange={(e) => setForm((f) => ({ ...f, content: e.target.value }))}
            rows={24}
            style={{ ...inputStyle, resize: 'vertical', lineHeight: 1.6 }}
            placeholder="Write your page content in Markdown..."
          />
          <p style={hintStyle}>Supports Markdown: headings (#), bold (**text**), lists, tables, links. Content is sanitized on render.</p>
        </div>

        <details style={{ background: '#161b22', border: '1px solid rgba(48,54,61,0.6)', borderRadius: 6, padding: '14px 16px' }}>
          <summary style={{ fontSize: 10, color: '#8b949e', letterSpacing: '0.12em', textTransform: 'uppercase', cursor: 'pointer' }}>
            SEO Fields
          </summary>
          <div style={{ marginTop: 14, display: 'grid', gap: 12 }}>
            <div>
              <label style={labelStyle}>Meta Title</label>
              <input
                type="text"
                value={form.meta_title}
                onChange={(e) => setForm((f) => ({ ...f, meta_title: e.target.value }))}
                maxLength={200}
                style={inputStyle}
                placeholder="Defaults to page title if empty"
              />
            </div>
            <div>
              <label style={labelStyle}>Meta Description</label>
              <textarea
                value={form.meta_description}
                onChange={(e) => setForm((f) => ({ ...f, meta_description: e.target.value }))}
                maxLength={320}
                rows={2}
                style={{ ...inputStyle, resize: 'vertical' }}
                placeholder="Up to 320 characters. Shown in search engine results."
              />
            </div>
          </div>
        </details>

        <div style={{ display: 'flex', gap: 10 }}>
          <button
            onClick={save} disabled={saving}
            style={{
              background: 'rgba(0,212,255,0.12)', border: '1px solid rgba(0,212,255,0.4)',
              borderRadius: 4, color: '#00d4ff', fontFamily: "'Orbitron', sans-serif",
              fontSize: 11, fontWeight: 700, letterSpacing: '0.08em', padding: '11px 22px',
              cursor: saving ? 'not-allowed' : 'pointer', textTransform: 'uppercase',
            }}
          >
            {saving ? '...' : (isNew ? 'Create Page' : 'Save Changes')}
          </button>
          <button
            onClick={() => navigate('/admin/pages')}
            style={{
              background: 'rgba(48,54,61,0.4)', border: '1px solid rgba(48,54,61,0.8)',
              borderRadius: 4, color: '#8b949e', fontFamily: "'JetBrains Mono', monospace",
              fontSize: 12, padding: '11px 16px', cursor: 'pointer',
            }}
          >
            Cancel
          </button>
        </div>

      </div>
    </div>
  );
}
