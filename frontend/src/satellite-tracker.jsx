import { useState, useEffect, useRef } from "react";
import * as THREE from "three";
import * as satellite from "satellite.js";
import client from "./api/client";

const STYLE = `
  @import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;900&family=JetBrains+Mono:wght@300;400;500&display=swap');
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #020810; }

  .tracker-root {
    display: flex;
    height: 100vh;
    width: 100vw;
    background: #020810;
    font-family: 'JetBrains Mono', monospace;
    color: #c8dff0;
    overflow: hidden;
  }

  .globe-wrap {
    flex: 1;
    position: relative;
    cursor: grab;
  }
  .globe-wrap:active { cursor: grabbing; }

  .scanline {
    position: absolute;
    inset: 0;
    background: repeating-linear-gradient(
      to bottom,
      transparent 0px,
      transparent 3px,
      rgba(0,212,255,0.015) 3px,
      rgba(0,212,255,0.015) 4px
    );
    pointer-events: none;
    z-index: 2;
  }

  .corner-hud {
    position: absolute;
    width: 40px;
    height: 40px;
    pointer-events: none;
    z-index: 3;
    opacity: 0.5;
  }
  .corner-hud.tl { top: 16px; left: 16px; border-top: 1px solid #00d4ff; border-left: 1px solid #00d4ff; }
  .corner-hud.tr { top: 16px; right: 16px; border-top: 1px solid #00d4ff; border-right: 1px solid #00d4ff; }
  .corner-hud.bl { bottom: 16px; left: 16px; border-bottom: 1px solid #00d4ff; border-left: 1px solid #00d4ff; }
  .corner-hud.br { bottom: 16px; right: 16px; border-bottom: 1px solid #00d4ff; border-right: 1px solid #00d4ff; }

  .globe-label {
    position: absolute;
    bottom: 28px;
    left: 50%;
    transform: translateX(-50%);
    font-family: 'Orbitron', sans-serif;
    font-size: 9px;
    letter-spacing: 4px;
    color: rgba(0,212,255,0.3);
    pointer-events: none;
    z-index: 3;
  }

  .drag-hint {
    position: absolute;
    top: 20px;
    left: 50%;
    transform: translateX(-50%);
    font-size: 9px;
    letter-spacing: 2px;
    color: rgba(0,212,255,0.25);
    pointer-events: none;
    z-index: 3;
  }

  /* Panel */
  .panel {
    width: 340px;
    background: rgba(2, 10, 22, 0.97);
    border-left: 1px solid rgba(0,212,255,0.12);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    z-index: 10;
  }

  .panel-header {
    padding: 20px 20px 16px;
    border-bottom: 1px solid rgba(0,212,255,0.1);
    background: rgba(0, 20, 50, 0.4);
  }

  .panel-title {
    font-family: 'Orbitron', sans-serif;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 3px;
    color: #00d4ff;
    margin-bottom: 4px;
  }

  .panel-subtitle {
    font-size: 9px;
    color: rgba(0,212,255,0.35);
    letter-spacing: 2px;
  }

  .panel-body {
    flex: 1;
    overflow-y: auto;
    padding: 0 0 16px;
    scrollbar-width: thin;
    scrollbar-color: rgba(0,212,255,0.15) transparent;
  }

  .section {
    padding: 14px 20px;
    border-bottom: 1px solid rgba(0,212,255,0.06);
  }

  .section-label {
    font-size: 8px;
    letter-spacing: 3px;
    color: rgba(0,212,255,0.35);
    margin-bottom: 10px;
    text-transform: uppercase;
  }

  /* Input */
  .input-row {
    display: flex;
    gap: 8px;
    align-items: center;
  }

  .norad-input {
    flex: 1;
    background: rgba(0,212,255,0.05);
    border: 1px solid rgba(0,212,255,0.2);
    color: #00d4ff;
    font-family: 'JetBrains Mono', monospace;
    font-size: 13px;
    padding: 8px 32px 8px 12px;
    outline: none;
    transition: border-color 0.2s;
    letter-spacing: 1px;
  }
  .norad-input:focus { border-color: rgba(0,212,255,0.6); }
  .norad-input::placeholder { color: rgba(0,212,255,0.2); font-size: 10px; }

  .quick-ids {
    display: flex;
    gap: 6px;
    margin-top: 8px;
    flex-wrap: wrap;
  }

  .quick-id {
    font-size: 9px;
    padding: 3px 8px;
    background: rgba(0,212,255,0.05);
    border: 1px solid rgba(0,212,255,0.12);
    color: rgba(0,212,255,0.5);
    cursor: pointer;
    letter-spacing: 1px;
    transition: all 0.15s;
  }
  .quick-id:hover { background: rgba(0,212,255,0.1); color: #00d4ff; border-color: rgba(0,212,255,0.3); }
  .quick-id:active:not(:disabled) { transform: scale(0.96); }
  .quick-id:disabled { opacity: 0.4; cursor: not-allowed; }

  .input-clear-btn {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: rgba(0,212,255,0.35);
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    padding: 2px 4px;
    transition: color 0.12s;
  }
  .input-clear-btn:hover { color: rgba(0,212,255,0.8); }

  .search-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    z-index: 50;
    background: #050f1e;
    border: 1px solid rgba(0,212,255,0.22);
    border-radius: 4px;
    max-height: 220px;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: rgba(0,212,255,0.1) transparent;
    box-shadow: 0 8px 24px rgba(0,0,0,0.6);
  }

  .search-dropdown-item {
    padding: 9px 12px;
    cursor: pointer;
    border-bottom: 1px solid rgba(0,212,255,0.06);
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: background 0.08s;
    user-select: none;
  }
  .search-dropdown-item:last-child { border-bottom: none; }
  .search-dropdown-item:hover,
  .search-dropdown-item.is-active { background: rgba(0,212,255,0.09); }

  .search-dropdown-hint {
    padding: 5px 12px 7px;
    font-size: 7px;
    letter-spacing: 0.5px;
    color: rgba(0,212,255,0.2);
    border-top: 1px solid rgba(0,212,255,0.06);
  }

  /* Error */
  .error-box {
    background: rgba(255,59,48,0.08);
    border: 1px solid rgba(255,59,48,0.25);
    color: #ff6b60;
    font-size: 10px;
    padding: 8px 12px;
    margin-top: 8px;
    letter-spacing: 0.5px;
    line-height: 1.5;
  }

  /* Loading */
  .loading-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 0;
    font-size: 10px;
    color: rgba(0,212,255,0.4);
    letter-spacing: 1px;
  }
  .spinner {
    width: 12px;
    height: 12px;
    border: 1px solid rgba(0,212,255,0.2);
    border-top: 1px solid #00d4ff;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg); } }

  /* Sat info */
  .sat-name {
    font-family: 'Orbitron', sans-serif;
    font-size: 12px;
    font-weight: 600;
    color: #00d4ff;
    margin-bottom: 12px;
    letter-spacing: 1px;
  }

  .data-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
  }

  .data-cell {
    background: rgba(0,212,255,0.04);
    border: 1px solid rgba(0,212,255,0.08);
    padding: 8px;
  }

  .data-cell-label {
    font-size: 8px;
    color: rgba(0,212,255,0.35);
    letter-spacing: 2px;
    margin-bottom: 4px;
  }

  .data-cell-value {
    font-size: 13px;
    color: #a8d4f0;
    font-weight: 500;
  }

  .data-cell-unit {
    font-size: 9px;
    color: rgba(0,212,255,0.3);
    margin-left: 2px;
  }

  /* Risk indicator */
  .risk-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border: 1px solid;
    margin-bottom: 0;
  }

  .risk-label {
    font-family: 'Orbitron', sans-serif;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 2px;
  }

  .risk-score-big {
    font-family: 'Orbitron', sans-serif;
    font-size: 22px;
    font-weight: 900;
    line-height: 1;
  }

  .risk-bar-track {
    height: 3px;
    background: rgba(255,255,255,0.05);
    margin-top: 10px;
    position: relative;
  }
  .risk-bar-fill {
    height: 100%;
    transition: width 0.8s ease;
  }

  /* Debris list */
  .debris-item {
    padding: 10px 0;
    border-bottom: 1px solid rgba(0,212,255,0.05);
    display: flex;
    align-items: flex-start;
    gap: 10px;
    cursor: default;
    transition: background 0.15s;
  }
  .debris-item:hover { background: rgba(0,212,255,0.02); }
  .debris-item:last-child { border-bottom: none; }

  .debris-risk-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-top: 3px;
    flex-shrink: 0;
    animation: pulse-dot 2s ease-in-out infinite;
  }
  @keyframes pulse-dot {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
  }

  .debris-id {
    font-size: 10px;
    color: #a8d4f0;
    font-weight: 500;
    letter-spacing: 1px;
    margin-bottom: 3px;
  }

  .debris-stats {
    font-size: 9px;
    color: rgba(0,212,255,0.35);
    letter-spacing: 0.5px;
    line-height: 1.6;
  }

  .debris-tca {
    margin-left: auto;
    font-size: 8px;
    color: rgba(0,212,255,0.25);
    text-align: right;
    letter-spacing: 0.5px;
  }

  /* Mock data badge */
  .mock-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 8px;
    color: rgba(255,149,0,0.6);
    letter-spacing: 1px;
    margin-bottom: 8px;
  }
  .mock-badge::before {
    content: '';
    width: 4px;
    height: 4px;
    background: rgba(255,149,0,0.6);
    border-radius: 50%;
  }

  .live-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 8px;
    color: rgba(48,209,88,0.7);
    letter-spacing: 1px;
    margin-bottom: 8px;
  }
  .live-badge::before {
    content: '';
    width: 4px;
    height: 4px;
    background: #30d158;
    border-radius: 50%;
    animation: blink 1.5s ease-in-out infinite;
  }
  @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.2} }

  .empty-state {
    padding: 24px 20px;
    text-align: center;
    color: rgba(0,212,255,0.18);
    font-size: 10px;
    letter-spacing: 1.5px;
    line-height: 2;
  }

  .panel-footer {
    padding: 12px 20px;
    border-top: 1px solid rgba(0,212,255,0.08);
    font-size: 8px;
    color: rgba(0,212,255,0.2);
    letter-spacing: 1px;
  }

  /* ─── Responsive layout ─────────────────────────────────────────────── */

  /* Tablet: narrow panel */
  @media (max-width: 768px) {
    .panel { width: 260px; min-width: 260px; }
  }

  /* Mobile: globe on top, panel below */
  @media (max-width: 600px) {
    .tracker-root { flex-direction: column; }

    .globe-wrap {
      height: 50vh;
      flex: none;
      width: 100%;
    }

    .panel {
      width: 100% !important;
      min-width: unset;
      height: 50vh;
      border-left: none;
      border-top: 1px solid rgba(0,212,255,0.12);
      overflow: hidden;
    }

    /* Prevent iOS auto-zoom on focus */
    .norad-input { font-size: 16px !important; }

    /* Error message — ensure it wraps instead of overflowing */
    .error-box { word-break: break-word; font-size: 9px; }

    /* Quick-sat buttons: tighter wrap */
    .quick-ids { gap: 4px; }
  }
`;

const QUICK_SATS = [
  { id: "25544", label: "ISS" },
  { id: "20580", label: "Hubble" },
  { id: "41866", label: "GOES-16" },
  { id: "48274", label: "Tianhe" },
];

function latLonAltToVec3(lat, lon, alt) {
  const earthRadius = 6371;
  const r = (earthRadius + alt) / earthRadius;
  const latRad = (lat * Math.PI) / 180;
  const lonRad = (lon * Math.PI) / 180;
  return new THREE.Vector3(
    r * Math.cos(latRad) * Math.cos(lonRad),
    r * Math.sin(latRad),
    r * Math.cos(latRad) * Math.sin(lonRad)
  );
}

function getRiskColor(level) {
  if (level === "HIGH") return "#ff3b30";
  if (level === "MEDIUM") return "#ff9500";
  return "#30d158";
}

function createSatelliteTexture(colorHex) {
  const canvas = document.createElement("canvas");
  canvas.width = canvas.height = 64;
  const ctx = canvas.getContext("2d");
  const cx = 32, cy = 32;

  // Soft glow halo
  const glow = ctx.createRadialGradient(cx, cy, 0, cx, cy, 24);
  glow.addColorStop(0,   colorHex + "bb");
  glow.addColorStop(0.4, colorHex + "44");
  glow.addColorStop(1,   colorHex + "00");
  ctx.fillStyle = glow;
  ctx.fillRect(0, 0, 64, 64);

  // Satellite body
  ctx.fillStyle = colorHex;
  ctx.fillRect(cx - 6, cy - 5, 12, 10);

  // Left solar panel
  ctx.fillRect(cx - 20, cy - 2, 12, 4);

  // Right solar panel
  ctx.fillRect(cx + 8,  cy - 2, 12, 4);

  // Center highlight dot
  ctx.fillStyle = "#ffffff";
  ctx.fillRect(cx - 2, cy - 2, 4, 4);

  return canvas;
}

/**
 * Fetch a TLE-derived satellite.js satrec via the internal API.
 * Uses local DB first; backend falls back to CelesTrak only when the satellite
 * is not in the catalog. Returns null on any error — callers use approx fallback.
 */
async function fetchSecondaryTle(noradId) {
  if (!noradId) return null;
  try {
    const res  = await client.get(`/satellites/${noradId}`);
    const data = res.data?.data ?? res.data;
    if (!data?.tle_line1 || !data?.tle_line2) return null;
    const satrec = satellite.twoline2satrec(data.tle_line1, data.tle_line2);
    return satrec.error !== 0 ? null : satrec;
  } catch {
    return null;
  }
}

/**
 * Approximate screen position for a nearby object when TLE is unavailable.
 * Places markers in an even ring around the primary satellite's Earth-local position.
 * NOT a real orbital position — purely a visual placeholder until TLE is fetched.
 */
function approxNearbyPosition(satPos, index, count) {
  const angle  = (index / Math.max(count, 1)) * Math.PI * 2;
  const spread = 0.09; // ~540 km at ISS altitude — tight cluster, clearly approximate
  return new THREE.Vector3(
    satPos.x + spread * Math.cos(angle),
    satPos.y + spread * Math.sin(angle * 0.4) * 0.25,
    satPos.z + spread * Math.sin(angle),
  );
}

/** Small sphere marker for a nearby/conjunction object. One per object (not a point cloud). */
function createNearbyMarker(colorHex) {
  return new THREE.Mesh(
    new THREE.SphereGeometry(0.014, 8, 8),
    new THREE.MeshBasicMaterial({ color: new THREE.Color(colorHex), transparent: true, opacity: 0.85 }),
  );
}

function riskFromScore(score) {
  if (score > 60) return { label: "HIGH RISK", color: "#ff3b30", bg: "rgba(255,59,48,0.08)", border: "rgba(255,59,48,0.25)" };
  if (score > 30) return { label: "MODERATE", color: "#ff9500", bg: "rgba(255,149,0,0.08)", border: "rgba(255,149,0,0.25)" };
  return { label: "LOW RISK", color: "#30d158", bg: "rgba(48,209,88,0.06)", border: "rgba(48,209,88,0.2)" };
}

async function fetchConjunctions(noradId) {
  const res = await client.get(`/conjunctions/${noradId}`);
  // X-Guest-Requests-Remaining is only present for unauthenticated (guest) requests.
  // axios normalises header names to lowercase.
  const remainingHeader = res.headers['x-guest-requests-remaining'];
  const remaining = remainingHeader !== undefined ? parseInt(remainingHeader, 10) : null;

  return {
    objects: res.data.data.objects.map((obj) => ({
      id:               obj.object_id,
      secondaryNoradId: obj.secondary_norad_id ?? null,
      missKm:           obj.miss_km,
      prob:             obj.probability,
      riskScore:        obj.risk_score,
      riskLevel:        obj.risk_level,
      tca:              obj.tca,
      alt:              obj.altitude_km,
    })),
    remaining,
    source: res.data.data.source ?? 'simulated',
  };
}

function generateMockDebris(satLat, satLon, satAlt) {
  const debris = [];
  for (let i = 0; i < 9; i++) {
    const latOff = (Math.random() - 0.5) * 12;
    const lonOff = (Math.random() - 0.5) * 12;
    const altOff = (Math.random() - 0.5) * 250;
    const dLat = satLat + latOff;
    const dLon = satLon + lonOff;
    const dAlt = satAlt + altOff;
    const dist = Math.sqrt(latOff ** 2 + lonOff ** 2) * 111 + Math.abs(altOff);
    const missKm = (dist * 2 + Math.random() * 300).toFixed(1);
    const prob = Math.max(0.000001, (1 / (missKm * 0.4)) * Math.random() * 0.01).toFixed(7);
    const riskScore = Math.min(95, Math.round(100 / (missKm * 0.08 + 1)));
    const riskLevel = riskScore > 60 ? "HIGH" : riskScore > 30 ? "MEDIUM" : "LOW";
    debris.push({
      id: `DEB-${Math.random().toString(36).slice(2, 7).toUpperCase()}`,
      lat: dLat, lon: dLon, alt: dAlt.toFixed(0),
      missKm, prob, riskScore, riskLevel,
      tca: new Date(Date.now() + Math.random() * 86400000 * 5).toISOString().slice(0, 10),
    });
  }
  return debris.sort((a, b) => b.riskScore - a.riskScore);
}

export default function SatelliteTracker({
  initialNoradId   = "25544",
  savedSats        = [],
  onSatelliteAdded  = null,
  onSatelliteRemoved = null,
}) {
  const mountRef = useRef(null);
  const sceneRef = useRef(null);
  const rendererRef = useRef(null);
  const earthRef = useRef(null);
  const atmRef = useRef(null);
  const frameRef = useRef(null);
  const trackedSatsRef   = useRef([]);          // [{id, name, satrec, colorHex}]
  const satMeshesRef     = useRef({});           // {noradId: {dot, ring, orbit}}
  const searchTimerRef   = useRef(null);
  const conjLoadCountRef = useRef(0);
  const searchWrapRef = useRef(null);
  const dropItemRefs  = useRef([]);

  const [searchQuery,   setSearchQuery]   = useState(initialNoradId);
  const [searchResults, setSearchResults] = useState([]);
  const [searching,     setSearching]     = useState(false);
  // TODO: persist tracked satellites via /api/watch (future feature) — currently session-only
  const [trackedSats,   setTrackedSats]   = useState([]);
  const [error,         setError]         = useState(null);
  const [debris,              setDebris]              = useState([]);
  const [overallRisk,         setOverallRisk]         = useState(null);
  const [conjunctionsLoading, setConjunctionsLoading] = useState(false);
  const [guestLimitReached,   setGuestLimitReached]   = useState(false);
  // null = authenticated user (no quota); 0–9 = guest with N analyses remaining today
  const [guestRemaining,      setGuestRemaining]      = useState(null);
  // 'simulated' (Phase 1 risk scores) or 'live' (real CDM data, Phase 2)
  const [conjSource,          setConjSource]          = useState('simulated');

  const [trackingId,        setTrackingId]        = useState(null);
  const [dropdownOpen,      setDropdownOpen]       = useState(false);
  const [dropdownActiveIdx, setDropdownActiveIdx]  = useState(-1);

  // Inject styles
  useEffect(() => {
    const styleEl = document.createElement("style");
    styleEl.textContent = STYLE;
    document.head.appendChild(styleEl);
    return () => { document.head.removeChild(styleEl); };
  }, []);

  // Close search dropdown on outside click
  useEffect(() => {
    function handler(e) {
      if (searchWrapRef.current && !searchWrapRef.current.contains(e.target)) {
        setDropdownOpen(false);
        setDropdownActiveIdx(-1);
      }
    }
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  // Scroll keyboard-highlighted dropdown item into view
  useEffect(() => {
    if (dropdownActiveIdx >= 0 && dropItemRefs.current[dropdownActiveIdx]) {
      dropItemRefs.current[dropdownActiveIdx].scrollIntoView({ block: 'nearest' });
    }
  }, [dropdownActiveIdx]);

  // On mount: restore savedSats (state lifted to parent, survives view switches) then load
  // initialNoradId if not already in the restored list. Three.js initialises synchronously;
  // async TLE fetches resolve after. Uses internal API (local DB → CelesTrak fallback).
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => {
    const toLoad = new Map(); // noradId -> hint name
    for (const s of savedSats) toLoad.set(s.id, s.name);
    if (initialNoradId && !toLoad.has(initialNoradId)) toLoad.set(initialNoradId, null);
    if (!toLoad.size) return;
    (async () => {
      for (const [id, hint] of toLoad) {
        try {
          const res  = await client.get(`/satellites/${id}`);
          const data = res.data?.data ?? res.data;
          if (data?.tle_line1 && data?.tle_line2) {
            addSatellite(data.name ?? hint ?? id, id, data.tle_line1, data.tle_line2);
          }
        } catch { /* ignore individual failures */ }
      }
    })();
  }, []); // intentional: fire once on mount

  // Init Three.js
  useEffect(() => {
    if (!mountRef.current) return;
    const el = mountRef.current;
    const w = el.clientWidth, h = el.clientHeight;

    const scene = new THREE.Scene();
    sceneRef.current = scene;

    const camera = new THREE.PerspectiveCamera(42, w / h, 0.1, 1000);
    camera.position.set(0, 0, 3.2);

    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setSize(w, h);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.setClearColor(0x000000, 0);
    el.appendChild(renderer.domElement);
    rendererRef.current = renderer;

    // Stars
    const starPositions = [];
    for (let i = 0; i < 3000; i++) {
      const r = 60 + Math.random() * 40;
      const theta = Math.random() * Math.PI * 2;
      const phi = Math.acos(2 * Math.random() - 1);
      starPositions.push(
        r * Math.sin(phi) * Math.cos(theta),
        r * Math.sin(phi) * Math.sin(theta),
        r * Math.cos(phi)
      );
    }
    const starGeo = new THREE.BufferGeometry();
    starGeo.setAttribute("position", new THREE.Float32BufferAttribute(starPositions, 3));
    const sizes = new Float32Array(3000).map(() => Math.random() * 1.5 + 0.3);
    starGeo.setAttribute("size", new THREE.Float32BufferAttribute(sizes, 1));
    scene.add(new THREE.Points(starGeo, new THREE.PointsMaterial({ color: 0xffffff, size: 0.06, transparent: true, opacity: 0.7 })));

    // Earth
    const earthGeo = new THREE.SphereGeometry(1, 64, 64);
    const earthMat = new THREE.MeshPhongMaterial({
      color: 0x1a4a8a,
      emissive: 0x050e20,
      specular: 0x2266bb,
      shininess: 12,
    });
    const earth = new THREE.Mesh(earthGeo, earthMat);
    scene.add(earth);
    earthRef.current = earth;

    // Try loading real texture
    new THREE.TextureLoader().load(
      "https://raw.githubusercontent.com/mrdoob/three.js/dev/examples/textures/land_ocean_ice_cloud_2048.jpg",
      (tex) => { earthMat.map = tex; earthMat.needsUpdate = true; },
      undefined,
      () => {}
    );

    // Atmosphere
    const atmGeo = new THREE.SphereGeometry(1.025, 64, 64);
    const atmMat = new THREE.MeshPhongMaterial({ color: 0x2255cc, transparent: true, opacity: 0.07, side: THREE.FrontSide });
    const atm = new THREE.Mesh(atmGeo, atmMat);
    scene.add(atm);
    atmRef.current = atm;

    // Outer glow
    const glowGeo = new THREE.SphereGeometry(1.06, 64, 64);
    const glowMat = new THREE.MeshPhongMaterial({ color: 0x0033aa, transparent: true, opacity: 0.03, side: THREE.FrontSide });
    scene.add(new THREE.Mesh(glowGeo, glowMat));

    // Grid lines
    const gridMat = new THREE.LineBasicMaterial({ color: 0x0a2050, transparent: true, opacity: 0.35 });
    for (let lat = -60; lat <= 60; lat += 30) {
      const pts = [];
      const latR = (lat * Math.PI) / 180;
      for (let lon = 0; lon <= 361; lon += 3) {
        const lonR = (lon * Math.PI) / 180;
        pts.push(new THREE.Vector3(Math.cos(latR) * Math.cos(lonR), Math.sin(latR), Math.cos(latR) * Math.sin(lonR)));
      }
      scene.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(pts), gridMat));
    }
    for (let lon = 0; lon < 360; lon += 30) {
      const pts = [];
      const lonR = (lon * Math.PI) / 180;
      for (let lat = -90; lat <= 90; lat += 3) {
        const latR = (lat * Math.PI) / 180;
        pts.push(new THREE.Vector3(Math.cos(latR) * Math.cos(lonR), Math.sin(latR), Math.cos(latR) * Math.sin(lonR)));
      }
      scene.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(pts), gridMat));
    }

    // Lighting
    scene.add(new THREE.AmbientLight(0x111133, 1.2));
    const sun = new THREE.DirectionalLight(0xffffff, 1.8);
    sun.position.set(5, 2, 4);
    scene.add(sun);
    const fill = new THREE.DirectionalLight(0x112244, 0.4);
    fill.position.set(-3, -1, -3);
    scene.add(fill);

    // Mouse drag
    let dragging = false, prevX = 0, prevY = 0;
    let rotY = 0, rotX = 0;
    const onDown = (e) => { dragging = true; prevX = e.clientX; prevY = e.clientY; };
    const onUp = () => (dragging = false);
    const onMove = (e) => {
      if (!dragging) return;
      rotY += (e.clientX - prevX) * 0.006;
      rotX = Math.max(-0.6, Math.min(0.6, rotX + (e.clientY - prevY) * 0.006));
      prevX = e.clientX; prevY = e.clientY;
      earth.rotation.set(rotX, rotY, 0);
      atm.rotation.set(rotX, rotY, 0);
    };
    el.addEventListener("mousedown", onDown);
    window.addEventListener("mouseup", onUp);
    window.addEventListener("mousemove", onMove);

    // Animate — update all tracked satellites every second
    let lastSatUpdate = 0;
    const animate = () => {
      frameRef.current = requestAnimationFrame(animate);

      const now = new Date();
      const ts  = now.getTime();
      if (ts - lastSatUpdate > 1000 && trackedSatsRef.current.length > 0) {
        lastSatUpdate = ts;
        const gmst    = satellite.gstime(now);
        const updates = {};
        trackedSatsRef.current.forEach(sat => {
          const meshes = satMeshesRef.current[sat.id];
          if (!meshes) return;
          const pv = satellite.propagate(sat.satrec, now);
          if (!pv.position) return;
          const geo = satellite.eciToGeodetic(pv.position, gmst);
          const lat = satellite.degreesLat(geo.latitude);
          const lon = satellite.degreesLong(geo.longitude);
          const pos = latLonAltToVec3(lat, lon, geo.height);
          meshes.sprite.position.copy(pos);
          meshes.ring.position.copy(pos);
          meshes.ring.lookAt(new THREE.Vector3(0, 0, 0));
          updates[sat.id] = {
            lat:   lat.toFixed(2),
            lon:   lon.toFixed(2),
            alt:   geo.height.toFixed(0),
            speed: Math.sqrt(pv.velocity.x**2 + pv.velocity.y**2 + pv.velocity.z**2).toFixed(2),
          };

          // Propagate SGP4 nearby markers every second alongside the primary satellite.
          // Approx markers (no TLE) are static — their position is not updated here.
          meshes.nearbyMarkers?.forEach(obj => {
            if (!obj.satrec || obj.method !== 'sgp4') return;
            const pv2 = satellite.propagate(obj.satrec, now);
            if (!pv2.position) return;
            const geo2 = satellite.eciToGeodetic(pv2.position, gmst);
            obj.mesh.position.copy(latLonAltToVec3(
              satellite.degreesLat(geo2.latitude),
              satellite.degreesLong(geo2.longitude),
              geo2.height,
            ));
          });
        });
        if (Object.keys(updates).length)
          setTrackedSats(prev => prev.map(s => updates[s.id] ? { ...s, ...updates[s.id] } : s));
      }

      renderer.render(scene, camera);
    };
    animate();

    const onResize = () => {
      if (!el) return;
      const w = el.clientWidth;
      const h = el.clientHeight;
      if (!w || !h) return;
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
      renderer.setSize(w, h);
    };
    // ResizeObserver catches flex-layout changes (panel stacking) that window
    // resize alone misses.
    const resizeObserver = new ResizeObserver(onResize);
    resizeObserver.observe(el);
    window.addEventListener("resize", onResize);

    return () => {
      cancelAnimationFrame(frameRef.current);
      resizeObserver.disconnect();
      window.removeEventListener("mouseup", onUp);
      window.removeEventListener("mousemove", onMove);
      window.removeEventListener("resize", onResize);
      el.removeEventListener("mousedown", onDown);
      if (el.contains(renderer.domElement)) el.removeChild(renderer.domElement);
      renderer.dispose();
    };
  }, []);

  const PALETTE     = [0x00d4ff, 0xff6b6b, 0x51cf66, 0xffd43b, 0xe599f7, 0xff922b, 0x74c0fc, 0xa9e34b];
  const PALETTE_CSS = ['#00d4ff','#ff6b6b','#51cf66','#ffd43b','#e599f7','#ff922b','#74c0fc','#a9e34b'];

  // ── Search ─────────────────────────────────────────────────
  // Uses internal catalog API — no direct CelesTrak calls from search.
  const performSearch = async (q) => {
    setSearching(true);
    try {
      const res     = await client.get('/satellites/search', { params: { q: q.trim() } });
      const results = (res.data?.data ?? res.data ?? []).map(r => ({
        name:    r.name,
        noradId: r.norad_id,
      }));
      setSearchResults(results);
      return results;
    } catch {
      setSearchResults([]);
      return [];
    } finally {
      setSearching(false);
    }
  };

  const handleSearchChange = (q) => {
    setSearchQuery(q);
    setSearchResults([]);
    setError(null);
    setDropdownActiveIdx(-1);
    clearTimeout(searchTimerRef.current);
    if (q.trim().length < 2) {
      setDropdownOpen(false);
      return;
    }
    setDropdownOpen(true);
    searchTimerRef.current = setTimeout(() => performSearch(q), 400);
  };

  // Enter key: never a silent no-op.
  // - Pure numeric → treat as NORAD ID, load directly.
  // - Results in dropdown → pick first.
  // - Text but debounce not fired yet → force immediate search, auto-select or show error.
  const handleSearchKeyDown = async (e) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setDropdownActiveIdx(i => Math.min(i + 1, searchResults.length - 1));
      return;
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      setDropdownActiveIdx(i => Math.max(i - 1, -1));
      return;
    }
    if (e.key === 'Escape') {
      setDropdownOpen(false);
      setSearchResults([]);
      setDropdownActiveIdx(-1);
      return;
    }
    if (e.key !== 'Enter') return;
    const q = searchQuery.trim();
    if (!q) return;

    if (dropdownActiveIdx >= 0 && dropdownActiveIdx < searchResults.length) {
      const r = searchResults[dropdownActiveIdx];
      setSearchResults([]);
      setDropdownOpen(false);
      setDropdownActiveIdx(-1);
      setSearchQuery('');
      loadAndTrack(r.noradId, r.name);
      return;
    }

    if (/^\d+$/.test(q)) {
      clearTimeout(searchTimerRef.current);
      setSearchResults([]);
      setDropdownOpen(false);
      loadAndTrack(q, '');
      return;
    }

    if (searchResults.length > 0) {
      const first = searchResults[0];
      setSearchResults([]);
      setDropdownOpen(false);
      setDropdownActiveIdx(-1);
      setSearchQuery('');
      loadAndTrack(first.noradId, first.name);
      return;
    }

    clearTimeout(searchTimerRef.current);
    const results = await performSearch(q);
    if (results.length > 0) {
      const first = results[0];
      setSearchResults([]);
      setDropdownOpen(false);
      setSearchQuery('');
      loadAndTrack(first.noradId, first.name);
    } else {
      setError('No satellite found');
    }
  };

  // Fetch TLE from internal API and begin tracking the satellite.
  // Called on search result selection and quick-sat buttons.
  const loadAndTrack = async (noradId, knownName) => {
    setError(null);
    setTrackingId(noradId);
    try {
      const res  = await client.get(`/satellites/${noradId}`);
      const data = res.data?.data ?? res.data;
      if (!data?.tle_line1 || !data?.tle_line2) {
        setError(`TLE not available for NORAD ${noradId}`);
        return;
      }
      addSatellite(data.name ?? knownName ?? noradId, noradId, data.tle_line1, data.tle_line2);
    } catch (err) {
      if (err.type === 'GUEST_LIMIT_REACHED') {
        setGuestLimitReached(true);
      } else {
        // client.js interceptor normalizes errors to plain {type, code, message} objects
        setError(err.message ?? `Could not load satellite ${noradId}`);
      }
    } finally {
      setTrackingId(null);
    }
  };

  // ── Add satellite ───────────────────────────────────────────
  const addSatellite = (name, noradId, tle1, tle2) => {
    if (trackedSatsRef.current.find(s => s.id === noradId)) return; // already tracked
    setError(null);
    setSearchResults([]);
    setSearchQuery('');

    try {
      const satrec  = satellite.twoline2satrec(tle1, tle2);
      const now     = new Date();
      const pv      = satellite.propagate(satrec, now);
      if (!pv.position) { setError(`${name}: orbit could not be propagated.`); return; }

      const colorIdx = trackedSatsRef.current.length % PALETTE.length;
      const color    = PALETTE[colorIdx];
      const colorCss = PALETTE_CSS[colorIdx];

      const gmst = satellite.gstime(now);
      const geo  = satellite.eciToGeodetic(pv.position, gmst);
      const lat  = satellite.degreesLat(geo.latitude);
      const lon  = satellite.degreesLong(geo.longitude);
      const alt  = geo.height;
      const spd  = Math.sqrt(pv.velocity.x**2 + pv.velocity.y**2 + pv.velocity.z**2).toFixed(2);
      const satPos = latLonAltToVec3(lat, lon, alt);

      // 3D objects (children of Earth so they drag with the globe)

      // Satellite icon — billboard sprite (always faces camera)
      const spriteTex = new THREE.CanvasTexture(createSatelliteTexture(colorCss));
      const spriteMat = new THREE.SpriteMaterial({ map: spriteTex, transparent: true, depthTest: false });
      const sprite    = new THREE.Sprite(spriteMat);
      sprite.scale.set(0.12, 0.12, 1);
      sprite.position.copy(satPos);

      const ring = new THREE.Mesh(new THREE.RingGeometry(0.03,0.046,32), new THREE.MeshBasicMaterial({ color, transparent:true, opacity:0.4, side:THREE.DoubleSide }));
      ring.position.copy(satPos);
      ring.lookAt(new THREE.Vector3(0,0,0));

      const orbitPts = [];
      for (let i = 0; i <= 90; i++) {
        const t  = new Date(now.getTime() + i * 60000);
        const pv2 = satellite.propagate(satrec, t);
        if (!pv2.position) continue;
        const g2  = satellite.eciToGeodetic(pv2.position, satellite.gstime(t));
        orbitPts.push(latLonAltToVec3(satellite.degreesLat(g2.latitude), satellite.degreesLong(g2.longitude), g2.height));
      }
      const orbit = orbitPts.length > 1
        ? new THREE.Line(new THREE.BufferGeometry().setFromPoints(orbitPts), new THREE.LineBasicMaterial({ color, transparent:true, opacity:0.3 }))
        : null;

      earthRef.current?.add(sprite, ring);
      if (orbit) earthRef.current?.add(orbit);

      satMeshesRef.current[noradId] = { sprite, ring, orbit, spriteTex, nearbyMarkers: [] };
      trackedSatsRef.current = [...trackedSatsRef.current, { id: noradId, name, satrec, colorCss }];
      setTrackedSats(prev => [...prev, { id: noradId, name, color: colorCss, lat: lat.toFixed(2), lon: lon.toFixed(2), alt: alt.toFixed(0), speed: spd }]);
      onSatelliteAdded?.(noradId, name);

      // Fetch conjunctions for every tracked satellite.
      // HandlePublicRequest applies quota: 10/day for guests, tier limit for API keys, unlimited for users.
      const satNameCapture = name;
      conjLoadCountRef.current += 1;
      setConjunctionsLoading(true);
      fetchConjunctions(noradId)
        .then(async ({ objects, remaining, source }) => {
          const tagged = objects.map(obj => ({ ...obj, forNoradId: noradId, forSatName: satNameCapture }));

          // Merge: replace this satellite's entries, keep others, deduplicate by conjunction id.
          setDebris(prev => {
            const others   = prev.filter(d => d.forNoradId !== noradId);
            const otherIds = new Set(others.map(d => d.id));
            return [...others, ...tagged.filter(d => !otherIds.has(d.id))];
          });
          setConjSource(source);

          if (objects.length > 0) {
            setOverallRisk(prev => {
              const newMax = Math.max(...objects.map(d => d.riskScore));
              return prev == null ? newMax : Math.max(prev, newMax);
            });

            // Skip secondary TLE fetches for guests: each call counts against the 10/day
            // quota and the 3D positions are cosmetic. Guests see ~APPROX markers instead.
            const isGuest = remaining !== null;
            const tleResults = isGuest
              ? objects.map(() => null)
              : await Promise.all(objects.map(obj => fetchSecondaryTle(obj.secondaryNoradId)));

            // Update panel items with resolved propagation method label
            setDebris(prev => {
              const others   = prev.filter(d => d.forNoradId !== noradId);
              const otherIds = new Set(others.map(d => d.id));
              const updated  = tagged
                .map((obj, i) => ({ ...obj, propagationMethod: tleResults[i] ? 'sgp4' : 'approx' }))
                .filter(d => !otherIds.has(d.id));
              return [...others, ...updated];
            });

            // Build one 3D sphere marker per nearby object
            const nearbyMarkers = [];
            const now2  = new Date();
            const gmst2 = satellite.gstime(now2);

            objects.forEach((obj, i) => {
              const satrec2  = tleResults[i];
              const marker   = createNearbyMarker(getRiskColor(obj.riskLevel));
              let   useSgp4  = false;

              if (satrec2) {
                const pv2 = satellite.propagate(satrec2, now2);
                if (pv2.position) {
                  const geo2 = satellite.eciToGeodetic(pv2.position, gmst2);
                  marker.position.copy(latLonAltToVec3(
                    satellite.degreesLat(geo2.latitude),
                    satellite.degreesLong(geo2.longitude),
                    geo2.height,
                  ));
                  useSgp4 = true;
                }
              }

              if (!useSgp4) {
                // Approximate: evenly-spaced ring around primary satellite.
                // NOT a real orbital position — clearly approximate (TLE unavailable).
                marker.position.copy(approxNearbyPosition(satPos, i, objects.length));
              }

              earthRef.current?.add(marker);
              nearbyMarkers.push({
                mesh:   marker,
                satrec: useSgp4 ? satrec2 : null,
                method: useSgp4 ? 'sgp4'  : 'approx',
              });
            });

            if (satMeshesRef.current[noradId]) {
              satMeshesRef.current[noradId].nearbyMarkers = nearbyMarkers;
            }
          }
          if (remaining !== null) setGuestRemaining(remaining);
        })
        .catch((err) => {
          if (err.type === 'GUEST_LIMIT_REACHED') {
            setGuestLimitReached(true);
          }
        })
        .finally(() => {
          conjLoadCountRef.current -= 1;
          if (conjLoadCountRef.current === 0) setConjunctionsLoading(false);
        });
    } catch (err) { setError(err.message); }
  };

  // ── Remove satellite ────────────────────────────────────────
  const removeSatellite = (noradId) => {
    const meshes = satMeshesRef.current[noradId];
    if (meshes) {
      // Sprite — dispose texture + material
      if (meshes.sprite) {
        earthRef.current?.remove(meshes.sprite);
        meshes.spriteTex?.dispose();
        meshes.sprite.material?.dispose();
      }
      // Ring + orbit — dispose geometry + material
      [meshes.ring, meshes.orbit].filter(Boolean).forEach(m => {
        earthRef.current?.remove(m);
        m.geometry?.dispose();
        m.material?.dispose();
      });
      // Nearby object markers (SGP4-propagated or approximate)
      if (meshes.nearbyMarkers?.length) {
        meshes.nearbyMarkers.forEach(obj => {
          earthRef.current?.remove(obj.mesh);
          obj.mesh.geometry?.dispose();
          obj.mesh.material?.dispose();
        });
      }
      delete satMeshesRef.current[noradId];
    }
    trackedSatsRef.current = trackedSatsRef.current.filter(s => s.id !== noradId);
    setTrackedSats(prev => prev.filter(s => s.id !== noradId));
    setDebris(prev => {
      const remaining = prev.filter(d => d.forNoradId !== noradId);
      setOverallRisk(remaining.length > 0 ? Math.max(...remaining.map(d => d.riskScore)) : null);
      return remaining;
    });
    if (trackedSatsRef.current.length === 0) setConjunctionsLoading(false);
    onSatelliteRemoved?.(noradId);
  };

  const risk = overallRisk != null ? riskFromScore(overallRisk) : null;

  return (
    <div className="tracker-root">
      {/* Globe */}
      <div className="globe-wrap" ref={mountRef}>
        <div className="scanline" />
        <div className="corner-hud tl" />
        <div className="corner-hud tr" />
        <div className="corner-hud bl" />
        <div className="corner-hud br" />
        <div className="drag-hint">DRAG TO ROTATE</div>
        <div className="globe-label">ORBITAL DEBRIS RISK MONITOR</div>
      </div>

      {/* Panel */}
      <div className="panel">
        <div className="panel-header">
          <div className="panel-title">DEBRIS.MONITOR</div>
          <div className="panel-subtitle">CONJUNCTION RISK ANALYSIS // v0.1.0</div>
        </div>

        <div className="panel-body">
          {/* Search */}
          <div className="section">
            <div className="section-label">Search Satellites</div>
            <div ref={searchWrapRef} style={{ position: "relative" }}>
              <div style={{ position: "relative" }}>
                <input
                  className="norad-input"
                  value={searchQuery}
                  onChange={(e) => handleSearchChange(e.target.value)}
                  onKeyDown={handleSearchKeyDown}
                  placeholder="Name or NORAD ID…"
                  autoComplete="off"
                />
                {searching && (
                  <div className="spinner" style={{
                    position: "absolute",
                    right: searchQuery ? 32 : 10,
                    top: "50%",
                    transform: "translateY(-50%)",
                    pointerEvents: "none",
                  }} />
                )}
                {searchQuery && !searching && (
                  <button
                    className="input-clear-btn"
                    onClick={() => {
                      setSearchQuery('');
                      setSearchResults([]);
                      setDropdownOpen(false);
                      setDropdownActiveIdx(-1);
                      setError(null);
                      clearTimeout(searchTimerRef.current);
                    }}
                    tabIndex={-1}
                    aria-label="Clear"
                  >×</button>
                )}
              </div>
              {dropdownOpen && searchResults.length > 0 && (
                <div className="search-dropdown" role="listbox">
                  {searchResults.map((r, i) => (
                    <div
                      key={r.noradId}
                      ref={el => { dropItemRefs.current[i] = el; }}
                      className={`search-dropdown-item${dropdownActiveIdx === i ? ' is-active' : ''}`}
                      role="option"
                      aria-selected={dropdownActiveIdx === i}
                      onClick={() => {
                        setSearchResults([]);
                        setDropdownOpen(false);
                        setDropdownActiveIdx(-1);
                        setSearchQuery('');
                        loadAndTrack(r.noradId, r.name);
                      }}
                    >
                      <span style={{ fontSize: 11, color: "#c8dff0" }}>{r.name}</span>
                      <span style={{ fontSize: 9, color: "rgba(0,212,255,0.4)", letterSpacing: 1 }}>{r.noradId}</span>
                    </div>
                  ))}
                  <div className="search-dropdown-hint">↑↓ navigate · Enter select · Esc close</div>
                </div>
              )}
            </div>
            <div className="quick-ids" style={{ marginTop: 8 }}>
              {QUICK_SATS.map((s) => (
                <button
                  key={s.id}
                  className="quick-id"
                  disabled={trackingId !== null}
                  onClick={() => loadAndTrack(s.id, s.label)}
                >
                  {trackingId === s.id ? '…' : s.label}
                </button>
              ))}
            </div>
            {error && <div className="error-box" style={{ marginTop: 8 }}>⚠ {error}</div>}
          </div>

          {/* Tracked satellites list */}
          {trackedSats.length > 0 && (
            <div className="section">
              <div className="section-label">Tracking ({trackedSats.length})</div>
              <div className="live-badge">LIVE POSITIONS</div>
              {trackedSats.map(sat => (
                <div key={sat.id} style={{ marginBottom: 10, background: "rgba(0,212,255,0.03)", border: "1px solid rgba(0,212,255,0.08)", borderLeft: `3px solid ${sat.color}`, padding: "8px 10px" }}>
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 6 }}>
                    <div style={{ display: "flex", alignItems: "center", gap: 7 }}>
                      <div style={{ width: 8, height: 8, borderRadius: "50%", background: sat.color, flexShrink: 0 }} />
                      <span style={{ fontSize: 10, color: sat.color, fontFamily: "Orbitron, sans-serif", letterSpacing: 1 }}>{sat.name}</span>
                    </div>
                    <button onClick={() => removeSatellite(sat.id)} style={{ background: "none", border: "none", color: "rgba(255,100,100,0.5)", cursor: "pointer", fontSize: 14, lineHeight: 1, padding: "0 2px" }}>×</button>
                  </div>
                  <div className="data-grid">
                    <div className="data-cell">
                      <div className="data-cell-label">LAT</div>
                      <div className="data-cell-value" style={{ fontSize: 11 }}>{sat.lat}<span className="data-cell-unit">°</span></div>
                    </div>
                    <div className="data-cell">
                      <div className="data-cell-label">LON</div>
                      <div className="data-cell-value" style={{ fontSize: 11 }}>{sat.lon}<span className="data-cell-unit">°</span></div>
                    </div>
                    <div className="data-cell">
                      <div className="data-cell-label">ALT</div>
                      <div className="data-cell-value" style={{ fontSize: 11 }}>{sat.alt}<span className="data-cell-unit">km</span></div>
                    </div>
                    <div className="data-cell">
                      <div className="data-cell-label">VEL</div>
                      <div className="data-cell-value" style={{ fontSize: 11 }}>{sat.speed}<span className="data-cell-unit">km/s</span></div>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}

          {/* Risk Overview */}
          {risk && (
            <div className="section">
              <div className="section-label">Conjunction Risk</div>
              <div className="risk-banner" style={{ borderColor: risk.border, background: risk.bg }}>
                <div>
                  <div className="risk-label" style={{ color: risk.color }}>{risk.label}</div>
                  <div style={{ fontSize: 9, color: "rgba(200,220,240,0.35)", marginTop: 2, letterSpacing: 1 }}>
                    {debris.length} OBJECTS TRACKED
                  </div>
                </div>
                <div className="risk-score-big" style={{ color: risk.color }}>{overallRisk}</div>
              </div>
              <div className="risk-bar-track">
                <div className="risk-bar-fill" style={{ width: `${overallRisk}%`, background: risk.color }} />
              </div>
            </div>
          )}

          {/* Conjunction / nearby objects section */}
          {trackedSats.length > 0 && (
            <div className="section">
              <div className="section-label">Nearby Risky Objects</div>

              {conjunctionsLoading && (
                <div className="loading-row">
                  <div className="spinner" />
                  ANALYSING CONJUNCTION WINDOW…
                </div>
              )}

              {!conjunctionsLoading && debris.length === 0 && (
                <div style={{ padding: "12px 0", textAlign: "center" }}>
                  <div style={{ color: "#30d158", fontSize: 11, letterSpacing: 1, marginBottom: 4 }}>✓ NO THREATS DETECTED</div>
                  <div style={{ color: "rgba(0,212,255,0.3)", fontSize: 9, letterSpacing: 1, lineHeight: 1.7 }}>
                    No conjunction events in<br />the 5-day analysis window.
                  </div>
                </div>
              )}

              {!conjunctionsLoading && debris.length > 0 && (
                <>
                  {conjSource === 'simulated' ? (
                    <div className="mock-badge">SIMULATED RISK · REAL NORAD IDs</div>
                  ) : (
                    <div className="live-badge">LIVE CDM DATA</div>
                  )}
                  {debris.map((d) => (
                    <div key={d.id} className="debris-item">
                      <div className="debris-risk-dot" style={{ background: getRiskColor(d.riskLevel) }} />
                      <div style={{ flex: 1 }}>
                        <div className="debris-id" style={{ display: "flex", alignItems: "center", gap: 5 }}>
                          {d.secondaryNoradId ? `NORAD ${d.secondaryNoradId}` : d.id}
                          {trackedSats.length > 1 && d.forSatName && (
                            <span style={{ fontSize: 7, color: 'rgba(0,212,255,0.4)', letterSpacing: 0.5 }}>· {d.forSatName}</span>
                          )}
                          {d.propagationMethod && (
                            <span style={{
                              fontSize: 7, letterSpacing: 0.5, borderRadius: 2, padding: "1px 5px",
                              color:   d.propagationMethod === 'sgp4' ? '#30d158' : 'rgba(255,149,0,0.8)',
                              border: `1px solid ${d.propagationMethod === 'sgp4' ? 'rgba(48,209,88,0.35)' : 'rgba(255,149,0,0.35)'}`,
                            }}>
                              {d.propagationMethod === 'sgp4' ? 'SGP4' : '~APPROX'}
                            </span>
                          )}
                        </div>
                        <div className="debris-stats">
                          <span style={{ color: "rgba(0,212,255,0.5)" }}>MISS DIST</span> {d.missKm} km
                          {" · "}
                          <span style={{ color: "rgba(0,212,255,0.5)" }}>COL. PROB</span> {d.prob != null ? (parseFloat(d.prob) * 100).toExponential(1) + "%" : "N/A"}
                        </div>
                      </div>
                      <div className="debris-tca">
                        TCA<br />{d.tca}
                      </div>
                    </div>
                  ))}
                </>
              )}
            </div>
          )}

          {/* Guest limit reached — upgrade CTA */}
          {guestLimitReached && (
            <div style={{
              margin: '16px 0', padding: '16px',
              background: 'rgba(0,212,255,0.05)',
              border: '1px solid rgba(0,212,255,0.25)',
              borderRadius: 6,
              fontFamily: "'JetBrains Mono', monospace",
            }}>
              <div style={{ color: '#00d4ff', fontSize: 10, letterSpacing: 2, marginBottom: 6 }}>
                FREE LIMIT REACHED
              </div>
              <div style={{ color: 'rgba(200,220,240,0.7)', fontSize: 11, lineHeight: 1.6, marginBottom: 12 }}>
                You've used your 10 free analyses today.
                Create a free account for 500 analyses/day.
              </div>
              <div style={{ display: 'flex', gap: 8 }}>
                <a href="/register" style={{
                  flex: 1, padding: '8px 0', textAlign: 'center',
                  fontSize: 10, letterSpacing: 1,
                  background: 'rgba(0,212,255,0.12)', border: '1px solid #00d4ff',
                  borderRadius: 4, color: '#00d4ff', textDecoration: 'none',
                }}>CREATE FREE ACCOUNT</a>
                <a href="/login" style={{
                  flex: 1, padding: '8px 0', textAlign: 'center',
                  fontSize: 10, letterSpacing: 1,
                  background: 'transparent', border: '1px solid rgba(0,212,255,0.3)',
                  borderRadius: 4, color: 'rgba(0,212,255,0.6)', textDecoration: 'none',
                }}>SIGN IN</a>
              </div>
            </div>
          )}

          {trackedSats.length === 0 && !error && (
            <div className="empty-state">
              SEARCH BY NAME OR NORAD ID<br />SELECT FROM RESULTS<br />ADD MULTIPLE SATELLITES
            </div>
          )}
        </div>

        <div className="panel-footer">
          {guestRemaining !== null ? (
            <>
              {guestRemaining === 0
                ? <span style={{ color: '#ff9500' }}>LAST FREE ANALYSIS USED TODAY</span>
                : <span><span style={{ color: '#00d4ff' }}>{guestRemaining}</span> FREE {guestRemaining === 1 ? 'ANALYSIS' : 'ANALYSES'} REMAINING</span>
              }
              {' · '}
              <a href="/register" style={{ color: 'rgba(0,212,255,0.5)', textDecoration: 'none' }}>
                SIGN UP FOR MORE
              </a>
            </>
          ) : (
            'ORBITAL MOTION: SGP4 (TLE-BASED) · CONJUNCTION DATA: SPACE-TRACK CDM'
          )}
        </div>
      </div>
    </div>
  );
}
