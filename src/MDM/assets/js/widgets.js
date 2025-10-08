/**
 * MDM Dashboard Widgets JavaScript
 * Handles widget-specific functionality and interactions
 */

class MDMWidgets {
  constructor() {
    this.init();
  }

  /**
   * Initialize all widgets
   */
  init() {
    this.initProgressWidgets();
    this.initStatusWidgets();
    this.initActivityWidgets();
    this.initQuickActions();
  }

  /**
   * Initialize progress widgets
   */
  initProgressWidgets() {
    const progressWidgets = document.querySelectorAll(".progress-widget");

    progressWidgets.forEach((widget) => {
      this.animateProgressBars(widget);
    });
  }

  /**
   * Animate progress bars
   */
  animateProgressBars(widget) {
    const progressBars = widget.querySelectorAll(".progress-fill");

    progressBars.forEach((bar, index) => {
      const targetWidth = bar.dataset.width || "0%";

      // Animate with delay
      setTimeout(() => {
        bar.style.width = targetWidth;
      }, index * 200);
    });
  }

  /**
   * Initialize status widgets
   */
  initStatusWidgets() {
    const statusWidgets = document.querySelectorAll(".status-widget");

    statusWidgets.forEach((widget) => {
      this.setupStatusUpdates(widget);
    });
  }

  /**
   * Setup status updates
   */
  setupStatusUpdates(widget) {
    const statusItems = widget.querySelectorAll(".status-item");

    statusItems.forEach((item) => {
      const indicator = item.querySelector(".status-dot");
      const text = item.querySelector(".status-text");

      if (indicator && text) {
        // Add hover effects
        item.addEventListener("mouseenter", () => {
          item.style.backgroundColor = "#f8f9fa";
        });

        item.addEventListener("mouseleave", () => {
          item.style.backgroundColor = "";
        });
      }
    });
  }

  /**
   * Initialize activity widgets
   */
  initActivityWidgets() {
    const activityWidgets = document.querySelectorAll(".activity-widget");

    activityWidgets.forEach((widget) => {
      this.setupActivityInteractions(widget);
    });
  }

  /**
   * Setup activity interactions
   */
  setupActivityInteractions(widget) {
    const activityItems = widget.querySelectorAll(".activity-item");

    activityItems.forEach((item) => {
      // Add click handler for expandable details
      item.addEventListener("click", () => {
        this.toggleActivityDetails(item);
      });

      // Add fade-in animation
      this.addFadeInAnimation(item);
    });
  }

  /**
   * Toggle activity details
   */
  toggleActivityDetails(item) {
    const details = item.querySelector(".activity-details");

    if (details) {
      const isExpanded = details.style.display === "block";
      details.style.display = isExpanded ? "none" : "block";

      // Update icon if exists
      const icon = item.querySelector(".expand-icon");
      if (icon) {
        icon.classList.toggle("fa-chevron-down", !isExpanded);
        icon.classList.toggle("fa-chevron-up", isExpanded);
      }
    }
  }

  /**
   * Initialize quick actions
   */
  initQuickActions() {
    const quickActions = document.querySelectorAll(".action-item");

    quickActions.forEach((action) => {
      this.setupActionInteractions(action);
    });
  }

  /**
   * Setup action interactions
   */
  setupActionInteractions(action) {
    // Add ripple effect on click
    action.addEventListener("click", (e) => {
      this.createRippleEffect(e, action);
    });

    // Add keyboard navigation
    action.addEventListener("keydown", (e) => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        action.click();
      }
    });
  }

  /**
   * Create ripple effect
   */
  createRippleEffect(event, element) {
    const ripple = document.createElement("span");
    const rect = element.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    const x = event.clientX - rect.left - size / 2;
    const y = event.clientY - rect.top - size / 2;

    ripple.style.cssText = `
            position: absolute;
            width: ${size}px;
            height: ${size}px;
            left: ${x}px;
            top: ${y}px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        `;

    element.style.position = "relative";
    element.style.overflow = "hidden";
    element.appendChild(ripple);

    // Remove ripple after animation
    setTimeout(() => {
      ripple.remove();
    }, 600);
  }

  /**
   * Add fade-in animation
   */
  addFadeInAnimation(element) {
    element.style.opacity = "0";
    element.style.transform = "translateY(20px)";

    // Use Intersection Observer for performance
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.style.transition =
            "opacity 0.5s ease, transform 0.5s ease";
          entry.target.style.opacity = "1";
          entry.target.style.transform = "translateY(0)";
          observer.unobserve(entry.target);
        }
      });
    });

    observer.observe(element);
  }

  /**
   * Update widget data
   */
  updateWidget(widgetId, data) {
    const widget = document.getElementById(widgetId);
    if (!widget) return;

    const widgetType = widget.dataset.widgetType;

    switch (widgetType) {
      case "metric":
        this.updateMetricWidget(widget, data);
        break;
      case "progress":
        this.updateProgressWidget(widget, data);
        break;
      case "status":
        this.updateStatusWidget(widget, data);
        break;
      case "activity":
        this.updateActivityWidget(widget, data);
        break;
    }
  }

  /**
   * Update metric widget
   */
  updateMetricWidget(widget, data) {
    const valueElement = widget.querySelector(".metric-value");
    const changeElement = widget.querySelector(".metric-change");

    if (valueElement && data.value !== undefined) {
      this.animateNumber(valueElement, data.value);
    }

    if (changeElement && data.change !== undefined) {
      changeElement.textContent = `${data.change > 0 ? "+" : ""}${
        data.change
      }%`;
      changeElement.className = `metric-change ${
        data.change >= 0 ? "text-success" : "text-danger"
      }`;
    }
  }

  /**
   * Update progress widget
   */
  updateProgressWidget(widget, data) {
    const progressItems = widget.querySelectorAll(".progress-item");

    progressItems.forEach((item, index) => {
      if (data[index]) {
        const progressFill = item.querySelector(".progress-fill");
        const progressValue = item.querySelector(".progress-value");

        if (progressFill) {
          progressFill.style.width = data[index].percentage + "%";
        }

        if (progressValue) {
          progressValue.textContent = data[index].percentage + "%";
        }
      }
    });
  }

  /**
   * Update status widget
   */
  updateStatusWidget(widget, data) {
    const statusItems = widget.querySelectorAll(".status-item");

    statusItems.forEach((item, index) => {
      if (data[index]) {
        const statusDot = item.querySelector(".status-dot");
        const statusText = item.querySelector(".status-text");

        if (statusDot) {
          statusDot.className = `status-dot ${data[index].status}`;
        }

        if (statusText) {
          statusText.textContent = data[index].text;
        }
      }
    });
  }

  /**
   * Update activity widget
   */
  updateActivityWidget(widget, data) {
    const activityContainer = widget.querySelector(".activity-container");
    if (!activityContainer) return;

    // Clear existing items
    activityContainer.innerHTML = "";

    // Add new items
    data.forEach((item) => {
      const activityItem = this.createActivityItem(item);
      activityContainer.appendChild(activityItem);
    });

    // Re-initialize interactions
    this.setupActivityInteractions(widget);
  }

  /**
   * Create activity item
   */
  createActivityItem(data) {
    const item = document.createElement("div");
    item.className = "activity-item";

    item.innerHTML = `
            <div class="activity-icon bg-${data.type || "primary"}">
                <i class="fas fa-${data.icon || "info"}"></i>
            </div>
            <div class="activity-content">
                <div class="activity-title">${data.title}</div>
                <div class="activity-description">${data.description}</div>
                <div class="activity-time">${data.time}</div>
            </div>
        `;

    return item;
  }

  /**
   * Animate number changes
   */
  animateNumber(element, targetValue) {
    const startValue = parseInt(element.textContent.replace(/[^\d]/g, "")) || 0;
    const duration = 1000;
    const startTime = performance.now();

    const animate = (currentTime) => {
      const elapsed = currentTime - startTime;
      const progress = Math.min(elapsed / duration, 1);

      // Easing function
      const easeOutQuart = 1 - Math.pow(1 - progress, 4);
      const currentValue = Math.round(
        startValue + (targetValue - startValue) * easeOutQuart
      );

      element.textContent = new Intl.NumberFormat("ru-RU").format(currentValue);

      if (progress < 1) {
        requestAnimationFrame(animate);
      }
    };

    requestAnimationFrame(animate);
  }

  /**
   * Show loading state for widget
   */
  showWidgetLoading(widgetId) {
    const widget = document.getElementById(widgetId);
    if (widget) {
      widget.classList.add("loading");
    }
  }

  /**
   * Hide loading state for widget
   */
  hideWidgetLoading(widgetId) {
    const widget = document.getElementById(widgetId);
    if (widget) {
      widget.classList.remove("loading");
    }
  }
}

// Add CSS for ripple animation
const style = document.createElement("style");
style.textContent = `
    @keyframes ripple {
        to {
            transform: scale(4);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// Initialize widgets when DOM is loaded
document.addEventListener("DOMContentLoaded", function () {
  window.mdmWidgets = new MDMWidgets();
});
