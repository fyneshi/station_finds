<?php
/**
 * Authentication & Admin Handler
 * 
 * Processes AJAX requests from the frontend for:
 *   - signup:                         Register a new user
 *   - login:                          Authenticate an existing user (regular or admin)
 *   - forgot_password: { email, contact? }  ask the admins to reset a password (no reset codes are handed out)
 *   - logout:                         Destroy session
 */

session_start();
header('Content-Type: application/json');

require_once 'db_setup.php';

// ── Read JSON request body (with fallback to $_POST) ────────────
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    $input = $_POST;
}
$action = $input['action'] ?? '';

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
                'redirect' => 'home.php'
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

        $stmt = $conn->prepare("SELECT id, username, email, password, role, status FROM users WHERE email = ?");
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

        if (($user['status'] ?? 'active') === 'suspended') {
            echo json_encode(['success' => false, 'message' => 'Your account has been suspended. Contact support@stationfinds.edu.ph for help.']);
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

        $redirect = ($role === 'admin') ? 'admin_dashboard.php' : 'home.php';

        echo json_encode([
            'success'  => true,
            'message'  => $role === 'admin' ? 'Welcome back, Admin!' : 'Login successful!',
            'username' => $user['username'],
            'role'     => $role,
            'redirect' => $redirect
        ]);
        break;

    // ═══════════════════════════════════════════════════════════
    // FORGOT PASSWORD: ask the admins for a reset
    // The site can't send emails, so no reset code is ever handed out here (anyone could have
    // typed someone else's email). The request goes to Admin › Feedback; an admin resets the
    // password in User Management and passes the temporary one on.
    // ═══════════════════════════════════════════════════════════
    case 'forgot_password':
        $email   = trim((string) ($input['email'] ?? ''));
        $contact = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($input['contact'] ?? ''))), 0, 100);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
            exit;
        }

        // Same answer whether or not the email has an account, so this can't be used to check emails
        $answer = ['success' => true, 'message' => 'Request sent. If that email has an account, an admin will reset its password.'];

        $stmt = $conn->prepare("SELECT id, username FROM users WHERE email = ? AND status = 'active'");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$account) { echo json_encode($answer); exit; }

        // One open request per account per hour (stops someone flooding the admins)
        $stmt = $conn->prepare("SELECT 1 FROM feedback WHERE user_id = ? AND subject = 'Password reset request'
                                AND status IN ('pending', 'escalated') AND created_at >= NOW() - INTERVAL 1 HOUR");
        $stmt->bind_param("i", $account['id']);
        $stmt->execute();
        $recent = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$recent) {
            $note = 'Asked to reset their password from the login page.'
                  . ($contact !== '' ? ' Reach them at: ' . $contact . '.' : ' No contact details given.')
                  . ' Reset it in User Management › Manage › Reset password, then pass the temporary password on privately.';
            $stmt = $conn->prepare("INSERT INTO feedback (user_id, name, email, type, subject, message, status)
                                    VALUES (?, ?, ?, 'platform', 'Password reset request', ?, 'pending')");
            $stmt->bind_param("isss", $account['id'], $account['username'], $email, $note);
            $stmt->execute();
            $stmt->close();
        }
        echo json_encode($answer);
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
