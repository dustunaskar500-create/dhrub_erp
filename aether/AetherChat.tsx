import React, { useState, useRef, useEffect, useCallback } from 'react';

// ─── Markdown renderer ────────────────────────────────────────────────────────
function renderMarkdown(text: string): React.ReactNode[] {
  const lines = text.split('\n');
  const nodes: React.ReactNode[] = [];

  lines.forEach((line, li) => {
    const trimmed = line.trim();
    if (/^[-*] /.test(trimmed)) {
      nodes.push(
        <div key={li} className="flex gap-1.5 my-0.5">
          <span className="mt-1.5 w-1.5 h-1.5 rounded-full bg-current flex-shrink-0 opacity-60" />
          <span>{inlineMarkdown(trimmed.replace(/^[-*] /, ''))}</span>
        </div>
      );
    } else if (/^\*\*(.+)\*\*$/.test(trimmed)) {
      nodes.push(<p key={li} className="font-semibold my-0.5">{inlineMarkdown(trimmed)}</p>);
    } else if (/^#{1,3} /.test(trimmed)) {
      nodes.push(<p key={li} className="font-bold my-1 text-emerald-700">{inlineMarkdown(trimmed.replace(/^#+\s/, ''))}</p>);
    } else if (trimmed === '') {
      nodes.push(<div key={li} className="h-2" />);
    } else {
      nodes.push(<p key={li} className="my-0.5 leading-relaxed">{inlineMarkdown(trimmed)}</p>);
    }
  });
  return nodes;
}

function inlineMarkdown(text: string): React.ReactNode {
  const pattern = /(\*\*(.+?)\*\*|\*(.+?)\*|`(.+?)`|\[(.+?)\]\((.+?)\))/g;
  const parts: React.ReactNode[] = [];
  let last = 0, m: RegExpExecArray | null;

  while ((m = pattern.exec(text)) !== null) {
    if (m.index > last) parts.push(text.slice(last, m.index));
    if (m[2])      parts.push(<strong key={m.index}>{m[2]}</strong>);
    else if (m[3]) parts.push(<em key={m.index}>{m[3]}</em>);
    else if (m[4]) parts.push(<code key={m.index} className="bg-black/10 rounded px-1 font-mono text-xs">{m[4]}</code>);
    else if (m[5]) parts.push(<a key={m.index} href={m[6]} target="_blank" rel="noreferrer" className="underline text-emerald-700 break-all">{m[5]}</a>);
    last = m.index + m[0].length;
  }
  if (last < text.length) parts.push(text.slice(last));
  return parts.length === 0 ? text : parts.length === 1 ? parts[0] : <>{parts}</>;
}

// ─── Types ─────────────────────────────────────────────────────────────────────
interface Message {
  role: 'user' | 'assistant';
  content: string;
  error?: boolean;
}

// ─── JWT Token helpers ────────────────────────────────────────────────────────
/**
 * The ERP is a React SPA that stores the JWT in localStorage after login.
 * We try the most common key names used by PHP/React ERPs.
 */
function getJwtToken(): string | null {
  const keys = ['token', 'authToken', 'auth_token', 'jwt', 'access_token', 'userToken'];
  for (const key of keys) {
    const val = localStorage.getItem(key);
    if (val && val.split('.').length === 3) return val; // basic JWT shape check
  }
  return null;
}

function authHeaders(): Record<string, string> {
  const token = getJwtToken();
  return token
    ? { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` }
    : { 'Content-Type': 'application/json' };
}

/**
 * Asks auth-check.php whether the current JWT is valid.
 * Sends the token as a Bearer header — same as every other ERP API call.
 */
/**
 * Checks whether the current JWT is valid against auth-check.php.
 * Returns 'authenticated' | 'unauthenticated' | 'network_error' so the
 * widget can distinguish "not logged in" from "server temporarily unreachable".
 */
async function checkAuthStatus(): Promise<'authenticated' | 'unauthenticated' | 'network_error'> {
  const token = getJwtToken();
  if (!token) return 'unauthenticated';
  try {
    const res = await fetch('/dhrub_erp/aether/api/auth-check.php', {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
    });
    // If the ERP's parent .htaccess intercepted and returned HTML (login redirect)
    const ct = res.headers.get('content-type') ?? '';
    if (!ct.includes('application/json')) {
      const body = await res.text();
      // HTML = login page redirect = not authenticated
      if (body.trimStart().startsWith('<')) return 'unauthenticated';
      return 'network_error';
    }
    if (res.status === 401 || res.status === 403) return 'unauthenticated';
    if (!res.ok) return 'network_error';
    const data = await res.json();
    return data.authenticated === true ? 'authenticated' : 'unauthenticated';
  } catch {
    return 'network_error';
  }
}

// ─── Component ─────────────────────────────────────────────────────────────────
const AetherChat: React.FC = () => {
  const [visible, setVisible]     = useState(false);
  const [isOpen, setIsOpen]       = useState(false);
  const [messages, setMessages]   = useState<Message[]>([]);
  const [input, setInput]         = useState('');
  const [isTyping, setIsTyping]   = useState(false);
  const [isListening, setIsListening] = useState(false);

  const chatRef          = useRef<HTMLDivElement>(null);
  const inputRef         = useRef<HTMLInputElement>(null);
  const recognitionRef   = useRef<any>(null);
  const sendMessageWithRef = useRef<(text: string) => void>(() => {});

  const conversationId = useRef<string>(
    (() => {
      try {
        const stored = sessionStorage.getItem('aether_conv_id');
        if (stored) return stored;
      } catch {}
      const id = 'conv_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);
      try { sessionStorage.setItem('aether_conv_id', id); } catch {}
      return id;
    })()
  );

  // ── Auth gate: only show widget when logged in ─────────────────────────────
  // Network errors don't hide the widget — only a definitive "unauthenticated"
  // response does. This prevents the widget from vanishing during brief outages.
  useEffect(() => {
    let cancelled = false;
    let retryTimer: ReturnType<typeof setTimeout> | null = null;

    const check = async () => {
      const result = await checkAuthStatus();
      if (cancelled) return;

      if (result === 'authenticated') {
        setVisible(true);
      } else if (result === 'unauthenticated') {
        setVisible(false);
      } else {
        // network_error — keep current visibility, retry in 5 s
        retryTimer = setTimeout(check, 5000);
      }
    };

    check();

    // Re-check on SPA navigation
    const onNav = () => check();
    window.addEventListener('popstate', onNav);

    // Periodic re-check every 5 minutes to catch token expiry
    const pollTimer = setInterval(check, 5 * 60 * 1000);

    return () => {
      cancelled = true;
      window.removeEventListener('popstate', onNav);
      clearInterval(pollTimer);
      if (retryTimer) clearTimeout(retryTimer);
    };
  }, []);

  // ── Voice recognition ──────────────────────────────────────────────────────
  useEffect(() => {
    const SR = (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
    if (!SR) return;
    const recognition = new SR();
    recognition.continuous     = false;
    recognition.interimResults = false;
    recognition.lang           = 'en-IN';

    recognition.onresult = (e: any) => {
      const transcript = e.results[0][0].transcript.trim();
      setIsListening(false);
      if (transcript) {
        setInput(transcript);
        setTimeout(() => sendMessageWithRef.current(transcript), 100);
      }
    };
    recognition.onerror = () => setIsListening(false);
    recognition.onend   = () => setIsListening(false);
    recognitionRef.current = recognition;
  }, []);

  // ── Auto-scroll ────────────────────────────────────────────────────────────
  useEffect(() => {
    chatRef.current?.scrollTo({ top: chatRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, isTyping]);

  // ── Focus input when opened ────────────────────────────────────────────────
  useEffect(() => {
    if (isOpen) setTimeout(() => inputRef.current?.focus(), 100);
  }, [isOpen]);

  // ── Core send function ─────────────────────────────────────────────────────
  // Uses regular JSON fetch (not SSE) — required for Hostinger shared hosting
  // which buffers all PHP output and does not support true streaming.
  const sendMessageWith = useCallback(async (text: string) => {
    text = text.trim();
    if (!text || isTyping) return;

    setMessages(prev => {
      if (prev.length > 0 && prev[prev.length - 1].role === 'user' && prev[prev.length - 1].content === text) return prev;
      return [...prev, { role: 'user', content: text }];
    });
    setInput('');
    setIsTyping(true);

    // Show typing indicator
    setMessages(prev => [...prev, { role: 'assistant', content: '' }]);

    try {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 90000); // 90s timeout

      const res = await fetch('/dhrub_erp/aether/api/aether.php', {
        method: 'POST',
        headers: authHeaders(),
        body: JSON.stringify({ message: text, conversation_id: conversationId.current }),
        credentials: 'same-origin',
        signal: controller.signal,
      });

      clearTimeout(timeout);

      if (res.status === 401) {
        setMessages(prev => [...prev.slice(0, -1), {
          role: 'assistant',
          content: '⚠️ Session expired. Please refresh the page and log in again.',
          error: true,
        }]);
        setVisible(false);
        setTimeout(() => { window.location.href = '/dhrub_erp/'; }, 2000);
        return;
      }

      // Detect HTML response (login page redirect) before trying .json()
      const contentType = res.headers.get('content-type') ?? '';
      if (!contentType.includes('application/json')) {
        const body = await res.text();
        const isHtml = body.trimStart().startsWith('<');
        if (res.status === 401 || res.status === 403 || isHtml) {
          setMessages(prev => [...prev.slice(0, -1), {
            role: 'assistant',
            content: '⚠️ Session expired or not logged in. Please refresh the page and log in again.',
            error: true,
          }]);
          setVisible(false);
          setTimeout(() => { window.location.href = '/dhrub_erp/'; }, 2500);
          return;
        }
        throw new Error(`Unexpected response (HTTP ${res.status})`);
      }

      if (!res.ok) throw new Error(`Server error (HTTP ${res.status})`);

      const data = await res.json();

      if (data.error) {
        setMessages(prev => [...prev.slice(0, -1), {
          role: 'assistant',
          content: '⚠️ ' + data.error,
          error: true,
        }]);
        return;
      }

      const reply = (data.reply ?? '').trim();
      setMessages(prev => [...prev.slice(0, -1), {
        role: 'assistant',
        content: reply || '(No response)',
      }]);

    } catch (e: any) {
      const msg = e?.name === 'AbortError'
        ? 'Request timed out. The server took too long to respond.'
        : (e?.message || 'Connection error. Please try again.');
      setMessages(prev => [...prev.slice(0, -1), {
        role: 'assistant',
        content: '⚠️ ' + msg,
        error: true,
      }]);
    } finally {
      setIsTyping(false);
    }
  }, [isTyping]);

  useEffect(() => { sendMessageWithRef.current = sendMessageWith; }, [sendMessageWith]);

  const sendMessage = useCallback(() => sendMessageWith(input), [input, sendMessageWith]);

  const clearChat = async () => {
    if (!window.confirm('Clear entire chat history?')) return;
    try {
      await fetch('/dhrub_erp/aether/api/aether.php', {
        method: 'POST',
        headers: authHeaders(),
        body: JSON.stringify({ action: 'clear', conversation_id: conversationId.current }),
        credentials: 'same-origin',
      });
    } catch {}
    setMessages([]);
  };

  const toggleVoice = () => {
    if (!recognitionRef.current) {
      alert('Voice input not supported in this browser. Please use Chrome or Edge.');
      return;
    }
    if (isListening) recognitionRef.current.stop();
    else {
      setIsListening(true);
      try { recognitionRef.current.start(); } catch { setIsListening(false); }
    }
  };

  const quickPrompt = (text: string) => {
    if (isTyping) return;
    setInput(text);
    setTimeout(() => sendMessageWith(text), 50);
  };

  // ── Don't render anything if not authenticated ─────────────────────────────
  if (!visible) return null;

  return (
    <>
      {/* FAB button */}
      <button
        onClick={() => setIsOpen(o => !o)}
        className={`fixed bottom-8 right-8 w-16 h-16 bg-emerald-600 hover:bg-emerald-700 rounded-2xl shadow-2xl flex items-center justify-center text-3xl z-[9999] transition-all active:scale-95 ${isOpen ? 'rotate-12' : ''}`}
        title="Open Aether AI Assistant"
        aria-label="Open Aether AI Assistant"
      >
        🤖
      </button>

      {/* Chat panel */}
      {isOpen && (
        <div className="fixed bottom-28 right-8 w-[390px] h-[600px] bg-white border border-slate-200 rounded-3xl shadow-2xl flex flex-col overflow-hidden z-[9999]">

          {/* Header */}
          <div className="bg-emerald-600 text-white px-5 py-4 flex items-center justify-between flex-shrink-0">
            <div className="flex items-center gap-3">
              <span className="text-3xl">🤖</span>
              <div>
                <h3 className="font-semibold text-lg leading-none">Aether</h3>
                <p className="text-xs opacity-80 mt-0.5">AI Office Assistant</p>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <button onClick={clearChat} className="text-sm hover:bg-emerald-700 px-3 py-1.5 rounded-lg">Clear</button>
              <button
                onClick={() => setIsOpen(false)}
                className="text-2xl leading-none hover:bg-emerald-700 w-9 h-9 flex items-center justify-center rounded-lg"
                aria-label="Close"
              >×</button>
            </div>
          </div>

          {/* Messages */}
          <div ref={chatRef} className="flex-1 p-4 overflow-y-auto bg-slate-50 space-y-3 text-sm">
            {messages.length === 0 && (
              <div className="text-center text-slate-400 py-10 px-4">
                <div className="text-4xl mb-3">🤖</div>
                <p className="font-medium text-slate-500 mb-1">Hi! I'm Aether.</p>
                <p className="text-xs leading-relaxed">Ask me about donations, payroll, expenses,<br />or generate receipts &amp; payslips.</p>
              </div>
            )}

            {messages.map((msg, i) => (
              <div key={i} className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                <div className={`max-w-[88%] px-4 py-2.5 rounded-2xl text-sm ${
                  msg.role === 'user'
                    ? 'bg-emerald-600 text-white rounded-br-none'
                    : msg.error
                    ? 'bg-red-50 border border-red-200 text-red-700 rounded-bl-none'
                    : 'bg-white border border-slate-200 rounded-bl-none shadow-sm text-slate-800'
                }`}>
                  {msg.role === 'assistant' && !msg.error ? renderMarkdown(msg.content) : msg.content}
                  {msg.role === 'assistant' && !msg.error && isTyping && i === messages.length - 1 && (
                    <span className="inline-block w-0.5 h-4 bg-emerald-500 ml-0.5 animate-pulse align-middle" />
                  )}
                </div>
              </div>
            ))}

            {isTyping && (messages.length === 0 || messages[messages.length - 1].role !== 'assistant') && (
              <div className="flex justify-start">
                <div className="bg-white border border-slate-200 rounded-2xl rounded-bl-none px-4 py-3 shadow-sm">
                  <div className="flex gap-1 items-center">
                    <span className="w-2 h-2 bg-emerald-500 rounded-full animate-bounce" style={{ animationDelay: '0ms' }} />
                    <span className="w-2 h-2 bg-emerald-500 rounded-full animate-bounce" style={{ animationDelay: '150ms' }} />
                    <span className="w-2 h-2 bg-emerald-500 rounded-full animate-bounce" style={{ animationDelay: '300ms' }} />
                  </div>
                </div>
              </div>
            )}
          </div>

          {/* Input */}
          <div className="p-4 border-t bg-white flex-shrink-0">
            <div className="flex gap-2">
              <button
                onClick={toggleVoice}
                className={`w-11 h-11 flex items-center justify-center rounded-xl transition-all flex-shrink-0 ${isListening ? 'bg-red-500 text-white animate-pulse' : 'bg-slate-100 hover:bg-slate-200 text-slate-600'}`}
                title={isListening ? 'Stop listening' : 'Voice input'}
              >🎤</button>
              <input
                ref={inputRef}
                value={input}
                onChange={e => setInput(e.target.value)}
                onKeyDown={e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } }}
                placeholder={isListening ? 'Listening…' : 'Ask Aether anything…'}
                disabled={isTyping}
                className="flex-1 border border-slate-300 focus:border-emerald-500 rounded-xl px-4 py-2.5 focus:outline-none text-sm disabled:bg-slate-50"
                maxLength={2000}
              />
              <button
                onClick={sendMessage}
                disabled={!input.trim() || isTyping}
                className="bg-emerald-600 hover:bg-emerald-700 disabled:bg-slate-300 text-white px-5 rounded-xl font-medium active:scale-95"
              >Send</button>
            </div>

            {/* Quick actions */}
            <div className="flex flex-wrap gap-1.5 mt-3">
              {[
                { emoji: '📊', label: 'Dashboard',  prompt: 'Show me dashboard statistics' },
                { emoji: '📄', label: 'Payslip',    prompt: 'Generate a payslip for an employee' },
                { emoji: '🧾', label: 'Receipt',    prompt: 'Generate a donation receipt' },
                { emoji: '💰', label: 'Donations',  prompt: 'List the 10 most recent donations' },
                { emoji: '👤', label: 'Donor',      prompt: 'Get details of a donor' },
                { emoji: '👥', label: 'Employee',   prompt: 'Get details of an employee' },
              ].map(q => (
                <button
                  key={q.label}
                  onClick={() => quickPrompt(q.prompt)}
                  disabled={isTyping}
                  className="px-2.5 py-1 bg-slate-100 hover:bg-emerald-100 hover:text-emerald-700 disabled:opacity-40 rounded-full text-xs transition-colors"
                >
                  {q.emoji} {q.label}
                </button>
              ))}
            </div>
          </div>
        </div>
      )}
    </>
  );
};

export default AetherChat;