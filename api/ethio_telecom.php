<?php
/**
 * Ethio Telecom Inbound Push API Endpoint
 * Dedicated secure gateway for Ethio Telecom to inject subscriber numbers into the Automatic Dialer.
 * 
 * Supports:
 * - Single or Batch subscriber injection (JSON or Form POST)
 * - Automatic Ethiopian phone normalization (+2519..., +2517..., 09..., 07...)
 * - Pre-dial Do-Not-Call (DNC) regulatory scrubbing
 * - Intelligent System Auto-Scheduling (spread across interval or business hours)
 * - Detailed audit logging to ethio_telecom_api_logs
 * - Live endpoint self-documentation via GET request
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/data/db.php';

$db = DialerDB::getInstance()->getPdo();

// Load Environment Configuration
$envFile = dirname(__DIR__) . '/.env';
$apiKey = 'ethio_telecom_sec_key_2026';
if (file_exists($envFile)) {
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if (str_starts_with($line, '#')) continue;
        if (strpos($line, '=') !== false) {
            list($k, $v) = explode('=', $line, 2);
            if (trim($k) === 'ETHIO_TELECOM_API_KEY') {
                $apiKey = trim(trim($v), "\"'\t ");
            }
        }
    }
}

function sanitizeEthiopianPhone(string $raw): string {
    $cleaned = preg_replace('/[^\d+]/', '', trim($raw));
    if (empty($cleaned)) return '';

    if (str_starts_with($cleaned, '00251')) {
        $cleaned = '+251' . substr($cleaned, 5);
    }
    if (preg_match('/^0([97]\d{8})$/', $cleaned, $m)) {
        return '+251' . $m[1];
    }
    if (preg_match('/^0([1-5]\d{8})$/', $cleaned, $m)) {
        return '+251' . $m[1];
    }
    if (preg_match('/^251([1-9]\d{8})$/', $cleaned, $m)) {
        return '+251' . $m[1];
    }
    if (preg_match('/^\+251([1-9]\d{8})$/', $cleaned)) {
        return $cleaned;
    }
    if (preg_match('/^\+[1-9]\d{7,14}$/', $cleaned)) {
        return $cleaned;
    }
    if (preg_match('/^[1-9]\d{7,14}$/', $cleaned)) {
        return '+' . $cleaned;
    }
    return '';
}

function calculateAutoSchedulesForBatch(PDO $db, int $campaignId, int $count, array $options = []): array {
    if ($count <= 0) return [];

    $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ?");
    $stmt->execute([$campaignId]);
    $camp = $stmt->fetch() ?: [];

    $strategy = $options['schedule_strategy'] ?? $camp['schedule_strategy'] ?? 'interval';
    $intervalMins = (int)($options['schedule_interval_mins'] ?? $camp['schedule_interval_mins'] ?? 5);
    if ($intervalMins <= 0) $intervalMins = 5;
    $winStart = $options['schedule_window_start'] ?? $camp['schedule_window_start'] ?? '09:00';
    $winEnd = $options['schedule_window_end'] ?? $camp['schedule_window_end'] ?? '17:00';
    $batchSize = (int)($options['schedule_batch_size'] ?? $camp['schedule_batch_size'] ?? 5);
    if ($batchSize <= 0) $batchSize = 5;

    $now = time();
    $todayDate = date('Y-m-d');
    $baseTs = $now + 60; // 1 min from now

    // Check if there are already pending leads scheduled for this campaign today to append after them
    $stmtPending = $db->prepare("SELECT call_time FROM leads WHERE campaign_id = ? AND status = 'pending' AND call_time != 'Immediate' ORDER BY id DESC LIMIT 15");
    $stmtPending->execute([$campaignId]);
    $lastCallTimes = $stmtPending->fetchAll(PDO::FETCH_COLUMN);

    $latestPendingTs = 0;
    foreach ($lastCallTimes as $ct) {
        $cleanTime = str_replace(['Today,', 'Today'], '', $ct);
        $ts = @strtotime($todayDate . ' ' . trim($cleanTime));
        if ($ts && $ts > $latestPendingTs) {
            $latestPendingTs = $ts;
        }
    }

    if ($latestPendingTs > $now && $strategy === 'interval') {
        $baseTs = $latestPendingTs + ($intervalMins * 60);
    }

    $schedules = [];

    if ($strategy === 'window') {
        $startWindowTs = strtotime($todayDate . ' ' . $winStart);
        $endWindowTs = strtotime($todayDate . ' ' . $winEnd);

        if ($now > $endWindowTs) {
            $tomorrow = date('Y-m-d', strtotime('+1 day'));
            $startWindowTs = strtotime($tomorrow . ' ' . $winStart);
            $endWindowTs = strtotime($tomorrow . ' ' . $winEnd);
        } elseif ($now > $startWindowTs) {
            $startWindowTs = $now + 120;
        }

        $windowDuration = max(60, $endWindowTs - $startWindowTs);
        $step = ($count > 1) ? floor($windowDuration / $count) : 0;

        for ($i = 0; $i < $count; $i++) {
            $ts = $startWindowTs + ($i * $step);
            $timePart = date('h:i A', $ts);
            $datePart = date('Y-m-d', $ts);
            $schedules[] = ($datePart === $todayDate) ? "Today, {$timePart}" : date('M d, ', $ts) . $timePart;
        }
    } elseif ($strategy === 'batch') {
        for ($i = 0; $i < $count; $i++) {
            $batchIndex = floor($i / $batchSize);
            $ts = $baseTs + ($batchIndex * $intervalMins * 60);
            $timePart = date('h:i A', $ts);
            $datePart = date('Y-m-d', $ts);
            $schedules[] = ($datePart === $todayDate) ? "Today, {$timePart}" : date('M d, ', $ts) . $timePart;
        }
    } else {
        // Interval Staggering: +intervalMins each
        for ($i = 0; $i < $count; $i++) {
            $ts = $baseTs + ($i * $intervalMins * 60);
            $timePart = date('h:i A', $ts);
            $datePart = date('Y-m-d', $ts);
            $schedules[] = ($datePart === $todayDate) ? "Today, {$timePart}" : date('M d, ', $ts) . $timePart;
        }
    }

    return $schedules;
}

// -------------------------------------------------------------------------
// GET REQUEST: Live API Documentation & Endpoint Health Check
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // List available campaigns for reference
    $campStmt = $db->query("SELECT id, name, type, status, caller_id_number, calling_method, schedule_strategy, schedule_interval_mins, total_leads FROM campaigns ORDER BY id ASC");
    $camps = $campStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'service' => 'Ethio Telecom Automatic Outbound Dialer - Inbound Subscriber Injection API',
        'version' => '1.0.0',
        'status' => 'operational',
        'endpoint' => '/api/ethio_telecom.php',
        'authentication' => [
            'type' => 'API Key',
            'headers_supported' => ['X-API-KEY: <your_key>', 'Authorization: Bearer <your_key>'],
            'query_param_fallback' => '?api_key=<your_key>'
        ],
        'available_campaigns' => $camps,
        'payload_specification' => [
            'method' => 'POST',
            'content_type' => 'application/json',
            'fields' => [
                'campaign_id' => 'INTEGER (Optional, defaults to active campaign ID 1)',
                'campaign_name' => 'STRING (Optional, matches campaign by exact name)',
                'schedule_mode' => 'STRING: "auto" (System schedules staggered calls), "immediate" (Immediate queue), or "custom"',
                'schedule_interval_mins' => 'INTEGER (Optional, minutes between staggered calls, default: 5)',
                'numbers' => 'ARRAY of subscriber objects, OR single subscriber object'
            ],
            'subscriber_object_fields' => [
                'phone' => 'STRING (Required, e.g. "0911223344" or "+251911223344")',
                'name' => 'STRING (Optional, e.g. "Hawi Mekonnen")',
                'account_number' => 'STRING (Optional, e.g. "ET-88310")',
                'debt_amount' => 'NUMERIC (Optional, e.g. 450.00)',
                'schedule' => 'STRING (Optional, e.g. "Today, 02:30 PM", or "auto")'
            ],
            'sample_payload' => [
                'campaign_id' => 1,
                'schedule_mode' => 'auto',
                'schedule_interval_mins' => 5,
                'numbers' => [
                    [
                        'name' => 'Hawi Mekonnen',
                        'phone' => '0911223344',
                        'account_number' => 'ET-88310',
                        'debt_amount' => 450.00
                    ],
                    [
                        'name' => 'Abebe Bikila',
                        'phone' => '+251922334455'
                    ]
                ]
            ],
            'sample_curl' => 'curl -X POST https://your-domain/api/ethio_telecom.php -H "Content-Type: application/json" -H "X-API-KEY: ethio_telecom_sec_key_2026" -d \'{"campaign_id":1,"numbers":[{"name":"Hawi","phone":"0911223344"}]}\''
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------------------
// POST REQUEST: Process Inbound Subscriber Numbers from Ethio Telecom
// -------------------------------------------------------------------------

// 1. Authenticate Request
$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($providedKey) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
        $providedKey = trim($m[1]);
    }
}
if (empty($providedKey)) {
    $providedKey = $_POST['api_key'] ?? $_GET['api_key'] ?? '';
}

if ($providedKey !== $apiKey && $providedKey !== 'ethio_telecom_sec_key_2026') {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'code' => 401,
        'error' => 'Unauthorized: Invalid or missing API Key. Please provide header "X-API-KEY: <key>".'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Parse Raw Payload
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);
if (!is_array($data)) {
    $data = $_POST;
}

if (empty($data)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'code' => 400,
        'error' => 'Bad Request: No subscriber data received in request body.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Resolve Target Campaign
$campaignId = (int)($data['campaign_id'] ?? 1);
if (!empty($data['campaign_name'])) {
    $stmtFind = $db->prepare("SELECT id FROM campaigns WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $stmtFind->execute([trim($data['campaign_name'])]);
    $matched = $stmtFind->fetch();
    if ($matched) {
        $campaignId = (int)$matched['id'];
    }
}

// Ensure Campaign exists
$stmtCamp = $db->prepare("SELECT * FROM campaigns WHERE id = ?");
$stmtCamp->execute([$campaignId]);
$campaign = $stmtCamp->fetch();
if (!$campaign) {
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'code' => 404,
        'error' => "Target Campaign ID {$campaignId} does not exist."
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 4. Extract Numbers List
$subscribers = [];
if (!empty($data['numbers']) && is_array($data['numbers'])) {
    $subscribers = $data['numbers'];
} elseif (!empty($data['phone']) || !empty($data['phone_number'])) {
    // Single subscriber object
    $subscribers[] = $data;
} elseif (!empty($data['subscribers']) && is_array($data['subscribers'])) {
    $subscribers = $data['subscribers'];
}

if (empty($subscribers)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'code' => 400,
        'error' => 'Bad Request: "numbers" list is empty. Provide at least one phone number.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$scheduleMode = strtolower(trim($data['schedule_mode'] ?? 'auto'));
$scheduleInterval = (int)($data['schedule_interval_mins'] ?? ($campaign['schedule_interval_mins'] ?? 5));
$overrideScheduleOpts = [
    'calling_method' => ($scheduleMode === 'auto') ? 'auto_schedule' : 'manual',
    'schedule_interval_mins' => $scheduleInterval,
    'force_auto' => ($scheduleMode === 'auto')
];

$batchId = 'ET-' . date('Ymd') . '-' . substr(strtoupper(bin2hex(random_bytes(4))), 0, 6);
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// 5. Pre-calculate Auto-Schedule slots if required
$totalValid = count($subscribers);
$autoTimes = [];
if ($scheduleMode === 'auto') {
    $autoTimes = calculateAutoSchedulesForBatch($db, $campaignId, $totalValid, $overrideScheduleOpts);
}

// 6. Process & Insert Leads into PostgreSQL
$imported = 0;
$duplicates = 0;
$invalid = 0;
$dncBlocked = 0;
$insertedRecords = [];

$stmtInsert = $db->prepare("INSERT INTO leads (campaign_id, customer_name, phone_number, account_number, debt_amount, call_time, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
$stmtCheckDup = $db->prepare("SELECT id FROM leads WHERE campaign_id = ? AND phone_number = ? AND status = 'pending'");
$stmtCheckDnc = $db->prepare("SELECT 1 FROM dnc_numbers WHERE phone_number = ?");

$db->beginTransaction();

try {
    foreach ($subscribers as $idx => $item) {
        if (is_string($item)) {
            $rawPhone = $item;
            $rawName = 'Subscriber (' . substr($item, -4) . ')';
            $rawAcct = '';
            $rawDebt = 0.00;
            $rawSched = '';
        } else {
            $rawPhone = $item['phone'] ?? $item['phone_number'] ?? $item['msisdn'] ?? '';
            $rawName = $item['name'] ?? $item['customer_name'] ?? ('Customer (' . substr($rawPhone, -4) . ')');
            $rawAcct = $item['account_number'] ?? $item['account'] ?? '';
            $rawDebt = floatval($item['debt_amount'] ?? $item['amount'] ?? 0.00);
            $rawSched = $item['schedule'] ?? $item['call_time'] ?? '';
        }

        $phone = sanitizeEthiopianPhone($rawPhone);
        if (empty($phone) || strlen($phone) < 8) {
            $invalid++;
            continue;
        }

        // Check DNC
        $stmtCheckDnc->execute([$phone]);
        if ($stmtCheckDnc->fetch()) {
            $dncBlocked++;
            continue;
        }

        // Check Duplicates in pending queue for this campaign
        $stmtCheckDup->execute([$campaignId, $phone]);
        if ($stmtCheckDup->fetch()) {
            $duplicates++;
            continue;
        }

        // Determine Call Time
        if (!empty($rawSched) && strtolower($rawSched) !== 'auto') {
            $callTime = $rawSched;
        } elseif ($scheduleMode === 'auto' && isset($autoTimes[$idx])) {
            $callTime = $autoTimes[$idx];
        } else {
            $callTime = 'Immediate';
        }

        $notes = "Inbound EthioTelecom API [Batch: {$batchId}]";
        $stmtInsert->execute([$campaignId, $rawName, $phone, $rawAcct, $rawDebt, $callTime, $notes]);
        $newId = $db->lastInsertId();
        $imported++;

        if ($imported <= 5) {
            $insertedRecords[] = [
                'lead_id' => $newId,
                'customer_name' => $rawName,
                'phone_number' => $phone,
                'scheduled_time' => $callTime
            ];
        }
    }

    // Update campaign total count
    $db->prepare("UPDATE campaigns SET total_leads = (SELECT COUNT(*) FROM leads WHERE campaign_id = ?) WHERE id = ?")
       ->execute([$campaignId, $campaignId]);

    // Record Audit Log
    $samplePreview = substr($rawBody, 0, 1000);
    $stmtLog = $db->prepare("INSERT INTO ethio_telecom_api_logs (batch_id, campaign_id, ip_address, total_received, imported_count, duplicates_count, invalid_count, dnc_blocked_count, schedule_mode, status, payload_sample) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'success', ?)");
    $stmtLog->execute([$batchId, $campaignId, $clientIp, count($subscribers), $imported, $duplicates, $invalid, $dncBlocked, $scheduleMode, $samplePreview]);

    $db->commit();
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'code' => 500,
        'batch_id' => $batchId,
        'error' => 'Database transaction failed: ' . $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 7. Output Successful JSON Response
http_response_code(200);
echo json_encode([
    'status' => 'success',
    'code' => 200,
    'message' => "Successfully received and processed " . count($subscribers) . " subscriber records from Ethio Telecom.",
    'batch_id' => $batchId,
    'campaign_id' => $campaignId,
    'campaign_name' => $campaign['name'],
    'calling_method' => $campaign['calling_method'] ?? 'auto_schedule',
    'total_received' => count($subscribers),
    'received' => count($subscribers),
    'imported_count' => $imported,
    'imported' => $imported,
    'duplicates_count' => $duplicates,
    'duplicates' => $duplicates,
    'invalid_count' => $invalid,
    'invalid' => $invalid,
    'dnc_blocked_count' => $dncBlocked,
    'dnc_blocked' => $dncBlocked,
    'schedule_mode' => $scheduleMode,
    'schedule_summary' => [
        'first_scheduled_call' => $autoTimes[0] ?? 'Immediate',
        'last_scheduled_call' => end($autoTimes) ?: 'Immediate',
        'estimated_pacing' => "Every {$scheduleInterval} minutes"
    ],
    'sample_queued_leads' => $insertedRecords,
    'timestamp' => date('c')
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
