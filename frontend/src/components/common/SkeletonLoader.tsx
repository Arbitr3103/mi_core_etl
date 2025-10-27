/**
 * Skeleton Loader Component
 *
 * Provides skeleton loading states for better UX during data loading.
 *
 * Requirements: 7.4, 7.5
 */

import React from "react";

interface SkeletonLoaderProps {
  variant?: "text" | "rectangular" | "circular" | "table-row";
  width?: string | number;
  height?: string | number;
  className?: string;
  count?: number;
}

export const SkeletonLoader: React.FC<SkeletonLoaderProps> = ({
  variant = "text",
  width = "100%",
  height,
  className = "",
  count = 1,
}) => {
  const getVariantClasses = () => {
    switch (variant) {
      case "text":
        return "h-4 rounded";
      case "rectangular":
        return "rounded-md";
      case "circular":
        return "rounded-full";
      case "table-row":
        return "h-12 rounded";
      default:
        return "h-4 rounded";
    }
  };

  const skeletonElement = (
    <div
      className={`loading-shimmer bg-gray-200 ${getVariantClasses()} ${className}`}
      style={{
        width: typeof width === "number" ? `${width}px` : width,
        height: height
          ? typeof height === "number"
            ? `${height}px`
            : height
          : undefined,
      }}
      aria-hidden="true"
    />
  );

  if (count === 1) {
    return skeletonElement;
  }

  return (
    <div className="space-y-2">
      {Array.from({ length: count }, (_, index) => (
        <div key={index}>{skeletonElement}</div>
      ))}
    </div>
  );
};

/**
 * Table Skeleton Component
 */
export const TableSkeleton: React.FC<{
  rows?: number;
  columns?: number;
}> = ({ rows = 5, columns = 6 }) => {
  return (
    <div className="space-y-3">
      {/* Header skeleton */}
      <div className="grid grid-cols-6 gap-4 p-3 bg-gray-50 rounded-t-lg">
        {Array.from({ length: columns }, (_, index) => (
          <SkeletonLoader key={index} variant="text" height="16px" />
        ))}
      </div>

      {/* Row skeletons */}
      {Array.from({ length: rows }, (_, rowIndex) => (
        <div
          key={rowIndex}
          className="grid grid-cols-6 gap-4 p-3 border-b border-gray-100"
        >
          {Array.from({ length: columns }, (_, colIndex) => (
            <SkeletonLoader
              key={colIndex}
              variant="text"
              height="20px"
              width={colIndex === 0 ? "80%" : "60%"}
            />
          ))}
        </div>
      ))}
    </div>
  );
};

/**
 * Card Skeleton Component
 */
export const CardSkeleton: React.FC<{
  hasImage?: boolean;
  lines?: number;
}> = ({ hasImage = false, lines = 3 }) => {
  return (
    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4 space-y-3">
      {hasImage && <SkeletonLoader variant="rectangular" height="200px" />}
      <SkeletonLoader variant="text" height="24px" width="70%" />
      {Array.from({ length: lines }, (_, index) => (
        <SkeletonLoader
          key={index}
          variant="text"
          height="16px"
          width={index === lines - 1 ? "40%" : "90%"}
        />
      ))}
    </div>
  );
};

export default SkeletonLoader;
