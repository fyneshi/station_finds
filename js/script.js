let currentAuthMode = 'login';

// Replays a CSS animation class on an element (see the ANIMATIONS section of css/style.css)
function replayAnimation(el, className) {
  if (!el) return;
  el.classList.remove(className);
  void el.offsetWidth;   // restart
  el.classList.add(className);
}

function showView(viewName) {
  document.querySelectorAll('.view-container').forEach(el => el.classList.remove('active'));
  const activeEl = document.getElementById('view-' + viewName);
  if (activeEl) activeEl.classList.add('active');

  const backBtn = document.getElementById('globalBackBtn');
  backBtn.style.display = (viewName === 'auth' || viewName === 'welcome') ? 'none' : 'inline-flex';
}

function setAuthMode(mode) {
  currentAuthMode = mode;
  const isLogin = mode === 'login';

  const changed = document.getElementById('tabLogin').classList.contains('active') !== isLogin;
  document.getElementById('tabLogin').classList.toggle('active', isLogin);
  document.getElementById('tabSignup').classList.toggle('active', !isLogin);
  document.querySelector('.tab-switcher').classList.toggle('is-signup', !isLogin);   // slides the white pill
  const usernameGroup = document.getElementById('usernameGroup');
  usernameGroup.style.display = isLogin ? 'none' : 'block';
  if (!isLogin) replayAnimation(usernameGroup, 'sf-field-in');
  if (changed) {
    replayAnimation(document.getElementById('authHeading'), 'sf-swap');
    replayAnimation(document.getElementById('authSubtitle'), 'sf-swap');
  }
  document.getElementById('authSubtitle').textContent = isLogin 
    ? 'Log in to continue to your account.' 
    : 'Sign up to continue to your account.';
  document.getElementById('forgotPasswordLink').style.display = isLogin ? 'inline-block' : 'none';
  document.getElementById('authSubmitBtn').textContent = isLogin ? 'Log in' : 'Sign up';
  document.getElementById('footerPrompt').textContent = isLogin ? "Don't have an account?" : 'Already have an account?';
  document.getElementById('footerActionBtn').textContent = isLogin ? 'Sign up' : 'Log in';
}

function toggleAuthMode() {
  setAuthMode(currentAuthMode === 'login' ? 'signup' : 'login');
}

function togglePasswordVisibility(inputId, btn) {
  const input = document.getElementById(inputId);
  const isPassword = input.type === 'password';
  input.type = isPassword ? 'text' : 'password';

  btn.innerHTML = isPassword
    ? `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
        <line x1="1" y1="1" x2="23" y2="23"></line>
       </svg>`
    : `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
        <circle cx="12" cy="12" r="3"></circle>
       </svg>`;
}

// ── Toast Notification ────────────────────────────────────────────
function showToast(message, type = 'success') {
  const toast = document.getElementById('toast');
  toast.textContent = message;
  toast.className = 'toast toast-' + type;
  toast.style.display = 'block';
  if (type === 'error') replayAnimation(document.querySelector('.form-card'), 'sf-shake');

  setTimeout(() => {
    toast.style.display = 'none';
  }, 4000);
}

// ── Helper: Send request to auth_handler.php ──────────────────────
async function sendAuthRequest(data) {
  try {
    const response = await fetch('api/auth_handler.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    return await response.json();
  } catch (error) {
    return { success: false, message: 'Network error. Please try again.' };
  }
}

// ── Sign Up / Log In ──────────────────────────────────────────────
async function handleAuthSubmit(e) {
  e.preventDefault();

  const email    = document.getElementById('authEmail').value;
  const password = document.getElementById('authPassword').value;
  const btn      = document.getElementById('authSubmitBtn');

  // Disable button while processing
  btn.disabled = true;
  btn.textContent = currentAuthMode === 'login' ? 'Logging in…' : 'Signing up…';

  let payload = { action: currentAuthMode, email, password, next: window.SF_NEXT || '' };

  if (currentAuthMode === 'signup') {
    const username = document.getElementById('signupUsername').value;
    if (!username.trim()) {
      showToast('Username is required.', 'error');
      btn.disabled = false;
      btn.textContent = 'Sign up';
      return;
    }
    payload.username = username;
  }

  const result = await sendAuthRequest(payload);

  if (result.success) {
    showToast(result.message, 'success');
    const targetUrl = result.redirect || 'home.php';
    setTimeout(() => {
      window.location.href = targetUrl;
    }, 800);
  } else {
    showToast(result.message, 'error');
    btn.disabled = false;
    btn.textContent = currentAuthMode === 'login' ? 'Log in' : 'Sign up';
  }
}

// ── Forgot Password: email + username + new password (not for admin accounts) ──
async function handleResetSubmit(e) {
  e.preventDefault();
  const email    = document.getElementById('forgotEmail').value.trim();
  const username = document.getElementById('forgotUsername').value.trim();
  const password = document.getElementById('newPassword').value;
  const confirm  = document.getElementById('confirmNewPassword').value;

  if (!email || !username || !password || !confirm) { showToast('Please fill in every field.', 'error'); return; }
  if (password.length < 8) { showToast('Password must be at least 8 characters.', 'error'); return; }
  if (password !== confirm) { showToast('Passwords do not match. Please try again.', 'error'); return; }

  const btn = e.target.querySelector('button[type="submit"]');
  btn.disabled = true;
  const result = await sendAuthRequest({ action: 'reset_password', email, username, new_password: password, confirm_password: confirm });
  btn.disabled = false;

  if (result.success) {
    showToast(result.message, 'success');
    e.target.reset();
    // Back to log in, with the email already filled in
    setTimeout(() => {
      showView('auth');
      setAuthMode('login');
      document.getElementById('authEmail').value = email;
      document.getElementById('authPassword').focus();
    }, 1200);
  } else {
    showToast(result.message, 'error');
  }
}
// ── Logout ───────────────────────────────────────────────────────
async function handleLogout() {
  const result = await sendAuthRequest({ action: 'logout' });
  if (result.success) {
    window.location.href = 'index.php';
  }
}