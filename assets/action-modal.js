/**
 * action-modal.js
 * Elegant Unified Modal System for Adding, Deleting, and Updating
 * Matches the exact design language of the Purple Bamboo Logout Confirmation Dialog:
 * - Frosted backdrop with subtle blur
 * - Dark indigo/slate dialog card with rounded corners and ambient glow
 * - Circular status icon with dedicated colors (Delete = Red/Rose, Add = Emerald, Update = Purple)
 * - Outfit font titles, Plus Jakarta Sans body & button typography
 * - Smooth scale-in animations and accessible keyboard & click-outside listeners
 */

(function () {
    'use strict';

    // Inject styles if not already defined in the document
    function injectActionModalStyles() {
        if (document.getElementById('actionModalInjectedStyles')) return;

        const styleEl = document.createElement('style');
        styleEl.id = 'actionModalInjectedStyles';
        styleEl.textContent = `
            .action-modal-backdrop,
            .logout-modal-backdrop {
                position: fixed;
                top: 0;
                left: 0;
                width: 100vw;
                height: 100vh;
                background: rgba(15, 23, 42, 0.8);
                backdrop-filter: blur(4px);
                -webkit-backdrop-filter: blur(4px);
                z-index: 999999;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1rem;
                box-sizing: border-box;
            }
            .action-modal-dialog,
            .logout-modal-dialog {
                background: #1e293b;
                border: 1.5px solid #334155;
                border-radius: 16px;
                padding: 2rem 1.75rem;
                max-width: 400px;
                width: 100%;
                text-align: center;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5), 0 0 24px rgba(124, 58, 237, 0.2);
                animation: scaleInActionModalAnim 0.2s cubic-bezier(0.16, 1, 0.3, 1);
                box-sizing: border-box;
            }
            .action-modal-icon,
            .logout-modal-icon {
                width: 56px;
                height: 56px;
                border-radius: 50%;
                background: rgba(245, 158, 11, 0.15);
                color: #f59e0b;
                border: 2px solid rgba(245, 158, 11, 0.35);
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 1.5rem;
                margin: 0 auto 1rem auto;
                transition: transform 0.2s ease;
            }
            .action-modal-dialog:hover .action-modal-icon {
                transform: scale(1.05);
            }

            /* Dedicated Action Color Variations matching Logout Modal */
            .action-modal-icon.icon-delete,
            .action-modal-icon.icon-danger {
                background: rgba(244, 63, 94, 0.15) !important;
                color: #f43f5e !important;
                border-color: rgba(244, 63, 94, 0.35) !important;
                box-shadow: 0 0 20px rgba(244, 63, 94, 0.2);
            }
            .action-modal-icon.icon-update,
            .action-modal-icon.icon-edit,
            .action-modal-icon.icon-info {
                background: rgba(124, 58, 237, 0.15) !important;
                color: #c084fc !important;
                border-color: rgba(124, 58, 237, 0.35) !important;
                box-shadow: 0 0 20px rgba(124, 58, 237, 0.25);
            }
            .action-modal-icon.icon-add,
            .action-modal-icon.icon-success {
                background: rgba(16, 185, 129, 0.15) !important;
                color: #10b981 !important;
                border-color: rgba(16, 185, 129, 0.35) !important;
                box-shadow: 0 0 20px rgba(16, 185, 129, 0.2);
            }
            .action-modal-icon.icon-warning,
            .action-modal-icon.icon-kitchen,
            .action-modal-icon.icon-logout {
                background: rgba(245, 158, 11, 0.15) !important;
                color: #f59e0b !important;
                border-color: rgba(245, 158, 11, 0.35) !important;
                box-shadow: 0 0 20px rgba(245, 158, 11, 0.2);
            }
            .action-modal-icon.icon-checkout,
            .action-modal-icon.icon-blue {
                background: rgba(37, 99, 235, 0.15) !important;
                color: #3b82f6 !important;
                border-color: rgba(37, 99, 235, 0.35) !important;
                box-shadow: 0 0 20px rgba(37, 99, 235, 0.2);
            }

            .action-modal-title,
            .logout-modal-title {
                font-family: 'Outfit', sans-serif;
                color: #ffffff;
                font-size: 1.25rem;
                font-weight: 700;
                margin: 0 0 0.5rem 0;
                letter-spacing: -0.01em;
            }
            .action-modal-text,
            .logout-modal-text {
                font-family: 'Plus Jakarta Sans', sans-serif;
                color: #cbd5e1;
                font-size: 0.95rem;
                font-weight: 500;
                line-height: 1.55;
                margin: 0 0 1.5rem 0;
            }
            .action-modal-text strong {
                color: #ffffff;
                font-weight: 700;
            }
            .action-modal-actions,
            .logout-modal-actions {
                display: flex;
                gap: 0.75rem;
                justify-content: center;
            }
            .action-modal-btn,
            .logout-modal-btn {
                flex: 1;
                padding: 0.7rem 1rem;
                border-radius: 10px;
                font-size: 0.95rem;
                font-weight: 700;
                font-family: 'Plus Jakarta Sans', sans-serif;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                cursor: pointer;
                transition: all 0.2s ease;
                border: none;
                outline: none;
            }
            .action-modal-btn-cancel,
            .logout-modal-btn-cancel {
                background: #334155 !important;
                color: #ffffff !important;
                border: 1px solid #475569 !important;
            }
            .action-modal-btn-cancel:hover,
            .logout-modal-btn-cancel:hover {
                background: #475569 !important;
                transform: translateY(-1px);
            }
            .logout-modal-btn-confirm {
                background: linear-gradient(135deg, #7c3aed, #6d28d9);
                color: #ffffff;
                border: 1px solid #7c3aed;
            }
            .logout-modal-btn-confirm:hover {
                background: #5b21b6;
                box-shadow: 0 4px 12px rgba(124, 58, 237, 0.35);
                transform: translateY(-1px);
            }
            .action-modal-btn-delete {
                background: linear-gradient(135deg, #e11d48, #be123c) !important;
                color: #ffffff !important;
                border: 1px solid #e11d48 !important;
            }
            .action-modal-btn-delete:hover {
                background: #9f1239 !important;
                box-shadow: 0 4px 14px rgba(225, 29, 72, 0.4) !important;
                transform: translateY(-1px);
            }
            .action-modal-btn-update {
                background: linear-gradient(135deg, #7c3aed, #6d28d9) !important;
                color: #ffffff !important;
                border: 1px solid #7c3aed !important;
            }
            .action-modal-btn-update:hover {
                background: #5b21b6 !important;
                box-shadow: 0 4px 14px rgba(124, 58, 237, 0.4) !important;
                transform: translateY(-1px);
            }
            .action-modal-btn-add {
                background: linear-gradient(135deg, #059669, #047857) !important;
                color: #ffffff !important;
                border: 1px solid #059669 !important;
            }
            .action-modal-btn-add:hover {
                background: #065f46 !important;
                box-shadow: 0 4px 14px rgba(5, 150, 105, 0.4) !important;
                transform: translateY(-1px);
            }
            .action-modal-btn-checkout {
                background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;
                color: #ffffff !important;
                border: 1px solid #2563eb !important;
            }
            .action-modal-btn-checkout:hover {
                background: #1e40af !important;
                box-shadow: 0 4px 14px rgba(37, 99, 235, 0.4) !important;
                transform: translateY(-1px);
            }
            .action-modal-btn-warning {
                background: linear-gradient(135deg, #f59e0b, #d97706) !important;
                color: #ffffff !important;
                border: 1px solid #f59e0b !important;
            }
            .action-modal-btn-warning:hover {
                background: #b45309 !important;
                box-shadow: 0 4px 14px rgba(245, 158, 11, 0.4) !important;
                transform: translateY(-1px);
            }
            @keyframes scaleInActionModalAnim {
                from { opacity: 0; transform: scale(0.95); }
                to { opacity: 1; transform: scale(1); }
            }
        `;
        document.head.appendChild(styleEl);
    }

    // Ensure action modal DOM elements exist in the page
    function ensureActionModalDOM() {
        injectActionModalStyles();

        let modal = document.getElementById('adminActionModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'adminActionModal';
            modal.className = 'logout-modal-backdrop action-modal-backdrop';
            modal.style.display = 'none';
            modal.onclick = function (e) {
                if (e.target === modal) closeActionModal();
            };
            modal.innerHTML = `
                <div class="logout-modal-dialog action-modal-dialog">
                    <div id="adminActionModalIcon" class="logout-modal-icon action-modal-icon">
                        <i class="fa-solid fa-circle-question"></i>
                    </div>
                    <h3 id="adminActionModalTitle" class="logout-modal-title action-modal-title">Action Confirmation</h3>
                    <p id="adminActionModalText" class="logout-modal-text action-modal-text">Are you sure you want to proceed?</p>
                    <div id="adminActionModalActions" class="logout-modal-actions action-modal-actions">
                        <button type="button" id="adminActionModalCancelBtn" class="logout-modal-btn logout-modal-btn-cancel action-modal-btn-cancel" onclick="closeActionModal()">
                            <i class="fa-solid fa-xmark"></i> Cancel
                        </button>
                        <button type="button" id="adminActionModalConfirmBtn" class="logout-modal-btn logout-modal-btn-confirm">
                            <i class="fa-solid fa-check"></i> Yes
                        </button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }
        return modal;
    }

    // Keep active callback reference
    let currentConfirmCallback = null;
    let currentCancelCallback = null;

    /**
     * Open Action Modal Dialog
     * @param {Object} opts Configuration options
     * - type: 'add' | 'delete' | 'update' | 'warning'
     * - title: Title text or HTML
     * - message: Message text or HTML
     * - confirmText: Custom label for confirm button
     * - cancelText: Custom label for cancel button (default: 'Cancel')
     * - onConfirm: Function to call when confirmed
     * - onCancel: Function to call when cancelled
     */
    function openActionModal(opts) {
        opts = opts || {};
        const modal = ensureActionModalDOM();
        const iconEl = document.getElementById('adminActionModalIcon');
        const titleEl = document.getElementById('adminActionModalTitle');
        const textEl = document.getElementById('adminActionModalText');
        const confirmBtn = document.getElementById('adminActionModalConfirmBtn');
        const cancelBtn = document.getElementById('adminActionModalCancelBtn');

        const type = (opts.type || 'update').toLowerCase();
        currentConfirmCallback = opts.onConfirm || null;
        currentCancelCallback = opts.onCancel || null;

        // Reset Icon Classes
        if (iconEl) {
            iconEl.className = 'logout-modal-icon action-modal-icon';
            if (opts.iconClass) {
                iconEl.className += ' ' + opts.iconClass;
            }
            if (opts.iconHtml) {
                iconEl.innerHTML = opts.iconHtml;
            } else if (type === 'delete' || type === 'danger') {
                iconEl.classList.add('icon-delete');
                iconEl.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
            } else if (type === 'checkout' || type === 'check_out') {
                iconEl.classList.add('icon-checkout');
                iconEl.innerHTML = '<i class="fa-solid fa-arrow-right-from-bracket"></i>';
            } else if (type === 'checkin' || type === 'check_in') {
                iconEl.classList.add('icon-add');
                iconEl.innerHTML = '<i class="fa-solid fa-door-open"></i>';
            } else if (type === 'kitchen') {
                iconEl.classList.add('icon-kitchen');
                iconEl.innerHTML = '<i class="fa-solid fa-fire-burner"></i>';
            } else if (type === 'serve') {
                iconEl.classList.add('icon-add');
                iconEl.innerHTML = '<i class="fa-solid fa-bell-concierge"></i>';
            } else if (type === 'verify' || type === 'approve') {
                iconEl.classList.add('icon-add');
                iconEl.innerHTML = '<i class="fa-solid fa-circle-check"></i>';
            } else if (type === 'add' || type === 'success') {
                iconEl.classList.add('icon-add');
                iconEl.innerHTML = '<i class="fa-solid fa-circle-plus"></i>';
            } else if (type === 'logout') {
                iconEl.classList.add('icon-logout');
                iconEl.innerHTML = '<i class="fa-solid fa-arrow-right-from-bracket"></i>';
            } else if (type === 'warning') {
                iconEl.classList.add('icon-warning');
                iconEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>';
            } else {
                // update / edit / default
                iconEl.classList.add('icon-update');
                iconEl.innerHTML = '<i class="fa-solid fa-pen-to-square"></i>';
            }
        }

        // Title
        if (titleEl) {
            titleEl.innerHTML = opts.title || (
                type === 'delete' ? 'Delete Confirmation' :
                type === 'checkout' || type === 'check_out' ? 'Check Out Confirmation' :
                type === 'checkin' || type === 'check_in' ? 'Check In Confirmation' :
                type === 'kitchen' ? 'Send to Kitchen' :
                type === 'serve' ? 'Mark as Served' :
                type === 'verify' ? 'Payment Verification' :
                type === 'logout' ? 'Sign Out Confirmation' :
                type === 'add' ? 'Add Confirmation' : 'Update Confirmation'
            );
        }

        // Message
        if (textEl) {
            textEl.innerHTML = opts.message || 'Are you sure you want to proceed?';
        }

        // Cancel Button
        if (cancelBtn) {
            const cancelLabel = opts.cancelText || 'Cancel';
            cancelBtn.innerHTML = `<i class="fa-solid fa-xmark"></i> ${cancelLabel}`;
            cancelBtn.onclick = function () {
                closeActionModal();
                if (typeof currentCancelCallback === 'function') {
                    currentCancelCallback();
                }
            };
        }

        // Confirm Button
        if (confirmBtn) {
            confirmBtn.className = 'logout-modal-btn action-modal-btn';
            let defaultIcon = 'fa-check';
            let defaultLabel = 'Yes, Proceed';

            if (type === 'delete' || type === 'danger') {
                confirmBtn.classList.add('action-modal-btn-delete');
                defaultIcon = 'fa-trash-can';
                defaultLabel = 'Yes, Delete';
            } else if (type === 'checkout' || type === 'check_out') {
                confirmBtn.classList.add('action-modal-btn-checkout');
                defaultIcon = 'fa-arrow-right-from-bracket';
                defaultLabel = 'Yes, Check Out';
            } else if (type === 'kitchen') {
                confirmBtn.classList.add('action-modal-btn-warning');
                defaultIcon = 'fa-fire-burner';
                defaultLabel = 'Yes, Send to Kitchen';
            } else if (type === 'logout') {
                confirmBtn.classList.add('action-modal-btn-delete');
                defaultIcon = 'fa-arrow-right-from-bracket';
                defaultLabel = 'Yes, Sign Out';
            } else if (type === 'add' || type === 'success' || type === 'checkin' || type === 'check_in' || type === 'serve' || type === 'verify') {
                confirmBtn.classList.add('action-modal-btn-add');
                defaultIcon = 'fa-check';
                defaultLabel = type === 'checkin' || type === 'check_in' ? 'Yes, Check In' :
                               type === 'serve' ? 'Yes, Mark Served' :
                               type === 'verify' ? 'Yes, Verify Payment' : 'Yes, Add';
            } else {
                confirmBtn.classList.add('action-modal-btn-update');
                defaultIcon = 'fa-check';
                defaultLabel = 'Yes, Save Changes';
            }

            if (opts.confirmBtnClass) {
                confirmBtn.className += ' ' + opts.confirmBtnClass;
            }

            const confirmLabel = opts.confirmText || defaultLabel;
            // Check if label already contains icon HTML
            if (confirmLabel.includes('<i class="')) {
                confirmBtn.innerHTML = confirmLabel;
            } else {
                confirmBtn.innerHTML = `<i class="fa-solid ${defaultIcon}"></i> ${confirmLabel}`;
            }

            confirmBtn.onclick = function (e) {
                if (e) e.preventDefault();
                const callback = currentConfirmCallback;
                closeActionModal();
                if (typeof callback === 'function') {
                    callback();
                }
            };
        }

        // Display Modal
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        // Accessibility focus: focus cancel for delete actions, confirm for others
        setTimeout(() => {
            if (type === 'delete' && cancelBtn) {
                cancelBtn.focus();
            } else if (confirmBtn) {
                confirmBtn.focus();
            }
        }, 50);
    }

    /**
     * Close Action Modal Dialog
     */
    function closeActionModal() {
        const modal = document.getElementById('adminActionModal');
        if (modal) {
            modal.style.display = 'none';
        }
        // Only release body scroll if logout modal is not open
        const logoutModal = document.getElementById('logoutConfirmModal') || document.getElementById('adminLogoutConfirmModal');
        if (!logoutModal || logoutModal.style.display === 'none') {
            document.body.style.overflow = '';
        }
        currentConfirmCallback = null;
        currentCancelCallback = null;
    }

    // Keyboard listener: Escape closes the active action modal
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('adminActionModal');
            if (modal && modal.style.display !== 'none') {
                closeActionModal();
            }
        }
    });

    // Auto-inject styles on DOM load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ensureActionModalDOM);
    } else {
        ensureActionModalDOM();
    }

    // ============================================================
    // Global Public APIs
    // ============================================================

    // 1. Delete Confirmation (Same design as Logout, with rose/red theme)
    window.showDeleteConfirm = function (title, messageHtml, onConfirm, confirmBtnText, cancelBtnText) {
        openActionModal({
            type: 'delete',
            title: title || 'Delete Confirmation',
            message: messageHtml || 'Are you sure you want to permanently delete this item?<br><small style="color: #f87171; display:block; margin-top:6px;">This action cannot be undone.</small>',
            confirmText: confirmBtnText || 'Yes, Delete',
            cancelText: cancelBtnText || 'Cancel',
            onConfirm: onConfirm
        });
    };

    // 2. Add Confirmation (Same design as Logout, with emerald theme)
    window.showAddConfirm = function (title, messageHtml, onConfirm, confirmBtnText) {
        openActionModal({
            type: 'add',
            title: title || 'Add Confirmation',
            message: messageHtml || 'Are you sure you want to add this item to resort inventory?',
            confirmText: confirmBtnText || 'Yes, Add',
            onConfirm: onConfirm
        });
    };

    // 3. Update Confirmation (Same design as Logout, with purple theme)
    window.showUpdateConfirm = function (title, messageHtml, onConfirm, confirmBtnText) {
        openActionModal({
            type: 'update',
            title: title || 'Save Changes Confirmation',
            message: messageHtml || 'Are you sure you want to save your changes?',
            confirmText: confirmBtnText || 'Yes, Save Changes',
            onConfirm: onConfirm
        });
    };

    // 4. Generic Action Confirm & Dialog Control
    window.showActionConfirm = openActionModal;
    window.showConfirmModal = openActionModal;
    window.openActionModal = openActionModal;
    window.closeActionModal = closeActionModal;

    // 5. Ensure openLogoutModal fallback exists everywhere
    if (typeof window.openLogoutModal !== 'function') {
        window.openLogoutModal = function(e) {
            if (e && typeof e.preventDefault === 'function') e.preventDefault();
            const modal = document.getElementById('adminLogoutConfirmModal') || document.getElementById('logoutConfirmModal');
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            } else {
                openActionModal({
                    type: 'logout',
                    title: 'Sign Out Confirmation',
                    message: 'Do you really want to logout from the Admin Console?',
                    confirmText: '<i class="fa-solid fa-arrow-right-from-bracket"></i> Yes, Sign Out',
                    cancelText: 'No',
                    onConfirm: function() {
                        window.location.href = 'logout.php?confirm=1';
                    }
                });
            }
        };
    }
})();
