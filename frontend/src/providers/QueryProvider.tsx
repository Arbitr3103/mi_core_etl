import React from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { API_CONFIG } from "@/utils";

// Create a client
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: API_CONFIG.CACHE_TIME,
      gcTime: API_CONFIG.CACHE_TIME * 2,
      retry: API_CONFIG.RETRY_ATTEMPTS,
      refetchOnWindowFocus: false,
    },
  },
});

export interface QueryProviderProps {
  children: React.ReactNode;
}

export const QueryProvider: React.FC<QueryProviderProps> = ({ children }) => {
  return (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
};

// Export queryClient for use in tests
export { queryClient };
