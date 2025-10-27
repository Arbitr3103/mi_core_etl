/**
 * Status Indicator Component
 *
 * Displays color-coded visual indicators for inventory status levels
 * with tooltips showing detailed stock information.
 *
 * Requirements: 5.1, 5.2, 5.3, 5.4, 5.5
 */

import React, { useState } from "react";
import type {
  StockStatus,
  StatusIndicatorProps,
} from "../../types/inventory-dashboard";
import { STATUS_COLORS } from "../../types/inventory-dashboard";

/**
 * Get status label for display
 */
const getStatusLabel = (status: StockStatus): string => {
  switch (status) {
    case "critical":
      return "Critical";
    case "low":
      return "Low Stock";
    case "normal":
      return "Normal";
    case "excess":
      return "Excess";
    case "no_sales":
      return "No Sales";
    default:
      return "Unknown";
  }
};

/**
 * Get status description with days of stock context
 */
const getStatusDescription = (
  status: StockStatus,
  daysOfStock: number
): string => {
  switch (status) {
    case "critical":
      return `Critical stock level - only ${daysOfStock.toFixed(
        0
      )} days remaining. Immediate replenishment needed.`;
    case "low":
      return `Low stock level - ${daysOfStock.toFixed(
        0
      )} days remaining. Consider replenishment soon.`;
    case "normal":
      return `Normal stock level - ${daysOfStock.toFixed(
        0
      )} days remaining. No immediate action needed.`;
    case "excess":
      return `Excess stock - ${
        daysOfStock > 999 ? "999+" : daysOfStock.toFixed(0)
      } days remaining. Consider reducing orders.`;
    case "no_sales":
      return "No recent sales activity. Review product performance and demand.";
    default:
      return "Status unknown";
  }
};

/**
 * Get urgency level for additional styling
 */
const getUrgencyLevel = (
  status: StockStatus,
  daysOfStock: number
): "high" | "medium" | "low" => {
  if (status === "critical" || daysOfStock < 7) return "high";
  if (status === "low" || daysOfStock < 21) return "medium";
  return "low";
};

/**
 * Tooltip Component
 */
const Tooltip: React.FC<{
  children: React.ReactNode;
  content: string;
  show: boolean;
}> = ({ children, content, show }) => {
  return (
    <div className="relative inline-block">
      {children}
      {show && (
        <div className="absolute z-50 px-3 py-2 text-sm text-white bg-gray-900 rounded-lg shadow-lg -top-2 left-1/2 transform -translate-x-1/2 -translate-y-full w-64">
          <div className="text-center">{content}</div>
          {/* Arrow */}
          <div className="absolute top-full left-1/2 transform -translate-x-1/2">
            <div className="border-4 border-transparent border-t-gray-900"></div>
          </div>
        </div>
      )}
    </div>
  );
};

/**
 * Status Dot Component
 */
const StatusDot: React.FC<{
  status: StockStatus;
  size: "sm" | "md" | "lg";
  urgency: "high" | "medium" | "low";
}> = ({ status, size, urgency }) => {
  const sizeClasses = {
    sm: "w-2 h-2",
    md: "w-3 h-3",
    lg: "w-4 h-4",
  };

  const colors = STATUS_COLORS[status];

  // Add pulsing animation for high urgency
  const animationClass = urgency === "high" ? "animate-pulse" : "";

  return (
    <div
      className={`${sizeClasses[size]} ${colors.dot} rounded-full ${animationClass}`}
      aria-hidden="true"
      role="presentation"
    />
  );
};

/**
 * Main Status Indicator Component
 */
export const StatusIndicator: React.FC<StatusIndicatorProps> = ({
  status,
  daysOfStock,
  showTooltip = true,
  size = "md",
}) => {
  const [isHovered, setIsHovered] = useState(false);

  const colors = STATUS_COLORS[status];
  const label = getStatusLabel(status);
  const description = getStatusDescription(status, daysOfStock);
  const urgency = getUrgencyLevel(status, daysOfStock);

  // Size-specific classes
  const sizeClasses = {
    sm: {
      container: "px-2 py-1 text-xs",
      text: "text-xs",
    },
    md: {
      container: "px-3 py-1 text-sm",
      text: "text-sm",
    },
    lg: {
      container: "px-4 py-2 text-base",
      text: "text-base",
    },
  };

  const containerClasses = `
    inline-flex items-center space-x-2 rounded-full font-medium border
    ${colors.bg} ${colors.text} ${colors.border}
    ${sizeClasses[size].container}
    ${showTooltip ? "cursor-help" : ""}
    ${urgency === "high" ? "status-critical" : ""}
    transition-smooth hover:shadow-md hover-lift
  `.trim();

  const indicator = (
    <span
      className={containerClasses}
      onMouseEnter={() => showTooltip && setIsHovered(true)}
      onMouseLeave={() => showTooltip && setIsHovered(false)}
      onFocus={() => showTooltip && setIsHovered(true)}
      onBlur={() => showTooltip && setIsHovered(false)}
      role="status"
      aria-label={`Status: ${label}. ${description}`}
      tabIndex={showTooltip ? 0 : -1}
      id={`status-${Math.random().toString(36).substr(2, 9)}`}
    >
      <StatusDot status={status} size={size} urgency={urgency} />
      <span className={`font-medium ${sizeClasses[size].text}`}>{label}</span>
      {size !== "sm" && (
        <span className={`text-xs opacity-75`}>
          {status === "no_sales"
            ? ""
            : `${daysOfStock > 999 ? "999+" : daysOfStock.toFixed(0)}d`}
        </span>
      )}
    </span>
  );

  // Wrap with tooltip if enabled
  if (showTooltip) {
    return (
      <Tooltip content={description} show={isHovered}>
        {indicator}
      </Tooltip>
    );
  }

  return indicator;
};

/**
 * Status Legend Component
 *
 * Displays a legend explaining all status levels
 */
export const StatusLegend: React.FC<{
  size?: "sm" | "md" | "lg";
  orientation?: "horizontal" | "vertical";
}> = ({ size = "sm", orientation = "horizontal" }) => {
  const allStatuses: StockStatus[] = [
    "critical",
    "low",
    "normal",
    "excess",
    "no_sales",
  ];

  const containerClass =
    orientation === "horizontal" ? "flex flex-wrap gap-2" : "space-y-2";

  return (
    <div className={`${containerClass} p-3 bg-gray-50 rounded-lg`}>
      <div
        className={`${
          size === "sm" ? "text-xs" : "text-sm"
        } font-medium text-gray-700 ${
          orientation === "vertical" ? "mb-2" : "mr-3"
        }`}
      >
        Status Legend:
      </div>
      {allStatuses.map((status) => (
        <StatusIndicator
          key={status}
          status={status}
          daysOfStock={
            status === "critical"
              ? 7
              : status === "low"
              ? 21
              : status === "normal"
              ? 45
              : 90
          }
          size={size}
          showTooltip={false}
        />
      ))}
    </div>
  );
};

export default StatusIndicator;
