/**
 * manage-booking-actions.js
 * Unified interactive modal system for Purple Bamboo Booking Management
 *
 * Ensures all action buttons (Delete, Check-Out, Check-In, Verify Payment,
 * Kitchen Send, Mark Served, Clear History, and Logout) display the
 * sleek confirmation modal styled identically to the Logout Confirmation Dialog:
 * - Frosted backdrop with subtle blur
 * - Dark indigo/slate dialog card with ambient glow
 * - Circular status icon with dedicated colors & icons
 * - Clear action title and description
 * - "Cancel / No" and "Yes, Proceed" button controls
 */

(function () {
    'use strict';

    /**
     * Helper to show action modal using window.openActionModal / action-modal.js
     */
    function showModal(opts) {
        if (typeof window.openActionModal === 'function') {
            window.openActionModal(opts);
        } else if (typeof window.showActionConfirm === 'function') {
            window.showActionConfirm(opts);
        } else {
            // Fallback if modal system is somehow not initialized yet
            if (confirm(opts.message ? opts.message.replace(/<[^>]*>/g, '') : 'Are you sure you want to proceed?')) {
                if (typeof opts.onConfirm === 'function') opts.onConfirm();
            }
        }
    }

    /**
     * 1. Check Out Confirmation
     */
    window.confirmBookingCheckOut = function (bookingId, guestName, url) {
        const safeId = bookingId || 'selected booking';
        const guestText = guestName ? ` for <strong>${guestName}</strong>` : '';
        showModal({
            type: 'checkout',
            title: 'Check Out Guest Confirmation',
            message: `Are you sure you want to complete check-out for Booking <strong>#${safeId}</strong>${guestText}?`,
            confirmText: '<i class="fa-solid fa-arrow-right-from-bracket"></i> Yes, Check Out',
            cancelText: 'Cancel',
            onConfirm: function () {
                if (url) window.location.href = url;
            }
        });
    };

    /**
     * 2. Check In Confirmation
     */
    window.confirmBookingCheckIn = function (bookingId, guestName, url) {
        const safeId = bookingId || 'selected booking';
        const guestText = guestName ? ` for <strong>${guestName}</strong>` : '';
        showModal({
            type: 'checkin',
            title: 'Check In Guest Confirmation',
            message: `Are you sure you want to check in guest${guestText} for Booking <strong>#${safeId}</strong>?`,
            confirmText: '<i class="fa-solid fa-door-open"></i> Yes, Check In',
            cancelText: 'Cancel',
            onConfirm: function () {
                if (url) window.location.href = url;
            }
        });
    };

    /**
     * 3. Delete Booking / Order Confirmation
     */
    window.confirmBookingDelete = function (bookingId, isDining, url) {
        const safeId = bookingId || 'selected item';
        const itemType = isDining ? 'dining order' : 'booking';
        showModal({
            type: 'delete',
            title: isDining ? 'Delete Order Confirmation' : 'Delete Booking Confirmation',
            message: `Are you sure you want to permanently delete ${itemType} <strong>#${safeId}</strong>?<br><span style="color:#f87171; display:block; margin-top:8px; font-size:0.85rem; font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> This action cannot be undone.</span>`,
            confirmText: '<i class="fa-solid fa-trash-can"></i> Yes, Delete',
            cancelText: 'Cancel',
            onConfirm: function () {
                if (url) window.location.href = url;
            }
        });
    };

    /**
     * 4. Verify Payment Confirmation
     */
    window.confirmBookingVerifyPayment = function (bookingId, amount, url) {
        const safeId = bookingId || 'selected booking';
        const amountText = amount ? ` (<strong>₱${amount}</strong>)` : '';
        showModal({
            type: 'verify',
            title: 'Verify Payment Confirmation',
            message: `Are you sure you want to verify & approve payment for Booking <strong>#${safeId}</strong>${amountText}?`,
            confirmText: '<i class="fa-solid fa-circle-check"></i> Yes, Verify Payment',
            cancelText: 'Cancel',
            onConfirm: function () {
                if (url) window.location.href = url;
            }
        });
    };

    /**
     * 5. Send Dining Order to Kitchen
     */
    window.confirmSendDiningToKitchen = function (orderId, url) {
        const safeId = orderId || 'selected order';
        showModal({
            type: 'kitchen',
            title: 'Send Order to Kitchen',
            message: `Are you sure you want to send Dining Order <strong>#${safeId}</strong> to the kitchen queue for preparation?`,
            confirmText: '<i class="fa-solid fa-fire-burner"></i> Yes, Send to Kitchen',
            cancelText: 'Cancel',
            onConfirm: function () {
                if (url) window.location.href = url;
            }
        });
    };

    /**
     * 6. Mark Dining Order as Served
     */
    window.confirmMarkDiningServed = function (orderId, url) {
        const safeId = orderId || 'selected order';
        showModal({
            type: 'serve',
            title: 'Mark Order as Served',
            message: `Are you sure you want to mark Dining Order <strong>#${safeId}</strong> as served and completed?`,
            confirmText: '<i class="fa-solid fa-check"></i> Yes, Mark Served',
            cancelText: 'Cancel',
            onConfirm: function () {
                if (url) window.location.href = url;
            }
        });
    };

    /**
     * 7. Confirm Reschedule Request
     */
    window.confirmBookingRescheduleApply = function (bookingId, url) {
        const safeId = bookingId || 'selected booking';
        showModal({
            type: 'reschedule',
            title: 'Apply Reschedule Confirmation',
            message: `Are you sure you want to confirm and apply the rescheduled dates for Booking <strong>#${safeId}</strong>?`,
            confirmText: '<i class="fa-solid fa-check"></i> Yes, Apply Reschedule',
            cancelText: 'Cancel',
            onConfirm: function () {
                if (url) window.location.href = url;
            }
        });
    };

    /**
     * 8. Override / Replace confirmPaymentAndCheckin
     */
    window.confirmPaymentAndCheckin = function (id, guestName, amount) {
        const safeId = id || '';
        const safeGuest = guestName || 'Guest';
        const safeAmount = amount || '0.00';
        showModal({
            type: 'verify',
            title: 'Verify Payment & Check In',
            message: `Booking <strong>#${safeId}</strong> for <strong>${safeGuest}</strong> (<strong>₱${safeAmount}</strong>) has not been verified yet.<br><br>Do you want to <strong>Verify & Approve</strong> payment now and check the guest in?`,
            confirmText: '<i class="fa-solid fa-check-double"></i> Yes, Verify & Check In',
            cancelText: 'Cancel',
            onConfirm: function () {
                const params = new URLSearchParams(window.location.search);
                const viewMode = params.get('view') || 'all';
                window.location.href = 'bookings.php?view=' + encodeURIComponent(viewMode) + '&action=verify_and_checkin&id=' + encodeURIComponent(safeId);
            }
        });
    };

    /**
     * 9. Override / Replace confirmClearHistory
     */
    window.confirmClearHistory = function () {
        showModal({
            type: 'delete',
            title: 'Clear History Confirmation',
            message: `Are you sure you want to clear <strong>ALL archived booking history</strong>?<br><span style="color:#f87171; display:block; margin-top:8px; font-size:0.85rem; font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> This will permanently remove all completed & cancelled records. This cannot be undone.</span>`,
            confirmText: '<i class="fa-solid fa-trash-can"></i> Yes, Clear History',
            cancelText: 'Cancel',
            onConfirm: function () {
                const form = document.getElementById('clearHistoryForm');
                if (form) form.submit();
            }
        });
    };

    /**
     * 10. Global Click Interceptor (Capture Phase)
     * Intercepts button and link clicks across the Manage Booking interface,
     * suppressing browser confirm() alerts and showing the modern unified modal.
     */
    document.addEventListener('click', function (e) {
        // A. Logout Links / Buttons
        const logoutBtn = e.target.closest('a[href="logout.php"], a[href^="logout.php?"], .hs-btn-logout, .admin-sidebar-signout-btn');
        if (logoutBtn) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (typeof window.openLogoutModal === 'function') {
                window.openLogoutModal(e);
            } else {
                showModal({
                    type: 'logout',
                    title: 'Sign Out Confirmation',
                    message: 'Do you really want to logout from the Admin Console?',
                    confirmText: '<i class="fa-solid fa-arrow-right-from-bracket"></i> Yes, Sign Out',
                    cancelText: 'No',
                    onConfirm: function () {
                        window.location.href = 'logout.php?confirm=1';
                    }
                });
            }
            return;
        }

        // B. Action Links with bookings.php?action=...
        const actionLink = e.target.closest('a[href*="action="]');
        if (actionLink && actionLink.href) {
            const urlObj = new URL(actionLink.href, window.location.href);
            const action = urlObj.searchParams.get('action');
            const id = urlObj.searchParams.get('id') || '';

            // Extract row context (Guest name, amount, dining type)
            const row = actionLink.closest('tr');
            let guestName = '';
            let amount = '';
            let isDining = false;

            if (row) {
                const guestEl = row.querySelector('.bm-guest-name, [data-label="Guest Name"]');
                if (guestEl) guestName = guestEl.textContent.trim();

                const amountEl = row.querySelector('.bm-amount, [data-label="Amount"]');
                if (amountEl) amount = amountEl.textContent.replace(/[^\d.,]/g, '').trim();

                const typeBadge = row.querySelector('.bm-type-badge, [data-label="Type"]');
                if (typeBadge && /dining|kitchen/i.test(typeBadge.textContent)) isDining = true;
            }

            // Also inspect title or onclick text for dining cues
            const titleOrText = (actionLink.title || actionLink.textContent || actionLink.getAttribute('onclick') || '').toLowerCase();
            if (titleOrText.includes('dining') || titleOrText.includes('kitchen') || titleOrText.includes('order')) {
                isDining = true;
            }

            if (action === 'delete') {
                e.preventDefault();
                e.stopImmediatePropagation();
                window.confirmBookingDelete(id, isDining, actionLink.href);
                return;
            } else if (action === 'check_out') {
                e.preventDefault();
                e.stopImmediatePropagation();
                if (isDining || titleOrText.includes('served')) {
                    window.confirmMarkDiningServed(id, actionLink.href);
                } else {
                    window.confirmBookingCheckOut(id, guestName, actionLink.href);
                }
                return;
            } else if (action === 'check_in') {
                e.preventDefault();
                e.stopImmediatePropagation();
                window.confirmBookingCheckIn(id, guestName, actionLink.href);
                return;
            } else if (action === 'approve') {
                e.preventDefault();
                e.stopImmediatePropagation();
                if (isDining || titleOrText.includes('kitchen')) {
                    window.confirmSendDiningToKitchen(id, actionLink.href);
                } else {
                    window.confirmBookingVerifyPayment(id, amount, actionLink.href);
                }
                return;
            } else if (action === 'confirm_reschedule_request') {
                e.preventDefault();
                e.stopImmediatePropagation();
                window.confirmBookingRescheduleApply(id, actionLink.href);
                return;
            }
        }

        // C. Any button or link with an inline onclick containing confirm(
        const confirmEl = e.target.closest('[onclick*="confirm("], [data-confirm]');
        if (confirmEl && !confirmEl.closest('.logout-modal-dialog') && !confirmEl.closest('.action-modal-dialog')) {
            const rawOnclick = confirmEl.getAttribute('onclick') || '';
            const dataConfirm = confirmEl.getAttribute('data-confirm') || '';

            // Extract prompt string if available
            let promptMsg = dataConfirm;
            if (!promptMsg && rawOnclick) {
                const match = rawOnclick.match(/confirm\s*\(\s*(['"])(.*?)\1\s*\)/);
                if (match && match[2]) {
                    promptMsg = match[2];
                }
            }

            if (promptMsg) {
                e.preventDefault();
                e.stopImmediatePropagation();

                const isDelete = /delete|remove|clear/i.test(promptMsg);
                const isCheckOut = /check\s*out/i.test(promptMsg);
                const isVerify = /verify|approve/i.test(promptMsg);

                showModal({
                    type: isDelete ? 'delete' : isCheckOut ? 'checkout' : isVerify ? 'verify' : 'warning',
                    title: isDelete ? 'Delete Confirmation' : isCheckOut ? 'Check Out Confirmation' : isVerify ? 'Verification Confirmation' : 'Action Confirmation',
                    message: promptMsg,
                    confirmText: isDelete ? 'Yes, Delete' : isCheckOut ? 'Yes, Check Out' : 'Yes, Proceed',
                    cancelText: 'Cancel',
                    onConfirm: function () {
                        if (confirmEl.tagName.toLowerCase() === 'a' && confirmEl.href) {
                            window.location.href = confirmEl.href;
                        } else if (confirmEl.form) {
                            confirmEl.form.submit();
                        }
                    }
                });
            }
        }
    }, true); // Use capture phase so we precede inline onclick evaluation

    // Auto-initialize when DOM is ready
    console.log('[PurpleBamboo] Manage Booking Unified Action Modals initialized.');
})();
