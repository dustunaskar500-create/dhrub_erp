/* ============================================================
   Aether — Shared utilities: theme toggle + drag-drop helpers
   Loads early (no defer). Sets data-theme on <html> BEFORE first paint
   to avoid FOUC.
   ============================================================ */
(function () {
  // ── Apply persisted theme synchronously ───────────────────
  const saved = localStorage.getItem('aether-theme');
  const prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
  const theme = saved || (prefersLight ? 'light' : 'dark');
  document.documentElement.dataset.theme = theme;

  // ── Public API ────────────────────────────────────────────
  window.AetherTheme = {
    get current() { return document.documentElement.dataset.theme || 'dark'; },
    set(t) {
      if (t !== 'light' && t !== 'dark') t = 'dark';
      document.documentElement.dataset.theme = t;
      localStorage.setItem('aether-theme', t);
      document.dispatchEvent(new CustomEvent('aether:themechange', { detail: t }));
    },
    toggle() { this.set(this.current === 'dark' ? 'light' : 'dark'); },
    // Wire a button so it stays in sync with the current theme
    bindButton(btn) {
      if (!btn) return;
      btn.classList.add('theme-toggle');
      if (!btn.querySelector('.sun')) {
        btn.innerHTML = '<i class="fa-solid fa-sun sun" aria-hidden="true"></i><i class="fa-solid fa-moon moon" aria-hidden="true"></i>';
      }
      btn.setAttribute('title', 'Toggle light/dark theme');
      btn.setAttribute('aria-label', 'Toggle theme');
      btn.setAttribute('data-testid', btn.getAttribute('data-testid') || 'theme-toggle');
      btn.addEventListener('click', () => this.toggle());
    },
  };

  // ── Drag & drop helper ────────────────────────────────────
  // AetherDnD.attach(rootEl, {
  //   accept: ['image/*','video/*'],     // MIME match patterns, optional
  //   message: 'Drop files to upload',   // overlay message
  //   onFiles: (FileList) => {...},      // required handler
  // });
  let nextOverlayId = 0;
  window.AetherDnD = {
    attach(root, opts) {
      if (!root || !opts || typeof opts.onFiles !== 'function') return () => {};
      const accept = opts.accept || null;
      const id = ++nextOverlayId;
      const overlayId = 'dnd-overlay-' + id;

      let overlay = document.getElementById(overlayId);
      if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = overlayId;
        overlay.className = 'dnd-overlay';
        overlay.innerHTML = `<div class="dnd-card">
          <i class="fa-solid fa-cloud-arrow-up"></i>
          ${escapeHtml(opts.message || 'Drop files to upload')}
          <small>${escapeHtml(opts.hint || 'Photos · videos · documents — released here')}</small>
        </div>`;
        document.body.appendChild(overlay);
      }

      let counter = 0;
      function hasFiles(e) {
        return e.dataTransfer && e.dataTransfer.types && [].slice.call(e.dataTransfer.types).indexOf('Files') > -1;
      }
      function matchAccept(file) {
        if (!accept || !accept.length) return true;
        const mime = file.type || '';
        return accept.some(p => p === mime || (p.endsWith('/*') && mime.startsWith(p.slice(0, -1))));
      }

      function onEnter(e) {
        if (!hasFiles(e)) return;
        e.preventDefault();
        counter++;
        document.body.classList.add('dnd-active');
      }
      function onOver(e) { if (hasFiles(e)) e.preventDefault(); }
      function onLeave(e) {
        if (!hasFiles(e)) return;
        counter = Math.max(0, counter - 1);
        if (counter === 0) document.body.classList.remove('dnd-active');
      }
      function onDrop(e) {
        if (!hasFiles(e)) return;
        e.preventDefault();
        counter = 0;
        document.body.classList.remove('dnd-active');
        const files = [].slice.call(e.dataTransfer.files || []).filter(matchAccept);
        if (files.length) opts.onFiles(files);
        else if ((e.dataTransfer.files || []).length) {
          // Some files were dropped but none accepted
          if (typeof opts.onReject === 'function') opts.onReject(e.dataTransfer.files);
        }
      }

      const events = [
        ['dragenter', onEnter],
        ['dragover',  onOver],
        ['dragleave', onLeave],
        ['drop',      onDrop],
      ];
      events.forEach(([ev, fn]) => root.addEventListener(ev, fn));

      return function detach() {
        events.forEach(([ev, fn]) => root.removeEventListener(ev, fn));
        if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
        document.body.classList.remove('dnd-active');
      };
    },
  };

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));
  }
})();
