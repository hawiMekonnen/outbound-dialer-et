<?php
/**
 * Test Suite: Excel/CSV Schedule Extraction, Double Upload Fix, and Campaign-to-Calling Flow
 */

require_once __DIR__ . '/../data/db.php';
$db = DialerDB::getInstance()->getPdo();

$testCount = 0;
$passedCount = 0;

function assertTest($description, $condition, $details = '') {
    global $testCount, $passedCount;
    $testCount++;
    if ($condition) {
        $passedCount++;
        echo "  [ PASS ] " . str_pad($description, 60, '.') . " " . $details . "\n";
    } else {
        echo "  [ FAIL ] " . str_pad($description, 60, '.') . " " . $details . "\n";
    }
}

echo "========================================================================\n";
echo "  SkyKin Dialer: Schedule Extraction, Double Upload & Flow Verification  \n";
echo "========================================================================\n\n";

// --- TEST 1: Campaign Creation ---
echo ">>> TEST 1: Campaign Creation & Flow Initiation\n";
$campName = "Holiday Broadband " . time();
$postData = [
    'name' => $campName,
    'type' => 'agentless',
    'caller_id_name' => 'Ethio Telecom',
    'caller_id_number' => '+251911002233',
    'calling_method' => 'manual',
    'schedule_strategy' => 'interval'
];

$ch = curl_init("http://127.0.0.1:8000/api.php?action=create_campaign");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
$res = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$json = json_decode($res, true);

assertTest("Create Campaign Returns HTTP 200", $http === 200, "Code: {$http}");
assertTest("Response Success is True", !empty($json['success']), "Success: " . ($json['success'] ? 'true' : 'false'));
$newCampId = (int)($json['campaign_id'] ?? 0);
assertTest("Campaign ID Generated in PostgreSQL", $newCampId > 0, "Campaign ID: {$newCampId}");

// --- TEST 2: Upload CSV with (Name, Phone Number, Time) ---
echo "\n>>> TEST 2: File Upload with Exact Columns (Name, Phone Number, Time)\n";
$csvContent = "Name,Phone Number,Scheduled Time\n";
$csvContent .= "Hawi Mekonnen,0939777880,14:30\n";
$csvContent .= "Tewodros Kassahun,0911223344,02:45 PM\n";
$csvContent .= "Almaz Ayana,0922334455,2026-10-23 15:15\n";
$csvContent .= "Immediate Contact,0933445566,Immediate\n";

$tmpFile = tempnam(sys_get_temp_dir(), 'test_leads_') . '.csv';
file_put_contents($tmpFile, $csvContent);

$cFile = curl_file_create($tmpFile, 'text/csv', 'leads_with_schedule.csv');
$uploadData = [
    'campaign_id' => $newCampId,
    'lead_file' => $cFile,
    'auto_schedule' => '0'
];

$ch = curl_init("http://127.0.0.1:8000/api.php?action=upload_leads");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $uploadData);
$uploadRes = curl_exec($ch);
$uploadHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$uploadJson = json_decode($uploadRes, true);

assertTest("Upload Leads Returns HTTP 200", $uploadHttp === 200, "Code: {$uploadHttp}");
assertTest("Imported Exactly 4 Leads", ($uploadJson['imported'] ?? 0) === 4, "Imported: " . ($uploadJson['imported'] ?? 0));

// Check DB values for exact time preservation
$stmt = $db->prepare("SELECT customer_name, phone_number, call_time, status FROM leads WHERE campaign_id = ? ORDER BY id ASC");
$stmt->execute([$newCampId]);
$dbLeads = $stmt->fetchAll(PDO::FETCH_ASSOC);

assertTest("DB Has Exactly 4 Leads for Campaign", count($dbLeads) === 4, "Count: " . count($dbLeads));

$lead1 = $dbLeads[0] ?? [];
assertTest("Lead 1 Preserves Exact 24h Time '14:30'", ($lead1['call_time'] ?? '') === '14:30', "Time: " . ($lead1['call_time'] ?? ''));

$lead2 = $dbLeads[1] ?? [];
assertTest("Lead 2 Normalizes 12h Time to '14:45'", ($lead2['call_time'] ?? '') === '14:45', "Time: " . ($lead2['call_time'] ?? ''));

$lead3 = $dbLeads[2] ?? [];
assertTest("Lead 3 Preserves Full DateTime '2026-10-23 15:15'", ($lead3['call_time'] ?? '') === '2026-10-23 15:15', "Time: " . ($lead3['call_time'] ?? ''));

$lead4 = $dbLeads[3] ?? [];
assertTest("Lead 4 Preserves 'Immediate'", ($lead4['call_time'] ?? '') === 'Immediate', "Time: " . ($lead4['call_time'] ?? ''));

// --- TEST 3: Verification of Campaign Data API for Dialer Queue ---
echo "\n>>> TEST 3: Dialer Queue Data & Normalized Status Attributes\n";
$ch = curl_init("http://127.0.0.1:8000/api.php?action=get_campaign_data&campaign_id={$newCampId}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$qRes = curl_exec($ch);
$qJson = json_decode($qRes, true);

$queueLeads = $qJson['leads'] ?? [];
assertTest("Queue API Returns All Campaign Leads", count($queueLeads) === 4, "Found: " . count($queueLeads));
assertTest("Campaign Active Status is Set", isset($qJson['campaign']['name']), "Name: " . ($qJson['campaign']['name'] ?? ''));

@unlink($tmpFile);

echo "\n========================================================================\n";
echo "                      TEST SUMMARY                                      \n";
echo "========================================================================\n";
echo " Total Assertions Checked : {$testCount}\n";
echo " Passed Assertions        : {$passedCount}\n";
echo " Failed Assertions        : " . ($testCount - $passedCount) . "\n\n";

if ($testCount === $passedCount) {
    echo " ALL TESTS PASSED!\n";
} else {
    echo " SOME TESTS FAILED!\n";
}
echo "========================================================================\n";
