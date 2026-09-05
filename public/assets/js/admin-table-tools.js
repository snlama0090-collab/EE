/**
 * Admin table tools: Columns show/hide + CSV export for listing sections.
 * Loaded only by the admin shell (admin.php). Sections opt in via
 * <div class="listing-toolbar" data-table-tools="<section>"> and buttons
 * [data-columns] / [data-export]. Re-initialized after every loadSection()
 * injection because innerHTML-injected markup carries no live handlers.
 */
(function () {
    'use strict';

    // Active filter pill label -> export query params, per section.
    // Mirrors the client-side pill filtering in admin_sections/*.php.
    var PILL_PARAMS = {
        users:     { 'Drivers': 'role=driver', 'Owners': 'role=owner', 'Active': 'status=active', 'Inactive': 'status=inactive' },
        customers: { 'Active': 'status=active', 'Inactive': 'status=inactive' },
        orders:    { 'Active': 'status=charging', 'Completed': 'status=completed', 'Cancelled': 'status=cancelled' },
        stations:  { 'Approved': 'approval=approved', 'Pending': 'approval=pending', 'Rejected': 'approval=rejected' }
    };

    function storageKey(section) { return 'adminCols_' + section; }

    // --- Export -------------------------------------------------------------
    function exportCsv(section) {
        var area = document.getElementById('content-area');
        if (!area) return;
        var params = ['type=' + encodeURIComponent(section)];
        var pill = area.querySelector('.filter-pill.active');
        var kv = pill ? (PILL_PARAMS[section] || {})[pill.textContent.trim()] : null;
        if (kv) params.push(kv);
        var search = area.querySelector('.toolbar-search input');
        var q = search ? search.value.trim() : '';
        if (q) params.push('q=' + encodeURIComponent(q));
        window.location.href = '/EE/api/admin-export.php?' + params.join('&');
    }

    // --- Columns ------------------------------------------------------------
    function getHidden(section, colCount) {
        var hidden;
        try { hidden = JSON.parse(localStorage.getItem(storageKey(section)) || '[]'); } catch (e) { hidden = []; }
        if (!Array.isArray(hidden)) hidden = [];
        // Defensive: intersect with real indexes, and never allow "all hidden"
        hidden = hidden.filter(function (i) { return i >= 0 && i < colCount; });
        if (hidden.length >= colCount) hidden = [];
        return hidden;
    }

    function saveHidden(section, hidden) {
        try { localStorage.setItem(storageKey(section), JSON.stringify(hidden)); } catch (e) { /* storage unavailable - toggle still works this session */ }
    }

    function applyHidden(table, hidden) {
        var headCells = table.tHead ? table.tHead.rows[0].cells : [];
        for (var i = 0; i < headCells.length; i++) {
            headCells[i].style.display = hidden.indexOf(i) !== -1 ? 'none' : '';
        }
        for (var r = 0; r < table.tBodies.length; r++) {
            var rows = table.tBodies[r].rows;
            for (var j = 0; j < rows.length; j++) {
                for (var c = 0; c < rows[j].cells.length; c++) {
                    rows[j].cells[c].style.display = hidden.indexOf(c) !== -1 ? 'none' : '';
                }
            }
        }
    }

    function syncDisabledBoxes(menu, hidden, colCount) {
        var lastStanding = colCount - hidden.length === 1;
        menu.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
            var visible = hidden.indexOf(parseInt(box.dataset.colIndex, 10)) === -1;
            box.disabled = lastStanding && visible;
        });
    }

    function buildColumnsMenu(toolbar, section, table) {
        var host = toolbar.querySelector('.toolbar-actions') || toolbar;
        var old = host.querySelector('.columns-menu');
        if (old) old.remove();

        var headCells = table.tHead ? table.tHead.rows[0].cells : [];
        if (!headCells.length) return;
        var hidden = getHidden(section, headCells.length);

        var menu = document.createElement('div');
        menu.className = 'columns-menu';

        for (var i = 0; i < headCells.length; i++) {
            var label = headCells[i].textContent.trim() || ('Column ' + (i + 1));
            var row = document.createElement('label');
            var box = document.createElement('input');
            box.type = 'checkbox';
            box.checked = hidden.indexOf(i) === -1;
            box.dataset.colIndex = i;
            row.appendChild(box);
            row.appendChild(document.createTextNode(label));
            menu.appendChild(row);
        }

        menu.addEventListener('change', function (e) {
            var box = e.target;
            if (!box || box.type !== 'checkbox') return;
            var idx = parseInt(box.dataset.colIndex, 10);
            var current = getHidden(section, headCells.length);
            if (!box.checked && current.indexOf(idx) === -1) current.push(idx);
            if (box.checked) current = current.filter(function (i) { return i !== idx; });
            // Always keep at least one column visible
            if (headCells.length - current.length < 1) {
                box.checked = true;
                return;
            }
            saveHidden(section, current);
            applyHidden(table, current);
            syncDisabledBoxes(menu, current, headCells.length);
        });

        host.appendChild(menu);

        var toggle = toolbar.querySelector('[data-columns]');
        if (toggle) {
            toggle.addEventListener('click', function () {
                menu.classList.toggle('show');
            });
        }
        syncDisabledBoxes(menu, hidden, headCells.length);
    }

    // --- Entry point (called by loadSection hook + on initial page load) ----
    function initAdminTableTools() {
        var area = document.getElementById('content-area');
        if (!area) return;
        var toolbar = area.querySelector('.listing-toolbar[data-table-tools]');
        if (!toolbar) return; // section has no table tools
        var section = toolbar.getAttribute('data-table-tools');
        var table = area.querySelector('.listing-table table');
        if (!table || !table.tHead) return;

        buildColumnsMenu(toolbar, section, table);
        applyHidden(table, getHidden(section, table.tHead.rows[0].cells.length));

        var exportBtn = toolbar.querySelector('[data-export]');
        if (exportBtn) exportBtn.addEventListener('click', function () { exportCsv(section); });
    }

    // Close any open columns menu on outside click (single delegated listener)
    document.addEventListener('click', function (e) {
        var open = document.querySelector('.columns-menu.show');
        if (open && !open.contains(e.target) && !(e.target.closest && e.target.closest('[data-columns]'))) {
            open.classList.remove('show');
        }
    });

    window.initAdminTableTools = initAdminTableTools;
    // Initial server-rendered section (script runs at end of body)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAdminTableTools);
    } else {
        initAdminTableTools();
    }
})();
