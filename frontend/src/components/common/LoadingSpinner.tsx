/**
 * Loading Spinner Component
 *
 * Reusable loading spinner with different sizes and optional message.
 *
 * Requirements: 7.4
 */

import React from "react";

interface LoadingSpinnerProps {
  size?: "sm" | "md" | "lg";
  message?: string;
  className?: string;
}

const sizeClasses = {
  sm: "w-4 h-4",
  md: "w-8 h-8",
  lg: "w-12 h-12",
};

export const LoadingSpinner: React.FC<LoadingSpinnerProps> = ({
  size = "md",
  message,
  className = "",
}) => {
  return (
    <div
      className={`flex flex-col items-center justify-center ${className}`}
      role="status"
      aria-live="polite"
    >
      <div
        className={`${sizeClasses[size]} animate-spin text-blue-600`}
        aria-hidden="true"
      >
        <svg
          className="w-full h-full"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
          xmlns="http://www.w3.org/2000/svg"
        >
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={2}
            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
          />
        </svg>
      </div>

      {message && (
        <p
          className="mt-2 text-sm text-gray-600 text-center"
          aria-live="polite"
        >
          {message}
        </p>
      )}
      <span className="sr-only">
        {message || "Loading content, please wait..."}
      </span>
    </div>
  );
};

export default LoadingSpinner;
