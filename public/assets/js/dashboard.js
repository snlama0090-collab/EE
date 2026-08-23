/**
 * dashboard.js — Sidebar toggle, Theme controller & Dropdown toggles
 * Vanilla JS, no dependencies.
 */

(function () {
  'use strict';

  /* ── Sidebar Collapse Toggle ── */
  const sidebarToggle = document.getElementById('sidebar-toggle');
  const dashboardContainer = document.querySelector('.dashboard-container');
  var role = window.userRole || 'unknown';

  function getSidebarState() {
    return localStorage.getItem('sidebar-collapsed-' + role) === 'true';
  }

  function applySidebarState(collapsed) {
    if (!dashboardContainer) return;
    if (collapsed) {
      dashboardContainer.classList.add('sidebar-collapsed');
      if (sidebarToggle) {
        sidebarToggle.querySelector('i').className = 'fas fa-chevron-right';
      }
    } else {
      dashboardContainer.classList.remove('sidebar-collapsed');
      if (sidebarToggle) {
        sidebarToggle.querySelector('i').className = 'fas fa-chevron-left';
      }
    }
    localStorage.setItem('sidebar-collapsed-' + role, collapsed);
  }

  // Init sidebar state on load
  applySidebarState(getSidebarState());

  if (sidebarToggle) {
    sidebarToggle.addEventListener('click', function () {
      const isCollapsed = dashboardContainer.classList.contains('sidebar-collapsed');
      applySidebarState(!isCollapsed);
    });
  }

  /* ── Theme Controller ── */
  const themeBtn = document.getElementById('theme-toggle');
  const htmlEl = document.documentElement;

  function getStoredTheme() {
    return localStorage.getItem('dashboard-theme-' + role) || 'light';
  }

  function applyTheme(theme) {
    if (theme === 'dark') {
      htmlEl.setAttribute('data-theme', 'dark');
    } else {
      htmlEl.removeAttribute('data-theme');
    }
    if (themeBtn) {
      const icon = themeBtn.querySelector('i');
      if (icon) {
        icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
      }
    }
    localStorage.setItem('dashboard-theme-' + role, theme);
  }

  // Init theme on load
  applyTheme(getStoredTheme());

  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      const current = htmlEl.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
      applyTheme(current === 'dark' ? 'light' : 'dark');
    });
  }

  /* ── Dropdown Controller ── */
  const notifBtn = document.getElementById('notif-btn');
  const notifDropdown = document.getElementById('notif-dropdown');
  const profileBtn = document.getElementById('profile-btn');
  const profileDropdown = document.getElementById('profile-dropdown');

  function toggleDropdown(dropdown) {
    if (!dropdown) return;
    // Close all other dropdowns first
    [notifDropdown, profileDropdown].forEach(function (d) {
      if (d && d !== dropdown) d.classList.remove('show');
    });
    dropdown.classList.toggle('show');
  }

  if (notifBtn && notifDropdown) {
    notifBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleDropdown(notifDropdown);
      if (typeof openNotifBell === 'function') openNotifBell();
    });
  }

  if (profileBtn && profileDropdown) {
    profileBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleDropdown(profileDropdown);
    });
  }

  // Close all dropdowns on outside click
  document.addEventListener('click', function () {
    [notifDropdown, profileDropdown].forEach(function (d) {
      if (d) d.classList.remove('show');
    });
  });

  // Prevent dropdown close when clicking inside dropdown
  [notifDropdown, profileDropdown].forEach(function (d) {
    if (d) {
      d.addEventListener('click', function (e) {
        e.stopPropagation();
      });
    }
  });

})();

/* ── Notification Bell ── */
function notifEsc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}

function renderNotifBell(data) {
    const btn = document.getElementById('notif-btn');
    const body = document.getElementById('notif-items');
    if (!btn || !body) return;
    btn.classList.toggle('has-unread', data.unread_count > 0);
    let badge = btn.querySelector('.notification-count');
    if (data.unread_count > 0) {
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'notification-count';
            btn.appendChild(badge);
        }
        badge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
    } else if (badge) {
        badge.remove();
    }
    body.innerHTML = data.items.length
        ? data.items.map(function (n) {
              return '<div class="dropdown-item"><strong>' + notifEsc(n.action) + '</strong><br>' +
                     '<small>' + notifEsc(n.details) + '</small></div>';
          }).join('')
        : '<div class="dropdown-item muted">No new notifications</div>';
}

async function refreshNotifBell() {
    try {
        const r = await fetch('/EE/api/notifications.php');
        const j = await r.json();
        if (j.status === 'success') renderNotifBell(j.data);
    } catch (e) { /* bell is non-critical; stay silent */ }
}

async function openNotifBell() {
    // Opening the bell = viewing it → mark currently-shown items read immediately,
    // then re-render so the badge hides without any manual action.
    try {
        await fetch('/EE/api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'mark_all_read' })
        });
    } catch (e) { /* ignore */ }
    refreshNotifBell();
}

/* ── Global Toast Notification ── */
function showToast(message, type, duration) {
    type = type || 'info';
    duration = duration || 4000;
    var container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    var toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(function () {
        toast.className = toast.className + ' toast-hiding';
        setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 200);
    }, duration);
}
