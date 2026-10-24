<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?next=' . rawurlencode(basename($_SERVER['REQUEST_URI'] ?? '')));
    exit;
}

// Admins only use the admin dashboard
if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

require_once __DIR__ . '/includes/db_setup.php';
require_once __DIR__ . '/includes/shop.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/inbox.php';
require_once __DIR__ . '/includes/seller.php';

$userId   = (int) $_SESSION['user_id'];
$existing = sf_seller_profile($conn, $userId);   // set when editing an existing seller profile

// Answers so far, kept in the session until the last step is saved
if (!isset($_SESSION['seller_app']) || ($_GET['restart'] ?? '') === '1') {
    $_SESSION['seller_app'] = $existing ? [1 => true, 2 => true, 'data' => $existing] : ['data' => []];
}
$app  = &$_SESSION['seller_app'];
$data = &$app['data'];

// Steps open in order: a step is reachable once the steps before it are done
$step = (int) ($_GET['step'] ?? 1);
$step = max(1, min(3, $step));
while ($step > 1 && empty($app[$step - 1])) $step--;

$categories = sf_all_categories();
// Keep an existing seller's category selectable even if an admin has since turned it off
if ($existing && !isset($categories[$existing['category']])) {
    foreach (sf_categories() as $cats) {
        if (isset($cats[$existing['category']])) { $categories[$existing['category']] = $cats[$existing['category']]; break; }
    }
}
$errors     = [];
$posted     = [];

// "0917 123 4567" / "+63 917 123 4567" -> "09171234567" (null if not a PH mobile number)
function ph_mobile($value) {
    $digits = preg_replace('/[\s\-()]/', '', (string) $value);
    if (!preg_match('/^(\+63|0)(9\d{9})$/', $digits, $m)) return null;
    return '0' . $m[2];
}

function clean_line($value, $max) {
    return mb_substr(trim(preg_replace('/\s+/', ' ', (string) $value)), 0, $max + 1);
}

// ── Form submissions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors['form'] = 'That file is too large to upload. Files can be up to 5 MB.';
    } elseif (!sf_check_csrf()) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif ($step === 1) {
        $posted = [
            'store_name'     => clean_line($_POST['store_name'] ?? '', 80),
            'category'       => (string) ($_POST['category'] ?? ''),
            'contact_number' => trim((string) ($_POST['contact_number'] ?? '')),
            'seller_type'    => ($_POST['seller_type'] ?? '') === 'business' ? 'business' : 'individual',
            'about'          => trim(str_replace("\r\n", "\n", (string) ($_POST['about'] ?? ''))),
        ];
        if (mb_strlen($posted['store_name']) < 2 || mb_strlen($posted['store_name']) > 80) $errors['store_name'] = 'Please enter a store name (2–80 characters).';
        if (!isset($categories[$posted['category']])) $errors['category'] = 'Please choose a category.';
        $mobile = ph_mobile($posted['contact_number']);
        if ($mobile === null) $errors['contact_number'] = 'Enter a PH mobile number, e.g. 0917 123 4567.';
        if (mb_strlen($posted['about']) > 1000) $errors['about'] = 'Please keep this under 1,000 characters.';
        if (!$errors) {
            $data = array_merge($data, $posted, ['contact_number' => $mobile]);
            $app[1] = true;
            header('Location: become_seller.php?step=2');
            exit;
        }
    } elseif ($step === 2) {
        $posted = [
            'school'     => clean_line($_POST['school'] ?? '', 120),
            'student_id' => strtoupper(trim((string) ($_POST['student_id'] ?? ''))),
        ];
        if (mb_strlen($posted['school']) < 2 || mb_strlen($posted['school']) > 120) $errors['school'] = 'Please enter your school or university.';
        if (!preg_match('/^[A-Z0-9][A-Z0-9 -]{2,29}$/', $posted['student_id'])) $errors['student_id'] = 'Enter your student ID number (letters, numbers and dashes).';
        if (empty($_POST['confirm'])) $errors['confirm'] = 'Please confirm that these details are yours.';

        // ID photo or PDF: saved straight away so it isn't lost if another field needs fixing
        $upload = $_FILES['id_document'] ?? null;
        if ($upload && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
            [$saved, $fileError] = sf_save_id_document($upload);
            if ($fileError) {
                $errors['id_document'] = $fileError;
            } else {
                // Replace an earlier upload from this application (the saved profile's file goes when it's submitted)
                if (!empty($data['id_document']) && $data['id_document'] !== ($existing['id_document'] ?? '')) sf_delete_id_document($data['id_document']);
                $data['id_document']      = $saved;
                $data['id_document_name'] = mb_substr(basename((string) $upload['name']), 0, 120);
            }
        } elseif (empty($data['id_document']) && !($existing && $existing['status'] === 'approved')) {
            $errors['id_document'] = 'Please upload a photo of your student or government ID.';
        }
        if (!$errors) {
            $data = array_merge($data, $posted);
            $app[2] = true;
            header('Location: become_seller.php?step=3');
            exit;
        }
    } else {
        // Earnings are withdrawn to GCash only
        $posted = [
            'payout_method'  => 'gcash',
            'payout_name'    => clean_line($_POST['payout_name'] ?? '', 100),
            'payout_bank'    => '',
            'payout_account' => trim((string) ($_POST['gcash_number'] ?? '')),
        ];
        if (mb_strlen($posted['payout_name']) < 2 || mb_strlen($posted['payout_name']) > 100) $errors['payout_name'] = 'Enter the name on the account.';
        $posted['payout_account'] = ph_mobile($posted['payout_account']) ?? $posted['payout_account'];
        if (!preg_match('/^09\d{9}$/', $posted['payout_account'])) $errors['gcash_number'] = 'Enter your GCash number, e.g. 0917 123 4567.';

        if (!$errors) {
            $d = array_merge($data, $posted);
            // Approved sellers editing their details stay approved; new and rejected applications go to an admin
            $approved = $existing && $existing['status'] === 'approved';
            $status   = $approved ? 'approved' : 'pending';
            $stmt = $conn->prepare(
                "INSERT INTO seller_profiles (user_id, store_name, category, contact_number, seller_type, about, school, student_id,
                                              payout_method, payout_name, payout_account, payout_bank, status, id_document, id_document_name, submitted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE store_name = VALUES(store_name), category = VALUES(category),
                     contact_number = VALUES(contact_number), seller_type = VALUES(seller_type), about = VALUES(about),
                     school = VALUES(school), student_id = VALUES(student_id), payout_method = VALUES(payout_method),
                     payout_name = VALUES(payout_name), payout_account = VALUES(payout_account), payout_bank = VALUES(payout_bank),
                     status = VALUES(status), id_document = VALUES(id_document), id_document_name = VALUES(id_document_name),
                     submitted_at = IF(VALUES(status) = 'pending', NOW(), submitted_at)"
            );
            $idDoc  = (string) ($d['id_document'] ?? '');
            $idName = (string) ($d['id_document_name'] ?? '');
            $stmt->bind_param("issssssssssssss", $userId, $d['store_name'], $d['category'], $d['contact_number'], $d['seller_type'],
                $d['about'], $d['school'], $d['student_id'], $d['payout_method'], $d['payout_name'], $d['payout_account'], $d['payout_bank'], $status,
                $idDoc, $idName);
            $stmt->execute();
            $stmt->close();
            // A new ID replaces the one sent before
            if ($existing && $existing['id_document'] !== '' && $existing['id_document'] !== $idDoc) sf_delete_id_document($existing['id_document']);

            unset($_SESSION['seller_app']);
            if ($approved) {
                $_SESSION['flash'] = ['ok', 'Your seller profile was updated.'];
                header('Location: account.php?tab=seller');
                exit;
            }
            sf_notify($conn, $userId, 'info', 'Seller application received',
                'Thanks! An admin will review ' . $d['store_name'] . ' shortly. You\'ll get a notification once it\'s approved.', 'account.php');
            $_SESSION['flash'] = ['ok', 'Your application was sent! An admin will review it soon, and you\'ll get a notification once you can start selling.'];
            header('Location: account.php');
            exit;
        }
    }
}

// Values to show: what was just typed (after an error) or what was saved before
$v = fn($key) => htmlspecialchars((string) ($posted[$key] ?? $data[$key] ?? ''), ENT_QUOTES, 'UTF-8');
$err = fn($key) => isset($errors[$key]) ? '<span class="field-error">' . $errors[$key] . '</span>' : '';
$bad = fn($key) => isset($errors[$key]) ? ' has-error' : '';
$e   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$headings = [
    1 => ['Seller details', 'This information appears on your public seller profile.'],
    2 => ['Verify identity', 'We use this to keep the marketplace safe. It is never shown to buyers.'],
    3 => ['Payout method', 'Where we send the money from your sales.'],
];
$payoutMethod = $posted['payout_method'] ?? ($data['payout_method'] ?? 'gcash');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $existing && $existing['status'] === 'approved' ? 'Seller profile' : 'Become a Seller'; ?> — Station Finds</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/cart.css?v=6" />
  <link rel="stylesheet" href="css/account.css?v=21" />
  <link rel="stylesheet" href="css/header.css?v=1" />
  <link rel="stylesheet" href="css/responsive.css?v=2" />
</head>
<body>

  <?php sf_render_navbar(sf_cart_count($conn, $userId), 'account'); ?>

  <main class="wizard-wrapper">
    <!-- Stepper -->
    <nav class="stepper" aria-label="Seller application progress">
      <?php foreach ([1 => 'Seller details', 2 => 'Verify identity', 3 => 'Payout method'] as $n => $label):
        $state = $n === $step ? 'current' : (!empty($app[$n]) ? 'done' : '');
        $tag = ($state === 'done' && $n !== $step) ? 'a href="become_seller.php?step=' . $n . '"' : 'span';
      ?>
        <?php if ($n > 1): ?><span class="step-line<?php echo !empty($app[$n - 1]) ? ' done' : ''; ?>"></span><?php endif; ?>
        <<?php echo $tag; ?> class="step <?php echo $state; ?>"<?php echo $state === 'current' ? ' aria-current="step"' : ''; ?>>
          <span class="step-dot"><?php echo $state === 'done' ? '&#10003;' : $n; ?></span>
          <span class="step-label"><?php echo $label; ?></span>
        </<?php echo $tag === 'span' ? 'span' : 'a'; ?>>
      <?php endforeach; ?>
    </nav>

    <form method="post" action="become_seller.php?step=<?php echo $step; ?>" class="card wizard-card" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo $e(sf_csrf_token()); ?>" />
      <h1 class="wizard-title"><?php echo $headings[$step][0]; ?></h1>
      <p class="wizard-sub"><?php echo $headings[$step][1]; ?></p>

      <?php if (isset($errors['form'])): ?><div class="alert-error"><?php echo $errors['form']; ?></div><?php endif; ?>

      <?php if ($step === 1): ?>
      <div class="form-stack">
        <div class="form-field">
          <label class="form-label" for="store_name">Store name</label>
          <input type="text" id="store_name" name="store_name" class="text-input<?php echo $bad('store_name'); ?>" value="<?php echo $v('store_name'); ?>" maxlength="80" placeholder="e.g. Jordan's Vintage Finds" required />
          <?php echo $err('store_name'); ?>
        </div>
        <div class="field-row-split">
          <div class="form-field">
            <label class="form-label" for="category">Primary category</label>
            <select id="category" name="category" class="text-input<?php echo $bad('category'); ?>" required>
              <option value="">Select a category</option>
              <?php foreach ($categories as $key => $label): ?>
              <option value="<?php echo $e($key); ?>"<?php echo ($posted['category'] ?? $data['category'] ?? '') === $key ? ' selected' : ''; ?>><?php echo $e($label); ?></option>
              <?php endforeach; ?>
            </select>
            <?php echo $err('category'); ?>
          </div>
          <div class="form-field">
            <label class="form-label" for="contact_number">Contact number</label>
            <input type="tel" id="contact_number" name="contact_number" class="text-input<?php echo $bad('contact_number'); ?>" value="<?php echo $v('contact_number'); ?>" maxlength="20" placeholder="+63 9XX XXX XXXX" autocomplete="tel" required />
            <?php echo $err('contact_number'); ?>
          </div>
        </div>
        <div class="form-field">
          <span class="form-label">Seller type</span>
          <?php $type = $posted['seller_type'] ?? ($data['seller_type'] ?? 'individual'); ?>
          <div class="choice-grid">
            <label class="choice">
              <input type="radio" name="seller_type" value="individual"<?php echo $type !== 'business' ? ' checked' : ''; ?> />
              <span class="choice-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></span>
              <span class="choice-text"><strong>Individual seller</strong><span>Selling personal items casually</span></span>
              <span class="choice-check">&#10003;</span>
            </label>
            <label class="choice">
              <input type="radio" name="seller_type" value="business"<?php echo $type === 'business' ? ' checked' : ''; ?> />
              <span class="choice-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg></span>
              <span class="choice-text"><strong>Registered business</strong><span>Operating a registered store or business</span></span>
              <span class="choice-check">&#10003;</span>
            </label>
          </div>
        </div>
        <div class="form-field">
          <label class="form-label" for="about">About your store <span class="optional">(optional)</span></label>
          <textarea id="about" name="about" class="text-input<?php echo $bad('about'); ?>" maxlength="1000" placeholder="Tell buyers what you sell and what makes your store great..."><?php echo $v('about'); ?></textarea>
          <?php echo $err('about'); ?>
        </div>
      </div>
      <div class="info-note">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
        Next, we'll verify your identity to keep the marketplace safe for everyone.
      </div>

      <?php elseif ($step === 2): ?>
      <div class="form-stack">
        <div class="form-field">
          <label class="form-label" for="school">School or university</label>
          <input type="text" id="school" name="school" class="text-input<?php echo $bad('school'); ?>" value="<?php echo $v('school'); ?>" maxlength="120" placeholder="e.g. Ateneo de Manila University" autocomplete="organization" required />
          <?php echo $err('school'); ?>
        </div>
        <div class="form-field">
          <label class="form-label" for="student_id">Student ID number</label>
          <input type="text" id="student_id" name="student_id" class="text-input<?php echo $bad('student_id'); ?>" value="<?php echo $v('student_id'); ?>" maxlength="30" placeholder="e.g. 2024-12345" required />
          <?php echo $err('student_id'); ?>
        </div>
        <div class="form-field">
          <label class="form-label" for="id_document">Photo of your student or government ID</label>
          <input type="file" id="id_document" name="id_document" class="text-input file-input<?php echo $bad('id_document'); ?>" accept="image/jpeg,image/png,image/webp,application/pdf" />
          <?php if (!empty($data['id_document'])): ?>
          <span class="field-hint">Uploaded: <strong><?php echo $e($data['id_document_name'] ?: 'ID document'); ?></strong>. Choose a new file only to replace it.</span>
          <?php else: ?>
          <span class="field-hint">JPG, PNG, WebP or PDF, up to 5 MB. Only Station Finds admins can see it.</span>
          <?php endif; ?>
          <?php echo $err('id_document'); ?>
        </div>
        <div class="form-field">
          <label class="check-row">
            <input type="checkbox" name="confirm" value="1"<?php echo !empty($_POST['confirm']) || !empty($app[2]) ? ' checked' : ''; ?> />
            <span>I confirm I'm a currently enrolled student and these details are mine. Station Finds may contact my school to verify them.</span>
          </label>
          <?php echo $err('confirm'); ?>
        </div>
      </div>
      <div class="info-note">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
        Your school and ID are only used for verification and are never shown on your profile.
      </div>

      <?php else: ?>
      <div class="form-stack">
        <div class="form-field">
          <span class="form-label">Send my earnings to</span>
          <div class="choice-grid">
            <label class="choice">
              <input type="radio" name="payout_method" value="gcash" checked />
              <span class="choice-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg></span>
              <span class="choice-text"><strong>GCash</strong><span>Sent to your GCash wallet</span></span>
              <span class="choice-check">&#10003;</span>
            </label>
          </div>
          <span class="field-hint">Earnings are withdrawn to GCash only.</span>
        </div>
        <div class="form-field">
          <label class="form-label" for="payout_name">Account name</label>
          <input type="text" id="payout_name" name="payout_name" class="text-input<?php echo $bad('payout_name'); ?>" value="<?php echo $v('payout_name'); ?>" maxlength="100" placeholder="Name exactly as it appears on the account" autocomplete="name" required />
          <?php echo $err('payout_name'); ?>
        </div>
        <div class="form-field">
          <label class="form-label" for="gcash_number">GCash number</label>
          <input type="tel" id="gcash_number" name="gcash_number" class="text-input<?php echo $bad('gcash_number'); ?>" value="<?php echo $payoutMethod === 'gcash' ? $v('payout_account') : ''; ?>" maxlength="20" placeholder="0917 123 4567" required />
          <?php echo $err('gcash_number'); ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="wizard-actions">
        <a href="<?php echo $step > 1 ? 'become_seller.php?step=' . ($step - 1) : 'account.php' . ($existing && $existing['status'] === 'approved' ? '?tab=seller' : ''); ?>" class="btn-outline"><?php echo $step > 1 ? 'Back' : 'Cancel'; ?></a>
        <button type="submit" class="btn-primary"><?php echo $step < 3 ? 'Continue' : ($existing && $existing['status'] === 'approved' ? 'Save changes' : ($existing ? 'Resubmit application' : 'Submit application')); ?></button>
      </div>
    </form>
  </main>

  <?php sf_render_footer(); ?>

  <script src="js/cart.js?v=6"></script>
  <script src="js/inbox.js?v=4"></script>
  <?php sf_render_tabbar('account'); ?>
</body>
</html>
