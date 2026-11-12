<?php
session_start();
$_SESSION['role'] = 'admin';
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'Admin Manager';

ob_start();
include __DIR__ . '/../browse_activities.php';
$html = ob_get_clean();

echo "HTML length: " . strlen($html) . "\n";
assert(strpos($html, 'id="addActivityModal"') !== false, "Must contain addActivityModal");
assert(strpos($html, 'id="editActivityModal"') !== false, "Must contain editActivityModal");
assert(strpos($html, 'Availability &amp; Equipment') !== false, "Must contain Availability & Equipment header");
assert(strpos($html, 'name="total_units"') !== false, "Must contain total_units field");
assert(strpos($html, 'name="available_units"') !== false, "Must contain available_units field");
assert(strpos($html, 'name="equipment"') !== false, "Must contain equipment field");
assert(strpos($html, 'Save &amp; Add Activity') !== false, "Must contain Save & Add Activity button");
assert(strpos($html, 'Save &amp; Update Activity') !== false, "Must contain Save & Update Activity button");
assert(strpos($html, 'btn-modal-cancel-white') !== false, "Must contain white cancel button");

echo "ALL ADMIN HTML ASSERTIONS PASSED!\n";
?>
