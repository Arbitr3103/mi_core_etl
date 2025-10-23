import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { ReplenishmentIndicator } from "../ReplenishmentIndicator";

describe("ReplenishmentIndicator", () => {
  it("shows sufficient stock when need is zero", () => {
    render(<ReplenishmentIndicator need={0} targetStock={100} />);

    expect(screen.getByText(/Достаточно/)).toBeInTheDocument();
    expect(screen.queryByText(/🔥/)).not.toBeInTheDocument();
  });

  it("shows sufficient stock when need is negative", () => {
    render(<ReplenishmentIndicator need={-10} targetStock={100} />);

    expect(screen.getByText(/Достаточно/)).toBeInTheDocument();
  });

  it("shows replenishment need without urgency when below 50%", () => {
    render(<ReplenishmentIndicator need={30} targetStock={100} />);

    expect(screen.getByText(/30 шт\./)).toBeInTheDocument();
    expect(screen.queryByText(/🔥/)).not.toBeInTheDocument();
  });

  it("shows urgent indicator when need exceeds 50% of target", () => {
    render(<ReplenishmentIndicator need={60} targetStock={100} />);

    expect(screen.getByText(/60 шт\./)).toBeInTheDocument();
    expect(screen.getByText(/🔥/)).toBeInTheDocument();
  });

  it("applies urgent styling when need exceeds 50%", () => {
    const { container } = render(
      <ReplenishmentIndicator need={60} targetStock={100} />
    );

    const indicator = container.querySelector(".text-red-600");
    expect(indicator).toBeInTheDocument();
  });

  it("applies normal styling when need is below 50%", () => {
    const { container } = render(
      <ReplenishmentIndicator need={30} targetStock={100} />
    );

    const indicator = container.querySelector(".text-orange-600");
    expect(indicator).toBeInTheDocument();
  });

  it("formats large numbers with locale separator", () => {
    render(<ReplenishmentIndicator need={1500} targetStock={2000} />);

    expect(screen.getByText(/1 500 шт\./)).toBeInTheDocument();
  });

  it("handles edge case when target stock is zero", () => {
    render(<ReplenishmentIndicator need={10} targetStock={0} />);

    expect(screen.getByText(/10 шт\./)).toBeInTheDocument();
    expect(screen.queryByText(/🔥/)).not.toBeInTheDocument();
  });

  it("shows urgent indicator when need equals target stock", () => {
    render(<ReplenishmentIndicator need={100} targetStock={100} />);

    expect(screen.getByText(/100 шт\./)).toBeInTheDocument();
    expect(screen.getByText(/🔥/)).toBeInTheDocument();
  });
});
