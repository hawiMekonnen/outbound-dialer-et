/**
 * SkyKin Automatic Outbound Dialer - Production Controller & WebRTC Bridge
 * Direct SIP Calling (with Ethiopian Number Normalization) + Automated IVR Engine
 */

// Ethiopian Dial Normalization (e.g. 09XXXXXXXX -> 9XXXXXXXX)
window.skykinNormalizeEtDial = function(raw) {
  var s = String(raw || '').trim();
  if (!s) return s;
  if (/^2\d{2}$/.test(s) || /^1\d{2}$/.test(s)) return s; // Internal extensions like 101, 102
  var d = s.replace(/\D/g, '');
  if (!d) return s;
  if (d.indexOf('00251') === 0 && d.length >= 12) d = d.slice(5);
  else if (d.indexOf('251') === 0 && d.length >= 12) d = d.slice(3);
  if (d.length === 10 && d.charAt(0) === '0') {
    if (/^09\d{8}$/.test(d) || /^07\d{8}$/.test(d)) return d.slice(1);
    return d;
  }
  if (d.length === 9) {
    if (/^[97]\d{8}$/.test(d)) return d;
    if (/^[1-8]\d{8}$/.test(d)) return '0' + d;
  }
  return d;
};

class SkyKinDialerApp {
  constructor() {
    this.campaign = null;
    this.leads = [];
    this.stats = {};
    this.audioRecordings = [];
    this.activeChannels = new Map();
    this.isRunning = false;
    this.isPaused = false;
    this.activeAudioPlayer = null;

    // Outbound Softphone WebRTC state (Dual-Line Engine)
    this.line1 = {
      num: 1,
      ext: '101',
      pass: '1234',
      ua: null,
      reg: null,
      isRegistered: false,
      session: null,
      activeChannelId: null,
      audioCtx: null,
      ivrSource: null
    };
    this.line2 = {
      num: 2,
      ext: '102',
      pass: '1234',
      enabled: true,
      ua: null,
      reg: null,
      isRegistered: false,
      session: null,
      activeChannelId: null,
      audioCtx: null,
      ivrSource: null
    };

    this.cachedAudioMap = new Map();
    this.manualTimerInterval = null;
    this.manualStartTime = null;
    this.isMuted = false;
    this.isHeld = false;
    this.ringbackCtx = null;
    this.ringbackInterval = null;
    this.selectedCampaignIds = [1];
    this.selectedCampaigns = [];

    this.initElements();
    this.initEventListeners();
    try {
      this.initSipTelephony();
    } catch(e) {
      console.warn('SIP Telephony init error:', e);
    }
    this.loadCampaignData();
    this.loadCampaignsOverview();
    this.loadCallbacksList();
  }

  initElements() {
    this.btnStart = document.getElementById('btnStartCampaign');
    this.btnPause = document.getElementById('btnPauseCampaign');
    this.btnStop = document.getElementById('btnStopCampaign');
    this.btnReset = document.getElementById('btnResetLeads');
    this.btnExportLogs = document.getElementById('btnExportLogs');

    this.channelsContainer = document.getElementById('activeChannelsGrid');
    this.leadsTableBody = document.getElementById('leadsTableBody');
    this.leadsTableBodyFull = document.getElementById('leadsTableBodyFull');
    this.logsTableBody = document.getElementById('logsTableBody');

    this.campaignStatusTag = document.getElementById('campaignStatusTag');
    this.campaignTitleText = document.getElementById('campaignTitleText');
    this.audioSelectDropdown = document.getElementById('ivrAudioSelector');
    this.audioSelectDropdownTab = document.getElementById('ivrAudioSelectorTab');
    this.audioPlayerElement = document.getElementById('mainAudioPlayer');
    this.tabAudioPlayer = document.getElementById('tabAudioPlayer');
    this.activeAudioTitle = document.getElementById('activeAudioTitle');

    // KPI fields
    this.kpiTotal = document.getElementById('kpiTotalLeads');
    this.kpiPending = document.getElementById('kpiPendingLeads');
    this.kpiConnected = document.getElementById('kpiConnectedLeads');
    this.kpiAnswerRate = document.getElementById('kpiAnswerRate');
    this.kpiAvgDuration = document.getElementById('kpiAvgDuration');
  }

  initEventListeners() {
    this.btnStart?.addEventListener('click', () => this.startCampaign());
    this.btnPause?.addEventListener('click', () => this.togglePauseCampaign());
    this.btnStop?.addEventListener('click', () => this.stopCampaign());
    this.btnReset?.addEventListener('click', () => this.resetLeads());
    this.btnExportLogs?.addEventListener('click', () => {
      window.location.href = 'api.php?action=export_logs_csv&campaign_id=' + (this.campaign?.id || 1);
    });

    this.audioSelectDropdown?.addEventListener('change', (e) => this.selectAudioRecording(e.target.value));

    // Modals
    document.querySelectorAll('.btn-close-modal').forEach(btn => {
      btn.addEventListener('click', () => this.closeAllModals());
    });

    // Upload Lead File Form
    document.getElementById('formUploadLeads')?.addEventListener('submit', (e) => this.handleFileUpload(e));
    document.getElementById('formManualLeads')?.addEventListener('submit', (e) => this.handleManualUpload(e));
    document.getElementById('formUploadAudio')?.addEventListener('submit', (e) => this.handleAudioUpload(e));

    // Drag and Drop
    const dropzone = document.getElementById('dropzoneBox');
    const fileInput = document.getElementById('leadFileInput');
    if (dropzone && fileInput) {
      dropzone.addEventListener('click', () => fileInput.click());
      fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
          document.getElementById('selectedFileName').textContent = fileInput.files[0].name;
        }
      });
      ['dragenter', 'dragover'].forEach(ev => dropzone.addEventListener(ev, (e) => { e.preventDefault(); dropzone.classList.add('dragover'); }));
      ['dragleave', 'drop'].forEach(ev => dropzone.addEventListener(ev, (e) => {
        e.preventDefault();
        dropzone.classList.remove('dragover');
        if (ev === 'drop' && e.dataTransfer.files.length > 0) {
          fileInput.files = e.dataTransfer.files;
          document.getElementById('selectedFileName').textContent = fileInput.files[0].name;
        }
      }));
    }

    // Lead Search & Filter
    document.getElementById('leadSearchInput')?.addEventListener('input', () => this.renderLeadsTables());
    document.getElementById('leadStatusFilter')?.addEventListener('change', () => this.renderLeadsTables());
  }

  /* ─────────────────────────────────────────────────────────
     SIP.js Dual-Line WebRTC Registration & Softphone Bridge
     ───────────────────────────────────────────────────────── */
  initSipTelephony() {
    const cfg = window.AGENT_CONFIG || {};
    const ext = localStorage.getItem('sip_ext') || cfg.ext || '101';
    const pass = localStorage.getItem('sip_pass') || cfg.pass || '1234';
    const ext2 = localStorage.getItem('sip_ext2') || cfg.ext2 || '102';
    const pass2 = localStorage.getItem('sip_pass2') || cfg.pass2 || '1234';
    const l2Stored = localStorage.getItem('line2_enabled');
    const l2Enabled = l2Stored !== null ? (l2Stored === '1' || l2Stored === 'true') : (cfg.line2Enabled !== false);
    const server = localStorage.getItem('sip_server') || cfg.server || 'webcc.skyconnectsolutions.et';
    const port = localStorage.getItem('sip_port') || cfg.port || '443';
    const dom = localStorage.getItem('sip_domain') || cfg.domain || 'client1.skykin.local';

    // Populate modal inputs
    if (document.getElementById('sipExt')) document.getElementById('sipExt').value = ext;
    if (document.getElementById('sipPass')) document.getElementById('sipPass').value = pass;
    if (document.getElementById('sipExt2')) document.getElementById('sipExt2').value = ext2;
    if (document.getElementById('sipPass2')) document.getElementById('sipPass2').value = pass2;
    if (document.getElementById('line2EnabledCheck')) document.getElementById('line2EnabledCheck').checked = l2Enabled;
    if (document.getElementById('sipServer')) document.getElementById('sipServer').value = server;
    if (document.getElementById('sipPort')) document.getElementById('sipPort').value = port;
    if (document.getElementById('sipDomain')) document.getElementById('sipDomain').value = dom;

    this.connectDualSip({ ext, pass, ext2, pass2, l2Enabled, server, port, dom });
  }

  connectDualSip(config) {
    const { ext, pass, ext2, pass2, l2Enabled, server, port, dom } = config;
    this.line1.ext = ext || '101';
    this.line1.pass = pass || '1234';
    this.line2.ext = ext2 || '102';
    this.line2.pass = pass2 || '1234';
    this.line2.enabled = !!l2Enabled;
    this.sipServer = server;
    this.sipPort = port;
    this.sipDomain = dom;

    this.updateSipStatus('connecting', 'Connecting Dual SIP...');

    // Build WebSocket URI
    let wsUri = server.trim();
    if (!wsUri.startsWith('wss://') && !wsUri.startsWith('ws://')) {
      const isHttps = typeof location !== 'undefined' && location.protocol === 'https:';
      wsUri = (isHttps ? 'wss://' : 'ws://') + wsUri;
    }
    const hostPart = wsUri.replace(/^wss?:\/\//i, '');
    if (!hostPart.includes('/')) {
      if (port && port !== '443' && port !== '80' && !hostPart.includes(':')) {
        wsUri = wsUri + ':' + port + '/wss/';
      } else {
        wsUri = wsUri + '/wss/';
      }
    }
    this.wsUri = wsUri;

    const startBridge = (tries = 0) => {
      const SIP = window.SIPjs || window.SIP;
      if (window.SIP && !window.SIPjs) window.SIPjs = window.SIP;
      if (!SIP || !SIP.UserAgent) {
        if (tries < 30) {
          setTimeout(() => startBridge(tries + 1), 150);
          return;
        }
        this.updateSipStatus('failed', 'SIP softphone standby');
        return;
      }

      const { UserAgent, Registerer, RegistererState } = SIP;

      // Register Line 1
      this.initSingleLine(this.line1, this.line1.ext, this.line1.pass, dom, wsUri, UserAgent, Registerer, RegistererState);

      // Register Line 2 if enabled
      if (this.line2.enabled && this.line2.ext) {
        this.initSingleLine(this.line2, this.line2.ext, this.line2.pass, dom, wsUri, UserAgent, Registerer, RegistererState);
      } else {
        this.teardownLine(this.line2);
      }

      // Softphone bridge methods for UI
      window.sipBridge = {
        call: (target, d, lNum = 1) => this.sipMakeCall(target, d || dom, {}, lNum),
        hangup: (lNum) => this.sipHangup(lNum),
        hold: (lNum) => this.sipHold(lNum),
        unhold: (lNum) => this.sipUnhold(lNum),
        mute: (lNum) => this.sipMute(lNum),
        unmute: (lNum) => this.sipUnmute(lNum),
        sendDtmf: (tone, lNum) => this.sipSendDtmf(tone, lNum)
      };
    };

    startBridge();
  }

  initSingleLine(lineObj, ext, pass, dom, wsUri, UserAgent, Registerer, RegistererState) {
    this.teardownLine(lineObj);

    lineObj.ext = ext;
    lineObj.pass = pass;

    const sipUri = UserAgent.makeURI('sip:' + ext + '@' + dom);
    if (!sipUri) {
      console.warn(`Invalid SIP URI: sip:${ext}@${dom}`);
      return;
    }

    try {
      lineObj.ua = new UserAgent({
        uri: sipUri,
        transportOptions: {
          server: wsUri,
          connectionTimeout: 10,
          traceSip: false
        },
        authorizationUsername: ext,
        authorizationPassword: pass,
        contactParams: { transport: 'wss' },
        logLevel: 'error',
        sessionDescriptionHandlerFactoryOptions: {
          peerConnectionConfiguration: {
            iceServers: [
              { urls: 'stun:stun.cloudflare.com:3478' },
              { urls: 'stun:stun.l.google.com:19302' }
            ]
          }
        }
      });

      lineObj.reg = new Registerer(lineObj.ua, { expires: 300 });

      lineObj.reg.stateChange.addListener(state => {
        if (state === RegistererState.Registered || state === 'Registered') {
          lineObj.isRegistered = true;
          this.updateSipStatus();
          this.showToast(`Line ${lineObj.num} (${ext}) Registered`, 'success');
        } else if (state === RegistererState.Unregistered || state === 'Unregistered') {
          lineObj.isRegistered = false;
          this.updateSipStatus();
        } else if (state === RegistererState.Terminated || state === 'Terminated') {
          lineObj.isRegistered = false;
          this.updateSipStatus();
        }
      });

      lineObj.ua.start()
        .then(() => {
          lineObj.reg.register();
        })
        .catch(err => {
          console.warn(`Line ${lineObj.num} SIP UA start notice:`, err);
          lineObj.isRegistered = false;
          this.updateSipStatus();
        });
    } catch (err) {
      console.error(`Line ${lineObj.num} SIP init error:`, err);
    }
  }

  teardownLine(lineObj) {
    if (lineObj.ua) {
      try {
        if (lineObj.reg) lineObj.reg.unregister();
        lineObj.ua.stop();
      } catch (e) {}
      lineObj.ua = null;
      lineObj.reg = null;
      lineObj.isRegistered = false;
      lineObj.session = null;
      lineObj.activeChannelId = null;
    }
  }

  reconnectDualSip(ext, pass, ext2, pass2, l2Enabled, server, port, dom) {
    localStorage.setItem('sip_ext', ext);
    localStorage.setItem('sip_pass', pass);
    localStorage.setItem('sip_ext2', ext2);
    localStorage.setItem('sip_pass2', pass2);
    localStorage.setItem('line2_enabled', l2Enabled ? '1' : '0');
    localStorage.setItem('sip_server', server);
    localStorage.setItem('sip_port', port);
    localStorage.setItem('sip_domain', dom);

    this.showToast('Re-connecting SIP Lines...', 'info');
    this.connectDualSip({ ext, pass, ext2, pass2, l2Enabled, server, port, dom });
  }

  updateSipStatus(customState, customText) {
    const dot = document.getElementById('sipDot');
    const headerDot = document.getElementById('headerSipDot');
    const badge = document.getElementById('fabBadge');
    const statusText = document.getElementById('sipStatusText');
    const headerText = document.getElementById('headerSipStatusText');

    let state = 'unregistered';
    let text = '';

    if (customState && customText) {
      state = customState;
      text = customText;
    } else {
      const l1Ready = this.line1.isRegistered;
      const l2Ready = this.line2.enabled && this.line2.isRegistered;

      if (this.line1.session || this.line2.session) {
        state = 'calling';
      } else if (l1Ready || l2Ready) {
        state = 'registered';
      } else {
        state = 'unregistered';
      }

      if (this.line2.enabled && this.line2.ext) {
        const l1Str = `L1: ${this.line1.ext} (${l1Ready ? 'Ready' : 'Off'})`;
        const l2Str = `L2: ${this.line2.ext} (${l2Ready ? 'Ready' : 'Off'})`;
        text = `${l1Str} | ${l2Str}`;
      } else {
        text = `Ext: ${this.line1.ext || '101'} (${l1Ready ? 'Ready' : 'Off'})`;
      }
    }

    [dot, headerDot].forEach(d => {
      if (!d) return;
      d.className = 'sip-dot ' + state;
    });

    if (badge) {
      badge.className = 'fab-badge show ' + (state === 'registered' ? '' : (state === 'calling' ? 'calling' : 'unreg'));
    }

    if (statusText) statusText.textContent = text;
    if (headerText) headerText.textContent = text;
  }

  async sipMakeCall(rawTarget, dom, callbacks = {}, lineNum = 1) {
    const lineObj = (lineNum === 2 && this.line2.enabled) ? this.line2 : this.line1;
    const SIP = window.SIPjs || window.SIP;

    if (!lineObj.ua || !SIP) {
      if (callbacks.onError) callbacks.onError(new Error(`Line ${lineObj.num} SIP not initialized`));
      this.showToast(`Line ${lineObj.num} SIP is not initialized`, 'error');
      return;
    }

    const target = window.skykinNormalizeEtDial(rawTarget);
    const targetUri = SIP.UserAgent.makeURI('sip:' + target + '@' + (dom || this.sipDomain || 'client1.skykin.local'));
    if (!targetUri) {
      if (callbacks.onError) callbacks.onError(new Error('Invalid phone number'));
      this.showToast('Invalid target phone number', 'error');
      return;
    }

    // Hangup existing session on this line cleanly
    if (lineObj.session) {
      try {
        if (lineObj.session.dispose) lineObj.session.dispose();
      } catch (e) {}
      lineObj.session = null;
    }

    // Acquire microphone permission once if possible
    try {
      if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
      }
    } catch (e) {
      console.warn('Microphone permission:', e);
    }

    try {
      const inviter = new SIP.Inviter(lineObj.ua, targetUri, {
        sessionDescriptionHandlerOptions: {
          constraints: { audio: true, video: false },
          peerConnectionConfiguration: {
            iceServers: [
              { urls: 'stun:stun.cloudflare.com:3478' },
              { urls: 'stun:stun.l.google.com:19302' }
            ]
          }
        }
      });
      lineObj.session = inviter;
      this.updateSipStatus();

      inviter.stateChange.addListener(state => {
        if (state === SIP.SessionState.Establishing) {
          if (callbacks.onEstablishing) callbacks.onEstablishing();
        }
        if (state === SIP.SessionState.Established) {
          this.stopRingbackTone();
          const pc = inviter.sessionDescriptionHandler?.peerConnection;
          if (pc) this.attachAudio(pc);
          this.showToast(`Line ${lineObj.num}: Connected to ${target}`, 'success');
          if (callbacks.onEstablished) callbacks.onEstablished(pc);
        }
        if (state === SIP.SessionState.Terminated) {
          this.stopRingbackTone();
          this.stopCurrentIvrAudio(lineObj.num);
          lineObj.session = null;
          this.updateSipStatus();
          if (callbacks.onTerminated) callbacks.onTerminated();
        }
      });

      await inviter.invite({
        requestDelegate: {
          onProgress: () => {
            if (callbacks.onProgress) callbacks.onProgress();
          },
          onAccept: () => {
            this.stopRingbackTone();
          },
          onReject: (response) => {
            this.stopRingbackTone();
            this.showToast(`Line ${lineObj.num} declined: ${response.statusCode} ${response.reasonPhrase}`, 'warning');
            if (callbacks.onReject) callbacks.onReject(response);
          }
        }
      });

    } catch (e) {
      this.stopRingbackTone();
      console.error(`Line ${lineObj.num} SIP Call invite failed:`, e);
      if (callbacks.onError) callbacks.onError(e);
    }
  }

  /* ─────────────────────────────────────────────────────────
     IVR In-Call Audio Stream Injection (Streams into Phone Call)
     ───────────────────────────────────────────────────────── */
  async getAudioArrayBuffer(audioUrl) {
    if (this.cachedAudioMap.has(audioUrl)) {
      const cached = this.cachedAudioMap.get(audioUrl);
      return cached.slice(0);
    }
    try {
      const response = await fetch(audioUrl);
      const buf = await response.arrayBuffer();
      this.cachedAudioMap.set(audioUrl, buf);
      return buf.slice(0);
    } catch(fetchErr) {
      console.warn('Could not fetch audioUrl directly, trying relative path:', fetchErr);
      const response = await fetch(audioUrl.replace(/^assets\//, 'assets/'));
      const buf = await response.arrayBuffer();
      this.cachedAudioMap.set(audioUrl, buf);
      return buf.slice(0);
    }
  }

  async playIvrAudioIntoPeerConnection(audioUrl, pc, onEnded, lineNum = 1) {
    if (!audioUrl) {
      if (onEnded) onEnded();
      return;
    }

    const lineObj = lineNum === 2 ? this.line2 : this.line1;

    // Clean up previous IVR audio context for this line
    if (lineObj.ivrSource) {
      try { lineObj.ivrSource.stop(); } catch(e) {}
      lineObj.ivrSource = null;
    }
    if (lineObj.audioCtx) {
      try { lineObj.audioCtx.close(); } catch(e) {}
      lineObj.audioCtx = null;
    }

    try {
      const AudioCtxClass = window.AudioContext || window.webkitAudioContext;
      const audioCtx = new AudioCtxClass();
      if (audioCtx.state !== 'running') {
        try { await audioCtx.resume(); } catch(e) {}
      }
      lineObj.audioCtx = audioCtx;

      // Get cloned ArrayBuffer for clean decoding without detaching issues
      const arrayBuffer = await this.getAudioArrayBuffer(audioUrl);
      const audioBuffer = await audioCtx.decodeAudioData(arrayBuffer);
      const durationSec = Math.max(2, audioBuffer.duration || 5);

      const source = audioCtx.createBufferSource();
      source.buffer = audioBuffer;

      const gainNode = audioCtx.createGain();
      gainNode.gain.value = 1.0;

      const destination = audioCtx.createMediaStreamDestination();
      source.connect(gainNode);
      gainNode.connect(destination);

      const ivrTrack = destination.stream.getAudioTracks()[0];
      if (ivrTrack) {
        ivrTrack.enabled = true;
      }

      // Robust sender attachment with retry loop to ensure track is replaced even if sender initializes asynchronously
      if (pc && ivrTrack) {
        let attached = false;
        for (let attempt = 0; attempt < 15; attempt++) {
          const senders = pc.getSenders ? pc.getSenders() : [];
          let audioSender = senders.find(s => s.track && s.track.kind === 'audio');
          if (!audioSender && senders.length > 0) {
            audioSender = senders.find(s => !s.track || (s.track && s.track.kind === 'audio')) || senders[0];
          }
          if (audioSender && audioSender.replaceTrack) {
            try {
              await audioSender.replaceTrack(ivrTrack);
              console.log(`[Line ${lineNum}] IVR Audio track replaced on sender (attempt ${attempt + 1})`);
              attached = true;
              break;
            } catch(e) {
              console.warn(`[Line ${lineNum}] replaceTrack error on attempt ${attempt + 1}:`, e);
            }
          }
          await new Promise(r => setTimeout(r, 100));
        }
        if (!attached) {
          console.warn(`[Line ${lineNum}] Could not attach IVR track to peerConnection senders`);
        }
      }

      lineObj.ivrSource = source;

      let finished = false;
      const finishPlayback = () => {
        if (finished) return;
        finished = true;
        lineObj.ivrSource = null;
        // Wait 1.5 seconds after audio finishes so customer hears the entire message completely
        setTimeout(() => {
          if (onEnded) onEnded();
        }, 1500);
      };

      source.onended = finishPlayback;
      source.start(0);

      // Fallback safety timer: guarantees completion after full audio length + 2s buffer
      setTimeout(() => {
        finishPlayback();
      }, (durationSec + 2) * 1000);

      return { source, duration: durationSec };
    } catch (err) {
      console.error(`[Line ${lineNum}] Error in playIvrAudioIntoPeerConnection:`, err);
      // Wait 8 seconds before hangup if audio decode had an issue so call isn't dropped instantly
      setTimeout(() => {
        if (onEnded) onEnded();
      }, 8000);
      return null;
    }
  }

  stopCurrentIvrAudio(lineNum = null) {
    const stopLineAudio = (lineObj) => {
      if (lineObj) {
        if (lineObj.ivrSource) {
          try { lineObj.ivrSource.stop(); } catch(e) {}
          lineObj.ivrSource = null;
        }
        if (lineObj.audioCtx) {
          try { lineObj.audioCtx.close(); } catch(e) {}
          lineObj.audioCtx = null;
        }
      }
    };

    if (lineNum === 1) {
      stopLineAudio(this.line1);
    } else if (lineNum === 2) {
      stopLineAudio(this.line2);
    } else {
      stopLineAudio(this.line1);
      stopLineAudio(this.line2);
    }
  }

  sipHangup(lineNum = null) {
    this.stopRingbackTone();
    this.stopCurrentIvrAudio(lineNum);

    const hangupSession = (lineObj) => {
      if (lineObj && lineObj.session) {
        try {
          const SIP = window.SIPjs || window.SIP;
          const s = lineObj.session;
          if (s.state === SIP?.SessionState?.Established || s.state === 'Established') {
            if (s.bye) s.bye();
          } else if (s.state === SIP?.SessionState?.Establishing || s.state === 'Establishing') {
            if (s.cancel) s.cancel();
          } else {
            if (s.bye) s.bye();
            else if (s.cancel) s.cancel();
          }
        } catch (e) {
          console.warn(`Line ${lineObj.num} SIP Hangup notice:`, e);
        }
        try {
          if (lineObj.session.dispose) lineObj.session.dispose();
        } catch(e) {}
        lineObj.session = null;
      }
    };

    if (lineNum === 1) {
      hangupSession(this.line1);
    } else if (lineNum === 2) {
      hangupSession(this.line2);
    } else {
      hangupSession(this.line1);
      hangupSession(this.line2);
    }
    this.updateSipStatus();
  }

  sipHold(lineNum = 1) {
    const lineObj = lineNum === 2 ? this.line2 : this.line1;
    if (lineObj.session && lineObj.session.hold) lineObj.session.hold();
  }

  sipUnhold(lineNum = 1) {
    const lineObj = lineNum === 2 ? this.line2 : this.line1;
    if (lineObj.session && lineObj.session.unhold) lineObj.session.unhold();
  }

  sipMute(lineNum = 1) {
    const lineObj = lineNum === 2 ? this.line2 : this.line1;
    const pc = lineObj.session?.sessionDescriptionHandler?.peerConnection;
    if (pc) pc.getSenders().forEach(s => { if (s.track) s.track.enabled = false; });
  }

  sipUnmute(lineNum = 1) {
    const lineObj = lineNum === 2 ? this.line2 : this.line1;
    const pc = lineObj.session?.sessionDescriptionHandler?.peerConnection;
    if (pc) pc.getSenders().forEach(s => { if (s.track) s.track.enabled = true; });
  }

  sipSendDtmf(tone, lineNum = 1) {
    const lineObj = lineNum === 2 ? this.line2 : this.line1;
    if (lineObj.session && lineObj.session.sessionDescriptionHandler) {
      try { lineObj.session.sessionDescriptionHandler.sendDtmf(tone); } catch(e) {}
    }
  }

  attachAudio(pc, isManualCall = false) {
    let el = document.getElementById('remoteAudio');
    if (!el) {
      el = document.createElement('audio');
      el.id = 'remoteAudio';
      el.autoplay = true;
      el.muted = true;
      el.volume = 0;
      document.body.appendChild(el);
    }
    
    // Completely silence computer speakers during calls
    el.muted = true;
    el.volume = 0;

    const remote = new MediaStream();
    pc.getReceivers().forEach(r => { if (r.track) remote.addTrack(r.track); });
    pc.ontrack = (ev) => {
      if (el) {
        el.muted = true;
        el.volume = 0;
      }
    };
  }

  startRingbackTone() {
    this.stopRingbackTone();
    // Computer speakers remain silent during dialing
    return;
  }

  stopRingbackTone() {
    if (this.ringbackInterval) {
      clearInterval(this.ringbackInterval);
      this.ringbackInterval = null;
    }
    if (this.ringbackCtx) {
      try { this.ringbackCtx.close(); } catch(e) {}
      this.ringbackCtx = null;
    }
  }

  openModal(modalId) {
    document.getElementById(modalId)?.classList.add('open');
  }

  closeModal(modalId) {
    if (modalId) {
      document.getElementById(modalId)?.classList.remove('open');
    } else {
      this.closeAllModals();
    }
  }

  closeAllModals() {
    document.querySelectorAll('.modal-backdrop').forEach(m => m.classList.remove('open'));
  }

  handleSingleAddSubmit(e) { return this.handleModalSingleAdd(e); }
  handleUploadSubmit(e) { return this.handleFileUpload(e); }
  handleManualSubmit(e) { return this.handleManualUpload(e); }
  handleAudioUploadSubmit(e) { return this.handleAudioUpload(e); }

  exportContactsCsv() {
    window.location.href = 'api.php?action=export_contacts_csv&campaign_id=' + (this.campaign?.id || 1);
  }

  async loadReportsAndLogs() {
    await this.loadCampaignData();
    this.renderHistoryTable();
  }

  showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    const icon = type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ';
    toast.innerHTML = `<span>${icon}</span><span>${message}</span>`;
    container.appendChild(toast);
    setTimeout(() => {
      toast.style.opacity = '0';
      setTimeout(() => toast.remove(), 300);
    }, 4000);
  }

  /* ─────────────────────────────────────────────────────────
     Data Loading & Queue Manager
     ───────────────────────────────────────────────────────── */
  async loadCampaignData(campaignInput = null) {
    try {
      let ids = [];
      if (Array.isArray(campaignInput)) {
        ids = campaignInput.map(Number).filter(n => n > 0);
      } else if (typeof campaignInput === 'string' && campaignInput.includes(',')) {
        ids = campaignInput.split(',').map(Number).filter(n => n > 0);
      } else if (campaignInput !== null && campaignInput !== undefined) {
        ids = [Number(campaignInput)];
      } else if (this.selectedCampaignIds && this.selectedCampaignIds.length > 0) {
        ids = this.selectedCampaignIds.map(Number);
      } else {
        ids = [this.activeCampaignId || this.campaign?.id || 1];
      }

      this.selectedCampaignIds = ids;
      if (ids.length === 1) {
        this.activeCampaignId = ids[0];
      }

      const res = await fetch(`api.php?action=get_campaign_data&campaign_ids=${encodeURIComponent(ids.join(','))}`);
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Failed to load');

      this.campaign = data.campaign;
      this.selectedCampaigns = data.selected_campaigns || (data.campaign ? [data.campaign] : []);
      this.leads = data.leads || [];
      this.stats = data.stats || {};
      this.audioRecordings = data.audio_recordings || [];
      this.recentLogs = data.recent_logs || [];
      this.serverToday = data.server_today || new Date().toISOString().split('T')[0];
      this.availableDates = data.available_dates || [];
      this.remainingCount = data.remaining_count || 0;

      this.renderCampaignHeader();
      this.renderDialerCampaignSelector();
      this.renderStats();
      this.renderLeadsTables();
      this.renderActiveChannels();
      this.renderUnansweredTable();
      this.renderLogsTable(this.recentLogs);
      this.renderHistoryTable();
      this.populateAudioDropdown();
      this.updateRolloverBanners();

      // Phase 1 updates
      this.loadCampaignsOverview();
      this.updateDesignerUI();

      // Phase 2 updates
      this.renderAgentScreen();

      // Phase 3 & 4 updates
      this.loadCallbacksList();
      this.loadDncList();
    } catch (err) {
      console.error(err);
      this.showToast('Could not load campaign data', 'error');
    }
  }

  renderDialerCampaignSelector() {
    const sel = document.getElementById('dialerCampaignDropdown');
    const badge = document.getElementById('dialerSelectedCampaignsBadge');
    const queueBadge = document.getElementById('dialerQueueCountBadge');
    const cbList = document.getElementById('multiCampaignCheckboxesList');

    const campaigns = this.allCampaigns || this.selectedCampaigns || [];
    const selectedIds = (this.selectedCampaignIds || [1]).map(Number);
    const isMulti = selectedIds.length > 1;

    // Update Dropdown
    if (sel && campaigns.length > 0) {
      let optionsHtml = '';
      if (isMulti) {
        optionsHtml += `<option value="multi" selected>📋 Multiple Campaigns (${selectedIds.length} Selected)</option>`;
      }
      campaigns.forEach(c => {
        const isSelected = !isMulti && selectedIds.includes(Number(c.id));
        const count = c.total_leads_count || c.total_leads || 0;
        optionsHtml += `<option value="${c.id}" ${isSelected ? 'selected' : ''}>${this.escapeHtml(c.name)} &middot; ${count} lead${count === 1 ? '' : 's'}</option>`;
      });
      optionsHtml += `<option value="all">⚡ Select All Campaigns Combined</option>`;
      sel.innerHTML = optionsHtml;
    }

    // Update Checkboxes Drawer
    if (cbList && campaigns.length > 0) {
      cbList.innerHTML = campaigns.map(c => {
        const checked = selectedIds.includes(Number(c.id));
        const count = c.total_leads_count || c.total_leads || 0;
        return `
          <label style="display:flex;align-items:center;gap:10px;padding:9px 12px;background:${checked ? '#eefbf2' : '#ffffff'};border:1.5px solid ${checked ? '#00A651' : '#e2e8f0'};border-radius:8px;cursor:pointer;transition:all 0.15s ease;">
            <input type="checkbox" value="${c.id}" ${checked ? 'checked' : ''} onchange="app.handleCampaignCheckboxToggle(${c.id}, this.checked)" style="width:17px;height:17px;accent-color:#00A651;cursor:pointer;">
            <div style="flex:1;min-width:0;">
              <div style="font-weight:700;font-size:12.5px;color:#143522;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${this.escapeHtml(c.name)}</div>
              <div style="font-size:11px;color:#526e5f;">${count} contacts &bull; Caller ID: ${this.escapeHtml(c.caller_id_number || '+251911002233')}</div>
            </div>
          </label>
        `;
      }).join('');
    }

    // Update Badges & Status Pill
    const pendingCount = (this.leads || []).filter(l => l.status === 'pending').length;
    const agentQueueStatus = document.getElementById('agentQueueStatusText');
    if (agentQueueStatus) {
      agentQueueStatus.innerHTML = `Ready &middot; ${pendingCount} lead${pendingCount === 1 ? '' : 's'} in queue`;
    }

    if (badge) {
      if (isMulti) {
        badge.textContent = `${selectedIds.length} Campaigns Active`;
        badge.className = 'sc-badge running';
      } else {
        const singleCamp = campaigns.find(c => Number(c.id) === selectedIds[0]);
        badge.textContent = singleCamp ? singleCamp.name : (this.campaign?.name || 'Campaign Active');
        badge.className = 'sc-badge running';
      }
    }

    if (queueBadge) {
      queueBadge.textContent = `${pendingCount} Leads in Queue`;
      queueBadge.style.background = pendingCount > 0 ? '#00A651' : '#64748b';
    }
  }

  handleDialerCampaignDropdownChange(val) {
    if (val === 'multi') {
      this.toggleMultiCampaignPanel(true);
      return;
    }
    if (val === 'all') {
      const allIds = (this.allCampaigns || []).map(c => Number(c.id));
      if (allIds.length > 0) {
        this.selectedCampaignIds = allIds;
        this.loadCampaignData(allIds);
        this.showToast(`Selected all ${allIds.length} campaigns for outbound dialing!`, 'success');
      }
      return;
    }

    const campId = Number(val);
    if (!campId) return;
    this.selectedCampaignIds = [campId];
    this.activeCampaignId = campId;
    this.loadCampaignData([campId]);
    const camp = (this.allCampaigns || []).find(c => Number(c.id) === campId);
    this.showToast(`Active dialing campaign set to: ${camp?.name || 'Campaign #' + campId}`, 'success');
  }

  toggleMultiCampaignPanel(forceOpen = null) {
    const wrap = document.getElementById('multiCampaignSelectorWrap');
    if (!wrap) return;
    const isCurrentlyOpen = wrap.style.display !== 'none';
    const shouldOpen = (forceOpen !== null) ? forceOpen : !isCurrentlyOpen;
    wrap.style.display = shouldOpen ? 'block' : 'none';

    const btn = document.getElementById('btnToggleMultiCampaign');
    if (btn) {
      btn.className = shouldOpen ? 'sc-pill-btn active' : 'sc-pill-btn secondary';
    }
  }

  handleCampaignCheckboxToggle(campaignId, isChecked) {
    const id = Number(campaignId);
    if (isChecked) {
      if (!this.selectedCampaignIds.includes(id)) {
        this.selectedCampaignIds.push(id);
      }
    } else {
      if (this.selectedCampaignIds.length > 1) {
        this.selectedCampaignIds = this.selectedCampaignIds.filter(x => x !== id);
      } else {
        this.showToast('At least one campaign must remain selected.', 'error');
        this.renderDialerCampaignSelector();
        return;
      }
    }
    this.renderDialerCampaignSelector();
  }

  selectAllCampaignsForDialer(select) {
    if (select) {
      this.selectedCampaignIds = (this.allCampaigns || []).map(c => Number(c.id));
    } else {
      this.selectedCampaignIds = [Number(this.allCampaigns?.[0]?.id || 1)];
    }
    this.renderDialerCampaignSelector();
  }

  applyMultiCampaignSelection() {
    if (!this.selectedCampaignIds || this.selectedCampaignIds.length === 0) {
      this.selectedCampaignIds = [1];
    }
    this.loadCampaignData(this.selectedCampaignIds);
    this.showToast(`Loaded ${this.selectedCampaignIds.length} campaigns into outbound dialing queue!`, 'success');
  }

  async loadCampaignsOverview() {
    try {
      const res = await fetch('api.php?action=get_all_campaigns');
      const data = await res.json();
      if (!data.success) return;
      this.allCampaigns = data.campaigns || [];
      const tbody = document.getElementById('campaignsOverviewTableBody');
      if (!tbody) return;

      if (this.allCampaigns.length === 0) {
        tbody.innerHTML = `<tr class="camp-table-row"><td colspan="5" style="padding:24px;text-align:center;color:#64748b;">No campaigns found. Create one using the form on the right.</td></tr>`;
        return;
      }

      tbody.innerHTML = this.allCampaigns.map(c => {
        const total = parseInt(c.total_leads > 0 ? c.total_leads : (c.total_leads_count || 0), 10);
        const comp = parseInt(c.completed_leads > 0 ? c.completed_leads : (c.completed_leads_count || 0), 10);
        const pct = total > 0 ? Math.min(100, Math.round((comp / total) * 100)) : 0;
        
        const isRunning = (c.status === 'running' || c.status === 'active');
        const statusClass = isRunning ? 'running' : 'idle';
        const statusLabel = isRunning ? 'Running' : (c.status === 'completed' ? 'Completed' : 'Idle');
        const dotClass = isRunning ? 'green' : 'blue';

        let progressText = `${pct}%`;
        if (total > 0 && comp < total) {
          progressText = `${pct}% · ${comp} of ${total}`;
        } else if (total === 0) {
          progressText = `0% · 0 of 0`;
        }

        let typeDisplay = 'Predictive';
        if (c.type === 'agentless') typeDisplay = 'Agentless';
        else if (c.type === 'progressive') typeDisplay = 'Progressive';
        else if (c.type === 'preview') typeDisplay = 'Preview';
        else if (c.type) typeDisplay = c.type.charAt(0).toUpperCase() + c.type.slice(1);

        const callerId = c.caller_id_number || '+251911002233';

        return `
          <tr class="camp-table-row">
            <td style="padding:14px 12px;vertical-align:middle;">
              <div style="font-weight:700;color:#0f172a;font-size:13.5px;line-height:1.3;">${this.escapeHtml(c.name)}</div>
              <div style="font-size:11.5px;color:#64748b;margin-top:2px;">Caller ID ${this.escapeHtml(callerId)}</div>
            </td>
            <td style="padding:14px 12px;vertical-align:middle;color:#334155;font-size:13px;font-weight:500;">
              ${typeDisplay}
            </td>
            <td style="padding:14px 12px;vertical-align:middle;">
              <span class="camp-status-badge ${statusClass}">
                <span class="camp-dot ${dotClass}"></span>
                ${statusLabel}
              </span>
            </td>
            <td style="padding:14px 12px;vertical-align:middle;min-width:130px;">
              <div style="font-size:11px;color:#64748b;font-weight:600;margin-bottom:5px;">
                ${progressText}
              </div>
              <div class="camp-progress-track">
                <div class="camp-progress-fill" style="width:${pct}%;"></div>
              </div>
            </td>
            <td style="padding:14px 12px;vertical-align:middle;text-align:right;white-space:nowrap;">
              <button type="button" class="camp-btn-design" onclick="selectCampaignForDesigner(${c.id})" title="Configure Audio, TTS &amp; IVR Message">Design</button>
              <button type="button" class="camp-btn-more" onclick="app.openCampaignActionMenu(${c.id}, event)" title="More options">More</button>
            </td>
          </tr>
        `;
      }).join('');
      this.populateCampaignSelectDropdowns();
      this.renderDialerCampaignSelector();
    } catch (e) {
      console.warn('loadCampaignsOverview error:', e);
    }
  }

  setWizardStep(step) {
    for (let i = 1; i <= 4; i++) {
      const stepItem = document.getElementById(`wizardStep${i}`);
      if (stepItem) {
        const circle = stepItem.querySelector('.camp-step-circle');
        const title = stepItem.querySelector('.camp-step-title');
        if (i === step) {
          stepItem.classList.add('active');
          if (circle) circle.classList.add('active');
          if (title) title.classList.add('active');
        } else {
          stepItem.classList.remove('active');
          if (circle) circle.classList.remove('active');
          if (title) title.classList.remove('active');
        }
      }
    }
    if (step === 2) {
      switchCampaignSubView('designer');
    } else if (step === 1) {
      switchCampaignSubView('list');
    }
  }

  async handleNewCampaignWizardSubmit(e) {
    if (e) e.preventDefault();
    const name = document.getElementById('newCampaignNameInput')?.value.trim();
    const type = document.getElementById('newCampaignTypeSelect')?.value || 'bill_reminder';
    const mode = document.getElementById('newCampaignModeSelect')?.value || 'agentless';
    const callerId = document.getElementById('newCampaignCallerIdSelect')?.value || '+251911002233';

    if (!name) {
      this.showToast('Please enter a campaign name', 'warning');
      return;
    }

    const formData = new FormData();
    formData.append('name', name);
    formData.append('type', mode);
    formData.append('caller_id_name', 'Ethio Telecom');
    formData.append('caller_id_number', callerId);
    formData.append('ivr_language', 'amharic');

    try {
      const res = await fetch('api.php?action=create_campaign', { method: 'POST', body: formData });
      const data = await res.json();
      if (data && data.success) {
        this.showToast(`Campaign "${name}" created! Advancing to Message Design...`, 'success');
        await this.loadCampaignsOverview();
        const newCampId = data.campaign_id;
        if (newCampId) {
          this.selectCampaignForDesigner(newCampId);
        } else {
          switchCampaignSubView('designer');
        }
        this.setWizardStep(2);
      } else {
        this.showToast(data.error || 'Failed to create campaign', 'error');
      }
    } catch (err) {
      this.showToast('Network error creating campaign', 'error');
    }
  }

  openCampaignActionMenu(campaignId, event) {
    if (event) event.stopPropagation();
    const camp = (this.allCampaigns || []).find(c => Number(c.id) === Number(campaignId));
    if (!camp) return;

    const idInp = document.getElementById('campActionMenuCampaignId');
    const titleEl = document.getElementById('campActionMenuTitle');
    if (idInp) idInp.value = camp.id;
    if (titleEl) titleEl.textContent = `${camp.name} Options`;

    this.openModal('modalCampaignActionMenu');
  }

  actionMenuDialCampaign() {
    const id = document.getElementById('campActionMenuCampaignId')?.value;
    this.closeModal('modalCampaignActionMenu');
    if (id) {
      this.openCampaignInQueue(Number(id));
    }
  }

  actionMenuAddContacts() {
    const id = document.getElementById('campActionMenuCampaignId')?.value;
    this.closeModal('modalCampaignActionMenu');
    if (id) {
      this.openAddContactsModal(Number(id));
    }
  }

  actionMenuExportContacts() {
    const id = document.getElementById('campActionMenuCampaignId')?.value;
    this.closeModal('modalCampaignActionMenu');
    if (id) {
      this.exportCampaignContacts(Number(id));
    }
  }

  async actionMenuToggleStatus() {
    const id = document.getElementById('campActionMenuCampaignId')?.value;
    this.closeModal('modalCampaignActionMenu');
    if (!id) return;
    const camp = (this.allCampaigns || []).find(c => Number(c.id) === Number(id));
    const nextStatus = (camp && camp.status === 'running') ? 'idle' : 'running';

    try {
      const formData = new FormData();
      formData.append('campaign_id', id);
      formData.append('status', nextStatus);
      const res = await fetch('api.php?action=toggle_campaign_status', { method: 'POST', body: formData });
      const data = await res.json();
      this.showToast(`Campaign status updated to ${nextStatus}`, 'success');
      await this.loadCampaignsOverview();
    } catch (e) {
      this.showToast('Failed to update campaign status', 'error');
    }
  }

  async actionMenuDeleteCampaign() {
    const id = document.getElementById('campActionMenuCampaignId')?.value;
    this.closeModal('modalCampaignActionMenu');
    if (!id) return;
    if (!confirm('Are you sure you want to delete this campaign and all its leads?')) return;

    try {
      const formData = new FormData();
      formData.append('campaign_id', id);
      const res = await fetch('api.php?action=delete_campaign', { method: 'POST', body: formData });
      const data = await res.json();
      if (data && data.success) {
        this.showToast('Campaign deleted successfully', 'success');
        await this.loadCampaignsOverview();
      } else {
        this.showToast(data.error || 'Failed to delete campaign', 'error');
      }
    } catch (e) {
      this.showToast('Error deleting campaign', 'error');
    }
  }

  populateCampaignSelectDropdowns(selectedId = null) {
    const campaigns = this.allCampaigns || [];
    if (campaigns.length === 0) return;

    const targetIds = ['uploadCampaignSelect', 'manualCampaignSelect', 'singleAddCampaignSelect'];
    targetIds.forEach(id => {
      const sel = document.getElementById(id);
      if (!sel) return;
      const currentVal = selectedId || sel.value || this.activeCampaignId || this.campaign?.id;
      sel.innerHTML = campaigns.map(c => {
        const count = c.total_leads_count || c.total_leads || 0;
        return `<option value="${c.id}">${this.escapeHtml(c.name)} (${count} contacts)</option>`;
      }).join('');
      if (currentVal) sel.value = String(currentVal);
    });
  }

  async openAddContactsModal(campaignId = null) {
    if (!this.allCampaigns || this.allCampaigns.length === 0) {
      await this.loadCampaignsOverview();
    }
    const targetId = campaignId || this.activeCampaignId || this.campaign?.id || 1;
    this.populateCampaignSelectDropdowns(targetId);
    this.openModal('modalUpload');
  }

  async openManualAddModal(campaignId = null) {
    if (!this.allCampaigns || this.allCampaigns.length === 0) {
      await this.loadCampaignsOverview();
    }
    const targetId = campaignId || this.activeCampaignId || this.campaign?.id || 1;
    this.populateCampaignSelectDropdowns(targetId);
    this.openModal('modalManual');
  }

  async openSingleAddModal(campaignId = null) {
    if (!this.allCampaigns || this.allCampaigns.length === 0) {
      await this.loadCampaignsOverview();
    }
    const targetId = campaignId || this.activeCampaignId || this.campaign?.id || 1;
    this.populateCampaignSelectDropdowns(targetId);
    this.openModal('modalSingleAdd');
  }

  exportCampaignContacts(campaignId = null) {
    const targetId = campaignId || this.activeCampaignId || this.campaign?.id || 1;
    this.showToast('Generating contacts CSV export...', 'info');
    window.location.href = `api.php?action=export_contacts_csv&campaign_id=${encodeURIComponent(targetId)}`;
  }

  async selectCampaignForDesigner(campaignId) {
    this.activeCampaignId = Number(campaignId);

    // Switch view immediately to provide instant visual navigation
    if (typeof window.switchCampaignSubView === 'function') {
      window.switchCampaignSubView('designer');
    } else {
      const listEl = document.getElementById('subViewCampaignsList');
      const designerEl = document.getElementById('subViewCampaignsDesigner');
      if (listEl) listEl.style.display = 'none';
      if (designerEl) designerEl.style.display = 'block';
    }

    // Immediately reflect campaign name in breadcrumb tab from cached list
    const camps = this.allCampaigns || [];
    const c = camps.find(item => Number(item.id) === Number(campaignId));
    if (c) {
      this.campaign = c;
      const tabName = document.getElementById('designerTabCampaignName');
      if (tabName) tabName.textContent = c.name || 'Campaign';
      this.updateDesignerUI();
    }

    try {
      await this.loadCampaignData(campaignId);
      this.updateDesignerUI();
    } catch (err) {
      console.warn('loadCampaignData in selectCampaignForDesigner error:', err);
    }
  }

  loadData(campaignInput = null) {
    return this.loadCampaignData(campaignInput);
  }

  openCampaignInQueue(campaignId) {
    this.selectedCampaignIds = [Number(campaignId)];
    this.activeCampaignId = Number(campaignId);
    this.loadCampaignData([campaignId]).then(() => {
      if (typeof window.switchNav === 'function') {
        window.switchNav('tabDialer', 'dialer/agent');
      }
    });
  }

  updateDesignerUI() {
    if (!this.campaign) return;
    const nameEl = document.getElementById('designerCampaignNameText');
    if (nameEl) nameEl.textContent = this.campaign.name || 'Outbound Campaign';

    const tabNameEl = document.getElementById('designerTabCampaignName');
    if (tabNameEl) tabNameEl.textContent = this.campaign.name || 'Bill reminder: October';

    const subPill = document.getElementById('subBtnCampaignsDesigner');
    if (subPill) subPill.textContent = `${this.campaign.name || 'Campaign'} Designer`;

    const typeBadge = document.getElementById('designerCampaignTypeBadge');
    if (typeBadge) {
      typeBadge.textContent = (this.campaign.type || 'predictive').toUpperCase();
    }

    const txtArea = document.getElementById('campaignMessageText');
    if (txtArea && this.campaign.ivr_message_text) {
      txtArea.value = this.campaign.ivr_message_text;
    }

    const lang = (this.campaign.ivr_language || 'amharic').toLowerCase();
    document.querySelectorAll('#langBtnAmharic, #langBtnOromifa, #langBtnEnglish').forEach(b => {
      b.className = 'des-lang-btn';
    });
    if (lang === 'amharic') document.getElementById('langBtnAmharic')?.classList.add('active');
    else if (lang === 'oromifa') document.getElementById('langBtnOromifa')?.classList.add('active');
    else document.getElementById('langBtnEnglish')?.classList.add('active');

    // Audio prompt dropdown
    this.populateDesignerAudioDropdown();

    // Live Results calculation
    const total = (this.campaign.total_leads > 0) ? this.campaign.total_leads : this.leads.length;
    let pending = 0, answered = 0, busy = 0, noAnswer = 0;
    this.leads.forEach(l => {
      const s = l.status;
      if (s === 'pending') pending++;
      else if (['answered', 'completed', 'ivr_completed', 'ivr_playing'].includes(s)) answered++;
      else if (s === 'busy') busy++;
      else if (s === 'no_answer') noAnswer++;
    });

    const dialed = total > 0 ? (total - pending) : 0;
    const pct = total > 0 ? Math.min(100, Math.round((dialed / total) * 100)) : 0;

    const elSched = document.getElementById('chipScheduled');
    const elDialed = document.getElementById('chipDialed');
    const elAns = document.getElementById('chipAnswered');
    const elBusy = document.getElementById('chipBusy');
    const elNoAns = document.getElementById('chipNoAnswer');

    if (elSched) elSched.textContent = (total > 0 ? total : 1);
    if (elDialed) elDialed.textContent = dialed;
    if (elAns) elAns.textContent = answered;
    if (elBusy) elBusy.textContent = busy;
    if (elNoAns) elNoAns.textContent = noAnswer;

    const pctEl = document.getElementById('designerProgressPercent');
    const ratioEl = document.getElementById('designerProgressRatio');
    const barEl = document.getElementById('designerProgressBarFill');

    if (pctEl) pctEl.textContent = `Progress ${pct}%`;
    if (ratioEl) ratioEl.textContent = `${dialed} of ${total > 0 ? total : 1} dialed`;
    if (barEl) barEl.style.width = `${pct}%`;

    const statusBadge = document.getElementById('designerCampaignStatusBadge');
    const btnLaunch = document.getElementById('btnLaunchDesignerCampaign');

    const isRunning = (this.campaign.status === 'running');
    if (statusBadge) {
      statusBadge.innerHTML = `<span class="dot"></span>${isRunning ? 'Running' : (this.campaign.status === 'completed' ? 'Done' : 'Paused')}`;
      statusBadge.className = `des-status-pill ${isRunning ? 'running' : 'idle'}`;
    }
    if (btnLaunch) {
      btnLaunch.textContent = isRunning ? 'Pause campaign' : 'Launch campaign';
      btnLaunch.className = 'des-btn-outline';
    }
  }

  populateDesignerAudioDropdown() {
    const sel = document.getElementById('designerAudioPromptSelect');
    if (!sel || !this.audioRecordings) return;
    sel.innerHTML = this.audioRecordings.map(a => {
      const isSel = (this.campaign?.ivr_audio_file === a.filepath || this.campaign?.ivr_audio_name === a.name);
      const langLabel = (a.language || 'Amharic').charAt(0).toUpperCase() + (a.language || 'Amharic').slice(1);
      return `<option value="${this.escapeHtml(a.filepath)}" data-name="${this.escapeHtml(a.name)}" ${isSel ? 'selected' : ''}>${this.escapeHtml(a.name)} &middot; ${langLabel}</option>`;
    }).join('');
  }

  handleDesignerAudioChange(audioPath) {
    const sel = document.getElementById('designerAudioPromptSelect');
    const opt = sel?.options[sel.selectedIndex];
    const name = opt?.dataset.name || '';
    if (this.campaign) {
      this.campaign.ivr_audio_file = audioPath;
      this.campaign.ivr_audio_name = name;
    }
    const player = document.getElementById('mainAudioPlayer');
    if (player) player.src = audioPath;
  }

  async saveCampaignDesigner() {
    if (!this.campaign) return;
    const txtArea = document.getElementById('campaignMessageText');
    const selAudio = document.getElementById('designerAudioPromptSelect');
    const activeLangBtn = document.querySelector('#langBtnAmharic.active, #langBtnOromifa.active, #langBtnEnglish.active');
    const lang = activeLangBtn ? (activeLangBtn.id.replace('langBtn', '').toLowerCase()) : 'amharic';

    const formData = new FormData();
    formData.append('campaign_id', this.campaign.id);
    formData.append('message_text', txtArea ? txtArea.value : '');
    formData.append('language', lang);
    if (selAudio) {
      formData.append('audio_file', selAudio.value);
      const opt = selAudio.options[selAudio.selectedIndex];
      if (opt) formData.append('audio_name', opt.dataset.name || '');
    }

    try {
      const res = await fetch('api.php?action=save_campaign_designer', {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      if (data.success) {
        this.showToast('Campaign IVR template and voice settings saved to PostgreSQL!', 'success');
        this.loadCampaignsOverview();
      } else {
        this.showToast(data.error || 'Failed to save settings', 'error');
      }
    } catch (e) {
      this.showToast('Network error saving campaign settings', 'error');
    }
  }

  async handleCreateCampaign(e) {
    e.preventDefault();
    const name = document.getElementById('newCampaignNameInput')?.value.trim();
    const type = document.getElementById('newCampaignTypeSelect')?.value;
    const mode = document.getElementById('newCampaignModeSelect')?.value;
    const callerId = document.getElementById('newCampaignCallerIdSelect')?.value;

    if (!name) {
      this.showToast('Please enter a campaign name', 'warning');
      return;
    }

    const formData = new FormData();
    formData.append('name', name);
    formData.append('type', mode || type || 'agentless');
    formData.append('caller_id_number', callerId || '+251911002233');
    formData.append('language', 'amharic');

    try {
      const res = await fetch('api.php?action=create_campaign', {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      if (data.success) {
        this.showToast(`Campaign "${name}" created in PostgreSQL!`, 'success');
        await this.loadCampaignsOverview();
        await this.selectCampaignForDesigner(data.campaign_id);
      } else {
        this.showToast(data.error || 'Failed to create campaign', 'error');
      }
    } catch (err) {
      this.showToast('Error creating campaign', 'error');
    }
  }

  async toggleCampaignLaunch() {
    if (!this.campaign) return;
    const newStatus = (this.campaign.status === 'running') ? 'paused' : 'running';

    const formData = new FormData();
    formData.append('campaign_id', this.campaign.id);
    formData.append('status', newStatus);

    try {
      const res = await fetch('api.php?action=set_campaign_status', {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      if (data.success) {
        this.campaign.status = newStatus;
        this.isRunning = (newStatus === 'running');
        this.isPaused = (newStatus === 'paused');
        this.updateDesignerUI();
        this.loadCampaignsOverview();
      }
    } catch (e) {
      this.showToast('Error updating campaign status', 'error');
    }
  }

  renderAgentScreen() {
    const pendingLeads = (this.leads || []).filter(l => l.status === 'pending');
    this.activeLead = pendingLeads.length > 0 ? pendingLeads[0] : (this.leads ? this.leads[0] : null);

    const nameEl = document.getElementById('agentScreenCustName');
    const phoneEl = document.getElementById('agentScreenPhoneText');
    const debtEl = document.getElementById('agentScreenDebtText');
    const debtAmtEl = document.getElementById('agentScreenDebtAmount');
    const debtDueEl = document.getElementById('agentScreenDebtDue');
    const acctEl = document.getElementById('agentScreenAccountText');
    const timeEl = document.getElementById('agentScreenCallTimeText');
    const campEl = document.getElementById('agentScreenCampaignText');
    const langTag = document.getElementById('agentScreenLangTag');
    const tierTag = document.getElementById('agentScreenTierTag');
    const scriptBox = document.getElementById('agentDynamicScriptBox');
    const qCountTag = document.getElementById('agentQueueStatusTag');
    const queueStatusText = document.getElementById('agentQueueStatusText');

    if (queueStatusText) {
      queueStatusText.innerHTML = `Ready &middot; ${pendingLeads.length} lead${pendingLeads.length === 1 ? '' : 's'} in queue`;
    }

    if (qCountTag) {
      qCountTag.textContent = `${pendingLeads.length} Leads in Queue`;
      qCountTag.style.background = pendingLeads.length > 0 ? '#00A651' : '#64748b';
    }

    if (!this.activeLead) {
      if (nameEl) nameEl.textContent = 'Queue Empty';
      if (phoneEl) phoneEl.textContent = '-';
      if (debtEl) debtEl.textContent = 'All calls completed';
      if (debtAmtEl) debtAmtEl.textContent = 'ETB 0.00';
      if (debtDueEl) debtDueEl.textContent = 'No pending calls';
      if (acctEl) acctEl.textContent = '-';
      if (timeEl) timeEl.textContent = '-';
      if (scriptBox) scriptBox.textContent = 'All scheduled leads for this campaign have been dialed. Upload new contacts or reset leads to continue.';
      return;
    }

    const lead = this.activeLead;
    if (nameEl) nameEl.textContent = lead.customer_name || 'Helen Tesfaye';
    if (phoneEl) phoneEl.textContent = lead.phone_number || '+251911456789';

    const debtAmt = parseFloat(lead.debt_amount || 450);
    const curr = lead.currency || 'ETB';
    const debtStr = `${curr} ${debtAmt.toFixed(2)}`;
    const dueTimeStr = lead.call_time || '2026-10-20 11:15';

    if (debtEl) debtEl.textContent = `${debtStr}, due ${dueTimeStr}`;
    if (debtAmtEl) debtAmtEl.textContent = debtStr;
    if (debtDueEl) debtDueEl.textContent = `Due ${dueTimeStr}`;

    if (acctEl) acctEl.textContent = lead.account_number || `ACC-${lead.id}98231`.slice(0, 9);
    if (timeEl) timeEl.textContent = dueTimeStr;
    if (campEl) campEl.textContent = lead.campaign_name || this.campaign?.name || 'Bill reminder: October';

    const leadLang = (lead.language || this.campaign?.ivr_language || 'amharic').toLowerCase();
    if (langTag) {
      langTag.textContent = leadLang.charAt(0).toUpperCase() + leadLang.slice(1);
    }
    if (tierTag) {
      tierTag.textContent = debtAmt > 1000 ? 'VIP / Corporate' : (debtAmt > 500 ? 'Gold' : 'Standard');
    }

    // Dynamic Script customized with real customer name & debt amount (Exact Amharic styling)
    if (scriptBox) {
      const cName = lead.customer_name || 'Helen Tesfaye';
      if (leadLang === 'amharic') {
        scriptBox.innerHTML = `&ldquo;ሰላም ${this.escapeHtml(cName)}: ከኢትዮ ቴሌኮም የደንበኞች አገልግሎት ነው የምደውለው:: የ ${this.escapeHtml(debtStr)} የክፍያ ሂሳብዎን ለማስታወስ ነበር:: በቴሌብር ለመክፈል አሁን የክፍያ መልእክት ልላክሎት?&rdquo;`;
      } else if (leadLang === 'oromifa') {
        scriptBox.innerHTML = `&ldquo;Akkam jirtu ${this.escapeHtml(cName)}: tajaajila maamiltootaa Itiyoo Telekoom irraani bilbila:: Kaffaltiin herrega keessanii ${this.escapeHtml(debtStr)} dhumachuu isaa isin yaadachiisuufi:: Telebirriin akka kaffaltaniif ergaa isiniif erguu?&rdquo;`;
      } else {
        scriptBox.innerHTML = `&ldquo;Hello ${this.escapeHtml(cName)}: this is Ethio Telecom customer service. I am calling regarding your outstanding balance of ${this.escapeHtml(debtStr)}. Would you like me to send an instant Telebirr USSD payment prompt to your phone?&rdquo;`;
      }
    }
  }

  selectAgentOutcome(labelEl, dispo) {
    document.querySelectorAll('#dialOutcomeList .dial-outcome-opt').forEach(opt => {
      opt.classList.remove('active');
      const r = opt.querySelector('.dial-radio-circle');
      if (r) r.classList.remove('checked');
      const t = opt.querySelector('.dial-outcome-text');
      if (t) t.classList.remove('active');
    });
    if (labelEl) {
      labelEl.classList.add('active');
      const r = labelEl.querySelector('.dial-radio-circle');
      if (r) r.classList.add('checked');
      const t = labelEl.querySelector('.dial-outcome-text');
      if (t) t.classList.add('active');
    }
    this.selectedDispo = dispo;
    this.showToast(`Outcome selected: ${dispo.replace(/_/g, ' ').toUpperCase()}`, 'info');
  }

  toggleAgentCheck(item) {
    if (!item) return;
    const box = item.querySelector('.dial-check-box');
    if (!box) return;
    const isChecked = box.classList.contains('checked');
    if (isChecked) {
      box.classList.remove('checked');
      box.innerHTML = '';
    } else {
      box.classList.add('checked');
      box.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
    }
  }

  selectAgentDispo(btn, dispo) {
    this.selectAgentOutcome(btn, dispo);
  }

  handleAgentDialClick() {
    if (!this.activeLead || !this.activeLead.phone_number) {
      this.showToast('No customer selected to dial', 'warning');
      return;
    }
    this.showToast(`Originating call to ${this.activeLead.customer_name} (${this.activeLead.phone_number})...`, 'info');
    this.clickToDial(this.activeLead.phone_number, this.activeLead.customer_name);
  }

  async handleAgentSkipClick() {
    if (!this.activeLead) {
      this.showToast('No more leads to skip', 'warning');
      return;
    }
    this.showToast(`Skipped ${this.activeLead.customer_name}. Fetching next lead...`, 'warning');
    const currentIdx = this.leads.findIndex(l => l.id === this.activeLead.id);
    const remainingPending = this.leads.slice(currentIdx + 1).filter(l => l.status === 'pending');
    if (remainingPending.length > 0) {
      this.activeLead = remainingPending[0];
    } else {
      const anyPending = this.leads.filter(l => l.status === 'pending' && l.id !== this.activeLead.id);
      this.activeLead = anyPending.length > 0 ? anyPending[0] : null;
    }
    this.renderAgentScreen();
  }

  async handleAgentSaveNextClick() {
    if (!this.activeLead) {
      this.showToast('No active contact to save', 'warning');
      return;
    }

    const dispo = this.selectedDispo || 'promise_to_pay';
    const notes = document.getElementById('agentCallNotesInput')?.value || '';
    const promiseDate = document.getElementById('agentPromiseDateSelect')?.value || '';
    const duration = parseInt(this.lastCallDuration || 35, 10);

    const formData = new FormData();
    formData.append('lead_id', this.activeLead.id);
    formData.append('campaign_id', this.campaign?.id || 1);
    formData.append('disposition', dispo);
    formData.append('promise_date', promiseDate);
    formData.append('notes', notes);
    formData.append('duration_sec', duration);

    try {
      const res = await fetch('api.php?action=save_agent_outcome', {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      if (data.success) {
        this.showToast(`Outcome recorded to PostgreSQL! Connecting next call...`, 'success');

        const notesInput = document.getElementById('agentCallNotesInput');
        if (notesInput) notesInput.value = '';

        const tag = document.getElementById('agentNextCallCountdownTag');
        if (tag) {
          let count = 5;
          tag.textContent = `Next call in ${count}s`;
          const timer = setInterval(() => {
            count--;
            if (count > 0) {
              tag.textContent = `Next call in ${count}s`;
            } else {
              clearInterval(timer);
              tag.textContent = 'Ready';
            }
          }, 1000);
        }

        if (data.next_lead) {
          this.activeLead = data.next_lead;
        } else {
          const remaining = this.leads.filter(l => l.status === 'pending' && l.id !== this.activeLead.id);
          this.activeLead = remaining.length > 0 ? remaining[0] : null;
        }

        await this.loadCampaignData();
      } else {
        this.showToast(data.error || 'Failed to save outcome', 'error');
      }
    } catch (e) {
      this.showToast('Network error saving outcome', 'error');
    }
  }

  async setPacingMode(mode) {
    const btnPrev = document.getElementById('btnPacingPreview');
    const btnProg = document.getElementById('btnPacingProgressive');
    const btnPred = document.getElementById('btnPacingPredictive');

    if (btnPrev) btnPrev.className = (mode === 'preview') ? 'dial-seg-btn active-green' : 'dial-seg-btn';
    if (btnProg) btnProg.className = (mode === 'progressive') ? 'dial-seg-btn active-green' : 'dial-seg-btn';
    if (btnPred) btnPred.className = (mode === 'predictive') ? 'dial-seg-btn active-green' : 'dial-seg-btn';

    const pacingSelect = document.getElementById('pacingModeSelect');
    if (pacingSelect) pacingSelect.value = mode;

    const formData = new FormData();
    formData.append('campaign_id', this.campaign?.id || 1);
    formData.append('pacing_mode', mode);

    try {
      const res = await fetch('api.php?action=set_pacing_mode', {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      if (data.success) {
        if (this.campaign) this.campaign.type = mode;
        const modeLabels = {
          'preview': 'Preview (Agent manual click before dialing)',
          'progressive': 'Progressive (1-to-1 immediate lead connection)',
          'predictive': 'Predictive (High-throughput algorithm, target 1,000 calls/min)'
        };
        this.showToast(`Pacing mode: ${modeLabels[mode] || mode}`, 'success');
      }
    } catch (e) {
      this.showToast('Failed to update pacing mode', 'error');
    }
  }

  async startMockVolumeTest() {
    const modeSel = document.getElementById('mockVolumeModeSelect');
    const mode = modeSel ? modeSel.value : 'paced_1000';
    const btn = document.getElementById('btnRunMockVolumeTest');
    const btnStop = document.getElementById('btnStopMockVolumeTest');
    const resBox = document.getElementById('mockVolumeResultsBox');

    let totalTarget = 1000;
    let isPaced = true;
    if (mode === 'paced_500') totalTarget = 500;
    else if (mode === 'paced_170') totalTarget = 170;
    else if (mode === 'fast_1000') { totalTarget = 1000; isPaced = false; }

    this.isMockTestRunning = true;
    if (btn) btn.style.display = 'none';
    if (btnStop) btnStop.style.display = 'inline-block';

    if (resBox) {
      resBox.style.display = 'block';
      resBox.innerHTML = `
        <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:18px;margin-top:14px;box-shadow:0 2px 6px rgba(0,0,0,0.03);">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
            <div style="font-weight:700;color:#166534;font-size:14.5px;display:flex;align-items:center;gap:8px;">
              <span class="spinner-border spinner-border-sm" style="display:inline-block;width:14px;height:14px;border:2.5px solid #00A651;border-right-color:transparent;border-radius:50%;animation:spin 0.6s linear infinite;"></span>
              <span id="mockTestHeaderTitle">Live Mock Carrier Trunk Test &bull; Pacing 17 Calls/Sec</span>
            </div>
            <div style="font-family:monospace;font-size:13px;font-weight:700;color:#15803d;background:#dcfce7;padding:4px 12px;border-radius:12px;" id="mockTestTimer">00:00</div>
          </div>

          <!-- Live Animated Progress Bar -->
          <div style="background:#e2e8f0;border-radius:9999px;height:10px;overflow:hidden;margin-bottom:14px;">
            <div id="mockTestProgressBar" style="width:0%;height:100%;background:linear-gradient(90deg, #00A651, #16a34a);transition:width 0.3s ease;"></div>
          </div>

          <!-- 4 Metrics Chips -->
          <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:10px;font-size:12px;margin-bottom:12px;">
            <div style="background:#ffffff;padding:9px 12px;border-radius:8px;border:1px solid #bbf7d0;">
              <div style="color:#64748b;font-size:11px;">Calls Dispatched</div>
              <div style="font-size:16px;font-weight:800;color:#0f172a;" id="mockTestCount">0 / ${totalTarget}</div>
            </div>
            <div style="background:#ffffff;padding:9px 12px;border-radius:8px;border:1px solid #bbf7d0;">
              <div style="color:#64748b;font-size:11px;">Live Throughput</div>
              <div style="font-size:16px;font-weight:800;color:#00A651;" id="mockTestCpm">0 CPM</div>
            </div>
            <div style="background:#ffffff;padding:9px 12px;border-radius:8px;border:1px solid #bbf7d0;">
              <div style="color:#64748b;font-size:11px;">Active Trunk Channels</div>
              <div style="font-size:16px;font-weight:800;color:#0284c7;" id="mockTestChannels">0</div>
            </div>
            <div style="background:#ffffff;padding:9px 12px;border-radius:8px;border:1px solid #bbf7d0;">
              <div style="color:#64748b;font-size:11px;">Answered / IVR</div>
              <div style="font-size:16px;font-weight:800;color:#15803d;" id="mockTestAnswered">0</div>
            </div>
            <div style="background:#ffffff;padding:9px 12px;border-radius:8px;border:1px solid #bbf7d0;">
              <div style="color:#64748b;font-size:11px;">Busy / No Answer</div>
              <div style="font-size:16px;font-weight:800;color:#b45309;" id="mockTestBusy">0</div>
            </div>
          </div>

          <div style="font-size:11.5px;color:#166534;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <span>🔒 100% Mock Telephony: Zero real phone calls placed. Target numbers: +251900000001 - +251900001000.</span>
            <button type="button" class="btn btn-xs btn-outline-success" onclick="switchNav('tabReports', 'reports')" style="font-size:11.5px;padding:3px 10px;cursor:pointer;">View Live CDRs in Reports &rarr;</button>
          </div>
        </div>
      `;
    }

    if (!isPaced) {
      // Run fast mode
      try {
        const res = await fetch(`api.php?action=run_mock_volume_test&calls=${totalTarget}&fast=1`);
        const data = await res.json();
        this.finishMockTestUI(data, totalTarget, data.execution_time_sec || 0.5);
      } catch (e) {
        this.showToast('Test failed: ' + e.message, 'error');
        this.stopMockVolumeTest();
      }
      return;
    }

    // Live real-time pacing mode (17 calls every second)
    const startTime = Date.now();
    let dispatched = 0;
    let answered = 0;
    let busyNoAns = 0;
    let secElapsed = 0;

    const timerInterval = setInterval(() => {
      if (!this.isMockTestRunning) {
        clearInterval(timerInterval);
        return;
      }
      secElapsed++;
      const mm = String(Math.floor(secElapsed / 60)).padStart(2, '0');
      const ss = String(secElapsed % 60).padStart(2, '0');
      const timerEl = document.getElementById('mockTestTimer');
      if (timerEl) timerEl.textContent = `${mm}:${ss}`;
    }, 1000);

    const runBatch = async () => {
      if (!this.isMockTestRunning) return;

      const remaining = totalTarget - dispatched;
      const batchSize = Math.min(17, remaining);
      const isFirst = (dispatched === 0);

      try {
        const res = await fetch(`api.php?action=dispatch_mock_batch&size=${batchSize}&total_target=${totalTarget}${isFirst ? '&reset_first=1' : ''}`);
        const data = await res.json();
        if (data.success) {
          dispatched += batchSize;
          answered += (data.batch_outcomes?.answered || 0);
          busyNoAns += ((data.batch_outcomes?.busy || 0) + (data.batch_outcomes?.no_answer || 0));

          const elapsedSec = Math.max(0.1, (Date.now() - startTime) / 1000);
          const currentCaps = (dispatched / elapsedSec).toFixed(1);
          const currentCpm = Math.round(currentCaps * 60);
          const pct = Math.min(100, Math.round((dispatched / totalTarget) * 100));

          const barEl = document.getElementById('mockTestProgressBar');
          if (barEl) barEl.style.width = pct + '%';

          const countEl = document.getElementById('mockTestCount');
          if (countEl) countEl.textContent = `${dispatched} / ${totalTarget} (${pct}%)`;

          const cpmEl = document.getElementById('mockTestCpm');
          if (cpmEl) cpmEl.textContent = `${currentCpm.toLocaleString()} CPM (${currentCaps}/s)`;

          const chanEl = document.getElementById('mockTestChannels');
          if (chanEl) chanEl.textContent = Math.min(500, Math.round(dispatched * 0.45));

          const ansEl = document.getElementById('mockTestAnswered');
          if (ansEl) ansEl.textContent = answered;

          const busyEl = document.getElementById('mockTestBusy');
          if (busyEl) busyEl.textContent = busyNoAns;

          if (dispatched >= totalTarget) {
            this.finishMockTestUI(null, totalTarget, elapsedSec, answered, busyNoAns);
            return;
          }

          // Schedule next 1-second batch
          this.mockTestTimeout = setTimeout(runBatch, 1000);
        } else {
          this.showToast(data.error || 'Batch failed', 'error');
          this.stopMockVolumeTest();
        }
      } catch (e) {
        this.showToast('Network error during mock dispatch: ' + e.message, 'error');
        this.stopMockVolumeTest();
      }
    };

    runBatch();
  }

  stopMockVolumeTest() {
    this.isMockTestRunning = false;
    if (this.mockTestTimeout) clearTimeout(this.mockTestTimeout);
    const btn = document.getElementById('btnRunMockVolumeTest');
    const btnStop = document.getElementById('btnStopMockVolumeTest');
    if (btn) btn.style.display = 'inline-block';
    if (btnStop) btnStop.style.display = 'none';
    const titleEl = document.getElementById('mockTestHeaderTitle');
    if (titleEl) titleEl.innerHTML = '⏹ Mock Test Stopped';
  }

  finishMockTestUI(data, totalTarget, elapsedSec, ansCount, busyCount) {
    this.stopMockVolumeTest();
    this.showToast(`1,000 CPM Mock Test Completed! Dispatched ${totalTarget} mock calls.`, 'success');
    const titleEl = document.getElementById('mockTestHeaderTitle');
    if (titleEl) titleEl.innerHTML = `✔ Mock Volume Test Completed Successfully (${totalTarget} Calls in ${elapsedSec ? elapsedSec.toFixed(1) : ''}s)`;
    const barEl = document.getElementById('mockTestProgressBar');
    if (barEl) barEl.style.width = '100%';
    const countEl = document.getElementById('mockTestCount');
    if (countEl) countEl.textContent = `${totalTarget} / ${totalTarget} (100%)`;
  }

  getTodayLeads() {
    const today = this.serverToday || new Date().toISOString().split('T')[0];
    return this.leads.filter(l => l.is_today || l.effective_date === today || (!l.effective_date && !l.is_past));
  }

  getPastLeads() {
    const today = this.serverToday || new Date().toISOString().split('T')[0];
    return this.leads.filter(l => l.is_past || (l.effective_date && l.effective_date < today));
  }

  renderCampaignHeader() {
    if (!this.campaign) return;
    if (this.campaignTitleText) this.campaignTitleText.textContent = this.campaign.name || 'Outbound Customer Calls';
    
    if (this.campaignStatusTag) {
      this.campaignStatusTag.textContent = this.isRunning ? (this.isPaused ? 'PAUSED' : 'ACTIVE') : 'IDLE';
      this.campaignStatusTag.className = `campaign-status-tag ${this.isRunning ? (this.isPaused ? 'paused' : 'running') : 'idle'}`;
    }

    if (this.activeAudioTitle) {
      this.activeAudioTitle.textContent = this.campaign.ivr_audio_name || 'Human Welcome Recording (WAV)';
    }

    if (this.audioPlayerElement && this.campaign.ivr_audio_file) {
      this.audioPlayerElement.src = this.campaign.ivr_audio_file;
    }
    if (this.tabAudioPlayer && this.campaign.ivr_audio_file) {
      this.tabAudioPlayer.src = this.campaign.ivr_audio_file;
    }
  }

  renderStats() {
    const todayLeads = this.getTodayLeads();
    const todayTotal = todayLeads.length;
    let todayPending = 0;
    let todayConnected = 0;

    todayLeads.forEach(l => {
      if (l.status === 'pending') todayPending++;
      else if (['answered', 'ivr_playing', 'ivr_completed', 'completed'].includes(l.status)) todayConnected++;
    });

    if (this.kpiTotal) this.kpiTotal.textContent = todayTotal;
    if (this.kpiPending) this.kpiPending.textContent = todayPending;
    if (this.kpiConnected) this.kpiConnected.textContent = todayConnected;
    if (this.kpiAnswerRate) this.kpiAnswerRate.textContent = `${this.stats.answer_rate || 0}%`;
    if (this.kpiAvgDuration) this.kpiAvgDuration.textContent = `${this.stats.avg_duration || 0}s`;

    const badgePending = document.getElementById('badgePendingCount');
    if (badgePending) badgePending.textContent = todayPending;

    const badgeHistory = document.getElementById('badgeHistoryCount');
    if (badgeHistory) badgeHistory.textContent = this.getPastLeads().length;
  }

  updateLocalStats() {
    this.renderStats();
  }

  updateRolloverBanners() {
    const rolloverTag = document.getElementById('historyRolloverCount');
    if (rolloverTag) rolloverTag.textContent = this.remainingCount || 0;

    const btnRollover = document.getElementById('btnHistoryRollover');
    if (btnRollover) {
      btnRollover.style.display = (this.remainingCount > 0) ? 'inline-flex' : 'none';
    }
  }

  renderLeadsTables(search = null, filterStatus = null) {
    const todayLeads = this.getTodayLeads();

    const searchVal = (search !== null && search !== undefined) ? search : (document.getElementById('leadSearchInput')?.value || '');
    const filterVal = (filterStatus !== null && filterStatus !== undefined) ? filterStatus : (document.getElementById('leadStatusFilter')?.value || 'all');
    const query = searchVal.toLowerCase().trim();

    const filtered = todayLeads.filter(l => {
      const matchQuery = !query || 
        (l.customer_name && l.customer_name.toLowerCase().includes(query)) ||
        (l.phone_number && l.phone_number.includes(query)) ||
        (l.account_number && l.account_number.toLowerCase().includes(query));
      const matchStatus = filterVal === 'all' || l.status === filterVal;
      return matchQuery && matchStatus;
    });

    const renderTarget = (tbodyEl, isFull = false) => {
      if (!tbodyEl) return;
      tbodyEl.innerHTML = '';

      if (filtered.length === 0) {
        const colCount = isFull ? 10 : 8;
        const rolloverBtn = this.remainingCount > 0
          ? `<div style="margin-top:12px;"><button class="btn btn-primary btn-sm" onclick="app.bulkRolloverRemainingToToday()">Roll Over ${this.remainingCount} Unanswered Calls from Previous Days</button></div>`
          : '';
        tbodyEl.innerHTML = `<tr><td colspan="${colCount}" style="text-align:center;color:#64748b;padding:28px;">
          <div style="font-size:14px;font-weight:700;color:#1e293b;margin-bottom:4px;">No contacts found matching filter</div>
          <div style="font-size:12px;color:#64748b;">Upload Excel/CSV, quick-add contacts, or adjust search criteria.</div>
          ${rolloverBtn}
        </td></tr>`;
        return;
      }

      filtered.forEach((l, idx) => {
        const tr = document.createElement('tr');
        const stBadge = this.getStatusBadge(l.status);
        const callTime = l.call_time || 'Immediate';
        const dur = l.duration_sec ? `${l.duration_sec}s` : '-';
        const isImmediate = !l.call_time || l.call_time.toLowerCase() === 'immediate';
        const isDue = this.isLeadDueForCall(l);
        const timeBadgeStyle = (isImmediate || isDue)
          ? 'background:#dcfce7;color:#15803d;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:700;'
          : 'background:#f8fafc;color:#475569;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:600;border:1px solid #e2e8f0;';

        const acct = l.account_number ? `<span style="font-family:monospace;background:#f1f5f9;padding:2px 7px;border-radius:4px;font-size:11.5px;color:#0f5132;font-weight:600;">${this.escapeHtml(l.account_number)}</span>` : '<span style="color:#94a3b8;">-</span>';
        const debtAmt = parseFloat(l.debt_amount || 0);
        const curr = l.currency || 'ETB';
        const debtFormatted = debtAmt > 0
          ? `<span style="font-weight:700;color:#15803d;font-size:12.5px;">${curr} ${debtAmt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>`
          : `<span style="color:#64748b;font-size:12px;">0.00 ETB</span>`;

        const campDisplay = l.campaign_name || this.campaign?.name || 'Campaign';
        const campBadge = `<span class="sc-badge running" style="font-size:11px;font-weight:700;padding:2px 8px;white-space:nowrap;" title="Campaign: ${this.escapeHtml(campDisplay)}">${this.escapeHtml(campDisplay)}</span>`;

        if (isFull) {
          tr.innerHTML = `
            <td>${idx + 1}</td>
            <td><strong>${this.escapeHtml(l.customer_name || 'Customer')}</strong></td>
            <td>
              <a href="javascript:void(0)" onclick="app.clickToDial('${this.escapeHtml(l.phone_number)}', '${this.escapeHtml(l.customer_name)}')" style="color:#00A651;font-weight:700;text-decoration:none;" title="Click to Call with Softphone">
                ${this.escapeHtml(l.phone_number)}
              </a>
            </td>
            <td>${campBadge}</td>
            <td>${acct}</td>
            <td>${debtFormatted}</td>
            <td><span style="${timeBadgeStyle}" title="${isDue ? 'Ready to Call' : 'Scheduled for future'}">${this.escapeHtml(callTime)}</span></td>
            <td>${stBadge}</td>
            <td>${dur}</td>
            <td style="white-space:nowrap;">
              <button class="btn btn-primary btn-sm" onclick="app.dialSingleLeadWithIvr(${l.id})" title="Outbound Call with IVR">Call</button>
              <button class="btn btn-secondary btn-sm" onclick="app.deleteLead(${l.id})" style="color:#ef4444;margin-left:4px;" title="Delete">Delete</button>
            </td>
          `;
        } else {
          tr.innerHTML = `
            <td>${idx + 1}</td>
            <td><strong>${this.escapeHtml(l.customer_name || 'Customer')}</strong></td>
            <td>
              <a href="javascript:void(0)" onclick="app.dialSingleLeadWithIvr(${l.id})" style="color:#00A651;font-weight:700;text-decoration:none;" title="Click to Dial Outbound with IVR">
                ${this.escapeHtml(l.phone_number)}
              </a>
            </td>
            <td>${campBadge}</td>
            <td><span style="${timeBadgeStyle}" title="${isDue ? 'Ready to Call' : 'Scheduled for future'}">${this.escapeHtml(callTime)}</span></td>
            <td>${stBadge}</td>
            <td>${dur}</td>
            <td style="white-space:nowrap;">
              <button class="btn btn-primary btn-sm" onclick="app.dialSingleLeadWithIvr(${l.id})" title="Outbound Call with IVR">Call</button>
              <button class="btn btn-secondary btn-sm" onclick="app.deleteLead(${l.id})" style="color:#ef4444;" title="Delete">Delete</button>
            </td>
          `;
        }
        tbodyEl.appendChild(tr);
      });
    };

    renderTarget(this.leadsTableBody, false);
    renderTarget(this.leadsTableBodyFull, true);
  }

  /* ─────────────────────────────────────────────────────────
     History Page & Past Calls Management
     ───────────────────────────────────────────────────────── */
  renderHistoryTable() {
    const tbody = document.getElementById('historyTableBody');
    if (!tbody) return;

    // Populate History Date Filter Dropdown if not filled
    const dateSelect = document.getElementById('historyDateSelect');
    if (dateSelect && this.availableDates) {
      const currentSelected = dateSelect.value;
      const today = this.serverToday || new Date().toISOString().split('T')[0];
      const pastDates = this.availableDates.filter(d => d !== today);

      let optionsHtml = '<option value="all">All Past Dates</option><option value="yesterday">Yesterday</option>';
      pastDates.forEach(d => {
        optionsHtml += `<option value="${d}">${d}</option>`;
      });
      dateSelect.innerHTML = optionsHtml;
      if (currentSelected && (currentSelected === 'all' || currentSelected === 'yesterday' || pastDates.includes(currentSelected))) {
        dateSelect.value = currentSelected;
      }
    }

    const dateFilter = dateSelect?.value || 'all';
    const customDatePicker = document.getElementById('historyCustomDatePicker');
    const customDate = customDatePicker?.value || '';
    const searchInput = document.getElementById('historySearchInput');
    const query = searchInput?.value.toLowerCase().trim() || '';
    const statusFilter = document.getElementById('historyStatusFilter')?.value || 'all';

    const yestDate = new Date(Date.now() - 86400000).toISOString().split('T')[0];
    const pastLeads = this.getPastLeads();

    const filtered = pastLeads.filter(l => {
      const effDate = l.effective_date || (l.created_at ? l.created_at.split(' ')[0] : '');

      // Date match
      if (customDate) {
        if (effDate !== customDate) return false;
      } else if (dateFilter === 'yesterday') {
        if (effDate !== yestDate) return false;
      } else if (dateFilter !== 'all') {
        if (effDate !== dateFilter) return false;
      }

      // Search match
      if (query) {
        const matchName = l.customer_name && l.customer_name.toLowerCase().includes(query);
        const matchPhone = l.phone_number && l.phone_number.includes(query);
        if (!matchName && !matchPhone) return false;
      }

      // Status match
      if (statusFilter !== 'all') {
        if (statusFilter === 'answered') {
          if (!['answered', 'ivr_playing', 'ivr_completed', 'completed'].includes(l.status)) return false;
        } else if (l.status !== statusFilter) {
          return false;
        }
      }

      return true;
    });

    const countTag = document.getElementById('historyTotalRecordsTag');
    if (countTag) countTag.textContent = `${filtered.length} records`;

    tbody.innerHTML = '';
    if (filtered.length === 0) {
      tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:28px;">No historical call records found for selected filter.</td></tr>`;
      return;
    }

    filtered.forEach((l, idx) => {
      const tr = document.createElement('tr');
      const stBadge = this.getStatusBadge(l.status);
      const effDate = l.effective_date || (l.created_at ? l.created_at.split(' ')[0] : '-');
      const dur = l.duration_sec ? `${l.duration_sec}s` : '-';
      const callTime = l.call_time || 'Immediate';

      tr.innerHTML = `
        <td>${idx + 1}</td>
        <td><span style="font-size:11.5px;font-weight:700;color:#00A651;">${this.escapeHtml(effDate)}</span></td>
        <td><strong>${this.escapeHtml(l.customer_name || 'Customer')}</strong></td>
        <td>
          <a href="javascript:void(0)" onclick="app.clickToDial('${this.escapeHtml(l.phone_number)}', '${this.escapeHtml(l.customer_name)}')" style="color:#00A651;font-weight:700;text-decoration:none;" title="Click to Call">
            ${this.escapeHtml(l.phone_number)}
          </a>
        </td>
        <td><span style="font-size:11.5px;color:#334155;">${this.escapeHtml(callTime)}</span></td>
        <td>${stBadge}</td>
        <td>${dur}</td>
        <td>
          <button class="btn btn-primary btn-sm" onclick="app.openRedialModal(${l.id})" style="margin-right:4px;" title="Redial & Schedule with new date/time">
            Redial
          </button>
          <button class="btn btn-secondary btn-sm" onclick="app.clickToDial('${this.escapeHtml(l.phone_number)}', '${this.escapeHtml(l.customer_name)}')" title="Instant Call">
            Call
          </button>
        </td>
      `;
      tbody.appendChild(tr);
    });
  }

  handleHistoryDateFilter(val) {
    const customPicker = document.getElementById('historyCustomDatePicker');
    if (customPicker) customPicker.value = '';
    this.renderHistoryTable();
  }

  handleHistoryCustomDate(val) {
    this.renderHistoryTable();
  }

  openRedialModal(leadId) {
    const lead = this.leads.find(l => Number(l.id) === Number(leadId));
    if (!lead) return;

    const idInp = document.getElementById('redialLeadId');
    const nameEl = document.getElementById('redialCustomerName');
    const phoneEl = document.getElementById('redialPhoneNumber');
    const dateInp = document.getElementById('redialDateInput');
    const timeInp = document.getElementById('redialCustomTimeInput');

    if (idInp) idInp.value = lead.id;
    if (nameEl) nameEl.textContent = lead.customer_name || 'Customer';
    if (phoneEl) phoneEl.textContent = lead.phone_number || '';
    
    const today = this.serverToday || new Date().toISOString().split('T')[0];
    if (dateInp) dateInp.value = today;

    const now = new Date();
    const hh = String(now.getHours()).padStart(2, '0');
    const mm = String(now.getMinutes()).padStart(2, '0');
    if (timeInp) timeInp.value = `${hh}:${mm}`;

    this.updateRedialPreview();
    this.openModal('modalRedialSchedule');
  }

  setRedialPreset(preset) {
    const now = new Date();
    let target = new Date();
    let hoursStr = '09:00';

    if (preset === '+15m') {
      target = new Date(now.getTime() + 15 * 60000);
      hoursStr = String(target.getHours()).padStart(2, '0') + ':' + String(target.getMinutes()).padStart(2, '0');
    } else if (preset === '+1h') {
      target = new Date(now.getTime() + 60 * 60000);
      hoursStr = String(target.getHours()).padStart(2, '0') + ':' + String(target.getMinutes()).padStart(2, '0');
    } else if (preset === '+3h') {
      target = new Date(now.getTime() + 180 * 60000);
      hoursStr = String(target.getHours()).padStart(2, '0') + ':' + String(target.getMinutes()).padStart(2, '0');
    } else if (preset === 'tomorrow_am') {
      target = new Date(now.getTime() + 86400000);
      hoursStr = '09:00';
    } else if (preset === 'tomorrow_pm') {
      target = new Date(now.getTime() + 86400000);
      hoursStr = '14:00';
    } else if (preset === '+3days') {
      target = new Date(now.getTime() + 3 * 86400000);
      hoursStr = '10:00';
    }

    const yyyy = target.getFullYear();
    const mm = String(target.getMonth() + 1).padStart(2, '0');
    const dd = String(target.getDate()).padStart(2, '0');

    const dateInp = document.getElementById('redialDateInput');
    const timeInp = document.getElementById('redialCustomTimeInput');
    if (dateInp) dateInp.value = `${yyyy}-${mm}-${dd}`;
    if (timeInp) timeInp.value = hoursStr;

    this.updateRedialPreview();
  }

  updateRedialPreview() {
    const previewEl = document.getElementById('redialPreviewText');
    const dateVal = document.getElementById('redialDateInput')?.value;
    const timeVal = document.getElementById('redialCustomTimeInput')?.value || '09:00';

    if (!previewEl) return;
    if (!dateVal) {
      previewEl.textContent = 'Please choose a date from the calendar.';
      return;
    }

    try {
      const dt = new Date(`${dateVal}T${timeVal}`);
      const options = { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
      previewEl.textContent = `📅 Redial scheduled for: ${dt.toLocaleDateString('en-US', options)}`;
    } catch (e) {
      previewEl.textContent = `📅 Redial scheduled for: ${dateVal} at ${timeVal}`;
    }
  }

  async handleRedialSubmit(e) {
    if (e) e.preventDefault();
    const leadId = document.getElementById('redialLeadId')?.value;
    const newDate = document.getElementById('redialDateInput')?.value;
    const customTime = document.getElementById('redialCustomTimeInput')?.value || '09:00';

    if (!leadId || !newDate) {
      this.showToast('Please select a date from the calendar.', 'error');
      return;
    }

    try {
      const formData = new FormData();
      formData.append('lead_id', leadId);
      formData.append('new_date', newDate);
      formData.append('new_time', customTime);

      const res = await fetch('api.php?action=reschedule_lead', { method: 'POST', body: formData });
      const data = await res.json();
      if (data && data.success) {
        this.closeModal('modalRedialSchedule');
        this.showToast(data.message || 'Lead scheduled for redial', 'success');
        await this.loadCampaignData();
        await this.loadCallbacksList();
      } else {
        this.showToast(data.error || 'Failed to reschedule contact', 'error');
      }
    } catch(err) {
      this.showToast('Error rescheduling contact', 'error');
    }
  }

  async requeueSingleLead(leadId) {
    const formData = new FormData();
    formData.append('lead_id', leadId);
    try {
      const res = await fetch('api.php?action=requeue_lead', { method: 'POST', body: formData });
      const data = await res.json();
      if (data && data.success) {
        this.showToast(data.message || 'Lead re-queued into active calling queue', 'success');
        await this.loadCallbacksList();
        await this.loadCampaignData();
      } else {
        this.showToast(data.error || 'Failed to re-queue lead', 'error');
      }
    } catch (e) {
      this.showToast('Network error re-queueing lead', 'error');
    }
  }

  async bulkRolloverRemainingToToday() {
    try {
      const formData = new FormData();
      formData.append('campaign_id', this.campaign?.id || 1);
      formData.append('target_date', this.serverToday || new Date().toISOString().split('T')[0]);
      formData.append('target_time', 'Immediate');

      const res = await fetch('api.php?action=bulk_rollover_to_today', { method: 'POST', body: formData });
      const data = await res.json();
      if (data.success) {
        this.showToast(data.message, 'success');
        await this.loadCampaignData();
        switchTab('tabLiveRoom');
      } else {
        this.showToast(data.error || 'Rollover failed', 'error');
      }
    } catch (e) {
      this.showToast('Error during bulk rollover', 'error');
    }
  }

  exportHistoryCsv() {
    const pastLeads = this.getPastLeads();
    if (pastLeads.length === 0) {
      this.showToast('No historical records to export.', 'info');
      return;
    }
    let csv = "Date,Customer Name,Phone Number,Scheduled Time,Status,Duration (sec),Notes\n";
    pastLeads.forEach(l => {
      const effDate = l.effective_date || (l.created_at ? l.created_at.split(' ')[0] : '');
      csv += `"${effDate}","${(l.customer_name || '').replace(/"/g, '""')}","${l.phone_number}","${l.call_time || ''}","${l.status}","${l.duration_sec || 0}","${(l.notes || '').replace(/"/g, '""')}"\n`;
    });
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `call_history_${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  renderLogsTable(logs) {
    if (!this.logsTableBody) return;
    this.logsTableBody.innerHTML = '';

    if (!logs || logs.length === 0) {
      this.logsTableBody.innerHTML = `<tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:24px;">No call logs recorded yet.</td></tr>`;
      return;
    }

    logs.forEach(log => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td style="font-size:11.5px;color:#64748b;">${this.escapeHtml(log.timestamp || '')}</td>
        <td><strong>${this.escapeHtml(log.customer_name || 'Customer')}</strong></td>
        <td>${this.escapeHtml(log.phone_number || '')}</td>
        <td>${this.getStatusBadge(log.status)}</td>
        <td>${log.duration_sec || 0}s</td>
        <td><span style="font-size:11px;background:#f1f5f9;padding:3px 8px;border-radius:6px;color:#334155;">${this.escapeHtml(log.audio_played || 'Human Audio')}</span></td>
        <td><span style="font-size:11px;color:#0369a1;">${this.escapeHtml(log.call_time || '-')}</span></td>
      `;
      this.logsTableBody.appendChild(tr);
    });
  }

  async loadCallbacksList() {
    try {
      const res = await fetch('api.php?action=get_callbacks');
      const data = await res.json();
      if (!data.success) return;
      this.callbacksList = data.callbacks || [];
      this.renderUnansweredTable();
    } catch (e) {
      console.warn('loadCallbacksList error:', e);
    }
  }

  renderUnansweredTable() {
    const tbody = document.getElementById('unansweredTableBody');
    const badge = document.getElementById('badgeUnansweredCount');

    const cbs = this.callbacksList || [];
    const missed = (this.leads || []).filter(l => ['no_answer', 'busy', 'failed'].includes(l.status));
    const totalCount = cbs.length + missed.length;
    if (badge) badge.textContent = totalCount;

    if (!tbody) return;
    tbody.innerHTML = '';

    if (totalCount === 0) {
      tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:24px;">No pending callbacks or missed leads.</td></tr>`;
      return;
    }

    let rowIdx = 1;

    // 1. Render Scheduled Callbacks from PostgreSQL
    cbs.forEach(cb => {
      const isCompleted = cb.status === 'completed';
      const sched = cb.scheduled_time ? cb.scheduled_time.replace('T', ' ').substring(0, 16) : 'Scheduled';
      const tr = document.createElement('tr');
      tr.style.background = isCompleted ? '#ffffff' : '#f0fdf4';
      tr.innerHTML = `
        <td>${rowIdx++}</td>
        <td><strong>${this.escapeHtml(cb.customer_name || 'Customer')}</strong></td>
        <td>
          <a href="javascript:void(0)" onclick="app.clickToDial('${this.escapeHtml(cb.phone_number)}', '${this.escapeHtml(cb.customer_name)}')" style="color:#00A651;font-weight:700;text-decoration:none;">
            ${this.escapeHtml(cb.phone_number)}
          </a>
        </td>
        <td>
          <span class="sc-badge ${isCompleted ? 'done' : 'running'}" style="font-size:11px;">
            ${isCompleted ? 'Completed' : 'Scheduled Callback'}
          </span>
          <small style="color:#475569;margin-left:6px;">${this.escapeHtml(cb.notes || '')}</small>
        </td>
        <td><span style="font-size:11.5px;color:#0369a1;font-weight:700;">${this.escapeHtml(sched)}</span></td>
        <td style="font-size:11.5px;color:#64748b;">Assigned: Agent ${this.escapeHtml(cb.assigned_agent || '101')}</td>
        <td style="white-space:nowrap;">
          <button class="btn btn-primary btn-sm" onclick="app.clickToDial('${this.escapeHtml(cb.phone_number)}', '${this.escapeHtml(cb.customer_name)}')" title="Instant Call">
            Dial
          </button>
          ${!isCompleted ? `
            <button class="btn btn-success btn-sm" onclick="app.completeCallback(${cb.id})" style="margin-left:4px;" title="Mark Completed">✓</button>
            <button class="btn btn-secondary btn-sm" onclick="app.openRescheduleModal(${cb.id}, '${this.escapeHtml(cb.customer_name)}', '${this.escapeHtml(cb.phone_number)}', '${cb.scheduled_time || ''}')" style="margin-left:4px;" title="Choose Date & Time with Calendar">📅</button>
          ` : `
            <button class="btn btn-secondary btn-sm" onclick="app.openRescheduleModal(${cb.id}, '${this.escapeHtml(cb.customer_name)}', '${this.escapeHtml(cb.phone_number)}', '${cb.scheduled_time || ''}')" style="margin-left:4px;" title="Reschedule Callback">📅</button>
          `}
        </td>
      `;
      tbody.appendChild(tr);
    });

    // 2. Render Unanswered / Busy Leads
    missed.forEach(l => {
      const tr = document.createElement('tr');
      const stBadge = this.getStatusBadge(l.status);
      const lastAttempt = l.last_attempt_at || '-';
      const reason = l.error_message || (l.status === 'no_answer' ? 'No Answer / Timeout' : (l.status === 'busy' ? 'Busy Line' : 'Call Failed'));

      tr.innerHTML = `
        <td>${rowIdx++}</td>
        <td><strong>${this.escapeHtml(l.customer_name || 'Customer')}</strong></td>
        <td>
          <a href="javascript:void(0)" onclick="app.clickToDial('${this.escapeHtml(l.phone_number)}', '${this.escapeHtml(l.customer_name)}')" style="color:#00A651;font-weight:700;text-decoration:none;">
            ${this.escapeHtml(l.phone_number)}
          </a>
        </td>
        <td>${stBadge} <small style="color:#64748b;margin-left:4px;">(${this.escapeHtml(reason)})</small></td>
        <td><span style="font-size:11.5px;color:#0369a1;">${this.escapeHtml(l.call_time || '-')}</span></td>
        <td style="font-size:11.5px;color:#64748b;">${this.escapeHtml(lastAttempt)}</td>
        <td style="white-space:nowrap;">
          <button class="btn btn-primary btn-sm" onclick="app.clickToDial('${this.escapeHtml(l.phone_number)}', '${this.escapeHtml(l.customer_name)}')" title="Dial Customer">Dial</button>
          <button class="btn btn-secondary btn-sm" onclick="app.requeueSingleLead(${l.id})" style="margin-left:4px;" title="Move back to Calling Queue">Re-queue</button>
          <button class="btn btn-secondary btn-sm" onclick="app.openRedialModal(${l.id})" style="margin-left:4px;" title="Schedule with Calendar">📅</button>
        </td>
      `;
      tbody.appendChild(tr);
    });
  }

  async completeCallback(callbackId) {
    const formData = new FormData();
    formData.append('callback_id', callbackId);
    try {
      const res = await fetch('api.php?action=complete_callback', { method: 'POST', body: formData });
      const data = await res.json();
      if (data.success) {
        this.showToast('Callback marked completed in PostgreSQL', 'success');
        this.loadCallbacksList();
      }
    } catch (e) {
      this.showToast('Failed to update callback', 'error');
    }
  }

  openRescheduleModal(callbackId, name = 'Customer', phone = '', scheduledTime = '') {
    const idInp = document.getElementById('rescheduleCallbackId');
    const nameEl = document.getElementById('rescheduleCustomerName');
    const phoneEl = document.getElementById('reschedulePhoneNumber');
    const dateInp = document.getElementById('rescheduleDateInput');
    const timeInp = document.getElementById('rescheduleTimeInput');
    const notesInp = document.getElementById('rescheduleNotesInput');

    if (idInp) idInp.value = callbackId;
    if (nameEl) nameEl.textContent = name || 'Customer';
    if (phoneEl) phoneEl.textContent = phone || '';
    if (notesInp) notesInp.value = '';

    // If existing scheduled time is provided, parse it; otherwise default to tomorrow at 09:00
    let targetDate = new Date(Date.now() + 86400000);
    let hoursStr = '09:00';

    if (scheduledTime && scheduledTime.length >= 10) {
      try {
        const parsed = new Date(scheduledTime.replace(' ', 'T'));
        if (!isNaN(parsed.getTime())) {
          targetDate = parsed;
          hoursStr = String(parsed.getHours()).padStart(2, '0') + ':' + String(parsed.getMinutes()).padStart(2, '0');
        }
      } catch (e) {}
    }

    const yyyy = targetDate.getFullYear();
    const mm = String(targetDate.getMonth() + 1).padStart(2, '0');
    const dd = String(targetDate.getDate()).padStart(2, '0');

    if (dateInp) dateInp.value = `${yyyy}-${mm}-${dd}`;
    if (timeInp) timeInp.value = hoursStr;

    this.updateReschedulePreview();
    this.openModal('modalRescheduleCallback');
  }

  setReschedulePreset(preset) {
    const now = new Date();
    let target = new Date();
    let hoursStr = '09:00';

    if (preset === '+15m') {
      target = new Date(now.getTime() + 15 * 60000);
      hoursStr = String(target.getHours()).padStart(2, '0') + ':' + String(target.getMinutes()).padStart(2, '0');
    } else if (preset === '+1h') {
      target = new Date(now.getTime() + 60 * 60000);
      hoursStr = String(target.getHours()).padStart(2, '0') + ':' + String(target.getMinutes()).padStart(2, '0');
    } else if (preset === '+3h') {
      target = new Date(now.getTime() + 180 * 60000);
      hoursStr = String(target.getHours()).padStart(2, '0') + ':' + String(target.getMinutes()).padStart(2, '0');
    } else if (preset === 'tomorrow_am') {
      target = new Date(now.getTime() + 86400000);
      hoursStr = '09:00';
    } else if (preset === 'tomorrow_pm') {
      target = new Date(now.getTime() + 86400000);
      hoursStr = '14:00';
    } else if (preset === '+3days') {
      target = new Date(now.getTime() + 3 * 86400000);
      hoursStr = '10:00';
    }

    const yyyy = target.getFullYear();
    const mm = String(target.getMonth() + 1).padStart(2, '0');
    const dd = String(target.getDate()).padStart(2, '0');

    const dateInp = document.getElementById('rescheduleDateInput');
    const timeInp = document.getElementById('rescheduleTimeInput');
    if (dateInp) dateInp.value = `${yyyy}-${mm}-${dd}`;
    if (timeInp) timeInp.value = hoursStr;

    this.updateReschedulePreview();
  }

  updateReschedulePreview() {
    const previewEl = document.getElementById('reschedulePreviewText');
    const dateVal = document.getElementById('rescheduleDateInput')?.value;
    const timeVal = document.getElementById('rescheduleTimeInput')?.value || '09:00';

    if (!previewEl) return;
    if (!dateVal) {
      previewEl.textContent = 'Please choose a date from the calendar.';
      return;
    }

    try {
      const dt = new Date(`${dateVal}T${timeVal}`);
      const options = { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
      previewEl.textContent = `📅 Scheduled for: ${dt.toLocaleDateString('en-US', options)}`;
    } catch (e) {
      previewEl.textContent = `📅 Scheduled for: ${dateVal} at ${timeVal}`;
    }
  }

  async handleRescheduleSubmit(e) {
    if (e) e.preventDefault();
    const callbackId = document.getElementById('rescheduleCallbackId')?.value;
    const dateVal = document.getElementById('rescheduleDateInput')?.value;
    const timeVal = document.getElementById('rescheduleTimeInput')?.value || '09:00';
    const notesVal = document.getElementById('rescheduleNotesInput')?.value.trim() || '';

    if (!callbackId || !dateVal) {
      this.showToast('Please select a date from the calendar.', 'error');
      return;
    }

    const fullTimestamp = `${dateVal} ${timeVal}:00`;
    const formData = new FormData();
    formData.append('callback_id', callbackId);
    formData.append('scheduled_time', fullTimestamp);
    if (notesVal) formData.append('notes', notesVal);

    try {
      const res = await fetch('api.php?action=reschedule_callback', { method: 'POST', body: formData });
      const data = await res.json();
      if (data && data.success) {
        this.showToast(`Callback successfully rescheduled for ${dateVal} at ${timeVal}!`, 'success');
        this.closeModal('modalRescheduleCallback');
        await this.loadCallbacksList();
      } else {
        this.showToast(data.error || 'Failed to reschedule callback.', 'error');
      }
    } catch (err) {
      this.showToast('Network error rescheduling callback.', 'error');
    }
  }

  async rescheduleCallback(callbackId) {
    const cb = (this.callbacksList || []).find(c => Number(c.id) === Number(callbackId));
    this.openRescheduleModal(callbackId, cb?.customer_name, cb?.phone_number, cb?.scheduled_time);
  }

  async loadDncList() {
    try {
      const res = await fetch('api.php?action=get_dnc_list');
      const data = await res.json();
      if (!data.success) return;
      this.dncList = data.dnc_list || [];

      const tbody = document.getElementById('dncTableBody');
      const countTag = document.getElementById('dncCountTag');
      if (countTag) countTag.textContent = this.dncList.length;

      if (!tbody) return;
      if (this.dncList.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:24px;color:#64748b;">No numbers currently registered on Do-Not-Call list.</td></tr>`;
        return;
      }

      tbody.innerHTML = this.dncList.map(item => {
        const dateStr = item.created_at ? item.created_at.substring(0, 10) : 'Today';
        return `
          <tr style="border-bottom:1px solid #f0f5f1;">
            <td style="font-weight:700;color:#0d2e1b;font-family:monospace;">${this.escapeHtml(item.phone_number)}</td>
            <td style="font-size:12px;color:#526e5f;">${this.escapeHtml(item.reason || 'Customer opt-out')}</td>
            <td style="font-size:11.5px;color:#64748b;">${this.escapeHtml(dateStr)}</td>
            <td style="font-size:11.5px;color:#1a3d2a;">${this.escapeHtml(item.added_by || 'Agent')}</td>
            <td style="text-align:right;">
              <button class="btn btn-xs btn-danger" onclick="app.removeDncNumber(${item.id})" style="font-size:11px;padding:3px 8px;" title="Remove from DNC">
                Unblock
              </button>
            </td>
          </tr>
        `;
      }).join('');
    } catch (e) {
      console.warn('loadDncList error:', e);
    }
  }

  async handleAddDncSubmit(e) {
    e.preventDefault();
    const phoneInput = document.getElementById('dncInputPhone');
    const reasonSelect = document.getElementById('dncInputReason');
    const phone = phoneInput ? phoneInput.value.trim() : '';
    const reason = reasonSelect ? reasonSelect.value : '';

    if (!phone) {
      this.showToast('Please enter a phone number', 'warning');
      return;
    }

    const formData = new FormData();
    formData.append('phone_number', phone);
    formData.append('reason', reason);

    try {
      const res = await fetch('api.php?action=add_dnc_number', { method: 'POST', body: formData });
      const data = await res.json();
      if (data.success) {
        this.showToast(data.message || 'Number added to DNC list!', 'success');
        if (phoneInput) phoneInput.value = '';
        await this.loadDncList();
        await this.loadCampaignData();
      } else {
        this.showToast(data.error || 'Failed to add number to DNC', 'error');
      }
    } catch (err) {
      this.showToast('Network error adding to DNC', 'error');
    }
  }

  async removeDncNumber(id) {
    if (!confirm('Are you sure you want to remove this number from the Do-Not-Call registry?')) return;
    const formData = new FormData();
    formData.append('id', id);
    try {
      const res = await fetch('api.php?action=remove_dnc_number', { method: 'POST', body: formData });
      const data = await res.json();
      if (data.success) {
        this.showToast('Number removed from DNC list', 'info');
        this.loadDncList();
      }
    } catch (e) {
      this.showToast('Failed to remove number from DNC', 'error');
    }
  }

  async scrubQueueDnc() {
    this.showToast('Running pre-dial regulatory DNC scrub...', 'info');
    try {
      const res = await fetch('api.php?action=scrub_dnc_queue', { method: 'POST' });
      const data = await res.json();
      if (data.success) {
        this.showToast(data.message, 'success');
        await this.loadCampaignData();
      }
    } catch (e) {
      this.showToast('Failed to run DNC scrub', 'error');
    }
  }


  async requeueUnansweredLeads() {
    let count = 0;
    this.leads.forEach(l => {
      if (['no_answer', 'busy', 'failed'].includes(l.status)) {
        l.status = 'pending';
        l.duration_sec = 0;
        count++;
      }
    });
    this.renderLeadsTables();
    this.renderUnansweredTable();
    this.updateLocalStats();
    this.renderActiveChannels();
    this.showToast(`Re-queued ${count} unanswered leads!`, 'success');

    try {
      const formData = new FormData();
      formData.append('campaign_id', this.campaign?.id || 1);
      await fetch('api.php?action=requeue_unanswered', { method: 'POST', body: formData });
    } catch (e) {
      this.showToast('Failed to sync re-queue with server', 'error');
    }
  }

  async requeueSingleLead(leadId) {
    const lead = this.leads.find(l => Number(l.id) === Number(leadId));
    if (lead) {
      lead.status = 'pending';
      lead.duration_sec = 0;
      this.renderLeadsTables();
      this.renderUnansweredTable();
      this.updateLocalStats();
      this.renderActiveChannels();
    }
    this.showToast('Contact moved back to calling queue!', 'success');

    try {
      const formData = new FormData();
      formData.append('lead_id', leadId);
      formData.append('status', 'pending');
      formData.append('duration_sec', 0);
      await fetch('api.php?action=update_lead_status', { method: 'POST', body: formData });
    } catch(e) {
      this.showToast('Error syncing contact status', 'error');
    }
  }

  exportUnansweredCsv() {
    const unansweredLeads = this.leads.filter(l => ['no_answer', 'busy', 'failed'].includes(l.status));
    if (unansweredLeads.length === 0) {
      this.showToast('No unanswered leads to export.', 'info');
      return;
    }
    let csv = "Customer Name,Phone Number,Status,Call Time,Notes\n";
    unansweredLeads.forEach(l => {
      csv += `"${(l.customer_name || '').replace(/"/g, '""')}","${l.phone_number}","${l.status}","${l.call_time || ''}","${(l.notes || '').replace(/"/g, '""')}"\n`;
    });
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `unanswered_leads_${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  getStatusBadge(st) {
    const map = {
      'pending': '<span style="background:#f1f5f9;color:#475569;padding:4px 10px;border-radius:12px;font-size:11.5px;font-weight:700;display:inline-flex;align-items:center;gap:5px;border:1px solid #e2e8f0;"><span style="width:6px;height:6px;border-radius:50%;background:#94a3b8;display:inline-block;"></span>Pending</span>',
      'calling': '<span style="background:#fef3c7;color:#b45309;padding:4px 10px;border-radius:12px;font-size:11.5px;font-weight:700;display:inline-flex;align-items:center;gap:5px;border:1px solid #fde68a;box-shadow:0 0 8px rgba(245,158,11,0.25);"><span style="width:7px;height:7px;border-radius:50%;background:#f59e0b;display:inline-block;"></span>Calling...</span>',
      'answered': '<span style="background:#dcfce7;color:#15803d;padding:4px 10px;border-radius:12px;font-size:11.5px;font-weight:700;display:inline-flex;align-items:center;gap:5px;border:1px solid #bbf7d0;"><span style="width:6px;height:6px;border-radius:50%;background:#22c55e;display:inline-block;"></span>Answered</span>',
      'ivr_playing': '<span style="background:#dbeafe;color:#1d4ed8;padding:4px 10px;border-radius:12px;font-size:11.5px;font-weight:700;display:inline-flex;align-items:center;gap:5px;border:1px solid #bfdbfe;"><span style="width:6px;height:6px;border-radius:50%;background:#3b82f6;display:inline-block;"></span>IVR Playing</span>',
      'ivr_completed': '<span style="background:#dcfce7;color:#15803d;padding:4px 10px;border-radius:12px;font-size:11.5px;font-weight:700;display:inline-flex;align-items:center;gap:5px;border:1px solid #bbf7d0;"><span style="width:6px;height:6px;border-radius:50%;background:#22c55e;display:inline-block;"></span>Completed</span>',
      'completed': '<span style="background:#dcfce7;color:#15803d;padding:4px 10px;border-radius:12px;font-size:11.5px;font-weight:700;display:inline-flex;align-items:center;gap:5px;border:1px solid #bbf7d0;"><span style="width:6px;height:6px;border-radius:50%;background:#22c55e;display:inline-block;"></span>Completed</span>',
      'busy': '<span style="background:#fee2e2;color:#b91c1c;padding:4px 10px;border-radius:12px;font-size:11.5px;font-weight:700;display:inline-flex;align-items:center;gap:5px;border:1px solid #fecaca;"><span style="width:6px;height:6px;border-radius:50%;background:#ef4444;display:inline-block;"></span>Busy</span>',
      'no_answer': '<span style="background:#fee2e2;color:#b91c1c;padding:4px 10px;border-radius:12px;font-size:11.5px;font-weight:700;display:inline-flex;align-items:center;gap:5px;border:1px solid #fecaca;"><span style="width:6px;height:6px;border-radius:50%;background:#ef4444;display:inline-block;"></span>No Answer</span>',
      'failed': '<span style="background:#fee2e2;color:#b91c1c;padding:4px 10px;border-radius:12px;font-size:11.5px;font-weight:700;display:inline-flex;align-items:center;gap:5px;border:1px solid #fecaca;"><span style="width:6px;height:6px;border-radius:50%;background:#ef4444;display:inline-block;"></span>Failed</span>'
    };
    return map[st] || `<span style="background:#f1f5f9;color:#475569;padding:4px 10px;border-radius:12px;font-size:11.5px;font-weight:700;">${st}</span>`;
  }

  populateAudioDropdown() {
    const fill = (el) => {
      if (!el) return;
      el.innerHTML = '';
      this.audioRecordings.forEach(a => {
        const opt = document.createElement('option');
        opt.value = a.id;
        opt.textContent = a.name;
        if (this.campaign && (this.campaign.ivr_audio_name === a.name || this.campaign.ivr_audio_file === a.filepath)) {
          opt.selected = true;
        }
        el.appendChild(opt);
      });
    };
    fill(this.audioSelectDropdown);
    fill(this.audioSelectDropdownTab);
    this.renderAudioListTable();
  }

  renderAudioListTable() {
    const tbody = document.getElementById('audioListTableBody');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!this.audioRecordings || this.audioRecordings.length === 0) {
      tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:20px;">No audio recordings found. Click "Upload New Audio" to add one.</td></tr>`;
      return;
    }

    this.audioRecordings.forEach((a, idx) => {
      const isActive = this.campaign && (this.campaign.ivr_audio_name === a.name || this.campaign.ivr_audio_file === a.filepath);
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${idx + 1}</td>
        <td><strong style="color:#00A651;">${this.escapeHtml(a.name)}</strong></td>
        <td style="font-size:12px;color:#64748b;font-family:monospace;">${this.escapeHtml(a.filename || a.filepath)}</td>
        <td>
          ${isActive 
            ? '<span class="status-badge answered" style="font-weight:700;">Active IVR</span>' 
            : '<span class="status-badge pending">Inactive</span>'}
        </td>
        <td>
          <button class="btn btn-secondary btn-sm" onclick="app.previewAudioItem('${this.escapeHtml(a.filepath || '')}')" title="Play Preview">Play</button>
        </td>
        <td>
          ${isActive 
            ? '<button class="btn btn-sm btn-success" disabled>Active</button>'
            : `<button class="btn btn-sm btn-primary" onclick="app.selectAudioRecording(${a.id})">Use for Calls</button>`}
          ${a.id > 1 
            ? `<button class="btn btn-sm btn-secondary" onclick="app.deleteAudioRecording(${a.id})" style="color:#ef4444;margin-left:4px;" title="Delete Recording">Delete</button>`
            : ''}
        </td>
      `;
      tbody.appendChild(tr);
    });
  }

  previewAudioItem(filepath) {
    if (!filepath) return;
    const tabPlayer = document.getElementById('tabAudioPlayer');
    if (tabPlayer) {
      tabPlayer.src = filepath;
      tabPlayer.play().catch(() => {});
    }
    const mainPlayer = document.getElementById('mainAudioPlayer');
    if (mainPlayer) {
      mainPlayer.src = filepath;
    }
  }

  async selectAudioRecording(audioId) {
    try {
      const formData = new FormData();
      formData.append('audio_id', audioId);
      formData.append('campaign_id', this.campaign?.id || 1);

      const res = await fetch('api.php?action=select_audio', { method: 'POST', body: formData });
      const data = await res.json();
      if (data.success) {
        this.showToast(data.message, 'success');
        const selectedAudio = this.audioRecordings.find(a => String(a.id) === String(audioId));
        if (selectedAudio && selectedAudio.filepath) {
          const tabPlayer = document.getElementById('tabAudioPlayer');
          if (tabPlayer) tabPlayer.src = selectedAudio.filepath;
          const mainPlayer = document.getElementById('mainAudioPlayer');
          if (mainPlayer) mainPlayer.src = selectedAudio.filepath;
        }
        await this.loadCampaignData();
      } else {
        this.showToast(data.error || 'Failed to select audio recording', 'error');
      }
    } catch (e) {
      this.showToast('Failed to select audio recording', 'error');
    }
  }

  async deleteAudioRecording(audioId) {
    const originalAudios = [...this.audioRecordings];
    this.audioRecordings = this.audioRecordings.filter(a => Number(a.id) !== Number(audioId));
    this.renderAudioListTable();
    this.populateAudioDropdown();
    this.showToast('Audio recording deleted', 'info');

    try {
      const formData = new FormData();
      formData.append('audio_id', audioId);
      const res = await fetch('api.php?action=delete_audio', { method: 'POST', body: formData });
      const data = await res.json();
      if (!data.success) {
        this.audioRecordings = originalAudios;
        this.renderAudioListTable();
        this.populateAudioDropdown();
        this.showToast(data.error || 'Failed to delete audio recording', 'error');
      }
    } catch(e) {
      this.audioRecordings = originalAudios;
      this.renderAudioListTable();
      this.populateAudioDropdown();
      this.showToast('Error deleting audio recording', 'error');
    }
  }

  /* ─────────────────────────────────────────────────────────
     Scheduled Call Time Parsers & Precise Time Engine
     ───────────────────────────────────────────────────────── */
  isLeadDueForCall(lead) {
    if (!lead || !lead.call_time) return true;
    const timeStr = String(lead.call_time).trim();
    if (timeStr === '' || timeStr.toLowerCase() === 'immediate' || timeStr.toLowerCase() === 'now') {
      return true; // Immediate calls are always ready
    }

    const scheduledTs = this.getLeadScheduledTimestamp(lead);
    if (scheduledTs <= 0) return true;

    const now = Date.now();
    // If time is in the future -> WAIT
    if (now < scheduledTs) {
      return false;
    }

    // If scheduled time has arrived within the current active window (<= 3 minutes after scheduled time) -> DUE NOW
    const diffSec = (now - scheduledTs) / 1000;
    return diffSec <= 180;
  }

  getLeadScheduledTimestamp(lead) {
    if (!lead || !lead.call_time) return 0;
    const s = String(lead.call_time).trim();
    if (!s || s.toLowerCase() === 'immediate' || s.toLowerCase() === 'now') {
      return 0; // Immediate priority
    }

    // 1. Relative offsets (e.g. "+5 mins", "+15 min", "+1 hour")
    const relMatch = s.match(/^\+(\d+)\s*(?:min|mins|minute|minutes)$/i);
    if (relMatch) {
      const mins = parseInt(relMatch[1], 10);
      const created = lead.created_at ? new Date(lead.created_at).getTime() : Date.now();
      return (isNaN(created) ? Date.now() : created) + mins * 60 * 1000;
    }
    const relHourMatch = s.match(/^\+(\d+)\s*(?:hr|hrs|hour|hours)$/i);
    if (relHourMatch) {
      const hrs = parseInt(relHourMatch[1], 10);
      const created = lead.created_at ? new Date(lead.created_at).getTime() : Date.now();
      return (isNaN(created) ? Date.now() : created) + hrs * 3600 * 1000;
    }

    const now = new Date();
    let baseYear = now.getFullYear();
    let baseMonth = now.getMonth();
    let baseDate = now.getDate();

    // Check if Tomorrow
    if (/^tomorrow/i.test(s)) {
      const tomorrow = new Date(now.getTime() + 24 * 60 * 60 * 1000);
      baseYear = tomorrow.getFullYear();
      baseMonth = tomorrow.getMonth();
      baseDate = tomorrow.getDate();
    } else {
      // Check for date formats like "Sep 14, 2026", "2026-09-14", "Sep 14"
      const dateMatch = s.match(/^([A-Za-z]{3,9})\s+(\d{1,2})(?:,?\s*(\d{4}))?/i);
      if (dateMatch) {
        const monthNames = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
        const mIdx = monthNames.indexOf(dateMatch[1].toLowerCase().slice(0, 3));
        if (mIdx !== -1) {
          baseMonth = mIdx;
          baseDate = parseInt(dateMatch[2], 10);
          if (dateMatch[3]) baseYear = parseInt(dateMatch[3], 10);
        }
      } else {
        const isoMatch = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (isoMatch) {
          baseYear = parseInt(isoMatch[1], 10);
          baseMonth = parseInt(isoMatch[2], 10) - 1;
          baseDate = parseInt(isoMatch[3], 10);
        }
      }
    }

    // 2. Standard 12-hour format: "Today, 01:50 PM", "01:50 PM", "1:50 PM", "12:50 PM", "07:50 AM"
    const match12 = s.match(/(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM)/i);
    if (match12) {
      let hours = parseInt(match12[1], 10);
      const minutes = parseInt(match12[2], 10);
      const seconds = match12[3] ? parseInt(match12[3], 10) : 0;
      const ampm = match12[4].toUpperCase();

      if (ampm === 'PM' && hours < 12) {
        hours += 12;
      } else if (ampm === 'AM' && hours === 12) {
        hours = 0;
      }

      const target = new Date(baseYear, baseMonth, baseDate, hours, minutes, seconds, 0);
      return target.getTime();
    }

    // 3. 24-hour time or time without AM/PM: "13:50", "13:50:00", "01:50"
    const matchTime = s.match(/(\d{1,2}):(\d{2})(?::(\d{2}))?/);
    if (matchTime) {
      let hours = parseInt(matchTime[1], 10);
      const minutes = parseInt(matchTime[2], 10);
      const seconds = matchTime[3] ? parseInt(matchTime[3], 10) : 0;

      // If hours is 1..6 during daytime afternoon (>= 12), infer PM
      if (hours >= 1 && hours <= 6 && now.getHours() >= 12) {
        hours += 12;
      }

      const target = new Date(baseYear, baseMonth, baseDate, hours, minutes, seconds, 0);
      return target.getTime();
    }

    // 4. Fallback Date.parse
    try {
      const cleanStr = s.replace(/^Today,\s*/i, '').replace(/^Tomorrow,\s*/i, '');
      const parsedDate = new Date(cleanStr);
      if (!isNaN(parsedDate.getTime())) {
        return parsedDate.getTime();
      }
    } catch (e) {}

    return 0;
  }

  startSchedulerLoop() {
    this.stopSchedulerLoop();
    this.schedulerInterval = setInterval(() => {
      if (this.isRunning && !this.isPaused) {
        const isL1Free = this.line1.isRegistered && !this.line1.activeChannelId && (!this.line1.session || this.line1.session.state === 'Terminated');
        const isL2Free = this.line2.enabled && this.line2.isRegistered && !this.line2.activeChannelId && (!this.line2.session || this.line2.session.state === 'Terminated');
        if (isL1Free || isL2Free) {
          this.processOutboundQueue();
        }
      }
    }, 1000); // Check every 1 second for scheduled call arrivals
  }

  stopSchedulerLoop() {
    if (this.schedulerInterval) {
      clearInterval(this.schedulerInterval);
      this.schedulerInterval = null;
    }
  }

  /* ─────────────────────────────────────────────────────────
     Automated Outbound Campaign Calling Engine (Dual-Line Ready)
     ───────────────────────────────────────────────────────── */
  async startCampaign() {
    if (this.isRunning && !this.isPaused) return;

    // Refresh queue data from database first
    await this.loadCampaignData();

    const pendingLeads = this.getTodayLeads().filter(l => l.status === 'pending');
    if (pendingLeads.length === 0) {
      this.showToast('No pending leads in today\'s list! Import an Excel file, add numbers, or roll over remaining calls from History.', 'warning');
      return;
    }

    // Ensure at least Line 1 is registered
    const l1Registered = this.line1.isRegistered;
    const l2Registered = this.line2.enabled && this.line2.isRegistered;

    if (!l1Registered && !l2Registered) {
      this.showToast('SIP Softphone is not registered to PBX! Please click Connect in Settings to register Extension.', 'error');
      openSipSettingsModal();
      return;
    }

    this.isRunning = true;
    this.isPaused = false;
    this.btnStart.disabled = true;
    this.btnPause.disabled = false;
    this.btnStop.disabled = false;

    this.renderCampaignHeader();
    const lineMode = (this.line2.enabled && l2Registered) ? 'Dual-Line (2 Concurrent Numbers)' : 'Single Line';
    this.showToast(`Auto-Dialer active [${lineMode}]: Monitoring queue...`, 'success');
    this.startSchedulerLoop();
    this.processOutboundQueue();
  }

  togglePauseCampaign() {
    if (!this.isRunning) return;
    this.isPaused = !this.isPaused;
    this.btnPause.textContent = this.isPaused ? 'Resume' : 'Pause';
    this.btnPause.className = this.isPaused ? 'btn btn-success' : 'btn btn-warning';
    this.renderCampaignHeader();
    this.showToast(this.isPaused ? 'Auto-Dialer Paused' : 'Auto-Dialer Resumed', 'info');

    if (!this.isPaused) {
      this.startSchedulerLoop();
      this.processOutboundQueue();
    } else {
      this.stopSchedulerLoop();
    }
  }

  stopCampaign() {
    this.isRunning = false;
    this.isPaused = false;
    this.stopSchedulerLoop();
    if (this._displayCountdownTimer) {
      clearInterval(this._displayCountdownTimer);
      this._displayCountdownTimer = null;
    }
    this.nextScheduledLeadWaiting = null;
    this.btnStart.disabled = false;
    this.btnPause.disabled = true;
    this.btnPause.textContent = 'Pause';
    this.btnPause.className = 'btn btn-warning';
    this.btnStop.disabled = true;

    // Terminate any in-flight SIP or IVR audio on both lines
    this.sipHangup(1);
    this.sipHangup(2);
    if (this.activeAudioPlayer) {
      try { this.activeAudioPlayer.pause(); } catch(e) {}
      this.activeAudioPlayer = null;
    }

    this.activeChannels.forEach(ch => {
      if (ch.timerInterval) clearInterval(ch.timerInterval);
    });
    this.activeChannels.clear();
    this.line1.activeChannelId = null;
    this.line2.activeChannelId = null;

    this.renderActiveChannels();
    this.renderCampaignHeader();
    this.showToast('Auto-Dialer Stopped', 'info');
  }

  async processOutboundQueue() {
    if (!this.isRunning || this.isPaused || this._isProcessingQueue) return;
    this._isProcessingQueue = true;

    try {
      const maxConcurrent = parseInt(document.getElementById('maxConcurrentInput')?.value || 2, 10);
      const pacingDelay = parseInt(document.getElementById('pacingDelayInput')?.value || 2, 10);

      // Collect currently active lead IDs and normalized phone numbers across both channels
      const activeLeadIds = new Set();
      const activePhoneNumbers = new Set();
      this.activeChannels.forEach(ch => {
        if (ch.lead?.id) activeLeadIds.add(Number(ch.lead.id));
        if (ch.lead?.phone_number) {
          activePhoneNumbers.add(window.skykinNormalizeEtDial(ch.lead.phone_number));
        }
      });

      // Filter today's pending leads (excluding any lead ID or phone number already in progress)
      const pendingLeads = this.getTodayLeads().filter(l => {
        if (l.status !== 'pending') return false;
        if (activeLeadIds.has(Number(l.id))) return false;
        const normPhone = window.skykinNormalizeEtDial(l.phone_number);
        if (activePhoneNumbers.has(normPhone)) return false;
        return true;
      });

      if (pendingLeads.length === 0 && this.activeChannels.size === 0) {
        this.stopCampaign();
        this.showToast('All pending customer leads in queue have been processed!', 'success');
        await this.loadCampaignData();
        return;
      }

      const now = Date.now();

      // 1. Immediate leads (no scheduled time)
      const immediateLeads = pendingLeads.filter(l => {
        const s = String(l.call_time || '').trim().toLowerCase();
        return !s || s === 'immediate' || s === 'now';
      });

      // 2. Scheduled leads whose time has arrived (within 60 seconds)
      const dueScheduledLeads = pendingLeads.filter(l => {
        const s = String(l.call_time || '').trim().toLowerCase();
        if (!s || s === 'immediate' || s === 'now') return false;
        const ts = this.getLeadScheduledTimestamp(l);
        if (ts <= 0) return false;
        return (now >= ts) && ((now - ts) <= 60000);
      });

      const dueLeads = [...immediateLeads, ...dueScheduledLeads];
      dueLeads.sort((a, b) => {
        const aTime = this.getLeadScheduledTimestamp(a);
        const bTime = this.getLeadScheduledTimestamp(b);
        return aTime - bTime;
      });

      // Check which lines are free and actually registered
      const isLine1Free = this.line1.isRegistered && !this.line1.activeChannelId && (!this.line1.session || this.line1.session.state === 'Terminated');
      const isLine2Free = maxConcurrent >= 2 && this.line2.enabled && this.line2.isRegistered && !this.line2.activeChannelId && (!this.line2.session || this.line2.session.state === 'Terminated');

      const availableDue = [...dueLeads];

      // Dispatch Line 1 if free
      if (isLine1Free && availableDue.length > 0 && this.activeChannels.size < maxConcurrent) {
        const lead1 = availableDue.shift();
        lead1.status = 'calling';
        activeLeadIds.add(Number(lead1.id));
        activePhoneNumbers.add(window.skykinNormalizeEtDial(lead1.phone_number));
        this.renderLeadsTables();
        this.updateLeadStatusBackend(lead1.id, 'calling', 0);
        this.dialCustomerLead(lead1, 1, pacingDelay);
      }

      // Dispatch Line 2 simultaneously if free and another distinct number is due
      if (isLine2Free && availableDue.length > 0 && this.activeChannels.size < maxConcurrent) {
        const l2Idx = availableDue.findIndex(l => {
          const norm = window.skykinNormalizeEtDial(l.phone_number);
          return !activeLeadIds.has(Number(l.id)) && !activePhoneNumbers.has(norm);
        });
        if (l2Idx !== -1) {
          const lead2 = availableDue.splice(l2Idx, 1)[0];
          lead2.status = 'calling';
          activeLeadIds.add(Number(lead2.id));
          activePhoneNumbers.add(window.skykinNormalizeEtDial(lead2.phone_number));
          this.renderLeadsTables();
          this.updateLeadStatusBackend(lead2.id, 'calling', 0);
          this.dialCustomerLead(lead2, 2, pacingDelay);
        }
      }

      if (this.activeChannels.size === 0) {
        this.renderActiveChannels();
      }
    } finally {
      this._isProcessingQueue = false;
    }
  }

  getNextLeadInQueue() {
    const now = Date.now();
    const pending = this.leads.filter(l => l.status === 'pending');
    if (pending.length === 0) return null;

    // Separate immediate, upcoming future, and past-expired leads
    const immediateLeads = pending.filter(l => {
      const s = String(l.call_time || '').trim().toLowerCase();
      return !s || s === 'immediate' || s === 'now';
    });

    // Future leads: scheduled time is still in the future
    const futureLeads = pending.filter(l => {
      const s = String(l.call_time || '').trim().toLowerCase();
      if (!s || s === 'immediate' || s === 'now') return false;
      const ts = this.getLeadScheduledTimestamp(l);
      return ts > now;
    });

    // Due right now leads: scheduled time arrived within last 60 seconds
    const dueNowLeads = pending.filter(l => {
      const s = String(l.call_time || '').trim().toLowerCase();
      if (!s || s === 'immediate' || s === 'now') return false;
      const ts = this.getLeadScheduledTimestamp(l);
      return ts > 0 && now >= ts && (now - ts) <= 60000;
    });

    // Priority: Immediate > Due Now > Soonest Future
    if (immediateLeads.length > 0) return immediateLeads[0];
    if (dueNowLeads.length > 0) return dueNowLeads[0];

    if (futureLeads.length > 0) {
      futureLeads.sort((a, b) => this.getLeadScheduledTimestamp(a) - this.getLeadScheduledTimestamp(b));
      return futureLeads[0];
    }

    return null;
  }

  async dialCustomerLead(lead, lineNum = 1, pacingDelay = 2) {
    const channelId = `ch_l${lineNum}_${lead.id}_${Date.now()}`;
    const audioSrc = lead.ivr_audio_file || this.campaign?.ivr_audio_file || 'assets/audio/welcome_human_voice.wav';
    const dom = localStorage.getItem('sip_domain') || this.sipDomain || 'client1.skykin.local';

    const lineObj = lineNum === 2 ? this.line2 : this.line1;
    lineObj.activeChannelId = channelId;

    const channelData = {
      channelId: channelId,
      lineNum: lineNum,
      lineExt: lineObj.ext,
      lead: lead,
      status: 'dialing',
      duration: 0,
      startTime: Date.now(),
      timerInterval: null
    };
    this.activeChannels.set(channelId, channelData);

    this.renderActiveChannels();
    this.renderLeadsTables();

    let isFinished = false;
    const finishCall = async (finalStatus, durationSec, errorMsg = '') => {
      if (isFinished) return;
      isFinished = true;

      if (channelData.timerInterval) clearInterval(channelData.timerInterval);
      this.activeChannels.delete(channelId);
      lineObj.activeChannelId = null;
      lead.status = finalStatus;
      lead.duration_sec = durationSec;
      if (errorMsg) lead.error_message = errorMsg;
      this.renderActiveChannels();
      this.renderLeadsTables();

      // If call completed or answered, deduplicate any duplicate pending entries with identical phone number
      if (finalStatus === 'completed' || finalStatus === 'ivr_completed' || finalStatus === 'answered') {
        const currentNormPhone = window.skykinNormalizeEtDial(lead.phone_number);
        this.leads.forEach(otherLead => {
          if (Number(otherLead.id) !== Number(lead.id) && otherLead.status === 'pending') {
            if (window.skykinNormalizeEtDial(otherLead.phone_number) === currentNormPhone) {
              otherLead.status = 'completed';
              otherLead.duration_sec = durationSec;
              this.updateLeadStatusBackend(otherLead.id, 'completed', durationSec, 'Deduplicated (Already Answered)');
            }
          }
        });
      }

      await this.updateLeadStatusBackend(lead.id, finalStatus, durationSec, errorMsg);
      await this.loadCampaignData();

      // Proceed to the next customer in the queue for this line
      if (this.isRunning && !this.isPaused) {
        const delay = Math.max(1, pacingDelay);
        setTimeout(() => {
          if (this.isRunning && !this.isPaused) {
            this.processOutboundQueue();
          }
        }, delay * 1000);
      }
    };

    if (!lineObj.isRegistered && !lineObj.ua) {
      this.showToast(`Cannot dial ${lead.phone_number}: Line ${lineNum} (${lineObj.ext}) is not registered!`, 'error');
      finishCall('failed', 0, `Line ${lineNum} Not Registered`);
      return;
    }

    let callSec = 0;
    this.sipMakeCall(lead.phone_number, dom, {
      onEstablishing: () => {
        channelData.status = 'ringing';
        lead.status = 'calling';
        this.renderActiveChannels();
        this.renderLeadsTables();
      },
      onEstablished: async (pc) => {
        channelData.status = 'answered';
        lead.status = 'answered';
        this.renderActiveChannels();
        this.renderLeadsTables();
        this.updateLeadStatusBackend(lead.id, 'answered', 1);

        channelData.timerInterval = setInterval(() => {
          callSec++;
          channelData.duration = callSec;
          lead.duration_sec = callSec;
          this.renderActiveChannels();
        }, 1000);

        // Stream IVR audio directly into customer's phone call
        await this.playIvrAudioIntoPeerConnection(audioSrc, pc, () => {
          setTimeout(() => {
            this.sipHangup(lineNum);
            finishCall('completed', callSec || 1);
          }, 500);
        }, lineNum);
      },
      onTerminated: () => {
        finishCall(callSec > 0 ? 'completed' : 'no_answer', callSec);
      },
      onError: (err) => {
        finishCall('failed', 0, err?.message || 'Call Failed');
      },
      onReject: (resp) => {
        finishCall('no_answer', 0, resp?.reasonPhrase || 'Declined / Busy');
      }
    }, lineNum);
  }

  renderActiveChannels() {
    if (!this.channelsContainer) return;

    if (this._displayCountdownTimer) {
      clearInterval(this._displayCountdownTimer);
      this._displayCountdownTimer = null;
    }

    this.channelsContainer.innerHTML = '';

    // 1. In-Call State (Active calling lines with live duration)
    if (this.activeChannels.size > 0) {
      this.activeChannels.forEach((ch, id) => {
        const mins = Math.floor(ch.duration / 60);
        const secs = ch.duration % 60;
        const durStr = `${String(mins).padStart(2,'0')}:${String(secs).padStart(2,'0')}`;
        const campDisplay = ch.lead.campaign_name || this.campaign?.name || 'Outbound';
        const audioNameDisplay = ch.lead.ivr_audio_name || this.campaign?.ivr_audio_name || 'Human Greeting';
        const card = document.createElement('div');
        card.className = `channel-card ${ch.status === 'answered' ? 'connected' : 'ringing'}`;
        card.innerHTML = `
          <div class="ch-header">
            <span class="ch-badge ${ch.status === 'answered' ? 'active' : 'dialing'}">
              Line ${ch.lineNum} (Ext ${ch.lineExt}) &bull; ${ch.status === 'answered' ? 'IVR Playing: ' + this.escapeHtml(campDisplay) : 'Dialing...'}
            </span>
            <span style="font-family:monospace;font-weight:700;color:#0c3629;">${durStr}</span>
          </div>
          <div style="font-weight:700;font-size:14px;color:#1e293b;margin-bottom:2px;">
            ${this.escapeHtml(ch.lead.customer_name || 'Customer')}
          </div>
          <div style="font-size:12px;color:#64748b;margin-bottom:6px;">
            ${this.escapeHtml(ch.lead.phone_number)} &bull; <span style="color:#00A651;font-weight:700;">${this.escapeHtml(campDisplay)}</span>
          </div>
          <div style="font-size:11px;color:#0c3629;background:#eaf8f0;border:1px solid #bce8ce;padding:4px 8px;border-radius:4px;display:flex;justify-content:space-between;align-items:center;">
            <span>${this.escapeHtml(audioNameDisplay)}</span>
            <button class="btn btn-secondary btn-sm" onclick="app.sipHangup(${ch.lineNum})" style="padding:1px 6px;font-size:10px;height:auto;line-height:1.2;">End Call</button>
          </div>
        `;
        this.channelsContainer.appendChild(card);
      });
      return;
    }

    // Helper: build countdown text from a timestamp
    const makeCountdown = (targetTs) => {
      const diff = Math.max(0, Math.round((targetTs - Date.now()) / 1000));
      if (diff <= 0) return 'Calling now...';
      const h = Math.floor(diff / 3600);
      const m = Math.floor((diff % 3600) / 60);
      const s = diff % 60;
      if (h > 0) return `${h}h ${m}m ${s}s`;
      if (m > 0) return `${m}m ${s}s`;
      return `${s}s`;
    };

    // Get the next lead (future-prioritized)
    const next = this.getNextLeadInQueue();
    const targetTs = next ? this.getLeadScheduledTimestamp(next) : 0;
    const isFuture = targetTs > Date.now();

    // 2. Auto-Dialer Running
    if (this.isRunning) {
      if (!next) {
        this.channelsContainer.innerHTML = `
          <div style="grid-column:1/-1;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:16px;text-align:center;">
            <div style="font-weight:700;color:#64748b;font-size:13px;margin-bottom:2px;">Auto-Dialer Active &bull; No Pending Leads</div>
            <div style="font-size:12px;color:#94a3b8;">All contacts have been dialed. Add numbers or click "Reset Leads" to dial again.</div>
          </div>
        `;
        return;
      }

      // Render the waiting card with a live countdown
      const countdownId = `cd_${Date.now()}`;
      this.channelsContainer.innerHTML = `
        <div style="grid-column:1/-1;background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:18px 20px;text-align:center;">
          <div style="font-weight:700;color:#15803d;font-size:14px;margin-bottom:6px;display:flex;align-items:center;justify-content:center;gap:7px;">
            <span style="width:9px;height:9px;border-radius:50%;background:#22c55e;display:inline-block;animation:pulse 1.2s ease-in-out infinite;"></span>
            Auto-Dialer Active &bull; Next Scheduled Call
          </div>
          <div style="font-size:14px;color:#1e293b;font-weight:600;margin-bottom:4px;">
            ${this.escapeHtml(next.customer_name || 'Customer')}
            <span style="color:#64748b;font-weight:400;"> &mdash; </span>
            <a href="javascript:void(0)" onclick="app.clickToDial('${this.escapeHtml(next.phone_number)}')" style="color:#00A651;font-weight:700;text-decoration:none;">${this.escapeHtml(next.phone_number)}</a>
          </div>
          <div style="font-size:12.5px;color:#166534;margin-bottom:8px;">
            Scheduled: <strong>${this.escapeHtml(next.call_time || 'Immediate')}</strong>
          </div>
          <div id="${countdownId}" style="display:inline-block;background:#dcfce7;color:#15803d;font-weight:800;font-size:20px;padding:6px 20px;border-radius:20px;font-family:monospace;letter-spacing:1px;">
            ${isFuture ? makeCountdown(targetTs) : 'Dialing soon...'}
          </div>
        </div>
      `;

      if (isFuture) {
        this._displayCountdownTimer = setInterval(() => {
          const el = document.getElementById(countdownId);
          if (!el) { clearInterval(this._displayCountdownTimer); return; }
          el.textContent = makeCountdown(targetTs);
        }, 1000);
      }
      return;
    }

    // 3. Auto-Dialer is Idle
    if (!next) {
      this.channelsContainer.innerHTML = `
        <div style="grid-column:1/-1;background:#f8fafc;border:1px dashed #e2e8f0;border-radius:8px;padding:14px;text-align:center;color:#94a3b8;font-size:12px;">
          No active calls. Add numbers to the queue or click <strong>Start Auto-Dialer</strong>.
        </div>
      `;
      return;
    }

    const countdownIdIdle = `cd_idle_${Date.now()}`;
    this.channelsContainer.innerHTML = `
      <div style="grid-column:1/-1;background:#f8fafc;border:1.5px dashed #bce8ce;border-radius:10px;padding:16px 18px;text-align:center;">
        <div style="font-weight:700;color:#00A651;font-size:13px;margin-bottom:4px;">
          Next Scheduled Call
        </div>
        <div style="font-size:14px;color:#1e293b;font-weight:600;margin-bottom:3px;">
          ${this.escapeHtml(next.customer_name || 'Customer')}
          <span style="color:#64748b;font-weight:400;"> &mdash; </span>
          ${this.escapeHtml(next.phone_number)}
        </div>
        <div style="font-size:12px;color:#475569;margin-bottom:8px;">
          Scheduled: <strong>${this.escapeHtml(next.call_time || 'Immediate')}</strong>
        </div>
        ${isFuture ? `<div id="${countdownIdIdle}" style="display:inline-block;background:#dbeafe;color:#1d4ed8;font-weight:700;font-size:16px;padding:4px 16px;border-radius:16px;font-family:monospace;">${makeCountdown(targetTs)}</div>` : ''}
        <div style="font-size:11px;color:#94a3b8;margin-top:8px;">Click <strong style="color:#15803d;">Start Auto-Dialer</strong> to enable automatic calling at scheduled time.</div>
      </div>
    `;

    if (isFuture) {
      this._displayCountdownTimer = setInterval(() => {
        const el = document.getElementById(countdownIdIdle);
        if (!el) { clearInterval(this._displayCountdownTimer); return; }
        el.textContent = makeCountdown(targetTs);
      }, 1000);
    }
  }

  async updateLeadStatusBackend(leadId, status, duration) {
    try {
      const formData = new FormData();
      formData.append('lead_id', leadId);
      formData.append('status', status);
      formData.append('duration_sec', duration);
      formData.append('audio_played', this.campaign?.ivr_audio_name || 'Human Welcome Audio');

      await fetch('api.php?action=update_lead_status', { method: 'POST', body: formData });
    } catch (e) {
      console.error(e);
    }
  }

  async resetLeads() {
    this.leads.forEach(l => {
      l.status = 'pending';
      l.duration_sec = 0;
      l.error_message = '';
    });
    this.renderLeadsTables();
    this.renderUnansweredTable();
    this.updateLocalStats();
    this.renderActiveChannels();
    this.showToast('All leads reset to pending.', 'success');

    try {
      const formData = new FormData();
      formData.append('campaign_id', this.campaign?.id || 1);
      await fetch('api.php?action=reset_leads', { method: 'POST', body: formData });
    } catch (e) {
      this.showToast('Failed to sync reset with database', 'error');
    }
  }

  async deleteLead(leadId) {
    // Ultra-fast optimistic UI removal (0ms latency)
    const originalLeads = [...this.leads];
    this.leads = this.leads.filter(l => Number(l.id) !== Number(leadId));
    this.renderLeadsTables();
    this.renderUnansweredTable();
    this.updateLocalStats();
    this.renderActiveChannels();
    this.showToast('Lead removed', 'info');

    try {
      const formData = new FormData();
      formData.append('lead_id', leadId);
      formData.append('campaign_id', this.campaign?.id || 1);
      await fetch('api.php?action=delete_lead', { method: 'POST', body: formData });
    } catch (e) {
      // Revert if network failed
      this.leads = originalLeads;
      this.renderLeadsTables();
      this.renderUnansweredTable();
      this.updateLocalStats();
      this.renderActiveChannels();
      this.showToast('Could not delete lead from server', 'error');
    }
  }

  /* ─────────────────────────────────────────────────────────
     Quick Add Single Contact & Scheduler with Date and Time
     ───────────────────────────────────────────────────────── */
  handleTimePresetChange(preset) {
    const customDate = document.getElementById('quickAddCustomDate');
    const customTime = document.getElementById('quickAddCustomTime');
    if (preset === 'custom') {
      if (customDate) {
        customDate.style.display = 'inline-block';
        if (!customDate.value) {
          customDate.value = new Date().toISOString().split('T')[0];
        }
      }
      if (customTime) {
        customTime.style.display = 'inline-block';
        if (!customTime.value) {
          const d = new Date(Date.now() + 15 * 60 * 1000);
          customTime.value = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
        }
      }
    } else {
      if (customDate) customDate.style.display = 'none';
      if (customTime) customTime.style.display = 'none';
    }
  }

  handleModalPresetChange(preset) {
    const wrap = document.getElementById('modalCustomDateTimeWrap');
    const customDate = document.getElementById('modalAddCustomDate');
    const customTime = document.getElementById('modalAddCustomTime');
    if (preset === 'custom') {
      if (wrap) wrap.style.display = 'flex';
      if (customDate && !customDate.value) {
        customDate.value = new Date().toISOString().split('T')[0];
      }
      if (customTime && !customTime.value) {
        const d = new Date(Date.now() + 15 * 60 * 1000);
        customTime.value = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
      }
    } else {
      if (wrap) wrap.style.display = 'none';
    }
  }

  getCalculatedCallTime(presetValue, dateVal = '', timeVal = '') {
    if (presetValue === 'immediate' || !presetValue) return 'Immediate';
    if (presetValue === 'custom') {
      let d = dateVal ? dateVal.trim() : '';
      let t = timeVal ? timeVal.trim() : '';
      if (!t && !d) return 'Immediate';
      if (!t) t = '12:00 PM';

      if (/^\d{1,2}:\d{2}$/.test(t)) {
        const [hh, mm] = t.split(':');
        const rawH = parseInt(hh, 10);
        let ampm = rawH >= 12 ? 'PM' : 'AM';
        let formattedH = (rawH % 12) || 12;
        if (rawH === 12) {
          ampm = 'PM';
          formattedH = 12;
        }
        t = `${String(formattedH).padStart(2, '0')}:${mm} ${ampm}`;
      }

      if (d) {
        try {
          const dtObj = new Date(d + 'T' + (timeVal || '12:00'));
          const today = new Date();
          if (dtObj.toDateString() === today.toDateString()) {
            return `Today, ${t}`;
          }
          const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
          return `${monthNames[dtObj.getMonth()]} ${dtObj.getDate()}, ${t}`;
        } catch(e) {
          return `${d} ${t}`;
        }
      }
      return `Today, ${t}`;
    }
    const minsMatch = presetValue.match(/\+(\d+)min/);
    if (minsMatch) {
      const mins = parseInt(minsMatch[1], 10);
      const targetDate = new Date(Date.now() + mins * 60 * 1000);
      let h = targetDate.getHours();
      const m = String(targetDate.getMinutes()).padStart(2, '0');
      const ampm = h >= 12 ? 'PM' : 'AM';
      h = (h % 12) || 12;
      return `Today, ${String(h).padStart(2, '0')}:${m} ${ampm}`;
    }
    return 'Immediate';
  }

  async handleQuickAddSubmit(e) {
    if (e) e.preventDefault();
    const nameInp = document.getElementById('quickAddName');
    const phoneInp = document.getElementById('quickAddPhone');
    const presetSelect = document.getElementById('quickAddTimePreset');
    const customDate = document.getElementById('quickAddCustomDate')?.value;
    const customTime = document.getElementById('quickAddCustomTime')?.value;

    const name = nameInp?.value.trim() || 'Customer';
    const phone = phoneInp?.value.trim() || '';
    const callTime = this.getCalculatedCallTime(presetSelect?.value, customDate, customTime);

    if (!phone) {
      this.showToast('Please enter a phone number', 'error');
      return null;
    }

    try {
      const formData = new FormData();
      formData.append('campaign_id', this.campaign?.id || 1);
      formData.append('customer_name', name);
      formData.append('phone_number', phone);
      formData.append('call_time', callTime);
      formData.append('notes', 'Quick Add');

      const res = await fetch('api.php?action=add_single_lead', { method: 'POST', body: formData });
      const data = await res.json();
      if (data.success) {
        this.showToast(data.message || `Contact ${name} added!`, 'success');
        if (nameInp) nameInp.value = '';
        if (phoneInp) phoneInp.value = '';
        if (presetSelect) presetSelect.value = 'immediate';
        document.getElementById('quickAddCustomDate')?.style.setProperty('display', 'none');
        document.getElementById('quickAddCustomTime')?.style.setProperty('display', 'none');

        if (data.lead) {
          this.leads.push(data.lead);
          this.renderLeadsTables();
          this.renderUnansweredTable();
          this.updateLocalStats();
          this.renderActiveChannels();
        } else {
          this.loadCampaignData();
        }
        return data.lead;
      } else {
        this.showToast(data.error || 'Failed to add contact', 'error');
        return null;
      }
    } catch(err) {
      this.showToast('Network error adding contact', 'error');
      return null;
    }
  }

  async quickAddAndCall() {
    const nameInp = document.getElementById('quickAddName');
    const phoneInp = document.getElementById('quickAddPhone');
    const name = nameInp?.value.trim() || 'Customer';
    const phone = phoneInp?.value.trim() || '';

    if (!phone) {
      this.showToast('Please enter a phone number to call', 'error');
      return;
    }

    const lead = await this.handleQuickAddSubmit();
    if (lead) {
      this.dialCustomerLead(lead, 1, 2);
    } else if (phone) {
      this.clickToDial(phone, name);
    }
  }

  dialSingleLeadWithIvr(leadId) {
    const lead = this.leads.find(l => Number(l.id) === Number(leadId));
    if (!lead) return;
    this.dialCustomerLead(lead, 1, 2);
  }

  async handleModalSingleAdd(e) {
    if (e) e.preventDefault();
    const nameInp = document.getElementById('modalAddName');
    const phoneInp = document.getElementById('modalAddPhone');
    const acctInp = document.getElementById('modalAddAccount');
    const debtInp = document.getElementById('modalAddDebt');
    const presetSelect = document.getElementById('modalAddTimePreset');
    const customDate = document.getElementById('modalAddCustomDate')?.value;
    const customTime = document.getElementById('modalAddCustomTime')?.value;
    const notesInp = document.getElementById('modalAddNotes');

    const name = nameInp?.value.trim() || 'Customer';
    const phone = phoneInp?.value.trim() || '';
    const account = acctInp?.value.trim() || '';
    const debt = debtInp?.value.trim() || '0.00';
    const notes = notesInp?.value.trim() || 'Quick Add';
    const callTime = this.getCalculatedCallTime(presetSelect?.value, customDate, customTime);

    if (!phone) {
      this.showToast('Please enter a phone number', 'error');
      return;
    }

    try {
      const targetCampaignId = document.getElementById('singleAddCampaignSelect')?.value || this.activeCampaignId || this.campaign?.id || 1;
      const formData = new FormData();
      formData.append('campaign_id', targetCampaignId);
      formData.append('customer_name', name);
      formData.append('phone_number', phone);
      formData.append('account_number', account);
      formData.append('debt_amount', debt);
      formData.append('call_time', callTime);
      formData.append('notes', notes);

      const res = await fetch('api.php?action=add_single_lead', { method: 'POST', body: formData });
      const data = await res.json();
      if (data.success) {
        this.showToast(data.message || `Contact ${name} added to PostgreSQL queue!`, 'success');
        if (nameInp) nameInp.value = '';
        if (phoneInp) phoneInp.value = '';
        if (acctInp) acctInp.value = '';
        if (debtInp) debtInp.value = '';
        if (notesInp) notesInp.value = '';
        if (presetSelect) presetSelect.value = 'immediate';
        const wrap = document.getElementById('modalCustomDateTimeWrap');
        if (wrap) wrap.style.display = 'none';

        this.closeModal('modalSingleAdd');
        await this.loadCampaignsOverview();
        if (String(this.campaign?.id) === String(targetCampaignId)) {
          await this.loadCampaignData(targetCampaignId);
        }
      } else {
        this.showToast(data.error || 'Failed to add contact', 'error');
      }
    } catch(err) {
      this.showToast('Network error adding contact', 'error');
    }
  }

  /* ─────────────────────────────────────────────────────────
     Audio & File Upload Handlers
     ───────────────────────────────────────────────────────── */
  async handleAudioUpload(e) {
    if (e) e.preventDefault();
    const nameInp = document.getElementById('audioNameInput');
    const fileInp = document.getElementById('audioFileInput');
    const submitBtn = document.getElementById('btnUploadAudioSubmit');

    if (!fileInp || !fileInp.files || fileInp.files.length === 0) {
      this.showToast('Please select an audio file (.wav or .mp3) to upload.', 'error');
      return;
    }

    const file = fileInp.files[0];
    const name = nameInp?.value.trim() || file.name.replace(/\.[^/.]+$/, "");

    const formData = new FormData();
    formData.append('audio_file', file);
    formData.append('audio_name', name);
    formData.append('campaign_id', this.campaign?.id || 1);

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = '⏳ Uploading Audio...';
    }

    this.showToast('Uploading voice audio recording...', 'info');

    try {
      const res = await fetch('api.php?action=upload_audio', {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      if (data.success) {
        this.showToast(data.message || `Audio "${name}" uploaded & selected as Active IVR!`, 'success');
        if (nameInp) nameInp.value = '';
        if (fileInp) fileInp.value = '';
        this.closeAllModals();
        await this.loadCampaignData();

        // Switch to Human IVR Audio tab to view the newly uploaded file
        if (typeof window.switchTab === 'function') {
          window.switchTab('tabIVRStudio');
        }
      } else {
        this.showToast(data.error || 'Failed to upload audio file', 'error');
      }
    } catch(err) {
      console.error('Audio upload error:', err);
      this.showToast('Error uploading audio file', 'error');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Upload & Set as Active IVR';
      }
    }
  }

  async handleFileUpload(e) {
    if (e) e.preventDefault();
    const fileInp = document.getElementById('leadFileInput');
    if (!fileInp || !fileInp.files || fileInp.files.length === 0) {
      this.showToast('Please select a CSV, Excel, or TXT file to upload.', 'error');
      return;
    }
    const targetCampaignId = document.getElementById('uploadCampaignSelect')?.value || this.activeCampaignId || this.campaign?.id || 1;
    const formData = new FormData();
    formData.append('lead_file', fileInp.files[0]);
    formData.append('campaign_id', targetCampaignId);

    this.showToast('Importing customer leads...', 'info');
    try {
      const res = await fetch('api.php?action=upload_leads', { method: 'POST', body: formData });
      const data = await res.json();
      if (data.success) {
        this.showToast(data.message || `Imported ${data.imported} leads!`, 'success');
        fileInp.value = '';
        const nameLabel = document.getElementById('selectedFileName');
        if (nameLabel) nameLabel.textContent = '';
        this.closeAllModals();

        await this.loadCampaignsOverview();
        if (String(this.campaign?.id) === String(targetCampaignId)) {
          if (data.leads && Array.isArray(data.leads)) {
            this.leads = data.leads;
            this.renderLeadsTables();
            this.renderUnansweredTable();
            this.updateLocalStats();
          } else {
            await this.loadCampaignData(targetCampaignId);
          }
        }
      } else {
        this.showToast(data.error || 'Failed to import file', 'error');
      }
    } catch (err) {
      this.showToast('Error uploading lead file', 'error');
    }
  }

  async handleManualUpload(e) {
    if (e) e.preventDefault();
    const textInp = document.getElementById('manualLeadsText');
    const text = textInp?.value.trim() || '';
    if (!text) {
      this.showToast('Please enter at least one contact line', 'error');
      return;
    }
    const targetCampaignId = document.getElementById('manualCampaignSelect')?.value || this.activeCampaignId || this.campaign?.id || 1;
    const formData = new FormData();
    formData.append('leads_text', text);
    formData.append('campaign_id', targetCampaignId);

    try {
      const res = await fetch('api.php?action=save_manual_leads', { method: 'POST', body: formData });
      const data = await res.json();
      if (data.success) {
        this.showToast(data.message || `Added ${data.imported} leads!`, 'success');
        if (textInp) textInp.value = '';
        this.closeAllModals();

        await this.loadCampaignsOverview();
        if (String(this.campaign?.id) === String(targetCampaignId)) {
          await this.loadCampaignData(targetCampaignId);
        }
      } else {
        this.showToast(data.error || 'Failed to add leads', 'error');
      }
    } catch (err) {
      this.showToast('Error saving leads', 'error');
    }
  }

  /* ─────────────────────────────────────────────────────────
     Manual Outbound Calling with Dialpad
     ───────────────────────────────────────────────────────── */
  clickToDial(number, name) {
    openPhonePopup();
    const dialInput = document.getElementById('dialInput');
    if (dialInput) dialInput.value = number;
    this.startManualCall(number, name);
  }

  startManualCall(rawNumber, name = 'Customer') {
    const raw = rawNumber || document.getElementById('dialInput')?.value.trim();
    if (!raw) {
      this.showToast('Please enter a phone number to call', 'error');
      return;
    }

    const number = window.skykinNormalizeEtDial(raw);

    const banner = document.getElementById('callActiveBanner');
    const callNum = document.getElementById('activeCallNumber');
    const callSub = document.getElementById('activeCallSub');
    const timer = document.getElementById('callTimer');
    const btnHangup = document.getElementById('btnHangup');
    const btnHold = document.getElementById('btnHold');
    const btnMute = document.getElementById('btnMute');
    const btnKeypad = document.getElementById('btnKeypadToggle');

    if (banner) banner.style.display = 'block';
    if (callNum) callNum.textContent = raw;
    if (callSub) callSub.textContent = `Dialing ${name} (${number})...`;
    if (timer) { timer.style.display = 'block'; timer.textContent = '00:00'; }
    if (btnHangup) btnHangup.style.display = 'block';
    if (btnHold) btnHold.style.display = 'flex';
    if (btnMute) btnMute.style.display = 'flex';
    if (btnKeypad) btnKeypad.style.display = 'flex';

    // Originate SIP outbound call
    const dom = localStorage.getItem('sip_domain') || 'client1.skykin.local';
    this.sipMakeCall(number, dom);

    // Start live timer
    let sec = 0;
    clearInterval(this.manualTimerInterval);
    this.manualTimerInterval = setInterval(() => {
      sec++;
      const m = String(Math.floor(sec / 60)).padStart(2, '0');
      const s = String(sec % 60).padStart(2, '0');
      if (timer) timer.textContent = `${m}:${s}`;
    }, 1000);
  }

  hangupManualCall() {
    this.sipHangup();

    if (this.activeAudioPlayer) {
      try { this.activeAudioPlayer.pause(); } catch(e) {}
      this.activeAudioPlayer = null;
    }

    clearInterval(this.manualTimerInterval);
    this.manualTimerInterval = null;

    const banner = document.getElementById('callActiveBanner');
    const timer = document.getElementById('callTimer');
    const btnHangup = document.getElementById('btnHangup');
    const btnHold = document.getElementById('btnHold');
    const btnMute = document.getElementById('btnMute');
    const btnKeypad = document.getElementById('btnKeypadToggle');

    if (banner) banner.style.display = 'none';
    if (timer) timer.style.display = 'none';
    if (btnHangup) btnHangup.style.display = 'none';
    if (btnHold) btnHold.style.display = 'none';
    if (btnMute) btnMute.style.display = 'none';
    if (btnKeypad) btnKeypad.style.display = 'none';

    const ext = localStorage.getItem('sip_ext') || '101';
    this.updateSipStatus('registered', `Registered (${ext})`);
    this.showToast('Call ended', 'info');
  }

  escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
}

// Global Softphone Handlers
let phonePopupOpen = false;
let lastDialedNumber = '';

function togglePhonePopup() {
  phonePopupOpen = !phonePopupOpen;
  document.getElementById('phonePopup')?.classList.toggle('open', phonePopupOpen);
  const fab = document.getElementById('phoneFab');
  if (fab) {
    fab.innerHTML = phonePopupOpen
      ? '&#x2715;<span class="fab-badge unreg" id="fabBadge"></span>'
      : '&#128222;<span class="fab-badge unreg" id="fabBadge"></span>';
  }
}

function openPhonePopup() {
  phonePopupOpen = true;
  document.getElementById('phonePopup')?.classList.add('open');
  const fab = document.getElementById('phoneFab');
  if (fab) fab.innerHTML = '&#x2715;<span class="fab-badge unreg" id="fabBadge"></span>';
}

function dpKey(digit) {
  const dial = document.getElementById('dialInput');
  if (dial) dial.value += digit;
  if (window.app) window.app.sipSendDtmf(digit);
}

function dpDelete() {
  const dial = document.getElementById('dialInput');
  if (dial) dial.value = dial.value.slice(0, -1);
}

function dpCall() {
  const num = document.getElementById('dialInput')?.value.trim();
  if (num) {
    lastDialedNumber = num;
    if (window.app) {
      window.app.startManualCall(num);
    }
  }
}

function fillRecentNumber() {
  const dial = document.getElementById('dialInput');
  if (!dial) return;
  if (lastDialedNumber) {
    dial.value = lastDialedNumber;
    return;
  }
  if (window.app && window.app.leads && window.app.leads.length > 0) {
    dial.value = window.app.leads[0].phone_number || '';
  }
}

function hangupManualCall() {
  if (window.app) window.app.hangupManualCall();
}

function toggleManualMute() {
  const btn = document.getElementById('btnMute');
  if (!window.app) return;
  window.app.isMuted = !window.app.isMuted;
  if (window.app.isMuted) window.app.sipMute();
  else window.app.sipUnmute();
  if (btn) btn.classList.toggle('active', window.app.isMuted);
}

function toggleManualHold() {
  const btn = document.getElementById('btnHold');
  if (!window.app) return;
  window.app.isHeld = !window.app.isHeld;
  if (window.app.isHeld) window.app.sipHold();
  else window.app.sipUnhold();
  if (btn) btn.classList.toggle('active', window.app.isHeld);
}

function toggleCallKeypad() {
  const dp = document.getElementById('dpPanel');
  if (dp) {
    dp.style.display = dp.style.display === 'none' ? 'block' : 'none';
  }
}

// SIP Settings Modal Save Handler
async function saveSipSettings() {
  const ext = document.getElementById('sipExt')?.value.trim() || '';
  const pass = document.getElementById('sipPass')?.value.trim() || '';
  const ext2 = document.getElementById('sipExt2')?.value.trim() || '';
  const pass2 = document.getElementById('sipPass2')?.value.trim() || '';
  const l2Enabled = document.getElementById('line2EnabledCheck')?.checked ? 1 : 0;
  const server = document.getElementById('sipServer')?.value.trim() || 'webcc.skyconnectsolutions.et';
  const port = document.getElementById('sipPort')?.value.trim() || '443';
  const dom = document.getElementById('sipDomain')?.value.trim() || 'client1.skykin.local';

  if (!ext || !pass) {
    alert('Line 1 Extension and Password are required.');
    return;
  }

  // Save to database
  try {
    const formData = new FormData();
    formData.append('sip_extension', ext);
    formData.append('sip_password', pass);
    formData.append('sip_extension2', ext2);
    formData.append('sip_password2', pass2);
    formData.append('line2_enabled', l2Enabled);
    formData.append('sip_server', server);
    formData.append('sip_port', port);
    formData.append('sip_domain', dom);
    await fetch('api.php?action=save_sip_settings', { method: 'POST', body: formData });
  } catch(e) {}

  closeSipSettingsModal();
  if (window.app) {
    window.app.showToast('SIP settings saved. Connecting lines...', 'success');
    window.app.reconnectDualSip(ext, pass, ext2, pass2, l2Enabled, server, port, dom);
  }
}

// Initialize Application
document.addEventListener('DOMContentLoaded', () => {
  window.app = new SkyKinDialerApp();

  const dialInp = document.getElementById('dialInput');
  if (dialInp) {
    dialInp.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        dpCall();
      }
    });
  }
});

window.openModal = function(id) {
  if (window.app) window.app.openModal(id);
  else document.getElementById(id)?.classList.add('open');
};
window.closeModal = function(id) {
  if (window.app) window.app.closeModal(id);
  else document.getElementById(id)?.classList.remove('open');
};

window.selectCampaignForDesigner = function(id) {
  if (window.app && window.app.selectCampaignForDesigner) {
    window.app.selectCampaignForDesigner(id);
  } else if (typeof window.switchCampaignSubView === 'function') {
    window.switchCampaignSubView('designer');
  } else {
    const listEl = document.getElementById('subViewCampaignsList');
    const designerEl = document.getElementById('subViewCampaignsDesigner');
    if (listEl) listEl.style.display = 'none';
    if (designerEl) designerEl.style.display = 'block';
  }
};


