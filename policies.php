<?php
session_start();

// Redirect to login page if user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Admins only use the admin dashboard
if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

require_once 'db_setup.php';
require_once 'shop.php';
require_once 'layout.php';

$userId = (int) $_SESSION['user_id'];

// Each policy: title and numbered sections [heading, paragraph, ...].
// Keep these in line with what the site actually does (see seller.php, checkout.php).
$policies = [
    'privacy' => ['Privacy policy', 'Privacy Policy', [
        ['Scope and Campus Trust',
         'This policy explains what STATIONFINDS collects when you use the marketplace and how it is used. We only use your information to run the marketplace for students: listing, buying, renting, messaging and paying out sellers.'],
        ['Information We Collect',
         'Your account: username, email address, password and, if you add it, your full name.',
         'When you check out: the name, mobile number and delivery address you enter for that order.',
         'When you become a seller: your store name, contact number, school, student ID number and payout account (GCash number or bank account).',
         'What you post and send: listings and their photos, messages, saved items, cart items, and messages sent through the Contact us page.'],
        ['How We Use It',
         'To show your listings, process your orders, deliver messages and notifications, send payouts to sellers, check seller applications, and answer support requests. We do not sell your information or use it for advertising.'],
        ['Who Can See It',
         'Buyers see a seller\'s store name, listings and join date. Sellers see the buyer\'s name and order details for items bought from them. Messages are only visible to the two people in the conversation. Your school, student ID and payout details are only visible to you and Station Finds admins.'],
        ['Cookies',
         'We use one session cookie to keep you logged in. Your browser may also remember small settings on your device. We do not use advertising or tracking cookies, and we do not track your location.'],
        ['Your Choices',
         'You can update your name and email under Account → Edit Profile, choose which notifications you get under Preferences, and unpublish or delete your listings at any time. To delete your account, contact support@stationfinds.edu.ph.'],
    ]],
    'terms' => ['Terms of service', 'Terms of Service', [
        ['Who Can Use STATIONFINDS',
         'STATIONFINDS is for students. Use accurate information, keep one account, and keep your password private. You are responsible for activity on your account.'],
        ['Listings',
         'Only list items you own or services you can provide yourself, and describe them honestly, including their condition. Do not list illegal, stolen, counterfeit or dangerous items, alcohol, or academic work meant to be submitted as someone else\'s. Admins may remove listings that break these rules.'],
        ['Buying and Renting',
         'Prices are set by sellers. Placing an order is a commitment to buy, rent or book at that price. Rentals must be returned on time and in the same condition; renters are responsible for loss or damage while an item is in their care.'],
        ['Selling',
         'Sellers must fulfill orders promptly and keep each order\'s status accurate (shipped, picked up, returned, completed). Earnings become available once an order is completed, and payout requests are reviewed by an admin before money is sent.'],
        ['Meetups and Safety',
         'Meet in public, well-lit areas on or near campus. Never share your password or payment PINs. Report suspicious listings or users through the Contact us page.'],
        ['Conduct and Changes',
         'Be respectful in messages and comments; spam, harassment and scams are not allowed. Accounts that break these terms may be suspended. We may update these terms, and the date above shows the latest version.'],
    ]],
    'refund' => ['Refund policy', 'Refund Policy', [
        ['Cancelling an Order',
         'If a seller has not yet shipped your item, handed over a rental, or started a service, message the seller to cancel. If they agree, contact support with your order number and we will cancel it.'],
        ['Items Not as Described',
         'If a product arrives damaged, broken, or clearly different from its listing, contact support within 3 days of delivery with your order number and photos. An admin will review the case with the seller.'],
        ['Rentals',
         'Rent covers the days you booked. Returning an item early does not refund unused days. If a rented item is returned damaged, the seller may ask for repair or replacement costs, which an admin reviews.'],
        ['Services',
         'A service can be cancelled for a full refund before the provider starts it. If a completed service was not delivered as agreed, contact support within 3 days of completion.'],
        ['How Refunds Are Paid',
         'Approved refunds are sent back the way you paid: to your GCash wallet or card, or by GCash or cash for Cash on Delivery orders. Refunds are processed within 5–7 business days after approval. The shipping fee is not refunded once an item has been shipped.'],
    ]],
];
$page = isset($policies[$_GET['page'] ?? '']) ? $_GET['page'] : 'privacy';
[$navLabel, $title, $sections] = $policies[$page];
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $title; ?> — Station Finds</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/cart.css?v=5" />
  <link rel="stylesheet" href="css/about.css?v=3" />
</head>
<body>

  <?php sf_render_navbar(sf_cart_count($conn, $userId), 'policies'); ?>

  <main class="about-wrapper">
    <h1 class="shop-title">Policies</h1>

    <div class="policy-layout">
      <nav class="card policy-nav" aria-label="Policies">
        <?php foreach ($policies as $key => [$label]): ?>
        <a href="policies.php?page=<?php echo $key; ?>"<?php echo $key === $page ? ' class="active" aria-current="page"' : ''; ?>><?php echo $label; ?></a>
        <?php endforeach; ?>
      </nav>

      <article class="card policy-doc">
        <h2><?php echo $title; ?></h2>
        <p class="policy-updated">Last updated: October 2026</p>

        <?php foreach ($sections as $i => $section): ?>
        <section>
          <h3><?php echo ($i + 1) . '. ' . $e($section[0]); ?></h3>
          <?php foreach (array_slice($section, 1) as $paragraph): ?>
          <p><?php echo $e($paragraph); ?></p>
          <?php endforeach; ?>
        </section>
        <?php endforeach; ?>

        <p class="policy-contact">Questions about this policy? <a href="contact.php">Contact us</a>.</p>
      </article>
    </div>
  </main>

  <?php sf_render_footer(); ?>

  <script src="js/cart.js?v=4"></script>
  <script src="js/inbox.js?v=2"></script>
</body>
</html>
