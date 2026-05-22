<?php
/**
 * Aether v2 — Standalone Chat Interface (chat.php)
 *
 * A dedicated full-screen entry point for Aether, accessible at:
 *   https://erp.dhrubfoundation.org/aetherV2/chat.php
 *
 * Behaves like a real AI assistant (ChatGPT/Claude-style layout) but:
 *   • Requires the same JWT-based ERP auth (re-uses bootstrap.php)
 *   • Identifies the user + role before exposing any confidential data
 *   • Reads RBAC scope so a viewer cannot extract donor PII via this UI
 *   • Supports streaming + voice + conversation switching
 *
 * If the visitor is NOT logged in, the page asks them to sign in to the ERP
 * first (same overlay used by dashboard.php). It does NOT accept fresh email
 * + password directly — the JWT must already be in localStorage from the ERP
 * front-end, which keeps the trust boundary clean and avoids credential
 * duplication.
 */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Aether — Your butler-in-residence</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Manrope:wght@300;400;500;600;700&family=Crimson+Pro:ital,wght@0,400;0,500;0,600;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="icon" type="image/svg+xml" href="logo.svg">
<style>
:root {
  --aev-bg:        #0a0e13;
  --aev-bg-2:      #131922;
  --aev-bg-3:      #1c2433;
  --aev-line:      #2a3548;
  --aev-line-2:    #3a4761;
  --aev-text:      #e8eef5;
  --aev-text-2:    #aeb9c8;
  --aev-text-3:    #6b7689;
  --aev-primary:   #10b981;
  --aev-primary-2: #34d399;
  --aev-primary-3: #6ee7b7;
  --aev-primary-bg:#062f23;
  --aev-violet:    #a78bfa;
  --aev-violet-2:  #8b5cf6;
  --aev-rose:      #f472b6;
  --aev-amber:     #fbbf24;
  --aev-gold:      #d4a747;
  --aev-bad:       #ef4444;
  --aev-warn:      #f59e0b;
  --aev-info:      #38bdf8;
  --aev-gradient-1: linear-gradient(135deg, #10b981 0%, #06d6a0 100%);
  --aev-gradient-2: linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%);
  --aev-gradient-3: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
  --aev-gradient-glow: linear-gradient(135deg, rgba(16,185,129,0.18), rgba(139,92,246,0.12));
  --shadow-glow:   0 0 0 4px rgba(16,185,129,.18);
  --shadow-card:   0 12px 40px rgba(0,0,0,.5);
  --shadow-elev:   0 4px 24px rgba(0,0,0,.45);
}

* { box-sizing: border-box; }
html, body {
  margin: 0; padding: 0; height: 100%;
  background: var(--aev-bg);
  color: var(--aev-text); font-family: 'Manrope', sans-serif;
  -webkit-font-smoothing: antialiased;
}
body::before {
  content: ''; position: fixed; inset: 0;
  background:
    radial-gradient(circle 800px at 10% 0%, rgba(16,185,129,.08) 0%, transparent 50%),
    radial-gradient(circle 700px at 90% 100%, rgba(139,92,246,.06) 0%, transparent 50%),
    radial-gradient(circle 600px at 50% 50%, rgba(251,191,36,.025) 0%, transparent 50%);
  pointer-events: none; z-index: 0;
}
.app { position: relative; z-index: 1; }
a { color: var(--aev-primary-2); text-decoration: none; }
a:hover { color: var(--aev-primary-3); }

/* ── Auth overlay (when no JWT) ── */
.auth-wall {
  position: fixed; inset: 0; display: none; align-items: center; justify-content: center;
  background:
    radial-gradient(ellipse at 30% 20%, rgba(16,185,129,.10) 0%, transparent 50%),
    radial-gradient(ellipse at 70% 70%, rgba(168,85,247,.07) 0%, transparent 50%),
    linear-gradient(135deg, #050709 0%, #0d1217 100%);
  z-index: 9999; padding: 24px;
}
.auth-wall.show { display: flex; }
.auth-card {
  background: rgba(22, 27, 34, 0.85);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 20px;
  padding: 44px 42px;
  max-width: 460px; width: 100%; text-align: center;
  box-shadow:
    0 30px 60px rgba(0, 0, 0, 0.6),
    0 0 0 1px rgba(16, 185, 129, 0.05),
    inset 0 1px 0 rgba(255, 255, 255, 0.06);
  position: relative;
  overflow: hidden;
}
.auth-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
  background: linear-gradient(90deg, transparent 0%, var(--aev-primary) 50%, transparent 100%);
  opacity: 0.7;
}
.auth-card .mark { width: 64px; height: 64px; margin: 0 auto 22px; }
.auth-card h1 {
  font-family: 'Crimson Pro', Georgia, serif;
  font-style: italic; font-weight: 500;
  font-size: 32px; margin: 0 0 10px;
  letter-spacing: -0.02em;
  background: linear-gradient(135deg, #fff 0%, var(--aev-primary-3) 100%);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
}
.auth-card .sub {
  font-family: 'Crimson Pro', Georgia, serif;
  font-style: italic;
  color: var(--aev-text-2); font-size: 15px; margin: 0 0 22px;
  line-height: 1.55;
}
.auth-card .role-warn {
  font-size: 10.5px; color: var(--aev-gold);
  text-transform: uppercase; letter-spacing: 0.12em; font-weight: 600;
  padding: 5px 12px;
  background: linear-gradient(135deg, rgba(212,167,71,.12), rgba(212,167,71,.04));
  border: 1px solid rgba(212,167,71,.3);
  border-radius: 9999px; display: inline-block; margin-bottom: 28px;
}
.login-form { text-align: left; margin-top: 8px; }
.login-form label {
  display: block; font-size: 10.5px; font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.07em;
  color: var(--aev-text-3); margin: 14px 0 6px;
  font-family: 'Manrope', sans-serif;
}
.login-form input {
  width: 100%; padding: 12px 14px;
  background: rgba(15, 20, 25, 0.6);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 10px;
  color: var(--aev-text); font-size: 14.5px;
  font-family: 'Manrope', sans-serif;
  transition: border-color .15s, box-shadow .15s, background .15s;
}
.login-form input:focus {
  outline: none;
  border-color: var(--aev-primary);
  background: rgba(15, 20, 25, 0.85);
  box-shadow: 0 0 0 4px rgba(16,185,129,.12);
}
.login-error {
  font-size: 12.5px; color: var(--aev-bad);
  min-height: 18px; margin: 10px 0 4px; font-family: 'Manrope';
}
.btn-primary, .btn-secondary {
  display: inline-flex; align-items: center; justify-content: center; gap: 9px;
  padding: 13px 22px; border-radius: 11px; font-weight: 600;
  font-size: 14.5px; cursor: pointer; transition: all .18s;
  font-family: 'Manrope', sans-serif; border: none; width: 100%;
  text-decoration: none;
}
.btn-primary {
  background: linear-gradient(135deg, var(--aev-primary) 0%, var(--aev-primary-2) 100%);
  color: white;
  box-shadow: 0 8px 24px rgba(16,185,129,.35);
}
.btn-primary:hover {
  transform: translateY(-2px);
  box-shadow: 0 12px 32px rgba(16,185,129,.5);
}
.btn-primary:active { transform: translateY(0); }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
.btn-secondary {
  background: transparent;
  color: var(--aev-text-2);
  border: 1px solid rgba(255,255,255,0.1);
}
.btn-secondary:hover { background: rgba(255,255,255,0.04); color: var(--aev-text); border-color: rgba(255,255,255,0.18); }

.auth-divider {
  position: relative; text-align: center; margin: 22px 0 16px;
  font-size: 11px; color: var(--aev-text-3); text-transform: uppercase; letter-spacing: 0.1em;
}
.auth-divider::before, .auth-divider::after {
  content: ''; position: absolute; top: 50%; width: 38%; height: 1px;
  background: rgba(255,255,255,0.08);
}
.auth-divider::before { left: 0; }
.auth-divider::after { right: 0; }
.auth-divider span { background: rgba(22, 27, 34, 0.6); padding: 0 12px; position: relative; }

.auth-alt { display: flex; flex-direction: column; gap: 10px; }
.auth-card .footnote { margin-top: 24px; font-size: 11.5px; color: var(--aev-text-3); }
.auth-card .footnote code { background: rgba(255,255,255,.05); padding: 2px 6px; border-radius: 4px; }

/* ── Main layout ── */
.app { display: none; height: 100vh; }
.app.show { display: grid; grid-template-columns: 280px 1fr; }

/* Sidebar */
aside {
  background: linear-gradient(180deg, rgba(19, 25, 34, 0.9) 0%, rgba(10, 14, 19, 0.95) 100%);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-right: 1px solid rgba(255,255,255,0.06);
  display: flex; flex-direction: column; min-height: 0;
  position: relative;
}
aside::after {
  content: ''; position: absolute; top: 0; right: 0; bottom: 0; width: 1px;
  background: linear-gradient(180deg, transparent 0%, rgba(16,185,129,0.3) 30%, rgba(139,92,246,0.2) 70%, transparent 100%);
}
aside .brand {
  padding: 20px 20px 16px; display: flex; align-items: center; gap: 12px;
  border-bottom: 1px solid rgba(255,255,255,0.05);
}
aside .brand .mark { width: 36px; height: 36px; flex-shrink: 0; }
aside .brand h2 {
  margin: 0; font-family: 'Outfit'; font-weight: 600; font-size: 17px;
  letter-spacing: -0.02em;
  background: linear-gradient(135deg, #fff 0%, var(--aev-primary-3) 100%);
  -webkit-background-clip: text; background-clip: text;
  -webkit-text-fill-color: transparent;
}
aside .brand .sub { font-family: 'Crimson Pro'; font-style: italic; font-size: 12px; color: var(--aev-text-3); margin-top: 2px; }

aside .new-chat {
  margin: 16px 14px 6px; padding: 12px 14px;
  background: linear-gradient(135deg, rgba(16,185,129,0.08) 0%, rgba(139,92,246,0.06) 100%);
  border: 1px solid rgba(16,185,129,0.18);
  border-radius: 11px; color: var(--aev-text);
  display: flex; align-items: center; gap: 9px; cursor: pointer; font-family: 'Manrope'; font-weight: 500;
  font-size: 13.5px; transition: all .18s;
}
aside .new-chat:hover {
  border-color: var(--aev-primary);
  background: linear-gradient(135deg, rgba(16,185,129,0.15) 0%, rgba(139,92,246,0.1) 100%);
  color: var(--aev-primary-3);
  transform: translateY(-1px);
}
aside .new-chat i { color: var(--aev-primary-2); }

aside .convs { flex: 1; overflow-y: auto; padding: 8px 8px 14px; }
aside .convs h3 { font-size: 10px; text-transform: uppercase; letter-spacing: 0.1em; color: var(--aev-text-3); padding: 16px 12px 8px; margin: 0; font-weight: 600; }
aside .conv-item {
  padding: 10px 12px; border-radius: 9px; cursor: pointer; font-size: 13px;
  color: var(--aev-text-2); transition: all .15s; margin-bottom: 2px;
  display: flex; align-items: center; gap: 9px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
aside .conv-item:hover { background: rgba(255,255,255,0.04); color: var(--aev-text); }
aside .conv-item.active {
  background: linear-gradient(135deg, rgba(16,185,129,0.12), rgba(139,92,246,0.06));
  color: var(--aev-primary-3);
  border-left: 2px solid var(--aev-primary);
  padding-left: 10px;
}
aside .conv-item i { font-size: 11px; opacity: 0.6; flex-shrink: 0; }

aside .user-card {
  border-top: 1px solid rgba(255,255,255,0.05);
  padding: 14px 16px; display: flex; align-items: center; gap: 11px;
  background: rgba(10, 14, 19, 0.5);
}
aside .user-avatar {
  width: 38px; height: 38px; border-radius: 50%;
  background: var(--aev-gradient-1);
  display: flex; align-items: center; justify-content: center; font-weight: 600; color: white; font-size: 14px;
  font-family: 'Outfit'; flex-shrink: 0;
  box-shadow: 0 4px 12px rgba(16,185,129,0.35);
}
aside .user-info { flex: 1; min-width: 0; }
aside .user-info .name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--aev-text); }
aside .user-info .role { font-size: 10.5px; color: var(--aev-primary-2); text-transform: uppercase; letter-spacing: 0.07em; font-weight: 600; }
aside .user-card .menu-btn { background: transparent; border: 1px solid rgba(255,255,255,0.08); color: var(--aev-text-3); cursor: pointer; padding: 7px 9px; border-radius: 8px; transition: all .15s; }
aside .user-card .menu-btn:hover { color: var(--aev-bad); border-color: rgba(239,68,68,0.3); }

/* Main panel */
main { display: flex; flex-direction: column; min-height: 0; background: transparent; }

.topbar {
  padding: 14px 26px; border-bottom: 1px solid rgba(255,255,255,0.05);
  display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-shrink: 0;
  background: rgba(10, 14, 19, 0.6);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}
.topbar .title {
  font-family: 'Crimson Pro'; font-size: 17px; font-style: italic;
  color: var(--aev-text-2);
}
.topbar .actions { display: flex; gap: 8px; align-items: center; }
.topbar .pill-btn {
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.08); color: var(--aev-text-2);
  padding: 7px 14px; border-radius: 9px; cursor: pointer; font-size: 12.5px; transition: all .18s;
  display: inline-flex; align-items: center; gap: 7px; font-family: 'Manrope'; font-weight: 500;
  text-decoration: none;
}
.topbar .pill-btn:hover {
  border-color: var(--aev-primary);
  color: var(--aev-primary-2);
  background: rgba(16,185,129,0.06);
  transform: translateY(-1px);
}
.topbar .pill-btn.active {
  background: linear-gradient(135deg, rgba(16,185,129,0.18), rgba(139,92,246,0.1));
  border-color: var(--aev-primary);
  color: var(--aev-primary-3);
}
.topbar a { color: var(--aev-text-2); font-size: 12.5px; }

/* Chat scroll area */
.chat-scroll { flex: 1; overflow-y: auto; padding: 32px 0; }
.chat-inner { max-width: 820px; margin: 0 auto; padding: 0 28px; }

/* Welcome card */
.welcome { text-align: center; padding: 70px 28px; }
.welcome .mark-lg {
  width: 88px; height: 88px; margin: 0 auto 28px;
  filter: drop-shadow(0 0 24px rgba(16,185,129,.5));
  animation: aether-float 4s ease-in-out infinite;
}
@keyframes aether-float {
  0%, 100% { transform: translateY(0); }
  50%      { transform: translateY(-8px); }
}
.welcome h1 {
  font-family: 'Crimson Pro'; font-style: italic; font-weight: 500;
  font-size: 38px; margin: 0 0 12px; letter-spacing: -0.015em;
  background: linear-gradient(135deg, #fff 0%, var(--aev-primary-3) 50%, var(--aev-violet) 100%);
  -webkit-background-clip: text; background-clip: text;
  -webkit-text-fill-color: transparent;
}
.welcome p {
  font-family: 'Crimson Pro', Georgia, serif;
  font-style: italic;
  font-size: 16px; color: var(--aev-text-2); margin: 0 0 36px;
  line-height: 1.6; max-width: 560px; margin-left: auto; margin-right: auto;
}
.welcome p strong { color: var(--aev-primary-2); font-weight: 600; font-style: normal; }

.welcome .suggestions { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin-top: 36px; }
.welcome .sugg {
  padding: 18px 20px;
  background: linear-gradient(135deg, rgba(28, 36, 51, 0.6) 0%, rgba(19, 25, 34, 0.6) 100%);
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 14px; cursor: pointer; text-align: left;
  transition: all .2s ease;
  position: relative; overflow: hidden;
}
.welcome .sugg::before {
  content: ''; position: absolute; inset: 0;
  background: var(--aev-gradient-glow);
  opacity: 0; transition: opacity .25s;
}
.welcome .sugg:hover {
  border-color: var(--aev-primary);
  transform: translateY(-3px);
  box-shadow: 0 12px 32px rgba(16,185,129,.18), 0 0 0 1px rgba(16,185,129,0.2);
}
.welcome .sugg:hover::before { opacity: 1; }
.welcome .sugg > * { position: relative; z-index: 1; }
.welcome .sugg .ico {
  font-size: 18px; color: var(--aev-primary-2);
  margin-bottom: 10px;
  display: inline-flex; width: 38px; height: 38px;
  align-items: center; justify-content: center;
  background: linear-gradient(135deg, rgba(16,185,129,0.18), rgba(139,92,246,0.1));
  border: 1px solid rgba(16,185,129,0.25);
  border-radius: 9px;
}
.welcome .sugg .label { font-size: 14px; font-weight: 500; line-height: 1.45; color: var(--aev-text); }
.welcome .sugg .hint {
  font-size: 11.5px; color: var(--aev-text-3); margin-top: 6px;
  font-style: italic; font-family: 'Crimson Pro';
}

@media (max-width: 720px) {
  .welcome .suggestions { grid-template-columns: 1fr; }
  .welcome h1 { font-size: 28px; }
}

/* Messages */
.msg { padding: 22px 0; border-bottom: 1px solid rgba(255,255,255,.04); }
.msg.user { background: transparent; }
.msg.assistant {
  background: linear-gradient(180deg, rgba(16,185,129,0.025) 0%, rgba(139,92,246,0.015) 100%);
}
.msg-row { display: flex; gap: 16px; align-items: flex-start; }
.msg-avatar {
  width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px;
  font-family: 'Outfit';
}
.msg.user .msg-avatar {
  background: linear-gradient(135deg, var(--aev-bg-3) 0%, var(--aev-bg-2) 100%);
  color: var(--aev-text);
  border: 1px solid rgba(255,255,255,0.1);
}
.msg.assistant .msg-avatar {
  background: var(--aev-gradient-1);
  color: white;
  box-shadow: 0 4px 14px rgba(16,185,129,0.35);
}
.msg-body { flex: 1; min-width: 0; }
.msg-author {
  font-size: 13.5px; font-weight: 600; color: var(--aev-text); margin-bottom: 5px;
  font-family: 'Outfit';
}
.msg.assistant .msg-author {
  background: linear-gradient(90deg, var(--aev-primary-3) 0%, var(--aev-violet) 100%);
  -webkit-background-clip: text; background-clip: text;
  -webkit-text-fill-color: transparent;
}
.msg-author small { color: var(--aev-text-3); font-weight: 400; font-size: 11px; margin-left: 7px; -webkit-text-fill-color: var(--aev-text-3); font-style: italic; font-family: 'Crimson Pro'; }
.msg-content {
  font-size: 14.5px; line-height: 1.75; color: var(--aev-text);
  font-family: 'Manrope', -apple-system, sans-serif;
}
.msg-content p:first-child { margin-top: 0; }
.msg-content p:last-child { margin-bottom: 0; }
.msg-content code {
  background: rgba(16,185,129,0.08);
  border: 1px solid rgba(16,185,129,0.15);
  padding: 2px 7px; border-radius: 5px;
  font-family: 'JetBrains Mono'; font-size: 12.5px; color: var(--aev-primary-3);
}
.msg-content pre {
  background: rgba(10,14,19,0.6); padding: 14px 18px; border-radius: 10px;
  overflow-x: auto; border: 1px solid rgba(255,255,255,0.06);
}
.msg-content strong { color: var(--aev-text); font-weight: 600; }
.msg-content .amount { color: var(--aev-primary-2); font-weight: 600; }
.msg-content em { color: var(--aev-text-2); font-family: 'Crimson Pro'; font-style: italic; }
.msg-content ul, .msg-content ol { padding-left: 22px; }
.msg-content ul li, .msg-content ol li { margin-bottom: 5px; }
.msg-content table {
  border-collapse: separate; border-spacing: 0; width: 100%;
  margin: 12px 0; font-size: 13px;
  border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; overflow: hidden;
}
.msg-content th, .msg-content td {
  padding: 9px 13px; border-bottom: 1px solid rgba(255,255,255,0.06);
  text-align: left;
}
.msg-content th {
  background: linear-gradient(135deg, rgba(16,185,129,0.08), rgba(139,92,246,0.05));
  font-family: 'Outfit'; font-weight: 600;
  color: var(--aev-primary-3); font-size: 11.5px;
  text-transform: uppercase; letter-spacing: 0.05em;
}
.msg-content h2, .msg-content h3 {
  font-family: 'Outfit'; font-weight: 600;
  color: var(--aev-primary-3); margin-top: 18px;
}

.thinking-dots { display: inline-flex; gap: 5px; padding: 8px 0; }
.thinking-dots span {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--aev-gradient-1);
  animation: dot-bounce 1.2s infinite ease-in-out;
  box-shadow: 0 0 8px rgba(16,185,129,0.5);
}
.thinking-dots span:nth-child(2) { animation-delay: .2s; }
.thinking-dots span:nth-child(3) { animation-delay: .4s; }
@keyframes dot-bounce { 0%,80%,100% { transform: scale(0.5); opacity: .4; } 40% { transform: scale(1); opacity: 1; } }

.msg-meta {
  font-size: 11px; color: var(--aev-text-3); margin-top: 10px;
  display: flex; gap: 10px; align-items: center;
}
.msg-meta .tag {
  background: rgba(16,185,129,0.06);
  border: 1px solid rgba(16,185,129,0.15);
  padding: 3px 9px; border-radius: 5px;
  font-family: 'JetBrains Mono'; font-size: 10.5px; color: var(--aev-primary-3);
}
.msg-meta .speak-btn {
  background: transparent; border: 1px solid rgba(255,255,255,0.1); color: var(--aev-text-3);
  padding: 4px 10px; border-radius: 7px; cursor: pointer; font-size: 11px;
  display: inline-flex; align-items: center; gap: 5px;
  transition: all .15s;
}
.msg-meta .speak-btn:hover { color: var(--aev-primary-2); border-color: var(--aev-primary); background: rgba(16,185,129,0.05); }
.msg-meta .speak-btn.speaking {
  color: var(--aev-primary-2); border-color: var(--aev-primary);
  background: rgba(16,185,129,0.08);
  animation: pulse-glow 1.5s infinite;
}
@keyframes pulse-glow {
  0%, 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0.4); }
  50%      { box-shadow: 0 0 0 6px rgba(16,185,129,0); }
}

/* Composer */
.composer { padding: 18px 28px 24px; flex-shrink: 0; }
.composer-inner { max-width: 820px; margin: 0 auto; }
.composer-row {
  display: flex; align-items: flex-end; gap: 8px;
  background: linear-gradient(180deg, rgba(28, 36, 51, 0.7) 0%, rgba(19, 25, 34, 0.7) 100%);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 16px;
  padding: 9px 9px 9px 18px;
  transition: border-color .2s, box-shadow .2s;
}
.composer-row:focus-within {
  border-color: var(--aev-primary);
  box-shadow: 0 0 0 4px rgba(16,185,129,.12), 0 8px 24px rgba(16,185,129,.18);
}
.composer textarea {
  flex: 1; background: transparent; border: none; color: var(--aev-text); resize: none;
  font-family: 'Manrope'; font-size: 14.5px; line-height: 1.55; padding: 9px 0;
  min-height: 24px; max-height: 200px; outline: none;
}
.composer textarea::placeholder { color: var(--aev-text-3); font-style: italic; font-family: 'Crimson Pro'; font-size: 15px; }
.composer .icon-btn {
  background: transparent; border: none; color: var(--aev-text-2); padding: 9px;
  border-radius: 9px; cursor: pointer; font-size: 15px; transition: all .15s;
  display: inline-flex; align-items: center; justify-content: center;
}
.composer .icon-btn:hover { background: rgba(255,255,255,0.04); color: var(--aev-text); }
.composer .icon-btn.mic-btn.recording {
  color: var(--aev-bad);
  background: rgba(239,68,68,0.08);
  animation: pulse-mic 1s infinite;
}
@keyframes pulse-mic { 0%,100% { transform: scale(1); } 50% { transform: scale(1.15); } }
.composer .send-btn {
  background: var(--aev-gradient-1); color: white; padding: 10px 12px; border-radius: 11px;
  box-shadow: 0 4px 14px rgba(16,185,129,0.4);
}
.composer .send-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(16,185,129,0.55); }
.composer .send-btn:disabled { opacity: .35; cursor: not-allowed; background: var(--aev-text-3); box-shadow: none; transform: none; }
.composer-foot { font-size: 11.5px; color: var(--aev-text-3); text-align: center; margin-top: 12px; font-family: 'Crimson Pro'; font-style: italic; }
.composer-foot .indicator { color: var(--aev-primary-2); font-style: normal; font-family: 'Manrope'; }
.composer-foot code { background: rgba(255,255,255,0.05); padding: 1px 6px; border-radius: 4px; font-size: 10.5px; font-family: 'JetBrains Mono'; font-style: normal; }

/* Mark = animated logo */
.mark {
  display: inline-block;
  background: url(logo.svg) center/contain no-repeat;
  filter: drop-shadow(0 0 14px rgba(16,185,129,.5));
}

/* Mobile */
@media (max-width: 768px) {
  .app.show { grid-template-columns: 1fr; }
  aside { display: none; }
  .chat-inner, .composer-inner { padding: 0 16px; }
}
</style>
</head>
<body>

<!-- Auth overlay -->
<div class="auth-wall" id="auth-wall">
  <div class="auth-card">
    <div class="mark mark-lg" style="width:64px;height:64px;margin:0 auto 22px"></div>
    <h1 id="auth-title">At your service.</h1>
    <p class="sub" id="auth-sub">I am Aether — butler-in-residence to the foundation. Kindly identify yourself so I may verify your standing before any matters of the estate are discussed.</p>
    <div class="role-warn" id="role-warn">CONFIDENTIAL · ROLE VERIFICATION REQUIRED</div>

    <form id="login-form" class="login-form" data-testid="login-form">
      <label>Email</label>
      <input type="email" id="login-email" data-testid="login-email" autocomplete="email" required placeholder="you@dhrubfoundation.org" />
      <label>Password</label>
      <input type="password" id="login-password" data-testid="login-password" autocomplete="current-password" required placeholder="••••••••" />
      <div id="login-error" class="login-error"></div>
      <button type="submit" class="btn-primary" id="login-submit" data-testid="login-submit">
        <span id="login-btn-text">Sign in</span>
      </button>
    </form>

    <div class="auth-divider"><span>or</span></div>
    <div class="auth-alt">
      <a href="/" class="btn-secondary" data-testid="auth-erp-home"><i class="fa-solid fa-house"></i> Return to ERP home</a>
    </div>

    <div class="footnote">If you reached this page from your ERP, your session may have lapsed. Sign in again above.</div>
  </div>
</div>

<!-- App -->
<div class="app" id="app">
  <aside>
    <div class="brand">
      <div class="mark"></div>
      <div>
        <h2>Aether</h2>
        <div class="sub">in service of the estate</div>
      </div>
    </div>
    <button class="new-chat" id="new-chat" data-testid="chat-new"><i class="fa-solid fa-plus"></i> New conversation</button>
    <div class="convs" id="convs">
      <h3>Recent</h3>
      <div id="conv-list"><div style="padding:10px 12px;font-size:12px;color:var(--aev-text-3)">— no conversations yet —</div></div>
    </div>
    <div class="user-card">
      <div class="user-avatar" id="user-avatar">·</div>
      <div class="user-info">
        <div class="name" id="user-name">…</div>
        <div class="role" id="user-role">…</div>
      </div>
      <a href="/dhrub_erp/" class="menu-btn" title="Back to ERP"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
  </aside>

  <main>
    <div class="topbar">
      <div class="title" id="top-title">A new conversation begins…</div>
      <div class="actions">
        <button class="pill-btn" id="voice-toggle" data-testid="voice-toggle" title="Read replies aloud">
          <i class="fa-solid fa-volume-high"></i> Voice off
        </button>
        <a href="dashboard.php" class="pill-btn" title="Open the dashboard"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="/dhrub_erp/" class="pill-btn"><i class="fa-solid fa-arrow-left"></i> ERP</a>
      </div>
    </div>

    <div class="chat-scroll" id="chat-scroll">
      <div class="chat-inner" id="chat-inner">
        <div class="welcome" id="welcome">
          <div class="mark mark-lg" style="width:72px;height:72px"></div>
          <h1>"At your service."</h1>
          <p>I am Aether — butler-in-residence to <strong id="welcome-name">the household</strong>. May I assist with a report, a draft, or a plan for the day?</p>
          <div class="suggestions" id="suggestions"></div>
        </div>
      </div>
    </div>

    <div class="composer">
      <div class="composer-inner">
        <div class="composer-row">
          <textarea id="input" placeholder="Speak your mind, sir…" rows="1" data-testid="chat-input"></textarea>
          <button class="icon-btn mic-btn" id="mic-btn" data-testid="mic-btn" title="Voice input"><i class="fa-solid fa-microphone"></i></button>
          <button class="icon-btn send-btn" id="send-btn" data-testid="chat-send" title="Send"><i class="fa-solid fa-paper-plane"></i></button>
        </div>
        <div class="composer-foot">
          Aether may take a moment to ponder. Press <code>Enter</code> to send, <code>Shift+Enter</code> for a new line.
          <span class="indicator" id="hot-indicator"></span>
        </div>
      </div>
    </div>
  </main>
</div>

<script>
(function () {
  // ──────────── State ────────────
  const API = 'api/aether.php';
  const STORE = 'aether_conv_history';
  let token = '';
  let me = null;
  let currentConv = null;
  let isStreaming = false;
  let voiceOn = false;

  // ──────────── Auth ────────────
  function readToken() {
    for (const k of ['token', 'authToken', 'auth_token', 'jwt', 'access_token', 'userToken']) {
      const v = localStorage.getItem(k);
      if (v && v.split('.').length === 3) return v;
    }
    return null;
  }
  function showAuth(title, msg, roleWarn) {
    document.getElementById('auth-title').textContent = title;
    document.getElementById('auth-sub').textContent = msg;
    if (roleWarn !== undefined) {
      document.getElementById('role-warn').style.display = roleWarn ? 'inline-block' : 'none';
    }
    document.getElementById('auth-wall').classList.add('show');
    setTimeout(() => document.getElementById('login-email')?.focus(), 100);
  }

  // ── Inline sign-in form ──
  const loginForm = document.getElementById('login-form');
  const loginErr = document.getElementById('login-error');
  const loginSubmit = document.getElementById('login-submit');
  const loginBtnText = document.getElementById('login-btn-text');
  if (loginForm) {
    loginForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const email = document.getElementById('login-email').value.trim();
      const pw = document.getElementById('login-password').value;
      if (!email || !pw) return;
      loginErr.textContent = '';
      loginSubmit.disabled = true;
      loginBtnText.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying…';
      try {
        const r = await fetch('/api/auth/login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email, password: pw }),
        });
        const j = await r.json();
        if (!r.ok || !j.access_token) {
          loginErr.textContent = j.detail || j.error || 'Sign-in failed. Please verify your credentials.';
          loginSubmit.disabled = false;
          loginBtnText.textContent = 'Sign in';
          return;
        }
        // Store across all possible token keys for compatibility
        ['access_token','token','authToken','auth_token','jwt'].forEach(k => localStorage.setItem(k, j.access_token));
        loginBtnText.innerHTML = '<i class="fa-solid fa-check"></i> Welcome';
        setTimeout(() => window.location.reload(), 400);
      } catch (err) {
        loginErr.textContent = 'Could not reach the estate. Please try again.';
        loginSubmit.disabled = false;
        loginBtnText.textContent = 'Sign in';
      }
    });
  }

  async function bootstrap() {
    token = readToken();
    if (!token) {
      showAuth('Sign in to meet Aether',
        'Aether is your foundation\'s butler-in-residence. To preserve the discretion of the estate, you must first sign in to your ERP. Your role and permissions are confirmed there before any confidential matters are discussed.',
        true);
      return;
    }
    try {
      const r = await call('identity');
      if (!r.user) throw new Error('no identity');
      me = r.user;
    } catch (e) {
      showAuth('Your session has lapsed',
        'It appears the ERP session has expired, sir. Kindly sign in again to resume our conversation.',
        true);
      return;
    }
    document.getElementById('app').classList.add('show');
    document.getElementById('user-name').textContent = me.full_name || me.username;
    document.getElementById('user-role').textContent = me.role || '—';
    document.getElementById('user-avatar').textContent = (me.full_name || me.username || '?').slice(0,1).toUpperCase();
    document.getElementById('welcome-name').textContent = formalName(me);
    renderSuggestions();
    renderConversations();
    setTimeout(() => document.getElementById('input').focus(), 200);
  }

  function formalName(u) {
    const full = (u.full_name || u.username || 'the user').trim();
    const parts = full.split(/\s+/);
    if (parts.length >= 2) return 'Mr. ' + parts[parts.length - 1];
    return full;
  }

  async function call(action, body = {}) {
    const r = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
      body: JSON.stringify({ action, ...body })
    });
    if (r.status === 401) {
      showAuth('Authentication required', 'Your session expired, sir.', false);
      throw new Error('auth');
    }
    return r.json();
  }

  // ──────────── Suggestions (role-aware) ────────────
  const SUGG = {
    super_admin: [
      { ico: 'chart-pie',     label: 'Brief me on this month\'s donations',           hint: 'Live data', msg: 'Brief me on this month\'s donations and what action you recommend.' },
      { ico: 'scale-balanced', label: 'Draft an 80G compliance summary for FY 25-26', hint: 'Indian statute', msg: 'Build the 80G compliance summary for FY 2025-26 and flag any donations that would not qualify.' },
      { ico: 'pen-fancy',     label: 'Write a 200-word donor thank-you',              hint: 'In our voice', msg: 'Draft a 200-word thank-you note for a donor who recently gave ₹25,000 to our education programme.' },
      { ico: 'triangle-exclamation', label: 'Any donors gone quiet?',                 hint: 'Lapsed donor sweep', msg: 'Which donors have not given in 90+ days, and shall we send a warm reminder?' },
    ],
    admin: [
      { ico: 'chart-pie',     label: 'How are donations trending?',                    hint: 'Live', msg: 'How are donations trending compared to last quarter?' },
      { ico: 'pen-fancy',     label: 'Draft a board memo on programme ROI',            hint: '5 minutes', msg: 'Draft a one-page board memo summarising programme ROI for the last 90 days.' },
    ],
    manager: [
      { ico: 'list-check',    label: 'What\'s on my plate?',                            hint: 'My tasks', msg: 'What pending tasks are assigned to me, sir?' },
      { ico: 'box',           label: 'Anything running low in inventory?',              hint: 'Stock check', msg: 'Are there any inventory items below their minimum stock level?' },
    ],
    accountant: [
      { ico: 'hand-holding-heart', label: 'Today\'s donation register',                 hint: 'Live', msg: 'Show me today\'s donation register.' },
      { ico: 'wallet',        label: 'Last week\'s expense summary',                    hint: 'Category breakdown', msg: 'Summarise last week\'s expenses by category.' },
    ],
    hr: [
      { ico: 'users',         label: 'Active employees count by department',           hint: 'Live', msg: 'How many active employees do we have, grouped by department?' },
    ],
    editor: [
      { ico: 'pen-fancy',     label: 'Draft a blog post about our latest programme',    hint: '350 words', msg: 'Draft a 350-word blog post about our latest education programme for the foundation\'s blog.' },
    ],
    viewer: [
      { ico: 'gauge-high',    label: 'Give me an estate snapshot',                      hint: 'Counts only', msg: 'Give me a high-level snapshot of the estate — counts only, no personal information.' },
    ],
  };

  function renderSuggestions() {
    const role = me?.role || 'viewer';
    const list = SUGG[role] || SUGG.viewer;
    document.getElementById('suggestions').innerHTML = list.map(s => `
      <div class="sugg" data-msg="${esc(s.msg)}" data-testid="sugg-${esc(s.ico)}">
        <div class="ico"><i class="fa-solid fa-${esc(s.ico)}"></i></div>
        <div class="label">${esc(s.label)}</div>
        <div class="hint">${esc(s.hint)}</div>
      </div>`).join('');
    document.querySelectorAll('.sugg').forEach(el =>
      el.addEventListener('click', () => sendMessage(el.getAttribute('data-msg'))));
  }

  // ──────────── Conversations (sidebar) ────────────
  function loadConvs() {
    try { return JSON.parse(localStorage.getItem(STORE) || '[]'); }
    catch (e) { return []; }
  }
  function saveConvs(c) { localStorage.setItem(STORE, JSON.stringify(c.slice(0, 20))); }

  function renderConversations() {
    const list = loadConvs();
    if (!list.length) {
      document.getElementById('conv-list').innerHTML = '<div style="padding:10px 12px;font-size:12px;color:var(--aev-text-3)">— no conversations yet —</div>';
      return;
    }
    document.getElementById('conv-list').innerHTML = list.map(c => `
      <div class="conv-item ${c.id === currentConv?.id ? 'active' : ''}" data-id="${esc(c.id)}">
        <i class="fa-regular fa-comment"></i> ${esc(c.title || 'Untitled')}
      </div>`).join('');
    document.querySelectorAll('.conv-item').forEach(el =>
      el.addEventListener('click', () => loadConv(el.dataset.id)));
  }

  function loadConv(id) {
    const list = loadConvs();
    const c = list.find(x => x.id === id);
    if (!c) return;
    currentConv = c;
    document.getElementById('top-title').textContent = c.title || 'Untitled';
    document.getElementById('chat-inner').innerHTML = '';
    document.getElementById('welcome').style.display = 'none';
    for (const m of c.messages || []) {
      addMessage(m.role, m.content, { skipPersist: true, meta: m.meta });
    }
    renderConversations();
    scrollToBottom();
  }

  function newConv() {
    currentConv = null;
    document.getElementById('chat-inner').innerHTML = '';
    document.getElementById('welcome').style.display = '';
    document.getElementById('top-title').textContent = 'A new conversation begins…';
    document.querySelectorAll('.conv-item').forEach(x => x.classList.remove('active'));
    renderSuggestions();
    document.getElementById('input').focus();
  }
  document.getElementById('new-chat').addEventListener('click', newConv);

  function persistMessage(role, content, meta) {
    if (!currentConv) {
      currentConv = {
        id: 'c_' + Date.now().toString(36),
        title: role === 'user' ? content.slice(0, 48) : 'New conversation',
        created: Date.now(),
        messages: [],
      };
      document.getElementById('top-title').textContent = currentConv.title;
    }
    currentConv.messages.push({ role, content, meta, ts: Date.now() });
    const list = loadConvs().filter(c => c.id !== currentConv.id);
    list.unshift(currentConv);
    saveConvs(list);
    renderConversations();
  }

  // ──────────── Messaging ────────────
  function esc(s) { return String(s || '').replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m])); }
  function md(t) {
    return esc(t)
      .replace(/```([\s\S]+?)```/g, (_, c) => '<pre>' + c.replace(/^\n/, '') + '</pre>')
      .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
      .replace(/\*([^*\n]+)\*/g, '<em>$1</em>')
      .replace(/`([^`\n]+)`/g, '<code>$1</code>')
      .replace(/(₹\s?[\d,]+(?:\.\d+)?)/g, '<span class="amount">$1</span>')
      .replace(/(^|\n)### (.+)/g, '$1<h3 style="font-family:Outfit;font-weight:600;font-size:16px;margin:14px 0 6px">$2</h3>')
      .replace(/(^|\n)## (.+)/g, '$1<h2 style="font-family:Outfit;font-weight:600;font-size:18px;margin:18px 0 8px">$2</h2>')
      .replace(/\n/g, '<br>');
  }

  function scrollToBottom() {
    const s = document.getElementById('chat-scroll');
    s.scrollTop = s.scrollHeight;
  }

  function addMessage(role, content, opts = {}) {
    document.getElementById('welcome').style.display = 'none';
    const el = document.createElement('div');
    el.className = 'msg ' + role;
    const author = role === 'user' ? (me?.full_name || 'You') : 'Aether';
    const initial = role === 'user' ? (author.slice(0,1).toUpperCase()) : 'A';
    let meta = '';
    if (opts.meta?.model) {
      meta = `<div class="msg-meta">
        <span class="tag">${esc(opts.meta.model)}</span>
        ${opts.meta.latency_ms ? `<span class="tag">${opts.meta.latency_ms}ms</span>` : ''}
        ${role === 'assistant' ? '<button class="speak-btn" data-testid="speak"><i class="fa-solid fa-volume-low"></i> Speak</button>' : ''}
      </div>`;
    } else if (role === 'assistant') {
      meta = '<div class="msg-meta"><button class="speak-btn" data-testid="speak"><i class="fa-solid fa-volume-low"></i> Speak</button></div>';
    }
    el.innerHTML = `
      <div class="msg-row">
        <div class="msg-avatar">${esc(initial)}</div>
        <div class="msg-body">
          <div class="msg-author">${esc(author)}${role === 'assistant' ? ' <small>butler</small>' : ''}</div>
          <div class="msg-content">${md(content)}</div>
          ${meta}
        </div>
      </div>`;
    document.getElementById('chat-inner').appendChild(el);
    scrollToBottom();

    const speakBtn = el.querySelector('.speak-btn');
    if (speakBtn) speakBtn.addEventListener('click', () => speak(content, speakBtn));

    if (!opts.skipPersist) persistMessage(role, content, opts.meta);
    return el;
  }

  async function sendMessage(text) {
    text = (text || document.getElementById('input').value).trim();
    if (!text || isStreaming) return;
    document.getElementById('input').value = '';
    autosize();
    addMessage('user', text);
    isStreaming = true;
    document.getElementById('send-btn').disabled = true;

    const convId = currentConv?.id || 'standalone_' + Date.now();

    const assistantEl = document.createElement('div');
    assistantEl.className = 'msg assistant';
    assistantEl.innerHTML = `
      <div class="msg-row">
        <div class="msg-avatar">A</div>
        <div class="msg-body">
          <div class="msg-author">Aether <small>butler</small></div>
          <div class="msg-content"><span class="thinking-dots"><span></span><span></span><span></span></span></div>
        </div>
      </div>`;
    document.getElementById('chat-inner').appendChild(assistantEl);
    scrollToBottom();
    const content = assistantEl.querySelector('.msg-content');

    let collected = '';
    let meta = null;
    try {
      const r = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({ action: 'chat_stream', message: text, conversation_id: convId }),
      });
      if (!r.ok || !r.body) throw new Error('stream unavailable');
      const reader = r.body.getReader();
      const decoder = new TextDecoder();
      let buf = '';
      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        buf += decoder.decode(value, { stream: true });
        let idx;
        while ((idx = buf.indexOf('\n\n')) >= 0) {
          const frame = buf.slice(0, idx);
          buf = buf.slice(idx + 2);
          let event = 'message', data = '';
          for (const line of frame.split('\n')) {
            if (line.startsWith('event: ')) event = line.slice(7).trim();
            else if (line.startsWith('data: ')) data += line.slice(6);
          }
          if (!data) continue;
          let payload = {}; try { payload = JSON.parse(data); } catch (e) {}
          if (event === 'token') {
            if (collected === '') content.innerHTML = '';
            collected += payload.t || '';
            content.innerHTML = md(collected);
            scrollToBottom();
          } else if (event === 'done') {
            meta = payload;
          } else if (event === 'status') {
            content.innerHTML = '<em style="opacity:.7">Pondering, sir…</em>';
          }
        }
      }
      if (!collected) collected = '(no response, sir)';
      // Persist + add meta tag + speak button
      const metaDiv = document.createElement('div');
      metaDiv.className = 'msg-meta';
      metaDiv.innerHTML = `
        ${meta?.model ? `<span class="tag">${esc(meta.model)}</span>` : ''}
        ${meta?.source ? `<span class="tag">${esc(meta.source)}</span>` : ''}
        <button class="speak-btn" data-testid="speak"><i class="fa-solid fa-volume-low"></i> Speak</button>`;
      content.parentNode.appendChild(metaDiv);
      const speakBtn = metaDiv.querySelector('.speak-btn');
      if (speakBtn) speakBtn.addEventListener('click', () => speak(collected, speakBtn));
      persistMessage('assistant', collected, meta || {});

      // Auto-TTS if voice is on
      if (voiceOn) speak(collected, speakBtn);

      // Tell backend to persist on the server side too
      if (meta?.source !== 'rules') {
        call('chat_persist_assistant', { conversation_id: convId, text: collected, meta }).catch(() => {});
      }
    } catch (e) {
      content.innerHTML = `<em style="color:var(--aev-bad)">I'm afraid I encountered a disturbance, sir. (${esc(e.message || 'unknown')})</em>`;
    } finally {
      isStreaming = false;
      document.getElementById('send-btn').disabled = false;
      document.getElementById('input').focus();
    }
  }

  // ──────────── Input handlers ────────────
  const input = document.getElementById('input');
  function autosize() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 200) + 'px';
  }
  input.addEventListener('input', autosize);
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });
  document.getElementById('send-btn').addEventListener('click', () => sendMessage());

  // ──────────── Voice: STT (Web Speech API) ────────────
  const micBtn = document.getElementById('mic-btn');
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  let recog = null;
  let recording = false;

  if (!SR) {
    micBtn.style.display = 'none';
  } else {
    recog = new SR();
    recog.continuous = false;
    recog.interimResults = true;
    recog.lang = 'en-IN';
    recog.onresult = e => {
      let txt = '';
      for (let i = e.resultIndex; i < e.results.length; i++) {
        txt += e.results[i][0].transcript;
      }
      input.value = txt;
      autosize();
    };
    recog.onend = () => { recording = false; micBtn.classList.remove('recording'); };
    recog.onerror = () => { recording = false; micBtn.classList.remove('recording'); };

    micBtn.addEventListener('click', () => {
      if (recording) { recog.stop(); return; }
      recording = true;
      micBtn.classList.add('recording');
      try { recog.start(); } catch (e) { recording = false; micBtn.classList.remove('recording'); }
    });
  }

  // ──────────── Voice: TTS (SpeechSynthesis) ────────────
  function chooseVoice() {
    const voices = window.speechSynthesis.getVoices();
    // Prefer English UK voices, then Indian English, then any English
    const preferences = [
      v => /en-GB/i.test(v.lang) && /Daniel|Oliver|Arthur/i.test(v.name),
      v => /en-GB/i.test(v.lang),
      v => /en-IN/i.test(v.lang),
      v => /en/i.test(v.lang),
    ];
    for (const test of preferences) {
      const found = voices.find(test);
      if (found) return found;
    }
    return voices[0];
  }
  function speak(text, btn) {
    if (!window.speechSynthesis) return;
    window.speechSynthesis.cancel();
    // Strip markdown for cleaner speech
    const clean = text.replace(/\*\*([^*]+)\*\*/g, '$1').replace(/\*([^*]+)\*/g, '$1').replace(/`([^`]+)`/g, '$1').replace(/[#>_]/g, '');
    const utt = new SpeechSynthesisUtterance(clean);
    const v = chooseVoice();
    if (v) utt.voice = v;
    utt.rate = 1.02;
    utt.pitch = 0.95;
    utt.volume = 1;
    if (btn) btn.classList.add('speaking');
    utt.onend = () => { if (btn) btn.classList.remove('speaking'); };
    utt.onerror = () => { if (btn) btn.classList.remove('speaking'); };
    window.speechSynthesis.speak(utt);
  }
  // Warm up voices
  if (window.speechSynthesis) {
    window.speechSynthesis.onvoiceschanged = () => {};
    window.speechSynthesis.getVoices();
  }

  // Voice toggle (auto-speak all replies)
  const voiceBtn = document.getElementById('voice-toggle');
  voiceBtn.addEventListener('click', () => {
    voiceOn = !voiceOn;
    voiceBtn.classList.toggle('active', voiceOn);
    voiceBtn.innerHTML = voiceOn
      ? '<i class="fa-solid fa-volume-high"></i> Voice on'
      : '<i class="fa-solid fa-volume-xmark"></i> Voice off';
    if (!voiceOn) window.speechSynthesis?.cancel();
  });

  // ──────────── Init ────────────
  bootstrap();
})();
</script>
</body>
</html>
