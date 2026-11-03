class LockHistoryManager {
  constructor() {
    this.page = 1;
    this.limit = 20;
    this.filters = {
      user_type: "all",
      action_type: "all",
      status: "all",
      search: "",
      date_from: "",
      date_to: "",
    };
    this.recordsById = new Map();
    this.hydrateFromUrl();
    this.init();
  }

  hydrateFromUrl() {
    const p = new URLSearchParams(location.search);
    this.page = parseInt(p.get("page")) || 1;
    ["user_type", "action_type", "status", "search", "date_from", "date_to"].forEach(
      (k) => {
        if (p.get(k)) this.filters[k] = p.get(k);
      },
    );
  }

  syncUrl() {
    const p = new URLSearchParams();
    if (this.page > 1) p.set("page", this.page);
    Object.entries(this.filters).forEach(([k, v]) => {
      if (v && v !== "all") p.set(k, v);
    });
    const qs = p.toString();
    history.replaceState(null, "", qs ? `?${qs}` : location.pathname);
  }

  init() {
    this.populateFilterInputs();
    this.bindEvents();
    this.bindDetailModal();
    this.setupToasts();
    this.load();
  }

  populateFilterInputs() {
    const map = {
      historyUserTypeFilter: "user_type",
      historyActionTypeFilter: "action_type",
      historyStatusFilter: "status",
      historySearchInput: "search",
      historyDateFrom: "date_from",
      historyDateTo: "date_to",
    };
    Object.entries(map).forEach(([id, key]) => {
      const el = document.getElementById(id);
      if (!el) return;
      if (el.tagName === "SELECT") el.value = this.filters[key] || "all";
      else el.value = this.filters[key] || "";
    });
  }

  readFilterInputs() {
    return {
      user_type: document.getElementById("historyUserTypeFilter")?.value || "all",
      action_type:
        document.getElementById("historyActionTypeFilter")?.value || "all",
      status: document.getElementById("historyStatusFilter")?.value || "all",
      search:
        document.getElementById("historySearchInput")?.value.trim() || "",
      date_from: document.getElementById("historyDateFrom")?.value || "",
      date_to: document.getElementById("historyDateTo")?.value || "",
    };
  }

  bindEvents() {
    document
      .getElementById("applyHistoryFilters")
      ?.addEventListener("click", () => {
        this.filters = this.readFilterInputs();
        this.page = 1;
        this.load();
      });

    document
      .getElementById("resetHistoryFilters")
      ?.addEventListener("click", () => {
        this.filters = {
          user_type: "all",
          action_type: "all",
          status: "all",
          search: "",
          date_from: "",
          date_to: "",
        };
        this.page = 1;
        this.populateFilterInputs();
        this.load();
      });

    document.getElementById("filterToggle")?.addEventListener("click", () => {
      document.getElementById("filterBody")?.classList.toggle("collapsed");
    });

    document
      .getElementById("historySearchInput")
      ?.addEventListener("keypress", (e) => {
        if (e.key === "Enter") {
          e.preventDefault();
          document.getElementById("applyHistoryFilters")?.click();
        }
      });
  }

  bindDetailModal() {
    const modal = document.getElementById("recordDetailModal");
    if (!modal) return;

    modal.querySelectorAll(".close-modal").forEach((btn) => {
      btn.addEventListener("click", () => this.closeDetailModal());
    });

    modal.addEventListener("click", (e) => {
      if (e.target === modal) this.closeDetailModal();
    });

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && modal.classList.contains("show")) {
        this.closeDetailModal();
      }
    });
  }

  openDetailModal(record) {
    const set = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = val;
    };

    set("detailName", record.name || "—");
    set("detailEmail", record.email || "—");
    set("detailUserId", record.user_id || "—");
    set("detailRole", record.role || "—");
    set("detailLockReason", record.lock_reason || "—");
    set("detailLockedAt", this.fmt(record.locked_at));
    set("detailUnlockedAt", record.unlocked_at ? this.fmt(record.unlocked_at) : "—");
    set("detailUnlockedBy", record.unlocked_by || "—");
    set("detailUnlockMethod", record.unlock_method || "—");

    const icons = {
      admin: "fa-user-shield",
      tenant: "fa-user",
      agent: "fa-user-tie",
      client: "fa-briefcase",
    };
    const avatarIcon = document.getElementById("detailAvatarIcon");
    if (avatarIcon) {
      avatarIcon.className = `fas ${icons[record.user_type] || "fa-user"}`;
    }

    const statusBadge = document.getElementById("detailStatusBadge");
    if (statusBadge) {
      statusBadge.className = `status-badge ${
        record.status === "locked" ? "status-locked" : "status-unlocked"
      }`;
      statusBadge.textContent = (record.status || "").toUpperCase();
    }

    const ltBadge = document.getElementById("detailLockTypeBadge");
    if (ltBadge) {
      ltBadge.className = `lock-type-badge ${
        record.lock_type === "login_attempts"
          ? "lock-type-login"
          : "lock-type-manual"
      }`;
      ltBadge.textContent =
        record.lock_type === "login_attempts" ? "Failed Logins" : "Manual Lock";
    }

    const modal = document.getElementById("recordDetailModal");
    if (modal) {
      modal.classList.add("show");
      document.body.style.overflow = "hidden";
    }
  }

  closeDetailModal() {
    const modal = document.getElementById("recordDetailModal");
    if (!modal) return;
    modal.classList.remove("show");
    document.body.style.overflow = "";
  }

  async load() {
    this.syncUrl();

    const loadingRow = document.getElementById("historyLoadingRow");
    const emptyState = document.getElementById("historyEmptyState");
    const tbody = document.getElementById("lockHistoryBody");

    if (loadingRow) loadingRow.style.display = "";
    if (emptyState) emptyState.style.display = "none";

    if (tbody) {
      Array.from(tbody.children).forEach((r) => {
        if (r.id !== "historyLoadingRow") r.remove();
      });
    }

    const params = new URLSearchParams({
      page: this.page,
      limit: this.limit,
      user_type: this.filters.user_type || "all",
      action_type: this.filters.action_type || "all",
      status: this.filters.status || "all",
      search: this.filters.search || "",
    });

    if (this.filters.date_from) params.append("date_from", this.filters.date_from);
    if (this.filters.date_to) params.append("date_to", this.filters.date_to);

    try {
      const res = await fetch(`../backend/utilities/lock_history.php?${params}`);
      const data = await res.json();

      if (!data.success) throw new Error(data.message || "Load failed");

      this.render(data.history);
      this.renderStats(data.stats);
      this.renderPagination(data.pagination);

      if (loadingRow) loadingRow.style.display = "none";
      if (emptyState)
        emptyState.style.display =
          data.history.length === 0 ? "block" : "none";
    } catch (err) {
      console.error("Error loading lock history:", err);
      if (loadingRow) loadingRow.style.display = "none";
      if (emptyState) emptyState.style.display = "block";
      this.showError("Failed to load lock history");
    }
  }

  render(history) {
    const tbody = document.getElementById("lockHistoryBody");
    if (!tbody) return;

    const icons = {
      admin: "fa-user-shield",
      tenant: "fa-user",
      agent: "fa-user-tie",
      client: "fa-briefcase",
    };

    history.forEach((r, idx) => {
      const tr = document.createElement("tr");
      tr.className = "history-row";
      tr.setAttribute("role", "button");
      tr.setAttribute("tabindex", "0");
      tr.dataset.index = idx;

      const statusCls =
        r.status === "locked" ? "status-locked" : "status-unlocked";
      const statusTxt = (r.status || "").toUpperCase();

      const ltCls =
        r.lock_type === "login_attempts"
          ? "lock-type-login"
          : "lock-type-manual";
      const ltTxt =
        r.lock_type === "login_attempts" ? "Failed Logins" : "Manual Lock";

      tr.innerHTML = `
                <td data-label="User">
                    <i class="fas ${icons[r.user_type] || "fa-user"}" style="color:#667eea;margin-right:6px;"></i>
                    <strong>${this.esc(r.name)}</strong>
                </td>
                <td data-label="Email">${this.esc(r.email)}</td>
                <td data-label="Role">${this.esc(r.role)}</td>
                <td data-label="Lock Type"><span class="lock-type-badge ${ltCls}">${ltTxt}</span></td>
                <td data-label="Status"><span class="status-badge ${statusCls}">${statusTxt}</span></td>
                <td class="row-chevron" aria-hidden="true"><i class="fas fa-chevron-right"></i></td>
            `;

      // Stash the full record on the row
      tr._record = r;

      const open = () => this.openDetailModal(tr._record);
      tr.addEventListener("click", open);
      tr.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          open();
        }
      });

      tbody.appendChild(tr);
    });
  }

  renderStats(stats) {
    const map = {
      historyTotalLocks: stats.total_locks,
      historyLoginAttempts: stats.login_attempt_locks,
      historyManualLocks: stats.manual_locks,
      historyUnlocked: stats.unlocked,
      historyStillLocked: stats.still_locked,
    };
    Object.entries(map).forEach(([id, v]) => {
      const el = document.getElementById(id);
      if (el) el.textContent = v || 0;
    });
  }

  renderPagination(p) {
    const el = document.getElementById("lockHistoryPagination");
    if (!el) return;
    if (!p || p.total_pages <= 1) {
      el.innerHTML = "";
      return;
    }

    let html = "";
    if (this.page > 1) {
      html += `<button class="page-btn" data-page="${this.page - 1}"><i class="fas fa-chevron-left"></i></button>`;
    }

    const maxVisible = 5;
    let start = Math.max(1, this.page - Math.floor(maxVisible / 2));
    let end = Math.min(p.total_pages, start + maxVisible - 1);
    if (end - start + 1 < maxVisible) start = Math.max(1, end - maxVisible + 1);

    for (let i = start; i <= end; i++) {
      html += `<button class="page-btn ${i === this.page ? "active" : ""}" data-page="${i}">${i}</button>`;
    }

    if (this.page < p.total_pages) {
      html += `<button class="page-btn" data-page="${this.page + 1}"><i class="fas fa-chevron-right"></i></button>`;
    }

    html += `<div class="page-info">Page ${this.page} of ${p.total_pages} (${p.total} records)</div>`;
    el.innerHTML = html;

    el.querySelectorAll(".page-btn[data-page]").forEach((btn) => {
      btn.addEventListener("click", () => {
        this.page = parseInt(btn.dataset.page);
        this.load();
        window.scrollTo({ top: 0, behavior: "smooth" });
      });
    });
  }

  setupToasts() {
    if (typeof toastr !== "undefined") {
      toastr.options = {
        closeButton: true,
        newestOnTop: true,
        progressBar: true,
        positionClass: "toast-top-right",
        timeOut: "5000",
      };
    }
  }

  showError(message) {
    if (typeof toastr !== "undefined") toastr.error(message, "Error");
    else console.error("Error:", message);
  }

  esc(t) {
    if (!t) return "";
    const d = document.createElement("div");
    d.textContent = t;
    return d.innerHTML;
  }

  fmt(t) {
    if (!t) return "N/A";
    try {
      const d = new Date(t);
      return (
        d.toLocaleDateString() +
        " " +
        d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })
      );
    } catch {
      return t;
    }
  }
}

document.addEventListener("DOMContentLoaded", () => {
  window.lockHistoryManager = new LockHistoryManager();
});