<?php
require_once __DIR__ . '/../data/db.php';

$db = DialerDB::getInstance()->getPdo();

echo "Checking campaigns table columns in PostgreSQL...\n";
$stmt = $db->query("SELECT column_name, data_type, column_default FROM information_schema.columns WHERE table_name = 'campaigns' ORDER BY ordinal_position");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    if (str_starts_with($c['column_name'], 'schedule') || in_array($c['column_name'], ['calling_method', 'name', 'status', 'type'])) {
        echo " - {$c['column_name']} ({$c['data_type']}) default: {$c['column_default']}\n";
    }
}

echo "\nChecking ethio_telecom_api_logs table...\n";
$stmtLogs = $db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'ethio_telecom_api_logs' ORDER BY ordinal_position");
$logCols = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
echo "ethio_telecom_api_logs columns count: " . count($logCols) . "\n";
foreach ($logCols as $c) {
    echo " - {$c['column_name']} ({$c['data_type']})\n";
}
