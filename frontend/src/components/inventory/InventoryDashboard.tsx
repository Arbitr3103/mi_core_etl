import React from "react";
import { useInventory } from "../../hooks/useInventory";
import { useLocalStorage } from "../../hooks/useLocalStorage";
import type { ViewMode } from "../../types/inventory";
import { ProductList } from "./ProductList";
import { ViewModeToggle } from "./ViewModeToggle";
import { LoadingSpinner } from "../ui/LoadingSpinner";
import { ErrorMessage } from "../ui/ErrorMessage";

interface InventoryDashboardProps {
  initialViewMode?: ViewMode;
}

const InventoryDashboard: React.FC<InventoryDashboardProps> = ({
  initialViewMode = "top10",
}) => {
  const [viewMode, setViewMode] = useLocalStorage<ViewMode>(
    "inventory-view-mode",
    initialViewMode
  );

  const { data, isLoading, error, refetch, isFetching } = useInventory();

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <LoadingSpinner size="xl" text="Загрузка данных..." />
      </div>
    );
  }

  if (error) {
    return (
      <div className="max-w-2xl mx-auto mt-8">
        <ErrorMessage
          title="Ошибка загрузки данных"
          error={
            error instanceof Error
              ? error
              : new Error("Не удалось загрузить данные инвентаря")
          }
          onRetry={() => refetch()}
          showDetails={true}
        />
      </div>
    );
  }

  if (!data) {
    return (
      <div className="text-center py-12">
        <p className="text-gray-500">Нет данных для отображения</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {isFetching && !isLoading && (
        <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 flex items-center justify-center">
          <LoadingSpinner size="sm" />
          <span className="ml-2 text-sm text-blue-700">
            Обновление данных...
          </span>
        </div>
      )}

      <ViewModeToggle
        mode={viewMode}
        onChange={setViewMode}
        counts={{
          critical: data.critical_products.count,
          lowStock: data.low_stock_products.count,
          overstock: data.overstock_products.count,
        }}
      />

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <ProductList
          title="🚨 Критический остаток"
          products={data.critical_products}
          type="critical"
          viewMode={viewMode}
        />
        <ProductList
          title="⚠️ Низкий остаток"
          products={data.low_stock_products}
          type="low_stock"
          viewMode={viewMode}
        />
        <ProductList
          title="📈 Избыток товара"
          products={data.overstock_products}
          type="overstock"
          viewMode={viewMode}
        />
      </div>

      <div className="text-center text-sm text-gray-500">
        Последнее обновление:{" "}
        {new Date(data.last_updated).toLocaleString("ru-RU")}
      </div>
    </div>
  );
};

// Export as default for lazy loading
export default InventoryDashboard;

// Also export as named export for backward compatibility
export { InventoryDashboard };
