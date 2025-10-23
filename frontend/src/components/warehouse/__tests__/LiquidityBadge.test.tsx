import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { LiquidityBadge } from "../LiquidityBadge";
// LiquidityStatus type is imported via WarehouseItem type

describe("LiquidityBadge", () => {
  it("renders critical status with correct styling", () => {
    render(<LiquidityBadge status="critical" daysOfStock={5} />);

    const badge = screen.getByText(/Дефицит/);
    expect(badge).toBeInTheDocument();
    expect(badge).toHaveClass("bg-red-100", "text-red-800");
    expect(screen.getByText(/5 дн\./)).toBeInTheDocument();
  });

  it("renders low status with correct styling", () => {
    render(<LiquidityBadge status="low" daysOfStock={10} />);

    const badge = screen.getByText(/Низкий/);
    expect(badge).toBeInTheDocument();
    expect(badge).toHaveClass("bg-yellow-100", "text-yellow-800");
    expect(screen.getByText(/10 дн\./)).toBeInTheDocument();
  });

  it("renders normal status with correct styling", () => {
    render(<LiquidityBadge status="normal" daysOfStock={25} />);

    const badge = screen.getByText(/Норма/);
    expect(badge).toBeInTheDocument();
    expect(badge).toHaveClass("bg-green-100", "text-green-800");
    expect(screen.getByText(/25 дн\./)).toBeInTheDocument();
  });

  it("renders excess status with correct styling", () => {
    render(<LiquidityBadge status="excess" daysOfStock={60} />);

    const badge = screen.getByText(/Избыток/);
    expect(badge).toBeInTheDocument();
    expect(badge).toHaveClass("bg-blue-100", "text-blue-800");
    expect(screen.getByText(/60 дн\./)).toBeInTheDocument();
  });

  it("renders infinity symbol when daysOfStock is null", () => {
    render(<LiquidityBadge status="normal" daysOfStock={null} />);

    expect(screen.getByText(/Норма/)).toBeInTheDocument();
    expect(screen.queryByText(/дн\./)).not.toBeInTheDocument();
  });

  it("rounds days of stock to nearest integer", () => {
    render(<LiquidityBadge status="normal" daysOfStock={25.7} />);

    expect(screen.getByText(/26 дн\./)).toBeInTheDocument();
  });

  it("handles zero days of stock", () => {
    render(<LiquidityBadge status="critical" daysOfStock={0} />);

    expect(screen.getByText(/Дефицит/)).toBeInTheDocument();
    expect(screen.getByText(/0 дн\./)).toBeInTheDocument();
  });
});
