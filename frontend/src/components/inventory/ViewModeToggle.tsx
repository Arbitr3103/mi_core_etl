import React from "react";
import type { ViewMode } from "../../types/inventory";

interface ViewModeToggleProps {
  mode: ViewMode;
  onChange: (mode: ViewMode) => void;
  counts: {
    critical: number;
    lowStock: number;
    overstock: number;
  };
}

export const ViewModeToggle: React.FC<ViewModeToggleProps> = React.memo(
  ({ mode, onChange, counts }) => {
    const totalProducts = React.useMemo(
      () => counts.critical + counts.lowStock + counts.overstock,
      [counts.critical, counts.lowStock, counts.overstock]
    );

    return (
      <div className="bg-white rounded-lg border border-gray-200 p-4 shadow-sm">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-6">
            <div>
              <p className="text-sm text-gray-500 mb-1">Режим отображения</p>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => onChange("top10")}
                  className={`
                  px-4 py-2 rounded-lg text-sm font-medium transition-colors
                  ${
                    mode === "top10"
                      ? "bg-blue-600 text-white shadow-sm"
                      : "bg-gray-100 text-gray-700 hover:bg-gray-200"
                  }
                `}
                >
                  Топ-10
                </button>
                <button
                  onClick={() => onChange("all")}
                  className={`
                  px-4 py-2 rounded-lg text-sm font-medium transition-colors
                  ${
                    mode === "all"
                      ? "bg-blue-600 text-white shadow-sm"
                      : "bg-gray-100 text-gray-700 hover:bg-gray-200"
                  }
                `}
                >
                  Показать все
                </button>
              </div>
            </div>

            <div className="h-12 w-px bg-gray-200" />

            <div className="flex items-center gap-4">
              <div>
                <p className="text-xs text-gray-500">Критический</p>
                <p className="text-lg font-bold text-red-600">
                  {counts.critical}
                </p>
              </div>
              <div>
                <p className="text-xs text-gray-500">Низкий остаток</p>
                <p className="text-lg font-bold text-yellow-600">
                  {counts.lowStock}
                </p>
              </div>
              <div>
                <p className="text-xs text-gray-500">Избыток</p>
                <p className="text-lg font-bold text-blue-600">
                  {counts.overstock}
                </p>
              </div>
            </div>
          </div>

          <div className="text-right">
            <p className="text-xs text-gray-500">Всего товаров</p>
            <p className="text-2xl font-bold text-gray-900">{totalProducts}</p>
          </div>
        </div>
      </div>
    );
  },
  (prevProps, nextProps) => {
    return (
      prevProps.mode === nextProps.mode &&
      prevProps.counts.critical === nextProps.counts.critical &&
      prevProps.counts.lowStock === nextProps.counts.lowStock &&
      prevProps.counts.overstock === nextProps.counts.overstock
    );
  }
);
