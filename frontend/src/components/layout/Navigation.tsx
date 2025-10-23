import React from "react";
import { Link } from "react-router-dom";

interface NavigationItem {
  label: string;
  href: string;
  active?: boolean;
}

interface NavigationProps {
  items: NavigationItem[];
}

export const Navigation: React.FC<NavigationProps> = ({ items }) => {
  return (
    <nav className="sticky top-16 z-40 bg-white border-b border-gray-200">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex space-x-8 h-12">
          {items.map((item) => (
            <Link
              key={item.href}
              to={item.href}
              className={`
                inline-flex items-center px-1 border-b-2 text-sm font-medium transition-colors
                ${
                  item.active
                    ? "border-blue-500 text-gray-900"
                    : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700"
                }
              `}
            >
              {item.label}
            </Link>
          ))}
        </div>
      </div>
    </nav>
  );
};
