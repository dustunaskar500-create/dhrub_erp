import { useEffect } from "react";
import "@/App.css";

// Emergent preview lands here at "/". Aether lives on Apache (port 8001) and is
// reverse-proxied by craco.config.js. We redirect immediately to the standalone
// Aether chat UI so visiting the preview URL drops you directly into the AI.
function App() {
  useEffect(() => {
    window.location.replace("/aetherV2/chat.php");
  }, []);

  return (
    <div
      style={{
        display: "flex",
        height: "100vh",
        alignItems: "center",
        justifyContent: "center",
        background: "#0f1419",
        color: "#e6edf3",
        fontFamily: "system-ui, sans-serif",
        flexDirection: "column",
        gap: 14,
      }}
    >
      <div
        style={{
          width: 48,
          height: 48,
          borderRadius: "50%",
          border: "3px solid #10b981",
          borderTopColor: "transparent",
          animation: "spin 1s linear infinite",
        }}
      />
      <p style={{ opacity: 0.7, margin: 0 }}>Summoning Aether…</p>
      <style>{`@keyframes spin{to{transform:rotate(360deg)}}`}</style>
    </div>
  );
}

export default App;
