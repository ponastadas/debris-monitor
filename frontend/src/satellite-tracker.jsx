import { useState, useEffect, useRef } from "react";
import * as THREE from "three";
import * as satellite from "satellite.js";

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
    padding: 8px 12px;
    outline: none;
    transition: border-color 0.2s;
    letter-spacing: 1px;
  }
  .norad-input:focus { border-color: rgba(0,212,255,0.6); }
  .norad-input::placeholder { color: rgba(0,212,255,0.2); font-size: 10px; }

  .track-btn {
    background: rgba(0,212,255,0.1);
    border: 1px solid rgba(0,212,255,0.35);
    color: #00d4ff;
    font-family: 'Orbitron', sans-serif;
    font-size: 8px;
    letter-spacing: 2px;
    padding: 8px 12px;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
  }
  .track-btn:hover:not(:disabled) {
    background: rgba(0,212,255,0.2);
    border-color: #00d4ff;
  }
  .track-btn:disabled { opacity: 0.4; cursor: not-allowed; }

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
`;

const QUICK_SATS = [
  { id: "25544", label: "ISS" },
  { id: "20580", label: "Hubble" },
  { id: "43013", label: "GOES-16" },
  { id: "37849", label: "Tiangong" },
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

function riskFromScore(score) {
  if (score > 60) return { label: "HIGH RISK", color: "#ff3b30", bg: "rgba(255,59,48,0.08)", border: "rgba(255,59,48,0.25)" };
  if (score > 30) return { label: "MODERATE", color: "#ff9500", bg: "rgba(255,149,0,0.08)", border: "rgba(255,149,0,0.25)" };
  return { label: "LOW RISK", color: "#30d158", bg: "rgba(48,209,88,0.06)", border: "rgba(48,209,88,0.2)" };
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

export default function SatelliteTracker({ initialNoradId = "25544" }) {
  const mountRef = useRef(null);
  const sceneRef = useRef(null);
  const rendererRef = useRef(null);
  const earthRef = useRef(null);
  const atmRef = useRef(null);
  const frameRef = useRef(null);
  const trackedSatsRef   = useRef([]);          // [{id, name, satrec, colorHex}]
  const satMeshesRef     = useRef({});           // {noradId: {dot, ring, orbit}}
  const searchTimerRef   = useRef(null);

  const [searchQuery,   setSearchQuery]   = useState(initialNoradId);
  const [searchResults, setSearchResults] = useState([]);
  const [searching,     setSearching]     = useState(false);
  const [trackedSats,   setTrackedSats]   = useState([]);  // for panel display
  const [error,         setError]         = useState(null);
  const [debris,        setDebris]        = useState([]);
  const [overallRisk,   setOverallRisk]   = useState(null);

  // Inject styles
  useEffect(() => {
    const styleEl = document.createElement("style");
    styleEl.textContent = STYLE;
    document.head.appendChild(styleEl);
    return () => { document.head.removeChild(styleEl); };
  }, []);

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
          meshes.dot.position.copy(pos);
          meshes.ring.position.copy(pos);
          meshes.ring.lookAt(new THREE.Vector3(0, 0, 0));
          updates[sat.id] = {
            lat:   lat.toFixed(2),
            lon:   lon.toFixed(2),
            alt:   geo.height.toFixed(0),
            speed: Math.sqrt(pv.velocity.x**2 + pv.velocity.y**2 + pv.velocity.z**2).toFixed(2),
          };
        });
        if (Object.keys(updates).length)
          setTrackedSats(prev => prev.map(s => updates[s.id] ? { ...s, ...updates[s.id] } : s));
      }

      renderer.render(scene, camera);
    };
    animate();

    const onResize = () => {
      if (!el) return;
      camera.aspect = el.clientWidth / el.clientHeight;
      camera.updateProjectionMatrix();
      renderer.setSize(el.clientWidth, el.clientHeight);
    };
    window.addEventListener("resize", onResize);

    return () => {
      cancelAnimationFrame(frameRef.current);
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
  const handleSearchChange = (q) => {
    setSearchQuery(q);
    setSearchResults([]);
    setError(null);
    clearTimeout(searchTimerRef.current);
    if (q.trim().length < 2) return;

    searchTimerRef.current = setTimeout(async () => {
      setSearching(true);
      try {
        const isId  = /^\d+$/.test(q.trim());
        const param = isId ? `CATNR=${q.trim()}` : `NAME=${encodeURIComponent(q.trim())}`;
        const res   = await fetch(`https://celestrak.org/NORAD/elements/gp.php?${param}&FORMAT=TLE`);
        const text  = await res.text();
        if (!text || text.includes('No GP data')) { setSearchResults([]); return; }
        const lines   = text.trim().split('\n').map(l => l.trim()).filter(Boolean);
        const results = [];
        for (let i = 0; i + 2 < lines.length; i += 3) {
          if (lines[i+1].startsWith('1 ') && lines[i+2].startsWith('2 ')) {
            results.push({ name: lines[i], noradId: lines[i+1].substring(2,7).trim(), tle1: lines[i+1], tle2: lines[i+2] });
          }
        }
        setSearchResults(results.slice(0, 10));
      } catch { setSearchResults([]); }
      finally  { setSearching(false); }
    }, 400);
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
      const dot  = new THREE.Mesh(new THREE.SphereGeometry(0.022,16,16), new THREE.MeshBasicMaterial({ color }));
      dot.position.copy(satPos);

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

      earthRef.current?.add(dot, ring);
      if (orbit) earthRef.current?.add(orbit);

      satMeshesRef.current[noradId] = { dot, ring, orbit };
      trackedSatsRef.current = [...trackedSatsRef.current, { id: noradId, name, satrec, colorCss }];
      setTrackedSats(prev => [...prev, { id: noradId, name, color: colorCss, lat: lat.toFixed(2), lon: lon.toFixed(2), alt: alt.toFixed(0), speed: spd }]);

      // Debris for first sat
      if (trackedSatsRef.current.length === 1) {
        const debrisData = generateMockDebris(lat, lon, alt);
        setDebris(debrisData);
        setOverallRisk(Math.max(...debrisData.map(d => d.riskScore)));
      }
    } catch (err) { setError(err.message); }
  };

  // ── Remove satellite ────────────────────────────────────────
  const removeSatellite = (noradId) => {
    const meshes = satMeshesRef.current[noradId];
    if (meshes) {
      [meshes.dot, meshes.ring, meshes.orbit].filter(Boolean).forEach(m => {
        earthRef.current?.remove(m);
        m.geometry?.dispose();
        m.material?.dispose();
      });
      delete satMeshesRef.current[noradId];
    }
    trackedSatsRef.current = trackedSatsRef.current.filter(s => s.id !== noradId);
    setTrackedSats(prev => prev.filter(s => s.id !== noradId));
    if (trackedSatsRef.current.length === 0) { setDebris([]); setOverallRisk(null); }
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
            <div style={{ position: "relative" }}>
              <div className="input-row">
                <input
                  className="norad-input"
                  value={searchQuery}
                  onChange={(e) => handleSearchChange(e.target.value)}
                  placeholder="Name or NORAD ID…"
                />
                {searching && <div className="spinner" style={{ position: "absolute", right: 12, top: 10 }} />}
              </div>
              {searchResults.length > 0 && (
                <div style={{ position: "absolute", top: "100%", left: 0, right: 0, zIndex: 50, background: "#020a16", border: "1px solid rgba(0,212,255,0.2)", maxHeight: 220, overflowY: "auto" }}>
                  {searchResults.map(r => (
                    <div key={r.noradId}
                      onClick={() => addSatellite(r.name, r.noradId, r.tle1, r.tle2)}
                      style={{ padding: "8px 12px", cursor: "pointer", borderBottom: "1px solid rgba(0,212,255,0.07)", display: "flex", justifyContent: "space-between", alignItems: "center" }}
                      onMouseEnter={e => e.currentTarget.style.background = "rgba(0,212,255,0.07)"}
                      onMouseLeave={e => e.currentTarget.style.background = "transparent"}
                    >
                      <span style={{ fontSize: 11, color: "#c8dff0" }}>{r.name}</span>
                      <span style={{ fontSize: 9, color: "rgba(0,212,255,0.4)", letterSpacing: 1 }}>{r.noradId}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>
            <div className="quick-ids" style={{ marginTop: 8 }}>
              {QUICK_SATS.map((s) => (
                <div key={s.id} className="quick-id" onClick={() => handleSearchChange(s.id)}>
                  {s.label}
                </div>
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

          {/* Debris List */}
          {debris.length > 0 && (
            <div className="section">
              <div className="section-label">Tracked Objects</div>
              <div className="mock-badge">SIMULATED CONJUNCTION DATA</div>
              {debris.map((d) => (
                <div key={d.id} className="debris-item">
                  <div className="debris-risk-dot" style={{ background: getRiskColor(d.riskLevel) }} />
                  <div style={{ flex: 1 }}>
                    <div className="debris-id">{d.id}</div>
                    <div className="debris-stats">
                      MISS: {d.missKm} km · P(c): {d.prob}<br />
                      ALT: {d.alt} km
                    </div>
                  </div>
                  <div className="debris-tca">
                    TCA<br />{d.tca}
                  </div>
                </div>
              ))}
            </div>
          )}

          {trackedSats.length === 0 && !error && (
            <div className="empty-state">
              SEARCH BY NAME OR NORAD ID<br />SELECT FROM RESULTS<br />ADD MULTIPLE SATELLITES
            </div>
          )}
        </div>

        <div className="panel-footer">
          TLE SOURCE: CELESTRAK.ORG · CONJUNCTION DATA: SIMULATED
        </div>
      </div>
    </div>
  );
}
