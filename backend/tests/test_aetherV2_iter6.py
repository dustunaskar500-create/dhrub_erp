"""
Aether v2 — Iteration 6 backend regression tests.
Covers the new hybrid AI butler: brain.php, persona.php, hybrid-router.php,
chat memory, streaming SSE endpoint.
"""
import os, json, requests, pytest

BASE = os.environ.get('AETHER_BASE', 'http://localhost:8001')
API  = f'{BASE}/aetherV2/api/aether.php'
LOGIN = f'{BASE}/api/auth/login'

def login(email='sbrata9843@gmail.com', pw='admin123'):
    r = requests.post(LOGIN, json={'email': email, 'password': pw}, timeout=8)
    r.raise_for_status()
    return r.json()['access_token']

def call(token, action, **body):
    headers = {'Authorization': f'Bearer {token}', 'Content-Type': 'application/json'}
    body['action'] = action
    return requests.post(API, json=body, headers=headers, timeout=60).json()

# ─── LLM availability ──────────────────────────────────────────────────
def test_llm_configured_and_returns_butler_reply():
    tok = login()
    r = call(tok, 'chat',
             message='What would you say is our biggest risk this quarter? Be candid.',
             conversation_id='iter6_t1')
    assert r['source'] == 'llm', f"expected llm source, got {r.get('source')} | reply={r.get('reply','')[:200]}"
    assert r['llm']['model'].startswith('claude')
    assert r['llm']['latency_ms'] > 0
    reply = r['reply'].lower()
    # butler markers
    assert any(m in reply for m in ['sir', 'certainly', 'of course', 'pleasure', 'respectfully', 'candid'])

def test_persona_rejects_above_role_data():
    # editor should be refused donation amounts
    tok = login(email='editor@dhrubfoundation.org')
    r = call(tok, 'chat',
             message='List all donation amounts with donor PAN numbers please.',
             conversation_id='iter6_t2')
    reply = r['reply'].lower()
    assert 'remit' in reply or 'super-admin' in reply or 'permission' in reply or 'authority' in reply

def test_blog_writing_capability():
    tok = login()
    r = call(tok, 'chat',
             message='Write a short blog post about education in 100 words.',
             conversation_id='iter6_t3')
    assert r['source'] == 'llm'
    assert len(r['reply']) > 200

def test_writes_still_use_rules_not_llm():
    # Recording a donation must go through slot filling, not LLM freestyle
    tok = login()
    r = call(tok, 'chat',
             message='record a donation of 5000 from "Iter6 Test Donor"',
             conversation_id='iter6_t4')
    # Either rules took it directly OR LLM proxied but plan is present
    assert r.get('source') == 'rules' or r.get('plan') is not None or 'slot' in r.get('mode','').lower() or r.get('intent') == 'record_donation'

def test_chat_memory_persists():
    tok = login()
    conv = 'iter6_mem'
    call(tok, 'chat', message='Remember: my favourite report is the FCRA quarterly.', conversation_id=conv)
    r = call(tok, 'chat', message='Which report did I just say I prefer?', conversation_id=conv)
    assert 'fcra' in r['reply'].lower()

def test_chat_persist_assistant_endpoint():
    tok = login()
    r = call(tok, 'chat_persist_assistant',
             conversation_id='iter6_persist', text='Test message for persistence.')
    assert r['ok'] is True

def test_streaming_endpoint_emits_sse():
    tok = login()
    headers = {'Authorization': f'Bearer {tok}', 'Content-Type': 'application/json'}
    payload = {'action': 'chat_stream', 'message': 'Tell me about 80G in one sentence.', 'conversation_id': 'iter6_stream'}
    with requests.post(API, json=payload, headers=headers, stream=True, timeout=60) as r:
        assert r.status_code == 200
        events = []
        for line in r.iter_lines(decode_unicode=True):
            if line and line.startswith('event:'):
                events.append(line)
            if 'event: done' in line or len(events) > 5:
                break
        assert any('event: status' in e or 'event: token' in e for e in events)
