import React from "react";

export interface ErrorMessageProps {
  error: Error | string;
  title?: string;
  onRetry?: () => void;
  className?: string;
  showDetails?: boolean;
}

export const ErrorMessage: React.FC<ErrorMessageProps> = ({
  error,
  title = "Произошла ошибка",
  onRetry,
  className = "",
  showDetails = false,
}) => {
  const errorMessage = typeof error === "string" ? error : error.message;
  const statusCode =
    typeof error !== "string" && "statusCode" in error
      ? (error as { statusCode?: number }).statusCode
      : undefined;

  // Determine user-friendly message based on status code
  const getUserFriendlyMessage = () => {
    if (statusCode === 404) {
      return "Запрашиваемые данные не найдены";
    }
    if (statusCode === 403) {
      return "У вас нет доступа к этим данным";
    }
    if (statusCode === 401) {
      return "Требуется авторизация";
    }
    if (statusCode && statusCode >= 500) {
      return "Ошибка сервера. Пожалуйста, попробуйте позже";
    }
    if (statusCode === 408) {
      return "Превышено время ожидания ответа от сервера";
    }
    if (!statusCode) {
      return "Ошибка подключения к серверу";
    }
    return errorMessage;
  };

  return (
    <div
      className={`bg-red-50 border border-red-200 rounded-lg p-6 ${className}`}
    >
      <div className="flex items-start">
        <div className="flex-shrink-0">
          <svg
            className="h-6 w-6 text-red-600"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
            />
          </svg>
        </div>
        <div className="ml-3 flex-1">
          <h3 className="text-sm font-medium text-red-800">{title}</h3>
          <div className="mt-2 text-sm text-red-700">
            <p>{getUserFriendlyMessage()}</p>
            {showDetails && statusCode && (
              <p className="mt-1 text-xs text-red-600">
                Код ошибки: {statusCode}
              </p>
            )}
          </div>
          {onRetry && (
            <div className="mt-4">
              <button
                type="button"
                onClick={onRetry}
                className="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-red-700 bg-red-100 hover:bg-red-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors"
              >
                <svg
                  className="mr-2 h-4 w-4"
                  xmlns="http://www.w3.org/2000/svg"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={2}
                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                  />
                </svg>
                Попробовать снова
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};
