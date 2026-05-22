/**
 * Aether v2 — Floating Command Panel
 * Drop-in vanilla JS. No build step.
 *
 * Features:
 *   • Auth-gated: launcher only appears when a valid JWT is in localStorage
 *   • Periodic re-check — disappears on logout
 *   • Image upload → gallery (with caption suggestions)
 *   • Plan approval inline
 *   • Module reports as cards
 *   • Highlight money/numbers/alerts inside replies
 */
(function () {
  'use strict';
  if (window.__AETHER_V2_PANEL__) return;
  window.__AETHER_V2_PANEL__ = true;

  const SCRIPT = document.currentScript || (function(){ const s=document.getElementsByTagName('script'); return s[s.length-1]; })();
  const BASE = (SCRIPT && SCRIPT.src) ? SCRIPT.src.replace(/[^/]+$/, '') : '/aetherV2/';
  const API  = BASE + 'api/aether.php';
  const DASH = BASE + 'dashboard.php';

  if (!document.getElementById('aev-style-link')) {
    const link = document.createElement('link');
    link.id = 'aev-style-link'; link.rel = 'stylesheet'; link.href = BASE + 'style.css';
    document.head.appendChild(link);
  }
  if (!document.querySelector('link[href*="font-awesome"]')) {
    const fa = document.createElement('link');
    fa.rel = 'stylesheet'; fa.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';
    document.head.appendChild(fa);
  }

  // ── token gate ─────────────────────────────────────────────────────────
  function getToken() {
    for (const k of ['token','authToken','auth_token','jwt','access_token','userToken']) {
      const v = localStorage.getItem(k);
      if (v && v.split('.').length === 3) return v;
    }
    return null;
  }
  let currentUser = null;

  async function call(action, body = {}) {
    const token = getToken();
    if (!token) throw new Error('not_authenticated');
    const r = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type':'application/json', 'Authorization':'Bearer '+token },
      body: JSON.stringify({ action, ...body }),
    });
    return r.json();
  }
  function esc(s){return String(s||'').replace(/[&<>]/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;'}[m]))}
  function md(t){
    return String(t||'')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
      .replace(/\*(?!\s)([^*\n]+?)\*/g,'<em>$1</em>')
      .replace(/`([^`\n]+)`/g,'<code>$1</code>')
      // highlight ₹ amounts
      .replace(/(₹\s?[\d,]+(?:\.\d+)?)/g, '<span class="amount">$1</span>')
      // alert keywords
      .replace(/\b(low stock|critical|failed|warning|alert|missing|orphan)\b/gi, '<span class="alert">$1</span>')
      .replace(/\n/g,'<br>');
  }

  // ── DOM (only injected when authenticated) ─────────────────────────────
  let launcher, panel, body, input, sendBtn, badge, attachedView, currentAttachment = null;

  function inject() {
    if (launcher) return; // already injected

    launcher = document.createElement('div');
    launcher.className = 'aev-launcher';
    launcher.setAttribute('data-testid','aether-v2-launcher');
    launcher.title = 'Open Aether';
    launcher.innerHTML = '<div class="aev-pulse"></div><div class="aev-launch-glyph"></div><div class="aev-badge" data-testid="aether-v2-badge"></div>';
    document.body.appendChild(launcher);

    panel = document.createElement('div');
    panel.className = 'aev-panel';
    panel.setAttribute('data-testid','aether-v2-panel');
    panel.innerHTML = `
      <div class="aev-resize-handle" id="aev-resize-handle" title="Drag to resize"></div>
      <div class="aev-panel-head">
        <div class="aev-mark"></div>
        <div class="aev-panel-title">
          <h3>Aether</h3>
          <div class="panel-sub" id="aev-sub">At your service, sir.</div>
        </div>
        <button class="aev-iconbtn" id="aev-voice-toggle" title="Auto-read replies aloud" data-testid="aether-v2-voice"><i class="fa-solid fa-volume-xmark"></i></button>
        <button class="aev-iconbtn" id="aev-fullscreen" title="Open standalone Aether" data-testid="aether-v2-fullscreen"><i class="fa-solid fa-expand"></i></button>
        <button class="aev-iconbtn" id="aev-dash" title="Open Command Centre" data-testid="aether-v2-open-dash"><i class="fa-solid fa-up-right-from-square"></i></button>
        <button class="aev-iconbtn" id="aev-clear" title="Clear chat" data-testid="aether-v2-clear"><i class="fa-solid fa-trash-can"></i></button>
        <button class="aev-iconbtn" id="aev-close" title="Close" data-testid="aether-v2-close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="aev-tabs">
        <button class="aev-tab active" data-pane="chat" data-testid="aether-v2-tab-chat"><i class="fa-solid fa-comments"></i>Chat</button>
        <button class="aev-tab" data-pane="tasks" data-testid="aether-v2-tab-tasks"><i class="fa-solid fa-list-check"></i>Tasks</button>
        <button class="aev-tab" data-pane="reports" data-testid="aether-v2-tab-reports"><i class="fa-solid fa-chart-pie"></i>Reports</button>
        <button class="aev-tab" data-pane="health" data-testid="aether-v2-tab-health"><i class="fa-solid fa-heart-pulse"></i>Health</button>
        <button class="aev-tab" data-pane="plans" data-testid="aether-v2-tab-plans"><i class="fa-solid fa-clipboard-check"></i>Plans</button>
        <button class="aev-tab" data-pane="import" data-testid="aether-v2-tab-import"><i class="fa-solid fa-file-csv"></i>Import</button>
      </div>
      <div class="aev-panes">
        <div class="aev-pane active" data-pane="chat">
          <div class="aev-body" id="aev-body" data-testid="aether-v2-body"></div>
        </div>
        <div class="aev-pane" data-pane="tasks" id="aev-tasks-pane"><div class="aev-empty">Loading…</div></div>
        <div class="aev-pane" data-pane="reports" id="aev-reports-pane"><div class="aev-empty">Loading…</div></div>
        <div class="aev-pane" data-pane="health" id="aev-health-pane"><div class="aev-empty">Loading…</div></div>
        <div class="aev-pane" data-pane="plans"  id="aev-plans-pane"><div class="aev-empty">Loading…</div></div>
        <div class="aev-pane" data-pane="import" id="aev-import-pane"><div class="aev-empty">Loading…</div></div>
      </div>
      <div class="aev-quick" id="aev-quick">
        <button data-q="my tasks" data-testid="aether-v2-chip-tasks"><i class="fa-solid fa-list-check"></i>My tasks</button>
        <button data-q="report on donations this quarter" data-testid="aether-v2-chip-report"><i class="fa-solid fa-chart-pie"></i>Report</button>
        <button data-q="donation reminders" data-testid="aether-v2-chip-reminders"><i class="fa-solid fa-bell"></i>Reminders</button>
        <button data-q="impact report" data-testid="aether-v2-chip-impact"><i class="fa-solid fa-award"></i>Impact</button>
        <button data-q="import csv for donors" data-testid="aether-v2-chip-csv"><i class="fa-solid fa-file-csv"></i>Import CSV</button>
        <button data-q="health status" data-testid="aether-v2-chip-health"><i class="fa-solid fa-heart-pulse"></i>Health</button>
      </div>
      <div class="aev-attached" id="aev-attached">
        <img id="aev-attached-img" alt="">
        <span class="name" id="aev-attached-name"></span>
        <button id="aev-attached-clear" title="Remove"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="aev-input">
        <button class="attach" id="aev-attach" title="Attach image or CSV" data-testid="aether-v2-attach"><i class="fa-solid fa-paperclip"></i></button>
        <input type="file" id="aev-file" accept="image/*,.csv,text/csv" style="display:none">
        <input type="text" id="aev-input" data-testid="aether-v2-input" placeholder="Ask Aether to do anything…" autocomplete="off"/>
        <button class="attach" id="aev-mic" title="Voice input" data-testid="aether-v2-mic"><i class="fa-solid fa-microphone"></i></button>
        <button class="send" id="aev-send" data-testid="aether-v2-send" title="Send"><i class="fa-solid fa-paper-plane"></i></button>
      </div>
    `;
    document.body.appendChild(panel);

    body    = panel.querySelector('#aev-body');
    input   = panel.querySelector('#aev-input');
    sendBtn = panel.querySelector('#aev-send');
    badge   = launcher.querySelector('.aev-badge');
    attachedView = panel.querySelector('#aev-attached');

    // ── chat ─────────────────────────────────────────────────────────
    panel.querySelectorAll('.aev-tab').forEach(t => {
      t.addEventListener('click', () => {
        panel.querySelectorAll('.aev-tab').forEach(x=>x.classList.remove('active'));
        panel.querySelectorAll('.aev-pane').forEach(x=>x.classList.remove('active'));
        t.classList.add('active');
        const pane = t.dataset.pane;
        panel.querySelector(`.aev-pane[data-pane="${pane}"]`).classList.add('active');
        if (pane==='tasks')   loadTasks();
        if (pane==='health')  loadHealth();
        if (pane==='plans')   loadPlans();
        if (pane==='reports') loadReports();
        if (pane==='import')  loadImport();
      });
    });

    panel.querySelector('#aev-close').addEventListener('click', closePanel);
    panel.querySelector('#aev-dash').addEventListener('click', () => window.location.href = DASH);
    panel.querySelector('#aev-fullscreen').addEventListener('click', () => window.open(BASE + 'chat.php', '_blank'));
    panel.querySelector('#aev-clear').addEventListener('click', async () => {
      await call('clear_history');
      body.innerHTML='';
      bubble('bot', 'Chat cleared.', { feedback:false });
    });
    sendBtn.addEventListener('click', () => send(input.value));
    input.addEventListener('keydown', e => { if (e.key==='Enter' && !e.shiftKey) { e.preventDefault(); send(input.value); }});
    panel.querySelectorAll('#aev-quick button').forEach(b => {
      b.addEventListener('click', () => { input.value = b.dataset.q; send(b.dataset.q); });
    });

    // ── Voice toggle (auto-speak replies) ──
    const voiceBtn = panel.querySelector('#aev-voice-toggle');
    let voiceOn = localStorage.getItem('aether_voice') === '1';
    updateVoiceUI();
    voiceBtn.addEventListener('click', () => {
      voiceOn = !voiceOn;
      localStorage.setItem('aether_voice', voiceOn ? '1' : '0');
      updateVoiceUI();
      if (!voiceOn) window.speechSynthesis?.cancel();
    });
    function updateVoiceUI() {
      voiceBtn.innerHTML = voiceOn
        ? '<i class="fa-solid fa-volume-high" style="color:var(--aev-primary)"></i>'
        : '<i class="fa-solid fa-volume-xmark"></i>';
      voiceBtn.title = voiceOn ? 'Click to silence' : 'Auto-read replies aloud';
    }
    // Expose so other functions can read it
    panel.__voiceOn = () => voiceOn;

    // ── Mic (Web Speech API STT) ──
    const micBtn = panel.querySelector('#aev-mic');
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) { micBtn.style.display = 'none'; }
    else {
      const recog = new SR();
      recog.continuous = false;
      recog.interimResults = true;
      recog.lang = 'en-IN';
      let recording = false;
      recog.onresult = e => {
        let txt = '';
        for (let i = e.resultIndex; i < e.results.length; i++) txt += e.results[i][0].transcript;
        input.value = txt;
      };
      recog.onend = () => { recording = false; micBtn.classList.remove('recording'); };
      recog.onerror = () => { recording = false; micBtn.classList.remove('recording'); };
      micBtn.addEventListener('click', () => {
        if (recording) { recog.stop(); return; }
        recording = true; micBtn.classList.add('recording');
        try { recog.start(); } catch (e) { recording = false; micBtn.classList.remove('recording'); }
      });
    }

    // ── Resize handle (top-left corner) ──
    const resizeHandle = panel.querySelector('#aev-resize-handle');
    let resizing = false, startX = 0, startY = 0, startW = 0, startH = 0;
    // Load saved size
    const savedW = parseInt(localStorage.getItem('aether_panel_w') || '0', 10);
    const savedH = parseInt(localStorage.getItem('aether_panel_h') || '0', 10);
    if (savedW > 320 && savedH > 320) {
      panel.style.width = savedW + 'px';
      panel.style.height = savedH + 'px';
    }
    resizeHandle.addEventListener('mousedown', e => {
      resizing = true;
      startX = e.clientX; startY = e.clientY;
      const rect = panel.getBoundingClientRect();
      startW = rect.width; startH = rect.height;
      document.body.style.userSelect = 'none';
      e.preventDefault();
    });
    document.addEventListener('mousemove', e => {
      if (!resizing) return;
      // Top-left grows the panel up and left
      const dx = startX - e.clientX;
      const dy = startY - e.clientY;
      const newW = Math.max(360, Math.min(window.innerWidth - 40, startW + dx));
      const newH = Math.max(420, Math.min(window.innerHeight - 40, startH + dy));
      panel.style.width = newW + 'px';
      panel.style.height = newH + 'px';
    });
    document.addEventListener('mouseup', () => {
      if (!resizing) return;
      resizing = false;
      document.body.style.userSelect = '';
      const rect = panel.getBoundingClientRect();
      localStorage.setItem('aether_panel_w', String(Math.round(rect.width)));
      localStorage.setItem('aether_panel_h', String(Math.round(rect.height)));
    });

    // attach handler
    const fileInput = panel.querySelector('#aev-file');
    panel.querySelector('#aev-attach').addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => attachFile(fileInput.files[0]));
    panel.querySelector('#aev-attached-clear').addEventListener('click', clearAttachment);

    launcher.addEventListener('click', openPanel);
  }

  function uninject() {
    if (launcher) { launcher.remove(); launcher = null; }
    if (panel)    { panel.remove(); panel = null; }
  }

  function openPanel(){
    panel.classList.add('open');
    launcher.classList.add('open');
    if (!body.children.length) {
      const role = currentUser?.role || 'user';
      const name = currentUser?.full_name || currentUser?.username || 'there';
      // Use last name with honorific for butler tone
      const parts = (name || '').trim().split(/\s+/);
      const honoured = parts.length >= 2 ? 'Mr. ' + parts[parts.length-1] : name;
      bubble('bot',
        md(`At your service, **${honoured}**. ` +
           `I am **Aether**, your butler-in-residence — fully on-premise rule engine paired with Claude Sonnet for the moments that require deeper thought.\n\n` +
           `I shall be glad to manage donations, expenses, statutory compliance, blog drafts, inventory, payroll, donor outreach — whatever the estate requires of me. Each action is proposed as a plan for your approval, sir.\n\n` +
           `Try a chip below or simply speak your mind. _Position: \`${role}\`._`),
        { feedback:false });
    }
    setTimeout(()=>input.focus(), 300);
  }
  function closePanel(){
    panel.classList.remove('open');
    launcher.classList.remove('open');
  }

  // ── Text-to-speech: read butler replies aloud ──
  function speakButler(text){
    if (!window.speechSynthesis) return;
    window.speechSynthesis.cancel();
    const clean = String(text||'')
      .replace(/```[\s\S]+?```/g, '')
      .replace(/\*\*([^*]+)\*\*/g, '$1')
      .replace(/\*([^*]+)\*/g, '$1')
      .replace(/`([^`]+)`/g, '$1')
      .replace(/[#>_~]/g, '')
      .replace(/<[^>]+>/g, '')
      .replace(/\s+/g, ' ')
      .slice(0, 1500); // safety cap for very long replies
    if (!clean) return;
    const voices = window.speechSynthesis.getVoices();
    const pick =
      voices.find(v => /en-GB/i.test(v.lang) && /Daniel|Oliver|Arthur/i.test(v.name)) ||
      voices.find(v => /en-GB/i.test(v.lang)) ||
      voices.find(v => /en-IN/i.test(v.lang)) ||
      voices.find(v => /en/i.test(v.lang));
    const utt = new SpeechSynthesisUtterance(clean);
    if (pick) utt.voice = pick;
    utt.rate = 1.02; utt.pitch = 0.95; utt.volume = 1;
    window.speechSynthesis.speak(utt);
  }


  function attachFile(file){
    if (!file) return;
    const reader = new FileReader();
    const isCsv = /\.csv$/i.test(file.name) || /csv/i.test(file.type||'');
    reader.onload = () => {
      const dataUri = reader.result;
      const base64 = dataUri.split(',')[1];
      currentAttachment = {
        filename: file.name, mime: file.type, data: base64, preview: dataUri,
        kind: isCsv ? 'csv' : 'image',
      };
      if (isCsv) {
        // CSV: hide image preview, show a CSV chip
        attachedView.querySelector('#aev-attached-img').src = '';
        attachedView.querySelector('#aev-attached-img').style.display = 'none';
        attachedView.querySelector('#aev-attached-name').innerHTML =
          '<i class="fa-solid fa-file-csv" style="color:#10b981;margin-right:6px"></i>' +
          esc(file.name) + ' <span style="opacity:.7">(' + Math.round(file.size/1024) + ' KB)</span>';
        attachedView.classList.add('show');
        input.placeholder = 'Type the module: donors / donations / expenses / employees / volunteers / inventory / programs';
      } else {
        attachedView.querySelector('#aev-attached-img').src = dataUri;
        attachedView.querySelector('#aev-attached-img').style.display = '';
        attachedView.querySelector('#aev-attached-name').textContent = file.name + ' (' + Math.round(file.size/1024) + ' KB)';
        attachedView.classList.add('show');
        input.placeholder = 'Add a caption / title (or just press send)…';
      }
      input.focus();
    };
    reader.readAsDataURL(file);
  }
  function clearAttachment(){
    currentAttachment = null;
    attachedView.classList.remove('show');
    input.placeholder = 'Ask Aether to do anything…';
    panel.querySelector('#aev-file').value = '';
  }

  function bubble(role, html, opts={}){
    const div = document.createElement('div');
    div.className = 'aev-msg '+role;
    div.innerHTML = html;
    if (opts.cards) {
      const cards = document.createElement('div');
      cards.className = 'aev-cardlets';
      cards.innerHTML = opts.cards.map(c =>
        `<div class="aev-cardlet"><div class="l">${esc(c.label)}</div><div class="v">${esc(c.value)}</div></div>`
      ).join('');
      div.appendChild(cards);
    }
    if (opts.plan) {
      const p = opts.plan;
      const planEl = document.createElement('div');
      planEl.className = 'aev-plan';
      planEl.innerHTML = `
        <div class="aev-plan-h"><i class="fa-solid fa-bolt"></i> Action plan #${p.id} · needs your approval</div>
        <div class="aev-plan-text">${md(p.preview||'')}</div>
        <div class="aev-plan-actions">
          <button class="approve">✓ Approve & execute</button>
          <button class="reject">✗ Reject</button>
        </div>`;
      div.appendChild(planEl);
      planEl.querySelector('.approve').addEventListener('click', () => approvePlan(p.id, planEl));
      planEl.querySelector('.reject').addEventListener('click', () => rejectPlan(p.id, planEl));
    }
    if (role === 'bot' && opts.feedback !== false) {
      const fb = document.createElement('div');
      fb.className = 'aev-feedback';
      fb.innerHTML = '<button title="Helpful" data-fb="1"><i class="fa-solid fa-thumbs-up"></i></button>'
                   + '<button title="Not helpful" class="bad" data-fb="-1"><i class="fa-solid fa-thumbs-down"></i></button>';
      fb.querySelectorAll('button').forEach(b => {
        b.addEventListener('click', async () => {
          await call('feedback',{score:Number(b.dataset.fb)});
          fb.innerHTML = `<span style="font-size:11px;color:var(--aev-muted);font-style:italic">Thanks — Aether will learn from this.</span>`;
        });
      });
      div.appendChild(fb);
    }
    body.appendChild(div);
    body.scrollTop = body.scrollHeight;
    return div;
  }
  function showTyping(){
    const t = document.createElement('div');
    t.className = 'aev-msg bot';
    t.innerHTML = '<div class="aev-typing"><span></span><span></span><span></span></div>';
    body.appendChild(t);
    body.scrollTop = body.scrollHeight;
    return t;
  }

  async function send(text){
    text = (text||'').trim();
    if (!text && !currentAttachment) return;

    // ── Attachment flow: upload to gallery (image) OR CSV import (csv) ───
    if (currentAttachment) {
      const att = currentAttachment;

      // CSV import branch
      if (att.kind === 'csv') {
        const moduleName = (text || '').trim().toLowerCase().replace(/[^a-z]/g,'');
        const validModules = ['donors','donations','expenses','employees','volunteers','inventory','programs'];
        if (!validModules.includes(moduleName)) {
          bubble('user', md(text || '(csv attached)'));
          input.value = '';
          bubble('bot', md(`Please type one of these module names with the CSV: \`${validModules.join('` · `')}\``));
          return;
        }
        bubble('user', md(`📎 import CSV → \`${moduleName}\``));
        input.value=''; clearAttachment();
        sendBtn.disabled = true;
        const t = showTyping();
        try {
          const prev = await call('csv_import_preview', { module: moduleName, data: att.data, filename: att.filename });
          t.remove();
          if (!prev.ok) { bubble('bot', `<em style="color:var(--aev-bad)">${esc(prev.error || 'Preview failed')}</em>`); return; }
          renderCsvPreview(prev);
        } catch (e) {
          t.remove();
          bubble('bot', '<em style="color:var(--aev-bad)">CSV preview failed.</em>');
        } finally { sendBtn.disabled = false; input.focus(); }
        return;
      }

      // Image branch (default)
      const userMsg = text || `(uploaded ${att.filename})`;
      bubble('user', md(userMsg) + `<div style="margin-top:6px"><img src="${att.preview}" style="max-width:200px;max-height:140px;border-radius:8px;border:1px solid rgba(255,255,255,.3)"></div>`);
      input.value=''; clearAttachment();
      sendBtn.disabled = true;
      const t = showTyping();
      try {
        const up = await call('upload_image', { filename: att.filename, data: att.data, title: text || att.filename });
        const sug = await call('suggest_caption', { filename: att.filename });
        t.remove();
        bubble('bot', md(`✓ Uploaded **${att.filename}** to gallery (id #${up.id}, ${Math.round((up.size||0)/1024)} KB).`));
        bubble('bot', md(sug.reply || 'Image saved. No caption suggestions available.'));
      } catch (e) {
        t.remove();
        bubble('bot', '<em style="color:var(--aev-bad)">Upload failed — your role may not have gallery permission.</em>');
      } finally {
        sendBtn.disabled = false;
        input.focus();
      }
      return;
    }

    bubble('user', md(text));
    input.value=''; sendBtn.disabled = true;

    // ── Streaming chat (SSE) — gracefully degrades to non-streaming on error ──
    const bot = document.createElement('div');
    bot.className = 'aev-msg bot';
    bot.innerHTML = '<div class="aev-typing"><span></span><span></span><span></span></div>';
    body.appendChild(bot);
    body.scrollTop = body.scrollHeight;

    let collected = '';
    let llmMeta = null;
    try {
      const url = API;
      const resp = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + getToken() },
        body: JSON.stringify({ action: 'chat_stream', message: text, conversation_id: 'panel' }),
      });
      if (!resp.ok || !resp.body) throw new Error('stream not available');
      const reader = resp.body.getReader();
      const decoder = new TextDecoder('utf-8');
      let buf = '';
      let started = false;
      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        buf += decoder.decode(value, { stream: true });
        let idx;
        while ((idx = buf.indexOf('\n\n')) >= 0) {
          const frame = buf.slice(0, idx);
          buf = buf.slice(idx + 2);
          const lines = frame.split('\n');
          let event = 'message', data = '';
          for (const ln of lines) {
            if (ln.startsWith('event: ')) event = ln.slice(7).trim();
            else if (ln.startsWith('data: ')) data += ln.slice(6);
          }
          if (!data) continue;
          let payload = {};
          try { payload = JSON.parse(data); } catch (e) {}
          if (event === 'status') {
            // Replace typing dots with a soft "thinking…" hint once we hear status
            bot.innerHTML = `<span style="opacity:.7;font-style:italic">Pondering, sir…</span>`;
          } else if (event === 'token') {
            if (!started) { bot.innerHTML = ''; started = true; }
            collected += payload.t || '';
            bot.innerHTML = md(collected);
            body.scrollTop = body.scrollHeight;
          } else if (event === 'done') {
            llmMeta = payload;
            if (payload.source === 'rules') {
              // Plan card etc — re-render with plan support
              renderBotMessage(bot, collected || payload.reply || '', { cards: payload.cards, plan: payload.plan });
            }
          }
        }
      }
      if (!started && !collected) throw new Error('empty stream');
      // Persist assistant reply server-side for next-turn memory
      if (collected && llmMeta && llmMeta.source !== 'rules') {
        call('chat_persist_assistant', { conversation_id: 'panel', text: collected, meta: llmMeta }).catch(()=>{});
      }
      if (llmMeta && llmMeta.plan) refreshBadge();
      // Auto-speak if voice toggle on
      if (collected && panel.__voiceOn && panel.__voiceOn()) {
        speakButler(collected);
      }
    } catch (e) {
      // Fallback to non-streaming
      try {
        const r = await call('chat', { message: text, conversation_id: 'panel' });
        if (r.error) bot.innerHTML = `<em style="color:var(--aev-bad)">${esc(r.error)}</em>`;
        else { renderBotMessage(bot, r.reply||'', { cards: r.cards, plan: r.plan }); if (r.plan) refreshBadge(); }
      } catch (err) {
        bot.innerHTML = '<em style="color:var(--aev-bad)">Network or auth error. Are you logged in?</em>';
      }
    } finally {
      sendBtn.disabled = false;
      input.focus();
    }
  }

  // Helper — render plan-aware bot message after streaming completes
  function renderBotMessage(el, text, opts){
    el.innerHTML = md(text);
    if (opts && opts.plan) {
      const p = opts.plan;
      el.innerHTML += `
        <div class="aev-plan" data-testid="aether-v2-plan-card">
          <div class="aev-plan-h"><i class="fa-solid fa-bolt"></i> Plan #${p.id} · ${esc(p.intent || '')}</div>
          <div class="aev-plan-text">${md(p.preview || '')}</div>
          <div class="aev-plan-actions">
            <button class="approve" data-testid="aether-v2-approve">✓ Approve & execute</button>
            <button class="reject" data-testid="aether-v2-reject">✗ Reject</button>
          </div>
        </div>`;
      el.querySelector('.approve').addEventListener('click', () => approvePlan(p.id, el.querySelector('.aev-plan')));
      el.querySelector('.reject').addEventListener('click', () => rejectPlan(p.id, el.querySelector('.aev-plan')));
    }
    body.scrollTop = body.scrollHeight;
  }

  async function approvePlan(id, el){
    const r = await call('approve_plan', { plan_id: id });
    if (r.ok) {
      let msg = `<br><strong style="color:var(--aev-primary-2)">✓ Executed successfully.</strong>`;
      if (r.result && r.result.receipt) {
        const rc = r.result.receipt;
        msg += `<br><span style="color:var(--aev-muted);font-size:11.5px">Receipt: email ${rc.email?'✓':'✗'} · sms ${rc.sms?'✓':'✗'}` +
               (rc.reasons && rc.reasons.length ? ` <br>📝 ${rc.reasons.map(esc).join(', ')}` : '') + '</span>';
      }
      el.querySelector('.aev-plan-text').innerHTML += msg;
    } else {
      el.querySelector('.aev-plan-text').innerHTML += `<br><strong style="color:var(--aev-bad)">✗ ${esc(r.error||'Failed')}</strong>`;
    }
    el.querySelector('.aev-plan-actions').remove();
    refreshBadge();
  }
  async function rejectPlan(id, el){
    await call('reject_plan',{ plan_id:id });
    el.querySelector('.aev-plan-text').innerHTML += `<br><em>Plan rejected.</em>`;
    el.querySelector('.aev-plan-actions').remove();
    refreshBadge();
  }

  // ── Tabs: Tasks, Health, Plans, Reports ──────────────────────────
  async function loadTasks(){
    const pane = panel.querySelector('#aev-tasks-pane');
    pane.innerHTML = '<div class="aev-empty"><span class="aev-loader"></span></div>';
    try {
      const r = await call('my_tasks');
      let html = `<div style="font-size:13px;line-height:1.6">${md(r.text||'')}</div>`;
      if (r.cards && r.cards.length) {
        html = `<div class="aev-cardlets" style="grid-template-columns:repeat(2,1fr);margin-bottom:12px">` +
               r.cards.map(c => `<div class="aev-cardlet"><div class="l">${esc(c.label)}</div><div class="v">${esc(c.value)}</div></div>`).join('') +
               '</div>' + html;
      }
      // Plans assigned TO me by super_admin
      try {
        const a = await call('assigned_to_me');
        if (a.assignments && a.assignments.length) {
          html += `<div style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(0,0,0,.06)">
            <div style="font-size:12px;font-weight:600;color:var(--aev-primary-2);margin-bottom:8px">
              <i class="fa-solid fa-user-tag"></i> Assigned to you (${a.assignments.length})</div>` +
            a.assignments.map(asg => `
              <div class="aev-plan" data-plan="${asg.plan_id}">
                <div class="aev-plan-h"><i class="fa-solid fa-bolt"></i> Plan #${asg.plan_id} · ${esc(asg.intent)}
                  <span style="font-size:10.5px;font-weight:500;opacity:.7;margin-left:6px">from ${esc(asg.assigner_name||'admin')}</span></div>
                <div class="aev-plan-text">${md(asg.preview||'')}${asg.note?'<br><em style="color:var(--aev-muted);font-size:11.5px">📝 '+esc(asg.note)+'</em>':''}</div>
                <div class="aev-plan-actions">
                  <button class="approve">✓ Approve & execute</button>
                  <button class="reject">✗ Reject</button>
                </div>
              </div>`).join('') + `</div>`;
        }
      } catch(e){}
      pane.innerHTML = html;
      // Wire approve/reject for assigned plans
      pane.querySelectorAll('.aev-plan[data-plan]').forEach(el => {
        const pid = parseInt(el.getAttribute('data-plan'),10);
        el.querySelector('.approve')?.addEventListener('click', () => approvePlan(pid, el));
        el.querySelector('.reject')?.addEventListener('click', () => rejectPlan(pid, el));
      });
    } catch(e){ pane.innerHTML='<div class="aev-empty">Failed to load tasks.</div>'; }
  }

  function renderCsvPreview(prev){
    const div = document.createElement('div');
    div.className = 'aev-msg bot';
    const okN = prev.rows_ok || 0, badN = prev.rows_failed || 0, totN = prev.rows_total || 0;
    let html = `<div><strong>CSV preview — <code>${esc(prev.module)}</code></strong></div>
      <div class="aev-cardlets" style="margin-top:10px;grid-template-columns:repeat(3,1fr)">
        <div class="aev-cardlet"><div class="l">Total</div><div class="v">${totN}</div></div>
        <div class="aev-cardlet"><div class="l">Valid</div><div class="v" style="color:#10b981">${okN}</div></div>
        <div class="aev-cardlet"><div class="l">Errors</div><div class="v" style="color:${badN?'#ef4444':'inherit'}">${badN}</div></div>
      </div>`;
    if (prev.unknown_columns?.length) {
      html += `<div style="margin-top:8px;font-size:11.5px;color:var(--aev-muted)">⚠ Unknown columns ignored: <code>${prev.unknown_columns.map(esc).join(', ')}</code></div>`;
    }
    if (prev.missing_required?.length) {
      html += `<div style="margin-top:6px;font-size:11.5px;color:#ef4444">✗ Missing required columns: <code>${prev.missing_required.map(esc).join(', ')}</code></div>`;
    }
    if (prev.sample_failed?.length) {
      html += `<div style="margin-top:8px;font-size:11.5px;color:var(--aev-muted)"><strong>First few errors:</strong><ul style="margin:4px 0 0 18px;padding:0">` +
        prev.sample_failed.slice(0,3).map(s => `<li>Row ${s.row}: ${esc((s.errors||[]).join('; '))}</li>`).join('') + '</ul></div>';
    }
    if (okN > 0) {
      html += `<div class="aev-plan-actions" style="margin-top:12px">
        <button class="approve" data-import="${prev.import_id}">✓ Import ${okN} valid row(s)</button>
        <button class="reject">✗ Cancel</button>
      </div>`;
    } else {
      html += `<div style="margin-top:12px;font-size:11.5px;color:#ef4444">No valid rows to import. Fix your CSV and try again.</div>`;
    }
    div.innerHTML = html;
    body.appendChild(div);
    body.scrollTop = body.scrollHeight;
    div.querySelector('.approve')?.addEventListener('click', async () => {
      const btn = div.querySelector('.approve');
      btn.disabled = true; btn.textContent = 'Importing…';
      try {
        const r = await call('csv_import_execute', { import_id: prev.import_id });
        if (r.ok) {
          div.querySelector('.aev-plan-actions').innerHTML =
            `<span style="color:#10b981;font-size:12px"><i class="fa-solid fa-check"></i> Imported ${r.inserted} row(s) into <code>${esc(prev.module)}</code>` +
            (r.errors && r.errors.length ? ` · ${r.errors.length} row error(s)` : '') + '</span>';
        } else {
          div.querySelector('.aev-plan-actions').innerHTML =
            `<span style="color:#ef4444;font-size:12px"><i class="fa-solid fa-xmark"></i> ${esc(r.error||'Failed')}</span>`;
        }
      } catch(e) {
        div.querySelector('.aev-plan-actions').innerHTML = '<span style="color:#ef4444;font-size:12px">Network error.</span>';
      }
    });
    div.querySelector('.reject')?.addEventListener('click', () => {
      div.querySelector('.aev-plan-actions').innerHTML = '<em style="font-size:11.5px;color:var(--aev-muted)">Cancelled. No data imported.</em>';
    });
  }

  async function loadHealth(){
    const pane = panel.querySelector('#aev-health-pane');
    pane.innerHTML = '<div class="aev-empty">Running checks…</div>';
    try {
      const r = await call('health');
      pane.innerHTML = '';
      const head = document.createElement('div');
      const cls = r.overall||'ok';
      head.className = 'aev-status-card '+cls;
      head.innerHTML = `<div class="t">${cls==='ok'?'✓ All systems nominal':cls==='warn'?'! Warnings detected':'✗ Failures detected'}</div>
        <div class="d">${r.issue_count||0} open issue(s) · ${(r.checks||[]).length} checks</div>`;
      pane.appendChild(head);
      (r.checks||[]).forEach(c => {
        const d = document.createElement('div');
        d.className = 'aev-status-card '+c.status;
        d.innerHTML = `<div class="t">${esc(c.title)}</div><div class="d">${esc(c.detail||'')}</div>`;
        pane.appendChild(d);
      });
    } catch(e){ pane.innerHTML='<div class="aev-empty">Failed to load health.</div>'; }
  }

  async function loadPlans(){
    const pane = panel.querySelector('#aev-plans-pane');
    pane.innerHTML = '<div class="aev-empty">Loading plans…</div>';
    try {
      const r = await call('list_plans', { status:'proposed' });
      const plans = r.plans || [];
      if (!plans.length) { pane.innerHTML='<div class="aev-empty">No pending plans.<br><br>Plans appear when you ask Aether to <strong>record</strong>, <strong>log</strong>, <strong>send</strong>, <strong>update</strong>, or <strong>create</strong> something.</div>'; return; }
      pane.innerHTML = '';
      plans.forEach(p => {
        const el = document.createElement('div');
        el.className = 'aev-plan';
        el.innerHTML = `
          <div class="aev-plan-h"><i class="fa-solid fa-bolt"></i> Plan #${p.id} · ${esc(p.intent)}</div>
          <div class="aev-plan-text">${md(p.preview||'')}</div>
          <div class="aev-plan-actions">
            <button class="approve">✓ Approve</button>
            <button class="reject">✗ Reject</button>
          </div>`;
        el.querySelector('.approve').addEventListener('click', () => approvePlan(p.id, el));
        el.querySelector('.reject').addEventListener('click', () => rejectPlan(p.id, el));
        pane.appendChild(el);
      });
    } catch(e){ pane.innerHTML='<div class="aev-empty">Failed to load plans.</div>'; }
  }

  async function loadImport(){
    const pane = panel.querySelector('#aev-import-pane');
    pane.innerHTML = '<div class="aev-empty"><span class="aev-loader"></span></div>';
    let info;
    try { info = await call('rbac_info'); } catch (e) { info = { csv_modules: [] }; }
    const allModules = [
      {key:'donors',     icon:'address-book',    label:'Donors'},
      {key:'donations',  icon:'hand-holding-heart', label:'Donations'},
      {key:'expenses',   icon:'wallet',          label:'Expenses'},
      {key:'employees',  icon:'user-tie',        label:'Employees'},
      {key:'volunteers', icon:'people-group',    label:'Volunteers'},
      {key:'inventory',  icon:'box',             label:'Inventory'},
      {key:'programs',   icon:'diagram-project', label:'Programs'},
    ];
    const allowed = info.csv_modules || [];
    if (!allowed.length) {
      pane.innerHTML = '<div class="aev-empty">Your role does not allow bulk imports. Ask a super-admin if you need to upload data.</div>';
      return;
    }

    let html = `<div style="font-size:11.5px;color:var(--aev-muted);margin-bottom:14px;line-height:1.55">
      <i class="fa-solid fa-circle-info" style="color:var(--aev-primary-2)"></i>
      <strong>Bulk import:</strong> download a sample CSV, fill it in, then upload. Aether validates rows and shows a preview before inserting anything.
      <span style="display:block;margin-top:6px;font-style:italic">Allowed for your role (${esc(info.role||'')}): <code>${allowed.map(esc).join(', ')}</code></span>
    </div>`;
    html += '<div style="display:grid;grid-template-columns:1fr;gap:8px">';
    for (const m of allModules) {
      const can = allowed.includes(m.key);
      html += `<div class="aev-import-row${can?'':' disabled'}" data-mod="${esc(m.key)}" style="padding:11px 13px;border:1px solid var(--aev-line);border-radius:9px;background:#fff;display:flex;align-items:center;gap:10px;${can?'':'opacity:.45;pointer-events:none'}">
        <i class="fa-solid fa-${m.icon}" style="color:var(--aev-primary-2);font-size:14px;width:20px;text-align:center"></i>
        <div style="flex:1">
          <div style="font-size:13px;font-weight:600">${esc(m.label)}</div>
          <div style="font-size:10.5px;color:var(--aev-muted)">${can ? 'Click "Sample" to download template, then "Upload" to import' : 'Not allowed for your role'}</div>
        </div>
        <button class="aev-btn aev-tpl-btn" data-mod="${esc(m.key)}" style="padding:5px 10px;font-size:11.5px"><i class="fa-solid fa-download"></i> Sample</button>
        <button class="aev-btn primary aev-up-btn" data-mod="${esc(m.key)}" style="padding:5px 10px;font-size:11.5px"><i class="fa-solid fa-upload"></i> Upload</button>
      </div>`;
    }
    html += '</div>';
    html += '<div id="aev-import-result" style="margin-top:14px"></div>';

    pane.innerHTML = html;
    pane.querySelectorAll('.aev-tpl-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const m = btn.dataset.mod;
        try {
          const url = `/aetherV2/api/aether.php?action=csv_template&module=${encodeURIComponent(m)}`;
          const r = await fetch(url, { headers: { 'Authorization': 'Bearer ' + getToken() }});
          const blob = await r.blob();
          const a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = `aether-${m}-sample.csv`;
          document.body.appendChild(a); a.click(); a.remove();
        } catch (e) {}
      });
    });
    pane.querySelectorAll('.aev-up-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const m = btn.dataset.mod;
        const inp = document.createElement('input');
        inp.type = 'file'; inp.accept = '.csv,text/csv'; inp.style.display = 'none';
        document.body.appendChild(inp);
        inp.addEventListener('change', () => uploadCsvForModule(inp.files?.[0], m));
        inp.click();
        setTimeout(() => inp.remove(), 200);
      });
    });
  }

  async function uploadCsvForModule(file, module){
    if (!file) return;
    const result = panel.querySelector('#aev-import-result');
    result.innerHTML = '<div class="aev-empty"><span class="aev-loader"></span></div>';
    const reader = new FileReader();
    reader.onload = async () => {
      const base64 = reader.result.split(',')[1];
      try {
        const prev = await call('csv_import_preview', { module, data: base64, filename: file.name });
        if (!prev.ok) { result.innerHTML = `<div class="aev-empty" style="color:var(--aev-bad)">${esc(prev.error || 'Preview failed')}</div>`; return; }
        renderInlineCsvPreview(prev, result, module);
      } catch (e) {
        result.innerHTML = '<div class="aev-empty" style="color:var(--aev-bad)">Upload failed.</div>';
      }
    };
    reader.readAsDataURL(file);
  }
  function renderInlineCsvPreview(prev, target, module){
    const okN = prev.rows_ok || 0, badN = prev.rows_failed || 0, totN = prev.rows_total || 0;
    let html = `<div style="padding:14px;border:1px solid var(--aev-line);border-radius:9px;background:#fff">
      <div style="font-weight:600;font-size:13px;margin-bottom:10px"><i class="fa-solid fa-eye" style="color:var(--aev-primary-2)"></i> Preview — <code>${esc(module)}</code></div>
      <div class="aev-cardlets" style="grid-template-columns:repeat(3,1fr);margin-bottom:10px">
        <div class="aev-cardlet"><div class="l">Total</div><div class="v">${totN}</div></div>
        <div class="aev-cardlet"><div class="l">Valid</div><div class="v" style="color:#10b981">${okN}</div></div>
        <div class="aev-cardlet"><div class="l">Errors</div><div class="v" style="color:${badN?'#ef4444':'inherit'}">${badN}</div></div>
      </div>`;
    if (prev.unknown_columns?.length) html += `<div style="font-size:11px;color:var(--aev-muted);margin-bottom:6px">⚠ Unknown columns ignored: <code>${prev.unknown_columns.map(esc).join(', ')}</code></div>`;
    if (prev.missing_required?.length) html += `<div style="font-size:11px;color:#ef4444;margin-bottom:6px">✗ Missing required: <code>${prev.missing_required.map(esc).join(', ')}</code></div>`;
    if (prev.sample_failed?.length) html += `<details style="font-size:11px;color:var(--aev-muted);margin-bottom:8px"><summary>First few errors</summary><ul style="margin:6px 0 0 18px">${prev.sample_failed.slice(0,5).map(s => `<li>Row ${s.row}: ${esc((s.errors||[]).join('; '))}</li>`).join('')}</ul></details>`;
    if (okN > 0) {
      html += `<div style="display:flex;gap:8px;margin-top:8px">
        <button class="aev-btn primary aev-do-import" data-imp="${prev.import_id}" style="font-size:11.5px">Import ${okN} valid row(s)</button>
        <button class="aev-btn aev-cancel-import" style="font-size:11.5px">Cancel</button>
      </div>`;
    } else {
      html += '<div style="font-size:11.5px;color:#ef4444;margin-top:8px">No valid rows.</div>';
    }
    html += '</div>';
    target.innerHTML = html;
    target.querySelector('.aev-do-import')?.addEventListener('click', async (e) => {
      e.target.disabled = true; e.target.textContent = 'Importing…';
      try {
        const r = await call('csv_import_execute', { import_id: prev.import_id });
        if (r.ok) {
          target.innerHTML = `<div style="padding:11px 13px;background:var(--aev-primary-bg);border:1px solid var(--aev-primary-border);border-radius:8px;color:var(--aev-primary-3);font-size:12.5px">✓ Imported <strong>${r.inserted}</strong> row(s) into <code>${esc(module)}</code>${r.errors?.length ? ` · ${r.errors.length} error(s)` : ''}</div>`;
        } else {
          target.innerHTML = `<div class="aev-empty" style="color:var(--aev-bad)">${esc(r.error || 'Import failed')}</div>`;
        }
      } catch (e) {
        target.innerHTML = '<div class="aev-empty" style="color:var(--aev-bad)">Network error.</div>';
      }
    });
    target.querySelector('.aev-cancel-import')?.addEventListener('click', () => { target.innerHTML = ''; });
  }

  async function loadReports(){
    const pane = panel.querySelector('#aev-reports-pane');
    const modules = [
      {key:'donations', icon:'hand-holding-heart', label:'Donations'},
      {key:'expenses',  icon:'wallet',             label:'Expenses'},
      {key:'hr',        icon:'user-tie',           label:'HR'},
      {key:'inventory', icon:'box',                label:'Inventory'},
      {key:'programs',  icon:'diagram-project',    label:'Programs'},
      {key:'volunteers',icon:'people-group',       label:'Volunteers'},
      {key:'cms',       icon:'newspaper',          label:'CMS / Blog'},
      {key:'audit',     icon:'clock-rotate-left',  label:'Audit'},
    ];
    pane.innerHTML = '<div style="color:var(--aev-muted);font-size:12px;margin-bottom:10px">Pick a module — Aether builds the report from live ERP data:</div>'
      + '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px">'
      + modules.map(m => `<button class="aev-btn" style="justify-content:flex-start;width:100%;font-size:12px" data-mod="${m.key}"><i class="fa-solid fa-${m.icon}"></i>${m.label}</button>`).join('')
      + '</div><div id="aev-report-result" style="margin-top:14px"></div>';
    pane.querySelectorAll('button[data-mod]').forEach(b => {
      b.addEventListener('click', async () => {
        const result = pane.querySelector('#aev-report-result');
        result.innerHTML = '<div class="aev-empty"><span class="aev-loader"></span></div>';
        try {
          const r = await call('module_report', { module: b.dataset.mod });
          let html = `<div style="font-size:13px;line-height:1.55">${md(r.text||'')}</div>`;
          if (r.cards && r.cards.length) {
            html = `<div class="aev-cardlets" style="grid-template-columns:repeat(2,1fr);margin-bottom:12px">` +
                   r.cards.map(c => `<div class="aev-cardlet"><div class="l">${esc(c.label)}</div><div class="v">${esc(c.value)}</div></div>`).join('') +
                   '</div>' + html;
          }
          result.innerHTML = html;
        } catch(e) { result.innerHTML = '<div class="aev-empty">Failed to load report.</div>'; }
      });
    });
  }

  async function refreshBadge(){
    try {
      const r = await call('list_plans',{ status:'proposed' });
      const n = (r.plans||[]).length;
      if (badge) {
        if (n>0){ badge.textContent = n; badge.classList.add('show'); }
        else badge.classList.remove('show');
      }
    } catch(e){}
  }

  // ── Auth gate: only inject when user is logged in ─────────────────
  async function authCheck(){
    const t = getToken();
    if (!t) {
      if (launcher) uninject();
      currentUser = null;
      return false;
    }
    if (!launcher) {
      try {
        const me = await call('identity');
        currentUser = me?.user || null;
        if (currentUser) {
          inject();
          refreshBadge();
          return true;
        }
      } catch(e) {}
    }
    return !!launcher;
  }

  // Initial mount: when running embedded in a React SPA, the JWT is written to
  // localStorage AFTER our script has parsed. Poll aggressively for the first
  // 30 seconds, then drop to a steady 30-sec heartbeat. We also hook into the
  // `storage` event (works across tabs) and `aether:auth` (same-tab signal
  // dispatched by the ERP shim in app.html).
  let mountAttempts = 0;
  function fastMountLoop(){
    authCheck().then(ok => {
      if (ok) return;
      if (mountAttempts++ < 60) {              // 60 × 500ms = 30 seconds
        setTimeout(fastMountLoop, 500);
      }
    });
  }
  fastMountLoop();
  setInterval(authCheck,    30000);
  setInterval(refreshBadge, 60000);
  // Re-check whenever localStorage changes (login/logout in other tab)
  window.addEventListener('storage', authCheck);
  // Same-tab signal: ERP's app.html dispatches this when JWT is stored
  window.addEventListener('aether:auth', authCheck);
  // Also re-check whenever the tab regains focus
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) authCheck();
  });

  window.Aether = {
    open: () => { if (panel) openPanel(); },
    close: () => { if (panel) closePanel(); },
    ask: send,
    refresh: authCheck,
  };
})();
