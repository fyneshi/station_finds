<?php
/**
 * Reporting listings and users (report_handler.php, js/report.js) and reviewing
 * the reports (admin_moderation.php). Needs db_setup.php included first ($conn).
 */

const SF_REPORT_REASONS = [
    'listing' => ['Suspicious pricing', 'Misleading description', 'Counterfeit item', 'Prohibited item', 'Spam or duplicate listing', 'Other'],
    'user'    => ['Harassment in messages', 'Scam attempt', 'Spam', 'Impersonation', 'Other'],
];
const SF_REPORTS_PER_DAY = 10;   // reports one user can file in 24 hours

// What's being reported: ['name' => ..., 'owner_id' => user who owns it] or null if it doesn't exist
function sf_report_target($conn, $type, $id) {
    if ($type === 'listing') {
        $stmt = $conn->prepare("SELECT title AS name, seller_id AS owner_id FROM listings WHERE id = ?");
    } elseif ($type === 'user') {
        $stmt = $conn->prepare("SELECT username AS name, id AS owner_id FROM users WHERE id = ? AND role <> 'admin'");
    } else {
        return null;
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Files a report. Returns null on success, or a message explaining why not.
function sf_file_report($conn, $reporterId, $type, $targetId, $reason, $details) {
    if (!isset(SF_REPORT_REASONS[$type])) return 'Something went wrong. Please try again.';
    if (!in_array($reason, SF_REPORT_REASONS[$type], true)) return 'Please choose a reason.';
    $details = trim(str_replace("\r\n", "\n", (string) $details));
    if ($reason === 'Other' && $details === '') return 'Please tell us what\'s wrong.';
    if (mb_strlen($details) > 1000) return 'Please keep the details under 1,000 characters.';

    $target = sf_report_target($conn, $type, $targetId);
    if (!$target) return 'That ' . $type . ' no longer exists.';
    if ((int) $target['owner_id'] === (int) $reporterId) return 'You can\'t report your own ' . ($type === 'user' ? 'account' : 'listing') . '.';

    $stmt = $conn->prepare("SELECT
        (SELECT COUNT(*) FROM reports WHERE reporter_id = ? AND target_type = ? AND target_id = ? AND status IN ('open', 'escalated')) AS open_same,
        (SELECT COUNT(*) FROM reports WHERE reporter_id = ? AND created_at > NOW() - INTERVAL 1 DAY) AS today");
    $stmt->bind_param("isii", $reporterId, $type, $targetId, $reporterId);
    $stmt->execute();
    $counts = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($counts['open_same'] > 0) return 'You\'ve already reported this ' . $type . '. Our team is looking into it.';
    if ($counts['today'] >= SF_REPORTS_PER_DAY) return 'You\'ve sent a lot of reports today. Please try again tomorrow.';

    $name = mb_substr($target['name'], 0, 120);
    $stmt = $conn->prepare("INSERT INTO reports (reporter_id, target_type, target_id, target_name, reason, details) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isisss", $reporterId, $type, $targetId, $name, $reason, $details);
    $stmt->execute();
    $stmt->close();
    return null;
}
