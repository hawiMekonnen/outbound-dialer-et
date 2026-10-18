-- ====================================================================
-- Ethio Telecom Automatic Dialer Enterprise Schema (PostgreSQL 16/18)
-- High-Throughput Outbound Engine (1,000 calls / minute)
-- ====================================================================

-- 1. Users & Agent Accounts
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    extension VARCHAR(20) NOT NULL,
    role VARCHAR(50) DEFAULT 'agent',
    domain VARCHAR(150) DEFAULT 'client1.skykin.local',
    sip_extension VARCHAR(20) DEFAULT '101',
    sip_password VARCHAR(100) DEFAULT '1234',
    sip_extension2 VARCHAR(20) DEFAULT '102',
    sip_password2 VARCHAR(100) DEFAULT '1234',
    line2_enabled SMALLINT DEFAULT 1,
    sip_server VARCHAR(150) DEFAULT 'webcc.skyconnectsolutions.et',
    sip_port VARCHAR(10) DEFAULT '443',
    sip_domain VARCHAR(150) DEFAULT 'client1.skykin.local',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 2. Campaigns Table
CREATE TABLE IF NOT EXISTS campaigns (
    id SERIAL PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    type VARCHAR(50) DEFAULT 'predictive', -- predictive, progressive, agentless, preview
    status VARCHAR(50) DEFAULT 'idle',     -- running, paused, idle, completed
    caller_id_name VARCHAR(100) DEFAULT 'Ethio Telecom',
    caller_id_number VARCHAR(50) DEFAULT '+251911002233',
    ivr_language VARCHAR(50) DEFAULT 'amharic', -- amharic, oromifa, english
    ivr_message_text TEXT DEFAULT '',
    ivr_audio_name VARCHAR(200) DEFAULT 'Human Welcome Recording (WAV)',
    ivr_audio_file VARCHAR(255) DEFAULT 'assets/audio/welcome_human_voice.wav',
    pacing_delay_sec INT DEFAULT 2,
    max_concurrent INT DEFAULT 50,
    total_leads INT DEFAULT 0,
    completed_leads INT DEFAULT 0,
    created_by VARCHAR(100) DEFAULT 'Agent1',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 3. Customer Leads Queue (Optimized for FOR UPDATE SKIP LOCKED)
CREATE TABLE IF NOT EXISTS leads (
    id BIGSERIAL PRIMARY KEY,
    campaign_id INT NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
    customer_name VARCHAR(200) DEFAULT 'Valued Customer',
    phone_number VARCHAR(50) NOT NULL,
    account_number VARCHAR(100) DEFAULT '',
    debt_amount NUMERIC(12, 2) DEFAULT 0.00,
    currency VARCHAR(10) DEFAULT 'ETB',
    language VARCHAR(50) DEFAULT 'amharic',
    call_time VARCHAR(100) DEFAULT 'Immediate',
    priority INT DEFAULT 1,
    status VARCHAR(50) DEFAULT 'pending', -- pending, calling, answered, busy, no_answer, failed, dnc
    duration_sec INT DEFAULT 0,
    dtmf_response VARCHAR(20) DEFAULT '',
    telebirr_reference VARCHAR(100) DEFAULT '',
    retry_count INT DEFAULT 0,
    max_retries INT DEFAULT 3,
    last_attempt_at TIMESTAMP WITH TIME ZONE,
    notes TEXT DEFAULT '',
    error_message TEXT DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 4. Call Detail Records (CDR) / Call Logs
CREATE TABLE IF NOT EXISTS call_logs (
    id BIGSERIAL PRIMARY KEY,
    lead_id BIGINT,
    campaign_id INT,
    customer_name VARCHAR(200),
    phone_number VARCHAR(50),
    agent_extension VARCHAR(50),
    status VARCHAR(50), -- ANSWERED, BUSY, NO_ANSWER, FAILED, VOICEMAIL
    sip_hangup_cause VARCHAR(100) DEFAULT 'NORMAL_CLEARING',
    duration_sec INT DEFAULT 0,
    audio_played VARCHAR(255) DEFAULT '',
    dtmf_pressed VARCHAR(20) DEFAULT '',
    recording_url VARCHAR(255) DEFAULT '',
    cost_etb NUMERIC(8, 2) DEFAULT 0.00,
    timestamp TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 5. Scheduled Callbacks Table
CREATE TABLE IF NOT EXISTS scheduled_callbacks (
    id SERIAL PRIMARY KEY,
    lead_id BIGINT,
    campaign_id INT,
    customer_name VARCHAR(200) NOT NULL,
    phone_number VARCHAR(50) NOT NULL,
    scheduled_time TIMESTAMP WITH TIME ZONE NOT NULL,
    assigned_agent VARCHAR(100) DEFAULT 'Agent1',
    status VARCHAR(50) DEFAULT 'pending', -- pending, completed, rescheduled, cancelled
    notes TEXT DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 6. Do Not Call (DNC) Regulatory Registry
CREATE TABLE IF NOT EXISTS dnc_numbers (
    id SERIAL PRIMARY KEY,
    phone_number VARCHAR(50) UNIQUE NOT NULL,
    reason VARCHAR(255) DEFAULT 'Customer requested opt-out',
    added_by VARCHAR(100) DEFAULT 'Agent1',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 7. Multilingual Audio Prompt Catalog
CREATE TABLE IF NOT EXISTS audio_recordings (
    id SERIAL PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    filepath VARCHAR(255) NOT NULL,
    language VARCHAR(50) DEFAULT 'amharic',
    duration_sec INT DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ====================================================================
-- High-Concurrency Performance Indexes (1,000 calls / min)
-- ====================================================================
CREATE INDEX IF NOT EXISTS idx_leads_queue ON leads (campaign_id, status, priority DESC, id ASC);
CREATE INDEX IF NOT EXISTS idx_leads_phone ON leads (phone_number);
CREATE INDEX IF NOT EXISTS idx_logs_timestamp ON call_logs (timestamp DESC);
CREATE INDEX IF NOT EXISTS idx_logs_campaign ON call_logs (campaign_id);
CREATE INDEX IF NOT EXISTS idx_callbacks_time ON scheduled_callbacks (scheduled_time, status);
CREATE INDEX IF NOT EXISTS idx_dnc_phone ON dnc_numbers (phone_number);

-- ====================================================================
-- Seed Initial Data
-- ====================================================================
INSERT INTO users (username, password_hash, full_name, extension, role, domain, sip_extension, sip_password, sip_server, sip_port, sip_domain)
VALUES 
('Agent1', '$2y$10$o4T9P5h6kRskgBvjWb.s4eYpGZlHnQvEwS0D4v0Gz1vOqEaX0rGvW', 'Agent 1', '101', 'agent', 'client1.skykin.local', '101', '1234', 'webcc.skyconnectsolutions.et', '443', 'client1.skykin.local'),
('admin', '$2y$10$o4T9P5h6kRskgBvjWb.s4eYpGZlHnQvEwS0D4v0Gz1vOqEaX0rGvW', 'Administrator', '101', 'admin', 'client1.skykin.local', '101', '1234', 'webcc.skyconnectsolutions.et', '443', 'client1.skykin.local')
ON CONFLICT (username) DO NOTHING;

INSERT INTO campaigns (id, name, type, status, caller_id_name, caller_id_number, ivr_language, ivr_message_text, total_leads, completed_leads)
VALUES 
(1, 'Bill reminder: October', 'agentless', 'running', 'Ethio Telecom', '+251911002233', 'amharic', 'ውድ ሰላም፣ የ 450 ብር የክፍያ ሂሳብዎ በጥቅምት 15 ቀን ይጠናቀቃል። በቴሌብር ለመክፈል 1ን ይጫኑ፣ ጥሪ ለመጠየቅ 2ን፣ ከደንበኞች አገልግሎት ጋር ለመነጋገር 3ን ይጫኑ።', 500, 400),
(2, 'Collections: 30+ days', 'predictive', 'running', 'Ethio Telecom Collections', '+251911002244', 'amharic', 'Dear Customer, your telecom account has an outstanding balance of 1,250 ETB.', 1200, 420),
(3, 'New package offer', 'agentless', 'idle', 'Ethio Telecom Broadband', '+251911002255', 'english', 'Discover the new unlimited 4G/5G Home WiFi packages with 50% discount on first month.', 850, 0)
ON CONFLICT (id) DO NOTHING;

INSERT INTO audio_recordings (name, filename, filepath, language, duration_sec)
VALUES 
('Human Welcome Recording (WAV)', 'welcome_human_voice.wav', 'assets/audio/welcome_human_voice.wav', 'amharic', 12),
('Bill Reminder Long (WAV)', '1788952093_opening_long.wav', 'assets/audio/1788952093_opening_long.wav', 'amharic', 24)
ON CONFLICT DO NOTHING;
