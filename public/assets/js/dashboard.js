/**
 * dashboard.js — Sidebar toggle, Theme controller & Dropdown toggles
 * Vanilla JS, no dependencies.
 */

(function () {
  'use strict';

  /* ── Sidebar Collapse Toggle ── */
  const sidebarToggle = document.getElementById('sidebar-toggle');
  const dashboardContainer = document.querySelector('.dashboard-container');

  function getSidebarState() {
    return localStorage.getItem('sidebar-collapsed') === 'true';
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
    localStorage.setItem('sidebar-collapsed', collapsed);
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
    return localStorage.getItem('dashboard-theme') || 'light';
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
    localStorage.setItem('dashboard-theme', theme);
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
