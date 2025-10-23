import { lazy, Suspense } from "react";
import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import { QueryProvider } from "./providers";
import { Layout } from "./components/layout";
import { LoadingSpinner } from "./components/ui/LoadingSpinner";

// Lazy load page components
const WarehouseDashboardPage = lazy(
  () => import("./pages/WarehouseDashboardPage")
);

function App() {
  return (
    <QueryProvider>
      <BrowserRouter>
        <Layout title="Дашборд складов Ozon">
          <Suspense
            fallback={
              <div className="flex items-center justify-center min-h-[400px]">
                <LoadingSpinner size="xl" text="Загрузка приложения..." />
              </div>
            }
          >
            {/* @ts-expect-error - React Router 6 type compatibility issue with React 18 types */}
            <Routes>
              {/* Warehouse Dashboard - main route */}
              {/* @ts-expect-error - React Router 6 type compatibility issue with React 18 types */}
              <Route
                path="/warehouse-dashboard"
                element={<WarehouseDashboardPage />}
              />

              {/* Default route - redirect to warehouse dashboard */}
              {/* @ts-expect-error - React Router 6 type compatibility issue with React 18 types */}
              <Route
                path="/"
                element={<Navigate to="/warehouse-dashboard" replace />}
              />

              {/* Catch-all route - redirect to warehouse dashboard */}
              {/* @ts-expect-error - React Router 6 type compatibility issue with React 18 types */}
              <Route
                path="*"
                element={<Navigate to="/warehouse-dashboard" replace />}
              />
            </Routes>
          </Suspense>
        </Layout>
      </BrowserRouter>
    </QueryProvider>
  );
}

export default App;
