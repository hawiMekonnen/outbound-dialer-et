<?php
/**
 * Test Suite: System Auto-Scheduling Engine & Ethio Telecom Push API Gateway
 */

require_once __DIR__ . '/../data/db.php';

$db = DialerDB::getInstance()->getPdo();

echo "========================================================================\n";
echo "  SkyKin Dialer: Auto-Scheduling Engine & Ethio Telecom API Test Suite  \n";
echo "========================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $desc, bool $condition, string $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [ PASS ] " . str_pad($desc, 52, '.') . " " . $detail . "\n";
    } else {
        $failCount++;
        echo "  [ FAIL ] " . str_pad($desc, 52, '.') . " " . $detail . "\n";
    }
}

// -----------------------------------------------------------------------------
// TEST 1: Campaign Creation with Auto-Scheduling Configuration
// -----------------------------------------------------------------------------
echo ">>> TEST 1: Campaign Creation & Scheduling Parameter Persistence\n";

$ch = curl_init("http://127.0.0.1:8000/api.php?action=create_campaign");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'name' => 'Auto-Schedule Enterprise Nov ' . time(),
        'type' => 'agentless',
        'calling_method' => 'auto_schedule',
        'schedule_strategy' => 'interval',
        'schedule_interval_mins' => '5',
        'schedule_window_start' => '09:00',
        'schedule_window_end' => '17:00',
        'schedule_batch_size' => '3'
    ],
    CURLOPT_RETURNTRANSFER => true
]);
$createResp = curl_exec($ch);
$createHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$createData = json_decode($createResp, true);
assertTest("Create Campaign API Returns HTTP 200", $createHttp === 200, "Code: {$createHttp}");
$testCampId = (int)($createData['campaign_id'] ?? 0);
assertTest("Campaign Created in PostgreSQL", $testCampId > 0, "Campaign ID: {$testCampId}");

// Verify campaign fields in DB
$stmtCamp = $db->prepare("SELECT calling_method, schedule_strategy, schedule_interval_mins, schedule_batch_size FROM campaigns WHERE id = ?");
$stmtCamp->execute([$testCampId]);
$campRow = $stmtCamp->fetch(PDO::FETCH_ASSOC);

assertTest("Calling Method Persisted as 'auto_schedule'", ($campRow['calling_method'] ?? '') === 'auto_schedule', $campRow['calling_method'] ?? '');
assertTest("Strategy Persisted as 'interval'", ($campRow['schedule_strategy'] ?? '') === 'interval', $campRow['schedule_strategy'] ?? '');
assertTest("Interval Persisted as 5 minutes", (int)($campRow['schedule_interval_mins'] ?? 0) === 5, "Interval: {$campRow['schedule_interval_mins']}m");

// -----------------------------------------------------------------------------
// TEST 2: Leads Import with Name & Phone Only (System Auto-Schedules Them)
// -----------------------------------------------------------------------------
echo "\n>>> TEST 2: File Upload (Name & Phone only) -> System Auto-Schedules\n";

$csvContent = "Customer Name,Phone Number\n";
$csvContent .= "Hawi Mekonnen,0911223301\n";
$csvContent .= "Tewodros Kassahun,0911223302\n";
$csvContent .= "Almaz Ayana,0911223303\n";

$tmpCsv = sys_get_temp_dir() . '/test_leads_' . time() . '.csv';
file_put_contents($tmpCsv, $csvContent);

$ch = curl_init("http://127.0.0.1:8000/api.php?action=upload_leads");
$cfile = new CURLFile($tmpCsv, 'text/csv', 'subscribers_list.csv');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'lead_file' => $cfile,
        'campaign_id' => $testCampId,
        'auto_schedule' => '1',
        'schedule_interval_mins' => '5'
    ],
    CURLOPT_RETURNTRANSFER => true
]);
$uploadResp = curl_exec($ch);
$uploadHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($tmpCsv);

$uploadData = json_decode($uploadResp, true);
assertTest("Upload Leads API Returns HTTP 200", $uploadHttp === 200, "Code: {$uploadHttp}");
assertTest("Upload Imported 3 Leads", ($uploadData['imported'] ?? 0) === 3, "Imported: " . ($uploadData['imported'] ?? 0));

// Check DB records for sequential staggered times
$stmtLeads = $db->prepare("SELECT id, customer_name, phone_number, call_time FROM leads WHERE campaign_id = ? ORDER BY id ASC");
$stmtLeads->execute([$testCampId]);
$insertedLeads = $stmtLeads->fetchAll(PDO::FETCH_ASSOC);

assertTest("Inserted Leads Count in PostgreSQL is 3", count($insertedLeads) === 3, "Found: " . count($insertedLeads));
$allAutoScheduled = true;
foreach ($insertedLeads as $l) {
    if ($l['call_time'] === 'Immediate' || empty($l['call_time'])) {
        $allAutoScheduled = false;
    }
    echo "       Lead {$l['id']}: {$l['customer_name']} ({$l['phone_number']}) -> {$l['call_time']}\n";
}
assertTest("All Leads Received Staggered Future Times", $allAutoScheduled);

// Test Manual Paste Entry (Name & Phone only)
echo "\n>>> TEST 3: Manual Write-In (Name & Phone only)\n";
$ch = curl_init("http://127.0.0.1:8000/api.php?action=save_manual_leads");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'campaign_id' => $testCampId,
        'leads_text' => "Solomon Bogale, 0911223304\nBethlehem Tilahun, 0911223305",
        'auto_schedule' => '1',
        'schedule_interval_mins' => '5'
    ],
    CURLOPT_RETURNTRANSFER => true
]);
$manualResp = curl_exec($ch);
$manualHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$manualData = json_decode($manualResp, true);
assertTest("Manual Lead Paste Returns HTTP 200", $manualHttp === 200, "Code: {$manualHttp}");
assertTest("Manual Lead Paste Imported 2 Leads", ($manualData['imported'] ?? 0) === 2, "Imported: " . ($manualData['imported'] ?? 0));

$stmtAllLeads = $db->prepare("SELECT id, customer_name, phone_number, call_time FROM leads WHERE campaign_id = ? ORDER BY id ASC");
$stmtAllLeads->execute([$testCampId]);
$allCampaignLeads = $stmtAllLeads->fetchAll(PDO::FETCH_ASSOC);
assertTest("Campaign Queue Contains Total 5 Scheduled Leads", count($allCampaignLeads) === 5, "Count: " . count($allCampaignLeads));

// -----------------------------------------------------------------------------
// TEST 4: Ethio Telecom Inbound Push API Gateway
// -----------------------------------------------------------------------------
echo "\n>>> TEST 4: Dedicated Ethio Telecom API Gateway (/api/ethio_telecom.php)\n";

// 4.1 GET Request: Live API Documentation & Schema
$ch = curl_init("http://127.0.0.1:8000/api/ethio_telecom.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$docResp = curl_exec($ch);
$docHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$docData = json_decode($docResp, true);
assertTest("Ethio Telecom API GET Returns HTTP 200", $docHttp === 200, "Code: {$docHttp}");
assertTest("Live Documentation Service Name Present", isset($docData['service']) && str_contains($docData['service'], 'Ethio Telecom'), $docData['service'] ?? '');
assertTest("API Spec Contains Available Campaigns", !empty($docData['available_campaigns']), "Campaigns: " . count($docData['available_campaigns'] ?? []));

// 4.2 POST Request: Push Subscribers Batch with Authentication & DNC Scrub
$normDnc = '+251911000333';
$db->prepare("INSERT INTO dnc_numbers (phone_number, reason) VALUES (?, 'Ethio Telecom Subscriber Opt-Out') ON CONFLICT (phone_number) DO NOTHING")->execute([$normDnc]);

$pushPayload = [
    'campaign_id' => $testCampId,
    'schedule_mode' => 'auto',
    'schedule_interval_mins' => 5,
    'numbers' => [
        [
            'name' => 'Ethio Telecom VIP 1',
            'phone' => '0911000111',
            'account_number' => 'ET-9901',
            'debt_amount' => 540.00
        ],
        [
            'name' => 'Ethio Telecom VIP 2',
            'phone' => '+251911000222',
            'account_number' => 'ET-9902',
            'debt_amount' => 1250.50
        ],
        [
            'name' => 'Ethio Telecom DNC Blocked',
            'phone' => '0911000333',
            'account_number' => 'ET-9903'
        ]
    ]
];

$ch = curl_init("http://127.0.0.1:8000/api/ethio_telecom.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-API-KEY: ethio_telecom_sec_key_2026'
    ],
    CURLOPT_POSTFIELDS => json_encode($pushPayload),
    CURLOPT_RETURNTRANSFER => true
]);
$postResp = curl_exec($ch);
$postHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$postData = json_decode($postResp, true);
assertTest("Ethio Telecom API POST Ingestion Returns HTTP 200", $postHttp === 200, "Code: {$postHttp}");
assertTest("Response Status is 'success'", ($postData['status'] ?? '') === 'success', "Status: " . ($postData['status'] ?? ''));
assertTest("Total Received is 3 Subscribers", ($postData['received'] ?? 0) === 3, "Received: " . ($postData['received'] ?? 0));
assertTest("Imported Count is 2 Active Leads", ($postData['imported'] ?? 0) === 2, "Imported: " . ($postData['imported'] ?? 0));
assertTest("DNC Regulatory Scrub Caught 1 Opted-Out Number", ($postData['dnc_blocked'] ?? 0) === 1, "DNC Blocked: " . ($postData['dnc_blocked'] ?? 0));

// 4.3 Audit Trail Logging Verification
$stmtAudit = $db->prepare("SELECT * FROM ethio_telecom_api_logs WHERE campaign_id = ? ORDER BY id DESC LIMIT 1");
$stmtAudit->execute([$testCampId]);
$auditLog = $stmtAudit->fetch(PDO::FETCH_ASSOC);

assertTest("Audit Log Record Written to ethio_telecom_api_logs", !empty($auditLog), "Log ID: " . ($auditLog['id'] ?? 'none'));
assertTest("Audit Log Recorded Batch ID", !empty($auditLog['batch_id']), "Batch: " . ($auditLog['batch_id'] ?? 'none'));
assertTest("Audit Log Recorded DNC Scrubbed Count = 1", (int)($auditLog['dnc_blocked_count'] ?? 0) === 1, "DNC: " . ($auditLog['dnc_blocked_count'] ?? 0));

// 4.4 Dual Route Verification via api.php?action=ethio_telecom_receive_leads
$ch = curl_init("http://127.0.0.1:8000/api.php?action=ethio_telecom_receive_leads");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-API-KEY: ethio_telecom_sec_key_2026'
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'campaign_id' => $testCampId,
        'numbers' => [
            ['name' => 'Dual Route Subscriber', 'phone' => '0911000999']
        ]
    ]),
    CURLOPT_RETURNTRANSFER => true
]);
$routeResp = curl_exec($ch);
$routeHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$routeData = json_decode($routeResp, true);
assertTest("Dual Route (/api.php?action=ethio_telecom_receive_leads) Ingestion Works", $routeHttp === 200 && ($routeData['imported'] ?? 0) === 1, "Imported: " . ($routeData['imported'] ?? 0));

// Cleanup test records
$db->prepare("DELETE FROM leads WHERE campaign_id = ?")->execute([$testCampId]);
$db->prepare("DELETE FROM ethio_telecom_api_logs WHERE campaign_id = ?")->execute([$testCampId]);
$db->prepare("DELETE FROM campaigns WHERE id = ?")->execute([$testCampId]);
$db->prepare("DELETE FROM dnc_numbers WHERE phone_number = ?")->execute([$normDnc]);

echo "\n========================================================================\n";
echo "                      TEST SUMMARY                                      \n";
echo "========================================================================\n";
echo " Total Assertions Checked : " . ($passCount + $failCount) . "\n";
echo " Passed Assertions        : {$passCount}\n";
echo " Failed Assertions        : {$failCount}\n";
if ($failCount === 0) {
    echo "\n ✔ ALL SYSTEM AUTO-SCHEDULING & ETHIO TELECOM API TESTS PASSED!\n";
} else {
    echo "\n ❌ SOME TESTS FAILED. PLEASE REVIEW OUTPUT.\n";
}
echo "========================================================================\n";
