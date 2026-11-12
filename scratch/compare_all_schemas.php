<?php
require_once __DIR__ . '/../database.php';

echo "========================================================\n";
echo "DETAILED DATABASE & CODEBASE SCHEMA AUDIT\n";
echo "========================================================\n\n";

if (!$pdo) {
    echo "Fatal: Database connection failed.\n";
    exit(1);
}

// 1. Get live tables and their columns
$liveTables = [];
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    $liveTables[$t] = array_column($cols, 'Field');
}

echo "1. LIVE DATABASE: purple_bamboo_db\n";
echo "Total Tables: " . count($liveTables) . "\n";
foreach ($liveTables as $t => $cols) {
    echo "  - $t (" . count($cols) . " columns): " . implode(', ', $cols) . "\n\n";
}

// 2. Parse Database/purple_bamboo_db.sql for CREATE TABLE statements
$sqlDumpFile = __DIR__ . '/../Database/purple_bamboo_db.sql';
$dumpTables = [];
if (file_exists($sqlDumpFile)) {
    $content = file_get_contents($sqlDumpFile);
    preg_match_all('/CREATE TABLE [`]?(\w+)[`]?\s*\((.*?)\)\s*ENGINE/si', $content, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $tblName = $m[1];
        $body = $m[2];
        $lines = explode("\n", $body);
        $cols = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^[`]?([a-zA-Z0-9_]+)[`]?\s+([a-zA-Z]+)/', $line, $colMatch)) {
                $cName = $colMatch[1];
                $cUpper = strtoupper($cName);
                if (!in_array($cUpper, ['PRIMARY', 'KEY', 'UNIQUE', 'CONSTRAINT', 'FOREIGN', 'CHECK'])) {
                    $cols[] = $cName;
                }
            }
        }
        $dumpTables[$tblName] = $cols;
    }
}

echo "--------------------------------------------------------\n";
echo "2. COMPARISON WITH Database/purple_bamboo_db.sql\n";
echo "Tables in purple_bamboo_db.sql: " . implode(', ', array_keys($dumpTables)) . "\n\n";

$tablesInDumpNotLive = array_diff(array_keys($dumpTables), array_keys($liveTables));
if ($tablesInDumpNotLive) {
    echo "Tables in SQL dump but NOT in live DB:\n";
    foreach ($tablesInDumpNotLive as $td) {
        echo "  - $td\n";
    }
} else {
    echo "All tables from SQL dump exist in live DB.\n";
}

$tablesInLiveNotDump = array_diff(array_keys($liveTables), array_keys($dumpTables));
if ($tablesInLiveNotDump) {
    echo "Tables in live DB but NOT in SQL dump:\n";
    foreach ($tablesInLiveNotDump as $td) {
        echo "  - $td\n";
    }
}

echo "\nColumn differences for common tables:\n";
foreach ($dumpTables as $tbl => $dCols) {
    if (isset($liveTables[$tbl])) {
        $missingInLive = array_diff($dCols, $liveTables[$tbl]);
        $extraInLive = array_diff($liveTables[$tbl], $dCols);
        if ($missingInLive || $extraInLive) {
            echo "Table `$tbl`:\n";
            if ($missingInLive) echo "  - Missing in live DB: " . implode(', ', $missingInLive) . "\n";
            if ($extraInLive) echo "  - Present in live DB but not dump: " . implode(', ', $extraInLive) . "\n";
        }
    }
}

// 3. Parse schema.sql
$schemaSqlFile = __DIR__ . '/../schema.sql';
$schemaTables = [];
if (file_exists($schemaSqlFile)) {
    $content = file_get_contents($schemaSqlFile);
    preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?[`]?(\w+)[`]?\s*\((.*?)\)\s*ENGINE/si', $content, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $tblName = $m[1];
        $body = $m[2];
        $lines = explode("\n", $body);
        $cols = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^[`]?([a-zA-Z0-9_]+)[`]?\s+([a-zA-Z]+)/', $line, $colMatch)) {
                $cName = $colMatch[1];
                $cUpper = strtoupper($cName);
                if (!in_array($cUpper, ['PRIMARY', 'KEY', 'UNIQUE', 'CONSTRAINT', 'FOREIGN', 'CHECK'])) {
                    $cols[] = $cName;
                }
            }
        }
        $schemaTables[$tblName] = $cols;
    }
}

echo "\n--------------------------------------------------------\n";
echo "3. COMPARISON WITH schema.sql\n";
echo "Tables in schema.sql: " . implode(', ', array_keys($schemaTables)) . "\n\n";
$tablesInSchemaNotLive = array_diff(array_keys($schemaTables), array_keys($liveTables));
if ($tablesInSchemaNotLive) {
    echo "Tables in schema.sql but NOT in live DB:\n";
    foreach ($tablesInSchemaNotLive as $td) {
        echo "  - $td\n";
    }
} else {
    echo "All tables from schema.sql exist in live DB.\n";
}

foreach ($schemaTables as $tbl => $sCols) {
    if (isset($liveTables[$tbl])) {
        $missingInLive = array_diff($sCols, $liveTables[$tbl]);
        if ($missingInLive) {
            echo "Table `$tbl` missing columns in live DB: " . implode(', ', $missingInLive) . "\n";
        }
    }
}

// 4. SCAN ALL PHP FILES FOR SQL QUERIES AND CHECK REFERENCED COLUMNS
echo "\n--------------------------------------------------------\n";
echo "4. SCANNING PHP CODEBASE FOR SQL COLUMN USAGE...\n";

$phpFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/..'));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getRealPath();
        // Skip scratch, phpmailer, and vendor
        if (strpos($path, 'scratch') !== false || strpos($path, 'phpmailer') !== false) {
            continue;
        }
        $phpFiles[] = $path;
    }
}

echo "Scanning " . count($phpFiles) . " PHP files for queries...\n";

// Let's find patterns like:
// INSERT INTO table (col1, col2)
// UPDATE table SET col1 = ..., col2 = ...
// SELECT ... FROM table
$missingColumnsFound = [];

foreach ($phpFiles as $file) {
    $code = file_get_contents($file);
    $relPath = str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', $file);

    // INSERT INTO tbl (cols...)
    if (preg_match_all('/INSERT\s+INTO\s+[`]?([a-zA-Z0-9_]+)[`]?\s*\(([^)]+)\)/i', $code, $inserts, PREG_SET_ORDER)) {
        foreach ($inserts as $ins) {
            $tbl = $ins[1];
            $colsStr = $ins[2];
            if (isset($liveTables[$tbl])) {
                $cols = array_map(function($c) {
                    return trim($c, " \t\n\r\0\x0B`");
                }, explode(',', $colsStr));
                foreach ($cols as $col) {
                    if ($col && !in_array($col, $liveTables[$tbl])) {
                        $missingColumnsFound[] = [
                            'file' => $relPath,
                            'table' => $tbl,
                            'column' => $col,
                            'context' => 'INSERT'
                        ];
                    }
                }
            } else {
                // Table doesn't exist?
                $missingColumnsFound[] = [
                    'file' => $relPath,
                    'table' => $tbl,
                    'column' => '(TABLE MISSING)',
                    'context' => 'INSERT'
                ];
            }
        }
    }

    // UPDATE tbl SET col = ...
    if (preg_match_all('/UPDATE\s+[`]?([a-zA-Z0-9_]+)[`]?\s+SET\s+([^WHERE;]+)/i', $code, $updates, PREG_SET_ORDER)) {
        foreach ($updates as $upd) {
            $tbl = $upd[1];
            $setClause = $upd[2];
            if (isset($liveTables[$tbl])) {
                // match column =
                preg_match_all('/[`]?([a-zA-Z0-9_]+)[`]?\s*=/i', $setClause, $colMatches);
                foreach ($colMatches[1] as $col) {
                    if ($col && !in_array($col, $liveTables[$tbl])) {
                        $missingColumnsFound[] = [
                            'file' => $relPath,
                            'table' => $tbl,
                            'column' => $col,
                            'context' => 'UPDATE'
                        ];
                    }
                }
            }
        }
    }
}

if (empty($missingColumnsFound)) {
    echo "SUCCESS: No missing columns found in INSERT/UPDATE statements across the PHP codebase!\n";
} else {
    echo "WARNING: Found potential missing columns or tables in code:\n";
    foreach ($missingColumnsFound as $m) {
        echo "  - File: {$m['file']} | Table: {$m['table']} | Column: {$m['column']} ({$m['context']})\n";
    }
}

