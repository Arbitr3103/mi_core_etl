import type { ApiError } from "@/types";

// API configuration
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || "/api";
const API_TIMEOUT = 30000; // 30 seconds
const MAX_RETRIES = 3;
const RETRY_DELAY = 1000; // 1 second

// Custom error class for API errors
export class ApiException extends Error {
  constructor(
    message: string,
    public statusCode?: number,
    public response?: unknown,
    public isRetryable: boolean = false
  ) {
    super(message);
    this.name = "ApiException";
  }
}

// Helper function to determine if error is retryable
function isRetryableError(error: unknown): boolean {
  if (error instanceof ApiException) {
    // Retry on 5xx errors and network errors
    if (!error.statusCode) return true; // Network error
    return error.statusCode >= 500 && error.statusCode < 600;
  }
  return true; // Unknown errors are retryable
}

// Helper function to delay execution
function delay(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

// Request options interface
interface RequestOptions extends RequestInit {
  timeout?: number;
  params?: Record<string, string | number | boolean>;
}

// Helper function to build URL with query parameters
function buildUrl(
  endpoint: string,
  params?: Record<string, string | number | boolean>
): string {
  const url = new URL(`${API_BASE_URL}${endpoint}`, window.location.origin);

  if (params) {
    Object.entries(params).forEach(([key, value]) => {
      url.searchParams.append(key, String(value));
    });
  }

  return url.toString();
}

// Helper function to handle fetch with timeout
async function fetchWithTimeout(
  url: string,
  options: RequestOptions = {}
): Promise<Response> {
  const { timeout = API_TIMEOUT, ...fetchOptions } = options;

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeout);

  try {
    const response = await fetch(url, {
      ...fetchOptions,
      signal: controller.signal,
    });
    clearTimeout(timeoutId);
    return response;
  } catch (error) {
    clearTimeout(timeoutId);
    if (error instanceof Error && error.name === "AbortError") {
      throw new ApiException("Request timeout", 408);
    }
    throw error;
  }
}

// Generic request function with retry logic
async function request<T>(
  endpoint: string,
  options: RequestOptions = {},
  retryCount = 0
): Promise<T> {
  const { params, ...fetchOptions } = options;

  const url = buildUrl(endpoint, params);

  const defaultHeaders: HeadersInit = {
    "Content-Type": "application/json",
  };

  try {
    const response = await fetchWithTimeout(url, {
      ...fetchOptions,
      headers: {
        ...defaultHeaders,
        ...fetchOptions.headers,
      },
    });

    // Handle non-OK responses
    if (!response.ok) {
      let errorData: ApiError;
      try {
        errorData = await response.json();
      } catch {
        errorData = {
          error: response.statusText || "Unknown error",
          code: response.status,
        };
      }

      const isRetryable = response.status >= 500 && response.status < 600;
      throw new ApiException(
        errorData.message || errorData.error || "Request failed",
        response.status,
        errorData,
        isRetryable
      );
    }

    // Parse JSON response
    const data = await response.json();
    return data as T;
  } catch (error) {
    // Retry logic for retryable errors
    if (retryCount < MAX_RETRIES && isRetryableError(error)) {
      const delayMs = RETRY_DELAY * Math.pow(2, retryCount); // Exponential backoff
      console.warn(
        `Request failed, retrying in ${delayMs}ms (attempt ${
          retryCount + 1
        }/${MAX_RETRIES})`,
        error
      );
      await delay(delayMs);
      return request<T>(endpoint, options, retryCount + 1);
    }

    // Throw the error if not retryable or max retries reached
    if (error instanceof ApiException) {
      throw error;
    }

    if (error instanceof Error) {
      throw new ApiException(error.message, undefined, undefined, false);
    }

    throw new ApiException(
      "Unknown error occurred",
      undefined,
      undefined,
      false
    );
  }
}

// API service object with HTTP methods
export const api = {
  get: <T>(endpoint: string, options?: RequestOptions) =>
    request<T>(endpoint, { ...options, method: "GET" }),

  post: <T>(endpoint: string, data?: unknown, options?: RequestOptions) =>
    request<T>(endpoint, {
      ...options,
      method: "POST",
      body: JSON.stringify(data),
    }),

  put: <T>(endpoint: string, data?: unknown, options?: RequestOptions) =>
    request<T>(endpoint, {
      ...options,
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: <T>(endpoint: string, options?: RequestOptions) =>
    request<T>(endpoint, { ...options, method: "DELETE" }),

  patch: <T>(endpoint: string, data?: unknown, options?: RequestOptions) =>
    request<T>(endpoint, {
      ...options,
      method: "PATCH",
      body: JSON.stringify(data),
    }),
};

// Export helper functions for testing
export { buildUrl, fetchWithTimeout };
