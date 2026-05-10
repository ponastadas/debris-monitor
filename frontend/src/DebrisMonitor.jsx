import { useState, useEffect, useRef, useCallback } from "react";
import * as THREE from "three";

// CelesTrak TLE group endpoints
const TLE_GROUPS = [
  { url: "https://celestrak.org/NORAD/elements/gp.php?GROUP=active&FORMAT=TLE",           type: "satellite" },
  { url: "https://celestrak.org/NORAD/elements/gp.php?GROUP=cosmos-2251-debris&FORMAT=TLE", type: "debris" },
  { url: "https://celestrak.org/NORAD/elements/gp.php?GROUP=iridium-33-debris&FORMAT=TLE",  type: "debris" },
  { url: "https://celestrak.org/NORAD/elements/gp.php?GROUP=fengyun-1c-debris&FORMAT=TLE",  type: "debris" },
  { url: "https://celestrak.org/NORAD/elements/gp.php?GROUP=2019-006&FORMAT=TLE",           type: "debris" },
  { url: "https://celestrak.org/NORAD/elements/gp.php?GROUP=rocket-bodies&FORMAT=TLE",      type: "rocket" },
];

const COLORS = {
  satellite: new THREE.Color("#388bfd"),
  debris:    new THREE.Color("#f85149"),
  rocket:    new THREE.Color("#d29922"),
  unknown:   new THREE.Color("#8b949e"),
};

const SPEED_PRESETS = [
  { label: "1×",      ms: 1000 },
  { label: "10×",     ms: 100 },
  { label: "1min/s",  ms: 1000 / 60 },
  { label: "10min/s", ms: 1000 / 600 },
];

// SGP4 simplified position propagation (Keplerian approximation for visualization)
function tleToPosition(line1, line2, dateMs) {
  try {
    const inc  = parseFloat(line2.substring(8,  16)) * (Math.PI / 180);
    const raan = parseFloat(line2.substring(17, 25)) * (Math.PI / 180);
    const ecc  = parseFloat("0." + line2.substring(26, 33).trim());
    const argP = parseFloat(line2.substring(34, 42)) * (Math.PI / 180);
    const ma0  = parseFloat(line2.substring(43, 51)) * (Math.PI / 180);
    const mm   = parseFloat(line2.substring(52, 63)); // revs/day
    const epoch = parseEpoch(line1.substring(18, 32));

    const dt   = (dateMs - epoch) / 1000; // seconds since epoch
    const n    = mm * 2 * Math.PI / 86400; // rad/s
    const ma   = (ma0 + n * dt) % (2 * Math.PI);

    // Eccentric anomaly (2-iteration Newton-Raphson)
    let ea = ma;
    ea = ea - (ea - ecc * Math.sin(ea) - ma) / (1 - ecc * Math.cos(ea));
    ea = ea - (ea - ecc * Math.sin(ea) - ma) / (1 - ecc * Math.cos(ea));

    const ta   = 2 * Math.atan2(Math.sqrt(1 + ecc) * Math.sin(ea / 2), Math.sqrt(1 - ecc) * Math.cos(ea / 2));
    const r    = (6371 + 500) * (1 - ecc * Math.cos(ea)); // km (rough semi-major from period)
    const scale = r / 6371;

    // Perifocal → ECI
    const xp = r * Math.cos(ta);
    const yp = r * Math.sin(ta);

    const cosR = Math.cos(raan), sinR = Math.sin(raan);
    const cosI = Math.cos(inc),  sinI = Math.sin(inc);
    const cosA = Math.cos(argP), sinA = Math.sin(argP);

    const x = (cosR * cosA - sinR * sinA * cosI) * xp + (-cosR * sinA - sinR * cosA * cosI) * yp;
    const y = (sinR * cosA + cosR * sinA * cosI) * xp + (-sinR * sinA + cosR * cosA * cosI) * yp;
    const z = (sinI * sinA) * xp + (sinI * cosA) * yp;

    const len = Math.sqrt(x * x + y * y + z * z) || 1;
    return new THREE.Vector3(x / len * scale, z / len * scale, -y / len * scale);
  } catch {
    return null;
  }
}

function parseEpoch(epochStr) {
  const year2  = parseInt(epochStr.substring(0, 2));
  const year   = year2 < 57 ? 2000 + year2 : 1900 + year2;
  const dayFrac = parseFloat(epochStr.substring(2));
  const jan1   = Date.UTC(year, 0, 1);
  return jan1 + (dayFrac - 1) * 86400000;
}

function parseTleText(text, type) {
  const lines = text.trim().split("\n").map(l => l.trim()).filter(Boolean);
  const objs = [];
  for (let i = 0; i + 2 < lines.length; i += 3) {
    if (lines[i + 1].startsWith("1 ") && lines[i + 2].startsWith("2 ")) {
      objs.push({ name: lines[i], line1: lines[i + 1], line2: lines[i + 2], type });
    }
  }
  return objs;
}

function altitudeKm(line2) {
  try {
    const mm = parseFloat(line2.substring(52, 63));
    const n  = mm * 2 * Math.PI / 86400;
    const mu = 398600.4418;
    const a  = Math.cbrt(mu / (n * n));
    return Math.round(a - 6371);
  } catch {
    return 0;
  }
}

function orbitBand(alt) {
  if (alt < 2000)  return "LEO";
  if (alt < 8000)  return "MEO";
  if (alt < 40000) return "HEO/GEO";
  return "HEO";
}

const STYLE = `
  @import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;900&family=JetBrains+Mono:wght@300;400;500&display=swap');
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #020810; }

  .dm-root {
    display: flex;
    height: 100%;
    width: 100%;
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
      transparent 0px, transparent 3px,
      rgba(0,212,255,0.012) 3px, rgba(0,212,255,0.012) 4px
    );
    pointer-events: none;
    z-index: 2;
  }

  .corner-hud {
    position: absolute;
    width: 40px; height: 40px;
    pointer-events: none;
    z-index: 3;
    opacity: 0.45;
  }
  .corner-hud.tl { top: 16px; left: 16px; border-top: 1px solid #00d4ff; border-left: 1px solid #00d4ff; }
  .corner-hud.tr { top: 16px; right: 16px; border-top: 1px solid #00d4ff; border-right: 1px solid #00d4ff; }
  .corner-hud.bl { bottom: 16px; left: 16px; border-bottom: 1px solid #00d4ff; border-left: 1px solid #00d4ff; }
  .corner-hud.br { bottom: 16px; right: 16px; border-bottom: 1px solid #00d4ff; border-right: 1px solid #00d4ff; }

  .fps-badge {
    position: absolute;
    top: 20px;
    left: 50%;
    transform: translateX(-50%);
    font-size: 9px;
    letter-spacing: 3px;
    color: rgba(0,212,255,0.3);
    pointer-events: none;
    z-index: 3;
  }

  .globe-label {
    position: absolute;
    bottom: 28px;
    left: 50%;
    transform: translateX(-50%);
    font-family: 'Orbitron', sans-serif;
    font-size: 9px;
    letter-spacing: 4px;
    color: rgba(0,212,255,0.25);
    pointer-events: none;
    z-index: 3;
  }

  /* Side panel */
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
    background: rgba(0,20,50,0.4);
  }

  .panel-title {
    font-family: 'Orbitron', sans-serif;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 3px;
    color: #00d4ff;
    margin-bottom: 4px;
  }

  .panel-sub {
    font-size: 9px;
    color: rgba(0,212,255,0.4);
    letter-spacing: 2px;
  }

  .panel-body {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    scrollbar-width: thin;
    scrollbar-color: rgba(0,212,255,0.2) transparent;
  }

  .section-label {
    font-size: 9px;
    letter-spacing: 3px;
    color: rgba(0,212,255,0.4);
    margin-bottom: 10px;
    text-transform: uppercase;
  }

  .search-box {
    width: 100%;
    background: rgba(0,212,255,0.05);
    border: 1px solid rgba(0,212,255,0.2);
    border-radius: 4px;
    padding: 8px 10px;
    color: #c8dff0;
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px;
    outline: none;
    margin-bottom: 16px;
  }
  .search-box:focus { border-color: rgba(0,212,255,0.5); }
  .search-box::placeholder { color: rgba(0,212,255,0.25); }

  .toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 7px 0;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    cursor: pointer;
  }
  .toggle-row:hover { background: rgba(0,212,255,0.03); }

  .dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    margin-right: 8px;
    flex-shrink: 0;
  }

  .toggle-label {
    display: flex;
    align-items: center;
    font-size: 11px;
    color: #c8dff0;
  }

  .toggle-count {
    font-size: 10px;
    color: rgba(0,212,255,0.5);
  }

  .pill {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 9px;
    letter-spacing: 1px;
    background: rgba(0,212,255,0.08);
    border: 1px solid rgba(0,212,255,0.15);
    margin-right: 4px;
    margin-bottom: 4px;
  }

  .divider {
    height: 1px;
    background: rgba(0,212,255,0.08);
    margin: 14px 0;
  }

  .stat-row {
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    padding: 5px 0;
  }
  .stat-label { color: rgba(0,212,255,0.5); }
  .stat-val   { color: #c8dff0; font-weight: 500; }

  .speed-row {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 12px;
  }
  .speed-btn {
    padding: 5px 10px;
    background: rgba(0,212,255,0.06);
    border: 1px solid rgba(0,212,255,0.2);
    border-radius: 4px;
    color: #c8dff0;
    font-family: 'JetBrains Mono', monospace;
    font-size: 10px;
    cursor: pointer;
  }
  .speed-btn.active {
    background: rgba(0,212,255,0.15);
    border-color: #00d4ff;
    color: #00d4ff;
  }

  .selected-panel {
    background: rgba(0,212,255,0.04);
    border: 1px solid rgba(0,212,255,0.15);
    border-radius: 6px;
    padding: 12px;
    margin-top: 4px;
  }

  .selected-name {
    font-family: 'Orbitron', sans-serif;
    font-size: 11px;
    color: #00d4ff;
    margin-bottom: 8px;
    word-break: break-all;
  }

  .loading-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: rgba(2,8,16,0.85);
    z-index: 20;
    gap: 16px;
  }

  .loading-title {
    font-family: 'Orbitron', sans-serif;
    font-size: 13px;
    letter-spacing: 4px;
    color: #00d4ff;
  }

  .loading-bar-wrap {
    width: 200px;
    height: 2px;
    background: rgba(0,212,255,0.15);
    border-radius: 1px;
    overflow: hidden;
  }

  .loading-bar {
    height: 100%;
    background: #00d4ff;
    transition: width 0.3s;
  }

  .loading-sub {
    font-size: 9px;
    color: rgba(0,212,255,0.4);
    letter-spacing: 2px;
  }

  /* ─── Responsive layout ─────────────────────────────────────────────── */

  /* Tablet: narrow panel so the globe gets more space */
  @media (max-width: 768px) {
    .panel { width: 260px; min-width: 260px; }
  }

  /* Mobile: stack globe on top, panel below */
  @media (max-width: 600px) {
    .dm-root { flex-direction: column; }

    .globe-wrap {
      height: 55%;
      flex: none;
      width: 100%;
    }

    .panel {
      width: 100% !important;
      min-width: unset;
      height: 45%;
      border-left: none;
      border-top: 1px solid rgba(0,212,255,0.12);
      overflow: hidden;
    }

    /* HUD decorations that get in the way on small screens */
    .fps-badge { font-size: 8px; letter-spacing: 1px; }
    .globe-label { font-size: 8px; letter-spacing: 2px; bottom: 12px; }
  }
`;

export default function DebrisMonitor({ onTrack }) {
  const canvasRef  = useRef(null);
  const sceneRef   = useRef(null);
  const rendRef    = useRef(null);
  const camRef     = useRef(null);
  const animRef    = useRef(null);
  const meshRef    = useRef(null);    // InstancedMesh
  const objectsRef = useRef([]);      // parsed TLE objects
  const simTimeRef = useRef(Date.now());
  const dragRef    = useRef({ active: false, last: null, velX: 0, velY: 0 });
  const fpsRef     = useRef({ frames: 0, last: Date.now(), val: 0 });
  const speedRef   = useRef(SPEED_PRESETS[0]);

  const [loading, setLoading]   = useState({ active: true, pct: 0, msg: "Initialising" });
  const [fps, setFps]           = useState(0);
  const [counts, setCounts]     = useState({ satellite: 0, debris: 0, rocket: 0, unknown: 0, total: 0 });
  const [visible, setVisible]   = useState({ satellite: true, debris: true, rocket: true, unknown: true });
  const [simDate, setSimDate]   = useState(new Date());
  const [speedIdx, setSpeedIdx] = useState(0);
  const [search, setSearch]     = useState("");
  const [selected, setSelected] = useState(null);
  const [bandCounts, setBandCounts] = useState({ LEO: 0, MEO: 0, "HEO/GEO": 0 });

  // Init Three.js scene
  useEffect(() => {
    const canvas = canvasRef.current;
    const parent = canvas.parentElement;
    const w = parent.clientWidth  || window.innerWidth;
    const h = parent.clientHeight || window.innerHeight;

    const renderer = new THREE.WebGLRenderer({ canvas, antialias: false, powerPreference: "high-performance" });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 1.5));
    // false = don't overwrite the canvas CSS (which React sets to width/height 100%)
    renderer.setSize(w, h, false);
    rendRef.current = renderer;

    const scene = new THREE.Scene();
    sceneRef.current = scene;

    const camera = new THREE.PerspectiveCamera(45, w / h, 0.01, 1000);
    camera.position.set(0, 0, 3.5);
    camRef.current = camera;

    // Stars
    const starGeo = new THREE.BufferGeometry();
    const starPos = new Float32Array(3000);
    for (let i = 0; i < 3000; i++) {
      const theta = Math.random() * Math.PI * 2;
      const phi   = Math.acos(2 * Math.random() - 1);
      const r     = 80 + Math.random() * 20;
      starPos[i * 3]     = r * Math.sin(phi) * Math.cos(theta);
      starPos[i * 3 + 1] = r * Math.cos(phi);
      starPos[i * 3 + 2] = r * Math.sin(phi) * Math.sin(theta);
    }
    starGeo.setAttribute("position", new THREE.BufferAttribute(starPos, 3));
    scene.add(new THREE.Points(starGeo, new THREE.PointsMaterial({ color: 0xffffff, size: 0.06, transparent: true, opacity: 0.7 })));

    // Globe
    const earthGeo = new THREE.SphereGeometry(1, 64, 64);
    const earthMat = new THREE.MeshPhongMaterial({
      color: 0x1a4a8a, emissive: 0x050e20, specular: 0x2266bb, shininess: 12,
    });
    scene.add(new THREE.Mesh(earthGeo, earthMat));
    new THREE.TextureLoader().load(
      '/earth.jpg',
      (tex) => { earthMat.map = tex; earthMat.needsUpdate = true; },
      undefined,
      () => {}
    );

    // Atmosphere
    const atmGeo = new THREE.SphereGeometry(1.025, 64, 64);
    const atmMat = new THREE.MeshPhongMaterial({ color: 0x2255cc, transparent: true, opacity: 0.07, side: THREE.FrontSide });
    scene.add(new THREE.Mesh(atmGeo, atmMat));

    // Outer glow
    const glowGeo = new THREE.SphereGeometry(1.06, 64, 64);
    const glowMat = new THREE.MeshPhongMaterial({ color: 0x0033aa, transparent: true, opacity: 0.03, side: THREE.FrontSide });
    scene.add(new THREE.Mesh(glowGeo, glowMat));

    // Lights
    scene.add(new THREE.AmbientLight(0x111133, 1.2));
    const sun = new THREE.DirectionalLight(0xffffff, 1.8);
    sun.position.set(5, 3, 5);
    scene.add(sun);
    const fill = new THREE.DirectionalLight(0x112244, 0.4);
    fill.position.set(-5, -3, -5);
    scene.add(fill);

    const resize = () => {
      // Read dimensions from the parent container — not from the canvas element,
      // whose CSS dimensions Three.js may have overwritten with px values.
      const p = canvas.parentElement;
      if (!p) return;
      const w = p.clientWidth;
      const h = p.clientHeight;
      if (!w || !h) return;
      renderer.setSize(w, h, false);
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
    };
    // ResizeObserver catches flex-layout changes (panel stacking) that window
    // resize alone misses.
    const ro = new ResizeObserver(resize);
    ro.observe(parent);
    window.addEventListener("resize", resize);

    return () => {
      ro.disconnect();
      window.removeEventListener("resize", resize);
      cancelAnimationFrame(animRef.current);
      renderer.dispose();
    };
  }, []);

  // Fetch TLE data — prefers local catalog API, falls back to direct CelesTrak group fetches.
  // Local catalog is populated by `php artisan satellites:sync` (scheduled every 6 hours).
  // When catalog is empty (fresh dev environment), CelesTrak fetches still work as before.
  const [dataSource, setDataSource] = useState(null); // 'local' | 'celestrak' | null

  useEffect(() => {
    let cancelled = false;

    function finalise(allObjects, source) {
      if (cancelled) return;
      objectsRef.current = allObjects;
      const bands = { LEO: 0, MEO: 0, "HEO/GEO": 0 };
      const cnts  = { satellite: 0, debris: 0, rocket: 0, unknown: 0, total: 0 };
      allObjects.forEach(o => {
        cnts[o.type] = (cnts[o.type] || 0) + 1;
        cnts.total++;
        const band = orbitBand(altitudeKm(o.line2));
        if (bands[band] !== undefined) bands[band]++;
      });
      setCounts(cnts);
      setBandCounts(bands);
      setDataSource(source);
      setLoading({ active: true, pct: 95, msg: "Building scene…" });
      buildMesh(allObjects);
      setLoading({ active: false, pct: 100, msg: "Done" });
    }

    async function loadFromLocalCatalog() {
      setLoading({ active: true, pct: 15, msg: "Loading local catalog…" });
      // fetch() uses browser cache automatically (Cache-Control + ETag handled transparently).
      const res  = await fetch("/api/catalog");
      if (!res.ok) return null;
      const json = await res.json();
      const sats = json?.data?.satellites ?? [];
      if (!sats.length) return null;
      // Response shape: { name, type, line1, line2 } — norad_id is in line1[2:7] if needed.
      return sats.map(s => ({ name: s.name, line1: s.line1, line2: s.line2, type: s.type }));
    }

    async function loadFromCelesTrak() {
      const allObjects = [];
      for (let i = 0; i < TLE_GROUPS.length; i++) {
        if (cancelled) return allObjects;
        const g = TLE_GROUPS[i];
        setLoading({ active: true, pct: Math.round((i / TLE_GROUPS.length) * 80) + 10, msg: `Loading ${g.type} data…` });
        try {
          const res  = await fetch(g.url);
          const text = await res.text();
          allObjects.push(...parseTleText(text, g.type));
        } catch { /* skip failed group */ }
      }
      return allObjects;
    }

    async function load() {
      try {
        const local = await loadFromLocalCatalog();
        if (local && local.length > 0 && !cancelled) {
          finalise(local, 'local');
          return;
        }
      } catch { /* local catalog unavailable — fall through */ }

      if (cancelled) return;
      setLoading({ active: true, pct: 10, msg: "Local catalog empty — fetching from CelesTrak…" });
      const remote = await loadFromCelesTrak();
      if (!cancelled) finalise(remote, 'celestrak');
    }

    load();
    return () => { cancelled = true; };
  }, []);

  function buildMesh(objects) {
    const scene = sceneRef.current;
    if (!scene) return;

    // Remove old mesh
    if (meshRef.current) {
      scene.remove(meshRef.current);
      meshRef.current.geometry.dispose();
      meshRef.current.material.dispose();
    }

    const geo  = new THREE.SphereGeometry(0.006, 4, 4);
    const mat  = new THREE.MeshBasicMaterial({ vertexColors: true });
    const mesh = new THREE.InstancedMesh(geo, mat, objects.length);
    mesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage);

    const dummy = new THREE.Object3D();
    const color = new THREE.Color();
    const now   = simTimeRef.current;

    objects.forEach((obj, i) => {
      const pos = tleToPosition(obj.line1, obj.line2, now);
      if (pos) {
        dummy.position.copy(pos);
      } else {
        dummy.position.set(0, 0, 0);
      }
      dummy.scale.setScalar(obj.type === 'debris' ? 0.5 : 1);
      dummy.updateMatrix();
      mesh.setMatrixAt(i, dummy.matrix);
      color.copy(COLORS[obj.type] || COLORS.unknown);
      mesh.setColorAt(i, color);
    });

    mesh.instanceMatrix.needsUpdate = true;
    if (mesh.instanceColor) mesh.instanceColor.needsUpdate = true;

    scene.add(mesh);
    meshRef.current = mesh;
  }

  // Animation loop
  useEffect(() => {
    if (loading.active) return;

    const dummy  = new THREE.Object3D();
    const color  = new THREE.Color();
    let lastTick = Date.now();

    function animate() {
      animRef.current = requestAnimationFrame(animate);

      // FPS counter
      const now = Date.now();
      fpsRef.current.frames++;
      if (now - fpsRef.current.last >= 500) {
        const val = Math.round(fpsRef.current.frames / ((now - fpsRef.current.last) / 1000));
        fpsRef.current = { frames: 0, last: now, val };
        setFps(val);
      }

      // Advance simulation time
      const elapsed = now - lastTick;
      lastTick = now;
      simTimeRef.current += elapsed * (speedRef.current.ms === SPEED_PRESETS[0].ms
        ? 1
        : speedRef.current.ms * 60);  // ms of sim per ms of real

      setSimDate(new Date(simTimeRef.current));

      // Update positions
      const mesh    = meshRef.current;
      const objects = objectsRef.current;
      if (!mesh || !objects.length) return;

      const simNow  = simTimeRef.current;
      const vis     = { ...visibleRef.current };

      objects.forEach((obj, i) => {
        if (!vis[obj.type]) {
          dummy.scale.setScalar(0);
          dummy.position.set(0, 0, 0);
          dummy.updateMatrix();
          mesh.setMatrixAt(i, dummy.matrix);
          return;
        }

        const pos = tleToPosition(obj.line1, obj.line2, simNow);
        dummy.scale.setScalar(obj.type === 'debris' ? 0.5 : 1);
        if (pos) dummy.position.copy(pos);
        else dummy.position.set(0, 0, 0);
        dummy.updateMatrix();
        mesh.setMatrixAt(i, dummy.matrix);
        color.copy(COLORS[obj.type] || COLORS.unknown);
        mesh.setColorAt(i, color);
      });

      mesh.instanceMatrix.needsUpdate = true;
      if (mesh.instanceColor) mesh.instanceColor.needsUpdate = true;

      // Inertia
      const drag = dragRef.current;
      if (!drag.active) {
        const globe = sceneRef.current?.getObjectByProperty("type", "Group") || mesh.parent;
        sceneRef.current.rotation.y += drag.velX * 0.0012;
        sceneRef.current.rotation.x += drag.velY * 0.0012;
        drag.velX *= 0.94;
        drag.velY *= 0.94;
      }

      rendRef.current.render(sceneRef.current, camRef.current);
    }

    animate();
    return () => cancelAnimationFrame(animRef.current);
  }, [loading.active]);

  // Keep a ref to visible so animation loop can read it without stale closure
  const visibleRef = useRef(visible);
  useEffect(() => { visibleRef.current = visible; }, [visible]);

  // Speed ref sync
  useEffect(() => {
    speedRef.current = SPEED_PRESETS[speedIdx];
  }, [speedIdx]);

  // Mouse drag + click-to-select
  const onMouseDown = useCallback((e) => {
    dragRef.current = { active: true, last: { x: e.clientX, y: e.clientY }, start: { x: e.clientX, y: e.clientY }, velX: 0, velY: 0 };
  }, []);

  const onMouseMove = useCallback((e) => {
    const drag = dragRef.current;
    if (!drag.active) return;
    const dx = e.clientX - drag.last.x;
    const dy = e.clientY - drag.last.y;
    drag.last = { x: e.clientX, y: e.clientY };
    drag.velX = dx;
    drag.velY = dy;
    sceneRef.current.rotation.y += dx * 0.005;
    sceneRef.current.rotation.x += dy * 0.005;
  }, []);

  const onMouseUp = useCallback((e) => {
    const drag = dragRef.current;
    if (!drag?.start) return;
    drag.active = false;

    // If mouse barely moved, treat as a click → raycast
    const dx = e.clientX - drag.start.x;
    const dy = e.clientY - drag.start.y;
    if (Math.sqrt(dx * dx + dy * dy) < 5 && meshRef.current && camRef.current) {
      const canvas = canvasRef.current;
      const rect   = canvas.getBoundingClientRect();
      const mouse  = new THREE.Vector2(
        ((e.clientX - rect.left) / rect.width)  *  2 - 1,
        ((e.clientY - rect.top)  / rect.height) * -2 + 1
      );
      const ray = new THREE.Raycaster();
      ray.setFromCamera(mouse, camRef.current);
      const hits = ray.intersectObject(meshRef.current);
      if (hits.length > 0 && hits[0].instanceId != null) {
        const obj = objectsRef.current[hits[0].instanceId];
        if (obj) { setSelected(obj); setSearch(""); }
      }
    }
  }, []);

  // Search filtering
  const searchResults = search.trim().length > 1
    ? objectsRef.current.filter(o =>
        o.name.toLowerCase().includes(search.toLowerCase()) ||
        o.line1.substring(2, 7).trim() === search.trim()
      ).slice(0, 8)
    : [];

  return (
    <>
      <style>{STYLE}</style>
      <div className="dm-root">
        {/* Globe viewport */}
        <div className="globe-wrap"
          onMouseDown={onMouseDown}
          onMouseMove={onMouseMove}
          onMouseUp={onMouseUp}
          onMouseLeave={onMouseUp}
        >
          <canvas ref={canvasRef} style={{ width: "100%", height: "100%", display: "block" }} />
          <div className="scanline" />
          <div className="corner-hud tl" /><div className="corner-hud tr" />
          <div className="corner-hud bl" /><div className="corner-hud br" />
          <div className="fps-badge">{fps} FPS · {counts.total.toLocaleString()} OBJECTS</div>
          <div className="globe-label">
            SATVIEW ·{" "}
            {dataSource === 'local'     && "LOCAL CATALOG"}
            {dataSource === 'celestrak' && "CELESTRAK DATA"}
            {!dataSource               && "REAL-TIME CATALOG"}
          </div>

          {loading.active && (
            <div className="loading-overlay">
              <div className="loading-title">SATVIEW</div>
              <div className="loading-bar-wrap">
                <div className="loading-bar" style={{ width: `${loading.pct}%` }} />
              </div>
              <div className="loading-sub">{loading.msg.toUpperCase()}</div>
            </div>
          )}
        </div>

        {/* Side panel */}
        <div className="panel">
          <div className="panel-header">
            <div className="panel-title">SATVIEW</div>
            <div className="panel-sub">REAL-TIME CATALOG · {counts.total.toLocaleString()} OBJECTS</div>
          </div>

          <div className="panel-body">
            {/* Search */}
            <div className="section-label">SEARCH</div>
            <input
              className="search-box"
              placeholder="Name or NORAD ID…"
              value={search}
              onChange={e => setSearch(e.target.value)}
            />
            {searchResults.length > 0 && (
              <div style={{ marginBottom: 14 }}>
                {searchResults.map((o, i) => (
                  <div key={i} className="toggle-row" onClick={() => setSelected(o)}>
                    <span className="toggle-label">
                      <span className="dot" style={{ background: (COLORS[o.type] || COLORS.unknown).getStyle() }} />
                      {o.name}
                    </span>
                    <span className="toggle-count">{o.line1.substring(2, 7).trim()}</span>
                  </div>
                ))}
              </div>
            )}

            <div className="divider" />

            {/* Category toggles */}
            <div className="section-label">CATEGORIES</div>
            {[
              { key: "satellite", label: "Active Satellites", color: "#388bfd" },
              { key: "debris",    label: "Debris",            color: "#f85149" },
              { key: "rocket",    label: "Rocket Bodies",     color: "#d29922" },
              { key: "unknown",   label: "Unknown",           color: "#8b949e" },
            ].map(({ key, label, color }) => (
              <div key={key} className="toggle-row" onClick={() => setVisible(v => ({ ...v, [key]: !v[key] }))}>
                <span className="toggle-label">
                  <span className="dot" style={{ background: visible[key] ? color : "#333", border: `1px solid ${color}` }} />
                  {label}
                </span>
                <span className="toggle-count">{(counts[key] || 0).toLocaleString()}</span>
              </div>
            ))}

            <div className="divider" />

            {/* Orbital distribution */}
            <div className="section-label">ORBITAL DISTRIBUTION</div>
            {Object.entries(bandCounts).map(([band, n]) => (
              <div key={band} className="stat-row">
                <span className="stat-label">{band}</span>
                <span className="stat-val">{n.toLocaleString()}</span>
              </div>
            ))}

            <div className="divider" />

            {/* Time simulation */}
            <div className="section-label">TIME SIMULATION</div>
            <div className="stat-row">
              <span className="stat-label">SIM TIME</span>
              <span className="stat-val" style={{ fontSize: 9 }}>
                {simDate.toUTCString().replace(" GMT", "Z").split(" ").slice(1).join(" ")}
              </span>
            </div>
            <div style={{ marginTop: 10 }}>
              <div className="speed-row">
                {SPEED_PRESETS.map((p, i) => (
                  <button key={i} className={`speed-btn${speedIdx === i ? " active" : ""}`} onClick={() => setSpeedIdx(i)}>
                    {p.label}
                  </button>
                ))}
              </div>
            </div>

            {/* Selected object */}
            {selected && (
              <>
                <div className="divider" />
                <div className="section-label">SELECTED OBJECT</div>
                <div className="selected-panel">
                  <div className="selected-name">{selected.name}</div>
                  <div className="stat-row">
                    <span className="stat-label">NORAD ID</span>
                    <span className="stat-val">{selected.line1.substring(2, 7).trim()}</span>
                  </div>
                  <div className="stat-row">
                    <span className="stat-label">TYPE</span>
                    <span className="stat-val">{selected.type.toUpperCase()}</span>
                  </div>
                  <div className="stat-row">
                    <span className="stat-label">ALTITUDE</span>
                    <span className="stat-val">~{altitudeKm(selected.line2).toLocaleString()} km</span>
                  </div>
                  <div className="stat-row">
                    <span className="stat-label">ORBIT BAND</span>
                    <span className="stat-val">{orbitBand(altitudeKm(selected.line2))}</span>
                  </div>
                  <div style={{ display: "flex", gap: 6, marginTop: 10 }}>
                    {onTrack && (
                      <button
                        style={{ flex: 1, padding: "7px", background: "rgba(0,212,255,0.15)", border: "1px solid #00d4ff", borderRadius: 4, color: "#00d4ff", fontFamily: "JetBrains Mono, monospace", fontSize: 10, cursor: "pointer", letterSpacing: 2 }}
                        onClick={() => onTrack(selected.line1.substring(2, 7).trim())}
                      >
                        TRACK
                      </button>
                    )}
                    <button
                      style={{ flex: 1, padding: "7px", background: "rgba(0,212,255,0.05)", border: "1px solid rgba(0,212,255,0.2)", borderRadius: 4, color: "rgba(0,212,255,0.5)", fontFamily: "JetBrains Mono, monospace", fontSize: 10, cursor: "pointer", letterSpacing: 2 }}
                      onClick={() => setSelected(null)}
                    >
                      CLEAR
                    </button>
                  </div>
                </div>
              </>
            )}
          </div>
        </div>
      </div>
    </>
  );
}
