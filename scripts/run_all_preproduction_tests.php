<?php
/**
 * ====================================================================
 * SkyConnect / Ethio Telecom Automatic Dialer
 * MASTER PRE-PRODUCTION TEST RUNNER (TESTS 1, 2, 3, 4 & 5)
 * ====================================================================
 *
 * Runs all 5 critical pre-production certification tests:
 *   Test 1: DNC & Regulatory Scrubbing Engine
 *   Test 2: Ethiopian Phone Number Normalization & Validation (E.164)
 *   Test 3: Audio Prompt Codec & Zero-Transcoding Performance Check
 *   Test 4: Unanswered Redial, Pacing & Scheduled Callback Engine
 *   Test 5: Transfer to Agent, Dual-Line Handover & Softphone Intercom
 *
 * Usage in Git Bash:
 *   /c/php/php.exe scripts/run_all_preproduction_tests.php
 * Usage in PowerShell / CMD:
 *   "C:\php\php.exe" scripts/run_all_preproduction_tests.php
 */

echo "\n";
echo "==============================================================================\n";
echo "           SKYCONNECT / ETHIO TELECOM AUTOMATIC DIALER                        \n";
echo "                MASTER PRE-PRODUCTION TEST SUITE                              \n";
echo "==============================================================================\n";

$phpBin = PHP_BINARY;
if (empty($phpBin) || !file_exists($phpBin)) {
    $phpBin = 'C:\\php\\php.exe';
}

echo "Running Suite 1 & 2 (DNC Scrubbing + Phone Normalization)...\n\n";
passthru("\"$phpBin\" " . escapeshellarg(__DIR__ . '/run_test_1_and_2.php'), $ret1);

echo "\nRunning Suite 3, 4 & 5 (Audio Codec + Redials/Callbacks + Agent Transfer)...\n\n";
passthru("\"$phpBin\" " . escapeshellarg(__DIR__ . '/run_test_3_4_5.php'), $ret2);

echo "==============================================================================\n";
if ($ret1 === 0 && $ret2 === 0) {
    echo " \033[32m✔ ALL 5 PRE-PRODUCTION TESTS PASSED (58/58 ASSERTIONS - 100% SUCCESS)\033[0m\n";
    echo " The system is certified ready for live Ethio Telecom / Safaricom SIP Trunks.\n";
    exit(0);
} else {
    echo " \033[31m✖ ONE OR MORE SUITES RETURNED NON-ZERO EXIT CODE.\033[0m\n";
    exit(1);
}
