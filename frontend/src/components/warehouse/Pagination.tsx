import React from "react";
import type { PaginationInfo } from "../../types/warehouse";

export interface PaginationProps {
  pagination: PaginationInfo;
  onPageChange: (page: number) => void;
}

/**
 * Pagination component for warehouse dashboard
 *
 * Displays page navigation controls with:
 * - Previous/Next buttons
 * - Page numbers
 * - Total count information
 *
 * Requirements: 12
 */
export const Pagination: React.FC<PaginationProps> = ({
  pagination,
  onPageChange,
}) => {
  const {
    current_page,
    total_pages,
    has_prev,
    has_next,
    total,
    limit,
    offset,
  } = pagination;

  // Calculate displayed items range
  const startItem = offset + 1;
  const endItem = Math.min(offset + limit, total);

  // Generate page numbers to display
  const getPageNumbers = () => {
    const pages: (number | string)[] = [];
    const maxPagesToShow = 7;

    if (total_pages <= maxPagesToShow) {
      // Show all pages if total is small
      for (let i = 1; i <= total_pages; i++) {
        pages.push(i);
      }
    } else {
      // Show first page
      pages.push(1);

      if (current_page > 3) {
        pages.push("...");
      }

      // Show pages around current page
      const start = Math.max(2, current_page - 1);
      const end = Math.min(total_pages - 1, current_page + 1);

      for (let i = start; i <= end; i++) {
        pages.push(i);
      }

      if (current_page < total_pages - 2) {
        pages.push("...");
      }

      // Show last page
      pages.push(total_pages);
    }

    return pages;
  };

  if (total === 0) {
    return null;
  }

  return (
    <div className="bg-white rounded-lg shadow p-4">
      <div className="flex items-center justify-between">
        {/* Mobile view */}
        <div className="flex-1 flex justify-between sm:hidden">
          <button
            onClick={() => onPageChange(current_page - 1)}
            disabled={!has_prev}
            className={`px-4 py-2 border border-gray-300 rounded-md text-sm font-medium ${
              has_prev
                ? "text-gray-700 bg-white hover:bg-gray-50"
                : "text-gray-400 bg-gray-100 cursor-not-allowed opacity-50"
            }`}
          >
            Назад
          </button>
          <button
            onClick={() => onPageChange(current_page + 1)}
            disabled={!has_next}
            className={`px-4 py-2 border border-gray-300 rounded-md text-sm font-medium ${
              has_next
                ? "text-gray-700 bg-white hover:bg-gray-50"
                : "text-gray-400 bg-gray-100 cursor-not-allowed opacity-50"
            }`}
          >
            Вперед
          </button>
        </div>

        {/* Desktop view */}
        <div className="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
          <div>
            <p className="text-sm text-gray-700">
              Показано <span className="font-medium">{startItem}</span>
              {" - "}
              <span className="font-medium">{endItem}</span>
              {" из "}
              <span className="font-medium">{total}</span>
              {" товаров"}
            </p>
          </div>
          <div>
            <nav
              className="relative z-0 inline-flex rounded-md shadow-sm space-x-2"
              aria-label="Pagination"
            >
              {/* Previous button */}
              <button
                onClick={() => onPageChange(current_page - 1)}
                disabled={!has_prev}
                className={`px-4 py-2 border border-gray-300 rounded-md text-sm font-medium ${
                  has_prev
                    ? "text-gray-700 bg-white hover:bg-gray-50"
                    : "text-gray-400 bg-gray-100 cursor-not-allowed opacity-50"
                }`}
              >
                <span className="sr-only">Предыдущая</span>
                <svg
                  className="h-5 w-5"
                  xmlns="http://www.w3.org/2000/svg"
                  viewBox="0 0 20 20"
                  fill="currentColor"
                  aria-hidden="true"
                >
                  <path
                    fillRule="evenodd"
                    d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z"
                    clipRule="evenodd"
                  />
                </svg>
              </button>

              {/* Page numbers */}
              {getPageNumbers().map((page, index) => {
                if (page === "...") {
                  return (
                    <span
                      key={`ellipsis-${index}`}
                      className="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md bg-white text-sm font-medium text-gray-700"
                    >
                      ...
                    </span>
                  );
                }

                const pageNum = page as number;
                const isCurrentPage = pageNum === current_page;

                return (
                  <button
                    key={pageNum}
                    onClick={() => onPageChange(pageNum)}
                    className={`px-4 py-2 border rounded-md text-sm font-medium ${
                      isCurrentPage
                        ? "bg-primary-50 border-primary-500 text-primary-600"
                        : "bg-white border-gray-300 text-gray-700 hover:bg-gray-50"
                    }`}
                  >
                    {pageNum}
                  </button>
                );
              })}

              {/* Next button */}
              <button
                onClick={() => onPageChange(current_page + 1)}
                disabled={!has_next}
                className={`px-4 py-2 border border-gray-300 rounded-md text-sm font-medium ${
                  has_next
                    ? "text-gray-700 bg-white hover:bg-gray-50"
                    : "text-gray-400 bg-gray-100 cursor-not-allowed opacity-50"
                }`}
              >
                <span className="sr-only">Следующая</span>
                <svg
                  className="h-5 w-5"
                  xmlns="http://www.w3.org/2000/svg"
                  viewBox="0 0 20 20"
                  fill="currentColor"
                  aria-hidden="true"
                >
                  <path
                    fillRule="evenodd"
                    d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                    clipRule="evenodd"
                  />
                </svg>
              </button>
            </nav>
          </div>
        </div>
      </div>
    </div>
  );
};
