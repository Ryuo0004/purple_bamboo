<?php
session_start();
$_SESSION['role'] = 'admin';

ob_start();
include __DIR__ . '/../browse_kubos.php';
$html = ob_get_clean();

$editIds = [
    'editKuboId',
    'editExistingImage',
    'editKuboName',
    'editKuboCategory',
    'editKuboPrice',
    'editKuboCapacity',
    'editKuboAvailableUnits',
    'editKuboStatus',
    'editKuboImgPreview',
    'editKuboUrl',
    'editKuboFile',
    'editKuboBadges',
    'editKuboDesc',
    'editRenoNotes',
    'editRenoStart',
    'editRenoEnd',
    'editRenovationFields',
    'editKuboModal'
];

echo "Checking Edit IDs in browse_kubos.php HTML:\n";
foreach ($editIds as $id) {
    $found = (strpos($html, 'id="' . $id . '"') !== false);
    echo "  ID '$id': " . ($found ? "FOUND" : "MISSING!") . "\n";
}
?>
