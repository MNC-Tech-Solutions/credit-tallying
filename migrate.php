<?php
// migrate.php — run ONCE then delete!
// Run via terminal: C:\xampp1\php\php.exe migrate.php
set_time_limit(0);
ini_set('memory_limit', '512M');

$db = new PDO(
    'mysql:host=ghl-credits-db.cr0yeukuujnk.ap-southeast-1.rds.amazonaws.com;dbname=ghlcredits;charset=utf8mb4',
    'admin',
    'ghlcredits123',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$startTime = microtime(true);

// ── Progress bar helper ───────────────────────────────────────────────────────
function progressBar($current, $total, $label = '', $barWidth = 35) {
    $pct      = $total > 0 ? $current / $total : 0;
    $filled   = (int) round($pct * $barWidth);
    $empty    = $barWidth - $filled;
    $bar      = str_repeat('█', $filled) . str_repeat('░', $empty);
    $percent  = str_pad(number_format($pct * 100, 1), 5, ' ', STR_PAD_LEFT) . '%';
    $curr     = str_pad(number_format($current), strlen(number_format($total)), ' ', STR_PAD_LEFT);
    $labelPad = str_pad(substr($label, 0, 28), 28);
    echo "\r  {$labelPad} [{$bar}] {$percent}  {$curr} / " . number_format($total);
}

// ── Header ────────────────────────────────────────────────────────────────────
echo "┌─────────────────────────────────────────────────┐\n";
echo "│         GHL Credits — DB Migration              │\n";
echo "└─────────────────────────────────────────────────┘\n\n";

// ── Truncate transactions table (table must already exist in DBeaver) ─────────
echo "► Truncating transactions table... ";
$db->exec("TRUNCATE TABLE transactions");
echo "DONE ✓\n\n";

// ── 1. credit_limits ─────────────────────────────────────────────────────────
echo "► [1/3] Migrating total_credits.csv... ";
$file   = fopen(__DIR__ . '/total_credits.csv', 'r');
$header = fgetcsv($file);
$stmt   = $db->prepare('INSERT INTO credit_limits (location_id, wa_credits, email_credits)
                        VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE wa_credits=VALUES(wa_credits), email_credits=VALUES(email_credits)');
$count  = 0;
while (($row = fgetcsv($file)) !== false) {
    $cols   = array_map('strtolower', array_map('trim', $header));
    $locIdx = array_search('locationid', $cols);
    $waIdx  = array_search('whatsappcredits', $cols);
    if ($waIdx === false) $waIdx = array_search('totalamount', $cols);
    $emIdx  = array_search('emailcredits', $cols);
    if ($locIdx === false || empty($row[$locIdx])) continue;
    $stmt->execute([trim($row[$locIdx]), floatval($row[$waIdx] ?? 0), floatval($row[$emIdx] ?? 0)]);
    $count++;
}
fclose($file);
echo "DONE ✓  ({$count} locations)\n\n";

// ── 2. credit_topups ─────────────────────────────────────────────────────────
echo "► [2/3] Migrating topups.csv... ";
if (!file_exists(__DIR__ . '/topups.csv')) {
    echo "SKIPPED (file not found)\n\n";
} else {
    $file   = fopen(__DIR__ . '/topups.csv', 'r');
    $header = array_map('strtolower', array_map('trim', fgetcsv($file)));
    $stmt   = $db->prepare('INSERT INTO credit_topups (location_id, topup_date, wa_credits, email_credits, added_by, notes)
                            VALUES (?, ?, ?, ?, ?, ?)');
    $count  = 0;
    while (($row = fgetcsv($file)) !== false) {
        $locIdx  = array_search('locationid',     $header);
        $dateIdx = array_search('date',           $header);
        $waIdx   = array_search('whatsappamount', $header);
        $emIdx   = array_search('emailamount',    $header);
        $byIdx   = array_search('addedby',        $header);
        $noteIdx = array_search('notes',          $header);
        if ($locIdx === false || empty($row[$locIdx])) continue;
        $stmt->execute([
            trim($row[$locIdx]),
            trim($row[$dateIdx] ?? date('Y-m-d')),
            floatval($row[$waIdx]  ?? 0),
            floatval($row[$emIdx]  ?? 0),
            trim($row[$byIdx]      ?? ''),
            trim($row[$noteIdx]    ?? ''),
        ]);
        $count++;
    }
    fclose($file);
    echo "DONE ✓  ({$count} top-ups)\n\n";
}

// ── 3. Transactions ───────────────────────────────────────────────────────────
$csvFiles   = glob(__DIR__ . '/csv_files/*.csv') ?: [];
$totalFiles = count($csvFiles);

echo "► [3/3] Migrating transactions — {$totalFiles} files\n";
echo str_repeat('─', 51) . "\n";

$colMap = [
    'row_id'          => ['id', 'transaction id', 'transactionid'],
    'location_id'     => ['locationid', 'location id'],
    'location_name'   => ['locationname', 'location name'],
    'type'            => ['type', 'transaction type'],
    'description'     => ['description'],
    'message_date'    => ['messagedate', 'message date', 'activity date', 'activitydate'],
    'tx_date'         => ['date'],
    'amount'          => ['amount'],
    'balance'         => ['balance', 'wallet balance after transaction'],
    'total_balance'   => ['totalbalance', 'total balance', 'total wallet balance (including wallet credits)'],
    'credits_used'    => ['creditsused', 'credits used'],
    'original_amount' => ['originalamount', 'original amount'],
    'discount_amount' => ['discountamount', 'discount applied', 'discountapplied'],
];

$grandCount = 0;
$fileNum    = 0;
$batchSize  = 500;

foreach ($csvFiles as $filePath) {
    $fileNum++;
    $filename  = basename($filePath);
    $fileSize  = round(filesize($filePath) / 1024, 1);
    $fileStart = microtime(true);

    // Count total rows for progress bar (pure PHP, works on Windows)
    $lc = 0;
    $lf = fopen($filePath, 'r');
    while (fgets($lf) !== false) $lc++;
    fclose($lf);
    $lineCount = max(0, $lc - 1); // minus header row

    echo "\n  File {$fileNum}/{$totalFiles}: {$filename} ({$fileSize} KB, ~" . number_format($lineCount) . " rows)\n";

    $file      = fopen($filePath, 'r');
    $rawHeader = fgetcsv($file);
    if (!$rawHeader) {
        echo "  SKIPPED (empty file)\n";
        fclose($file);
        continue;
    }

    // Map CSV headers to standard keys
    $header = array_map(fn($h) => strtolower(trim($h)), $rawHeader);
    $idxMap = [];
    foreach ($colMap as $stdKey => $aliases) {
        foreach ($aliases as $alias) {
            $pos = array_search($alias, $header);
            if ($pos !== false) { $idxMap[$stdKey] = $pos; break; }
        }
    }

    if (!isset($idxMap['location_id'])) {
        echo "  SKIPPED (no location_id column found)\n";
        fclose($file);
        continue;
    }

    $stmt = $db->prepare("INSERT INTO transactions
        (row_id, location_id, location_name, type, description, message_date, tx_date,
         amount, balance, total_balance, credits_used, original_amount, discount_amount, source_file)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $fileCount = 0;
    $batch     = [];
    $lastPrint = 0;

    $db->beginTransaction(); // one transaction per file — commits as each file finishes

    while (($row = fgetcsv($file)) !== false) {
        if (empty(array_filter($row))) continue;

        $g  = fn($key) => isset($idxMap[$key], $row[$idxMap[$key]]) ? trim($row[$idxMap[$key]]) : null;
        $gf = fn($key) => isset($idxMap[$key], $row[$idxMap[$key]]) && $row[$idxMap[$key]] !== '' ? floatval($row[$idxMap[$key]]) : null;

        $batch[] = [
            $g('row_id'),         $g('location_id'),    $g('location_name'),
            $g('type'),           $g('description'),    $g('message_date'),
            $g('tx_date'),        $gf('amount'),         $gf('balance'),
            $gf('total_balance'), $gf('credits_used'),   $gf('original_amount'),
            $gf('discount_amount'), $filename,
        ];

        if (count($batch) >= $batchSize) {
            foreach ($batch as $b) $stmt->execute($b);
            $fileCount += count($batch);
            $batch      = [];

            if ($fileCount - $lastPrint >= 500) {
                progressBar($fileCount, max($lineCount, $fileCount), $filename);
                $lastPrint = $fileCount;
            }
        }
    }

    // Flush remaining rows
    if (!empty($batch)) {
        foreach ($batch as $b) $stmt->execute($b);
        $fileCount += count($batch);
    }

    fclose($file);
    $db->commit(); // ✓ rows visible in DBeaver immediately after this

    progressBar($fileCount, $fileCount, $filename);
    $elapsed     = round(microtime(true) - $fileStart, 2);
    $grandCount += $fileCount;
    echo "\n  ✓ " . number_format($fileCount) . " rows inserted in {$elapsed}s\n";
}

$totalTime = round(microtime(true) - $startTime, 2);
echo "\n" . str_repeat('─', 51) . "\n";
echo "┌─────────────────────────────────────────────────┐\n";
echo "│  ✓ MIGRATION COMPLETE                           │\n";
printf("│  Total time  : %-32s│\n", $totalTime . 's');
printf("│  Total rows  : %-32s│\n", number_format($grandCount));
echo "└─────────────────────────────────────────────────┘\n";
echo "\n  !! Delete migrate.php from your server now !!\n\n";