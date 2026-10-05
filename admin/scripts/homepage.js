// =========================================================
// Admin Homepage — Dynamic Data Loader
// Fetches /admin/backend/homepage/fetch_homepage_data.php
// and updates all placeholders on the page.
// =========================================================

const HOMEPAGE_ENDPOINT = "../backend/homepage/fetch_homepage_data.php";

document.addEventListener("DOMContentLoaded", function () {
  console.log("KaraKata Pro Admin Homepage loaded");

  fetchHomepageData();
  initStatsAnimation();
  initSmoothScroll();
  initScrollProgress();
});

// =========================================================
// MAIN FETCH
// =========================================================
async function fetchHomepageData() {
  try {
    const response = await fetch(HOMEPAGE_ENDPOINT, {
      method: "GET",
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const payload = await response.json();

    if (!payload.success) {
      console.error("Homepage request failed:", payload.message);
      return;
    }

    // Handle both possible response shapes gracefully
    // (utils.php may return data at payload.data OR payload.message.data)
    let data = payload.data;
    if (!data || typeof data === "string") {
      // Fallback: some utils.php versions put the object under payload.message
      if (payload.message && typeof payload.message === "object") {
        data = payload.message;
      }
    }

    if (!data || typeof data !== "object" || !data.stats) {
      console.error("Homepage response has no usable data:", payload);
      return;
    }

    // Now safe to use
    renderStats(data.stats);
    renderTrustedBy(data.trusted_by);
    renderDashboardPreview(data.dashboard_preview);
    renderFeaturedProperty(data.featured_property);
    renderTestimonials(data.testimonials);
    initTestimonialSlider();
  } catch (error) {
    console.error("Error fetching homepage data:", error);
    // Page keeps static placeholders — no crash.
  }
}

// =========================================================
// RENDERERS
// =========================================================

function renderStats(stats) {
  if (!stats) return;

  // Hero badge
  const badge = document.getElementById("heroBadgeText");
  if (badge && stats.trusted_count_formatted) {
    badge.innerHTML = `<i class="fas fa-star"></i> Trusted by ${escapeHtml(stats.trusted_count_formatted)} Properties`;
  }

  // Properties managed
  const elProperties = document.getElementById("statPropertiesManaged");
  if (elProperties && stats.properties_managed_formatted) {
    elProperties.textContent = stats.properties_managed_formatted;
  }

  // Rent processed — show "Growing" if zero
  const elRent = document.getElementById("statRentProcessed");
  if (elRent) {
    if (stats.rent_processed > 0) {
      elRent.textContent = stats.rent_processed_formatted;
    } else {
      elRent.textContent = "Growing";
    }
  }

  // Satisfaction
  const elSat = document.getElementById("statSatisfaction");
  if (elSat && stats.satisfaction_rate_formatted) {
    elSat.innerHTML = "";
    elSat.textContent = stats.satisfaction_rate_formatted;
  }
}

function renderTrustedBy(names) {
  if (!Array.isArray(names) || !names.length) return;

  const container = document.getElementById("trustedByLogos");
  if (!container) return;

  container.innerHTML = names
    .map((name) => `<span>${escapeHtml(name)}</span>`)
    .join("");
}

function renderDashboardPreview(preview) {
  if (!preview) return;

  // Revenue this month
  const elRevenue = document.getElementById("previewRevenue");
  if (elRevenue && preview.revenue_this_month_formatted) {
    elRevenue.textContent = preview.revenue_this_month_formatted;
  }

  // Active properties
  const elActive = document.getElementById("previewActiveProperties");
  if (elActive && typeof preview.active_properties === "number") {
    elActive.textContent = `${preview.active_properties} Properties`;
  }

  // Recent tenants — keep the <h4> header, replace only .tenant-item rows
  const listContainer = document.getElementById("previewTenantList");
  if (
    listContainer &&
    Array.isArray(preview.recent_tenants) &&
    preview.recent_tenants.length
  ) {
    listContainer.querySelectorAll(".tenant-item").forEach((el) => el.remove());

    preview.recent_tenants.forEach((t) => {
      const div = document.createElement("div");
      div.className = "tenant-item";
      div.innerHTML = `
                <span>${escapeHtml(t.name)}</span>
                <span class="status ${escapeHtml(t.status)}">${escapeHtml(capitalize(t.status))}</span>
            `;
      listContainer.appendChild(div);
    });
  }
}

function renderFeaturedProperty(property) {
  if (!property) return;

  // Title
  const titleEl = document.getElementById("featuredPropertyTitle");
  if (titleEl) titleEl.textContent = property.title || titleEl.textContent;

  // Location
  const locEl = document.getElementById("featuredPropertyLocation");
  if (locEl) {
    locEl.innerHTML = `<i class="fas fa-map-marker-alt"></i> ${escapeHtml(property.location)}`;
  }

  // Specs — apartments / vacant / occupied
  const specsEl = document.getElementById("featuredPropertySpecs");
  if (specsEl) {
    specsEl.innerHTML = `
            <span><i class="fas fa-home"></i> ${property.apartments} Apartments</span>
            <span><i class="fas fa-door-open"></i> ${property.vacant} Vacant</span>
            <span><i class="fas fa-users"></i> ${property.occupied} Occupied</span>
        `;
  }

  // Price
  const priceEl = document.getElementById("featuredPropertyPrice");
  if (priceEl) priceEl.textContent = property.price_formatted;

  // Status badge
  const statusEl = document.getElementById("featuredPropertyStatus");
  if (statusEl) {
    statusEl.textContent = capitalize(property.status);
    statusEl.className = `property-status ${property.status}`;
  }

  // Photo
  const imageBox = document.getElementById("featuredPropertyImage");
  if (imageBox) {
    if (property.photo_url) {
      imageBox.innerHTML = `
                <img src="${escapeHtml(property.photo_url)}"
                     alt="${escapeHtml(property.title)}"
                     style="width:100%;height:100%;object-fit:cover;"
                     onerror="this.parentNode.innerHTML='<div class=\\'image-placeholder\\'></div>'">
            `;
    }
    // else leave the placeholder as-is
  }
}

function renderTestimonials(testimonials) {
  if (!Array.isArray(testimonials) || !testimonials.length) return;

  const slider = document.getElementById("testimonialSlider");
  if (!slider) return;

  slider.innerHTML = testimonials
    .map(
      (t) => `
        <div class="testimonial-card">
            <div class="testimonial-rating">${renderStars(t.rating)}</div>
            <p class="testimonial-text">"${escapeHtml(t.text)}"</p>
            <div class="testimonial-author">
                <div class="author-avatar">
                    <i class="fas fa-user-circle"></i>
                </div>
                <div class="author-info">
                    <h4>${escapeHtml(t.name)}</h4>
                    <p>${escapeHtml(t.role)}</p>
                </div>
            </div>
        </div>
    `,
    )
    .join("");
}

function renderStars(rating) {
  let html = "";
  for (let i = 1; i <= 5; i++) {
    if (rating >= i) html += '<i class="fas fa-star"></i>';
    else if (rating >= i - 0.5) html += '<i class="fas fa-star-half-alt"></i>';
    else html += '<i class="far fa-star"></i>';
  }
  return html;
}

// =========================================================
// ANIMATIONS & UI
// =========================================================

function initStatsAnimation() {
  const stats = document.querySelectorAll(".hero-stats .stat h3");
  if (!stats.length) return;

  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          animateStats(stats);
          observer.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.3 },
  );

  const statsSection = document.querySelector(".hero-stats");
  if (statsSection) observer.observe(statsSection);
}

function animateStats(stats) {
  stats.forEach((stat) => {
    // Skip if the value isn't purely numeric (e.g. "Growing")
    const raw = stat.textContent.trim();
    if (!/^[\d,.]+/.test(raw)) return;

    const target = parseInt(raw.replace(/[^0-9]/g, ""), 10);
    if (isNaN(target) || target === 0) return;

    const suffix = raw.replace(/[0-9,]/g, "");
    let current = 0;
    const increment = target / 60;

    const timer = setInterval(() => {
      current += increment;
      if (current >= target) {
        current = target;
        clearInterval(timer);
      }
      stat.textContent = Math.floor(current).toLocaleString() + suffix;
    }, 20);
  });
}

function initSmoothScroll() {
  document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
    anchor.addEventListener("click", function (e) {
      const href = this.getAttribute("href");
      if (href === "#") return;
      e.preventDefault();

      const target = document.getElementById(href.substring(1));
      if (target) {
        window.scrollTo({
          top: target.offsetTop - 80,
          behavior: "smooth",
        });
      }
    });
  });
}

function initTestimonialSlider() {
  const slider = document.getElementById("testimonialSlider");
  if (!slider) return;

  const cards = slider.querySelectorAll(".testimonial-card");
  if (cards.length <= 1) return;

  let currentIndex = 0;

  // Avoid stacking dots on re-init
  const existingDots = slider.parentNode.querySelector(".testimonial-dots");
  if (existingDots) existingDots.remove();

  const dotsContainer = document.createElement("div");
  dotsContainer.className = "testimonial-dots";

  for (let i = 0; i < cards.length; i++) {
    const dot = document.createElement("button");
    dot.className = "testimonial-dot" + (i === 0 ? " active" : "");
    dot.addEventListener("click", () => show(i));
    dotsContainer.appendChild(dot);
  }
  slider.parentNode.appendChild(dotsContainer);

  function show(index) {
    cards.forEach((c, i) => (c.style.display = i === index ? "block" : "none"));
    dotsContainer
      .querySelectorAll(".testimonial-dot")
      .forEach((d, i) => d.classList.toggle("active", i === index));
    currentIndex = index;
  }

  show(0);

  // Only start auto-rotate once
  if (!slider.dataset.autoRotate) {
    slider.dataset.autoRotate = "true";
    setInterval(() => show((currentIndex + 1) % cards.length), 5000);
  }
}

function initScrollProgress() {
  if (document.querySelector(".scroll-progress")) return;

  const bar = document.createElement("div");
  bar.className = "scroll-progress";
  bar.style.cssText = `
        position: fixed; top: 0; left: 0;
        width: 0%; height: 3px;
        background: linear-gradient(90deg, #667eea, #764ba2);
        z-index: 1001; transition: width 0.1s ease;
    `;
  document.body.appendChild(bar);

  window.addEventListener("scroll", () => {
    const scrolled =
      document.body.scrollTop || document.documentElement.scrollTop;
    const height =
      document.documentElement.scrollHeight -
      document.documentElement.clientHeight;
    bar.style.width = (scrolled / height) * 100 + "%";
  });
}

// =========================================================
// UTILITIES
// =========================================================

function escapeHtml(text) {
  if (text === null || text === undefined) return "";
  const div = document.createElement("div");
  div.textContent = String(text);
  return div.innerHTML;
}

function capitalize(str) {
  if (!str) return "";
  return str.charAt(0).toUpperCase() + str.slice(1);
}
