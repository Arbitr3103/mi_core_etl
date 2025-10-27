/**
 * Diagnostic Page - для проверки что React работает
 */

import React, { useEffect, useState } from "react";

export const DiagnosticPage: React.FC = () => {
  const [apiStatus, setApiStatus] = useState<string>("Checking...");
  const [warehousesData, setWarehousesData] = useState<any>(null);

  useEffect(() => {
    // Test API connection
    fetch("/api/inventory/detailed-stock?action=summary")
      .then((res) => res.json())
      .then((data) => {
        setApiStatus("✅ API Connected");
        console.log("API Summary:", data);
      })
      .catch((err) => {
        setApiStatus("❌ API Error: " + err.message);
        console.error("API Error:", err);
      });

    // Test warehouses endpoint
    fetch("/api/inventory/detailed-stock?action=warehouses")
      .then((res) => res.json())
      .then((data) => {
        setWarehousesData(data);
        console.log("Warehouses:", data);
      })
      .catch((err) => {
        console.error("Warehouses Error:", err);
      });
  }, []);

  return (
    <div style={{ padding: "20px", fontFamily: "Arial, sans-serif" }}>
      <h1>🔧 Diagnostic Page</h1>

      <div style={{ marginTop: "20px" }}>
        <h2>System Status</h2>
        <p>
          <strong>React:</strong> ✅ Working
        </p>
        <p>
          <strong>API:</strong> {apiStatus}
        </p>
      </div>

      <div style={{ marginTop: "20px" }}>
        <h2>Warehouses Data</h2>
        {warehousesData ? (
          <pre style={{ background: "#f5f5f5", padding: "10px" }}>
            {JSON.stringify(warehousesData, null, 2)}
          </pre>
        ) : (
          <p>Loading...</p>
        )}
      </div>

      <div style={{ marginTop: "20px" }}>
        <h2>Navigation</h2>
        <p>
          <a href="/warehouse">Go to Warehouse Dashboard</a>
        </p>
      </div>
    </div>
  );
};
