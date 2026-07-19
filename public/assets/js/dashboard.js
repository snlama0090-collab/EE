/**
 * dashboard.js — Theme controller & dropdown toggles
 * Vanilla JS, no dependencies.
 */

(function () {
  'use strict';

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
    // Update icon
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

  function toggleDropdown(dropdown, forceClose) {
    if (!dropdown) return;
    if (forceClose) {
      dropdown.classList.remove('show');
      return;
    }
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