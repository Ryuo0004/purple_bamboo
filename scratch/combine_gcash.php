<?php
$file = 'c:/xamppp/htdocs/purple_bamboo/bookings.php';
$content = file_get_contents($file);

// 1. Add PHP action
$action_find = "    if (\$act === 'approve') {";
$action_replace = "    if (\$act === 'reject_payment') {
        \$reason = trim(\$_GET['reason'] ?? 'Proof was rejected by admin');
        if (\$reason === 'Others' || empty(\$reason)) {
            \$reason = trim(\$_GET['other_reason'] ?? 'Proof was rejected by admin');
        }
        BookingStore::updateBookingStatus(\$id, 'pending', 'rejected', null, null, null, \$reason);
        \$action_msg = 'GCash payment proof for Ref #' . htmlspecialchars(\$id) . ' was marked as REJECTED.';
        \$action_type = 'danger';
    } elseif (\$act === 'approve') {";
$content = str_replace($action_find, $action_replace, $content);

// 2. Add View Proof button
$td_gcash_find = "                                        <?php if (!empty(\$b['gcash_ref'])): ?>
                                            <br><small style=\"font-size: 0.75rem; color: var(--text-muted);\">Ref: <?= htmlspecialchars(\$b['gcash_ref']) ?></small>
                                        <?php endif; ?>
                                    </td>";
$td_gcash_replace = "                                        <?php if (!empty(\$b['gcash_ref'])): ?>
                                            <br><small style=\"font-size: 0.75rem; color: var(--text-muted);\">Ref: <?= htmlspecialchars(\$b['gcash_ref']) ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty(\$b['payment_proof'])): ?>
                                            <div style=\"margin-top: 5px;\">
                                                <button type=\"button\" onclick=\"openPictureModal('<?= htmlspecialchars(addslashes(\$b['payment_proof'])) ?>', 'GCash Receipt - Ref #<?= htmlspecialchars(addslashes(\$b['id'])) ?>')\" class=\"act-btn\" style=\"width: auto; padding: 3px 8px; font-size: 0.75rem; background: rgba(124, 58, 237, 0.15); color: var(--primary-glow);\"><i class=\"fa-solid fa-image\"></i> View Proof</button>
                                            </div>
                                        <?php endif; ?>
                                    </td>";
$content = str_replace($td_gcash_find, $td_gcash_replace, $content);

// 3. Add Reject button
$action_col_find = "                                                    <a href=\"bookings.php?action=approve&id=<?= \$b['id'] ?>\" class=\"act-btn act-btn-approve\" title=\"Approve & Verify Payment\">
                                                        <i class=\"fa-solid fa-check\"></i>
                                                    </a>";
$action_col_replace = $action_col_find . "
                                                    <?php if ((\$b['payment_status'] ?? '') === 'proof_submitted'): ?>
                                                        <button type=\"button\" class=\"act-btn\" style=\"background: rgba(244, 63, 94, 0.15); color: #f43f5e; border-color: rgba(244, 63, 94, 0.3);\" onclick=\"openRejectModal('<?= htmlspecialchars(addslashes(\$b['id'])) ?>', '<?= htmlspecialchars(addslashes(\$b['guest_name'])) ?>', '<?= number_format(floatval(\$b['total_amount'])) ?>')\" title=\"Reject Payment Proof\">
                                                            <i class=\"fa-solid fa-xmark\"></i>
                                                        </button>
                                                    <?php endif; ?>";
$content = str_replace($action_col_find, $action_col_replace, $content);

// 4. Modals HTML
$modals_html = <<<HTML
    <!-- Picture Lightbox Modal -->
    <div id="picture_modal" class="picture-modal-overlay" onclick="closePictureModal(event)">
        <div class="picture-modal-container" onclick="event.stopPropagation()">
            <button type="button" class="picture-modal-close" onclick="closePictureModal()">&times;</button>
            <h3 id="picture_modal_title" style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 0.5rem;">Uploaded Picture View</h3>
            <img id="picture_modal_img" src="" class="picture-modal-img" alt="Enlarged View" style="max-width:100%; height:auto;">
        </div>
    </div>

    <!-- Reject Payment Modal -->
    <div id="rejectModal" class="picture-modal-overlay" onclick="if(event.target === this) closeRejectModal();">
        <div class="picture-modal-container" style="max-width: 520px; text-align: left; background: #0f172a; border: 1.5px solid #1e293b; color: #fff;">
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #1e293b; display: flex; justify-content: space-between; align-items: center; background: rgba(244, 63, 94, 0.1);">
                <h3 style="margin: 0; font-family: 'Outfit', sans-serif; color: #fb7185; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-triangle-exclamation"></i> Reject Payment Proof
                </h3>
                <button type="button" class="picture-modal-close" style="position: static;" onclick="closeRejectModal()">&times;</button>
            </div>
            <form action="bookings.php" method="GET" id="rejectForm">
                <input type="hidden" name="action" value="reject_payment">
                <input type="hidden" name="id" id="rejectModalRefNo" value="">
                
                <div style="padding: 1.5rem;">
                    <div style="background: rgba(15, 23, 42, 0.6); border: 1px dashed #334155; padding: 1rem; border-radius: 8px; margin-bottom: 1.25rem;">
                        <p style="margin: 0 0 0.5rem 0; color: #94a3b8; font-size: 0.9rem;"><strong>Ref #:</strong> <span id="rejectModalRefDisplay" style="color: #fbbf24; font-family: monospace;"></span></p>
                        <p style="margin: 0 0 0.5rem 0; color: #94a3b8; font-size: 0.9rem;"><strong>Guest Name:</strong> <span id="rejectModalGuest" style="color: #fff; font-weight: 700;"></span></p>
                        <p style="margin: 0; color: #94a3b8; font-size: 0.9rem;"><strong>Amount:</strong> <span id="rejectModalAmount" style="color: #34d399; font-weight: 700;"></span></p>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-size: 0.85rem; color: #cbd5e1; font-weight: 700; margin-bottom: 0.4rem; display: block;">Reason for Rejection: *</label>
                        <select name="reason" id="rejectReasonSelect" class="form-control" style="background: #1e293b; color: #fff; border: 1px solid #334155; width: 100%;" onchange="toggleRejectOtherReason(this)" required>
                            <option value="">-- Select a reason --</option>
                            <option value="Proof is blurry or unreadable">Proof is blurry or unreadable</option>
                            <option value="Incorrect amount paid">Incorrect amount paid</option>
                            <option value="Invalid reference number">Invalid reference number</option>
                            <option value="Fake or tampered receipt">Fake or tampered receipt</option>
                            <option value="Others">Others (Please specify)</option>
                        </select>
                    </div>

                    <div id="rejectOtherReasonGroup" style="display: none; margin-top: 0.85rem;">
                        <label style="font-size: 0.85rem; color: #fbbf24; font-weight: 700; margin-bottom: 0.4rem; display: block;">
                            <i class="fa-solid fa-pen"></i> Specify Custom Reason: *
                        </label>
                        <input type="text" name="other_reason" id="rejectOtherReasonInput" class="form-control" style="background: #1e293b; color: #fff; border: 1.5px solid #fbbf24; width: 100%; padding: 0.6rem 0.8rem;" placeholder="Type custom rejection reason...">
                    </div>
                </div>
                
                <div style="padding: 1rem 1.5rem; background: #0f172a; border-top: 1px solid #1e293b; display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="button" onclick="closeRejectModal()" class="admin-btn admin-btn-outline admin-btn-sm">Cancel</button>
                    <button type="submit" class="admin-btn admin-btn-danger admin-btn-sm" style="background: #f43f5e; border-color: #f43f5e;">
                        <i class="fa-solid fa-xmark"></i> Confirm Rejection
                    </button>
                </div>
            </form>
        </div>
    </div>
HTML;

$content = str_replace('    <!-- Manual Walk-in Booking Modal -->', $modals_html . "\n\n    <!-- Manual Walk-in Booking Modal -->", $content);

// 5. Modals JS
$js_code = <<<JS
        function openPictureModal(imgSrc, title) {
            if (!imgSrc) return;
            document.getElementById('picture_modal_img').src = imgSrc;
            document.getElementById('picture_modal_title').textContent = title || 'Picture Preview';
            document.getElementById('picture_modal').classList.add('active');
        }

        function closePictureModal(e) {
            document.getElementById('picture_modal').classList.remove('active');
        }

        function openRejectModal(ref, guest, amount) {
            document.getElementById('rejectModalRefNo').value = ref;
            document.getElementById('rejectModalRefDisplay').textContent = ref;
            document.getElementById('rejectModalGuest').textContent = guest;
            document.getElementById('rejectModalAmount').textContent = '₱' + amount;
            
            document.getElementById('rejectReasonSelect').selectedIndex = 0;
            document.getElementById('rejectOtherReasonGroup').style.display = 'none';
            document.getElementById('rejectOtherReasonInput').value = '';
            document.getElementById('rejectOtherReasonInput').required = false;
            
            document.getElementById('rejectModal').classList.add('active');
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('active');
        }

        function toggleRejectOtherReason(select) {
            const otherGroup = document.getElementById('rejectOtherReasonGroup');
            const otherInput = document.getElementById('rejectOtherReasonInput');
            if (select.value === 'Others') {
                otherGroup.style.display = 'block';
                otherInput.required = true;
            } else {
                otherGroup.style.display = 'none';
                otherInput.required = false;
                otherInput.value = '';
            }
        }
JS;

$content = str_replace('// ---------- Real-time Validation', $js_code . "\n\n        // ---------- Real-time Validation", $content);

file_put_contents($file, $content);
echo "Successfully patched bookings.php";
?>
