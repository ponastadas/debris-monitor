import { useState } from "react";
import DebrisMonitor from "./DebrisMonitor";
import SatelliteTracker from "./satellite-tracker";

export default function App() {
  const [view, setView]       = useState("catalog");
  const [trackId, setTrackId] = useState("25544");

  function handleTrack(noradId) {
    setTrackId(noradId);
    setView("tracker");
  }

  return (
    <div style={{ position: "relative", width: "100vw", height: "100vh" }}>
      {/* View toggle */}
      <div style={{
        position: "absolute",
        top: 16,
        right: 360,
        zIndex: 100,
        display: "flex",
        gap: 8,
        fontFamily: "'JetBrains Mono', monospace",
      }}>
        {[
          { key: "catalog", label: "CATALOG" },
          { key: "tracker", label: "TRACKER" },
        ].map(({ key, label }) => (
          <button
            key={key}
            onClick={() => setView(key)}
            style={{
              padding: "6px 14px",
              fontSize: 9,
              letterSpacing: 2,
              background: view === key ? "rgba(0,212,255,0.15)" : "rgba(0,212,255,0.05)",
              border: `1px solid ${view === key ? "#00d4ff" : "rgba(0,212,255,0.2)"}`,
              borderRadius: 4,
              color: view === key ? "#00d4ff" : "rgba(0,212,255,0.5)",
              cursor: "pointer",
            }}
          >
            {label}
          </button>
        ))}
      </div>

      {view === "catalog"
        ? <DebrisMonitor onTrack={handleTrack} />
        : <SatelliteTracker initialNoradId={trackId} />
      }
    </div>
  );
}
