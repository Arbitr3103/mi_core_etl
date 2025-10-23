import React from "react";
import { useLocation } from "react-router-dom";
import { Header } from "./Header";
import { Navigation } from "./Navigation";

interface LayoutProps {
  children: React.ReactNode;
  title?: string;
  showNavigation?: boolean;
}

export const Layout: React.FC<LayoutProps> = ({
  children,
  title = "Дашборд складских остатков",
  showNavigation = true,
}) => {
  const location = useLocation();

  // Define navigation items with active state based on current route
  const navigationItems = [
    {
      label: "Склады",
      href: "/warehouse-dashboard",
      active: location.pathname === "/warehouse-dashboard",
    },
  ];

  return (
    <div className="min-h-screen bg-gray-50 flex flex-col">
      <Header title={title} />
      {showNavigation && <Navigation items={navigationItems} />}
      <main className="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
        {children}
      </main>
    </div>
  );
};
