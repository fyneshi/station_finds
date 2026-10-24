<?php
/**
 * Database Setup & Connection
 *
 * This file creates the MySQL connection and auto-creates the
 * `station_finds` database and required tables if they don't exist.
 *
 * Default XAMPP credentials are used (root, no password).
 */

// Link to a page in the project root, from any folder (handlers in api/ are one level down)
function sf_root_url($page) {
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (basename($dir) === 'api') $dir = dirname($dir);
    return rtrim($dir, '/') . '/' . $page;
}

// A page to come back to after logging in (index.php?next=…): only this site's own buyer pages
function sf_safe_next($next) {
    $next = trim((string) $next);
    if (!preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $next)) return '';
    $page = strtok($next, '?');
    if (in_array($page, ['index.php', 'logout.php'], true) || strpos($page, 'admin_') === 0) return '';
    return is_file(dirname(__DIR__) . '/' . $page) ? $next : '';
}

// ── Database Configuration ──────────────────────────────────────
// On a web host, its database details go in includes/config.php (see config.sample.php;
// it's kept out of Git). Without that file, the XAMPP defaults below are used.
if (is_file(__DIR__ . '/config.php')) require __DIR__ . '/config.php';
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');           // Default XAMPP: no password
defined('DB_NAME') || define('DB_NAME', 'station_finds');

// ── Connect to MySQL Server ─────────────────────────────────────
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

if ($conn->connect_error) {
    die(json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $conn->connect_error
    ]));
}

// ── Create Database if it doesn't exist ─────────────────────────
// Web hosts create the database in their control panel and don't allow this, so it's skipped there
try {
    $conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (mysqli_sql_exception $e) {
    // no permission: the database already exists on the host
}
try {
    $found = $conn->select_db(DB_NAME);
} catch (mysqli_sql_exception $e) {
    $found = false;
}
if (!$found) {
    die(json_encode(['success' => false, 'message' => 'Database "' . DB_NAME . '" was not found. Check includes/config.php.']));
}
$conn->set_charset('utf8mb4');

// ── Create Users Table if it doesn't exist ──────────────────────
$createTable = "CREATE TABLE IF NOT EXISTS `users` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `username`        VARCHAR(50)  NOT NULL UNIQUE,
    `email`           VARCHAR(255) NOT NULL UNIQUE,
    `password`        VARCHAR(255) NOT NULL,
    `role`            VARCHAR(20)  NOT NULL DEFAULT 'user',
    `reset_token`     VARCHAR(255) DEFAULT NULL,
    `reset_expires`   DATETIME     DEFAULT NULL,
    `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($createTable)) {
    die(json_encode([
        'success' => false,
        'message' => 'Table creation failed: ' . $conn->error
    ]));
}

// Check if role column exists in users table (in case table was created earlier without it)
$checkRoleCol = $conn->query("SHOW COLUMNS FROM `users` LIKE 'role'");
if ($checkRoleCol && $checkRoleCol->num_rows === 0) {
    $conn->query("ALTER TABLE `users` ADD COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'user' AFTER `password`");
}

// ── Create Favorites Table if it doesn't exist ──────────────────
// One row per (user, listing). A snapshot of the listing is stored so the
// Favorites page can render items saved from any page (home, marketplace, search).
$createFavorites = "CREATE TABLE IF NOT EXISTS `favorites` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT          NOT NULL,
    `item_key`       VARCHAR(191) NOT NULL,
    `item_type`      VARCHAR(20)  NOT NULL DEFAULT 'products',
    `title`          VARCHAR(255) NOT NULL,
    `price`          DECIMAL(10,2) NOT NULL DEFAULT 0,
    `price_display`  VARCHAR(60)  NOT NULL DEFAULT '',
    `rating`         DECIMAL(2,1) NOT NULL DEFAULT 0,
    `badge_sub`      VARCHAR(60)  NOT NULL DEFAULT '',
    `image`          VARCHAR(500) NOT NULL DEFAULT '',
    `link`           VARCHAR(255) NOT NULL DEFAULT '',
    `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_user_item` (`user_id`, `item_key`),
    CONSTRAINT `fk_favorites_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($createFavorites)) {
    die(json_encode([
        'success' => false,
        'message' => 'Favorites table creation failed: ' . $conn->error
    ]));
}

// ── Create Cart & Order Tables if they don't exist ─────────────
// cart_items: one row per (user, listing). `units` = rental days or service hours (1 for products).
$cartTables = [
    "CREATE TABLE IF NOT EXISTS `cart_items` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `user_id`     INT          NOT NULL,
        `item_key`    VARCHAR(64)  NOT NULL,
        `quantity`    INT          NOT NULL DEFAULT 1,
        `units`       INT          NOT NULL DEFAULT 1,
        `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_user_cart_item` (`user_id`, `item_key`),
        CONSTRAINT `fk_cart_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `orders` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `order_no`        VARCHAR(20)   NOT NULL UNIQUE,
        `user_id`         INT           NOT NULL,
        `full_name`       VARCHAR(100)  NOT NULL,
        `phone`           VARCHAR(20)   NOT NULL,
        `address_line1`   VARCHAR(255)  NOT NULL,
        `address_line2`   VARCHAR(255)  NOT NULL DEFAULT '',
        `city`            VARCHAR(100)  NOT NULL,
        `province`        VARCHAR(100)  NOT NULL,
        `zip_code`        VARCHAR(10)   NOT NULL,
        `payment_method`  VARCHAR(20)   NOT NULL,
        `promo_code`      VARCHAR(30)   NOT NULL DEFAULT '',
        `subtotal`        DECIMAL(10,2) NOT NULL,
        `discount`        DECIMAL(10,2) NOT NULL DEFAULT 0,
        `shipping`        DECIMAL(10,2) NOT NULL,
        `tax`             DECIMAL(10,2) NOT NULL,
        `total`           DECIMAL(10,2) NOT NULL,
        `status`          VARCHAR(20)   NOT NULL DEFAULT 'pending',
        `created_at`      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `order_items` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `order_id`    INT           NOT NULL,
        `item_key`    VARCHAR(64)   NOT NULL,
        `item_type`   VARCHAR(20)   NOT NULL,
        `title`       VARCHAR(255)  NOT NULL,
        `seller`      VARCHAR(100)  NOT NULL DEFAULT '',
        `unit_price`  DECIMAL(10,2) NOT NULL,
        `units`       INT           NOT NULL DEFAULT 1,
        `quantity`    INT           NOT NULL DEFAULT 1,
        `line_total`  DECIMAL(10,2) NOT NULL,
        CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
foreach ($cartTables as $sql) {
    if (!$conn->query($sql)) {
        die(json_encode([
            'success' => false,
            'message' => 'Cart/order table creation failed: ' . $conn->error
        ]));
    }
}

// ── Create Messages & Notifications Tables if they don't exist ──
// messages: one row per chat message between two users (read_at = when the receiver opened it).
// notifications: one row per alert shown on notifications.php (type picks the icon).
$inboxTables = [
    "CREATE TABLE IF NOT EXISTS `messages` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `sender_id`    INT          NOT NULL,
        `receiver_id`  INT          NOT NULL,
        `body`         TEXT         NOT NULL,
        `read_at`      TIMESTAMP    NULL DEFAULT NULL,
        `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_pair` (`sender_id`, `receiver_id`, `id`),
        KEY `idx_receiver_unread` (`receiver_id`, `read_at`),
        CONSTRAINT `fk_messages_sender`   FOREIGN KEY (`sender_id`)   REFERENCES `users`(`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_messages_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `notifications` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `user_id`      INT          NOT NULL,
        `type`         VARCHAR(20)  NOT NULL DEFAULT 'info',
        `title`        VARCHAR(150) NOT NULL,
        `body`         VARCHAR(500) NOT NULL DEFAULT '',
        `link`         VARCHAR(255) NOT NULL DEFAULT '',
        `read_at`      TIMESTAMP    NULL DEFAULT NULL,
        `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_user_created` (`user_id`, `created_at`),
        CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
foreach ($inboxTables as $sql) {
    if (!$conn->query($sql)) {
        die(json_encode([
            'success' => false,
            'message' => 'Messages/notifications table creation failed: ' . $conn->error
        ]));
    }
}

// ── Create Seller Tables if they don't exist ────────────────────
// seller_profiles: filled in by the "Become a Seller" application (one row per seller).
// listings: products / rentals / services posted by sellers. Shown in the shop as
//           catalog item "listing-<id>" while status = 'active'.
$sellerTables = [
    "CREATE TABLE IF NOT EXISTS `seller_profiles` (
        `user_id`         INT          NOT NULL PRIMARY KEY,
        `store_name`      VARCHAR(80)  NOT NULL,
        `category`        VARCHAR(30)  NOT NULL,
        `contact_number`  VARCHAR(20)  NOT NULL,
        `seller_type`     VARCHAR(20)  NOT NULL DEFAULT 'individual',
        `about`           VARCHAR(1000) NOT NULL DEFAULT '',
        `school`          VARCHAR(120) NOT NULL,
        `student_id`      VARCHAR(30)  NOT NULL,
        `payout_method`   VARCHAR(20)  NOT NULL,
        `payout_name`     VARCHAR(100) NOT NULL,
        `payout_account`  VARCHAR(40)  NOT NULL,
        `payout_bank`     VARCHAR(60)  NOT NULL DEFAULT '',
        `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT `fk_seller_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `listings` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `seller_id`    INT           NOT NULL,
        `type`         VARCHAR(20)   NOT NULL,
        `title`        VARCHAR(100)  NOT NULL,
        `category`     VARCHAR(30)   NOT NULL,
        `term`         VARCHAR(30)   NOT NULL DEFAULT '',
        `price`        DECIMAL(10,2) NOT NULL,
        `unit`         VARCHAR(20)   NOT NULL DEFAULT '',
        `description`  VARCHAR(1000) NOT NULL DEFAULT '',
        `image`        VARCHAR(255)  NOT NULL DEFAULT '',
        `status`       VARCHAR(20)   NOT NULL DEFAULT 'active',
        `created_at`   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        `updated_at`   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_seller` (`seller_id`),
        KEY `idx_status` (`status`),
        CONSTRAINT `fk_listings_seller` FOREIGN KEY (`seller_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
// payout_requests: a seller asks to withdraw part of their released earnings; an admin
// marks it paid, flagged (held for review) or rejected (money goes back to the balance).
// `destination` keeps a copy of where the money was to be sent at the time of the request.
$sellerTables[] = "CREATE TABLE IF NOT EXISTS `payout_requests` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `seller_id`     INT           NOT NULL,
    `amount`        DECIMAL(10,2) NOT NULL,
    `method`        VARCHAR(20)   NOT NULL,
    `destination`   VARCHAR(120)  NOT NULL,
    `status`        VARCHAR(20)   NOT NULL DEFAULT 'pending',
    `admin_note`    VARCHAR(255)  NOT NULL DEFAULT '',
    `created_at`    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    `processed_at`  TIMESTAMP     NULL DEFAULT NULL,
    KEY `idx_seller_status` (`seller_id`, `status`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_payout_seller` FOREIGN KEY (`seller_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
// categories: listing categories per type, managed by admins (admin_categories.php).
// `slug` is what listings store and what the marketplace filters use.
$sellerTables[] = "CREATE TABLE IF NOT EXISTS `categories` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `type`        VARCHAR(20)  NOT NULL,
    `slug`        VARCHAR(40)  NOT NULL,
    `name`        VARCHAR(40)  NOT NULL,
    `status`      VARCHAR(20)  NOT NULL DEFAULT 'active',
    `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_type_slug` (`type`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// reports: users reporting a listing or another user (report_handler.php), reviewed in admin_moderation.php.
// `target_name` keeps the listing title / username in case it's deleted later.
// status: open | escalated | resolved | dismissed
$sellerTables[] = "CREATE TABLE IF NOT EXISTS `reports` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `reporter_id`  INT           NOT NULL,
    `target_type`  VARCHAR(10)   NOT NULL,
    `target_id`    INT           NOT NULL,
    `target_name`  VARCHAR(120)  NOT NULL,
    `reason`       VARCHAR(60)   NOT NULL,
    `details`      VARCHAR(1000) NOT NULL DEFAULT '',
    `status`       VARCHAR(20)   NOT NULL DEFAULT 'open',
    `outcome`      VARCHAR(30)   NOT NULL DEFAULT '',
    `admin_note`   VARCHAR(255)  NOT NULL DEFAULT '',
    `created_at`   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    `resolved_at`  TIMESTAMP     NULL DEFAULT NULL,
    KEY `idx_status` (`status`),
    KEY `idx_target` (`target_type`, `target_id`),
    CONSTRAINT `fk_reports_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// report_exports: a log of every report an admin downloaded from admin_reports.php
$sellerTables[] = "CREATE TABLE IF NOT EXISTS `report_exports` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id`     INT          NULL,
    `admin_name`   VARCHAR(100) NOT NULL,
    `report_type`  VARCHAR(20)  NOT NULL,
    `name`         VARCHAR(120) NOT NULL,
    `period`       VARCHAR(20)  NOT NULL,
    `format`       VARCHAR(10)  NOT NULL DEFAULT 'csv',
    `row_count`    INT          NOT NULL DEFAULT 0,
    `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_created` (`created_at`),
    CONSTRAINT `fk_report_admin` FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// admin_logs: every action an admin takes (sf_admin_log in admin_layout.php), shown in admin_logs.php.
// kind picks the icon: seller | user | listing | payout | report | feedback | dispute | category | export
// critical = actions worth a second look (suspensions, deletions, refunds, new admins…)
$logTable = $conn->query("SHOW TABLES LIKE 'admin_logs'");
$hadAdminLogs = $logTable && $logTable->num_rows > 0;
$sellerTables[] = "CREATE TABLE IF NOT EXISTS `admin_logs` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id`    INT          NULL,
    `admin_name`  VARCHAR(100) NOT NULL,
    `kind`        VARCHAR(20)  NOT NULL,
    `action`      VARCHAR(120) NOT NULL,
    `target`      VARCHAR(255) NOT NULL DEFAULT '',
    `badge`       VARCHAR(20)  NOT NULL DEFAULT '',
    `critical`    TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_created` (`created_at`),
    CONSTRAINT `fk_log_admin` FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// admin_notice_reads: notifications an admin has opened in admin_notifications.php.
// "Mark all as read" sets users.notices_seen_at instead; anything older counts as read.
$sellerTables[] = "CREATE TABLE IF NOT EXISTS `admin_notice_reads` (
    `admin_id`    INT         NOT NULL,
    `event_key`   VARCHAR(80) NOT NULL,
    `read_at`     TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`admin_id`, `event_key`),
    CONSTRAINT `fk_notice_admin` FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// listing_comments: public questions on a listing page and replies to them (comments.php).
// parent_id = the question a reply answers (one level deep).
$sellerTables[] = "CREATE TABLE IF NOT EXISTS `listing_comments` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `listing_id`  INT           NOT NULL,
    `user_id`     INT           NOT NULL,
    `parent_id`   INT           NULL,
    `body`        VARCHAR(1000) NOT NULL,
    `created_at`  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_listing` (`listing_id`, `created_at`),
    CONSTRAINT `fk_comment_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_comment_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_comment_parent` FOREIGN KEY (`parent_id`) REFERENCES `listing_comments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// Messages extras (messages.php): reactions, and messages someone removed just for themselves.
// (messages.reply_to / unsent_at are added below.)
$sellerTables[] = "CREATE TABLE IF NOT EXISTS `message_reactions` (
    `message_id`  INT         NOT NULL,
    `user_id`     INT         NOT NULL,
    `emoji`       VARCHAR(16) NOT NULL,
    `created_at`  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`message_id`, `user_id`),
    CONSTRAINT `fk_reaction_message` FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reaction_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$sellerTables[] = "CREATE TABLE IF NOT EXISTS `message_hidden` (
    `user_id`     INT NOT NULL,
    `message_id`  INT NOT NULL,
    PRIMARY KEY (`user_id`, `message_id`),
    CONSTRAINT `fk_hidden_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hidden_message` FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// promo_codes: discount codes for the cart, managed in admin_promos.php (checked by sf_promo_check in shop.php).
// type: percent (value = % off the items) | fixed (value = pesos off) | free_shipping
$hadPromoTable = ($promoTable = $conn->query("SHOW TABLES LIKE 'promo_codes'")) && $promoTable->num_rows > 0;
$sellerTables[] = "CREATE TABLE IF NOT EXISTS `promo_codes` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `code`           VARCHAR(20)   NOT NULL,
    `type`           VARCHAR(20)   NOT NULL DEFAULT 'percent',
    `value`          DECIMAL(10,2) NOT NULL DEFAULT 0,
    `min_order`      DECIMAL(10,2) NOT NULL DEFAULT 0,
    `max_uses`       INT           NULL,
    `once_per_user`  TINYINT(1)    NOT NULL DEFAULT 1,
    `expires_on`     DATE          NULL,
    `status`         VARCHAR(20)   NOT NULL DEFAULT 'active',
    `created_at`     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// feedback: everything users tell us, reviewed in admin_feedback.php.
//   type 'platform' — Contact us form (contact.php)
//   type 'buyer'    — a buyer rating a finished order; also the listing's public star rating
//   type 'seller'   — a seller rating the buyer of a finished order
// status: pending | escalated | resolved | flagged (hidden, e.g. an abusive review) | dismissed
$sellerTables[] = "CREATE TABLE IF NOT EXISTS `feedback` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT           NULL,
    `name`           VARCHAR(100)  NOT NULL,
    `email`          VARCHAR(255)  NOT NULL,
    `type`           VARCHAR(20)   NOT NULL,
    `subject`        VARCHAR(120)  NOT NULL,
    `message`        TEXT          NOT NULL,
    `rating`         TINYINT       NULL,
    `order_item_id`  INT           NULL,
    `listing_key`    VARCHAR(64)   NULL,
    `about_user_id`  INT           NULL,
    `status`         VARCHAR(20)   NOT NULL DEFAULT 'pending',
    `admin_note`     VARCHAR(255)  NOT NULL DEFAULT '',
    `created_at`     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    `resolved_at`    TIMESTAMP     NULL DEFAULT NULL,
    UNIQUE KEY `uniq_order_review` (`type`, `order_item_id`),
    KEY `idx_status` (`status`),
    KEY `idx_listing` (`listing_key`),
    KEY `idx_user_created` (`user_id`, `created_at`),
    CONSTRAINT `fk_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
foreach ($sellerTables as $sql) {
    if (!$conn->query($sql)) {
        die(json_encode([
            'success' => false,
            'message' => 'Seller table creation failed: ' . $conn->error
        ]));
    }
}

// Contact us messages used to live in `contact_messages`; move them into `feedback` once
$oldContact = $conn->query("SHOW TABLES LIKE 'contact_messages'");
if ($oldContact && $oldContact->num_rows > 0) {
    $moved = $conn->query("INSERT INTO `feedback` (user_id, name, email, type, subject, message, status, created_at, resolved_at)
        SELECT user_id, full_name, email, 'platform', 'Contact form message', message,
               IF(status = 'resolved', 'resolved', 'pending'), created_at, IF(status = 'resolved', created_at, NULL)
        FROM `contact_messages`");
    if ($moved) $conn->query("DROP TABLE `contact_messages`");
}

// First time the audit log exists: fill it from decisions admins already made, so it isn't empty.
// Who made them wasn't recorded back then, so they're credited to the first admin.
if (!$hadAdminLogs) {
    $firstAdmin = $conn->query("SELECT id, username FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch_assoc();
    $aid  = $firstAdmin ? (int) $firstAdmin['id'] : 'NULL';
    $anm  = $conn->real_escape_string($firstAdmin['username'] ?? 'Admin');
    $conn->query("INSERT INTO admin_logs (admin_id, admin_name, kind, action, target, badge, critical, created_at)
        SELECT $aid, '$anm', 'seller', IF(status = 'approved', 'Approved seller application', 'Rejected seller application'),
               store_name, UPPER(status), 0, reviewed_at
        FROM seller_profiles WHERE reviewed_at IS NOT NULL AND status IN ('approved', 'rejected')");
    $conn->query("INSERT INTO admin_logs (admin_id, admin_name, kind, action, target, badge, critical, created_at)
        SELECT $aid, '$anm', 'payout', CASE p.status WHEN 'paid' THEN 'Processed payout' WHEN 'flagged' THEN 'Flagged payout' ELSE 'Rejected payout' END,
               CONCAT('₱', FORMAT(p.amount, 0), ' to ', COALESCE(s.store_name, u.username)),
               CASE p.status WHEN 'paid' THEN 'DONE' ELSE UPPER(p.status) END, p.status = 'rejected', p.processed_at
        FROM payout_requests p JOIN users u ON u.id = p.seller_id LEFT JOIN seller_profiles s ON s.user_id = p.seller_id
        WHERE p.processed_at IS NOT NULL AND p.status IN ('paid', 'flagged', 'rejected')");
    $conn->query("INSERT INTO admin_logs (admin_id, admin_name, kind, action, target, badge, critical, created_at)
        SELECT $aid, '$anm', 'report', IF(status = 'resolved', 'Resolved report', 'Dismissed report'), target_name, UPPER(status), 0, resolved_at
        FROM reports WHERE resolved_at IS NOT NULL AND status IN ('resolved', 'dismissed')");
    $conn->query("INSERT INTO admin_logs (admin_id, admin_name, kind, action, target, badge, critical, created_at)
        SELECT admin_id, admin_name, 'export', 'Exported report', name, 'CSV', 0, created_at FROM report_exports");
}

// The two codes the cart always had become editable promo codes
if (!$hadPromoTable) {
    $conn->query("INSERT IGNORE INTO promo_codes (code, type, value, once_per_user) VALUES ('STUDENT10', 'percent', 10, 0), ('FREESHIP', 'free_shipping', 0, 0)");
}

// Orders made before cents were kept: subtotal and discount need them (e.g. 10% of ₱155 = ₱15.50)
foreach (['subtotal', 'discount'] as $moneyColumn) {
    $col = $conn->query("SHOW COLUMNS FROM `orders` LIKE '$moneyColumn'");
    $col = $col ? $col->fetch_assoc() : null;
    if ($col && stripos($col['Type'], 'decimal(10,0)') === 0) {
        $conn->query("ALTER TABLE `orders` MODIFY `$moneyColumn` DECIMAL(10,2) NOT NULL DEFAULT 0");
    }
}

// First run: start with the categories the site has always had
$hasCategories = $conn->query("SELECT 1 FROM `categories` LIMIT 1");
if ($hasCategories && $hasCategories->num_rows === 0) {
    $starter = [
        'products' => ['books' => 'Books', 'electronics' => 'Electronics', 'dorm-gear' => 'Dorm Gear', 'clothing' => 'Clothing', 'other' => 'Other'],
        'rentals'  => ['electronics' => 'Electronics', 'transport' => 'Transport', 'academic' => 'Academic', 'dorm-gear' => 'Dorm Gear', 'other' => 'Other'],
        'services' => ['academic' => 'Academic', 'creative' => 'Creative', 'tech' => 'Tech', 'wellness' => 'Wellness', 'writing' => 'Writing', 'errands' => 'Errands'],
    ];
    $seed = $conn->prepare("INSERT INTO `categories` (`type`, `slug`, `name`) VALUES (?, ?, ?)");
    foreach ($starter as $type => $cats) {
        foreach ($cats as $slug => $name) {
            $seed->bind_param("sss", $type, $slug, $name);
            $seed->execute();
        }
    }
    $seed->close();
}

// Columns added after the first release (account page, preferences, order tracking)
$addColumns = [
    ['users',       'full_name',       "VARCHAR(100) NOT NULL DEFAULT '' AFTER `username`"],
    ['users',       'notify_messages', "TINYINT(1) NOT NULL DEFAULT 1"],
    ['users',       'notify_orders',   "TINYINT(1) NOT NULL DEFAULT 1"],
    // Preferences page: browser pop-up notifications (js/inbox.js), and display language
    ['users',       'notify_push',     "TINYINT(1) NOT NULL DEFAULT 0"],
    // User Management (admin_users.php): 'active' or 'suspended' (suspended users can't log in)
    ['users',       'status',          "VARCHAR(20) NOT NULL DEFAULT 'active'"],
    ['users',       'language',        "VARCHAR(10) NOT NULL DEFAULT 'en-US'"],
    // Per-item progress set by the seller: pending -> shipped -> delivered (products),
    // pending -> active -> returned (rentals), pending -> in_progress -> completed (services)
    ['order_items', 'fulfillment',     "VARCHAR(20) NOT NULL DEFAULT 'pending'"],
    // Disputes (admin_transactions.php): a buyer reports a problem with one item.
    // dispute_status '' | open | resolved; outcome refund (fulfillment becomes 'refunded') | release
    ['order_items', 'dispute_status',  "VARCHAR(20) NOT NULL DEFAULT ''"],
    ['order_items', 'dispute_reason',  "VARCHAR(60) NOT NULL DEFAULT ''"],
    ['order_items', 'dispute_details', "VARCHAR(1000) NOT NULL DEFAULT ''"],
    ['order_items', 'dispute_outcome', "VARCHAR(20) NOT NULL DEFAULT ''"],
    ['order_items', 'dispute_note',    "VARCHAR(255) NOT NULL DEFAULT ''"],
    ['order_items', 'disputed_at',     "TIMESTAMP NULL DEFAULT NULL"],
    // Create-listing form: meetup campus / location, and every photo (JSON list; `image` is the cover)
    ['listings',    'location',        "VARCHAR(120) NOT NULL DEFAULT '' AFTER `description`"],
    ['listings',    'photos',          "TEXT NULL AFTER `image`"],
    // Listing Management (admin_listings.php): why an admin flagged a listing.
    // Listing status: active | draft | flagged (hidden by an admin) | pending (seller fixed it, awaiting review)
    ['listings',    'moderation_note', "VARCHAR(255) NOT NULL DEFAULT ''"],
    // Seller Applications (admin_sellers.php): pending | approved | rejected.
    // Sellers from before reviews existed count as approved.
    ['seller_profiles', 'status',       "VARCHAR(20) NOT NULL DEFAULT 'approved'"],
    ['seller_profiles', 'review_note',  "VARCHAR(255) NOT NULL DEFAULT ''"],
    ['seller_profiles', 'submitted_at', "TIMESTAMP NULL DEFAULT NULL"],
    ['seller_profiles', 'reviewed_at',  "TIMESTAMP NULL DEFAULT NULL"],
    // ID photo / PDF from Become a Seller step 2 (stored under private/ids, see seller.php)
    ['seller_profiles', 'id_document',      "VARCHAR(80) NOT NULL DEFAULT ''"],
    ['seller_profiles', 'id_document_name', "VARCHAR(120) NOT NULL DEFAULT ''"],
    // Admin notifications (admin_notifications.php): when this admin last pressed Mark All as Read
    ['users', 'notices_seen_at', "TIMESTAMP NULL DEFAULT NULL"],
    // Admin settings (admin_settings.php). admin_access: NULL = full access (super admin),
    // otherwise a comma list of SF_ADMIN_AREAS keys. admin_notice_prefs: JSON of notification switches.
    ['users', 'admin_title',        "VARCHAR(50) NOT NULL DEFAULT ''"],
    ['users', 'admin_access',       "VARCHAR(255) NULL DEFAULT NULL"],
    ['users', 'admin_notice_prefs', "VARCHAR(255) NULL DEFAULT NULL"],
    // Manage User modal (admin_users.php) "System log": set on every successful login (auth_handler.php)
    ['users', 'last_login_at', "TIMESTAMP NULL DEFAULT NULL"],
    ['users', 'last_login_ip', "VARCHAR(45) NOT NULL DEFAULT ''"],
    // Profile picture (Account › Profile), stored under uploads/avatars (see shop.php)
    ['users', 'avatar', "VARCHAR(80) NOT NULL DEFAULT ''"],
    // "About me" on the public profile (profile.php), set in Account › Profile
    ['users', 'bio', "VARCHAR(300) NOT NULL DEFAULT ''"],
    // Online status: when they were last active, and whether others may see it (Preferences)
    ['users', 'last_seen_at', "TIMESTAMP NULL DEFAULT NULL"],
    ['users', 'show_online',  "TINYINT(1) NOT NULL DEFAULT 1"],
    // Messages: the message being replied to, and when the sender unsent it (body is then cleared)
    ['messages', 'reply_to',  "INT NULL DEFAULT NULL"],
    ['messages', 'unsent_at', "TIMESTAMP NULL DEFAULT NULL"],
    // A photo sent in a chat (e.g. a payment screenshot), stored under private/chat (see inbox.php)
    ['messages', 'attachment', "VARCHAR(80) NOT NULL DEFAULT ''"],
    // Buyer cancelled an item before the seller started on it (fulfillment becomes 'cancelled')
    ['order_items', 'cancel_reason', "VARCHAR(60) NOT NULL DEFAULT ''"],
    ['order_items', 'cancelled_at',  "TIMESTAMP NULL DEFAULT NULL"],
    // Stock management (products only): how many are left to sell. UNSIGNED, so it can never go below 0;
    // checkout takes from it with a guarded UPDATE (shop.php › sf_reserve_stock). Products listed before
    // stock existed start with 1; sellers set the real number from their dashboard.
    ['listings',    'stock',          "INT UNSIGNED NOT NULL DEFAULT 1 AFTER `price`"],
    // 1 while this order item holds stock taken at checkout; set back to 0 when a cancellation returns it,
    // so stock is never taken or returned twice
    ['order_items', 'stock_deducted', "TINYINT(1) NOT NULL DEFAULT 0"],
];
foreach ($addColumns as [$table, $column, $definition]) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($check && $check->num_rows === 0 && !$conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
        die(json_encode([
            'success' => false,
            'message' => "Could not add $table.$column: " . $conn->error
        ]));
    }
}

// Prices are always in Philippine pesos, so the old Currency preference is gone
$check = $conn->query("SHOW COLUMNS FROM `users` LIKE 'currency'");
if ($check && $check->num_rows > 0) $conn->query("ALTER TABLE `users` DROP COLUMN `currency`");

// Two-factor authentication was removed; drop the columns and table it added
foreach (['two_factor', 'totp_secret', 'totp_enabled', 'totp_last_step'] as $column) {
    $check = $conn->query("SHOW COLUMNS FROM `users` LIKE '$column'");
    if ($check && $check->num_rows > 0) $conn->query("ALTER TABLE `users` DROP COLUMN `$column`");
}
$conn->query("DROP TABLE IF EXISTS `verification_tokens`");

// cart_items created by an older version lacks the (user, item) unique key that
// "add again" relies on; without it every add makes a new duplicate row.
$checkCartKey = $conn->query("SHOW INDEX FROM `cart_items` WHERE Key_name = 'uniq_user_cart_item'");
if ($checkCartKey && $checkCartKey->num_rows === 0) {
    $conn->query("DELETE c1 FROM `cart_items` c1 JOIN `cart_items` c2
                  ON c1.user_id = c2.user_id AND c1.item_key = c2.item_key AND c1.id > c2.id");
    if (!$conn->query("ALTER TABLE `cart_items`
                           MODIFY `quantity` INT NOT NULL DEFAULT 1,
                           MODIFY `units` INT NOT NULL DEFAULT 1,
                           ADD UNIQUE KEY `uniq_user_cart_item` (`user_id`, `item_key`)")) {
        die(json_encode([
            'success' => false,
            'message' => 'Cart table upgrade failed: ' . $conn->error
        ]));
    }
}

// Passwords are stored as hashes. Older signups saved them as plain text: hash any of those
// (the person keeps the same password; only how it's stored changes).
$plain = $conn->query('SELECT id, password FROM users WHERE password NOT LIKE "$2y$%" AND password NOT LIKE "$argon2%"');
while ($plain && $row = $plain->fetch_assoc()) {
    $hashed = password_hash($row['password'], PASSWORD_DEFAULT);
    $rehash = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $rehash->bind_param("si", $hashed, $row['id']);
    $rehash->execute();
    $rehash->close();
}

// Password reset codes are no longer handed out (auth_handler.php): cancel any still waiting
$conn->query("UPDATE users SET reset_token = NULL, reset_expires = NULL WHERE reset_token IS NOT NULL");

// ── Seed Default Admin User if not exists ───────────────────────
$adminEmail = 'admin@stationfinds.com';
$checkAdmin = $conn->prepare("SELECT id FROM users WHERE email = ? OR username = 'Admin User' OR role = 'admin'");
$checkAdmin->bind_param("s", $adminEmail);
$checkAdmin->execute();
$adminRes = $checkAdmin->get_result();

if ($adminRes->num_rows === 0) {
    $adminUser = 'Admin User';
    $adminPass = password_hash('admin123', PASSWORD_DEFAULT); // Default admin password: change it after the first login
    $adminRole = 'admin';
    $insertAdmin = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
    $insertAdmin->bind_param("ssss", $adminUser, $adminEmail, $adminPass, $adminRole);
    $insertAdmin->execute();
    $insertAdmin->close();
}
$checkAdmin->close();

// Remove tables for dashboard features that are no longer used.
if (!$conn->query("DROP TABLE IF EXISTS `activity_logs`, `admin_notifications`")) {
    die(json_encode([
        'success' => false,
        'message' => 'Unused dashboard tables could not be removed: ' . $conn->error
    ]));
}
// ── Suspended accounts ──────────────────────────────────────────
// An admin can suspend a user (admin_users.php). Their next page load or request ends
// their session: JSON handlers get a 401, pages go back to the login page.
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
    $statusStmt = $conn->prepare("SELECT status, last_seen_at < NOW() - INTERVAL 60 SECOND OR last_seen_at IS NULL AS stale FROM users WHERE id = ?");
    $sessionUserId = (int) $_SESSION['user_id'];
    $statusStmt->bind_param("i", $sessionUserId);
    $statusStmt->execute();
    $statusRow = $statusStmt->get_result()->fetch_assoc();
    $statusStmt->close();

    if (!$statusRow || $statusRow['status'] === 'suspended') {
        $_SESSION = [];
        session_destroy();
        $isJson = false;
        foreach (headers_list() as $sentHeader) {
            if (stripos($sentHeader, 'Content-Type: application/json') === 0) $isJson = true;
        }
        if ($isJson) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Your account has been suspended. Contact support@stationfinds.edu.ph.']);
        } else {
            header('Location: ' . sf_root_url('index.php'));
        }
        exit;
    }

    // Online status (the green dot): "last seen" is refreshed at most once a minute.
    // An open tab keeps it fresh because js/inbox.js checks in every 30 seconds.
    if ($statusRow['stale']) $conn->query("UPDATE users SET last_seen_at = NOW() WHERE id = " . $sessionUserId);
}
