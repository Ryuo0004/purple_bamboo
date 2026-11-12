<?php
header('Content-Type: text/plain');
$root = dirname(__DIR__);
require_once $root . '/database.php';
$map = [
  'users.json' => ['users', 'email'],
  'bookings.json' => ['bookings', 'id'],
  'booking_archive.json' => ['booking_archive', null],
  'reviews.json' => ['reviews', null],
  'sent_emails.json' => ['sent_emails', 'ref_id'],
  'kubos.json' => ['house_kubos', null],
  'login_activity.json' => ['activity_logs', null],
  'homepage_content.json' => ['site_content', null],
];
foreach ($map as $f => [$t, $key]) {
  $p = "$root/data/$f";
  $j = file_exists($p) ? (json_decode(file_get_contents($p), true) ?: []) : null;
  $jc = $j === null ? 'missing' : ($f === 'homepage_content.json' ? 1 : count($j));
  try { $mc = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(); } catch (Throwable $e) { $mc = 'ERR'; }
  $miss = '';
  if ($key && is_array($j)) {
    $n = 0;
    foreach ($j as $r) {
      $v = $r[$key] ?? null; if (!$v) continue;
      $s = $pdo->prepare("SELECT 1 FROM $t WHERE $key = ? LIMIT 1"); $s->execute([$v]);
      if (!$s->fetch()) $n++;
    }
    $miss = " | missing in MySQL: $n";
  }
  echo str_pad($f, 24) . " JSON=$jc  MySQL($t)=$mc$miss\n";
}
