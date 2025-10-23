import React from "react";
import type {
  FilterState,
  LiquidityStatus,
  WarehouseListItem,
  ClusterListItem,
} from "../../types/warehouse";

export interface WarehouseFiltersProps {
  filters: FilterState;
  onChange: (filters: FilterState) => void;
  warehouses?: WarehouseListItem[];
  clusters?: ClusterListItem[];
}

export const WarehouseFilters: React.FC<WarehouseFiltersProps> = ({
  filters,
  onChange,
  warehouses = [],
  clusters = [],
}) => {
  const liquidityOptions: { value: LiquidityStatus; label: string }[] = [
    { value: "critical", label: "Дефицит" },
    { value: "low", label: "Низкий запас" },
    { value: "normal", label: "Норма" },
    { value: "excess", label: "Избыток" },
  ];

  const handleWarehouseChange = (value: string) => {
    onChange({
      ...filters,
      warehouse: value === "" ? undefined : value,
    });
  };

  const handleClusterChange = (value: string) => {
    onChange({
      ...filters,
      cluster: value === "" ? undefined : value,
    });
  };

  const handleLiquidityStatusChange = (value: string) => {
    onChange({
      ...filters,
      liquidity_status: value === "" ? undefined : (value as LiquidityStatus),
    });
  };

  const handleActiveOnlyChange = (checked: boolean) => {
    onChange({
      ...filters,
      active_only: checked,
    });
  };

  const handleReplenishmentNeedChange = (checked: boolean) => {
    onChange({
      ...filters,
      has_replenishment_need: checked ? true : undefined,
    });
  };

  return (
    <div className="bg-white rounded-lg shadow-md p-6 mb-6">
      <h3 className="text-lg font-semibold text-gray-900 mb-4">Фильтры</h3>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
        {/* Warehouse filter */}
        <div>
          <label
            htmlFor="warehouse-filter"
            className="block text-sm font-medium text-gray-700 mb-1"
          >
            Склад
          </label>
          <select
            id="warehouse-filter"
            value={filters.warehouse || ""}
            onChange={(e) => handleWarehouseChange(e.target.value)}
            className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
          >
            <option value="">Все склады</option>
            {warehouses.map((warehouse) => (
              <option
                key={warehouse.warehouse_name}
                value={warehouse.warehouse_name}
              >
                {warehouse.warehouse_name} ({warehouse.cluster})
              </option>
            ))}
          </select>
        </div>

        {/* Cluster filter */}
        <div>
          <label
            htmlFor="cluster-filter"
            className="block text-sm font-medium text-gray-700 mb-1"
          >
            Кластер
          </label>
          <select
            id="cluster-filter"
            value={filters.cluster || ""}
            onChange={(e) => handleClusterChange(e.target.value)}
            className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
          >
            <option value="">Все кластеры</option>
            {clusters.map((cluster) => (
              <option key={cluster.cluster} value={cluster.cluster}>
                {cluster.cluster} ({cluster.warehouse_count} складов)
              </option>
            ))}
          </select>
        </div>

        {/* Liquidity status filter */}
        <div>
          <label
            htmlFor="liquidity-filter"
            className="block text-sm font-medium text-gray-700 mb-1"
          >
            Статус ликвидности
          </label>
          <select
            id="liquidity-filter"
            value={filters.liquidity_status || ""}
            onChange={(e) => handleLiquidityStatusChange(e.target.value)}
            className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
          >
            <option value="">Все статусы</option>
            {liquidityOptions.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>
      </div>

      {/* Checkboxes */}
      <div className="flex flex-wrap gap-6">
        <label className="inline-flex items-center cursor-pointer">
          <input
            type="checkbox"
            checked={filters.active_only}
            onChange={(e) => handleActiveOnlyChange(e.target.checked)}
            className="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-2 focus:ring-primary-500"
          />
          <span className="ml-2 text-sm font-medium text-gray-700">
            Только активные товары
          </span>
        </label>

        <label className="inline-flex items-center cursor-pointer">
          <input
            type="checkbox"
            checked={filters.has_replenishment_need || false}
            onChange={(e) => handleReplenishmentNeedChange(e.target.checked)}
            className="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-2 focus:ring-primary-500"
          />
          <span className="ml-2 text-sm font-medium text-gray-700">
            Только с потребностью в пополнении
          </span>
        </label>
      </div>
    </div>
  );
};
