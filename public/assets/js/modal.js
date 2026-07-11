/**
 * Themed modal system — replaces native alert() and confirm().
 * Reuses existing CSS button classes (.btn, .btn-primary, .btn-secondary, .btn-danger).
 * Injected dynamically, no static HTML required.
 */

(function() {
    'use strict';

    var overlay, box, iconEl, titleEl, msgEl, actionsEl;
    var escHandler = null;

    function build() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        box = document.createElement('div');
        box.className = 'modal-box';
        iconEl = document.createElement('div');
        iconEl.className = 'modal-icon';
        titleEl = document.createElement('div');
        titleEl.className = 'modal-title';
        msgEl = document.createElement('div');
        msgEl.className = 'modal-message';
        actionsEl = document.createElement('div');
        actionsEl.className = 'modal-actions';
        box.appendChild(iconEl);
        box.appendChild(titleEl);
        box.appendChild(msgEl);
        box.appendChild(actionsEl);
        overlay.appendChild(box);
        document.body.appendChild(overlay);
    }

    function close() {
        overlay.classList.remove('show');
        if (escHandler) {
            document.removeEventListener('keydown', escHandler);
            escHandler = null;
        }
    }

    function show(iconClass, iconHtml, title, message, buttons) {
        build();
        iconEl.className = 'modal-icon ' + iconClass;
        iconEl.innerHTML = iconHtml;
        titleEl.textContent = title;
        msgEl.textContent = message;
        actionsEl.innerHTML = '';
        buttons.forEach(function(b) {
            var btn = document.createElement('button');
            btn.className = 'btn ' + (b.cls || 'btn-primary');
            btn.textContent = b.label;
            btn.onclick = function() {
                close();
                if (b.cb) b.cb();
            };
            actionsEl.appendChild(btn);
            if (b.focus) setTimeout(function() { btn.focus(); }, 50);
        });
        escHandler = function(e) {
            if (e.key === 'Escape') { close(); }
        };
        document.addEventListener('keydown', escHandler);
        overlay.onclick = function(e) {
            if (e.target === overlay) close();
        };
        // Trigger animation
        requestAnimationFrame(function() {
            overlay.classList.add('show');
        });
    }

    window.showAlert = function(message, type, title) {
        if (!type) type = 'success';
        var iconMap = {
            success: ['success', '<i class="fas fa-check-circle"></i>', 'Success'],
            error:   ['error',   '<i class="fas fa-exclamation-circle"></i>', 'Error'],
            info:    ['info',    '<i class="fas fa-info-circle"></i>', 'Info']
        };
        var m = iconMap[type] || iconMap.info;
        show(m[0], m[1], title || m[2], message, [
            { label: 'OK', cls: 'btn-primary', focus: true }
        ]);
    };

    window.showConfirm = function(message, onConfirm, options) {
        options = options || {};
        var confirmLabel = options.confirmLabel || 'Confirm';
        var confirmClass = options.confirmClass || 'btn-primary';
        show('error', '<i class="fas fa-question-circle"></i>', 'Confirm Action', message, [
            { label: 'Cancel', cls: 'btn-secondary' },
            { label: confirmLabel, cls: confirmClass, focus: true, cb: onConfirm }
        ]);
    };
})();