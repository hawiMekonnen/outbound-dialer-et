<?php
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Africa/Addis_Ababa');
/**
 * Ethio Telecom Automatic Dialer
 * Enterprise Dual-Driver Database Engine (PostgreSQL 16/18 Primary + SQLite Fallback)
 * Designed for High-Throughput (1,000 Outbound Calls / Minute)
 */

class DialerDB {
    private static $instance = null;
    private $pdo;
    private $driver = 'sqlite';

    private function __construct() {
        $env = $this->loadEnv(dirname(__DIR__) . '/.env');
        $driverConfig = strtolower($env['DB_DRIVER'] ?? 'pgsql');

        if ($driverConfig === 'pgsql' && extension_loaded('pdo_pgsql')) {
            $host = $env['DB_HOST'] ?? '127.0.0.1';
            $port = $env['DB_PORT'] ?? '5432';
            $dbname = $env['DB_NAME'] ?? 'ethio_dialer';
            $user = $env['DB_USER'] ?? 'postgres';
            $pass = $env['DB_PASSWORD'] ?? '';

            try {
                $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
                $this->pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => true
                ]);
                $this->driver = 'pgsql';
                $this->initPostgresSchema();
                return;
            } catch (Exception $e) {
                error_log("[EthioDialer] PostgreSQL connection error: " . $e->getMessage() . " - Falling back to SQLite.");
            }
        }

        // Fallback: Local High-Concurrency SQLite Engine
        $this->driver = 'sqlite';
        $db_dir = __DIR__ . '/../data';
        if (!is_dir($db_dir)) {
            @mkdir($db_dir, 0777, true);
        }
        $db_file = $db_dir . '/dialer.db';

        $this->pdo = new PDO("sqlite:" . $db_file);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        try {
            $this->pdo->exec("PRAGMA journal_mode = WAL;");
            $this->pdo->exec("PRAGMA synchronous = NORMAL;");
            $this->pdo->exec("PRAGMA busy_timeout = 5000;");
        } catch (Exception $e) {}

        $this->initSqliteSchema();
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    public function getDriver(): string {
        return $this->driver;
    }

    public function isPostgres(): bool {
        return $this->driver === 'pgsql';
    }

    private function loadEnv(string $path): array {
        $env = [];
        if (!file_exists($path)) return $env;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) continue;
            if (strpos($line, '=') !== false) {
                list($key, $val) = explode('=', $line, 2);
                $key = trim($key);
                $val = trim(trim($val), "\"'\t ");
                $env[$key] = $val;
            }
        }
        return $env;
    }

    private function initPostgresSchema() {
        try {
            // Check if users table exists; if not, execute data/schema_postgres.sql
            $stmt = $this->pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'campaigns'");
            if (!$stmt->fetch()) {
                $schemaFile = __DIR__ . '/schema_postgres.sql';
                if (file_exists($schemaFile)) {
                    $sql = file_get_contents($schemaFile);
                    $this->pdo->exec($sql);
                }
            }

            // Ensure backward compatibility columns exist
            $this->pdo->exec("ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS fusionpbx_ivr_path VARCHAR(255) DEFAULT '/var/lib/freeswitch/recordings/welcome_human_voice.wav'");
            $this->pdo->exec("ALTER TABLE call_logs ADD COLUMN IF NOT EXISTS call_time VARCHAR(100) DEFAULT 'Immediate'");
            $this->pdo->exec("ALTER TABLE audio_recordings ADD COLUMN IF NOT EXISTS fusionpbx_path VARCHAR(255) DEFAULT '/var/lib/freeswitch/recordings/welcome_human_voice.wav'");

            // Auto-Scheduling & System Calling Method configuration columns
            $this->pdo->exec("ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS calling_method VARCHAR(50) DEFAULT 'manual'");
            $this->pdo->exec("ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS schedule_strategy VARCHAR(50) DEFAULT 'interval'");
            $this->pdo->exec("ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS schedule_interval_mins INT DEFAULT 5");
            $this->pdo->exec("ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS schedule_window_start VARCHAR(20) DEFAULT '09:00'");
            $this->pdo->exec("ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS schedule_window_end VARCHAR(20) DEFAULT '17:00'");
            $this->pdo->exec("ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS schedule_batch_size INT DEFAULT 5");
            $this->pdo->exec("ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS schedule_start_time VARCHAR(50) DEFAULT 'now'");

            // Ethio Telecom Inbound Integration API Logs Table
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS ethio_telecom_api_logs (
                id SERIAL PRIMARY KEY,
                batch_id VARCHAR(100) NOT NULL,
                campaign_id INT,
                ip_address VARCHAR(50),
                total_received INT DEFAULT 0,
                imported_count INT DEFAULT 0,
                duplicates_count INT DEFAULT 0,
                invalid_count INT DEFAULT 0,
                dnc_blocked_count INT DEFAULT 0,
                schedule_mode VARCHAR(50) DEFAULT 'auto',
                status VARCHAR(50) DEFAULT 'success',
                error_message TEXT DEFAULT '',
                payload_sample TEXT DEFAULT '',
                created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
            )");

            // Sync all sequence values to max(id) so SERIAL primary keys never collide
            $seqTables = ['campaigns', 'leads', 'call_logs', 'users', 'scheduled_callbacks', 'dnc_numbers', 'audio_recordings', 'ethio_telecom_api_logs'];
            foreach ($seqTables as $tbl) {
                try {
                    $this->pdo->exec("SELECT setval(pg_get_serial_sequence('{$tbl}', 'id'), COALESCE((SELECT MAX(id) FROM {$tbl}), 1))");
                } catch (Exception $ex) {}
            }
        } catch (Exception $e) {
            error_log("[EthioDialer] PostgreSQL schema check warning: " . $e->getMessage());
        }
    }

    private function initSqliteSchema() {
        $queries = [
            "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                full_name TEXT NOT NULL,
                extension TEXT NOT NULL,
                role TEXT DEFAULT 'agent',
                domain TEXT DEFAULT 'client1.skykin.local',
                sip_extension TEXT DEFAULT '101',
                sip_password TEXT DEFAULT '1234',
                sip_extension2 TEXT DEFAULT '102',
                sip_password2 TEXT DEFAULT '1234',
                line2_enabled INTEGER DEFAULT 1,
                sip_server TEXT DEFAULT 'webcc.skyconnectsolutions.et',
                sip_port TEXT DEFAULT '443',
                sip_domain TEXT DEFAULT 'client1.skykin.local',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                type TEXT DEFAULT 'predictive',
                status TEXT DEFAULT 'idle',
                caller_id_name TEXT DEFAULT 'Ethio Telecom',
                caller_id_number TEXT DEFAULT '+251911002233',
                ivr_language TEXT DEFAULT 'amharic',
                ivr_message_text TEXT DEFAULT '',
                ivr_audio_name TEXT DEFAULT 'Human Welcome Recording (WAV)',
                ivr_audio_file TEXT DEFAULT 'assets/audio/welcome_human_voice.wav',
                fusionpbx_ivr_path TEXT DEFAULT '/var/lib/freeswitch/recordings/welcome_human_voice.wav',
                pacing_delay_sec INTEGER DEFAULT 2,
                max_concurrent INTEGER DEFAULT 50,
                total_leads INTEGER DEFAULT 0,
                completed_leads INTEGER DEFAULT 0,
                calling_method TEXT DEFAULT 'manual',
                schedule_strategy TEXT DEFAULT 'interval',
                schedule_interval_mins INTEGER DEFAULT 5,
                schedule_window_start TEXT DEFAULT '09:00',
                schedule_window_end TEXT DEFAULT '17:00',
                schedule_batch_size INTEGER DEFAULT 5,
                schedule_start_time TEXT DEFAULT 'now',
                created_by TEXT DEFAULT 'Agent1',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS leads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL,
                customer_name TEXT DEFAULT 'Valued Customer',
                phone_number TEXT NOT NULL,
                account_number TEXT DEFAULT '',
                debt_amount REAL DEFAULT 0.0,
                currency TEXT DEFAULT 'ETB',
                language TEXT DEFAULT 'amharic',
                call_time TEXT DEFAULT 'Immediate',
                priority INTEGER DEFAULT 1,
                notes TEXT DEFAULT '',
                status TEXT DEFAULT 'pending',
                duration_sec INTEGER DEFAULT 0,
                dtmf_response TEXT DEFAULT '',
                telebirr_reference TEXT DEFAULT '',
                retry_count INTEGER DEFAULT 0,
                last_attempt_at DATETIME,
                error_message TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS call_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                lead_id INTEGER,
                campaign_id INTEGER,
                customer_name TEXT,
                phone_number TEXT,
                call_time TEXT,
                agent_extension TEXT,
                status TEXT,
                sip_hangup_cause TEXT DEFAULT 'NORMAL_CLEARING',
                duration_sec INTEGER DEFAULT 0,
                audio_played TEXT,
                dtmf_pressed TEXT DEFAULT '',
                cost_etb REAL DEFAULT 0.0,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS scheduled_callbacks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                lead_id INTEGER,
                campaign_id INTEGER,
                customer_name TEXT NOT NULL,
                phone_number TEXT NOT NULL,
                scheduled_time DATETIME NOT NULL,
                assigned_agent TEXT DEFAULT 'Agent1',
                status TEXT DEFAULT 'pending',
                notes TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS dnc_numbers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                phone_number TEXT UNIQUE NOT NULL,
                reason TEXT DEFAULT 'Customer opt-out',
                added_by TEXT DEFAULT 'Agent1',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS audio_recordings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                filename TEXT NOT NULL,
                filepath TEXT NOT NULL,
                fusionpbx_path TEXT NOT NULL,
                duration_sec INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS ethio_telecom_api_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                batch_id TEXT NOT NULL,
                campaign_id INTEGER,
                ip_address TEXT,
                total_received INTEGER DEFAULT 0,
                imported_count INTEGER DEFAULT 0,
                duplicates_count INTEGER DEFAULT 0,
                invalid_count INTEGER DEFAULT 0,
                dnc_blocked_count INTEGER DEFAULT 0,
                schedule_mode TEXT DEFAULT 'auto',
                status TEXT DEFAULT 'success',
                error_message TEXT DEFAULT '',
                payload_sample TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )"
        ];

        foreach ($queries as $q) {
            $this->pdo->exec($q);
        }

        // Indexes
        try {
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_leads_camp_status ON leads (campaign_id, status)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_leads_phone ON leads (phone_number)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_logs_campaign ON call_logs (campaign_id)");
        } catch (Exception $e) {}

        // Seed users
        $defaultPasswordHash = password_hash('123Newadissagentone', PASSWORD_DEFAULT);
        $seedUsers = [
            ['Agent1', $defaultPasswordHash, 'Agent 1', '101', 'agent', 'client1.skykin.local', '101', '1234', '102', '1234', 1, 'webcc.skyconnectsolutions.et', '443', 'client1.skykin.local'],
            ['Agent2', $defaultPasswordHash, 'Agent 2', '102', 'agent', 'client1.skykin.local', '102', '1234', '101', '1234', 1, 'webcc.skyconnectsolutions.et', '443', 'client1.skykin.local'],
            ['admin', $defaultPasswordHash, 'Administrator', '101', 'admin', 'client1.skykin.local', '101', '1234', '102', '1234', 1, 'webcc.skyconnectsolutions.et', '443', 'client1.skykin.local']
        ];

        $insUser = $this->pdo->prepare("INSERT OR IGNORE INTO users (username, password_hash, full_name, extension, role, domain, sip_extension, sip_password, sip_extension2, sip_password2, line2_enabled, sip_server, sip_port, sip_domain) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($seedUsers as $u) {
            $insUser->execute($u);
        }
    }
}
