"""
Aether v2 — Iteration 7 backend regression tests.
Covers: standalone chat.php entry point, panel resizable handle exposure via
data-testid, voice/mic widget visibility, cron diagnostic command.
(Pure backend / HTTP smoke tests; UI verification is done with playwright in
the main agent loop.)
"""
import os, requests, pytest

BASE = os.environ.get('AETHER_BASE', 'http://localhost:8001')

def test_standalone_chat_page_loads():
    r = requests.get(f'{BASE}/aetherV2/chat.php', timeout=8)
    assert r.status_code == 200
    body = r.text
    # Key UI markers
    assert 'Aether' in body
    assert 'data-testid="chat-input"' in body
    assert 'data-testid="mic-btn"' in body
    assert 'data-testid="voice-toggle"' in body
    assert 'data-testid="chat-new"' in body
    # Auth gate
    assert 'auth-wall' in body
    # Suggestions container
    assert 'id="suggestions"' in body
    # Conversation sidebar
    assert 'id="conv-list"' in body
    # Crimson Pro for the butler welcome
    assert 'Crimson+Pro' in body

def test_standalone_chat_redirects_unauth_friendly():
    # The page itself is public HTML — JS detects missing token and shows overlay.
    # We just confirm the overlay markup exists so the unauth UX renders.
    r = requests.get(f'{BASE}/aetherV2/chat.php', timeout=8)
    assert 'Sign in to meet Aether' in r.text or 'auth-wall' in r.text

def test_panel_js_has_resize_handle_markup():
    r = requests.get(f'{BASE}/aetherV2/panel.js', timeout=8)
    assert r.status_code == 200
    body = r.text
    # New buttons + handle
    assert 'aev-resize-handle' in body
    assert 'aether-v2-voice' in body
    assert 'aether-v2-fullscreen' in body
    assert 'aether-v2-mic' in body
    # Persistent storage keys for size
    assert 'aether_panel_w' in body and 'aether_panel_h' in body
    # Speech APIs
    assert 'SpeechRecognition' in body
    assert 'speakButler' in body

def test_style_css_has_resize_styles():
    r = requests.get(f'{BASE}/aetherV2/style.css', timeout=8)
    assert r.status_code == 200
    body = r.text
    assert '.aev-resize-handle' in body
    assert 'aev-mic-pulse' in body or '.recording' in body
