# MI Core ETL - Frontend

Modern React dashboard for inventory management built with Vite, TypeScript, and Tailwind CSS.

## 🚀 Tech Stack

-   **React 18** - UI library
-   **TypeScript** - Type safety
-   **Vite** - Build tool and dev server
-   **Tailwind CSS** - Utility-first CSS framework
-   **TanStack Query** - Server state management
-   **TanStack Virtual** - List virtualization
-   **Zustand** - Client state management
-   **Vitest** - Unit testing
-   **Testing Library** - Component testing

## 📁 Project Structure

```
frontend/
├── src/
│   ├── components/
│   │   ├── ui/              # Reusable UI components
│   │   ├── inventory/       # Business logic components
│   │   └── layout/          # Layout components
│   ├── hooks/               # Custom React hooks
│   ├── services/            # API services
│   ├── stores/              # Zustand stores
│   ├── types/               # TypeScript types
│   ├── utils/               # Utility functions
│   ├── providers/           # React context providers
│   └── test/                # Test utilities
├── public/                  # Static assets
└── dist/                    # Build output
```

## 🛠️ Development

### Prerequisites

-   Node.js >= 18.0.0
-   npm >= 9.0.0

### Installation

```bash
npm install
```

### Development Server

```bash
npm run dev
```

The app will be available at `http://localhost:3000`

### Build

```bash
npm run build
```

### Preview Production Build

```bash
npm run preview
```

### Type Checking

```bash
npm run type-check
```

### Linting

```bash
npm run lint
```

### Testing

```bash
# Run tests once
npm test

# Watch mode
npm run test:watch

# UI mode
npm run test:ui
```

## 📦 Available Components

### UI Components

-   **Button** - Customizable button with variants and loading state
-   **Card** - Container component with header, content, and footer
-   **Toggle** - Switch component for boolean values
-   **LoadingSpinner** - Loading indicator with customizable size
-   **VirtualList** - Virtualized list for large datasets
-   **ErrorMessage** - Error display with retry functionality

### Custom Hooks

-   **useInventory** - Fetch and manage inventory data
-   **useProduct** - Fetch product details by SKU
-   **useProducts** - Fetch products with filters
-   **useLocalStorage** - Persist state in localStorage
-   **useVirtualization** - Virtualize large lists

### Services

-   **api** - Generic API client with error handling
-   **inventoryService** - Inventory-specific API methods

## 🔧 Configuration

### Environment Variables

Create a `.env` file in the frontend directory:

```env
VITE_API_BASE_URL=/api
VITE_DEV_PORT=3000
VITE_ENABLE_DEBUG=false
```

### API Proxy

The development server proxies API requests to `http://localhost:8080`. Configure this in `vite.config.ts`.

## 📝 Code Style

-   Use TypeScript for all new files
-   Follow ESLint rules
-   Use Tailwind CSS for styling
-   Write tests for critical functionality
-   Document complex logic with comments

## 🧪 Testing

Tests are located in `src/test/` and use Vitest + Testing Library.

Example test:

```typescript
import { render, screen } from "@testing-library/react";
import { Button } from "@/components/ui/Button";

test("renders button with text", () => {
    render(<Button>Click me</Button>);
    expect(screen.getByText("Click me")).toBeInTheDocument();
});
```

## 📚 Documentation

-   [Vite Documentation](https://vitejs.dev/)
-   [React Documentation](https://react.dev/)
-   [TanStack Query](https://tanstack.com/query/latest)
-   [Tailwind CSS](https://tailwindcss.com/)

## 🤝 Contributing

1. Create a feature branch
2. Make your changes
3. Run tests and linting
4. Submit a pull request

## 📄 License

Proprietary - MI Core Development Team
