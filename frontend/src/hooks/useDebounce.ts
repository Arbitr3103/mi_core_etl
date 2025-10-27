/**
 * Debounce Hook for Performance Optimization
 *
 * Provides debounced values to reduce API calls during user input.
 *
 * Requirements: 7.2, 7.3
 * Task: 4.2 Frontend performance optimization - debounced search
 */

import { useState, useEffect } from "react";

/**
 * Hook that debounces a value
 *
 * @param value The value to debounce
 * @param delay Delay in milliseconds
 * @returns Debounced value
 */
export function useDebounce<T>(value: T, delay: number): T {
  const [debouncedValue, setDebouncedValue] = useState<T>(value);

  useEffect(() => {
    // Set up a timer to update the debounced value after the delay
    const handler = setTimeout(() => {
      setDebouncedValue(value);
    }, delay);

    // Clean up the timer if value changes before delay completes
    return () => {
      clearTimeout(handler);
    };
  }, [value, delay]);

  return debouncedValue;
}

/**
 * Hook for debounced search with additional optimizations
 *
 * @param searchTerm The search term to debounce
 * @param delay Delay in milliseconds (default: 300ms)
 * @param minLength Minimum length before search is triggered (default: 2)
 * @returns Object with debounced search term and search state
 */
export function useDebouncedSearch(
  searchTerm: string,
  delay: number = 300,
  minLength: number = 2
) {
  const [debouncedSearchTerm, setDebouncedSearchTerm] = useState("");
  const [isSearching, setIsSearching] = useState(false);

  useEffect(() => {
    // If search term is too short, clear the debounced value immediately
    if (searchTerm.length < minLength) {
      setDebouncedSearchTerm("");
      setIsSearching(false);
      return;
    }

    setIsSearching(true);

    const handler = setTimeout(() => {
      setDebouncedSearchTerm(searchTerm);
      setIsSearching(false);
    }, delay);

    return () => {
      clearTimeout(handler);
    };
  }, [searchTerm, delay, minLength]);

  return {
    debouncedSearchTerm,
    isSearching: isSearching && searchTerm.length >= minLength,
    shouldSearch: debouncedSearchTerm.length >= minLength,
  };
}

/**
 * Hook for debounced filter changes
 *
 * @param filters The filters object to debounce
 * @param delay Delay in milliseconds (default: 150ms)
 * @returns Debounced filters
 */
export function useDebouncedFilters<T extends Record<string, any>>(
  filters: T,
  delay: number = 150
): T {
  const [debouncedFilters, setDebouncedFilters] = useState<T>(filters);

  useEffect(() => {
    const handler = setTimeout(() => {
      setDebouncedFilters(filters);
    }, delay);

    return () => {
      clearTimeout(handler);
    };
  }, [filters, delay]);

  return debouncedFilters;
}
