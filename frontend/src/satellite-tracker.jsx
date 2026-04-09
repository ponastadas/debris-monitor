import { useState, useEffect, useRef } from "react";
import * as THREE from "three";

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

export default function SatelliteTracker() {
  const mountRef = useRef(null);
  const sceneRef = useRef(null);
  const rendererRef = useRef(null);
  const earthRef = useRef(null);
  const atmRef = useRef(null);
  const frameRef = useRef(null);
  const trackedObjectsRef = useRef([]);
  const satJsRef = useRef(false);

  const [noradInput, setNoradInput] = useState("25544");
  const [satInfo, setSatInfo] = useState(null);
  const [debris, setDebris] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [overallRisk, setOverallRisk] = useState(null);
  const [libReady, setLibReady] = useState(false);

  // Inject styles + load satellite.js
  useEffect(() => {
    const styleEl = document.createElement("style");
    styleEl.textContent = STYLE;
    document.head.appendChild(styleEl);

    const script = document.createElement("script");
    script.src = "https://cdnjs.cloudflare.com/ajax/libs/satellite.js/4.0.0/satellite.min.js";
    script.onload = () => { satJsRef.current = true; setLibReady(true); };
    document.head.appendChild(script);

    return () => {
      document.head.removeChild(styleEl);
      if (document.head.contains(script)) document.head.removeChild(script);
    };
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

    // Animate
    const animate = () => {
      frameRef.current = requestAnimationFrame(animate);
      if (!dragging) { earth.rotation.y += 0.0008; atm.rotation.y += 0.0008; }
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

  const clearTracked = () => {
    const scene = sceneRef.current;
    if (!scene) return;
    trackedObjectsRef.current.forEach((o) => scene.remove(o));
    trackedObjectsRef.current = [];
  };

  const addTracked = (obj) => {
    sceneRef.current?.add(obj);
    trackedObjectsRef.current.push(obj);
  };

  const handleTrack = async () => {
    if (!satJsRef.current || !window.satellite) {
      setError("Propagation library loading… please try again in a moment.");
      return;
    }
    setLoading(true);
    setError(null);
    setSatInfo(null);
    setDebris([]);
    setOverallRisk(null);
    clearTracked();

    try {
      const res = await fetch(
        `https://celestrak.org/NORAD/elements/gp.php?CATNR=${noradInput.trim()}&FORMAT=TLE`,
        { headers: { Accept: "text/plain" } }
      );
      const text = await res.text();
      if (!text || text.includes("No GP data") || text.trim().length < 30) {
        throw new Error(`No data found for NORAD ID ${noradInput.trim()}. Try: 25544 (ISS), 20580 (Hubble).`);
      }
      const lines = text.trim().split("\n").map((l) => l.trim());
      if (lines.length < 3) throw new Error("Invalid TLE format.");

      const [tleName, tle1, tle2] = lines;
      const satrec = window.satellite.twoline2satrec(tle1, tle2);
      const now = new Date();
      const posVel = window.satellite.propagate(satrec, now);
      if (!posVel.position) throw new Error("Could not propagate orbit. The satellite may have decayed.");

      const gmst = window.satellite.gstime(now);
      const geo = window.satellite.eciToGeodetic(posVel.position, gmst);
      const lat = window.satellite.degreesLat(geo.latitude);
      const lon = window.satellite.degreesLong(geo.longitude);
      const alt = geo.height;
      const spd = Math.sqrt(posVel.velocity.x ** 2 + posVel.velocity.y ** 2 + posVel.velocity.z ** 2).toFixed(2);

      setSatInfo({ name: tleName, lat: lat.toFixed(3), lon: lon.toFixed(3), alt: alt.toFixed(0), speed: spd, id: noradInput.trim() });

      // Place satellite on globe
      // Account for earth's current rotation
      const earthRotY = earthRef.current?.rotation.y || 0;
      const earthRotX = earthRef.current?.rotation.x || 0;

      const satPos = latLonAltToVec3(lat, lon, alt);

      // Satellite mesh
      const satGeo = new THREE.SphereGeometry(0.018, 16, 16);
      const satMat = new THREE.MeshBasicMaterial({ color: 0x00d4ff });
      const satMesh = new THREE.Mesh(satGeo, satMat);
      satMesh.position.copy(satPos);
      addTracked(satMesh);

      // Pulsing ring
      const ringGeo = new THREE.RingGeometry(0.025, 0.04, 32);
      const ringMat = new THREE.MeshBasicMaterial({ color: 0x00d4ff, transparent: true, opacity: 0.4, side: THREE.DoubleSide });
      const ring = new THREE.Mesh(ringGeo, ringMat);
      ring.position.copy(satPos);
      ring.lookAt(new THREE.Vector3(0, 0, 0));
      addTracked(ring);

      // Radial line
      const radPts = [new THREE.Vector3(0, 0, 0), satPos];
      const radGeo = new THREE.BufferGeometry().setFromPoints(radPts);
      addTracked(new THREE.Line(radGeo, new THREE.LineBasicMaterial({ color: 0x00d4ff, transparent: true, opacity: 0.15 })));

      // Orbital path (~90 min)
      const orbitPts = [];
      for (let i = 0; i <= 90; i++) {
        const t = new Date(now.getTime() + i * 60000);
        const pv = window.satellite.propagate(satrec, t);
        if (!pv.position) continue;
        const g = window.satellite.gstime(t);
        const geo2 = window.satellite.eciToGeodetic(pv.position, g);
        orbitPts.push(latLonAltToVec3(window.satellite.degreesLat(geo2.latitude), window.satellite.degreesLong(geo2.longitude), geo2.height));
      }
      if (orbitPts.length > 1) {
        const oGeo = new THREE.BufferGeometry().setFromPoints(orbitPts);
        addTracked(new THREE.Line(oGeo, new THREE.LineBasicMaterial({ color: 0x00d4ff, transparent: true, opacity: 0.25 })));
      }

      // Debris
      const debrisData = generateMockDebris(lat, lon, alt);
      setDebris(debrisData);
      const maxRisk = Math.max(...debrisData.map((d) => d.riskScore));
      setOverallRisk(maxRisk);

      debrisData.forEach((d) => {
        const dp = latLonAltToVec3(d.lat, d.lon, parseFloat(d.alt));
        const color = d.riskLevel === "HIGH" ? 0xff3b30 : d.riskLevel === "MEDIUM" ? 0xff9500 : 0x30d158;
        const dGeo = new THREE.SphereGeometry(0.01, 8, 8);
        const dMesh = new THREE.Mesh(dGeo, new THREE.MeshBasicMaterial({ color }));
        dMesh.position.copy(dp);
        addTracked(dMesh);

        // Line sat → debris
        const linkPts = [satPos, dp];
        const linkGeo = new THREE.BufferGeometry().setFromPoints(linkPts);
        const linkMat = new THREE.LineBasicMaterial({ color, transparent: true, opacity: d.riskLevel === "HIGH" ? 0.35 : 0.12 });
        addTracked(new THREE.Line(linkGeo, linkMat));
      });

    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
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
            <div className="section-label">Target Satellite</div>
            <div className="input-row">
              <input
                className="norad-input"
                value={noradInput}
                onChange={(e) => setNoradInput(e.target.value)}
                placeholder="NORAD ID"
                onKeyDown={(e) => e.key === "Enter" && handleTrack()}
              />
              <button className="track-btn" onClick={handleTrack} disabled={loading || !libReady}>
                {loading ? "..." : "TRACK"}
              </button>
            </div>
            <div className="quick-ids">
              {QUICK_SATS.map((s) => (
                <div key={s.id} className="quick-id" onClick={() => { setNoradInput(s.id); }}>
                  {s.label}
                </div>
              ))}
            </div>
            {loading && (
              <div className="loading-row">
                <div className="spinner" />
                ACQUIRING TLE DATA…
              </div>
            )}
            {error && <div className="error-box">⚠ {error}</div>}
          </div>

          {/* Satellite Info */}
          {satInfo && (
            <div className="section">
              <div className="section-label">Object Data</div>
              <div className="live-badge">LIVE POSITION</div>
              <div className="sat-name">{satInfo.name}</div>
              <div className="data-grid">
                <div className="data-cell">
                  <div className="data-cell-label">LATITUDE</div>
                  <div className="data-cell-value">{satInfo.lat}<span className="data-cell-unit">°</span></div>
                </div>
                <div className="data-cell">
                  <div className="data-cell-label">LONGITUDE</div>
                  <div className="data-cell-value">{satInfo.lon}<span className="data-cell-unit">°</span></div>
                </div>
                <div className="data-cell">
                  <div className="data-cell-label">ALTITUDE</div>
                  <div className="data-cell-value">{satInfo.alt}<span className="data-cell-unit">km</span></div>
                </div>
                <div className="data-cell">
                  <div className="data-cell-label">VELOCITY</div>
                  <div className="data-cell-value">{satInfo.speed}<span className="data-cell-unit">km/s</span></div>
                </div>
              </div>
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

          {!satInfo && !loading && !error && (
            <div className="empty-state">
              ENTER A NORAD ID<br />OR SELECT A QUICK TARGET<br />TO BEGIN TRACKING
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
