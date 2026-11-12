<?php
require_once __DIR__ . '/../database.php';

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$liveSchema = [];
foreach ($tables as $t) {
    $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    $liveSchema[$t] = array_column($cols, 'Field');
}

$files = glob(__DIR__ . '/../*.php');
$foundErrors = [];

foreach ($files as $file) {
    $fname = basename($file);
    if (in_array($fname, ['test_db.php'])) continue;

    $content = file_get_contents($file);

    // Find all SQL query strings in PDO prepare, query, exec
    // Matches $pdo->prepare("..."), $pdo->query("..."), $pdo->exec("...")
    if (preg_match_all('/\$pdo\s*->\s*(?:prepare|query|exec)\s*\(\s*(["\'])(.*?)\1\s*\)/is', $content, $matches)) {
        foreach ($matches[2] as $sql) {
            checkSql($sql, $fname, $liveSchema, $foundErrors);
        }
    }

    // Also multiline strings or variables passed to prepare/query
    if (preg_match_all('/\$sql\s*=\s*(["\'])(.*?)\1\s*;/is', $content, $matches)) {
        foreach ($matches[2] as $sql) {
            checkSql($sql, $fname, $liveSchema, $foundErrors);
        }
    }
}

function checkSql($sql, $fname, $liveSchema, &$foundErrors) {
    $sql = trim($sql);
    // Ignore non-sql or empty
    if (!preg_match('/^(SELECT|INSERT|UPDATE|DELETE|ALTER|CREATE|DROP)\b/i', $sql)) {
        return;
    }

    // Check table names
    // 1. SELECT ... FROM table [JOIN table2]
    if (preg_match('/FROM\s+([a-zA-Z0-9_`]+)/i', $sql, $m)) {
        $tbl = trim($m[1], '`');
        if (!isset($liveSchema[$tbl]) && !in_array(strtolower($tbl), ['information_schema'])) {
            $foundErrors[] = "File: $fname | Table '$tbl' in FROM does not exist in DB! Query: " . substr($sql, 0, 80);
        }
    }
    if (preg_match_all('/JOIN\s+([a-zA-Z0-9_`]+)/i', $sql, $jm)) {
        foreach ($jm[1] as $jtbl) {
            $tbl = trim($jtbl, '`');
            if (!isset($liveSchema[$tbl])) {
                $foundErrors[] = "File: $fname | Table '$tbl' in JOIN does not exist in DB! Query: " . substr($sql, 0, 80);
            }
        }
    }
    // 2. INSERT INTO table (cols)
    if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+([a-zA-Z0-9_`]+)\s*\((.*?)\)/i', $sql, $m)) {
        $tbl = trim($m[1], '`');
        if (!isset($liveSchema[$tbl])) {
            $foundErrors[] = "File: $fname | Table '$tbl' in INSERT does not exist in DB!";
        } else {
            $cols = array_map(function($c) { return trim($c, " `\t\n\r"); }, explode(',', $m[2]));
            foreach ($cols as $c) {
                if ($c && !in_array($c, $liveSchema[$tbl])) {
                    $foundErrors[] = "File: $fname | Table '$tbl' in INSERT missing column '$c'!";
                }
            }
        }
    }
    // 3. UPDATE table SET col1 = ...
    if (preg_match('/UPDATE\s+([a-zA-Z0-9_`]+)\s+SET\s+(.*?)(?:WHERE|$)/is', $sql, $m)) {
        $tbl = trim($m[1], '`');
        if (!isset($liveSchema[$tbl])) {
            $foundErrors[] = "File: $fname | Table '$tbl' in UPDATE does not exist in DB!";
        } else {
            $setStr = $m[2];
            preg_match_all('/([a-zA-Z0-9_`]+)\s*=/i', $setStr, $colMatches);
            foreach ($colMatches[1] as $c) {
                $c = trim($c, '`');
                if (in_array(strtoupper($c), ['CASE', 'WHEN', 'THEN', 'ELSE', 'END'])) continue;
                if ($c && !in_array($c, $liveSchema[$tbl])) {
                    $foundErrors[] = "File: $fname | Table '$tbl' in UPDATE missing column '$c'!";
                }
            }
        }
    }
}

echo "Found errors:\n";
$uniqueErrors = array_unique($foundErrors);
foreach ($uniqueErrors as $err) {
    echo " - $err\n";
}
if (empty($uniqueErrors)) {
    echo "No errors found!\n";
}
