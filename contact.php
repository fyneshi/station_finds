<?php
session_start();

// Guests can browse this page; account features ask them to log in (js/guest.js)

// Admins only use the admin dashboard
if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

require_once __DIR__ . '/includes/db_setup.php';
require_once __DIR__ . '/includes/shop.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/seller.php';

const CONTACT_HOURLY_LIMIT = 5;   // messages one user can send per hour

$userId = (int) ($_SESSION['user_id'] ?? 0);
$user   = sf_account_user($conn, $userId);
$errors = [];
$sent   = !empty($_SESSION['contact_sent']);
unset($_SESSION['contact_sent']);

// Start with the account's name and email
$v = ['full_name' => $user ? sf_display_name($user) : '', 'email' => $user['email'] ?? '', 'subject' => '', 'rating' => 0, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = [
        'full_name' => trim(preg_replace('/\s+/', ' ', (string) ($_POST['full_name'] ?? ''))),
        'email'     => trim((string) ($_POST['email'] ?? '')),
        'subject'   => trim(preg_replace('/\s+/', ' ', (string) ($_POST['subject'] ?? ''))),
        'rating'    => max(0, min(5, (int) ($_POST['rating'] ?? 0))),   // optional, 0 = no rating
        'message'   => trim(str_replace("\r\n", "\n", (string) ($_POST['message'] ?? ''))),
    ];
    if ($userId === 0) {
        $errors['form'] = 'Please log in to send us a message.';   // guests can read the page; js/guest.js asks them to log in
    } elseif (!sf_check_csrf()) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        if (mb_strlen($v['full_name']) < 2 || mb_strlen($v['full_name']) > 100) $errors['full_name'] = 'Please enter your full name.';
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 255) $errors['email'] = 'Please enter a valid email address.';
        if (mb_strlen($v['subject']) < 3 || mb_strlen($v['subject']) > 120) $errors['subject'] = 'Please add a short subject (3–120 characters).';
        if (mb_strlen($v['message']) < 10) $errors['message'] = 'Please describe your question in a little more detail.';
        elseif (mb_strlen($v['message']) > 2000) $errors['message'] = 'Please keep your message under 2,000 characters.';

        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM feedback WHERE user_id = ? AND type = 'platform' AND created_at > NOW() - INTERVAL 1 HOUR");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $recent = (int) $stmt->get_result()->fetch_assoc()['n'];
        $stmt->close();
        if ($recent >= CONTACT_HOURLY_LIMIT) $errors['form'] = 'You\'ve sent several messages recently. Please wait a while before sending another.';
    }

    if (!$errors) {
        // Saved as feedback to the platform; admins read it in admin_feedback.php
        $rating = $v['rating'] ?: null;
        $stmt = $conn->prepare("INSERT INTO feedback (user_id, name, email, type, subject, message, rating) VALUES (?, ?, ?, 'platform', ?, ?, ?)");
        $stmt->bind_param("issssi", $userId, $v['full_name'], $v['email'], $v['subject'], $v['message'], $rating);
        $stmt->execute();
        $stmt->close();
        $_SESSION['contact_sent'] = true;
        header('Location: contact.php');
        exit;
    }
}

$e   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$err = fn($key) => isset($errors[$key]) ? '<span class="field-error">' . $e($errors[$key]) . '</span>' : '';
$bad = fn($key) => isset($errors[$key]) ? ' has-error' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Contact Us — Station Finds</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/cart.css?v=6" />
  <link rel="stylesheet" href="css/about.css?v=3" />
  <link rel="stylesheet" href="css/header.css?v=1" />
  <link rel="stylesheet" href="css/responsive.css?v=2" />
</head>
<body>

  <?php sf_render_navbar(sf_cart_count($conn, $userId), 'contact'); ?>

  <main class="about-wrapper">
    <h1 class="shop-title">Contact us</h1>
    <p class="shop-subtitle">Have inquiries about safety, verification, or partnerships? Our campus team is here.</p>

    <div class="contact-grid">
      <section class="card contact-card" aria-labelledby="officeTitle">
        <h2 id="officeTitle">Campus Station Office</h2>
        <dl class="contact-list">
          <div><dt>Address</dt><dd>3rd Floor Student Pavilion, Katipunan Ave, Quezon City, PH</dd></div>
          <div><dt>Email support</dt><dd><a href="mailto:support@stationfinds.edu.ph">support@stationfinds.edu.ph</a></dd></div>
          <div><dt>Contact number</dt><dd><a href="tel:+63281234567">+63 (2) 8123&ndash;4567</a></dd></div>
          <div><dt>Office hours</dt><dd>Monday to Friday: 9:00 AM - 5:00 PM</dd></div>
        </dl>
      </section>

      <section class="card contact-card" aria-labelledby="formTitle">
        <h2 id="formTitle">Send us a message</h2>

        <?php if ($sent): ?>
        <div class="contact-sent" role="status">
          <strong>Message sent!</strong>
          Thanks for reaching out. Our team will reply to your email within office hours.
        </div>
        <?php endif; ?>
        <?php if (isset($errors['form'])): ?><div class="alert-error"><?php echo $e($errors['form']); ?></div><?php endif; ?>

        <form method="post" class="form-stack" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo $e(sf_csrf_token()); ?>" />
          <div class="form-field">
            <label class="form-label" for="full_name">Full Name</label>
            <input type="text" id="full_name" name="full_name" class="text-input<?php echo $bad('full_name'); ?>" value="<?php echo $e($v['full_name']); ?>" maxlength="100" placeholder="e.g. Juan dela Cruz" autocomplete="name" required />
            <?php echo $err('full_name'); ?>
          </div>
          <div class="form-field">
            <label class="form-label" for="email">Campus Email Address</label>
            <input type="email" id="email" name="email" class="text-input<?php echo $bad('email'); ?>" value="<?php echo $e($v['email']); ?>" maxlength="255" placeholder="e.g. juandelacruz@up.edu.ph" autocomplete="email" required />
            <?php echo $err('email'); ?>
          </div>
          <div class="form-field">
            <label class="form-label" for="subject">Subject</label>
            <input type="text" id="subject" name="subject" class="text-input<?php echo $bad('subject'); ?>" value="<?php echo $e($v['subject']); ?>" maxlength="120" placeholder="e.g. Payment not processed" required />
            <?php echo $err('subject'); ?>
          </div>
          <fieldset class="form-field star-field">
            <legend class="form-label">How's your experience with Station Finds? <span class="optional">(optional)</span></legend>
            <div class="star-input">
              <?php for ($s = 5; $s >= 1; $s--): ?>
              <input type="radio" id="star<?php echo $s; ?>" name="rating" value="<?php echo $s; ?>"<?php echo $v['rating'] === $s ? ' checked' : ''; ?> /><label for="star<?php echo $s; ?>" title="<?php echo $s; ?> star<?php echo $s === 1 ? '' : 's'; ?>">★</label>
              <?php endfor; ?>
            </div>
          </fieldset>
          <div class="form-field">
            <label class="form-label" for="message">Your Message</label>
            <textarea id="message" name="message" class="text-input<?php echo $bad('message'); ?>" maxlength="2000" placeholder="Describe your query in detail..." required><?php echo $e($v['message']); ?></textarea>
            <?php echo $err('message'); ?>
          </div>
          <button type="submit" class="btn-primary"<?php echo $userId ? '' : ' data-guest-gate'; ?>>Send message</button>
        </form>
      </section>
    </div>
  </main>

  <?php sf_render_footer(); ?>

  <script src="js/cart.js?v=6"></script>
  <script src="js/inbox.js?v=4"></script>
  <?php sf_render_tabbar(''); ?>
</body>
</html>
