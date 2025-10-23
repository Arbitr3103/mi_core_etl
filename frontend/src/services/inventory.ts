import { api } from "./api";
import type { DashboardData, Product } from "@/types";

// Inventory service endpoints
const ENDPOINTS = {
  DASHBOARD: "/inventory/dashboard",
  PRODUCT_DETAILS: (sku: string) => `/inventory/products/${sku}`,
  PRODUCTS_LIST: "/inventory/products",
} as const;

// Inventory service interface
export interface GetDashboardDataParams {
  limit?: number;
}

export interface GetProductsParams {
  warehouse?: string;
  category?: string;
  stockStatus?: string;
  limit?: number;
  offset?: number;
}

// Inventory service
export const inventoryService = {
  /**
   * Get dashboard data with product groups
   */
  async getDashboardData(
    params: GetDashboardDataParams = {}
  ): Promise<DashboardData> {
    const response = await api.get<{ success: boolean; data: DashboardData }>(
      ENDPOINTS.DASHBOARD,
      {
        params: {
          limit: params.limit || 10,
        },
      }
    );
    return response.data;
  },

  /**
   * Get product details by SKU
   */
  async getProductBySku(sku: string): Promise<Product> {
    const response = await api.get<Product>(ENDPOINTS.PRODUCT_DETAILS(sku));
    return response;
  },

  /**
   * Get products list with filters
   */
  async getProducts(params: GetProductsParams = {}): Promise<Product[]> {
    const response = await api.get<Product[]>(ENDPOINTS.PRODUCTS_LIST, {
      params: {
        ...params,
        limit: params.limit || 100,
        offset: params.offset || 0,
      },
    });
    return response;
  },

  /**
   * Get products by stock status
   */
  async getProductsByStockStatus(
    stockStatus: "critical" | "low_stock" | "overstock",
    limit?: number
  ): Promise<Product[]> {
    return this.getProducts({
      stockStatus,
      limit,
    });
  },
};

// Export types
export type InventoryService = typeof inventoryService;
