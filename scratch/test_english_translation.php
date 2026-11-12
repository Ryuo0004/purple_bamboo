<?php
$files = [
    'payment_method.php',
    'user-dashboard.php',
    'book_house.php',
    'book_activities.php'
];

$tagalogTerms = [
    'Pumili ng Akomodasyon',
    'Petsa at Detalye',
    'Kumpirmasyon',
    'Total Halaga na Babayaran',
    'Kasama ang buwis',
    'I-submit ang GCash Proof',
    'Mga Hakbang sa Pagbabayad',
    'I-click para palakihin',
    'Litrato ng GCash',
    'Katayuan:',
    'Kailangan pong',
    'Mangyaring',
    'Matagumpay na',
    'Naisumite na ang Bayad',
    'Walang Nakitang Reserbasyon',
    'Isara'
];

$errors = 0;
foreach ($files as $file) {
    $content = file_get_contents(__DIR__ . '/../' . $file);
    foreach ($tagalogTerms as $term) {
        if (stripos($content, $term) !== false) {
            echo "  [FAIL] Found '{$term}' in {$file}\n";
            $errors++;
        }
    }
}

if ($errors === 0) {
    echo "[PASS] All specified Tagalog terms have been successfully translated to English across all target files!\n";
} else {
    echo "[FAIL] Total leftover terms found: {$errors}\n";
}
