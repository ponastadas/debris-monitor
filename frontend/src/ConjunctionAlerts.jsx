import { useEffect, useState, useCallback, useRef } from "react";
import client from "./api/client";

// ── Local satellite catalog ───────────────────────────────────────────────────
// Provides instant search suggestions for well-known satellites without a network
// round-trip. Remote CelesTrak search supplements these after the 350 ms debounce.
// Names must be accurate — they are stored as-is on POST /watch.
const LOCAL_CATALOG = [
  { norad_id: "25544", name: "ISS (ZARYA)" },
  { norad_id: "20580", name: "HST" },
  { norad_id: "41866", name: "GOES 16" },
  { norad_id: "43226", name: "GOES 17" },
  { norad_id: "51850", name: "GOES 18" },
  { norad_id: "48274", name: "CSS (TIANHE)" },
  { norad_id: "43013", name: "NOAA 20 (JPSS-1)" },
  { norad_id: "54234", name: "NOAA 21 (JPSS-2)" },
  { norad_id: "39084", name: "LANDSAT 8" },
  { norad_id: "49260", name: "LANDSAT 9" },
  { norad_id: "27424", name: "AQUA" },
  { norad_id: "25994", name: "TERRA" },
  { norad_id: "37849", name: "SUOMI NPP" },
  { norad_id: "39634", name: "SENTINEL-1A" },
  { norad_id: "40697", name: "SENTINEL-2A" },
  { norad_id: "42063", name: "SENTINEL-2B" },
  { norad_id: "41335", name: "SENTINEL-3A" },
  { norad_id: "43437", name: "SENTINEL-3B" },
  { norad_id: "43613", name: "ICESAT-2" },
  { norad_id: "46984", name: "SENTINEL-6A" },
];

// ── Styles ────────────────────────────────────────────────────────────────────
const STYLE = `
  @import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;900&family=JetBrains+Mono:wght@300;400;500&display=swap');

  /* ─ Search input ─ */
  .ca-watch-input {
    flex: 1;
    background: rgba(0,212,255,0.06);
    border: 1px solid rgba(0,212,255,0.25);
    border-radius: 4px;
    color: #c8dff0;
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px;
    padding: 11px 36px 11px 12px; /* right padding leaves room for clear/spinner */
    outline: none;
    transition: border-color 0.15s, background 0.15s;
    min-width: 0;
    width: 100%;
  }
  .ca-watch-input:focus {
    border-color: rgba(0,212,255,0.55);
    background: rgba(0,212,255,0.09);
  }
  .ca-watch-input::placeholder { color: rgba(0,212,255,0.28); font-size: 10px; }

  /* ─ Clear (×) button inside input ─ */
  .ca-clear-btn {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: rgba(0,212,255,0.35);
    cursor: pointer;
    font-size: 15px;
    line-height: 1;
    padding: 2px 5px;
    transition: color 0.12s;
  }
  .ca-clear-btn:hover { color: rgba(0,212,255,0.75); }

  /* ─ Dropdown ─ */
  .ca-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    z-index: 200;
    background: #050f1e;
    border: 1px solid rgba(0,212,255,0.25);
    border-radius: 4px;
    box-shadow: 0 8px 28px rgba(0,0,0,0.65);
    overflow: hidden;
    max-height: 260px;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: rgba(0,212,255,0.1) transparent;
  }

  .ca-dropdown-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid rgba(0,212,255,0.06);
    transition: background 0.08s;
    gap: 10px;
  }
  .ca-dropdown-item:last-child { border-bottom: none; }
  .ca-dropdown-item:hover,
  .ca-dropdown-item.ca-active { background: rgba(0,212,255,0.1); }
  .ca-dropdown-item.ca-watched { cursor: default; opacity: 0.45; }

  .ca-dropdown-status {
    padding: 10px 14px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 9px;
    letter-spacing: 1px;
    color: rgba(0,212,255,0.38);
  }
  .ca-dropdown-status.error { color: #ff6b60; }

  .ca-dropdown-hint {
    padding: 6px 14px 8px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 7px;
    letter-spacing: 0.5px;
    color: rgba(0,212,255,0.2);
    border-top: 1px solid rgba(0,212,255,0.06);
  }

  /* ─ Quick-pick buttons ─ */
  .ca-quick-btn {
    background: rgba(0,212,255,0.05);
    border: 1px solid rgba(0,212,255,0.14);
    border-radius: 3px;
    color: rgba(0,212,255,0.55);
    cursor: pointer;
    font-family: 'JetBrains Mono', monospace;
    font-size: 8px;
    letter-spacing: 0.5px;
    padding: 5px 10px;
    transition: background 0.12s, border-color 0.12s, color 0.12s;
    white-space: nowrap;
  }
  .ca-quick-btn:hover:not(:disabled) {
    background: rgba(0,212,255,0.12);
    border-color: rgba(0,212,255,0.35);
    color: #00d4ff;
  }
  .ca-quick-btn:disabled {
    background: rgba(48,209,88,0.07);
    border-color: rgba(48,209,88,0.25);
    color: rgba(48,209,88,0.55);
    cursor: default;
  }

  /* ─ Unwatch / remove button ─ */
  .ca-unwatch-btn {
    background: transparent;
    border: 1px solid rgba(255,59,48,0.28);
    border-radius: 3px;
    color: rgba(255,59,48,0.55);
    cursor: pointer;
    font-family: 'JetBrains Mono', monospace;
    font-size: 10px;
    line-height: 1;
    padding: 5px 10px;
    transition: background 0.12s, border-color 0.12s, color 0.12s;
  }
  .ca-unwatch-btn:hover {
    background: rgba(255,59,48,0.1);
    border-color: rgba(255,59,48,0.55);
    color: #ff3b30;
  }

  /* ─ Alert card action buttons ─ */
  .ca-track-btn {
    width: 100%;
    padding: 9px 0;
    background: rgba(0,212,255,0.07);
    border: 1px solid rgba(0,212,255,0.28);
    border-radius: 4px;
    color: #00d4ff;
    font-size: 9px;
    letter-spacing: 1.5px;
    cursor: pointer;
    font-family: 'JetBrains Mono', monospace;
    transition: background 0.12s, border-color 0.12s;
  }
  .ca-track-btn:hover {
    background: rgba(0,212,255,0.15);
    border-color: rgba(0,212,255,0.6);
  }
  .ca-track-btn:active {
    background: rgba(0,212,255,0.22);
  }

  /* ─ Refresh button ─ */
  .ca-refresh-btn {
    margin-left: auto;
    background: transparent;
    border: 1px solid rgba(0,212,255,0.18);
    border-radius: 4px;
    color: rgba(0,212,255,0.55);
    cursor: pointer;
    font-family: 'JetBrains Mono', monospace;
    font-size: 8px;
    letter-spacing: 1px;
    min-width: 60px;
    padding: 6px 12px;
    text-align: center;
    transition: background 0.12s, border-color 0.12s, color 0.12s;
  }
  .ca-refresh-btn:hover:not(:disabled) {
    background: rgba(0,212,255,0.07);
    border-color: rgba(0,212,255,0.38);
    color: #00d4ff;
  }
  .ca-refresh-btn:active:not(:disabled) {
    background: rgba(0,212,255,0.14);
  }
  .ca-refresh-btn:disabled { opacity: 0.4; cursor: not-allowed; }

  /* ─ Spinner ─ */
  @keyframes ca-spin { to { transform: translateY(-50%) rotate(360deg); } }
`;

const RISK_COLOR  = { HIGH: "#ff3b30", MEDIUM: "#ff9500", LOW: "#30d158" };
const SOURCE_LABEL = { space_track_cdm: "SPACE-TRACK CDM", sgp4: "SGP4 COMPUTED", simulated: "SIMULATED" };
const SOURCE_COLOR = { space_track_cdm: "#30d158", sgp4: "rgba(0,212,255,0.6)", simulated: "#ff9500" };

// ── Helpers ──────────────────────────────────────────────────────────────────

function formatCountdown(hoursUntil) {
  if (hoursUntil < 0)  return "PAST";
  if (hoursUntil < 1)  return `${Math.round(hoursUntil * 60)}m`;
  if (hoursUntil < 24) return `${hoursUntil.toFixed(1)}h`;
  const days  = Math.floor(hoursUntil / 24);
  const hours = Math.floor(hoursUntil % 24);
  return `${days}d ${hours}h`;
}

function formatTca(iso) {
  const d = new Date(iso);
  return d.toUTCString().replace(" GMT", " UTC").replace(/:\d\d /, " ");
}

// ── AlertCard ─────────────────────────────────────────────────────────────────

function AlertCard({ alert, onTrack }) {
  const color       = RISK_COLOR[alert.risk_level] ?? "#8b949e";
  const percent     = alert.risk_score;
  const src         = alert.source ?? "sgp4";
  const srcLabel    = SOURCE_LABEL[src] ?? src.toUpperCase();
  const srcColor    = SOURCE_COLOR[src] ?? "rgba(200,223,240,0.4)";

  return (
    <div style={{
      background: "rgba(0,0,0,0.55)",
      border: `1px solid ${color}38`,
      borderLeft: `3px solid ${color}`,
      borderRadius: 6,
      padding: "14px 16px",
      marginBottom: 10,
      fontFamily: "'JetBrains Mono', monospace",
    }}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", marginBottom: 8 }}>
        <div>
          <div style={{ color: "#c8dff0", fontSize: 11, fontWeight: 500 }}>{alert.primary_name}</div>
          <div style={{ color: "rgba(200,223,240,0.45)", fontSize: 9, marginTop: 2 }}>NORAD {alert.primary_norad_id}</div>
        </div>
        <div style={{ display: "flex", gap: 5, alignItems: "center", flexShrink: 0 }}>
          <span style={{
            background: `${srcColor}14`, border: `1px solid ${srcColor}44`, borderRadius: 3,
            color: srcColor, fontSize: 7, letterSpacing: 1, padding: "2px 6px",
          }}>
            {srcLabel}
          </span>
          <span style={{
            background: `${color}18`, border: `1px solid ${color}55`, borderRadius: 3,
            color, fontSize: 8, fontWeight: 700, letterSpacing: 1.5, padding: "3px 7px",
          }}>
            {alert.risk_level}
          </span>
        </div>
      </div>

      <div style={{ color: "rgba(200,223,240,0.55)", fontSize: 9, marginBottom: 10 }}>
        ↔ <span style={{ color: "#ff6b6b" }}>{alert.secondary_name}</span>
        <span style={{ color: "rgba(200,223,240,0.28)" }}> · NORAD {alert.secondary_norad_id}</span>
      </div>

      <div style={{ marginBottom: 10 }}>
        <div style={{ height: 3, background: "rgba(255,255,255,0.07)", borderRadius: 2 }}>
          <div style={{
            height: "100%", width: `${percent}%`,
            background: `linear-gradient(90deg, ${color}70, ${color})`,
            borderRadius: 2, transition: "width 0.6s ease",
          }} />
        </div>
        <div style={{ display: "flex", justifyContent: "space-between", marginTop: 3 }}>
          <span style={{ color: "rgba(200,223,240,0.28)", fontSize: 8 }}>RISK</span>
          <span style={{ color, fontSize: 8 }}>{percent}/100</span>
        </div>
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 8, marginBottom: 10 }}>
        {[
          { label: "MISS DIST", value: `${alert.miss_distance_km} km` },
          { label: "TCA IN",    value: formatCountdown(alert.hours_until_tca) },
          { label: "PROB",      value: alert.probability != null ? `${(alert.probability * 100).toFixed(2)}%` : "N/A" },
        ].map(({ label, value }) => (
          <div key={label} style={{
            background: "rgba(0,212,255,0.04)", border: "1px solid rgba(0,212,255,0.09)",
            borderRadius: 4, padding: "7px 8px", textAlign: "center",
          }}>
            <div style={{ color: "rgba(0,212,255,0.45)", fontSize: 7, letterSpacing: 1, marginBottom: 2 }}>{label}</div>
            <div style={{ color: "#c8dff0", fontSize: 10, fontWeight: 500 }}>{value}</div>
          </div>
        ))}
      </div>

      <div style={{ color: "rgba(200,223,240,0.35)", fontSize: 8, marginBottom: 10 }}>
        TCA: {formatTca(alert.tca)}
      </div>

      <button className="ca-track-btn" onClick={() => onTrack(alert.primary_norad_id, alert.primary_name)}>
        TRACK SATELLITE
      </button>
    </div>
  );
}

// ── SatelliteSearchPicker ─────────────────────────────────────────────────────
// Search strategy:
//   1. Instant: filter LOCAL_CATALOG (20 well-known satellites, no network)
//   2. Deferred: 350 ms debounce → GET /api/satellites/search?q=... (local DB catalog)
//      Merges with local results; if remote fails but local matched, keeps local results.
// No live CelesTrak calls — backend serves from local satellite catalog.
// Run `php artisan satellites:sync` to populate/refresh the catalog.

function SatelliteSearchPicker({ onWatched, watchedNoradIds }) {
  const [query,         setQuery]         = useState("");
  const [results,       setResults]       = useState([]);
  const [status,        setStatus]        = useState("idle"); // idle|searching|noresults|error
  const [remoteLoading, setRemoteLoading] = useState(false);  // spinner inside input
  const [adding,        setAdding]        = useState(null);   // norad_id being added
  const [addErr,        setAddErr]        = useState(null);
  const [open,          setOpen]          = useState(false);
  const [activeIndex,   setActiveIndex]   = useState(-1);     // -1 = focus on input

  const timerRef    = useRef(null);
  const wrapRef     = useRef(null);
  const inputRef    = useRef(null);
  const itemRefs    = useRef([]);

  // Scroll active item into view when navigating with keyboard
  useEffect(() => {
    if (activeIndex >= 0 && itemRefs.current[activeIndex]) {
      itemRefs.current[activeIndex].scrollIntoView({ block: "nearest" });
    }
  }, [activeIndex]);

  // Close dropdown on outside click
  useEffect(() => {
    function handler(e) {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) {
        setOpen(false);
        setActiveIndex(-1);
      }
    }
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  function filterLocal(q) {
    const lower = q.toLowerCase();
    return LOCAL_CATALOG.filter(s =>
      s.name.toLowerCase().includes(lower) || s.norad_id.startsWith(q)
    );
  }

  function handleChange(e) {
    const q = e.target.value;
    setQuery(q);
    setAddErr(null);
    setActiveIndex(-1);
    clearTimeout(timerRef.current);

    const trimmed = q.trim();

    if (!trimmed || trimmed.length < 2) {
      setResults([]);
      setStatus("idle");
      setOpen(false);
      setRemoteLoading(false);
      return;
    }

    // 1. Instant local filter
    const local = filterLocal(trimmed);
    if (local.length > 0) {
      setResults(local);
      setStatus("idle");
      setOpen(true);
    } else {
      setResults([]);
      setStatus("searching");
      setOpen(true);
    }

    // 2. Deferred remote search — supplements local results
    setRemoteLoading(true);
    timerRef.current = setTimeout(async () => {
      try {
        const res    = await client.get("/satellites/search", { params: { q: trimmed } });
        const remote = res.data.data ?? res.data ?? [];

        setResults(prev => {
          const localIds = new Set(prev.map(s => s.norad_id));
          const merged   = [...prev, ...remote.filter(r => !localIds.has(r.norad_id))].slice(0, 10);
          return merged;
        });
        setStatus(prev => {
          // If previous status was searching (no local) and remote returned empty → noresults
          if (prev === "searching" && local.length === 0 && remote.length === 0) return "noresults";
          return "idle";
        });
      } catch {
        // Remote failed: keep local results if we had them; show error only when nothing to show
        if (local.length === 0) {
          setStatus("error");
        }
        // else: local results stay visible, remote failure is silent (acceptable tradeoff)
      } finally {
        setRemoteLoading(false);
      }
    }, 350);
  }

  function handleKeyDown(e) {
    if (!open && e.key !== "ArrowDown") return;

    if (e.key === "ArrowDown") {
      e.preventDefault();
      if (!open) { setOpen(true); return; }
      setActiveIndex(i => Math.min(i + 1, results.length - 1));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setActiveIndex(i => {
        const next = i - 1;
        if (next < 0) { inputRef.current?.focus(); return -1; }
        return next;
      });
    } else if (e.key === "Enter") {
      e.preventDefault();
      if (activeIndex >= 0 && activeIndex < results.length) {
        // Select the keyboard-highlighted result
        const r = results[activeIndex];
        if (!watchedNoradIds.has(r.norad_id) && adding !== r.norad_id) {
          addSatellite(r.norad_id, r.name);
        }
      } else if (/^\d{4,6}$/.test(query.trim())) {
        // Direct NORAD ID entry: user typed a 4–6 digit ID and pressed Enter
        // Name will be resolved server-side from CelesTrak if blank
        addSatellite(query.trim(), "");
      }
    } else if (e.key === "Escape") {
      setOpen(false);
      setActiveIndex(-1);
    }
  }

  async function addSatellite(noradId, name) {
    setAdding(noradId);
    setAddErr(null);
    try {
      await client.post("/watch", { norad_id: noradId, name: name || undefined });
      setQuery("");
      setResults([]);
      setStatus("idle");
      setOpen(false);
      setActiveIndex(-1);
      onWatched();
    } catch (err) {
      const msg = err.response?.data?.message ?? err.message ?? "Failed to add.";
      setAddErr(msg === "Already watching this satellite." ? "Already on watch list." : msg);
    } finally {
      setAdding(null);
    }
  }

  function clearInput() {
    setQuery("");
    setResults([]);
    setStatus("idle");
    setOpen(false);
    setActiveIndex(-1);
    setRemoteLoading(false);
    clearTimeout(timerRef.current);
    inputRef.current?.focus();
  }

  const showSpinner = remoteLoading;

  return (
    <div ref={wrapRef} style={{ position: "relative", marginBottom: 16 }}>
      {/* Input row */}
      <div style={{ position: "relative" }}>
        <input
          ref={inputRef}
          className="ca-watch-input"
          value={query}
          onChange={handleChange}
          onKeyDown={handleKeyDown}
          onFocus={() => { if (results.length > 0 || query.trim().length >= 2) setOpen(true); }}
          placeholder="Search by name or NORAD ID…"
          autoComplete="off"
          aria-label="Search satellites"
          aria-expanded={open}
          aria-autocomplete="list"
        />

        {/* Spinner (remote loading) */}
        {showSpinner && (
          <div style={{
            position: "absolute", right: query ? 30 : 10, top: "50%",
            transform: "translateY(-50%)",
            width: 11, height: 11,
            border: "1px solid rgba(0,212,255,0.18)", borderTop: "1px solid #00d4ff",
            borderRadius: "50%", animation: "ca-spin 0.75s linear infinite",
            pointerEvents: "none",
          }} />
        )}

        {/* Clear button */}
        {query && (
          <button
            className="ca-clear-btn"
            onClick={clearInput}
            tabIndex={-1}
            aria-label="Clear search"
            style={{ right: showSpinner ? 28 : 8 }}
          >
            ×
          </button>
        )}
      </div>

      {/* Dropdown */}
      {open && (
        <div className="ca-dropdown" role="listbox">
          {status === "searching" && (
            <div className="ca-dropdown-status">SEARCHING CELESTRAK…</div>
          )}

          {status === "noresults" && (
            <div className="ca-dropdown-status">
              No matches for &ldquo;{query}&rdquo;
              {/^\d{4,6}$/.test(query.trim()) && (
                <div style={{ marginTop: 4, color: "rgba(0,212,255,0.3)", fontSize: 8 }}>
                  Press Enter to add NORAD {query.trim()} directly
                </div>
              )}
            </div>
          )}

          {status === "error" && (
            <div className="ca-dropdown-status error">
              Search unavailable.
              {/^\d{4,6}$/.test(query.trim()) && (
                <span style={{ color: "rgba(0,212,255,0.5)" }}> Press Enter to add NORAD {query.trim()} directly.</span>
              )}
            </div>
          )}

          {status === "idle" && results.map((r, i) => {
            const alreadyWatched = watchedNoradIds.has(r.norad_id);
            const isAdding       = adding === r.norad_id;
            const isActive       = activeIndex === i;

            return (
              <div
                key={r.norad_id}
                ref={el => { itemRefs.current[i] = el; }}
                className={`ca-dropdown-item${alreadyWatched ? " ca-watched" : ""}${isActive ? " ca-active" : ""}`}
                role="option"
                aria-selected={isActive}
                onClick={() => !alreadyWatched && !isAdding && addSatellite(r.norad_id, r.name)}
                onMouseEnter={() => !alreadyWatched && setActiveIndex(i)}
              >
                <div style={{ minWidth: 0 }}>
                  <div style={{
                    color: alreadyWatched ? "rgba(200,223,240,0.45)" : "#c8dff0",
                    fontSize: 11, fontWeight: 500,
                    overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap",
                  }}>
                    {r.name}
                  </div>
                  <div style={{ color: "rgba(0,212,255,0.38)", fontSize: 9, marginTop: 1 }}>
                    NORAD {r.norad_id}
                  </div>
                </div>
                <div style={{ flexShrink: 0 }}>
                  {alreadyWatched ? (
                    <span style={{ color: "#30d158", fontSize: 8, letterSpacing: 1 }}>WATCHING</span>
                  ) : isAdding ? (
                    <span style={{ color: "rgba(0,212,255,0.45)", fontSize: 8, letterSpacing: 0.5 }}>ADDING…</span>
                  ) : (
                    <span style={{
                      background: "rgba(0,212,255,0.09)", border: "1px solid rgba(0,212,255,0.32)",
                      borderRadius: 3, color: "#00d4ff", fontSize: 8, letterSpacing: 1,
                      padding: "3px 9px",
                    }}>
                      + WATCH
                    </span>
                  )}
                </div>
              </div>
            );
          })}

          {/* Keyboard hint — shown when there are results */}
          {status === "idle" && results.length > 0 && (
            <div className="ca-dropdown-hint">
              ↑↓ navigate · Enter select · Esc close
            </div>
          )}
        </div>
      )}

      {addErr && (
        <div style={{
          color: "#ff6b60", fontSize: 9, marginTop: 6, letterSpacing: 0.4, lineHeight: 1.5,
        }}>
          ⚠ {addErr}
        </div>
      )}

      {/* Quick picks — most commonly added satellites */}
      <div style={{ display: "flex", gap: 5, marginTop: 9, flexWrap: "wrap" }}>
        {[
          { norad_id: "25544", label: "ISS",      name: "ISS (ZARYA)" },
          { norad_id: "20580", label: "Hubble",   name: "HST" },
          { norad_id: "41866", label: "GOES-16",  name: "GOES-16" },
          { norad_id: "48274", label: "Tianhe",   name: "CSS (TIANHE)" },
          { norad_id: "43013", label: "NOAA-20",  name: "NOAA 20 (JPSS-1)" },
        ].map(s => {
          const watched = watchedNoradIds.has(s.norad_id);
          const isAdd   = adding === s.norad_id;
          return (
            <button
              key={s.norad_id}
              className="ca-quick-btn"
              disabled={watched || isAdd}
              onClick={() => !watched && addSatellite(s.norad_id, s.name)}
              title={watched ? `Already watching ${s.name}` : `Watch ${s.name} (NORAD ${s.norad_id})`}
            >
              {isAdd ? "…" : watched ? `✓ ${s.label}` : s.label}
            </button>
          );
        })}
      </div>
    </div>
  );
}

// ── WatchedSatRow ─────────────────────────────────────────────────────────────

function WatchedSatRow({ sat, onUnwatch }) {
  const [hover,   setHover]   = useState(false);
  const [confirm, setConfirm] = useState(false);

  return (
    <div
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => { setHover(false); setConfirm(false); }}
      style={{
        background:    hover ? "rgba(0,212,255,0.06)" : "rgba(0,212,255,0.03)",
        border:        `1px solid ${hover ? "rgba(0,212,255,0.2)" : "rgba(0,212,255,0.09)"}`,
        borderRadius:  5,
        padding:       "11px 12px",
        marginBottom:  6,
        display:       "flex",
        justifyContent: "space-between",
        alignItems:    "center",
        transition:    "background 0.12s, border-color 0.12s",
      }}
    >
      <div style={{ minWidth: 0, flex: 1 }}>
        <div style={{
          color: "#c8dff0", fontSize: 11, fontWeight: 500,
          overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap",
        }}>
          {sat.name ?? sat.norad_id}
        </div>
        <div style={{ color: "rgba(200,223,240,0.32)", fontSize: 8, marginTop: 3, letterSpacing: 0.5 }}>
          NORAD {sat.norad_id}
          {/* tle_fresh is omitted: TLE sync not yet implemented — showing staleness
              would mislead users. Sync pipeline is listed in What's Next. */}
        </div>
      </div>

      <div style={{ flexShrink: 0, marginLeft: 8 }}>
        {confirm ? (
          <div style={{ display: "flex", gap: 4 }}>
            <button
              className="ca-unwatch-btn"
              onClick={() => onUnwatch(sat.id)}
              style={{
                background: "rgba(255,59,48,0.13)",
                borderColor: "rgba(255,59,48,0.55)",
                color: "#ff3b30",
                fontSize: 8,
                padding: "5px 10px",
              }}
            >
              REMOVE
            </button>
            <button
              onClick={() => setConfirm(false)}
              style={{
                background: "transparent",
                border: "1px solid rgba(0,212,255,0.18)",
                borderRadius: 3,
                color: "rgba(0,212,255,0.45)",
                cursor: "pointer",
                fontFamily: "'JetBrains Mono', monospace",
                fontSize: 8,
                padding: "5px 8px",
                transition: "border-color 0.12s, color 0.12s",
              }}
            >
              CANCEL
            </button>
          </div>
        ) : (
          <button
            className="ca-unwatch-btn"
            onClick={() => setConfirm(true)}
            title={`Stop watching ${sat.name ?? sat.norad_id}`}
          >
            ✕
          </button>
        )}
      </div>
    </div>
  );
}

// ── Main component ────────────────────────────────────────────────────────────

export default function ConjunctionAlerts({ onTrack }) {
  const [alerts,  setAlerts]  = useState([]);
  const [meta,    setMeta]    = useState(null);
  const [watched, setWatched] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error,   setError]   = useState(null);

  useEffect(() => {
    const el = document.createElement("style");
    el.textContent = STYLE;
    document.head.appendChild(el);
    return () => document.head.removeChild(el);
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [alertRes, watchRes] = await Promise.all([
        client.get("/alerts"),
        client.get("/watch"),
      ]);
      setAlerts(alertRes.data.data ?? alertRes.data ?? []);
      setMeta(alertRes.data.meta ?? null);
      setWatched(watchRes.data.data ?? watchRes.data ?? []);
    } catch (err) {
      setError(err.message ?? "Could not reach the API.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  async function unwatch(id) {
    try {
      await client.delete(`/watch/${id}`);
    } catch { /* ignore — list will refresh */ }
    load();
  }

  const highCount       = alerts.filter(a => a.risk_level === "HIGH").length;
  const watchedNoradIds = new Set(watched.map(s => s.norad_id));

  return (
    <div style={{
      width: "100%", height: "100%", background: "#020810",
      display: "flex", flexDirection: "column", overflow: "hidden",
      fontFamily: "'JetBrains Mono', monospace",
    }}>
      <div style={{ display: "flex", flex: 1, overflow: "hidden" }}>

        {/* ── Left: alerts feed ──────────────────────────────────────── */}
        <div style={{
          flex: 1, overflowY: "auto", padding: "24px 20px",
          borderRight: "1px solid rgba(0,212,255,0.09)",
        }}>
          {/* Header row */}
          <div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 12 }}>
            <div style={{ color: "#00d4ff", fontSize: 13, fontWeight: 600, letterSpacing: 2 }}>
              CONJUNCTION ALERTS
            </div>
            {highCount > 0 && (
              <span style={{
                background: "#ff3b3018", border: "1px solid #ff3b3055", borderRadius: 10,
                color: "#ff3b30", fontSize: 9, fontWeight: 700, padding: "2px 8px",
                flexShrink: 0,
              }}>
                {highCount} HIGH
              </span>
            )}
            <button
              className="ca-refresh-btn"
              onClick={load}
              disabled={loading}
              title="Refresh alerts"
            >
              {loading ? "…" : "REFRESH"}
            </button>
          </div>

          {/* Data credibility bar */}
          {meta && (
            <div style={{
              display: "flex", gap: 16, alignItems: "center", flexWrap: "wrap",
              marginBottom: 18, padding: "8px 12px",
              background: "rgba(0,212,255,0.03)", border: "1px solid rgba(0,212,255,0.09)",
              borderRadius: 4, fontSize: 8, letterSpacing: 0.8, lineHeight: 1.5,
            }}>
              <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
                <span style={{ color: "rgba(0,212,255,0.4)" }}>SOURCE</span>
                <span style={{
                  color: SOURCE_COLOR[meta.source] ?? "rgba(200,223,240,0.6)",
                  fontWeight: 600,
                }}>
                  {SOURCE_LABEL[meta.source] ?? meta.source?.toUpperCase() ?? "UNKNOWN"}
                </span>
              </div>
              {meta.coverage && (
                <div style={{ color: "rgba(200,223,240,0.35)" }}>{meta.coverage}</div>
              )}
              {meta.last_updated && (
                <div style={{ color: "rgba(200,223,240,0.28)" }}>
                  Updated {new Date(meta.last_updated).toUTCString().replace(" GMT", " UTC").replace(/:\d\d /, " ")}
                </div>
              )}
            </div>
          )}

          {/* Error */}
          {error && (
            <div style={{
              color: "#ff3b30", fontSize: 10, marginBottom: 16,
              padding: "10px 14px", border: "1px solid #ff3b3038", borderRadius: 4,
              lineHeight: 1.5,
            }}>
              ⚠ {error}
            </div>
          )}

          {/* Loading shimmer — only on first load when list is empty */}
          {loading && alerts.length === 0 && (
            <div style={{ paddingTop: 16 }}>
              {[1, 2, 3].map(i => (
                <div key={i} style={{
                  height: 120, marginBottom: 10, borderRadius: 6,
                  background: "rgba(0,212,255,0.025)",
                  border: "1px solid rgba(0,212,255,0.07)",
                  animation: `ca-shimmer ${0.9 + i * 0.15}s ease-in-out infinite alternate`,
                }} />
              ))}
              <style>{`@keyframes ca-shimmer { from { opacity: 0.4; } to { opacity: 0.7; } }`}</style>
            </div>
          )}

          {/* Empty states */}
          {!loading && alerts.length === 0 && (
            <div style={{ color: "rgba(200,223,240,0.28)", fontSize: 10, textAlign: "center", paddingTop: 40 }}>
              {watched.length === 0 ? (
                <>
                  <div style={{ fontSize: 28, marginBottom: 16, opacity: 0.15 }}>◎</div>
                  <div style={{ fontSize: 11, color: "rgba(0,212,255,0.42)", marginBottom: 10, letterSpacing: 1.5 }}>
                    NO SATELLITES MONITORED
                  </div>
                  <div style={{ lineHeight: 1.9, maxWidth: 240, margin: "0 auto", color: "rgba(200,223,240,0.35)" }}>
                    Search for a satellite in the panel on the right.
                    Conjunction alerts appear here once monitoring starts.
                  </div>
                </>
              ) : (
                <>
                  <div style={{ fontSize: 28, marginBottom: 16, color: "#30d158", opacity: 0.35 }}>✓</div>
                  <div style={{ fontSize: 11, color: "#30d158", marginBottom: 10, letterSpacing: 1.5 }}>
                    ALL CLEAR
                  </div>
                  <div style={{ lineHeight: 1.9, color: "rgba(200,223,240,0.35)" }}>
                    No upcoming conjunctions for your {watched.length} watched satellite{watched.length > 1 ? "s" : ""}.
                    <br />Checks run every 6 hours.
                  </div>
                </>
              )}
            </div>
          )}

          {alerts.map(alert => (
            <AlertCard key={alert.id} alert={alert} onTrack={onTrack} />
          ))}
        </div>

        {/* ── Right: watch list ──────────────────────────────────────── */}
        <div style={{ width: 296, overflowY: "auto", padding: "24px 16px", flexShrink: 0 }}>

          {/* Panel header */}
          <div style={{ display: "flex", alignItems: "baseline", justifyContent: "space-between", marginBottom: 14 }}>
            <div style={{ color: "rgba(0,212,255,0.65)", fontSize: 9, letterSpacing: 2 }}>
              WATCHED SATELLITES
            </div>
            {watched.length > 0 && (
              <div style={{ color: "rgba(0,212,255,0.3)", fontSize: 8 }}>
                {watched.length} / ∞
              </div>
            )}
          </div>

          <SatelliteSearchPicker onWatched={load} watchedNoradIds={watchedNoradIds} />

          {/* Empty watch list placeholder */}
          {watched.length === 0 && (
            <div style={{
              border: "1px dashed rgba(0,212,255,0.1)", borderRadius: 5,
              padding: "22px 14px", textAlign: "center", marginTop: 4,
            }}>
              <div style={{ fontSize: 20, marginBottom: 8, opacity: 0.12 }}>📡</div>
              <div style={{ color: "rgba(0,212,255,0.32)", fontSize: 9, letterSpacing: 1.5, marginBottom: 6 }}>
                EMPTY
              </div>
              <div style={{ color: "rgba(200,223,240,0.25)", fontSize: 8, lineHeight: 1.8 }}>
                Type a satellite name or NORAD ID above,<br />
                or click a quick-pick to start monitoring.
              </div>
            </div>
          )}

          {watched.map(sat => (
            <WatchedSatRow key={sat.id} sat={sat} onUnwatch={unwatch} />
          ))}

          {/* Risk legend */}
          <div style={{ marginTop: 24, borderTop: "1px solid rgba(0,212,255,0.07)", paddingTop: 16 }}>
            <div style={{ color: "rgba(0,212,255,0.35)", fontSize: 8, letterSpacing: 1.5, marginBottom: 10 }}>
              RISK SCALE
            </div>
            {[
              ["HIGH",   "#ff3b30", "score ≥ 70 / miss < 1 km"],
              ["MEDIUM", "#ff9500", "score ≥ 40 / miss < 3 km"],
              ["LOW",    "#30d158", "score < 40 / miss < 5 km"],
            ].map(([label, color, note]) => (
              <div key={label} style={{ display: "flex", alignItems: "center", gap: 7, marginBottom: 7 }}>
                <div style={{ width: 7, height: 7, borderRadius: "50%", background: color, flexShrink: 0 }} />
                <div>
                  <span style={{ color, fontSize: 8, fontWeight: 700 }}>{label}</span>
                  <span style={{ color: "rgba(200,223,240,0.28)", fontSize: 8 }}> — {note}</span>
                </div>
              </div>
            ))}
          </div>

          {/* Data notes */}
          <div style={{ marginTop: 16, color: "rgba(200,223,240,0.18)", fontSize: 8, lineHeight: 1.8 }}>
            <div>Conjunction data: Space-Track CDM</div>
            <div>Fallback: SGP4 propagation (TLE-based)</div>
            <div>Updated every 6 hours · 5-day horizon</div>
            <div style={{ marginTop: 6, color: "rgba(200,223,240,0.1)" }}>
              Search: local catalog + CelesTrak
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
