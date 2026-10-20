<?php
/**
 * SkyConnect / Ethio Telecom Automatic Outbound Dialer
 * Clean, high-performance interface with Human Voice IVR & Scheduled Call Times
 */
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
session_start();

require_once __DIR__ . '/data/db.php';
$db = DialerDB::getInstance()->getPdo();

if (!empty($_SESSION['dialer_user']['id'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int)$_SESSION['dialer_user']['id']]);
    $dbUser = $stmt->fetch();
    if ($dbUser) {
        unset($dbUser['password_hash']);
        $_SESSION['dialer_user'] = $dbUser;
    }
} elseif (!empty($_SESSION['username'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE LOWER(username) = LOWER(?)");
    $stmt->execute([$_SESSION['username']]);
    $dbUser = $stmt->fetch();
    if ($dbUser) {
        unset($dbUser['password_hash']);
        $_SESSION['dialer_user'] = $dbUser;
    }
}

if (empty($_SESSION['dialer_user'])) {
    if (empty($_SESSION['authorized'])) {
        header("Location: login.php");
        exit;
    }
}

$currentUser   = $_SESSION['dialer_user'] ?? [
    'username' => 'Agent1',
    'full_name' => 'Agent 1',
    'extension' => '101',
    'domain' => 'client1.skykin.local'
];
$raw_name      = $currentUser['full_name'] ?? $currentUser['username'] ?? 'Agent 1';
$clean_name    = trim(preg_replace('/\s*\(Abebe\)/i', '', $raw_name));
$agent_name    = htmlspecialchars(!empty($clean_name) ? $clean_name : 'Agent 1');
$domain        = htmlspecialchars($currentUser['domain'] ?? 'client1.skykin.local');
$agent_ext     = htmlspecialchars($currentUser['sip_extension'] ?? $currentUser['extension'] ?? '101');
$agent_pass    = htmlspecialchars($currentUser['sip_password'] ?? '1234');
$agent_ext2    = htmlspecialchars($currentUser['sip_extension2'] ?? '102');
$agent_pass2   = htmlspecialchars($currentUser['sip_password2'] ?? '1234');
$line2_enabled = isset($currentUser['line2_enabled']) ? (int)$currentUser['line2_enabled'] : 1;
$agent_server  = htmlspecialchars($currentUser['sip_server'] ?? 'webcc.skyconnectsolutions.et');
$agent_port    = htmlspecialchars($currentUser['sip_port'] ?? '443');
$agent_domain  = htmlspecialchars($currentUser['sip_domain'] ?? $domain);
$initials      = strtoupper(substr($currentUser['username'] ?? 'A', 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SkyConnect &bull; Ethio Telecom Outbound Automatic Dialer</title>
  <link rel="stylesheet" href="assets/css/dialer.css?v=<?= time() ?>">
</head>
<body>

  <!-- ── Left Navigation Sidebar with Brand Logo at Top ──── -->
  <div class="sc-sidebar" id="sidebarMenu">
    <!-- Brand Logo at Top of Sidebar -->
    <div class="sidebar-brand-header" onclick="switchNav('tabCampaigns', 'campaigns')">
      <div class="sidebar-brand-card">
        <img src="assets/images/skyservice_logo.png" alt="SkyService Logo" class="sidebar-brand-img">
      </div>
      <div class="sidebar-brand-titles">
        <span class="sidebar-brand-title">SkyConnect</span>
        <span class="sidebar-brand-subtitle">Ethio Telecom</span>
      </div>
    </div>

    <!-- Hidden compatibility elements to prevent JS errors -->
    <div style="display:none;">
      <span class="sip-dot" id="headerSipDot"></span>
      <span id="headerSipStatusText">Ext: <?php echo $agent_ext; ?> (Standby)</span>
      <button id="statusDropBtn" onclick="toggleStatusMenu()"></button>
      <span class="sdot ready" id="statusDot"></span>
      <span id="statusLabel">Ready</span>
      <div id="statusDropMenu"></div>
      <div id="liveClock"></div>
      <button id="sidebarToggleBtn"></button>
      <span id="headerSectionTitle"></span>
      <span id="topUrlText"></span>
    </div>

    <div class="sc-nav-list" style="padding:10px 0;">
      
      <!-- OPERATE Group -->
      <div class="sc-sidebar-section-title">OPERATE</div>

      <button class="sc-nav-item active" id="sbCampaigns" onclick="switchNav('tabCampaigns', 'campaigns')">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
          <line x1="2" y1="8" x2="4" y2="8"></line>
          <line x1="20" y1="8" x2="22" y2="8"></line>
        </svg>
        <span>Campaigns</span>
      </button>

      <button class="sc-nav-item" id="sbDialer" onclick="switchNav('tabDialer', 'dialer/agent')">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
        </svg>
        <span>Dialer</span>
      </button>

      <button class="sc-nav-item" id="sbCallbacks" onclick="switchNav('tabCallbacks', 'callbacks')">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="1 4 1 10 7 10"></polyline>
          <polyline points="23 20 23 14 17 14"></polyline>
          <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"></path>
        </svg>
        <span>Callbacks</span>
        <span class="sc-circle-badge" id="badgeUnansweredCount">3</span>
      </button>

      <button class="sc-nav-item" id="sbContacts" onclick="switchNav('tabContacts', 'contacts')">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
          <circle cx="9" cy="7" r="4"></circle>
          <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
          <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
        </svg>
        <span>Contacts</span>
      </button>

      <!-- INSIGHTS & CONTROL Group -->
      <div class="sc-sidebar-section-title" style="margin-top:16px;">INSIGHTS &amp; CONTROL</div>

      <button class="sc-nav-item" id="sbReports" onclick="switchNav('tabReports', 'reports')">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="20" x2="18" y2="10"></line>
          <line x1="12" y1="20" x2="12" y2="4"></line>
          <line x1="6" y1="20" x2="6" y2="14"></line>
        </svg>
        <span>Reports</span>
      </button>

      <button class="sc-nav-item" id="sbCompliance" onclick="switchNav('tabCompliance', 'compliance')">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
        </svg>
        <span>Compliance</span>
      </button>

      <button class="sc-nav-item" id="sbSettings" onclick="switchNav('tabSettings', 'settings')">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"></circle>
          <path d="M12 2v3m0 14v3M2 12h3m14 0h3m-3.5-6.5l-2.1 2.1m-8.8 8.8l-2.1 2.1m0-13l2.1 2.1m8.8 8.8l2.1 2.1"></path>
        </svg>
        <span>Settings</span>
      </button>

    </div>

    <!-- Bottom Sidebar Footer -->
    <div class="sc-sidebar-footer">
      <a href="javascript:void(0)" class="sb-footer-link" onclick="openSipSettingsModal()">Phone Settings</a>
      <a href="javascript:void(0)" class="sb-footer-link" onclick="openChangePasswordModal()">Change Password</a>
    </div>
  </div>

  <!-- ── SkyConnect Main Content Area ──────────────────────── -->
  <div class="sc-main-content main" id="mainContainer">

    <!-- ========================================================
         VIEW 1: CAMPAIGNS (Matching Exact Design Mockup)
         ======================================================== -->
    <div class="tab-panel active" id="tabCampaigns">
      
      <!-- ── SECTION A: CAMPAIGNS OVERVIEW (Mockup View) ── -->
      <div id="subViewCampaignsList">
        <!-- Top Header: Title, Subtitle and Refresh button -->
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;">
          <div>
            <h1 style="font-size:26px;font-weight:700;color:#0f172a;margin:0 0 5px 0;letter-spacing:-0.3px;">Campaigns</h1>
            <div style="font-size:13.5px;color:#64748b;">Create, monitor and control outbound voice campaigns.</div>
          </div>
          <div>
            <button type="button" class="btn-refresh-pill" onclick="app.loadCampaignsOverview()">Refresh</button>
          </div>
        </div>

        <!-- 4 KPI Metric Cards -->
        <div class="camp-metric-row">
          <div class="camp-metric-card">
            <div class="camp-metric-lbl">Agents ready</div>
            <div class="camp-metric-val" id="kpiAgentsReady">14</div>
          </div>
          <div class="camp-metric-card">
            <div class="camp-metric-lbl">Dialing now</div>
            <div class="camp-metric-val" id="kpiDialingNow">22</div>
          </div>
          <div class="camp-metric-card">
            <div class="camp-metric-lbl">Abandon rate &bull; target below 3%</div>
            <div class="camp-metric-val green" id="kpiAbandonRate">1.8%</div>
          </div>
          <div class="camp-metric-card">
            <div class="camp-metric-lbl">Avg talk time</div>
            <div class="camp-metric-val" id="kpiAvgTalkTime">03:40</div>
          </div>
        </div>

        <!-- Two Column Main Layout -->
        <div class="camp-layout-grid">
          
          <!-- LEFT: All campaigns (60%) -->
          <div class="camp-panel-box">
            <div class="camp-panel-heading">All campaigns</div>
            <div style="overflow-x:auto;">
              <table class="camp-table">
                <thead>
                  <tr>
                    <th style="width:34%;">CAMPAIGN</th>
                    <th style="width:16%;">TYPE</th>
                    <th style="width:18%;">STATUS</th>
                    <th style="width:20%;">PROGRESS</th>
                    <th style="width:12%;text-align:right;"></th>
                  </tr>
                </thead>
                <tbody id="campaignsOverviewTableBody">
                  <tr class="camp-table-row">
                    <td colspan="5" style="padding:24px;text-align:center;color:#64748b;">Loading campaigns from PostgreSQL...</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- RIGHT: New campaign Wizard Panel (40%) -->
          <div class="camp-panel-box">
            <div class="camp-panel-heading">New campaign</div>

            <!-- Stepper Header -->
            <div class="camp-stepper">
              <div class="camp-step-item active" id="wizardStep1" onclick="app.setWizardStep(1)">
                <span class="camp-step-circle active">1</span>
                <span class="camp-step-title active">General</span>
              </div>
              <div class="camp-step-item" id="wizardStep2" onclick="app.setWizardStep(2)">
                <span class="camp-step-circle">2</span>
                <span class="camp-step-title">Message</span>
              </div>
              <div class="camp-step-item" id="wizardStep3" onclick="app.setWizardStep(3)">
                <span class="camp-step-circle">3</span>
                <span class="camp-step-title">Telephony</span>
              </div>
              <div class="camp-step-item" id="wizardStep4" onclick="app.setWizardStep(4)">
                <span class="camp-step-circle">4</span>
                <span class="camp-step-title">Launch</span>
              </div>
            </div>

            <form id="formNewCampaignWizard" onsubmit="app.handleNewCampaignWizardSubmit(event);">
              <div class="form-group" style="margin-bottom:14px;">
                <label class="camp-input-label">Campaign name</label>
                <input type="text" id="newCampaignNameInput" class="camp-input-field" placeholder="e.g. Bill Reminder: November" required>
              </div>

              <div class="form-group" style="margin-bottom:14px;">
                <label class="camp-input-label">Type</label>
                <select id="newCampaignTypeSelect" class="camp-input-field">
                  <option value="bill_reminder" selected>Bill reminder</option>
                  <option value="collections">Collections: 30+ days</option>
                  <option value="promotions">New package offer</option>
                  <option value="broadband">Broadband Outreach: November</option>
                  <option value="survey">Customer Survey</option>
                </select>
              </div>

              <div class="form-group" style="margin-bottom:14px;">
                <label class="camp-input-label">Dialing mode</label>
                <select id="newCampaignModeSelect" class="camp-input-field">
                  <option value="agentless" selected>Agentless voice (Automated IVR)</option>
                  <option value="predictive">Predictive (Multi-line pacing)</option>
                  <option value="progressive">Progressive (1-to-1 pacing)</option>
                  <option value="preview">Preview (Agent manual click)</option>
                </select>
              </div>

              <div class="form-group" style="margin-bottom:14px;">
                <label class="camp-input-label">Caller ID</label>
                <select id="newCampaignCallerIdSelect" class="camp-input-field">
                  <option value="+251911002233" selected>+251911002233 (Customer Service)</option>
                  <option value="+251911002244">+251911002244 (Billing &amp; Collections)</option>
                  <option value="+251911002255">+251911002255 (Broadband Support)</option>
                </select>
              </div>

              <div class="form-group" style="margin-bottom:22px;">
                <label class="camp-input-label">Initial contacts</label>
                <div class="camp-browse-container">
                  <input type="text" id="newCampaignContactsInput" value="ethio_subscribers.xlsx" readonly class="camp-browse-input">
                  <button type="button" class="camp-browse-btn" onclick="app.openModal('modalUpload')">Browse</button>
                </div>
              </div>

              <button type="submit" class="camp-btn-submit">Continue to message design</button>
            </form>
          </div>
        </div>
      </div>

      <!-- ── SECTION B: MESSAGE DESIGNER & LIVE RESULTS (Secondary Screen) ── -->
      <!-- ── SECTION B: MESSAGE DESIGNER & LIVE RESULTS (Exact Match to Picture 2) ── -->
      <div id="subViewCampaignsDesigner" style="display:none;">
        
        <!-- Top Navigation Tabs (Campaigns overview | Campaign · Designer) -->
        <div class="des-nav-tabs">
          <button type="button" class="des-nav-tab" onclick="switchCampaignSubView('list')">Campaigns overview</button>
          <button type="button" class="des-nav-tab active">
            <span id="designerTabCampaignName">Bill reminder: October</span> &middot; Designer
          </button>
        </div>

        <!-- Two Column Main Layout: Message designer (Left) & Live campaign performance (Right) -->
        <div class="des-grid">
          
          <!-- LEFT CARD: Message designer -->
          <div class="des-panel">
            <div class="des-panel-header">
              <h2 class="des-panel-title">Message designer</h2>
              <span id="designerCampaignTypeBadge" class="des-badge-pill">PREDICTIVE</span>
            </div>

            <!-- IVR Audio Voice Prompt Selector -->
            <div style="margin-bottom:16px;">
              <label class="des-field-label">IVR audio voice prompt (WAV)</label>
              <select id="designerAudioPromptSelect" class="des-select-box" onchange="app.handleDesignerAudioChange(this.value)">
                <option value="assets/audio/welcome_human_voice.wav" selected>Human Welcome Recording (WAV) &middot; Amharic</option>
              </select>
            </div>

            <!-- Message Script Textarea -->
            <div style="margin-bottom:6px;">
              <label class="des-field-label">Message script</label>
              <textarea id="campaignMessageText" class="des-textarea" spellcheck="false">ውድ ደንበኛ: የ 450 ብር የክፍያ ሂሳብዎ በጥቅምት 15 ቀን ይጠናቀቃል። በቴሌብር ለመክፈል 1ን ይጫኑ: ጥሪ ለመጠየቅ 2ን: ከደንበኞች አገልግሎት ጋር ለመነጋገር 3ን ይጫኑ።</textarea>
            </div>

            <!-- Language Buttons (አማርኛ / Afaan Oromoo / English) -->
            <div class="des-lang-group">
              <button type="button" class="des-lang-btn active" id="langBtnAmharic" onclick="setDesignerLang('amharic')">አማርኛ</button>
              <button type="button" class="des-lang-btn" id="langBtnOromifa" onclick="setDesignerLang('oromifa')">Afaan Oromoo</button>
              <button type="button" class="des-lang-btn" id="langBtnEnglish" onclick="setDesignerLang('english')">English</button>
            </div>

            <!-- Toggle 1: Natural Human Audio -->
            <div class="des-toggle-row">
              <span class="des-toggle-label">Natural human audio playback via Softphone/SIP</span>
              <label class="sc-switch">
                <input type="checkbox" id="chkNaturalVoice" checked>
                <span class="sc-slider"></span>
              </label>
            </div>

            <!-- Toggle 2: Personalize with Name & Balance -->
            <div class="des-toggle-row" style="margin-bottom:24px;">
              <span class="des-toggle-label">Personalize with customer name and debt balance</span>
              <label class="sc-switch">
                <input type="checkbox" id="chkPersonalize" checked>
                <span class="sc-slider"></span>
              </label>
            </div>

            <!-- Action Buttons -->
            <div style="display:flex;gap:10px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
              <button type="button" class="des-btn-outline" onclick="playVoicePreview()">Test voice audio</button>
              <button type="button" class="des-btn-solid-green" onclick="app.saveCampaignDesigner()">Save settings</button>
              <button type="button" class="des-btn-outline" onclick="app.openAddContactsModal(app.activeCampaignId || app.campaign?.id)">Add numbers</button>
            </div>
            <div>
              <button type="button" class="des-btn-outline" onclick="app.exportCampaignContacts(app.activeCampaignId || app.campaign?.id)">Export CSV</button>
            </div>
          </div>

          <!-- RIGHT CARD: Live campaign performance -->
          <div class="des-panel">
            <div class="des-panel-header">
              <h2 class="des-panel-title">Live campaign performance</h2>
              <span id="designerCampaignStatusBadge" class="des-status-pill running">
                <span class="dot"></span>Running
              </span>
            </div>

            <!-- 5 Stat Cards in a row -->
            <div class="des-stat-grid">
              <div class="des-stat-card">
                <div class="des-stat-num" id="chipScheduled">1</div>
                <div class="des-stat-sub">Scheduled</div>
              </div>
              <div class="des-stat-card">
                <div class="des-stat-num" id="chipDialed">0</div>
                <div class="des-stat-sub">Dialed</div>
              </div>
              <div class="des-stat-card">
                <div class="des-stat-num" id="chipAnswered">0</div>
                <div class="des-stat-sub">Answered</div>
              </div>
              <div class="des-stat-card">
                <div class="des-stat-num" id="chipBusy">0</div>
                <div class="des-stat-sub">Busy</div>
              </div>
              <div class="des-stat-card">
                <div class="des-stat-num" id="chipNoAnswer">0</div>
                <div class="des-stat-sub">No answer</div>
              </div>
            </div>

            <!-- Progress Bar -->
            <div class="des-progress-row">
              <span id="designerProgressPercent" style="font-weight:600;color:#0f172a;">Progress 0%</span>
              <span id="designerProgressRatio" style="color:#64748b;font-size:12px;">0 of 1 dialed</span>
            </div>
            <div class="des-progress-bar-track">
              <div id="designerProgressBarFill" class="des-progress-bar-fill" style="width:0%;"></div>
            </div>

            <!-- Keypad IVR Actions -->
            <div class="des-ivr-section-title">Customer keypad IVR actions</div>

            <!-- Action 1: Telebirr pay -->
            <div class="des-ivr-item" onclick="app.showToast('Key 1: Automatically pushes Telebirr USSD payment prompt &amp; SMS reference to customer', 'info')">
              <div class="des-ivr-badge green">1</div>
              <div>
                <div class="des-ivr-title">Telebirr pay</div>
                <div class="des-ivr-desc">Send payment SMS push</div>
              </div>
            </div>

            <!-- Action 2: Callback -->
            <div class="des-ivr-item" onclick="switchNav('tabCallbacks', 'callbacks'); app.showToast('Key 2: Instantly schedules customer callback in queue', 'info');">
              <div class="des-ivr-badge blue">2</div>
              <div>
                <div class="des-ivr-title">Callback</div>
                <div class="des-ivr-desc">Schedule a return call</div>
              </div>
            </div>

            <!-- Action 3: Live agent -->
            <div class="des-ivr-item" onclick="switchNav('tabDialer', 'dialer/agent'); app.showToast('Key 3: Transferred to live agent on Softphone Line 1 / 2', 'success');">
              <div class="des-ivr-badge dark-green">3</div>
              <div>
                <div class="des-ivr-title">Live agent</div>
                <div class="des-ivr-desc">Transfer to the softphone queue</div>
              </div>
            </div>

            <!-- Bottom Actions -->
            <div style="display:flex;gap:12px;align-items:center;margin-top:28px;">
              <button type="button" id="btnLaunchDesignerCampaign" class="des-btn-outline" onclick="app.toggleCampaignLaunch()" style="padding:10px 22px;">
                Pause campaign
              </button>
              <button type="button" class="des-btn-solid-blue" onclick="switchNav('tabDialer', 'dialer/agent')">
                Open in live softphone queue
              </button>
            </div>
          </div>

        </div>
      </div>
    </div>


    <!-- ========================================================
         VIEW 2: DIALER (Screenshots 3 & 4)
         ======================================================== -->
    <div class="tab-panel" id="tabDialer">
      <!-- ── TOP CONTROLS CARD: Pacing Modes, Status, Sub-Views & Campaign Chooser (Mockup Match) ── -->
      <div class="dial-top-card">
        <!-- Row 1: Left Pacing & Queue Status | Right: Sub-View Selector -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
          <!-- Left: Pacing Modes & Status Pill -->
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <div class="dial-segmented">
              <button type="button" class="dial-seg-btn" id="btnPacingPreview" onclick="app.setPacingMode('preview')">Preview</button>
              <button type="button" class="dial-seg-btn" id="btnPacingProgressive" onclick="app.setPacingMode('progressive')">Progressive</button>
              <button type="button" class="dial-seg-btn active-green" id="btnPacingPredictive" onclick="app.setPacingMode('predictive')">Predictive</button>
            </div>
            
            <div class="dial-status-pill">
              <span class="dot"></span>
              <span id="agentQueueStatusText">Ready &middot; 1 lead in queue</span>
            </div>
          </div>

          <!-- Right: Sub-View Segmented Pills -->
          <div class="dial-segmented">
            <button type="button" class="dial-seg-btn active" id="subBtnDialerAgent" onclick="switchDialerSubView('agent')">Agent screen</button>
            <button type="button" class="dial-seg-btn" id="subBtnDialerPacing" onclick="switchDialerSubView('pacing')">Pacing &amp; compliance</button>
            <button type="button" class="dial-seg-btn" id="subBtnDialerQueue" onclick="switchDialerSubView('queue')">Queue &amp; lines</button>
          </div>
        </div>

        <!-- Row 2: Campaign Selector & Multiple Campaigns Switch -->
        <div class="dial-campaign-row">
          <div style="display:flex;align-items:center;gap:14px;flex:1;max-width:620px;">
            <label style="font-size:13.5px;font-weight:700;color:#0f172a;white-space:nowrap;margin:0;">Campaign</label>
            <select id="dialerCampaignDropdown" class="dial-campaign-select" onchange="app.handleDialerCampaignDropdownChange(this.value)">
              <option value="1" selected>Bill reminder: October &middot; 1 lead</option>
            </select>
          </div>

          <!-- Multi-Campaign Toggle Switch -->
          <div style="display:flex;align-items:center;gap:10px;">
            <label class="sc-switch">
              <input type="checkbox" id="chkSelectMultipleCampaigns" onchange="app.toggleMultiCampaignPanel()">
              <span class="sc-slider"></span>
            </label>
            <span style="font-size:13px;font-weight:500;color:#334155;cursor:pointer;" onclick="document.getElementById('chkSelectMultipleCampaigns').click()">Select multiple campaigns</span>
          </div>
        </div>

        <!-- Multi-Campaign Checkbox Drawer (Collapsible) -->
        <div id="multiCampaignSelectorWrap" style="display:none;margin-top:14px;padding-top:14px;border-top:1px dashed #cbd5e1;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
            <div style="font-size:12.5px;font-weight:700;color:#143522;">
              Select multiple campaigns to combine into one live outbound calling queue:
            </div>
            <div style="display:flex;gap:8px;">
              <button type="button" class="btn btn-xs btn-secondary" onclick="app.selectAllCampaignsForDialer(true)" style="font-size:11.5px;padding:4px 10px;">Select All</button>
              <button type="button" class="btn btn-xs btn-secondary" onclick="app.selectAllCampaignsForDialer(false)" style="font-size:11.5px;padding:4px 10px;">Clear All</button>
              <button type="button" class="btn btn-xs btn-success" onclick="app.applyMultiCampaignSelection()" style="font-size:11.5px;padding:4px 14px;font-weight:700;">Apply &amp; Load Queue</button>
            </div>
          </div>
          <div id="multiCampaignCheckboxesList" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));gap:10px;">
            <!-- Populated dynamically with campaign cards and checkboxes -->
          </div>
        </div>
      </div>

      <!-- ── SUB-VIEW A: AGENT SCREEN (Exact Match to Picture 2) ── -->
      <div id="subViewDialerAgent">
        <div class="dial-grid-cols">
          
          <!-- COL 1: Customer Information Card -->
          <div class="dial-col-card">
            <div class="dial-cust-name" id="agentScreenCustName">Helen Tesfaye</div>
            <div class="dial-cust-phone" id="agentScreenPhoneText">+251911456789</div>

            <div class="dial-debt-val" id="agentScreenDebtAmount">ETB 450.00</div>
            <div class="dial-debt-due" id="agentScreenDebtDue">Due 2026-10-20 11:15</div>

            <div class="dial-info-lbl">Account number</div>
            <div class="dial-info-val" id="agentScreenAccountText">ACC-98231</div>

            <div class="dial-info-lbl">Scheduled call time</div>
            <div class="dial-info-val" id="agentScreenCallTimeText">2026-10-20 11:15</div>

            <div class="dial-info-lbl">Campaign reference</div>
            <div class="dial-info-val" id="agentScreenCampaignText">Bill reminder: October</div>

            <div style="display:flex;gap:8px;margin-top:auto;">
              <span id="agentScreenTierTag" class="dial-badge-gray">Standard</span>
              <span id="agentScreenLangTag" class="dial-badge-mint">Amharic</span>
            </div>
          </div>

          <!-- COL 2: Agent Script & Interactive Checklist -->
          <div class="dial-col-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
              <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin:0;">Agent script</h3>
              <span style="font-size:11.5px;color:#64748b;font-weight:500;">Interactive</span>
            </div>

            <!-- Dynamic script speech bubble -->
            <div id="agentDynamicScriptBox" class="dial-script-bubble">
              &ldquo;ሰላም Helen Tesfaye: ከኢትዮ ቴሌኮም የደንበኞች አገልግሎት ነው የምደውለው:: የ ETB 450.00 የክፍያ ሂሳብዎን ለማስታወስ ነበር:: በቴሌብር ለመክፈል አሁን የክፍያ መልእክት ልላክሎት?&rdquo;
            </div>

            <!-- Checklist items -->
            <div class="dial-check-item" onclick="app.toggleAgentCheck(this)">
              <div class="dial-check-box checked">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
              </div>
              <span>Greet and verify customer identity</span>
            </div>

            <div class="dial-check-item" onclick="app.toggleAgentCheck(this)">
              <div class="dial-check-box checked">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
              </div>
              <span>State telecom outstanding bill balance</span>
            </div>

            <div class="dial-check-item" onclick="app.toggleAgentCheck(this)">
              <div class="dial-check-box"></div>
              <span>Offer Telebirr USSD payment SMS push</span>
            </div>

            <div class="dial-check-item" onclick="app.toggleAgentCheck(this)">
              <div class="dial-check-box"></div>
              <span>Confirm payment date or callback schedule</span>
            </div>
          </div>

          <!-- COL 3: Outcome & Dispositions -->
          <div class="dial-col-card">
            <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin:0 0 18px 0;">Outcome</h3>

            <div id="dialOutcomeList" style="margin-bottom:12px;">
              <label class="dial-outcome-opt active" onclick="app.selectAgentOutcome(this, 'promise_to_pay')">
                <span class="dial-radio-circle checked"></span>
                <span class="dial-outcome-text active">Promise to pay</span>
              </label>

              <label class="dial-outcome-opt" onclick="app.selectAgentOutcome(this, 'paid_now')">
                <span class="dial-radio-circle"></span>
                <span class="dial-outcome-text">Paid via Telebirr</span>
              </label>

              <label class="dial-outcome-opt" onclick="app.selectAgentOutcome(this, 'callback')">
                <span class="dial-radio-circle"></span>
                <span class="dial-outcome-text">Call back</span>
              </label>

              <label class="dial-outcome-opt" onclick="app.selectAgentOutcome(this, 'no_answer')">
                <span class="dial-radio-circle"></span>
                <span class="dial-outcome-text">No answer</span>
              </label>

              <label class="dial-outcome-opt" onclick="app.selectAgentOutcome(this, 'wrong_number')">
                <span class="dial-radio-circle"></span>
                <span class="dial-outcome-text red">Wrong number / DNC</span>
              </label>
            </div>

            <div style="margin-top:auto;">
              <label style="display:block;font-size:11.5px;color:#64748b;font-weight:500;margin-bottom:5px;">Promise / callback date</label>
              <select id="agentPromiseDateSelect" class="dial-input-box" style="margin-bottom:12px;">
                <option value="Tomorrow (+24h)" selected>Tomorrow (+24h)</option>
                <option value="Today (by 5 PM)">Today (by 5 PM)</option>
                <option value="+3 Days">+3 Days</option>
                <option value="End of Month">End of Month</option>
              </select>

              <textarea id="agentCallNotesInput" class="dial-input-box" placeholder="Notes (optional)..." style="min-height:54px;resize:vertical;margin-bottom:16px;"></textarea>

              <div style="display:flex;gap:10px;margin-bottom:10px;">
                <button type="button" class="dial-btn-outline" onclick="app.handleAgentSkipClick()">Skip</button>
                <button type="button" class="dial-btn-outline primary" onclick="app.handleAgentSaveNextClick()" style="flex:1;text-align:center;">Save &amp; next</button>
              </div>

              <button type="button" class="dial-btn-dial" onclick="app.handleAgentDialClick()">Dial</button>
            </div>
          </div>

        </div>
      </div>


      <!-- ── SUB-VIEW B: PACING & COMPLIANCE (Screenshot 4) ── -->
      <div id="subViewDialerPacing" style="display:none;">
        <div style="display:grid;grid-template-columns:1fr 1.15fr;gap:20px;">
          <!-- Left: Pacing and compliance -->
          <div class="sc-panel" style="padding:22px 24px;">
            <div style="font-size:16px;font-weight:700;color:#143522;margin-bottom:18px;">Pacing and compliance</div>

            <div class="form-group" style="margin-bottom:18px;">
              <label class="form-label" style="font-size:12px;font-weight:600;color:#1a3d2a;text-transform:none;">Dialing mode</label>
              <select class="form-control" id="pacingModeSelect" style="font-size:13px;padding:9px 12px;">
                <option value="predictive" selected>Predictive</option>
                <option value="progressive">Progressive</option>
                <option value="agentless">Agentless voice</option>
                <option value="preview">Preview</option>
              </select>
            </div>

            <!-- Dial Ratio Slider -->
            <div class="form-group" style="margin-bottom:18px;">
              <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                <label class="form-label" style="font-size:12px;font-weight:600;color:#1a3d2a;text-transform:none;margin:0;">Dial ratio (1.0 to 3.0)</label>
                <span id="dialRatioVal" style="font-size:12px;font-weight:700;color:#00A651;">2.1</span>
              </div>
              <input type="range" min="1.0" max="3.0" step="0.1" value="2.1" class="form-control" style="padding:0;height:7px;accent-color:#00A651;" oninput="document.getElementById('dialRatioVal').textContent=this.value">
            </div>

            <!-- Max Abandon Rate Slider -->
            <div class="form-group" style="margin-bottom:18px;">
              <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                <label class="form-label" style="font-size:12px;font-weight:600;color:#1a3d2a;text-transform:none;margin:0;">Maximum abandon rate (target below 3%)</label>
                <span id="abandonRateVal" style="font-size:12px;font-weight:700;color:#0082C8;">2.4%</span>
              </div>
              <input type="range" min="0.5" max="5.0" step="0.1" value="2.4" class="form-control" style="padding:0;height:7px;accent-color:#0082C8;" oninput="document.getElementById('abandonRateVal').textContent=this.value+'%'">
            </div>

            <!-- Calling Hours -->
            <div class="form-group" style="margin-bottom:18px;">
              <label class="form-label" style="font-size:12px;font-weight:600;color:#1a3d2a;text-transform:none;">Calling hours</label>
              <select class="form-control" style="font-size:13px;padding:9px 12px;">
                <option value="standard" selected>Mon to Sat, 08:00 to 20:00</option>
                <option value="all_week">Mon to Sun, 08:30 to 21:00</option>
                <option value="business">Mon to Fri, 09:00 to 18:00</option>
              </select>
            </div>

            <!-- Max Attempts & Retry After -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <div class="form-group">
                <label class="form-label" style="font-size:12px;font-weight:600;color:#1a3d2a;text-transform:none;">Max attempts</label>
                <select class="form-control" style="font-size:13px;padding:9px 12px;">
                  <option value="3" selected>3 per contact</option>
                  <option value="2">2 per contact</option>
                  <option value="5">5 per contact</option>
                </select>
              </div>

              <div class="form-group">
                <label class="form-label" style="font-size:12px;font-weight:600;color:#1a3d2a;text-transform:none;">Retry after</label>
                <select class="form-control" style="font-size:13px;padding:9px 12px;">
                  <option value="2h" selected>2 hours</option>
                  <option value="4h">4 hours</option>
                  <option value="24h">24 hours</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Right: Live pacing (sample) -->
          <div class="sc-panel" style="padding:22px 24px;">
            <div style="font-size:16px;font-weight:700;color:#143522;margin-bottom:18px;">Live pacing (sample)</div>

            <!-- 4 Metrics Cards -->
            <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:10px;margin-bottom:24px;">
              <div class="sc-stat-chip">
                <div class="sc-stat-val">14</div>
                <div class="sc-stat-lbl">Agents ready</div>
              </div>
              <div class="sc-stat-chip">
                <div class="sc-stat-val">22</div>
                <div class="sc-stat-lbl">Dialing</div>
              </div>
              <div class="sc-stat-chip">
                <div class="sc-stat-val" style="color:#0082C8;">1.8%</div>
                <div class="sc-stat-lbl">Abandon</div>
              </div>
              <div class="sc-stat-chip">
                <div class="sc-stat-val">03:40</div>
                <div class="sc-stat-lbl">Avg talk</div>
              </div>
            </div>

            <!-- Bar Chart -->
            <div style="font-size:12.5px;color:#1a3d2a;font-weight:600;margin-bottom:8px;">Calls dialed per 10 minutes</div>
            <div class="sc-barchart">
              <div class="sc-bar" style="height:35%;" title="10 min ago: 12 calls"></div>
              <div class="sc-bar" style="height:55%;" title="20 min ago: 19 calls"></div>
              <div class="sc-bar" style="height:65%;" title="30 min ago: 22 calls"></div>
              <div class="sc-bar" style="height:50%;" title="40 min ago: 17 calls"></div>
              <div class="sc-bar highlight" style="height:88%;" title="Current peak: 31 calls"></div>
              <div class="sc-bar" style="height:62%;" title="60 min: 21 calls"></div>
              <div class="sc-bar" style="height:58%;" title="70 min: 20 calls"></div>
              <div class="sc-bar" style="height:70%;" title="80 min: 24 calls"></div>
              <div class="sc-bar" style="height:64%;" title="90 min: 22 calls"></div>
              <div class="sc-bar" style="height:48%;" title="100 min: 16 calls"></div>
            </div>
          </div>
        </div>

        <!-- Mock Volume & Trunk Capacity Test Card -->
        <div class="sc-panel" style="margin-top:20px;padding:22px 24px;border:1px solid #bbf7d0;background:#fbfefc;">
          <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
            <div>
              <div style="font-size:16px;font-weight:700;color:#143522;display:flex;align-items:center;gap:8px;">
                <span>🧪 Mock 1,000 CPM Volume &amp; Trunk Capacity Tester</span>
                <span style="background:#e8f8ee;color:#00A651;font-size:11px;font-weight:700;padding:3px 9px;border-radius:12px;border:1px solid #bbf7d0;">SAFE &bull; ZERO REAL CALLS</span>
              </div>
              <div style="font-size:12px;color:#64748b;margin-top:3px;">
                Simulates real carrier SIP trunk traffic (17 calls/second = 1,020 CPM) using non-routable dummy numbers (+251900XXXXXX). Tests queue concurrency, DB throughput, and CDR generation.
              </div>
            </div>

            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
              <select id="mockVolumeModeSelect" class="form-control" style="font-size:13px;padding:7px 12px;width:auto;font-weight:600;">
                <option value="paced_1000" selected>⏱️ Real-Time Paced Test (1,000 Calls &bull; 60s at 17/s)</option>
                <option value="paced_500">⏱️ Real-Time Paced Test (500 Calls &bull; 30s at 17/s)</option>
                <option value="paced_170">⏱️ Quick Test (170 Calls &bull; 10s at 17/s)</option>
                <option value="fast_1000">⚡ Instant Benchmark (1,000 Calls in ~0.5s)</option>
              </select>

              <button type="button" id="btnRunMockVolumeTest" class="btn btn-primary" onclick="app.startMockVolumeTest()" style="font-size:13px;font-weight:700;padding:8px 18px;background:#00A651;border:none;border-radius:8px;cursor:pointer;">
                🚀 Start Mock Test
              </button>

              <button type="button" id="btnStopMockVolumeTest" class="btn btn-danger" onclick="app.stopMockVolumeTest()" style="display:none;font-size:13px;font-weight:700;padding:8px 16px;background:#dc2626;border:none;border-radius:8px;cursor:pointer;">
                ⏹ Stop
              </button>
            </div>
          </div>

          <!-- Live Interactive Progress & Dashboard Container -->
          <div id="mockVolumeResultsBox" style="display:none;"></div>
        </div>
      </div>

      <!-- ── SUB-VIEW C: QUEUE & PBX LINES ── -->
      <div id="subViewDialerQueue" style="display:none;">
        <div class="control-bar">
          <div class="campaign-title-block">
            <h2>
              <span id="campaignTitleText">Outbound Customer Campaign</span>
              <span id="campaignStatusTag" class="campaign-status-tag idle">IDLE</span>
            </h2>
          </div>

          <div class="control-actions">
            <div class="pacing-controls">
              <div class="pacing-item">
                <label for="dialingModeSelect">Mode:</label>
                <select id="dialingModeSelect">
                  <option value="agentless" selected>Agentless Voice Broadcast</option>
                  <option value="progressive">Progressive Dialing</option>
                  <option value="predictive">Predictive Dialing</option>
                  <option value="preview">Preview Dialing</option>
                  <option value="callback">Callback Assist</option>
                </select>
              </div>
              <div class="pacing-item">
                <label for="pacingDelayInput">Pacing:</label>
                <select id="pacingDelayInput">
                  <option value="1">1s</option>
                  <option value="2" selected>2s</option>
                  <option value="4">4s</option>
                </select>
              </div>
              <div class="pacing-item">
                <label for="maxConcurrentInput">Channels:</label>
                <select id="maxConcurrentInput">
                  <option value="1">1 Line</option>
                  <option value="2" selected>2 Lines</option>
                  <option value="4">4 Lines</option>
                </select>
              </div>
            </div>

            <button id="btnStartCampaign" class="btn btn-success">Start Auto-Dialer</button>
            <button id="btnPauseCampaign" class="btn btn-warning" disabled>Pause</button>
            <button id="btnStopCampaign" class="btn btn-danger" disabled>Stop</button>
            <button id="btnResetLeads" class="btn btn-secondary">Reset Leads</button>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 340px;gap:20px;">
          <!-- Left: Lines & Queue -->
          <div>
            <!-- Active Outbound Lines -->
            <div style="background:#fafbfc;border:1px solid #dbe8df;border-radius:10px;padding:16px;margin-bottom:20px;">
              <div style="font-size:12px;font-weight:700;color:#0c3629;text-transform:uppercase;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                <span class="live-dot"></span> Active Outbound Lines
              </div>
              <div id="activeChannelsGrid" class="channels-grid"></div>
            </div>

            <!-- Quick Add Contact Form -->
            <div class="quick-add-card">
              <div class="quick-add-header">
                <span style="font-weight:700;color:#0c3629;font-size:12.5px;">Quick Add Contact &amp; Schedule Call</span>
                <span style="font-size:11px;color:#64748b;">Schedule for today or any future date/time</span>
              </div>
              <form id="formQuickAddLead" class="quick-add-form" onsubmit="app.handleQuickAddSubmit(event)">
                <input type="text" id="quickAddName" class="form-control" placeholder="Name (e.g. Helen Tesfaye)" required style="flex:1;min-width:120px;">
                <input type="tel" id="quickAddPhone" class="form-control" placeholder="Phone (e.g. 0911223344)" required style="flex:1.2;min-width:140px;">
                <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">
                  <select id="quickAddTimePreset" class="form-control" style="font-size:12px;min-width:125px;" onchange="app.handleTimePresetChange(this.value)">
                    <option value="immediate" selected>Immediate</option>
                    <option value="+2min">+2 Minutes</option>
                    <option value="+5min">+5 Minutes</option>
                    <option value="+10min">+10 Minutes</option>
                    <option value="+30min">+30 Minutes</option>
                    <option value="custom">Custom Date &amp; Time...</option>
                  </select>
                  <input type="date" id="quickAddCustomDate" class="form-control" style="display:none;width:125px;font-size:12px;" title="Scheduled Date">
                  <input type="time" id="quickAddCustomTime" class="form-control" style="display:none;width:105px;font-size:12px;" title="Scheduled Time">
                </div>
                <div style="display:flex;gap:6px;">
                  <button type="submit" class="btn btn-primary btn-sm">Add to Queue</button>
                  <button type="button" class="btn btn-success btn-sm" onclick="app.quickAddAndCall()">Call Now</button>
                </div>
              </form>
            </div>

            <!-- Queue Table -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
              <div style="font-size:14px;font-weight:700;color:#1e293b;">Customer Calling Queue</div>
              <div style="display:flex;gap:8px;">
                <button class="btn btn-primary btn-sm" onclick="app.openModal('modalUpload')">Upload CSV File</button>
                <button class="btn btn-secondary btn-sm" onclick="app.openModal('modalManual')">Bulk Write Numbers</button>
              </div>
            </div>

            <div class="data-table-wrapper">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Customer Name</th>
                    <th>Phone Number</th>
                    <th>Campaign</th>
                    <th>Scheduled Call Time</th>
                    <th>Status</th>
                    <th>Duration</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="leadsTableBody"></tbody>
              </table>
            </div>
          </div>

          <!-- Right: Human IVR Audio Box -->
          <div>
            <div style="background:#f9fcf9;border:1px solid #dbe8df;border-radius:10px;padding:16px;">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <div style="font-size:13px;font-weight:700;color:#0c3629;">Human IVR Audio</div>
                <button class="btn btn-secondary btn-sm" onclick="app.openModal('modalAudioUpload')">Upload Audio</button>
              </div>
              <p style="font-size:11.5px;color:#64748b;margin-bottom:10px;">
                Plays automatically when customer answers the outbound call:
              </p>

              <div style="margin-bottom:12px;">
                <label class="form-label" style="font-size:11px;">Selected Recording:</label>
                <select id="ivrAudioSelector" class="form-control" style="font-size:12.5px;"></select>
              </div>

              <div style="background:#ffffff;padding:12px;border-radius:8px;border:1px solid #cbd5e1;">
                <div style="font-size:12px;font-weight:700;color:#1e293b;margin-bottom:6px;" id="activeAudioTitle">Human Welcome Recording</div>
                <audio id="mainAudioPlayer" controls style="width:100%;height:36px;outline:none;" src="assets/audio/welcome_human_voice.wav"></audio>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ========================================================
         VIEW 3: CALLBACKS (Unanswered / Scheduled Callbacks)
         ======================================================== -->
    <div class="tab-panel" id="tabCallbacks">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <div>
          <div style="font-size:16px;font-weight:700;color:#1e293b;">Callbacks &amp; Missed Leads</div>
          <div style="font-size:12px;color:#64748b;">Customers requiring follow-up callbacks or redials.</div>
        </div>
        <div style="display:flex;gap:8px;">
          <button class="btn btn-primary btn-sm" onclick="app.requeueUnansweredLeads()">Re-queue All to Queue</button>
          <button class="btn btn-secondary btn-sm" onclick="app.exportUnansweredCsv()">Export to CSV</button>
        </div>
      </div>

      <div class="data-table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Customer Name</th>
              <th>Phone Number</th>
              <th>Reason / Disposition</th>
              <th>Scheduled Call Time</th>
              <th>Last Attempt</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="unansweredTableBody"></tbody>
        </table>
      </div>
    </div>

    <!-- ========================================================
         VIEW 4: CONTACTS (Full Lead Manager)
         ======================================================== -->
    <div class="tab-panel" id="tabContacts">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <input type="text" id="leadSearchInput" class="form-control" placeholder="Search customer, phone, or account..." style="max-width:300px;">
          <select id="leadStatusFilter" class="form-control" style="max-width:180px;">
            <option value="all">All Statuses</option>
            <option value="pending">Pending</option>
            <option value="answered">Answered / Completed</option>
            <option value="busy">Busy / Unreachable</option>
            <option value="no_answer">No Answer</option>
          </select>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button class="btn btn-primary" onclick="app.openModal('modalSingleAdd')">Quick Add Contact</button>
          <button class="btn btn-secondary" onclick="app.openModal('modalUpload')">Upload CSV / Excel</button>
          <button class="btn btn-secondary" onclick="app.openModal('modalManual')">Bulk Write Numbers</button>
          <button class="btn btn-secondary" onclick="app.exportContactsCsv()" title="Export full contacts list to CSV">Export Contacts CSV</button>
        </div>
      </div>

      <div class="data-table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Customer Name</th>
              <th>Phone Number</th>
              <th>Campaign</th>
              <th>Scheduled Call Time</th>
              <th>Status</th>
              <th>Duration</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="leadsTableBodyFull"></tbody>
        </table>
      </div>
    </div>

    <!-- ========================================================
         VIEW 5: REPORTS (KPIs, Call Logs & History)
         ======================================================== -->
    <div class="tab-panel" id="tabReports">
      <!-- KPI Summary Grid -->
      <div class="summary-grid">
        <div class="card">
          <div class="card-label">Today's Leads</div>
          <div class="card-value" id="kpiTotalLeads">0</div>
          <div class="card-sub">In today's active list</div>
        </div>
        <div class="card orange">
          <div class="card-label">Today's Pending</div>
          <div class="card-value" id="kpiPendingLeads">0</div>
          <div class="card-sub">Waiting to be dialed today</div>
        </div>
        <div class="card green">
          <div class="card-label">Answered / Completed</div>
          <div class="card-value" id="kpiConnectedLeads">0</div>
          <div class="card-sub">Audio broadcast played</div>
        </div>
        <div class="card teal">
          <div class="card-label">Answer Rate</div>
          <div class="card-value" id="kpiAnswerRate">0%</div>
          <div class="card-sub">Pickup ratio</div>
        </div>
        <div class="card purple">
          <div class="card-label">Avg. Duration</div>
          <div class="card-value" id="kpiAvgDuration">0s</div>
          <div class="card-sub">Per completed call</div>
        </div>
      </div>

      <!-- Call Logs Section -->
      <div class="sc-panel" style="margin-bottom:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
          <div style="font-size:15px;font-weight:700;color:#0c3629;">Live Call Dispositions</div>
          <button id="btnExportLogs" class="btn btn-primary btn-sm">Export to CSV</button>
        </div>
        <div class="data-table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>Timestamp</th>
                <th>Customer Name</th>
                <th>Phone Number</th>
                <th>Status</th>
                <th>Duration</th>
                <th>Audio Played</th>
                <th>Scheduled Call Time</th>
              </tr>
            </thead>
            <tbody id="logsTableBody"></tbody>
          </table>
        </div>
      </div>

      <!-- Call History & Past Records -->
      <div class="sc-panel">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
          <div>
            <div style="font-size:15px;font-weight:700;color:#0c3629;">Call History &amp; Past Records</div>
            <div style="font-size:12px;color:#526e5f;">Review previous days, check dispositions, or roll over remaining contacts.</div>
          </div>
          <div style="display:flex;gap:8px;">
            <button class="btn btn-primary btn-sm" onclick="app.bulkRolloverRemainingToToday()" id="btnHistoryRollover">
              Roll Over Remaining to Today (<span id="historyRolloverCount">0</span>)
            </button>
            <button class="btn btn-secondary btn-sm" onclick="app.exportHistoryCsv()">Export CSV</button>
          </div>
        </div>

        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex:1;">
            <select id="historyDateSelect" class="form-control" style="font-size:12px;min-width:140px;" onchange="app.handleHistoryDateFilter(this.value)">
              <option value="all">All Past Dates</option>
              <option value="yesterday">Yesterday</option>
            </select>
            <input type="date" id="historyCustomDatePicker" class="form-control" style="font-size:12px;max-width:140px;" onchange="app.handleHistoryCustomDate(this.value)">
            <input type="text" id="historySearchInput" class="form-control" placeholder="Search customer or phone..." style="font-size:12px;min-width:180px;max-width:280px;" oninput="app.renderHistoryTable()">
            <select id="historyStatusFilter" class="form-control" style="font-size:12px;min-width:140px;" onchange="app.renderHistoryTable()">
              <option value="all">All Dispositions</option>
              <option value="answered">Answered / Completed</option>
              <option value="no_answer">No Answer</option>
              <option value="busy">Busy</option>
              <option value="failed">Failed</option>
              <option value="pending">Pending</option>
            </select>
          </div>
          <div style="background:#e0f2fe;color:#0369a1;padding:4px 12px;border-radius:14px;font-size:11.5px;font-weight:700;" id="historyTotalRecordsTag">0 records</div>
        </div>

        <div class="data-table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Date</th>
                <th>Customer Name</th>
                <th>Phone Number</th>
                <th>Scheduled Call Time</th>
                <th>Disposition / Status</th>
                <th>Duration</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="historyTableBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ========================================================
         VIEW 6: COMPLIANCE & DNC REGISTRY
         ======================================================== -->
    <div class="tab-panel" id="tabCompliance">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <div>
          <div style="font-size:16px;font-weight:700;color:#143522;">Telephony Compliance &amp; Regulatory DNC Registry</div>
          <div style="font-size:12px;color:#526e5f;">Enforcing Ethiopian Communications Authority guidelines &amp; opt-out scrubbing.</div>
        </div>
        <div style="display:flex;gap:8px;">
          <button class="btn btn-primary btn-sm" onclick="app.scrubQueueDnc()" title="Scan leads against DNC and block matches">
            🛡️ Run Pre-Dial DNC Scrub
          </button>
          <button class="btn btn-secondary btn-sm" onclick="app.loadDncList()">↻ Refresh DNC</button>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1.35fr;gap:20px;">
        <!-- Left: Compliance Rules & Add to DNC Form -->
        <div style="display:flex;flex-direction:column;gap:16px;">
          <div class="sc-panel" style="padding:18px 20px;">
            <div style="font-size:14.5px;font-weight:700;color:#1a3d2a;margin-bottom:10px;">Calling Hours Enforcement</div>
            <p style="font-size:12.5px;color:#526e5f;line-height:1.5;margin-bottom:12px;">
              In accordance with Ethiopian Communications Authority directives, outbound broadcast calls are restricted to permitted calling windows (Mon&ndash;Sat, 08:00 to 20:00 EAT).
            </p>
            <div style="background:#eef7f1;padding:10px 14px;border-radius:8px;font-size:12px;color:#143522;display:flex;align-items:center;gap:8px;">
              <span class="live-dot"></span> Status: <strong>Active &amp; Enforced</strong> by dialing engine.
            </div>
          </div>

          <div class="sc-panel" style="padding:20px 22px;">
            <div style="font-size:15px;font-weight:700;color:#143522;margin-bottom:12px;">Add Number to Do-Not-Call (DNC)</div>
            <form onsubmit="app.handleAddDncSubmit(event);">
              <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label" style="font-size:11.5px;font-weight:600;color:#1a3d2a;">Customer Phone Number</label>
                <input type="tel" id="dncInputPhone" class="form-control" placeholder="e.g. 0911223344 or +251911..." required style="font-size:13px;padding:8px 10px;">
              </div>
              <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label" style="font-size:11.5px;font-weight:600;color:#1a3d2a;">Opt-Out Reason</label>
                <select id="dncInputReason" class="form-control" style="font-size:12.5px;padding:8px 10px;">
                  <option value="Customer requested opt-out via SMS/IVR" selected>Customer requested opt-out via SMS/IVR</option>
                  <option value="Direct request to customer service agent">Direct request to customer service agent</option>
                  <option value="Wrong subscriber / Disconnected line">Wrong subscriber / Disconnected line</option>
                  <option value="Regulatory / Government restriction">Regulatory / Government restriction</option>
                </select>
              </div>
              <button type="submit" class="sc-pill-btn primary" style="width:100%;padding:9px;font-size:13px;font-weight:700;">
                + Add to Regulatory DNC List
              </button>
            </form>
          </div>
        </div>

        <!-- Right: Live DNC Table -->
        <div class="sc-panel" style="padding:18px 20px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <div style="font-size:15px;font-weight:700;color:#143522;">
              Active Do-Not-Call (DNC) Numbers (<span id="dncCountTag">0</span>)
            </div>
            <span style="font-size:11.5px;color:#526e5f;">Scrubbed before every outbound batch</span>
          </div>

          <div class="data-table-wrapper" style="max-height:480px;overflow-y:auto;">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Phone Number</th>
                  <th>Reason</th>
                  <th>Date Added</th>
                  <th>Added By</th>
                  <th style="text-align:right;">Action</th>
                </tr>
              </thead>
              <tbody id="dncTableBody">
                <tr><td colspan="5" style="text-align:center;padding:20px;color:#64748b;">Loading DNC registry from PostgreSQL...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>


    <!-- ========================================================
         VIEW 7: SETTINGS (PBX, SIP, IVR Audio Studio)
         ======================================================== -->
    <div class="tab-panel" id="tabSettings">
      <div style="display:grid;grid-template-columns:1fr 1.3fr;gap:20px;">
        <!-- Left: PBX & Phone Settings -->
        <div class="sc-panel">
          <div style="font-size:15px;font-weight:700;color:#143522;margin-bottom:14px;">FreeSWITCH PBX Connection</div>
          <div style="display:flex;flex-direction:column;gap:12px;font-size:13px;">
            <div style="display:flex;justify-content:space-between;border-bottom:1px solid #f0f5f1;padding-bottom:8px;">
              <span style="color:#526e5f;">ESL Host / Node:</span>
              <span style="font-weight:600;color:#1a3d2a;">127.0.0.1:8021</span>
            </div>
            <div style="display:flex;justify-content:space-between;border-bottom:1px solid #f0f5f1;padding-bottom:8px;">
              <span style="color:#526e5f;">WebSocket WebRTC:</span>
              <span style="font-weight:600;color:#00A651;">wss://webcc.skyconnectsolutions.et:443/ws</span>
            </div>
            <div style="display:flex;justify-content:space-between;border-bottom:1px solid #f0f5f1;padding-bottom:8px;">
              <span style="color:#526e5f;">Agent Extension:</span>
              <span style="font-weight:600;color:#1a3d2a;"><?php echo $agent_ext; ?> (<?php echo $domain; ?>)</span>
            </div>
            <div style="display:flex;justify-content:space-between;">
              <span style="color:#526e5f;">SIP Node Health:</span>
              <span style="font-weight:700;color:#00A651;">Online &bull; Low Latency</span>
            </div>
          </div>

          <div style="margin-top:20px;">
            <button class="sc-pill-btn primary" onclick="openSipSettingsModal()">Edit SIP Credentials</button>
            <button class="sc-pill-btn secondary" onclick="openChangePasswordModal()" style="margin-left:8px;">Change Password</button>
          </div>
        </div>

        <!-- Right: Human IVR Audio Recordings Library -->
        <div class="sc-panel">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
            <div style="font-size:15px;font-weight:700;color:#143522;">Human Voice IVR Audio Library</div>
            <button class="btn btn-primary btn-sm" onclick="app.openModal('modalAudioUpload')">Upload New Audio</button>
          </div>

          <div style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:10px;padding:12px;margin-bottom:16px;">
            <div class="form-group" style="margin-bottom:10px;">
              <label class="form-label" style="font-size:11px;font-weight:700;">Active Audio for Campaign Broadcasts:</label>
              <select class="form-control" id="ivrAudioSelectorTab" onchange="document.getElementById('ivrAudioSelector').value=this.value; app.selectAudioRecording(this.value)"></select>
            </div>
            <audio id="tabAudioPlayer" controls style="width:100%;height:36px;outline:none;" src="assets/audio/welcome_human_voice.wav"></audio>
          </div>

          <div class="data-table-wrapper">
            <table class="data-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Recording Title</th>
                  <th>Filename</th>
                  <th>Status</th>
                  <th>Preview</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="audioListTableBody"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Hidden alias panels for legacy switchTab backwards compatibility -->
    <div id="tabLiveRoom" style="display:none;"></div>
    <div id="tabLeadManager" style="display:none;"></div>
    <div id="tabIVRStudio" style="display:none;"></div>
    <div id="tabCallLogs" style="display:none;"></div>
    <div id="tabUnanswered" style="display:none;"></div>
    <div id="tabCallHistory" style="display:none;"></div>

  </div> <!-- /sc-main-content -->

  <!-- ========================================================
       SLIDE-OUT SOFTPHONE PANEL & FLOATING ACTION BUTTON (FAB)
       ======================================================== -->
  <button class="phone-fab" id="phoneFab" onclick="togglePhonePopup()" title="Open Softphone">
    &#128222;
    <span class="fab-badge unreg" id="fabBadge"></span>
  </button>

  <div class="phone-popup" id="phonePopup">
    <div class="pp-header">
      <div class="pp-status">
        <div class="sip-dot registered" id="sipDot"></div>
        <span id="sipStatusText">Registered (<?php echo $agent_ext; ?>)</span>
      </div>
      <div class="pp-header-actions">
        <button class="btn-settings-header" onclick="openSipSettingsModal()" title="Phone settings">&#9881;</button>
        <button class="pp-close" onclick="togglePhonePopup()" title="Close">&#x2715;</button>
      </div>
    </div>

    <div class="pp-body">
      <!-- Active Call Display Banner -->
      <div class="call-active-banner" id="callActiveBanner">
        <div class="call-active-number" id="activeCallNumber">Connecting...</div>
        <div class="call-active-sub" id="activeCallSub">Outbound Call &bull; Human IVR Audio</div>
      </div>

      <!-- Live Call Timer -->
      <div id="callTimer" class="call-timer">00:00</div>

      <!-- In-Call Controls -->
      <div class="call-controls">
        <button class="btn-hangup" id="btnHangup" onclick="hangupManualCall()">&#128222; End Call</button>
        <button class="btn-phone-ctrl" id="btnHold" onclick="toggleManualHold()">⏸️<br>Hold</button>
        <button class="btn-phone-ctrl" id="btnMute" onclick="toggleManualMute()">🎤<br>Mute</button>
        <button class="btn-phone-ctrl" id="btnKeypadToggle" onclick="toggleCallKeypad()">🔢<br>Keypad</button>
      </div>
    </div>

    <!-- Inline Outbound Dial Pad -->
    <div class="dp-panel" id="dpPanel">
      <div class="dp-title">DIAL A NUMBER</div>
      <input type="tel" class="dp-display" id="dialInput" placeholder="Enter number..." maxlength="20" autocomplete="off" inputmode="tel">
      <div class="dp-grid">
        <button class="dp-key" onclick="dpKey('1')">1<span class="dp-sub">&nbsp;</span></button>
        <button class="dp-key" onclick="dpKey('2')">2<span class="dp-sub">ABC</span></button>
        <button class="dp-key" onclick="dpKey('3')">3<span class="dp-sub">DEF</span></button>
        <button class="dp-key" onclick="dpKey('4')">4<span class="dp-sub">GHI</span></button>
        <button class="dp-key" onclick="dpKey('5')">5<span class="dp-sub">JKL</span></button>
        <button class="dp-key" onclick="dpKey('6')">6<span class="dp-sub">MNO</span></button>
        <button class="dp-key" onclick="dpKey('7')">7<span class="dp-sub">PQRS</span></button>
        <button class="dp-key" onclick="dpKey('8')">8<span class="dp-sub">TUV</span></button>
        <button class="dp-key" onclick="dpKey('9')">9<span class="dp-sub">WXYZ</span></button>
        <button class="dp-key" onclick="dpKey('*')">*<span class="dp-sub">&nbsp;</span></button>
        <button class="dp-key" onclick="dpKey('0')">0<span class="dp-sub">+</span></button>
        <button class="dp-key" onclick="dpKey('#')">#<span class="dp-sub">&nbsp;</span></button>
      </div>
      <div class="dp-row-actions">
        <button class="dp-recent" type="button" onclick="fillRecentNumber()">Recent</button>
        <button class="dp-call" onclick="dpCall()" title="Start call">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M20.01 15.38c-1.23 0-2.42-.2-3.53-.56a.977.977 0 00-1.01.24l-2.2 2.2a15.053 15.053 0 01-6.59-6.59l2.2-2.21a.96.96 0 00.25-1A11.36 11.36 0 018.5 3.99c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1 0 9.39 7.61 17 17 17 .55 0 1-.45 1-1v-3.5c0-.55-.45-1-1-1z"/></svg>
        </button>
        <button class="dp-del" onclick="dpDelete()" title="Delete last digit">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 4H8l-7 8 7 8h13a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"></path><line x1="18" y1="9" x2="12" y2="15"></line><line x1="12" y1="9" x2="18" y2="15"></line></svg>
        </button>
      </div>
    </div>
  </div>

  <!-- WebRTC Remote Audio Player -->
  <audio id="remoteAudio" autoplay muted style="display:none;"></audio>

  <!-- ========================================================
       MODALS
       ======================================================== -->
  <!-- MODAL: QUICK ADD SINGLE CONTACT -->
  <div id="modalSingleAdd" class="modal-backdrop">
    <div class="modal-dialog">
      <div class="modal-header">
        <h3>Quick Add Contact</h3>
        <button type="button" class="btn-close" onclick="app.closeModal('modalSingleAdd')">&times;</button>
      </div>
      <form id="formSingleAddLead" onsubmit="app.handleSingleAddSubmit(event)">
        <div class="modal-body">
          <div class="form-group" style="margin-bottom:12px;">
            <label class="form-label" style="font-weight:700;color:#143522;">Target Campaign</label>
            <select id="singleAddCampaignSelect" class="form-control" style="font-size:13px;padding:8px 10px;" required>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:12px;">
            <label class="form-label">Full Customer Name</label>
            <input type="text" id="modalAddName" class="form-control" placeholder="e.g. Helen Tesfaye" required>
          </div>
          <div class="form-group" style="margin-bottom:12px;">
            <label class="form-label">Phone Number</label>
            <input type="tel" id="modalAddPhone" class="form-control" placeholder="e.g. 0911223344 or +251911..." required>
          </div>
          <div class="form-group" style="margin-bottom:12px;">
            <label class="form-label">Scheduled Call Time</label>
            <select id="modalAddTimePreset" class="form-control" onchange="app.handleModalPresetChange(this.value)">
              <option value="immediate" selected>Immediate (Dial with queue)</option>
              <option value="+2min">+2 Minutes</option>
              <option value="+5min">+5 Minutes</option>
              <option value="+10min">+10 Minutes</option>
              <option value="+30min">+30 Minutes</option>
              <option value="custom">Custom Date &amp; Time...</option>
            </select>
          </div>
          <div id="modalCustomDateTimeWrap" style="display:none;margin-bottom:12px;background:#f8fafc;padding:10px;border-radius:8px;border:1px solid #cbd5e1;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <div>
                <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:4px;">Date</label>
                <input type="date" id="modalAddCustomDate" class="form-control" style="font-size:12.5px;">
              </div>
              <div>
                <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:4px;">Time</label>
                <input type="time" id="modalAddCustomTime" class="form-control" style="font-size:12.5px;">
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="app.closeModal('modalSingleAdd')">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Contact</button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: UPLOAD CSV / EXCEL FILE -->
  <div id="modalUpload" class="modal-backdrop">
    <div class="modal-dialog">
      <div class="modal-header">
        <h3>Add Contacts to Campaign</h3>
        <button type="button" class="btn-close" onclick="app.closeModal('modalUpload')">&times;</button>
      </div>
      <form id="formUploadLeads" onsubmit="app.handleUploadSubmit(event)">
        <div class="modal-body">
          <div class="form-group" style="margin-bottom:14px;">
            <label class="form-label" style="font-weight:700;color:#143522;">Target Campaign</label>
            <select id="uploadCampaignSelect" class="form-control" style="font-size:13px;padding:8px 10px;" required>
            </select>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <span style="font-size:12px;font-weight:600;color:#143522;">Upload Excel or CSV File</span>
            <a href="javascript:void(0)" onclick="app.closeModal('modalUpload'); app.openModal('modalManual');" style="font-size:11.5px;color:#00A651;font-weight:600;text-decoration:none;">Or Paste Numbers Directly &rarr;</a>
          </div>
          <p style="font-size:11.5px;color:#64748b;margin-bottom:12px;">
            Supports Excel (.xlsx, .xls) and CSV. Required columns: Name, Phone Number, Schedule.
          </p>
          <div class="dropzone-box" id="dropzoneBox" onclick="document.getElementById('leadFileInput').click()">
            <div style="font-size:32px;margin-bottom:6px;">📊</div>
            <div style="font-weight:700;color:#1e293b;margin-bottom:4px;">Click to select Excel or CSV file</div>
            <div style="font-size:11px;color:#64748b;" id="selectedFileName">No file selected</div>
            <input type="file" id="leadFileInput" accept=".csv,.txt,.xlsx,.xls" style="display:none;" onchange="app.handleFileSelect(this)">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="app.closeModal('modalUpload')">Cancel</button>
          <button type="submit" class="btn btn-primary">Import Numbers to Campaign</button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: BULK WRITE NUMBERS -->
  <div id="modalManual" class="modal-backdrop">
    <div class="modal-dialog">
      <div class="modal-header">
        <h3>Bulk Write Numbers to Campaign</h3>
        <button type="button" class="btn-close" onclick="app.closeModal('modalManual')">&times;</button>
      </div>
      <form id="formManualLeads" onsubmit="app.handleManualSubmit(event)">
        <div class="modal-body">
          <div class="form-group" style="margin-bottom:14px;">
            <label class="form-label" style="font-weight:700;color:#143522;">Target Campaign</label>
            <select id="manualCampaignSelect" class="form-control" style="font-size:13px;padding:8px 10px;" required>
            </select>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <span style="font-size:12px;font-weight:600;color:#143522;">Paste Phone Numbers (One per line)</span>
            <a href="javascript:void(0)" onclick="app.closeModal('modalManual'); app.openModal('modalUpload');" style="font-size:11.5px;color:#00A651;font-weight:600;text-decoration:none;">&larr; Or Upload Excel/CSV</a>
          </div>
          <p style="font-size:11.5px;color:#64748b;margin-bottom:8px;">
            Format: <code>0911223344</code>, or <code>Helen Tesfaye, 0911223344, 02:30 PM</code>
          </p>
          <textarea id="manualLeadsText" class="form-control" rows="8" placeholder="0911223344&#10;Helen Tesfaye, 0911223344, 12:00 PM&#10;Abebe Kebede, 0922334455, 02:30 PM" required style="font-family:monospace;font-size:12px;"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="app.closeModal('modalManual')">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Numbers to Campaign</button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: CAMPAIGN ACTION MENU (More Button) -->
  <div id="modalCampaignActionMenu" class="modal-backdrop" onclick="app.closeModal('modalCampaignActionMenu')">
    <div class="modal-dialog" style="max-width:380px;" onclick="event.stopPropagation();">
      <div class="modal-header">
        <h3 id="campActionMenuTitle" style="font-size:15px;font-weight:700;color:#0f172a;">Campaign Options</h3>
        <button type="button" class="btn-close" onclick="app.closeModal('modalCampaignActionMenu')">&times;</button>
      </div>
      <div class="modal-body" style="padding:16px 20px;">
        <input type="hidden" id="campActionMenuCampaignId">
        <div style="display:flex;flex-direction:column;gap:8px;">
          <button type="button" class="btn btn-primary" onclick="app.actionMenuDialCampaign()" style="justify-content:flex-start;padding:10px 14px;font-weight:600;font-size:13px;">
            <span style="margin-right:8px;">🚀</span> Load &amp; Dial in Outbound Queue
          </button>
          <button type="button" class="btn btn-secondary" onclick="app.actionMenuAddContacts()" style="justify-content:flex-start;padding:10px 14px;font-size:13px;">
            <span style="margin-right:8px;">📥</span> + Add Numbers / Import Contacts
          </button>
          <button type="button" class="btn btn-secondary" onclick="app.actionMenuExportContacts()" style="justify-content:flex-start;padding:10px 14px;font-size:13px;">
            <span style="margin-right:8px;">📤</span> Export Contacts (CSV)
          </button>
          <button type="button" class="btn btn-secondary" onclick="app.actionMenuToggleStatus()" style="justify-content:flex-start;padding:10px 14px;font-size:13px;">
            <span style="margin-right:8px;">⏸️</span> Toggle Running / Idle Status
          </button>
          <button type="button" class="btn btn-secondary" onclick="app.actionMenuDeleteCampaign()" style="justify-content:flex-start;padding:10px 14px;font-size:13px;color:#dc2626;border-color:#fecaca;">
            <span style="margin-right:8px;">🗑️</span> Delete Campaign
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL: UPLOAD AUDIO RECORDING -->
  <div id="modalAudioUpload" class="modal-backdrop">
    <div class="modal-dialog">
      <div class="modal-header">
        <h3>Upload Voice Recording (.wav / .mp3)</h3>
        <button type="button" class="btn-close" onclick="app.closeModal('modalAudioUpload')">&times;</button>
      </div>
      <form id="formUploadAudio" onsubmit="app.handleAudioUploadSubmit(event)">
        <div class="modal-body">
          <div class="form-group" style="margin-bottom:12px;">
            <label class="form-label">Recording Title</label>
            <input type="text" id="audioNameInput" class="form-control" placeholder="e.g. October Bill Payment Reminder" required>
          </div>
          <div class="form-group">
            <label class="form-label">Audio File (.wav / .mp3)</label>
            <input type="file" id="audioFileInput" class="form-control" accept=".wav,.mp3" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="app.closeModal('modalAudioUpload')">Cancel</button>
          <button type="submit" id="btnUploadAudioSubmit" class="btn btn-primary">Upload Recording</button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: REDIAL SCHEDULE -->
  <div id="modalRedialSchedule" class="modal-backdrop">
    <div class="modal-dialog" style="max-width:440px;">
      <div class="modal-header">
        <h3 style="display:flex;align-items:center;gap:8px;font-size:16px;">
          <span>📅</span> Schedule Redial Date &amp; Time
        </h3>
        <button type="button" class="btn-close" onclick="app.closeModal('modalRedialSchedule')">&times;</button>
      </div>
      <form onsubmit="app.handleRedialSubmit(event)">
        <input type="hidden" id="redialLeadId">
        <div class="modal-body">
          <div style="background:#f0fdf4;border:1px solid #bbf7d0;padding:12px;border-radius:8px;margin-bottom:14px;">
            <div style="font-weight:700;color:#143522;font-size:13.5px;" id="redialCustomerName">Customer Name</div>
            <div style="font-size:12px;color:#00A651;font-weight:600;" id="redialPhoneNumber">Phone Number</div>
          </div>

          <!-- Quick Presets -->
          <div style="font-size:11.5px;font-weight:700;color:#526e5f;margin-bottom:6px;">Quick Presets:</div>
          <div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;">
            <button type="button" class="sc-pill-btn secondary" style="font-size:11px;padding:4px 9px;" onclick="app.setRedialPreset('+15m')">+15 Mins</button>
            <button type="button" class="sc-pill-btn secondary" style="font-size:11px;padding:4px 9px;" onclick="app.setRedialPreset('+1h')">+1 Hour</button>
            <button type="button" class="sc-pill-btn secondary" style="font-size:11px;padding:4px 9px;" onclick="app.setRedialPreset('+3h')">+3 Hours</button>
            <button type="button" class="sc-pill-btn secondary" style="font-size:11px;padding:4px 9px;" onclick="app.setRedialPreset('tomorrow_am')">Tomorrow 09:00 AM</button>
            <button type="button" class="sc-pill-btn secondary" style="font-size:11px;padding:4px 9px;" onclick="app.setRedialPreset('tomorrow_pm')">Tomorrow 02:00 PM</button>
            <button type="button" class="sc-pill-btn secondary" style="font-size:11px;padding:4px 9px;" onclick="app.setRedialPreset('+3days')">+3 Days</button>
          </div>

          <!-- Small Calendar & Time Pickers -->
          <div style="background:#f8fafc;border:1.5px solid #cbd5e1;border-radius:10px;padding:14px;margin-bottom:14px;">
            <div style="font-size:12px;font-weight:700;color:#0f172a;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
              <span>🗓️</span> Pick from Calendar &amp; Clock:
            </div>
            <div style="display:grid;grid-template-columns:1.25fr 1fr;gap:10px;margin-bottom:10px;">
              <div>
                <label class="form-label" style="font-size:11px;font-weight:700;color:#334155;margin-bottom:4px;">Date (Calendar)</label>
                <input type="date" id="redialDateInput" class="form-control" required style="font-size:13px;padding:8px 10px;background:#ffffff;border:1.5px solid #94a3b8;border-radius:6px;cursor:pointer;" onchange="app.updateRedialPreview()">
              </div>
              <div>
                <label class="form-label" style="font-size:11px;font-weight:700;color:#334155;margin-bottom:4px;">Time (Hours : Mins)</label>
                <input type="time" id="redialCustomTimeInput" class="form-control" required style="font-size:13px;padding:8px 10px;background:#ffffff;border:1.5px solid #94a3b8;border-radius:6px;cursor:pointer;" onchange="app.updateRedialPreview()">
              </div>
            </div>
            <div style="font-size:11.5px;color:#15803d;font-weight:600;background:#dcfce7;padding:6px 10px;border-radius:6px;border:1px solid #bbf7d0;" id="redialPreviewText">
              Redial scheduled for: Tomorrow at 09:00 AM
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="app.closeModal('modalRedialSchedule')">Cancel</button>
          <button type="submit" class="btn btn-primary" style="font-weight:700;">Save &amp; Queue Redial</button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: RESCHEDULE CALLBACK (Calendar Date & Time Picker) -->
  <div id="modalRescheduleCallback" class="modal-backdrop">
    <div class="modal-dialog" style="max-width:440px;">
      <div class="modal-header">
        <h3 style="display:flex;align-items:center;gap:8px;font-size:16px;">
          <span>📅</span> Choose Callback Date &amp; Time
        </h3>
        <button type="button" class="btn-close" onclick="app.closeModal('modalRescheduleCallback')">&times;</button>
      </div>
      <form id="formRescheduleCallback" onsubmit="app.handleRescheduleSubmit(event)">
        <input type="hidden" id="rescheduleCallbackId">
        <div class="modal-body">
          <div style="background:#f0fdf4;border:1px solid #bbf7d0;padding:12px;border-radius:8px;margin-bottom:14px;">
            <div style="font-weight:700;color:#143522;font-size:13.5px;" id="rescheduleCustomerName">Customer Name</div>
            <div style="font-size:12px;color:#00A651;font-weight:600;" id="reschedulePhoneNumber">Phone Number</div>
          </div>

          <!-- Quick Presets -->
          <div style="font-size:11.5px;font-weight:700;color:#526e5f;margin-bottom:6px;">Quick Presets:</div>
          <div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;">
            <button type="button" class="sc-pill-btn secondary" style="font-size:11px;padding:4px 9px;" onclick="app.setReschedulePreset('+15m')">+15 Mins</button>
            <button type="button" class="sc-pill-btn secondary" style="font-size:11px;padding:4px 9px;" onclick="app.setReschedulePreset('+1h')">+1 Hour</button>
            <button type="button" class="sc-pill-btn secondary" style="font-size:11px;padding:4px 9px;" onclick="app.setReschedulePreset('+3h')">+3 Hours</button>
            <button type="button" class="sc-pill-btn secondary" style="font-size:11px;padding:4px 9px;" onclick="app.setReschedulePreset('tomorrow_am')">Tomorrow 09:00 AM</button>
            <button type="button" class="sc-pill-btn secondary" style="font-size:11px;padding:4px 9px;" onclick="app.setReschedulePreset('tomorrow_pm')">Tomorrow 02:00 PM</button>
            <button type="button" class="sc-pill-btn secondary" style="font-size:11px;padding:4px 9px;" onclick="app.setReschedulePreset('+3days')">+3 Days</button>
          </div>

          <!-- Small Calendar & Time Pickers -->
          <div style="background:#f8fafc;border:1.5px solid #cbd5e1;border-radius:10px;padding:14px;margin-bottom:14px;">
            <div style="font-size:12px;font-weight:700;color:#0f172a;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
              <span>🗓️</span> Pick from Calendar &amp; Clock:
            </div>
            <div style="display:grid;grid-template-columns:1.25fr 1fr;gap:10px;margin-bottom:10px;">
              <div>
                <label class="form-label" style="font-size:11px;font-weight:700;color:#334155;margin-bottom:4px;">Date (Calendar)</label>
                <input type="date" id="rescheduleDateInput" class="form-control" required style="font-size:13px;padding:8px 10px;background:#ffffff;border:1.5px solid #94a3b8;border-radius:6px;cursor:pointer;" onchange="app.updateReschedulePreview()">
              </div>
              <div>
                <label class="form-label" style="font-size:11px;font-weight:700;color:#334155;margin-bottom:4px;">Time (Hours : Mins)</label>
                <input type="time" id="rescheduleTimeInput" class="form-control" required style="font-size:13px;padding:8px 10px;background:#ffffff;border:1.5px solid #94a3b8;border-radius:6px;cursor:pointer;" onchange="app.updateReschedulePreview()">
              </div>
            </div>
            <div style="font-size:11.5px;color:#15803d;font-weight:600;background:#dcfce7;padding:6px 10px;border-radius:6px;border:1px solid #bbf7d0;" id="reschedulePreviewText">
              Callback scheduled for: Tomorrow at 09:00 AM
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" style="font-size:11.5px;font-weight:700;color:#143522;">Follow-up Note (Optional)</label>
            <input type="text" id="rescheduleNotesInput" class="form-control" placeholder="e.g. Customer requested callback after lunch" style="font-size:12.5px;">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="app.closeModal('modalRescheduleCallback')">Cancel</button>
          <button type="submit" class="btn btn-primary" style="font-weight:700;">Save &amp; Update Schedule</button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: CHANGE PASSWORD -->
  <div id="modalChangePassword" class="modal-backdrop">
    <div class="modal-dialog">
      <div class="modal-header">
        <h3>Change Account Password</h3>
        <button type="button" class="btn-close" onclick="closeChangePasswordModal()">&times;</button>
      </div>
      <form onsubmit="handleChangePasswordSubmit(event)">
        <div class="modal-body">
          <div id="changePassAlert" style="display:none;padding:8px 12px;border-radius:6px;font-size:12px;margin-bottom:12px;"></div>
          <div class="form-group" style="margin-bottom:12px;">
            <label class="form-label">Current Password</label>
            <input type="password" id="cpCurrentPassword" class="form-control" placeholder="Enter current password" required autocomplete="current-password">
          </div>
          <div class="form-group" style="margin-bottom:12px;">
            <label class="form-label">New Password</label>
            <input type="password" id="cpNewPassword" class="form-control" placeholder="Enter new password (min. 4 chars)" required minlength="4" autocomplete="new-password">
          </div>
          <div class="form-group" style="margin-bottom:12px;">
            <label class="form-label">Confirm New Password</label>
            <input type="password" id="cpConfirmPassword" class="form-control" placeholder="Re-type new password" required minlength="4" autocomplete="new-password">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="closeChangePasswordModal()">Cancel</button>
          <button type="submit" id="btnSubmitChangePass" class="btn btn-primary">Update Password</button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: SIP SETTINGS -->
  <div class="modal-overlay" id="settingsModal">
    <div class="modal-box">
      <div class="modal-title">SIP Softphone Extension Settings</div>
      <form onsubmit="saveSipSettings(event)">
        <div class="form-group">
          <label>SIP Server (WSS Host)</label>
          <input type="text" id="sipServer" value="<?php echo $agent_server; ?>" required>
        </div>
        <div class="form-group">
          <label>SIP WSS Port</label>
          <input type="text" id="sipPort" value="<?php echo $agent_port; ?>" required>
        </div>
        <div class="form-group">
          <label>SIP Domain</label>
          <input type="text" id="sipDomain" value="<?php echo $agent_domain; ?>" required>
        </div>
        <div class="form-group">
          <label>Extension 1 (Line 1)</label>
          <input type="text" id="sipExt" value="<?php echo $agent_ext; ?>" required>
        </div>
        <div class="form-group">
          <label>Password 1</label>
          <input type="password" id="sipPass" value="<?php echo $agent_pass; ?>" required>
        </div>
        <div class="form-group" style="border-top:1px solid #dce8df;padding-top:10px;">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="checkbox" id="line2EnabledCheck" <?php echo $line2_enabled ? 'checked' : ''; ?>>
            <span>Enable Dual-Line Outbound (Line 2)</span>
          </label>
        </div>
        <div class="form-group">
          <label>Extension 2 (Line 2)</label>
          <input type="text" id="sipExt2" value="<?php echo $agent_ext2; ?>">
        </div>
        <div class="form-group">
          <label>Password 2</label>
          <input type="password" id="sipPass2" value="<?php echo $agent_pass2; ?>">
        </div>
        <div style="display:flex;gap:8px;margin-top:12px;">
          <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeSipSettingsModal()">Cancel</button>
          <button type="submit" class="btn-save-settings" style="flex:1.5;">Connect / Save</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Toast Notification Container -->
  <div id="toastContainer" class="toast-container"></div>

  <!-- Global Agent Telephony Config -->
  <script>
  window.AGENT_CONFIG = {
    name:         '<?php echo addslashes($agent_name); ?>',
    username:     '<?php echo addslashes($currentUser['username'] ?? 'Agent1'); ?>',
    ext:          '<?php echo addslashes($agent_ext); ?>',
    pass:         '<?php echo addslashes($agent_pass); ?>',
    ext2:         '<?php echo addslashes($agent_ext2); ?>',
    pass2:        '<?php echo addslashes($agent_pass2); ?>',
    line2Enabled: <?php echo $line2_enabled ? 'true' : 'false'; ?>,
    server:       '<?php echo addslashes($agent_server); ?>',
    port:         '<?php echo addslashes($agent_port); ?>',
    domain:       '<?php echo addslashes($agent_domain); ?>'
  };

  /* Navigation & Context Switcher */
  const NAV_SECTION_TITLES = {
    'tabCampaigns': 'Campaigns Management',
    'tabDialer': 'Agent Softphone & Live Queue',
    'tabCallbacks': 'Scheduled Callbacks',
    'tabContacts': 'Customer Contacts Directory',
    'tabReports': 'Analytics & Call Reports',
    'tabCompliance': 'Compliance & DNC Registry',
    'tabSettings': 'Cloud PBX & Dialer Settings'
  };

  function switchNav(tabId, urlPath) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.sc-nav-item').forEach(b => b.classList.remove('active'));

    const targetPanel = document.getElementById(tabId);
    if (targetPanel) targetPanel.classList.add('active');

    const sbBtn = document.getElementById('sb' + tabId.replace('tab', ''));
    if (sbBtn) sbBtn.classList.add('active');

    const titleEl = document.getElementById('headerSectionTitle');
    if (titleEl && NAV_SECTION_TITLES[tabId]) {
      titleEl.textContent = NAV_SECTION_TITLES[tabId];
    }

    const urlText = document.getElementById('topUrlText');
    if (urlText && urlPath) {
      urlText.textContent = 'skyconnect.ethiotelecom.et/' + urlPath;
    }

    if (window.app) {
      if (tabId === 'tabCampaigns' && app.loadCampaignsOverview) app.loadCampaignsOverview();
      if (tabId === 'tabDialer' && app.renderAgentScreen) app.renderAgentScreen();
      if (tabId === 'tabCallbacks' && app.loadCallbacksList) app.loadCallbacksList();
      if (tabId === 'tabCompliance' && app.loadDncList) app.loadDncList();
      if (tabId === 'tabContacts' && app.renderLeadsTables) app.renderLeadsTables();
      if (tabId === 'tabReports' && app.loadReportsAndLogs) app.loadReportsAndLogs();
    }
  }

  /* Backwards-compatibility for switchTab calls from dialer.js */
  function switchTab(tabId) {
    if (tabId === 'tabLiveRoom') {
      switchNav('tabDialer', 'dialer/agent');
      switchDialerSubView('queue');
    } else if (tabId === 'tabLeadManager') {
      switchNav('tabContacts', 'contacts');
    } else if (tabId === 'tabUnanswered') {
      switchNav('tabCallbacks', 'callbacks');
    } else if (tabId === 'tabIVRStudio') {
      switchNav('tabSettings', 'settings');
    } else if (tabId === 'tabCallLogs' || tabId === 'tabCallHistory') {
      switchNav('tabReports', 'reports');
    } else {
      switchNav(tabId, tabId.replace('tab', '').toLowerCase());
    }
  }
  window.switchNav = switchNav;
  window.switchTab = switchTab;

  /* Campaigns Sub-View Switcher */
  function switchCampaignSubView(sub) {
    const listEl = document.getElementById('subViewCampaignsList');
    const designerEl = document.getElementById('subViewCampaignsDesigner');
    const urlText = document.getElementById('topUrlText');
    const titleEl = document.getElementById('headerSectionTitle');

    if (sub === 'designer') {
      if (listEl) listEl.style.display = 'none';
      if (designerEl) designerEl.style.display = 'block';
      const cName = window.app?.campaign?.name || 'Bill reminder: October';
      const tabName = document.getElementById('designerTabCampaignName');
      if (tabName) tabName.textContent = cName;
      if (urlText) urlText.textContent = 'skyconnect.ethiotelecom.et/campaigns/' + encodeURIComponent(cName.toLowerCase().replace(/[^a-z0-9]+/g, '-'));
      if (titleEl) titleEl.textContent = 'Campaigns Designer: ' + cName;
    } else {
      if (listEl) listEl.style.display = 'block';
      if (designerEl) designerEl.style.display = 'none';
      if (urlText) urlText.textContent = 'skyconnect.ethiotelecom.et/campaigns';
      if (titleEl) titleEl.textContent = 'Campaigns Management';
    }
  }
  window.switchCampaignSubView = switchCampaignSubView;

  /* Dialer Sub-View Switcher */
  function switchDialerSubView(sub) {
    const agentEl = document.getElementById('subViewDialerAgent');
    const pacingEl = document.getElementById('subViewDialerPacing');
    const queueEl = document.getElementById('subViewDialerQueue');
    const btnAgent = document.getElementById('subBtnDialerAgent');
    const btnPacing = document.getElementById('subBtnDialerPacing');
    const btnQueue = document.getElementById('subBtnDialerQueue');
    const urlText = document.getElementById('topUrlText');
    const titleEl = document.getElementById('headerSectionTitle');

    if (agentEl) agentEl.style.display = (sub === 'agent') ? 'block' : 'none';
    if (pacingEl) pacingEl.style.display = (sub === 'pacing') ? 'block' : 'none';
    if (queueEl) queueEl.style.display = (sub === 'queue') ? 'block' : 'none';

    if (btnAgent) btnAgent.className = (sub === 'agent') ? 'dial-seg-btn active' : 'dial-seg-btn';
    if (btnPacing) btnPacing.className = (sub === 'pacing') ? 'dial-seg-btn active' : 'dial-seg-btn';
    if (btnQueue) btnQueue.className = (sub === 'queue') ? 'dial-seg-btn active' : 'dial-seg-btn';

    if (sub === 'agent') {
      if (urlText) urlText.textContent = 'skyconnect.ethiotelecom.et/dialer/agent';
      if (titleEl) titleEl.textContent = 'Agent Script & Customer 360';
    } else if (sub === 'pacing') {
      if (urlText) urlText.textContent = 'skyconnect.ethiotelecom.et/dialer/control';
      if (titleEl) titleEl.textContent = 'Dialer Pacing & Predictive Engine';
    } else {
      if (urlText) urlText.textContent = 'skyconnect.ethiotelecom.et/dialer/queue';
      if (titleEl) titleEl.textContent = 'Live Outbound Queue & Softphone';
    }
  }
  window.switchDialerSubView = switchDialerSubView;

  /* Language switcher for Message Designer */
  function setDesignerLang(lang) {
    document.querySelectorAll('#langBtnAmharic, #langBtnOromifa, #langBtnEnglish').forEach(b => b.className = 'des-lang-btn');
    const txtArea = document.getElementById('campaignMessageText');
    if (lang === 'amharic') {
      const b = document.getElementById('langBtnAmharic');
      if (b) b.className = 'des-lang-btn active';
      if (txtArea) txtArea.value = 'ውድ ደንበኛ: የ 450 ብር የክፍያ ሂሳብዎ በጥቅምት 15 ቀን ይጠናቀቃል። በቴሌብር ለመክፈል 1ን ይጫኑ: ጥሪ ለመጠየቅ 2ን: ከደንበኞች አገልግሎት ጋር ለመነጋገር 3ን ይጫኑ።';
    } else if (lang === 'oromifa') {
      const b = document.getElementById('langBtnOromifa');
      if (b) b.className = 'des-lang-btn active';
      if (txtArea) txtArea.value = 'Kabajamoo maamila: kaffaltiin keessan Qarshii 450 Onkololeessa 15tti xumurama. Telebirriin kaffaluuf 1 dhiibaa: bilbila deebii gaafachuuf 2: tajaajila maamiltootaaf 3 dhiibaa.';
    } else {
      const b = document.getElementById('langBtnEnglish');
      if (b) b.className = 'des-lang-btn active';
      if (txtArea) txtArea.value = 'Dear customer: your payment balance of 450 ETB will expire on October 15. Press 1 to pay via Telebirr: press 2 to request a callback: press 3 to speak with customer service.';
    }
  }

  function playVoicePreview() {
    const sel = document.getElementById('designerAudioPromptSelect');
    const player = document.getElementById('mainAudioPlayer');
    const audioSrc = (sel && sel.value) ? sel.value : 'assets/audio/welcome_human_voice.wav';
    if (player) {
      player.src = audioSrc;
      player.currentTime = 0;
      player.play().catch(e => console.log('Audio autoplay prevented:', e));
    }
    if (window.app && app.showToast) {
      const opt = (sel && sel.selectedIndex >= 0) ? sel.options[sel.selectedIndex].text : 'Human Voice Prompt';
      app.showToast('Playing preview: ' + opt, 'info');
    }
  }

  /* Agent Screen Actions */
  function selectAgentOutcome(labelEl, dispo) {
    if (window.app && app.selectAgentOutcome) {
      app.selectAgentOutcome(labelEl, dispo);
    }
  }
  window.selectAgentOutcome = selectAgentOutcome;

  function toggleAgentCheck(item) {
    if (window.app && app.toggleAgentCheck) {
      app.toggleAgentCheck(item);
    }
  }
  window.toggleAgentCheck = toggleAgentCheck;

  function setPacingMode(mode) {
    if (window.app && app.setPacingMode) {
      app.setPacingMode(mode);
    }
  }
  window.setPacingMode = setPacingMode;

  function selectAgentDispo(btn, dispo) {
    if (window.app && app.selectAgentDispo) {
      app.selectAgentDispo(btn, dispo);
    } else {
      document.querySelectorAll('.sc-dispo-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
    }
  }

  function handleAgentDialClick() {
    if (window.app && app.handleAgentDialClick) {
      app.handleAgentDialClick();
    }
  }

  function handleAgentSkipClick() {
    if (window.app && app.handleAgentSkipClick) {
      app.handleAgentSkipClick();
    }
  }

  function handleAgentSaveNextClick() {
    if (window.app && app.handleAgentSaveNextClick) {
      app.handleAgentSaveNextClick();
    }
  }

  function toggleSidebarMenu() {
    document.getElementById('sidebarMenu')?.classList.toggle('collapsed');
    document.getElementById('mainContainer')?.classList.toggle('sidebar-collapsed');
  }

  function toggleStatusMenu() {
    document.getElementById('statusDropMenu')?.classList.toggle('open');
  }

  function setAgentStatus(st) {
    const label = document.getElementById('statusLabel');
    const dot = document.getElementById('statusDot');
    if (st === 'ready') { if (label) label.textContent = 'Ready'; if (dot) dot.className = 'sdot ready'; }
    else if (st === 'idle') { if (label) label.textContent = 'Idle'; if (dot) dot.className = 'sdot notready'; }
    else if (st === 'break') { if (label) label.textContent = 'On Break'; if (dot) dot.className = 'sdot brk'; }
    document.getElementById('statusDropMenu')?.classList.remove('open');
  }

  function updateClock() {
    const now = new Date();
    const str = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const clockEl = document.getElementById('liveClock');
    if (clockEl) clockEl.textContent = str;
  }
  setInterval(updateClock, 1000);
  updateClock();

  function openSipSettingsModal() {
    document.getElementById('settingsModal')?.classList.add('show');
    document.getElementById('statusDropMenu')?.classList.remove('open');
  }

  function closeSipSettingsModal() {
    document.getElementById('settingsModal')?.classList.remove('show');
  }

  function openChangePasswordModal() {
    const cp = document.getElementById('cpCurrentPassword');
    const np = document.getElementById('cpNewPassword');
    const cnp = document.getElementById('cpConfirmPassword');
    if (cp) cp.value = '';
    if (np) np.value = '';
    if (cnp) cnp.value = '';
    const alertEl = document.getElementById('changePassAlert');
    if (alertEl) { alertEl.style.display = 'none'; alertEl.textContent = ''; }
    document.getElementById('modalChangePassword')?.classList.add('open');
    document.getElementById('statusDropMenu')?.classList.remove('open');
  }

  function closeChangePasswordModal() {
    document.getElementById('modalChangePassword')?.classList.remove('open');
  }

  async function handleChangePasswordSubmit(e) {
    e.preventDefault();
    const currentPass = document.getElementById('cpCurrentPassword')?.value || '';
    const newPass = document.getElementById('cpNewPassword')?.value || '';
    const confirmPass = document.getElementById('cpConfirmPassword')?.value || '';
    const alertEl = document.getElementById('changePassAlert');
    const btn = document.getElementById('btnSubmitChangePass');

    if (newPass !== confirmPass) {
      if (alertEl) {
        alertEl.style.display = 'block';
        alertEl.style.background = '#fee2e2';
        alertEl.style.color = '#b91c1c';
        alertEl.style.border = '1px solid #fecaca';
        alertEl.textContent = 'New password and confirm password do not match.';
      }
      return;
    }

    if (btn) { btn.disabled = true; btn.textContent = 'Updating...'; }

    try {
      const resp = await fetch('api.php?action=change_password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ current_password: currentPass, new_password: newPass })
      });
      const data = await resp.json();

      if (data && data.success) {
        if (alertEl) {
          alertEl.style.display = 'block';
          alertEl.style.background = '#dcfce7';
          alertEl.style.color = '#15803d';
          alertEl.style.border = '1px solid #bbf7d0';
          alertEl.textContent = 'Password changed successfully!';
        }
        setTimeout(() => closeChangePasswordModal(), 1200);
      } else {
        if (alertEl) {
          alertEl.style.display = 'block';
          alertEl.style.background = '#fee2e2';
          alertEl.style.color = '#b91c1c';
          alertEl.style.border = '1px solid #fecaca';
          alertEl.textContent = data.error || 'Failed to update password.';
        }
      }
    } catch (err) {
      if (alertEl) {
        alertEl.style.display = 'block';
        alertEl.style.background = '#fee2e2';
        alertEl.style.color = '#b91c1c';
        alertEl.style.border = '1px solid #fecaca';
        alertEl.textContent = 'Network or server error while changing password.';
      }
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = 'Update Password'; }
    }
  }

  async function saveSipSettings(e) {
    e.preventDefault();
    const server = document.getElementById('sipServer')?.value.trim();
    const port   = document.getElementById('sipPort')?.value.trim();
    const domain = document.getElementById('sipDomain')?.value.trim();
    const ext    = document.getElementById('sipExt')?.value.trim();
    const pass   = document.getElementById('sipPass')?.value.trim();
    const ext2   = document.getElementById('sipExt2')?.value.trim();
    const pass2  = document.getElementById('sipPass2')?.value.trim();
    const line2  = document.getElementById('line2EnabledCheck')?.checked ? 1 : 0;

    try {
      const resp = await fetch('api.php?action=update_sip_settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          sip_server: server,
          sip_port: port,
          sip_domain: domain,
          sip_extension: ext,
          sip_password: pass,
          sip_extension2: ext2,
          sip_password2: pass2,
          line2_enabled: line2
        })
      });
      const data = await resp.json();
      if (data && data.success) {
        window.AGENT_CONFIG.server = server;
        window.AGENT_CONFIG.port   = port;
        window.AGENT_CONFIG.domain = domain;
        window.AGENT_CONFIG.ext    = ext;
        window.AGENT_CONFIG.pass   = pass;
        window.AGENT_CONFIG.ext2   = ext2;
        window.AGENT_CONFIG.pass2  = pass2;
        window.AGENT_CONFIG.line2Enabled = (line2 === 1);

        closeSipSettingsModal();
        if (window.app && app.showToast) {
          app.showToast('SIP Extension settings updated & reconnected!', 'success');
        }
        if (window.app && app.initSipSoftphone) {
          app.initSipSoftphone();
        }
      } else {
        alert('Failed to save settings: ' + (data.error || 'Unknown error'));
      }
    } catch (err) {
      alert('Network error saving SIP settings.');
    }
  }
  </script>

  <!-- Production SIP.js Softphone Engine & Core Dialer JS -->
  <script src="assets/js/sipjs.bundle.js?v=<?= time() ?>"></script>
  <script>
    if (!window.SIPjs && window.SIP) window.SIPjs = window.SIP;
  </script>
  <script src="assets/js/dialer.js?v=<?= time() ?>"></script>
</body>
</html>
