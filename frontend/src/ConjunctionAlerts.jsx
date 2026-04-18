import { useEffect, useState, useCallback } from "react";
import client from "./api/client";

const STYLE = `
  @import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;900&family=JetBrains+Mono:wght@300;400;500&display=swap');
`;

const RISK_COLOR = { HIGH: "#ff3b30", MEDIUM: "#ff9500", LOW: "#30d158" };

// ── Helpers ──────────────────────────────────────────────────────────────────

function formatCountdown(hoursUntil) {
  if (hoursUntil < 0)    return "PAST";
  if (hoursUntil < 1)    return `${Math.round(hoursUntil * 60)}m`;
  if (hoursUntil < 24)   return `${hoursUntil.toFixed(1)}h`;
  const days  = Math.floor(hoursUntil / 24);
  const hours = Math.floor(hoursUntil % 24);
  return `${days}d ${hours}h`;
}

function formatTca(iso) {
  const d = new Date(iso);
  return d.toUTCString().replace(" GMT", " UTC").replace(/:\d\d /, " ");
}

// ── Components ────────────────────────────────────────────────────────────────

function AlertCard({ alert, onTrack }) {
  const color   = RISK_COLOR[alert.risk_level] ?? "#8b949e";
  const percent = alert.risk_score;

  return (
    <div style={{
      background: "rgba(0,0,0,0.6)",
      border: `1px solid ${color}40`,
      borderLeft: `3px solid ${color}`,
      borderRadius: 6,
      padding: "14px 16px",
      marginBottom: 10,
      fontFamily: "'JetBrains Mono', monospace",
    }}>
      {/* Header */}
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", marginBottom: 8 }}>
        <div>
          <div style={{ color: "#c8dff0", fontSize: 11, fontWeight: 500 }}>
            {alert.primary_name}
          </div>
          <div style={{ color: "rgba(200,223,240,0.5)", fontSize: 9, marginTop: 2 }}>
            NORAD {alert.primary_norad_id}
          </div>
        </div>
        <span style={{
          background: `${color}20`,
          border: `1px solid ${color}60`,
          borderRadius: 3,
          color,
          fontSize: 8,
          fontWeight: 700,
          letterSpacing: 1.5,
          padding: "3px 7px",
        }}>
          {alert.risk_level}
        </span>
      </div>

      {/* Threat */}
      <div style={{ color: "rgba(200,223,240,0.6)", fontSize: 9, marginBottom: 10 }}>
        ↔ <span style={{ color: "#ff6b6b" }}>{alert.secondary_name}</span>
        <span style={{ color: "rgba(200,223,240,0.3)" }}> · NORAD {alert.secondary_norad_id}</span>
      </div>

      {/* Risk bar */}
      <div style={{ marginBottom: 10 }}>
        <div style={{ height: 3, background: "rgba(255,255,255,0.08)", borderRadius: 2 }}>
          <div style={{
            height: "100%",
            width: `${percent}%`,
            background: `linear-gradient(90deg, ${color}80, ${color})`,
            borderRadius: 2,
            transition: "width 0.6s ease",
          }} />
        </div>
        <div style={{ display: "flex", justifyContent: "space-between", marginTop: 3 }}>
          <span style={{ color: "rgba(200,223,240,0.3)", fontSize: 8 }}>RISK</span>
          <span style={{ color, fontSize: 8 }}>{percent}/100</span>
        </div>
      </div>

      {/* Stats grid */}
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 8, marginBottom: 10 }}>
        {[
          { label: "MISS DIST", value: `${alert.miss_distance_km} km` },
          { label: "TCA IN",    value: formatCountdown(alert.hours_until_tca) },
          { label: "PROB",      value: alert.probability != null ? `${(alert.probability * 100).toFixed(2)}%` : "N/A" },
        ].map(({ label, value }) => (
          <div key={label} style={{
            background: "rgba(0,212,255,0.04)",
            border: "1px solid rgba(0,212,255,0.1)",
            borderRadius: 4,
            padding: "6px 8px",
            textAlign: "center",
          }}>
            <div style={{ color: "rgba(0,212,255,0.5)", fontSize: 7, letterSpacing: 1, marginBottom: 2 }}>{label}</div>
            <div style={{ color: "#c8dff0", fontSize: 10, fontWeight: 500 }}>{value}</div>
          </div>
        ))}
      </div>

      {/* TCA */}
      <div style={{ color: "rgba(200,223,240,0.4)", fontSize: 8, marginBottom: 10 }}>
        TCA: {formatTca(alert.tca)}
      </div>

      {/* Track button */}
      <button
        onClick={() => onTrack(alert.primary_norad_id)}
        style={{
          width: "100%",
          padding: "6px 0",
          background: "rgba(0,212,255,0.08)",
          border: "1px solid rgba(0,212,255,0.3)",
          borderRadius: 4,
          color: "#00d4ff",
          fontSize: 9,
          letterSpacing: 1.5,
          cursor: "pointer",
          fontFamily: "'JetBrains Mono', monospace",
        }}
      >
        TRACK SATELLITE
      </button>
    </div>
  );
}

// ── Watch form ────────────────────────────────────────────────────────────────

function WatchForm({ onWatched }) {
  const [noradId, setNoradId] = useState("");
  const [busy, setBusy]       = useState(false);
  const [err, setErr]         = useState(null);

  async function submit(e) {
    e.preventDefault();
    if (! noradId.trim()) return;
    setBusy(true);
    setErr(null);
    try {
      await client.post("/watch", { norad_id: noradId.trim() });
      setNoradId("");
      onWatched();
    } catch (err) {
      setErr(err.message ?? "Failed to add satellite.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} style={{ display: "flex", gap: 6, marginBottom: 16 }}>
      <input
        value={noradId}
        onChange={e => setNoradId(e.target.value)}
        placeholder="NORAD ID (e.g. 25544)"
        style={{
          flex: 1,
          background: "rgba(0,212,255,0.06)",
          border: "1px solid rgba(0,212,255,0.25)",
          borderRadius: 4,
          color: "#c8dff0",
          fontFamily: "'JetBrains Mono', monospace",
          fontSize: 10,
          padding: "7px 10px",
          outline: "none",
        }}
      />
      <button
        type="submit"
        disabled={busy}
        style={{
          background: "rgba(0,212,255,0.12)",
          border: "1px solid rgba(0,212,255,0.4)",
          borderRadius: 4,
          color: "#00d4ff",
          cursor: busy ? "not-allowed" : "pointer",
          fontFamily: "'JetBrains Mono', monospace",
          fontSize: 9,
          letterSpacing: 1,
          padding: "7px 14px",
          opacity: busy ? 0.5 : 1,
        }}
      >
        {busy ? "…" : "WATCH"}
      </button>
      {err && <div style={{ color: "#ff3b30", fontSize: 9, alignSelf: "center" }}>{err}</div>}
    </form>
  );
}

// ── Main component ────────────────────────────────────────────────────────────

export default function ConjunctionAlerts({ onTrack }) {
  const [alerts, setAlerts]   = useState([]);
  const [watched, setWatched] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError]     = useState(null);

  // Inject fonts
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
      setWatched(watchRes.data.data ?? watchRes.data ?? []);
    } catch (err) {
      setError(err.message ?? "Could not reach the API.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  async function unwatch(id) {
    await client.delete(`/watch/${id}`);
    load();
  }

  const highCount = alerts.filter(a => a.risk_level === "HIGH").length;

  return (
    <div style={{
      width: "100%",
      height: "100%",
      background: "#020810",
      display: "flex",
      flexDirection: "column",
      overflow: "hidden",
      fontFamily: "'JetBrains Mono', monospace",
    }}>
      <div style={{ display: "flex", flex: 1, overflow: "hidden" }}>

        {/* ── Left: alerts feed ──────────────────────────────────────── */}
        <div style={{
          flex: 1,
          overflowY: "auto",
          padding: "24px 20px",
          borderRight: "1px solid rgba(0,212,255,0.1)",
        }}>
          {/* Header */}
          <div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 20 }}>
            <div style={{ color: "#00d4ff", fontSize: 13, fontWeight: 600, letterSpacing: 2 }}>
              CONJUNCTION ALERTS
            </div>
            {highCount > 0 && (
              <span style={{
                background: "#ff3b3020",
                border: "1px solid #ff3b3060",
                borderRadius: 10,
                color: "#ff3b30",
                fontSize: 9,
                fontWeight: 700,
                padding: "2px 8px",
              }}>
                {highCount} HIGH RISK
              </span>
            )}
            <button
              onClick={load}
              disabled={loading}
              style={{
                marginLeft: "auto",
                background: "transparent",
                border: "1px solid rgba(0,212,255,0.2)",
                borderRadius: 4,
                color: "rgba(0,212,255,0.6)",
                cursor: loading ? "not-allowed" : "pointer",
                fontSize: 8,
                letterSpacing: 1,
                padding: "4px 10px",
              }}
            >
              {loading ? "…" : "REFRESH"}
            </button>
          </div>

          {error && (
            <div style={{ color: "#ff3b30", fontSize: 10, marginBottom: 16, padding: "10px 14px", border: "1px solid #ff3b3040", borderRadius: 4 }}>
              {error}
            </div>
          )}

          {! loading && alerts.length === 0 && (
            <div style={{ color: "rgba(200,223,240,0.3)", fontSize: 10, textAlign: "center", paddingTop: 40 }}>
              {watched.length === 0 ? (
                <>
                  <div style={{ fontSize: 11, color: "rgba(0,212,255,0.4)", marginBottom: 10 }}>No satellites being monitored</div>
                  Add a satellite NORAD ID to your watch list to start receiving conjunction alerts.
                </>
              ) : (
                <>
                  <div style={{ fontSize: 11, color: "#30d158", marginBottom: 10 }}>✓ All clear</div>
                  No upcoming conjunctions detected for your watched satellites.
                </>
              )}
            </div>
          )}

          {alerts.map(alert => (
            <AlertCard key={alert.id} alert={alert} onTrack={onTrack} />
          ))}
        </div>

        {/* ── Right: watched satellites ──────────────────────────────── */}
        <div style={{ width: 280, overflowY: "auto", padding: "24px 16px" }}>
          <div style={{ color: "rgba(0,212,255,0.7)", fontSize: 9, letterSpacing: 2, marginBottom: 14 }}>
            WATCHED SATELLITES
          </div>

          <WatchForm onWatched={load} />

          {watched.length === 0 && (
            <div style={{ color: "rgba(200,223,240,0.25)", fontSize: 9, textAlign: "center", paddingTop: 8 }}>
              No satellites on watch list.<br />
              <span style={{ color: "rgba(0,212,255,0.3)" }}>Enter a NORAD ID above.</span>
            </div>
          )}

          {watched.map(sat => (
            <div key={sat.id} style={{
              background: "rgba(0,212,255,0.04)",
              border: "1px solid rgba(0,212,255,0.12)",
              borderRadius: 5,
              padding: "9px 12px",
              marginBottom: 8,
              display: "flex",
              justifyContent: "space-between",
              alignItems: "center",
            }}>
              <div>
                <div style={{ color: "#c8dff0", fontSize: 10 }}>{sat.name ?? sat.norad_id}</div>
                <div style={{ color: "rgba(200,223,240,0.35)", fontSize: 8, marginTop: 2 }}>
                  {sat.norad_id} · {sat.tle_fresh ? (
                    <span style={{ color: "#30d158" }}>TLE fresh</span>
                  ) : (
                    <span style={{ color: "#ff9500" }}>TLE stale</span>
                  )}
                </div>
              </div>
              <button
                onClick={() => unwatch(sat.id)}
                title="Stop watching"
                style={{
                  background: "transparent",
                  border: "1px solid rgba(255,59,48,0.3)",
                  borderRadius: 3,
                  color: "rgba(255,59,48,0.6)",
                  cursor: "pointer",
                  fontSize: 9,
                  padding: "3px 8px",
                }}
              >
                ✕
              </button>
            </div>
          ))}

          {/* Legend */}
          <div style={{ marginTop: 24, borderTop: "1px solid rgba(0,212,255,0.08)", paddingTop: 16 }}>
            <div style={{ color: "rgba(0,212,255,0.4)", fontSize: 8, letterSpacing: 1, marginBottom: 8 }}>RISK SCALE</div>
            {[
              ["HIGH",   "#ff3b30", "risk ≥ 70 / miss < 1 km"],
              ["MEDIUM", "#ff9500", "risk ≥ 40 / miss < 3 km"],
              ["LOW",    "#30d158", "risk < 40 / miss < 5 km"],
            ].map(([label, color, note]) => (
              <div key={label} style={{ display: "flex", alignItems: "center", gap: 6, marginBottom: 6 }}>
                <div style={{ width: 6, height: 6, borderRadius: "50%", background: color }} />
                <div>
                  <span style={{ color, fontSize: 8, fontWeight: 700 }}>{label}</span>
                  <span style={{ color: "rgba(200,223,240,0.3)", fontSize: 8 }}> — {note}</span>
                </div>
              </div>
            ))}
          </div>

          <div style={{ marginTop: 16, color: "rgba(200,223,240,0.2)", fontSize: 8, lineHeight: 1.5 }}>
            Checks run every 6 h via<br />
            <code style={{ color: "rgba(0,212,255,0.4)" }}>conjunctions:check</code><br />
            5-day horizon · 5-minute steps
          </div>
        </div>
      </div>
    </div>
  );
}
