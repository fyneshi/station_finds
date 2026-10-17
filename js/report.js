/* ==========================================================================
   STATION FINDS — REPORT A LISTING OR USER
   Any button with data-report-type ("listing" | "user"), data-report-id,
   data-report-name and data-report-reasons (JSON list) opens a small form that
   sends the report to report_handler.php. Admins review it in admin_moderation.php.
   Needs js/cart.js (for SFCart.toast).
   ========================================================================== */
(function () {
  let dialog = null;
  let current = null;   // { type, id }

  function build() {
    injectStyles();
    dialog = document.createElement('dialog');
    dialog.className = 'sf-report';
    dialog.setAttribute('aria-labelledby', 'sfReportTitle');
    dialog.innerHTML = `
      <form method="dialog" novalidate>
        <div class="sf-report-head">
          <h2 id="sfReportTitle">Report</h2>
          <button type="button" class="sf-report-close" aria-label="Close">&times;</button>
        </div>
        <p class="sf-report-sub" id="sfReportSub"></p>
        <label class="sf-report-label" for="sfReportReason">Reason</label>
        <select id="sfReportReason" required></select>
        <label class="sf-report-label" for="sfReportDetails">Details <span>(optional)</span></label>
        <textarea id="sfReportDetails" maxlength="1000" placeholder="What did you notice? Links, messages or anything that helps our team."></textarea>
        <p class="sf-report-error" id="sfReportError" role="alert"></p>
        <div class="sf-report-actions">
          <button type="button" class="sf-report-cancel">Cancel</button>
          <button type="submit" class="sf-report-send">Send report</button>
        </div>
      </form>`;
    document.body.appendChild(dialog);

    const close = () => dialog.close();
    dialog.querySelector('.sf-report-close').addEventListener('click', close);
    dialog.querySelector('.sf-report-cancel').addEventListener('click', close);
    dialog.addEventListener('click', e => { if (e.target === dialog) close(); });
    dialog.querySelector('form').addEventListener('submit', send);
  }

  function open(btn) {
    if (!dialog) build();
    current = { type: btn.dataset.reportType, id: Number(btn.dataset.reportId) };
    let reasons = [];
    try { reasons = JSON.parse(btn.dataset.reportReasons || '[]'); } catch (e) { /* none */ }

    document.getElementById('sfReportTitle').textContent = current.type === 'user' ? 'Report user' : 'Report listing';
    document.getElementById('sfReportSub').textContent = 'Reporting "' + btn.dataset.reportName + '". The ' + (current.type === 'user' ? 'user' : 'seller') + ' won\'t see who reported them.';
    const select = document.getElementById('sfReportReason');
    select.innerHTML = '<option value="">Choose a reason…</option>';
    reasons.forEach(r => {
      const opt = document.createElement('option');
      opt.value = r;
      opt.textContent = r;
      select.appendChild(opt);
    });
    document.getElementById('sfReportDetails').value = '';
    document.getElementById('sfReportError').textContent = '';
    dialog.showModal();
    select.focus();
  }

  async function send(e) {
    e.preventDefault();
    const reason = document.getElementById('sfReportReason').value;
    const details = document.getElementById('sfReportDetails').value.trim();
    const error = document.getElementById('sfReportError');
    if (!reason) { error.textContent = 'Please choose a reason.'; return; }
    if (reason === 'Other' && !details) { error.textContent = 'Please tell us what\'s wrong.'; return; }

    const btn = dialog.querySelector('.sf-report-send');
    btn.disabled = true;
    try {
      const res = await fetch('api/report_handler.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ action: 'report', type: current.type, id: current.id, reason: reason, details: details })
      });
      const r = await res.json();
      if (!r.success) {
        error.textContent = r.message || 'Your report could not be sent.';
      } else {
        dialog.close();
        if (window.SFCart) SFCart.toast(r.message);
      }
    } catch (err) {
      error.textContent = 'Could not reach the server. Check your connection and try again.';
    }
    btn.disabled = false;
  }

  function injectStyles() {
    if (document.getElementById('sfReportStyles')) return;
    const style = document.createElement('style');
    style.id = 'sfReportStyles';
    style.textContent = `
      .sf-report { width: min(440px, calc(100% - 32px)); margin: auto; padding: 22px 22px 20px; border: 1px solid #ebdccb; border-radius: 16px;
        background: #fffaf2; color: #18232A; box-shadow: 0 20px 50px rgba(24, 35, 42, .25);
        font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
      .sf-report::backdrop { background: rgba(24, 35, 42, .55); }
      .sf-report-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
      .sf-report-head h2 { font-size: 20px; font-weight: 800; }
      .sf-report-close { width: 32px; height: 32px; border: none; border-radius: 50%; background: #ddd6c9; color: #18232A; font-size: 18px; cursor: pointer; }
      .sf-report-sub { font-size: 13px; color: #64748b; margin: 6px 0 14px; }
      .sf-report-label { display: block; font-size: 13px; font-weight: 700; margin: 12px 0 6px; }
      .sf-report-label span { font-weight: 500; color: #64748b; }
      .sf-report select, .sf-report textarea { width: 100%; padding: 10px 12px; border: 1.5px solid #e5dccd; border-radius: 8px; background: #fffdf8;
        font: inherit; font-size: 14px; color: #18232A; outline: none; }
      .sf-report select:focus, .sf-report textarea:focus { border-color: #d4a843; background: #fff; }
      .sf-report textarea { min-height: 96px; resize: vertical; }
      .sf-report-error { min-height: 18px; margin-top: 8px; font-size: 12.5px; font-weight: 600; color: #dc2626; }
      .sf-report-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; }
      .sf-report-actions button { height: 40px; padding: 0 18px; border-radius: 8px; font: inherit; font-size: 14px; font-weight: 700; cursor: pointer; }
      .sf-report-cancel { border: 1.5px solid #e5dccd; background: #fff; color: #18232A; }
      .sf-report-send { border: none; background: #dc2626; color: #fff; }
      .sf-report-send:disabled { opacity: .6; cursor: not-allowed; }
      .report-link { display: inline-flex; align-items: center; gap: 6px; padding: 0; border: none; background: none; cursor: pointer;
        font: inherit; font-size: 12.5px; font-weight: 600; color: #94a3b8; }
      .report-link:hover { color: #dc2626; text-decoration: underline; }
    `;
    document.head.appendChild(style);
  }

  injectStyles();
  document.addEventListener('click', e => {
    const btn = e.target.closest('[data-report-type]');
    if (btn) { e.preventDefault(); open(btn); }
  });
})();
