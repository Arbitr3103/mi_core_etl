/**
 * Simple translation hook for Russian language
 */

import { ru } from "../i18n/ru";

export const useTranslation = () => {
  const t = (key: string, params?: Record<string, string | number>): string => {
    // Navigate through nested keys (e.g., "dashboard.title")
    const keys = key.split(".");
    let value: any = ru;

    for (const k of keys) {
      value = value?.[k];
      if (value === undefined) {
        console.warn(`Translation key not found: ${key}`);
        return key;
      }
    }

    // Replace parameters in the string
    if (typeof value === "string" && params) {
      return value.replace(/\{(\w+)\}/g, (match, paramKey) => {
        return params[paramKey]?.toString() || match;
      });
    }

    return value;
  };

  return { t };
};
