<?php
require_once __DIR__ . '/../database.php';

echo "========================================================\n";
echo "CHECKING ALL ROOT PHP FILES FOR DATABASE COMPATIBILITY\n";
echo "========================================================\n\n";

if (!$pdo) {
    echo "Fatal: Could not connect to database.\n";
    exit(1);
}

// 1. Fetch all live tables and columns
$liveSchema = [];
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    $liveSchema[$t] = array_column($cols, 'Field');
}

echo "Tables in live database:\n";
foreach ($liveSchema as $t => $cols) {
    echo " - $t (" . count($cols) . " columns)\n";
}
echo "\n";

// 2. Scan only ROOT php files and includes/
$rootPhpFiles = glob(__DIR__ . '/../*.php');
$includesPhpFiles = glob(__DIR__ . '/../includes/*.php');
$allFiles = array_merge($rootPhpFiles, $includesPhpFiles);

$report = [];
$totalQueriesAnalyzed = 0;
$issues = [];

foreach ($allFiles as $file) {
    $basename = basename($file);
    if ($basename === 'test_db.php') continue;

    $content = file_get_contents($file);

    // Look for all SQL strings: $pdo->query("..."), $pdo->prepare("..."), $pdo->exec("...")
    // Also strings containing SELECT, INSERT INTO, UPDATE, DELETE FROM
    preg_match_all('/(?:["\']|\<\<\<[A-Z0-9_\'\"]*\n)(SELECT\s+.*?\s+FROM\s+[a-zA-Z0-9_`]+.*?|INSERT\s+INTO\s+[a-zA-Z0-9_`]+.*?|UPDATE\s+[a-zA-Z0-9_`]+\s+SET.*?|DELETE\s+FROM\s+[a-zA-Z0-9_`]+.*?)(?:["\']|\n[A-Z0-9_]+;)/is', $content, $queryMatches);

    foreach ($queryMatches[1] as $query) {
        $totalQueriesAnalyzed++;
        $queryClean = trim($query);

        // Check INSERT
        if (preg_match('/INSERT\s+INTO\s+[`]?([a-zA-Z0-9_]+)[`]?\s*\(([^)]+)\)/i', $queryClean, $ins)) {
            $table = $ins[1];
            $colsStr = $ins[2];
            if (!isset($liveSchema[$table])) {
                $issues[] = [
                    'file' => $basename,
                    'type' => 'Missing Table in INSERT',
                    'detail' => "Table '$table' does not exist in live DB"
                ];
            } else {
                $cols = array_map(function($c) {
                    return trim($c, " \t\n\r\0\x0B`");
                }, explode(',', $colsStr));
                foreach ($cols as $col) {
                    if ($col && !in_array($col, $liveSchema[$table])) {
                        $issues[] = [
                            'file' => $basename,
                            'type' => 'Missing Column in INSERT',
                            'detail' => "Column '$col' not in table '$table'"
                        ];
                    }
                }
            }
        }

        // Check UPDATE
        if (preg_match('/UPDATE\s+[`]?([a-zA-Z0-9_]+)[`]?\s+SET\s+(.*?)(?:WHERE|$)/is', $queryClean, $upd)) {
            $table = $upd[1];
            $setBody = $upd[2];
            if (!isset($liveSchema[$table])) {
                $issues[] = [
                    'file' => $basename,
                    'type' => 'Missing Table in UPDATE',
                    'detail' => "Table '$table' does not exist in live DB"
                ];
            } else {
                preg_match_all('/[`]?([a-zA-Z0-9_]+)[`]?\s*=/i', $setBody, $colMatches);
                foreach ($colMatches[1] as $col) {
                    // Filter out SQL keywords
                    if (in_array(strtoupper($col), ['CASE', 'WHEN', 'THEN', 'ELSE', 'END'])) continue;
                    if ($col && !in_array($col, $liveSchema[$table])) {
                        $issues[] = [
                            'file' => $basename,
                            'type' => 'Missing Column in UPDATE',
                            'detail' => "Column '$col' not in table '$table'"
                        ];
                    }
                }
            }
        }

        // Check SELECT
        if (preg_match('/SELECT\s+(.*?)\s+FROM\s+[`]?([a-zA-Z0-9_]+)[`]?/is', $queryClean, $sel)) {
            $selectCols = $sel[1];
            $table = $sel[2];
            if (!in_array(strtolower($table), ['information_schema']) && !isset($liveSchema[$table])) {
                $issues[] = [
                    'file' => $basename,
                    'type' => 'Missing Table in SELECT',
                    'detail' => "Table '$table' does not exist in live DB"
                ];
            } else if (isset($liveSchema[$table])) {
                // If it's not SELECT *, check explicit columns (ignoring complex functions like COUNT, MAX, CONCAT)
                if (trim($selectCols) !== '*') {
                    $parts = explode(',', $selectCols);
                    foreach ($parts as $p) {
                        $p = trim($p);
                        // Simple column identifier e.g. `col` or col or t.col or col AS alias
                        if (preg_match('/^(?:[a-zA-Z0-9_]+[.])?[`]?([a-zA-Z0-9_]+)[`]?(?:\s+AS\s+[a-zA-Z0-9_]+)?$/i', $p, $cm)) {
                            $c = $cm[1];
                            if (!in_array(strtoupper($c), ['DISTINCT', 'ALL']) && !in_array($c, $liveSchema[$table])) {
                                $issues[] = [
                                    'file' => $basename,
                                    'type' => 'Missing Column in SELECT',
                                    'detail' => "Column '$c' not in table '$table'"
                                ];
                            }
                        }
                    }
                }
            }
        }
    }
}

echo "Total queries analyzed: $totalQueriesAnalyzed\n";
if (empty($issues)) {
    echo "RESULT: No missing tables or columns found across all root PHP files!\n";
} else {
    echo "RESULT: Found " . count($issues) . " potential issue(s):\n";
    foreach ($issues as $issue) {
        echo "  - [{$issue['file']}] {$issue['type']}: {$issue['detail']}\n";
    }
}
