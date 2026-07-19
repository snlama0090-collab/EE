/**
 * dashboard.js — Sidebar toggle, Theme controller & Dropdown toggles
 * Vanilla JS, no dependencies.
 */

(function () {
  'use strict';

  function init() {
    /* ── Sidebar Collapse Toggle ── */
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const dashboardContainer = document.querySelector('.dashboard-container');

    function getSidebarState() {
      try { return localStorage.getItem('sidebar-collapsed') === 'true'; }
      catch(e) { return false; }
    }

    function applySidebarState(collapsed) {
      if (!dashboardContainer) return;
      if (collapsed) {
        dashboardContainer.classList.add('sidebar-collapsed');
        if (sidebarToggle) {
          const ic = sidebarToggle.querySelector('i');
          if (ic) ic.className = 'fas fa-chevron-right';
        }
      } else {
        dashboardContainer.classList.remove('sidebar-collapsed');
        if (sidebarToggle) {
          const ic = sidebarToggle.querySelector('i');
          if (ic) ic.className = 'fas fa-chevron-left';
        }
      }
      try { localStorage.setItem('sidebar-collapsed', collapsed); }
      catch(e) {}
    }

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
      try { return localStorage.getItem('dashboard-theme') || 'light'; }
      catch(e) { return 'light'; }
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
      try { localStorage.setItem('dashboard-theme', theme); }
      catch(e) {}
    }

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

    document.addEventListener('click', function () {
      [notifDropdown, profileDropdown].forEach(function (d) {
        if (d) d.classList.remove('show');
      });
    });

    [notifDropdown, profileDropdown].forEach(function (d) {
      if (d) {
        d.addEventListener('click', function (e) {
          e.stopPropagation();
        });
      }
    });
  }

  // Run on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();