<?php
require_once __DIR__ . '/../database.php';

$files = glob(__DIR__ . '/../*.php');
$foundSelectErrors = [];

foreach ($files as $file) {
    $fname = basename($file);
    if (in_array($fname, ['test_db.php'])) continue;

    $content = file_get_contents($file);

    // Extract all strings that look like SELECT queries
    preg_match_all('/(?:["\']|\<\<\<[A-Z0-9_\'\"]*\n)(SELECT\s+.*?\s+FROM\s+[a-zA-Z0-9_`]+.*?)(?:["\']|\n[A-Z0-9_]+;)/is', $content, $matches);

    foreach ($matches[1] as $rawSql) {
        $sql = trim($rawSql);
        // Replace PHP variables like $limit, $table, etc. if present, or ignore if heavily dynamic
        if (strpos($sql, '$') !== false) {
            // Replace common PHP string interpolations with dummy values if possible
            $sql = preg_replace('/\{\$[^}]+\}/', '1', $sql);
            $sql = preg_replace('/\$[a-zA-Z0-9_]+/', '1', $sql);
        }

        // Test running EXPLAIN
        try {
            // Replace named parameters with 1 or 'test'
            $explainSql = preg_replace('/:[a-zA-Z0-9_]+/', "'test'", $sql);
            // Replace positional parameters ? with 'test'
            $explainSql = str_replace('?', "'test'", $explainSql);

            // Clean up any double quotes or backslashes
            $explainSql = stripslashes($explainSql);

            // Only test if it's a valid SELECT
            if (stripos(trim($explainSql), 'SELECT') === 0) {
                // If it contains syntax remnants that aren't valid SQL, skip
                $stmt = $pdo->query("EXPLAIN " . $explainSql);
            }
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            // We only care about missing column or table errors:
            // 1054: Unknown column
            // 1146: Table doesn't exist
            if (strpos($msg, '1054') !== false || strpos($msg, '1146') !== false) {
                $foundSelectErrors[] = [
                    'file' => $fname,
                    'error' => $msg,
                    'sql' => substr(preg_replace('/\s+/', ' ', $sql), 0, 120)
                ];
            }
        }
    }
}

echo "=== SELECT QUERY EXPLAIN AUDIT RESULTS ===\n";
if (empty($foundSelectErrors)) {
    echo "No missing column or table errors detected in any SELECT queries!\n";
} else {
    foreach ($foundSelectErrors as $err) {
        echo "File: {$err['file']}\n";
        echo "Error: {$err['error']}\n";
        echo "SQL: {$err['sql']}\n\n";
    }
}
