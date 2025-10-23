import { useRef, useMemo } from "react";
import { useVirtualizer } from "@tanstack/react-virtual";

export interface UseVirtualizationOptions<T> {
  items: T[];
  estimateSize: number;
  overscan?: number;
  enabled?: boolean;
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export interface UseVirtualizationReturn<T> {
  parentRef: React.RefObject<HTMLDivElement>;
  virtualizer: any;
  virtualItems: any;
  totalSize: number;
  items: T[];
}

/**
 * Custom hook for virtualizing large lists
 * Wraps @tanstack/react-virtual with sensible defaults
 */
export function useVirtualization<T>({
  items,
  estimateSize,
  overscan = 5,
  enabled = true,
}: UseVirtualizationOptions<T>): UseVirtualizationReturn<T> {
  const parentRef = useRef<HTMLDivElement>(null);

  // Create virtualizer instance
  const virtualizer = useVirtualizer({
    count: items.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => estimateSize,
    overscan,
    enabled,
  });

  // Get virtual items
  const virtualItems = useMemo(
    () => virtualizer.getVirtualItems(),
    [virtualizer]
  );

  // Get total size
  const totalSize = virtualizer.getTotalSize();

  return {
    parentRef,
    virtualizer,
    virtualItems,
    totalSize,
    items,
  };
}

/**
 * Hook for dynamic size virtualization
 * Use when item heights vary
 */
export interface UseDynamicVirtualizationOptions<T> {
  items: T[];
  estimateSize: (index: number) => number;
  overscan?: number;
  enabled?: boolean;
}

export function useDynamicVirtualization<T>({
  items,
  estimateSize,
  overscan = 5,
  enabled = true,
}: UseDynamicVirtualizationOptions<T>): UseVirtualizationReturn<T> {
  const parentRef = useRef<HTMLDivElement>(null);

  const virtualizer = useVirtualizer({
    count: items.length,
    getScrollElement: () => parentRef.current,
    estimateSize,
    overscan,
    enabled,
  });

  const virtualItems = useMemo(
    () => virtualizer.getVirtualItems(),
    [virtualizer]
  );

  const totalSize = virtualizer.getTotalSize();

  return {
    parentRef,
    virtualizer,
    virtualItems,
    totalSize,
    items,
  };
}

/**
 * Hook for horizontal virtualization
 */
export interface UseHorizontalVirtualizationOptions<T> {
  items: T[];
  estimateSize: number;
  overscan?: number;
  enabled?: boolean;
}

export function useHorizontalVirtualization<T>({
  items,
  estimateSize,
  overscan = 5,
  enabled = true,
}: UseHorizontalVirtualizationOptions<T>): UseVirtualizationReturn<T> {
  const parentRef = useRef<HTMLDivElement>(null);

  const virtualizer = useVirtualizer({
    horizontal: true,
    count: items.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => estimateSize,
    overscan,
    enabled,
  });

  const virtualItems = useMemo(
    () => virtualizer.getVirtualItems(),
    [virtualizer]
  );

  const totalSize = virtualizer.getTotalSize();

  return {
    parentRef,
    virtualizer,
    virtualItems,
    totalSize,
    items,
  };
}
