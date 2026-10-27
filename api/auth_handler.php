<?php
/**
 * Authentication & Admin Handler
 * 
 * Processes AJAX requests from the frontend for:
 *   - signup:                         Register a new user
 *   - login:                          Authenticate an existing user (regular or admin)
 *   - reset_password:  { email, username, new_password, confirm_password }  forgot password (not for admin accounts)
 *   - support:  { name, email, topic, message }  Contact support from the login page (no login needed),
 *                                     e.g. to appeal a suspended account; admins read it in admin_feedback.php
 *   - logout:                         Destroy session
 */

session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/includes/db_setup.php';

// ── Read JSON request body (with fallback to $_POST) ────────────
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    $input = $_POST;
}
$action = $input['action'] ?? '';

// Contact support from the login page: what people can write about (the subject admins see)
const SF_SUPPORT_TOPICS = [
    'appeal' => 'Appeal a suspended account',
    'access' => 'Can\'t log in to my account',
    'other'  => 'Something else',
];

// A suspended account's appeal still waiting for an admin, for the message shown when they try to log in
function sf_appeal_status($conn, $userId) {
    $stmt = $conn->prepare("SELECT UNIX_TIMESTAMP(created_at) AS ts FROM feedback
                            WHERE user_id = ? AND type = 'platform' AND subject = ? AND status IN ('pending', 'escalated') ORDER BY id DESC LIMIT 1");
    $subject = SF_SUPPORT_TOPICS['appeal'];
    $stmt->bind_param("is", $userId, $subject);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? ' Your appeal from ' . date('M j', $row['ts']) . ' is being reviewed.' : '';
}

switch ($action) {

    // ═══════════════════════════════════════════════════════════
    // SIGN UP
    // ═══════════════════════════════════════════════════════════
    case 'signup':
        $username = trim($input['username'] ?? '');
        $email    = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']);
            exit;
        }

        if (strlen($password) < 8) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
            exit;
        }

        // Check if email or username already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
        $stmt->bind_param("ss", $email, $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Email or username already exists.']);
            $stmt->close();
            exit;
        }
        $stmt->close();

        // Insert user (the password is stored as a hash, never as typed)
        $role = 'user';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $username, $email, $hash, $role);

        if ($stmt->execute()) {
            $_SESSION['user_id']  = $stmt->insert_id;
            $_SESSION['username'] = $username;
            $_SESSION['email']    = $email;
            $_SESSION['role']     = 'user';

            echo json_encode([
                'success'  => true,
                'message'  => 'Account created successfully!',
                'username' => $username,
                'role'     => 'user',
                'redirect' => sf_safe_next($input['next'] ?? '') ?: 'home.php'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Registration failed. Please try again.']);
        }
        $stmt->close();
        break;

    // ═══════════════════════════════════════════════════════════
    // LOG IN (Supports User and Admin)
    // ═══════════════════════════════════════════════════════════
    case 'login':
        $email    = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($email) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
            exit;
        }

        $stmt = $conn->prepare("SELECT id, username, email, password, role, status, UNIX_TIMESTAMP(suspended_until) AS suspended_until FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'No account found with that email.']);
            $stmt->close();
            exit;
        }

        $user = $result->fetch_assoc();
        $stmt->close();

        // Check password against its stored hash (db_setup.php hashes any old plain-text ones)
        $isMatch = password_verify($password, $user['password']);
        if (!$isMatch) {
            echo json_encode(['success' => false, 'message' => 'Incorrect password. Please try again.']);
            exit;
        }

        // Suspended: say so, and open the Contact support form to appeal (js/script.js)
        if (($user['status'] ?? 'active') === 'suspended') {
            echo json_encode(['success' => false, 'suspended' => true,
                'message' => ($user['suspended_until']
                    ? 'Your account is suspended until ' . date('M j, Y', $user['suspended_until']) . ' (' . max(1, (int) ceil(($user['suspended_until'] - time()) / 86400)) . ' more day' . ((int) ceil(($user['suspended_until'] - time()) / 86400) > 1 ? 's' : '') . '), then it turns back on by itself.'
                    : 'Your account has been suspended.')
                    . ' If you think this is a mistake, send an appeal below.' . sf_appeal_status($conn, (int) $user['id'])]);
            exit;
        }

        // Set session
        session_regenerate_id(true);
        $role = $user['role'] ?? 'user';
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email']    = $user['email'];
        $_SESSION['role']     = $role;

        // For the admins' System log (Manage User)
        $loginIp = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $stamp = $conn->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
        $stamp->bind_param("si", $loginIp, $user['id']);
        $stamp->execute();
        $stamp->close();

        $redirect = ($role === 'admin') ? 'admin_dashboard.php' : (sf_safe_next($input['next'] ?? '') ?: 'home.php');

        echo json_encode([
            'success'  => true,
            'message'  => $role === 'admin' ? 'Welcome back, Admin!' : 'Login successful!',
            'username' => $user['username'],
            'role'     => $role,
            'redirect' => $redirect
        ]);
        break;

    // ═══════════════════════════════════════════════════════════
    // FORGOT PASSWORD: reset it yourself with your email + username
    // Kept simple for the project (no emails are sent). Safeguards: both must match, admin
    // accounts can't be reset here, failed tries are limited, and the owner is notified.
    // ═══════════════════════════════════════════════════════════
    case 'reset_password':
        $email    = trim((string) ($input['email'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $new      = (string) ($input['new_password'] ?? '');
        $confirm  = (string) ($input['confirm_password'] ?? '');

        if ($email === '' || $username === '' || $new === '' || $confirm === '') {
            echo json_encode(['success' => false, 'message' => 'Please fill in every field.']);
            exit;
        }
        if (strlen($new) < 8) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
            exit;
        }
        if ($new !== $confirm) {
            echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
            exit;
        }

        // At most 5 wrong email/username pairs per 15 minutes from this browser
        $tries = array_filter($_SESSION['reset_fails'] ?? [], fn($t) => $t > time() - 900);
        if (count($tries) >= 5) {
            echo json_encode(['success' => false, 'message' => 'Too many tries. Please wait 15 minutes, or ask an admin to reset it.']);
            exit;
        }

        $stmt = $conn->prepare("SELECT id, username, role, status FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Same answer for every miss, so this can't be used to find out which emails exist.
        // Admin and suspended accounts can't be reset from the login page.
        if (!$account || strcasecmp($account['username'], $username) !== 0
            || $account['role'] === 'admin' || $account['status'] !== 'active') {
            $tries[] = time();
            $_SESSION['reset_fails'] = array_values($tries);
            echo json_encode(['success' => false, 'message' => "That email and username don't match an account we can reset here."]);
            exit;
        }

        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $account['id']);
        $stmt->execute();
        $stmt->close();
        unset($_SESSION['reset_fails']);

        require_once dirname(__DIR__) . '/includes/inbox.php';
        sf_notify($conn, (int) $account['id'], 'security', 'Your password was changed',
            'Your password was reset from the login page. If this wasn\'t you, change it now and tell us through Contact us.', 'account.php?tab=credentials');

        echo json_encode(['success' => true, 'message' => 'Password reset! You can log in with your new password.']);
        break;

    // ═══════════════════════════════════════════════════════════
    // CONTACT SUPPORT (login page): works without logging in, so a suspended
    // user can appeal. Saved as platform feedback for the admins.
    // ═══════════════════════════════════════════════════════════
    case 'support':
        $name    = trim(preg_replace('/\s+/', ' ', (string) ($input['name'] ?? '')));
        $email   = trim((string) ($input['email'] ?? ''));
        $topic   = (string) ($input['topic'] ?? '');
        $message = trim(str_replace("\r\n", "\n", (string) ($input['message'] ?? '')));

        $error = $name === '' || mb_strlen($name) > 100 ? 'Please enter your name.'
            : (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255 ? 'Please enter a valid email address.'
            : (!isset(SF_SUPPORT_TOPICS[$topic]) ? 'Please choose what you need help with.'
            : (mb_strlen($message) < 20 ? 'Please tell us a bit more (at least 20 characters).'
            : (mb_strlen($message) > 1000 ? 'Please keep your message under 1,000 characters.' : ''))));
        if ($error) {
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }

        // At most 3 messages an hour from this browser, and 3 a day for one email address
        $sent = array_filter($_SESSION['support_sent'] ?? [], fn($t) => $t > time() - 3600);
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM feedback WHERE type = 'platform' AND email = ? AND created_at > NOW() - INTERVAL 1 DAY
                                AND subject IN ('" . implode("', '", array_map([$conn, 'real_escape_string'], SF_SUPPORT_TOPICS)) . "')");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $today = (int) $stmt->get_result()->fetch_assoc()['n'];
        $stmt->close();
        if (count($sent) >= 3 || $today >= 3) {
            echo json_encode(['success' => false, 'message' => 'We already have your messages. Please wait for our team to review them before sending more.']);
            exit;
        }

        // Linked to the account with that email, if there is one (Feedback shows who it's from)
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND role <> 'admin'");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $userId  = $account ? (int) $account['id'] : null;
        $subject = SF_SUPPORT_TOPICS[$topic];

        $stmt = $conn->prepare("INSERT INTO feedback (user_id, name, email, type, subject, message) VALUES (?, ?, ?, 'platform', ?, ?)");
        $stmt->bind_param("issss", $userId, $name, $email, $subject, $message);
        $stmt->execute();
        $stmt->close();
        $sent[] = time();
        $_SESSION['support_sent'] = array_values($sent);

        echo json_encode(['success' => true, 'message' => $topic === 'appeal'
            ? 'Your appeal was sent. An admin will review it; if they lift the suspension, you can log in again.'
            : 'Your message was sent. Our team will look into it.']);
        break;

    // ═══════════════════════════════════════════════════════════
    // LOGOUT
    // ═══════════════════════════════════════════════════════════
    case 'logout':
        session_destroy();
        echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
        break;
}

$conn->close();
