<?php
session_start();
require_once __DIR__ . '/includes/db_setup.php';

// ?next=page.php: where to go after logging in (e.g. the listing a guest was looking at); ?mode=signup opens Sign up
$next = sf_safe_next($_GET['next'] ?? '');
$startMode = ($_GET['mode'] ?? '') === 'signup' ? 'signup' : 'login';

if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        header('Location: admin_dashboard.php');
    } else {
        header('Location: ' . ($next ?: 'home.php'));
    }
    exit;
}

// Visitors who just open the site (from a search engine, a shared link or the address bar) start by
// browsing as a guest, like most shops. This page shows only when they choose to log in or sign up:
// index.php?mode=login | ?mode=signup | ?next=page.php
if (($_SERVER['QUERY_STRING'] ?? '') === '') {
    header('Location: home.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Log in or sign up — Station Finds</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css?v=11">
</head>
<body>
  <div class="app-container">

    <!-- LEFT BRANDING PANEL -->
    <div class="brand-panel">
      <div class="bg-circle circle-1"></div>
      <div class="bg-circle circle-2"></div>
      <div class="bg-circle circle-3"></div>

      <!-- Brand Logo -->
       <div class="brand-logo-container">
        <!-- The logo goes to the home page, browsing as a guest (or back to the page they came from) -->
        <a href="<?php echo htmlspecialchars($next ?: 'home.php'); ?>" class="brand-logo-badge" title="Browse Station Finds as a guest">
            <img src="assets/images/logo.png" alt="Station Finds — browse as a guest" class="brand-logo-img" />
        </a>
    </div>

      <div class="brand-hero">
        <h1>Buy, rent, and sell right in your neighborhood.</h1>
        <p>Join thousands of neighbors already trading nearby.</p>

        <div class="features-list">
          <div class="feature-item">
            <div class="feature-icon">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <path d="M16 10a4 4 0 0 1-8 0"></path>
              </svg>
            </div>
            <span class="feature-text">Buy and sell products</span>
          </div>

          <div class="feature-item">
            <div class="feature-icon">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="7.5" cy="15.5" r="5.5"></circle>
                <path d="m21 2-9.6 9.6"></path>
                <path d="m15.5 7.5 3 3L22 7l-3-3"></path>
              </svg>
            </div>
            <span class="feature-text">Rent items nearby</span>
          </div>

          <div class="feature-item">
            <div class="feature-icon">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
              </svg>
            </div>
            <span class="feature-text">Book local services</span>
          </div>
        </div>
      </div>

      <div class="brand-footer">
        &copy; 2026 Station Finds Inc. All rights reserved.
      </div>
    </div>

    <!-- RIGHT FORM PANEL -->
    <div class="form-panel">
      <div class="form-card">

        <!-- Toast notification for success/error messages -->
        <div id="toast" class="toast" style="display: none;"></div>

        <!-- VIEW 0: WELCOME (LOGGED IN) -->
        <?php if (isset($_SESSION['user_id'])): ?>
        <div id="view-welcome" class="view-container active">
          <h2 class="card-title">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h2>
          <p class="card-subtitle">You are logged in as <strong><?php echo htmlspecialchars($_SESSION['email']); ?></strong></p>
          <button type="button" class="btn-primary" onclick="handleLogout()">Log out</button>
        </div>
        <?php endif; ?>

        <button id="globalBackBtn" class="back-btn" style="display: none;" onclick="showView('auth'); setAuthMode('login');">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="15 18 9 12 15 6"></polyline>
          </svg>
          Back to log in
        </button>

        <!-- VIEW 1: AUTH (LOG IN & SIGN UP) -->
        <div id="view-auth" class="view-container <?php echo isset($_SESSION['user_id']) ? '' : 'active'; ?>">
          <?php if (!empty($_GET['suspended'])): ?>
          <!-- Sent here after being suspended while logged in (db_setup.php) -->
          <div class="auth-notice" role="alert">Your account has been suspended. If you think this is a mistake, <button type="button" class="link-action" onclick="openSupport('appeal')">send an appeal</button>.</div>
          <?php endif; ?>
          <h2 id="authHeading" class="card-title">Welcome back</h2>
          <p id="authSubtitle" class="card-subtitle">Log in to continue to your account.</p>

          <div class="tab-switcher">
            <button id="tabLogin" class="tab-btn active" onclick="setAuthMode('login')">Log in</button>
            <button id="tabSignup" class="tab-btn" onclick="setAuthMode('signup')">Sign up</button>
          </div>

          <form id="authForm" onsubmit="handleAuthSubmit(event)">
            <div id="firstNameGroup" class="form-group" style="display: none;">
              <label class="form-label">First name</label>
              <input type="text" id="signupFirstName" class="form-input" placeholder="First Name" />
            </div>

            <div id="lastNameGroup" class="form-group" style="display: none;">
              <label class="form-label">Last name</label>
              <input type="text" id="signupLastName" class="form-input" placeholder="Last Name" />
            </div>

            <div id="usernameGroup" class="form-group" style="display: none;">
              <label class="form-label">Username</label>
              <input type="text" id="signupUsername" class="form-input" placeholder="Username" />
            </div>

            <div class="form-group">
              <label class="form-label">Email</label>
              <input type="email" id="authEmail" class="form-input" placeholder="name@email.com" required />
            </div>

            <div class="form-group">
              <div class="form-group-header">
                <label class="form-label">Password</label>
                <button type="button" id="forgotPasswordLink" class="link-forgot" onclick="showView('forgot')">Forgot password?</button>
              </div>
              <div class="input-wrapper">
                <input type="password" id="authPassword" class="form-input" placeholder="Enter your password" required />
                <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('authPassword', this)">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </div>
            </div>

            <div id="confirmPasswordGroup" class="form-group" style="display: none;">
              <label class="form-label">Confirm Password</label>
              <div class="input-wrapper">
                <input type="password" id="signupConfirmPassword" class="form-input" placeholder="Re-enter your password" />
                <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('signupConfirmPassword', this)">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </div>
            </div>

            <button type="submit" id="authSubmitBtn" class="btn-primary">Log in</button>
          </form>

          <div class="card-footer-text">
            <span id="footerPrompt">Don't have an account?</span><br>
            <button type="button" id="footerActionBtn" class="link-action" onclick="toggleAuthMode()">Sign up</button>
          </div>
          <p class="support-line">Can't log in, or was your account suspended? <button type="button" class="link-action" onclick="openSupport()">Contact support</button></p>
        </div>

        <!-- VIEW 2: FORGOT PASSWORD -->
        <div id="view-forgot" class="view-container">
          <h2 class="card-title">Reset your password</h2>
          <p class="card-subtitle">
            Enter your account's email and username, then choose a new password.
          </p>

          <form onsubmit="handleResetSubmit(event)" novalidate>
            <div class="form-group">
              <label class="form-label" for="forgotEmail">Email</label>
              <input type="email" id="forgotEmail" class="form-input" placeholder="name@email.com" autocomplete="email" required />
            </div>
            <div class="form-group">
              <label class="form-label" for="forgotUsername">Username</label>
              <input type="text" id="forgotUsername" class="form-input" placeholder="Your username" autocomplete="username" required />
            </div>
            <div class="form-group">
              <label class="form-label" for="newPassword">New password</label>
              <div class="input-wrapper">
                <input type="password" id="newPassword" class="form-input" placeholder="At least 8 characters" autocomplete="new-password" required />
                <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('newPassword', this)" aria-label="Show password">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                </button>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label" for="confirmNewPassword">Confirm new password</label>
              <div class="input-wrapper">
                <input type="password" id="confirmNewPassword" class="form-input" placeholder="Re-enter new password" autocomplete="new-password" required />
                <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('confirmNewPassword', this)" aria-label="Show password">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                </button>
              </div>
            </div>

            <button type="submit" class="btn-primary">Reset password</button>
          </form>

          <div class="card-footer-text">
            Forgot your username too? <button type="button" class="link-action" onclick="openSupport('access')">Contact support</button>
            <br>
            Remembered your password?
            <button type="button" class="link-action" onclick="showView('auth'); setAuthMode('login');">Log in</button>
          </div>
        </div>

        <!-- VIEW 3: CONTACT SUPPORT (no login needed, e.g. to appeal a suspension). Admins read it in Feedback. -->
        <div id="view-support" class="view-container">
          <h2 class="card-title">Contact support</h2>
          <p class="card-subtitle">Tell us what's wrong. An admin reads every message.</p>

          <form id="supportForm" onsubmit="handleSupportSubmit(event)" novalidate>
            <div class="form-group">
              <label class="form-label" for="supportTopic">What do you need help with?</label>
              <select id="supportTopic" class="form-input" required>
                <option value="appeal">Appeal a suspended account</option>
                <option value="access">Can't log in to my account</option>
                <option value="other">Something else</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="supportName">Your name</label>
              <input type="text" id="supportName" class="form-input" maxlength="100" placeholder="Full name" autocomplete="name" required />
            </div>
            <div class="form-group">
              <label class="form-label" for="supportEmail">Email on your account</label>
              <input type="email" id="supportEmail" class="form-input" maxlength="255" placeholder="name@email.com" autocomplete="email" required />
            </div>
            <div class="form-group">
              <label class="form-label" for="supportMessage">Message</label>
              <textarea id="supportMessage" class="form-input form-textarea" maxlength="1000" rows="5" placeholder="e.g. Why your account should be reactivated, or what happens when you try to log in." required></textarea>
            </div>
            <button type="submit" class="btn-primary">Send message</button>
          </form>

          <div class="card-footer-text" id="supportDone" hidden></div>
        </div>

      </div>
    </div>
  </div>

  <script>window.SF_NEXT = <?php echo json_encode($next); ?>;</script>
  <script src="js/script.js?v=10"></script>
  <?php if ($startMode === 'signup'): ?><script>setAuthMode('signup');</script><?php endif; ?>
</body>
</html>