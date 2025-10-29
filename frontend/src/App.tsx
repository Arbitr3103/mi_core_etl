/**
 * Main App Component
 *
 * Routing and layout for the application
 */

import { Routes, Route, Navigate } from "react-router-dom";
import { WarehouseDashboard } from "./components/inventory/WarehouseDashboard";
import { ETLStatusExample } from "./components/analytics/examples/ETLStatusExample";
import { DiagnosticPage } from "./components/DiagnosticPage";

function App() {
  return (
    <div className="App">
      <Routes>
        {/* Diagnostic Page */}
        <Route path="/diagnostic" element={<DiagnosticPage />} />

        {/* Warehouse Dashboard Route */}
        <Route path="/" element={<WarehouseDashboard />} />

        {/* Analytics Demo Route */}
        <Route path="/analytics" element={<ETLStatusExample />} />

        {/* Catch-all route */}
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </div>
  );
}

export default App;
