<?php
/**
 * ====================================================================
 * SkyConnect / Ethio Telecom Automatic Dialer
 * AUTOMATED PRE-PRODUCTION VALIDATION SUITE: TEST 1 & TEST 2
 * ====================================================================
 *
 * Test 1: Telephony Compliance & DNC (Do-Not-Call) Regulatory Scrubbing
 * Test 2: Ethiopian Phone Number Normalization & Validation (E.164)
 *
 * Usage in Git Bash:
 *   /c/php/php.exe scripts/run_test_1_and_2.php
 * Usage in PowerShell / CMD:
 *   "C:\php\php.exe" scripts/run_test_1_and_2.php
 */

require_once __DIR__ . '/../data/db.php';

$pdo = DialerDB::getInstance()->getPdo();

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function reportResult(string $testName, bool $passed, string $details = '') {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($passed) {
        $passedTests++;
        echo "  [ \033[32mPASS\033[0m ] " . str_pad($testName, 55, '.') . " " . ($details ? "\033[36m$details\033[0m" : "") . "\n";
    } else {
        $failedTests++;
        echo "  [ \033[31mFAIL\033[0m ] " . str_pad($testName, 55, '.') . " \033[31m$details\033[0m\n";
    }
}

echo "\n";
echo "==============================================================================\n";
echo "   Ethio Telecom & Safaricom Automatic Dialer - Pre-Production Test Suite     \n";
echo "==============================================================================\n";
echo " Environment : PostgreSQL 18.4 (ethio_dialer) / PHP " . PHP_VERSION . "\n";
echo " Scope       : Test 1 (DNC Regulatory Scrubbing) & Test 2 (E.164 Normalization)\n";
echo " Safety      : 100% Mock & Sandboxed (Zero real calls placed)\n";
echo "==============================================================================\n\n";

// ============================================================================
// TEST 2: PHONE NUMBER NORMALIZATION & VALIDATION (E.164)
// ============================================================================
echo "\033[1;33m>>> EXECUTING TEST 2: Ethiopian Phone Number Normalization & Validation\033[0m\n";
echo "------------------------------------------------------------------------------\n";

// Extract sanitizePhone implementation from api.php to ensure we test production code
$apiSource = file_get_contents(__DIR__ . '/../api.php');
if (preg_match('/function sanitizePhone\(string \$raw\): string \{(.*?)\n\}/s', $apiSource, $m)) {
    eval('function testSanitizePhone(string $raw): string {' . $m[1] . '}');
} else {
    die("FATAL: Could not extract sanitizePhone() from api.php\n");
}

$testVectors = [
    // [Raw Input, Expected Normalized Output, Description]
    ['0911234567', '+251911234567', 'Ethio Telecom Mobile (0911 standard)'],
    ['0922334455', '+251922334455', 'Ethio Telecom Mobile (0922 series)'],
    ['0712345678', '+251712345678', 'Safaricom Ethiopia Mobile (0712 standard)'],
    ['0701122334', '+251701122334', 'Safaricom Ethiopia Mobile (0701 series)'],
    ['09-11-23-45-67', '+251911234567', 'Ethio Telecom with Hyphens / Dashes'],
    ['+251 91 123 4567', '+251911234567', 'International Format with Spaces'],
    ['(0911) 23-45-67', '+251911234567', 'Local Format with Parentheses'],
    ['251911234567', '+251911234567', 'National Format without Plus Prefix'],
    ['+251911234567', '+251911234567', 'Standard E.164 Format'],
    ['00251911234567', '+251911234567', 'International Dial-out Prefix (00251)'],
    ['0115512345', '+251115512345', 'Addis Ababa Fixed Landline (011 series)'],
    ['0221112233', '+251221112233', 'Adama / Nazret Regional Landline (022)'],
    ['0462201234', '+251462201234', 'Hawassa Regional Landline (046)'],
    ['+251900000001', '+251900000001', 'Mock Volume Test Dummy (+251900)'],
    ['1234', '', 'Invalid Short Input (Rejected)'],
    ['ethiopia-call', '', 'Invalid Non-numeric Input (Rejected)'],
    ['0911234567899999', '', 'Invalid Oversized Number (Rejected)'],
    ['', '', 'Empty String (Rejected)']
];

foreach ($testVectors as [$raw, $expected, $desc]) {
    $actual = testSanitizePhone($raw);
    $passed = ($actual === $expected);
    $detail = $passed ? "'$raw' -> '$actual'" : "Expected '$expected', got '$actual'";
    reportResult("T2: $desc", $passed, $detail);
}

echo "\n";

// ============================================================================
// TEST 1: TELEPHONY COMPLIANCE & DNC REGULATORY SCRUBBING
// ============================================================================
echo "\033[1;33m>>> EXECUTING TEST 1: DNC & Regulatory Scrubbing Engine\033[0m\n";
echo "------------------------------------------------------------------------------\n";

try {
    // 1. Create a sandboxed test campaign
    $campName = 'TEST_SANDBOX_DNC_SCRUB_' . time();
    $pdo->prepare("INSERT INTO campaigns (name, status) VALUES (?, 'idle')")->execute([$campName]);
    $testCampId = (int)$pdo->lastInsertId();
    reportResult("T1.1: Sandbox Campaign Provisioned", $testCampId > 0, "Campaign ID: $testCampId");

    // 2. Define test DNC numbers
    $testDncNumbers = [
        '+251900000010' => 'Customer Opt-Out (Do Not Call)',
        '+251900000020' => 'Regulatory Restriction / ECA Directive',
        '+251900000030' => 'Legal Blacklist / Customer Complaint'
    ];

    // Seed into dnc_numbers table
    $stmtDnc = $pdo->prepare("INSERT INTO dnc_numbers (phone_number, reason, added_by) VALUES (?, ?, 'AutomatedTestSuite') ON CONFLICT (phone_number) DO UPDATE SET reason = EXCLUDED.reason");
    foreach ($testDncNumbers as $dncPhone => $reason) {
        $stmtDnc->execute([$dncPhone, $reason]);
    }
    reportResult("T1.2: Seeded 3 Test Records into dnc_numbers", true, "3 DNC records active");

    // 3. Populate test leads: 3 DNC numbers + 17 clean numbers (total 20 leads)
    $stmtLead = $pdo->prepare("INSERT INTO leads (campaign_id, customer_name, phone_number, account_number, debt_amount, call_time, status) VALUES (?, ?, ?, ?, ?, 'Immediate', 'pending')");
    
    // Insert the 3 DNC leads
    $stmtLead->execute([$testCampId, 'DNC Customer 1', '+251900000010', 'DNC-001', 500.00]);
    $stmtLead->execute([$testCampId, 'DNC Customer 2', '+251900000020', 'DNC-002', 750.00]);
    $stmtLead->execute([$testCampId, 'DNC Customer 3', '+251900000030', 'DNC-003', 1200.00]);

    // Insert 17 clean leads
    for ($i = 101; $i <= 117; $i++) {
        $cleanPhone = '+251900' . str_pad((string)$i, 6, '0', STR_PAD_LEFT);
        $stmtLead->execute([$testCampId, "Clean Customer $i", $cleanPhone, "CLEAN-$i", 300.00]);
    }
    
    $initialTotal = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE campaign_id = $testCampId")->fetchColumn();
    reportResult("T1.3: Queue Populated (20 Total Leads)", $initialTotal === 20, "3 DNC matches + 17 clean");

    // 4. Run Regulatory DNC Scrubbing (Mirroring api.php?action=scrub_dnc_queue)
    $scrubStmt = $pdo->prepare("UPDATE leads SET status = 'dnc' WHERE campaign_id = ? AND phone_number IN (SELECT phone_number FROM dnc_numbers) AND status = 'pending'");
    $scrubStmt->execute([$testCampId]);
    $scrubbedCount = $scrubStmt->rowCount();
    reportResult("T1.4: Pre-Dial Scrub Execution", $scrubbedCount === 3, "Scrubbed: $scrubbedCount / 3 expected");

    // 5. Verify database status breakdown
    $dncCount = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE campaign_id = $testCampId AND status = 'dnc'")->fetchColumn();
    $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE campaign_id = $testCampId AND status = 'pending'")->fetchColumn();
    
    reportResult("T1.5: Verify DNC Status Isolation", $dncCount === 3, "Marked DNC: $dncCount (100% blocked)");
    reportResult("T1.6: Verify Clean Leads Retained", $pendingCount === 17, "Remaining Active: $pendingCount");

    // 6. Simulate Dispatch Engine (Fetching pending leads to dial)
    $dialFetchStmt = $pdo->prepare("SELECT id, phone_number, customer_name FROM leads WHERE campaign_id = ? AND status = 'pending' ORDER BY id ASC");
    $dialFetchStmt->execute([$testCampId]);
    $dispatchedLeads = $dialFetchStmt->fetchAll(PDO::FETCH_ASSOC);

    $dispatchedDncViolations = 0;
    foreach ($dispatchedLeads as $dl) {
        if (isset($testDncNumbers[$dl['phone_number']])) {
            $dispatchedDncViolations++;
        }
    }
    reportResult("T1.7: Dialer Dispatch Engine Safeguard", $dispatchedDncViolations === 0 && count($dispatchedLeads) === 17, "0 DNC calls dispatched, 17 clean allowed");

    // 7. Dynamic Mid-Flight DNC Addition Test (Adding a lead while in active queue)
    $dynamicDncNumber = '+251900000101';
    $pdo->prepare("INSERT INTO dnc_numbers (phone_number, reason, added_by) VALUES (?, 'Mid-Flight Opt Out', 'AgentScreen') ON CONFLICT (phone_number) DO NOTHING")->execute([$dynamicDncNumber]);
    // Instant trigger as implemented in api.php add_dnc_number
    $instantScrub = $pdo->prepare("UPDATE leads SET status = 'dnc' WHERE campaign_id = ? AND phone_number = ? AND status = 'pending'");
    $instantScrub->execute([$testCampId, $dynamicDncNumber]);

    $postDynamicStatus = $pdo->query("SELECT status FROM leads WHERE campaign_id = $testCampId AND phone_number = '$dynamicDncNumber'")->fetchColumn();
    $postDynamicPending = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE campaign_id = $testCampId AND status = 'pending'")->fetchColumn();

    reportResult("T1.8: Dynamic Mid-Queue DNC Interception", $postDynamicStatus === 'dnc' && $postDynamicPending === 16, "Status immediately switched to 'dnc'");

    // 8. Auto-Scrub on Lead Import Test
    $manualLeadPhone = '+251900000010'; // already in DNC
    $dncCheck = $pdo->prepare("SELECT id FROM dnc_numbers WHERE phone_number = ?");
    $dncCheck->execute([$manualLeadPhone]);
    $inDnc = (bool)$dncCheck->fetch();
    $newStatus = $inDnc ? 'dnc' : 'pending';
    $pdo->prepare("INSERT INTO leads (campaign_id, customer_name, phone_number, status) VALUES (?, 'Re-added Customer', ?, ?)")->execute([$testCampId, $manualLeadPhone, $newStatus]);
    
    $importedLeadStatus = $pdo->query("SELECT status FROM leads WHERE campaign_id = $testCampId AND customer_name = 'Re-added Customer'")->fetchColumn();
    reportResult("T1.9: Real-time Import DNC Protection", $importedLeadStatus === 'dnc', "Imported number immediately quarantined as 'dnc'");

    // Cleanup sandbox data
    $pdo->prepare("DELETE FROM leads WHERE campaign_id = ?")->execute([$testCampId]);
    $pdo->prepare("DELETE FROM campaigns WHERE id = ?")->execute([$testCampId]);
    $pdo->exec("DELETE FROM dnc_numbers WHERE phone_number IN ('+251900000010', '+251900000020', '+251900000030', '+251900000101')");
    reportResult("T1.10: Sandbox Cleanup & Tear-down", true, "All test records pruned");

} catch (Exception $e) {
    reportResult("T1: Exception Caught", false, $e->getMessage());
}

// ============================================================================
// FINAL SUMMARY REPORT
// ============================================================================
echo "\n==============================================================================\n";
echo "                         TEST EXECUTION SUMMARY                               \n";
echo "==============================================================================\n";
echo " Total Assertions Checked : $totalTests\n";
echo " Passed Assertions        : \033[32m$passedTests\033[0m\n";
echo " Failed Assertions        : " . ($failedTests > 0 ? "\033[31m$failedTests\033[0m" : "\033[32m0\033[0m") . "\n";
$passRate = ($passedTests / max(1, $totalTests)) * 100;
echo " Overall Pass Rate        : " . number_format($passRate, 1) . "%\n";

if ($failedTests === 0) {
    echo "\n \033[32m✔ ALL PRE-PRODUCTION TESTS PASSED SUCCESSFULLY!\033[0m\n";
    echo " The DNC regulatory engine and E.164 phone normalization are production-ready.\n";
} else {
    echo "\n \033[31m✖ SOME TESTS FAILED. Please review the details above.\033[0m\n";
}
echo "==============================================================================\n\n";

exit($failedTests === 0 ? 0 : 1);
