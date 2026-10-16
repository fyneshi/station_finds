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

$cards = [
    ['Our Story', 'Started by student developers looking to solve Katipunan study cost limitations.',
     '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>'],
    ['Our Team', 'Built by verified student peer moderators and security-focused designers.',
     '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>'],
    ['Our Community', 'Connecting 10,000+ local students for trusted trades, rentals, and tutor networks.',
     '<circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>'],
];

$commitments = [
    ['Trusted transactions', 'Verification ensures safety and escrow protects payments.',
     '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" fill="currentColor"></path>'],
    ['Quality first', 'Listings undergo rapid community peer reviews before going live.',
     '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline>'],
    ['Community driven', 'Created strictly by students, for student affordability.',
     '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" fill="currentColor"></path>'],
    ['Sustainable reuse', 'Reduce paper and plastic waste via textbook re-selling and device rentals.',
     '<polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path>'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>About Us — Station Finds</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/cart.css?v=4" />
  <link rel="stylesheet" href="css/about.css?v=3" />
</head>
<body>

  <?php sf_render_navbar(sf_cart_count($conn, $userId), 'about'); ?>

  <main class="about-wrapper">
    <header class="about-head">
      <h1>About Us</h1>
      <p>Get to know the team, mission, and student-first values powering Station Finds marketplace.</p>
    </header>

    <section class="card about-intro" id="get-to-know-us" aria-labelledby="introTitle">
      <h2 id="introTitle">Get to know us</h2>
      <p>STATIONFINDS was founded in 2026 as a dedicated peer-to-peer campus marketplace designed specifically for students in Quezon City. Recognizing the high costs of textbooks, dorm essentials, and safe daily transportation, we set out to create an online venue where verified students can easily buy, rent, or offer services safely within physical university campuses.</p>
    </section>

    <div class="about-cards">
      <?php foreach ($cards as [$title, $text, $icon]): ?>
      <section class="card about-card">
        <span class="about-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $icon; ?></svg></span>
        <h3><?php echo $title; ?></h3>
        <p><?php echo $text; ?></p>
      </section>
      <?php endforeach; ?>
    </div>

    <section class="about-commitment" id="our-commitment" aria-labelledby="commitTitle">
      <h2 id="commitTitle">Our commitment</h2>
      <blockquote class="card about-quote">&ldquo;At STATIONFINDS, we hold university trust as our highest priority. We strive to provide transparent pricing, student identification validation, and an eco-friendly local lifecycle for campus resources.&rdquo;</blockquote>

      <ul class="commit-grid">
        <?php foreach ($commitments as [$title, $text, $icon]): ?>
        <li>
          <span class="commit-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $icon; ?></svg></span>
          <div>
            <strong><?php echo $title; ?></strong>
            <span><?php echo $text; ?></span>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </main>

  <?php sf_render_footer(); ?>

  <script src="js/cart.js?v=2"></script>
  <script src="js/inbox.js?v=2"></script>
</body>
</html>
