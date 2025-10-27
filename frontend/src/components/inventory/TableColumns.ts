import type { TableColumn } from "../../types/inventory-dashboard";

/**
 * Compact table column definitions - only essential columns
 */
export const COMPACT_TABLE_COLUMNS: TableColumn[] = [
  {
    key: "productName",
    label: "Товар",
    sortable: true,
    width: "w-48",
    align: "left",
  },
  {
    key: "warehouseName",
    label: "Склад",
    sortable: true,
    width: "w-40",
    align: "left",
  },
  {
    key: "status",
    label: "Статус",
    sortable: true,
    width: "w-28",
    align: "center",
  },
  {
    key: "currentStock",
    label: "Остаток",
    sortable: true,
    width: "w-24",
    align: "right",
    format: (value: number) => value.toLocaleString(),
  },
  {
    key: "sales28d",
    label: "Продажи 30д",
    sortable: true,
    width: "w-28",
    align: "right",
    format: (value: number) => value.toLocaleString(),
  },
  {
    key: "daysOfStock",
    label: "Дней запаса",
    sortable: true,
    width: "w-28",
    align: "right",
    format: (value: number) => (value > 999 ? "999+" : value.toFixed(0)),
  },
  {
    key: "recommendedQty",
    label: "Рекомендация",
    sortable: true,
    width: "w-28",
    align: "right",
    format: (value: number) => value.toLocaleString(),
  },
];
