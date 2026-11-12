<?php
$files = [
    __DIR__ . '/../book_house.php',
    __DIR__ . '/../purple_bamboo/book_house.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);

    // 1. In POST handling: capture check_in_time and check_out_time
    $target_post = '$submitted_check_out = trim($_POST[\'check_out\'] ?? \'\');';
    $replace_post = '$submitted_check_out = trim($_POST[\'check_out\'] ?? \'\');
    $submitted_check_in_time  = trim($_POST[\'check_in_time\'] ?? \'14:00\');
    $submitted_check_out_time = trim($_POST[\'check_out_time\'] ?? \'12:00\');';
    $content = str_replace($target_post, $replace_post, $content);

    // In BookingStore::createBooking: pass time and time_slot
    $target_booking = "'check_in' => \$submitted_check_in,\n            'check_out' => \$submitted_check_out,\n            'time_slot' => \"{\$calc_nights} Night(s) Stay\",";
    $target_booking_crlf = "'check_in' => \$submitted_check_in,\r\n            'check_out' => \$submitted_check_out,\r\n            'time_slot' => \"{\$calc_nights} Night(s) Stay\",";

    $replace_booking = "\$format_in_time = date('g:i A', strtotime(\$submitted_check_in_time ?: '14:00'));
        \$format_out_time = date('g:i A', strtotime(\$submitted_check_out_time ?: '12:00'));
        \$check_in_dt = \$submitted_check_in . ' ' . date('H:i:s', strtotime(\$submitted_check_in_time ?: '14:00'));
        \$check_out_dt = \$submitted_check_out . ' ' . date('H:i:s', strtotime(\$submitted_check_out_time ?: '12:00'));

        \$booking = BookingStore::createBooking([
            'id' => \$ref_no,
            'guest_name' => \$guest_name,
            'guest_email' => \$guest_email,
            'guest_phone' => \$clean_phone,
            'type' => 'house',
            'item_id' => \$kubo_id,
            'item_name' => \$item_name,
            'check_in' => \$check_in_dt,
            'check_out' => \$check_out_dt,
            'time_slot' => \"{\$calc_nights} Night(s) Stay ({\$format_in_time} - {\$format_out_time})\",";

    // Replace the createBooking call start
    $orig_create = "\$booking = BookingStore::createBooking([\n            'id' => \$ref_no,\n            'guest_name' => \$guest_name,\n            'guest_email' => \$guest_email,\n            'guest_phone' => \$clean_phone,\n            'type' => 'house',\n            'item_id' => \$kubo_id,\n            'item_name' => \$item_name,\n            'check_in' => \$submitted_check_in,\n            'check_out' => \$submitted_check_out,\n            'time_slot' => \"{\$calc_nights} Night(s) Stay\",";
    $orig_create_crlf = "\$booking = BookingStore::createBooking([\r\n            'id' => \$ref_no,\r\n            'guest_name' => \$guest_name,\r\n            'guest_email' => \$guest_email,\r\n            'guest_phone' => \$clean_phone,\r\n            'type' => 'house',\r\n            'item_id' => \$kubo_id,\r\n            'item_name' => \$item_name,\r\n            'check_in' => \$submitted_check_in,\r\n            'check_out' => \$submitted_check_out,\r\n            'time_slot' => \"{\$calc_nights} Night(s) Stay\",";

    $content = str_replace([$orig_create, $orig_create_crlf], $replace_booking, $content);

    // 2. Add time pickers under bookingCalendarPicker
    $target_picker = '<input type="hidden" name="check_out" id="inputCheckOut" value="<?= htmlspecialchars($check_out) ?>" required>' . "\n" . '                        </div>';
    $target_picker_crlf = '<input type="hidden" name="check_out" id="inputCheckOut" value="<?= htmlspecialchars($check_out) ?>" required>' . "\r\n" . '                        </div>';

    $time_picker_html = '<input type="hidden" name="check_out" id="inputCheckOut" value="<?= htmlspecialchars($check_out) ?>" required>

                            <!-- Custom Check-In & Check-Out Time Pickers -->
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem; padding: 1rem 1.15rem; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px;">
                                <div>
                                    <label style="display: flex; align-items: center; gap: 6px; font-weight: 700; font-size: 0.88rem; color: #0f172a; margin-bottom: 0.4rem; font-family: \'Outfit\', sans-serif;">
                                        <i class="fa-solid fa-clock" style="color: #7c3aed;"></i> Custom Check-In Time
                                    </label>
                                    <input type="time" name="check_in_time" id="inputCheckInTime" value="14:00" class="form-control" style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 0.55rem 0.85rem; font-weight: 700; font-family: \'Plus Jakarta Sans\', sans-serif; color: #0f172a; width: 100%;" required>
                                    <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 4px; font-family: \'Plus Jakarta Sans\', sans-serif;">
                                        <i class="fa-solid fa-circle-info"></i> Standard arrival is 2:00 PM
                                    </small>
                                </div>

                                <div>
                                    <label style="display: flex; align-items: center; gap: 6px; font-weight: 700; font-size: 0.88rem; color: #0f172a; margin-bottom: 0.4rem; font-family: \'Outfit\', sans-serif;">
                                        <i class="fa-solid fa-clock-rotate-left" style="color: #f59e0b;"></i> Custom Check-Out Time
                                    </label>
                                    <input type="time" name="check_out_time" id="inputCheckOutTime" value="12:00" class="form-control" style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 0.55rem 0.85rem; font-weight: 700; font-family: \'Plus Jakarta Sans\', sans-serif; color: #0f172a; width: 100%;" required>
                                    <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 4px; font-family: \'Plus Jakarta Sans\', sans-serif;">
                                        <i class="fa-solid fa-circle-info"></i> Standard release is 12:00 PM (Noon)
                                    </small>
                                </div>
                            </div>
                        </div>';

    $content = str_replace([$target_picker, $target_picker_crlf], $time_picker_html, $content);

    // 3. Update summary card to display live check-in and check-out times
    $target_summary_box = '<div style="background: #ffffff; border: 1px solid rgba(124, 58, 237, 0.2); border-radius: 8px; padding: 0.65rem 0.85rem; margin: 0.75rem 0; font-size: 0.82rem;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.35rem;">
                                    <span style="color: #64748b;"><i class="fa-solid fa-clock"></i> Check-In Time:</span>
                                    <strong style="color: #d97706;">2:00 PM</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: #64748b;"><i class="fa-regular fa-clock"></i> Check-Out Time:</span>
                                    <strong style="color: #059669;">12:00 PM (Noon)</strong>
                                </div>
                            </div>';
    $target_summary_box_crlf = str_replace("\n", "\r\n", $target_summary_box);

    $new_summary_box = '<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.65rem 0.85rem; margin: 0.75rem 0; font-size: 0.82rem;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.35rem;">
                                    <span style="color: #64748b;"><i class="fa-solid fa-clock" style="color: #7c3aed;"></i> Check-In Time:</span>
                                    <strong style="color: #7c3aed;" id="summaryCheckInTime">2:00 PM</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: #64748b;"><i class="fa-regular fa-clock" style="color: #f59e0b;"></i> Check-Out Time:</span>
                                    <strong style="color: #b45309;" id="summaryCheckOutTime">12:00 PM</strong>
                                </div>
                            </div>';

    $content = str_replace([$target_summary_box, $target_summary_box_crlf], $new_summary_box, $content);

    // 4. Add JS live update for times
    $target_js_end = '// Client-side guard against submitting incomplete reservations';
    $new_js = 'function formatTime12h(timeStr) {
        if (!timeStr) return "";
        const parts = timeStr.split(":");
        let hour = parseInt(parts[0], 10);
        const mins = parts[1] || "00";
        const ampm = hour >= 12 ? "PM" : "AM";
        hour = hour % 12 || 12;
        return hour + ":" + mins + " " + ampm;
    }

    const inputCheckInTime = document.getElementById("inputCheckInTime");
    const inputCheckOutTime = document.getElementById("inputCheckOutTime");
    const summaryCheckInTime = document.getElementById("summaryCheckInTime");
    const summaryCheckOutTime = document.getElementById("summaryCheckOutTime");

    if (inputCheckInTime && summaryCheckInTime) {
        inputCheckInTime.addEventListener("input", function() {
            summaryCheckInTime.textContent = formatTime12h(this.value);
        });
    }
    if (inputCheckOutTime && summaryCheckOutTime) {
        inputCheckOutTime.addEventListener("input", function() {
            summaryCheckOutTime.textContent = formatTime12h(this.value);
        });
    }

    // Client-side guard against submitting incomplete reservations';

    $content = str_replace($target_js_end, $new_js, $content);

    file_put_contents($file, $content);
    echo "Time picker added successfully to $file\n";
}
