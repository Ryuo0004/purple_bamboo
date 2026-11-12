/**
 * action-modal.js (fallback mirror)
 */
(function () {
    'use strict';
    // Mirror the main script
    var s = document.createElement('script');
    s.src = 'assets/action-modal.js';
    if (!window.showDeleteConfirm) {
        document.head.appendChild(s);
    }
})();
