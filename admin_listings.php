<?php
session_start();

// Admins only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/includes/db_setup.php';
require_once __DIR__ . '/includes/shop.php';
require_once __DIR__ . '/includes/inbox.php';
require_once __DIR__ . '/includes/seller.php';
require_once __DIR__ . '/includes/admin_layout.php';
sf_admin_require($conn, 'listings');

$types = ['products' => 'Products', 'rentals' => 'Rentals', 'services' => 'Services'];
$tabs  = ['all' => 'All'] + $types;
$tab   = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'all';

// Listing status => [label, chip style]
$statuses = [
    'active'  => ['Active', 'active'],
    'pending' => ['Pending review', 'pending'],
    'flagged' => ['Flagged', 'flagged'],
    'draft'   => ['Draft', 'draft'],
];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function back($tab, $type, $message) {
    $_SESSION['flash'] = [$type, $message];
    header('Location: admin_listings.php?tab=' . $tab);
    exit;
}

// Sellers an admin can add a listing for: approved stores whose owner isn't suspended.
// The label is what the Seller box suggests and what comes back when one is picked.
$sellers = [];
$res = $conn->query("SELECT s.user_id, s.store_name, u.username, u.email FROM seller_profiles s JOIN users u ON u.id = s.user_id
                     WHERE s.status = 'approved' AND u.status = 'active' ORDER BY s.store_name");
while ($s = $res->fetch_assoc()) $sellers[$s['store_name'] . ' (' . $s['email'] . ')'] = $s;

// Add Listing modal: values and errors to re-open it with
$addErrors = [];
$addValues = ['title' => '', 'seller' => '', 'category' => '', 'price' => '', 'stock' => '1', 'status' => 'pending'];
$addStatuses = ['pending' => 'Pending review', 'active' => 'Active', 'draft' => 'Draft'];
// Manage Listing modal, Edit Details: re-opens with these after a failed save
$editErrors = [];
$editValues = null;

// ── Add / approve / flag / delete ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!sf_check_csrf()) {
        $flash = ['err', 'Your session expired. Please try again.'];
    } elseif ($action === 'add_listing') {
        $addValues = [
            'title'    => trim(preg_replace('/\s+/', ' ', (string) ($_POST['title'] ?? ''))),
            'seller'   => trim(preg_replace('/\s+/', ' ', (string) ($_POST['seller'] ?? ''))),
            'category' => (string) ($_POST['category'] ?? ''),
            'price'    => str_replace([',', ' ', '₱'], '', trim((string) ($_POST['price'] ?? ''))),
            'stock'    => str_replace([',', ' '], '', trim((string) ($_POST['stock'] ?? ''))),   // products only
            'status'   => isset($addStatuses[$_POST['status'] ?? '']) ? $_POST['status'] : 'pending',
        ];
        // Seller: a suggestion picked from the list, or just their store name / email typed in full
        $seller = $sellers[$addValues['seller']] ?? null;
        if (!$seller) foreach ($sellers as $s) {
            if (strcasecmp($s['store_name'], $addValues['seller']) === 0 || strcasecmp($s['email'], $addValues['seller']) === 0) $seller = $s;
        }
        [$type, $slug] = array_pad(explode('|', $addValues['category'], 2), 2, '');
        $categories = sf_categories(true);

        if (mb_strlen($addValues['title']) < 3 || mb_strlen($addValues['title']) > 100) $addErrors['title'] = 'Enter a title (3–100 characters).';
        if (!$seller) $addErrors['seller'] = $addValues['seller'] === '' ? 'Choose the seller.' : 'Pick an approved seller from the suggestions.';
        if (!isset($categories[$type][$slug])) $addErrors['category'] = 'Choose a category.';
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $addValues['price']) || (float) $addValues['price'] < 1) $addErrors['price'] = 'Enter a price from ₱1 to ₱9,999,999.';
        if ($type === 'products' && (!ctype_digit($addValues['stock']) || (int) $addValues['stock'] > SF_MAX_STOCK)) {
            $addErrors['stock'] = 'Enter the stock, from 0 to ' . number_format(SF_MAX_STOCK) . '.';
        }

        if (!$addErrors) {
            // Rentals are priced per day and services per session, like the seller's own form;
            // the seller can add photos, condition and a description from their dashboard
            $unit   = $type === 'rentals' ? 'day' : ($type === 'services' ? 'session' : '');
            $price  = (float) $addValues['price'];
            $sellerId = (int) $seller['user_id'];
            $stock  = $type === 'products' ? (int) $addValues['stock'] : 0;
            $stmt = $conn->prepare("INSERT INTO listings (seller_id, type, title, category, price, stock, unit, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssdiss", $sellerId, $type, $addValues['title'], $slug, $price, $stock, $unit, $addValues['status']);
            $stmt->execute();
            $stmt->close();
            sf_notify($conn, $sellerId, 'info', 'New listing added to your store: ' . $addValues['title'],
                'An admin added this listing for you (' . strtolower($addStatuses[$addValues['status']]) . '). Add photos and a description from your seller dashboard.', 'account.php?tab=seller');
            sf_admin_log($conn, 'listing', 'Added listing', $addValues['title'] . ' · ' . $seller['store_name'], 'added');
            back($type, 'ok', '"' . $addValues['title'] . '" was added to ' . $seller['store_name'] . ' as ' . strtolower($addStatuses[$addValues['status']]) . '. The seller has been told.');
        }
    } elseif ($action === 'edit_listing') {
        $id   = (int) ($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM listings WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $listing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$listing) back($tab, 'err', 'That listing could not be found.');

        $editValues = [
            'id'       => $id,
            'type'     => $listing['type'],
            'current'  => $listing['status'],
            'title'    => trim(preg_replace('/\s+/', ' ', (string) ($_POST['title'] ?? ''))),
            'category' => (string) ($_POST['category'] ?? ''),
            'price'    => str_replace([',', ' ', '₱'], '', trim((string) ($_POST['price'] ?? ''))),
            'stock'    => $listing['type'] === 'products' ? str_replace([',', ' '], '', trim((string) ($_POST['stock'] ?? ''))) : (string) $listing['stock'],
            'status'   => (string) ($_POST['status'] ?? ''),
        ];
        // The type stays (it decides how the price works); any category of that type, including its current one
        $categories = sf_categories()[$listing['type']] ?? [];
        $editStatuses = $statuses;
        if ($listing['status'] !== 'flagged') unset($editStatuses['flagged']);   // flagging goes through Flag Listing (it needs a reason)
        if (mb_strlen($editValues['title']) < 3 || mb_strlen($editValues['title']) > 100) $editErrors['title'] = 'Enter a title (3–100 characters).';
        if (!isset($categories[$editValues['category']])) $editErrors['category'] = 'Choose a category.';
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $editValues['price']) || (float) $editValues['price'] < 1) $editErrors['price'] = 'Enter a price from ₱1 to ₱9,999,999.';
        if (!ctype_digit($editValues['stock']) || (int) $editValues['stock'] > SF_MAX_STOCK) {
            $editErrors['stock'] = 'Enter the stock, from 0 to ' . number_format(SF_MAX_STOCK) . '.';
        }
        if (!isset($editStatuses[$editValues['status']])) $editErrors['status'] = 'Choose a status.';

        if (!$editErrors) {
            $price = (float) $editValues['price'];
            $note  = $editValues['status'] === 'flagged' ? $listing['moderation_note'] : '';
            $stock = (int) $editValues['stock'];
            $stmt = $conn->prepare("UPDATE listings SET title = ?, category = ?, price = ?, stock = ?, status = ?, moderation_note = ? WHERE id = ?");
            $stmt->bind_param("ssdissi", $editValues['title'], $editValues['category'], $price, $stock, $editValues['status'], $note, $id);
            $stmt->execute();
            $stmt->close();
            sf_notify($conn, (int) $listing['seller_id'], 'info', 'An admin updated your listing: ' . $editValues['title'],
                'Its details were edited by the Station Finds team (now ' . strtolower($statuses[$editValues['status']][0]) . '). Check it from your seller dashboard.', 'account.php?tab=seller');
            sf_admin_log($conn, 'listing', 'Edited listing', $editValues['title'], 'edited');
            back($tab, 'ok', '"' . $editValues['title'] . '" was updated. The seller has been told.');
        }
    } else {
        $id   = (int) ($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM listings WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $listing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$listing) back($tab, 'err', 'That listing could not be found.');
        $title = '"' . $listing['title'] . '"';

        if ($action === 'flag') {
            $note = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($_POST['note'] ?? ''))), 0, 255);
            if ($note === '') back($tab, 'err', 'Add a reason so the seller knows what to fix.');
            sf_flag_listing($conn, $listing, $note);
            sf_admin_log($conn, 'listing', 'Flagged listing', $listing['title'], 'flagged');
            back($tab, 'ok', $title . ' was flagged and hidden from the marketplace. The seller has been told why.');

        } elseif ($action === 'approve') {
            if (!in_array($listing['status'], ['pending', 'flagged'], true)) back($tab, 'err', $title . ' doesn\'t need approval.');
            $conn->query("UPDATE listings SET status = 'active', moderation_note = '' WHERE id = " . (int) $id);
            sf_admin_log($conn, 'listing', 'Approved listing', $listing['title'], 'approved');
            sf_notify($conn, (int) $listing['seller_id'], 'info', 'Listing approved: ' . $listing['title'],
                'Your listing is live in the marketplace again.', 'account.php?tab=seller');
            back($tab, 'ok', $title . ' was approved and is live again.');

        } elseif ($action === 'delete') {
            $key  = sf_listing_key($id);
            $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM order_items WHERE item_key = ?");
            $stmt->bind_param("s", $key);
            $stmt->execute();
            $sold = (int) $stmt->get_result()->fetch_assoc()['n'];
            $stmt->close();
            if ($sold > 0) back($tab, 'err', $title . ' has orders, so it can\'t be deleted. Flag it instead to hide it.');

            $conn->query("DELETE FROM listings WHERE id = " . (int) $id);
            $stmt = $conn->prepare("DELETE FROM cart_items WHERE item_key = ?");
            $stmt->bind_param("s", $key);
            $stmt->execute();
            $stmt->close();
            foreach (sf_listing_photos($listing) as $photo) sf_delete_listing_photo($photo);
            sf_admin_log($conn, 'listing', 'Deleted listing', $listing['title'], 'deleted', true);
            sf_notify($conn, (int) $listing['seller_id'], 'info', 'Listing removed: ' . $listing['title'],
                'An admin removed this listing from Station Finds. Contact support if you have questions.', 'account.php?tab=seller');
            back($tab, 'ok', $title . ' was deleted.');
        }
    }
}

// ── Listings ────────────────────────────────────────────────────
$all = $conn->query("SELECT l.*, UNIX_TIMESTAMP(l.created_at) AS created_ts, UNIX_TIMESTAMP(l.updated_at) AS updated_ts,
           u.username, u.status AS seller_status, s.store_name,
           (SELECT COUNT(*) FROM order_items oi WHERE oi.item_key = CONCAT('listing-', l.id)) AS orders
    FROM listings l
    JOIN users u ON u.id = l.seller_id
    LEFT JOIN seller_profiles s ON s.user_id = l.seller_id
    ORDER BY FIELD(l.status, 'flagged', 'pending', 'active', 'draft'), l.created_at DESC")->fetch_all(MYSQLI_ASSOC);

$count  = fn($status) => count(array_filter($all, fn($l) => $l['status'] === $status));
$stats  = ['total' => count($all), 'active' => $count('active'), 'pending' => $count('pending'), 'flagged' => $count('flagged')];
$monthStart = strtotime(date('Y-m-01'));
$newThisMonth = count(array_filter($all, fn($l) => $l['created_ts'] >= $monthStart));
$tabCounts = ['all' => count($all)];
foreach ($types as $key => $label) $tabCounts[$key] = count(array_filter($all, fn($l) => $l['type'] === $key));
$shown = $tab === 'all' ? $all : array_filter($all, fn($l) => $l['type'] === $tab);

$categories = sf_categories();
$e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = sf_csrf_token();
$icon = fn($paths) => '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';

// "Listed 3 days ago", "Flagged 40m ago", "Submitted 2h ago"
function listing_when(array $l) {
    $verb = ['flagged' => 'Flagged', 'pending' => 'Submitted', 'draft' => 'Saved'][$l['status']] ?? 'Listed';
    $ts   = $l['status'] === 'active' ? $l['created_ts'] : $l['updated_ts'];
    $ago  = sf_time_ago($ts);
    return $verb . ' ' . ($ago === 'Just now' || $ago === 'Yesterday' ? strtolower($ago) : $ago);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Listing Management — Station Finds Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css?v=23" />
  <link rel="stylesheet" href="css/admin-shell.css?v=4" />
</head>
<body>

  <?php sf_admin_shell_start('listings', 'Search listings, sellers, categories…'); ?>

      <header class="dashboard-header">
        <div class="header-title-group">
          <h1>Listing Management</h1>
          <p>View, review, and moderate all marketplace listings</p>
        </div>
        <div class="header-actions">
          <button type="button" class="filter-button" id="filterToggle" aria-expanded="false" aria-controls="listingFilters"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18l-7 8v8l-4-2v-6Z"/></svg>Filters</button>
          <button type="button" class="export-button" onclick="document.getElementById('addListingModal').showModal()">+ Add Listing</button>
        </div>
      </header>

      <div class="dashboard-content">
        <?php if ($flash): ?>
        <div class="adm-flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>" role="status"><?php echo $e($flash[1]); ?></div>
        <?php endif; ?>

        <div class="filter-panel" id="listingFilters" hidden>
          <label for="statusFilter">Status</label>
          <select id="statusFilter">
            <option value="all">All statuses</option>
            <?php foreach ($statuses as $key => [$label]): ?><option value="<?php echo $key; ?>"><?php echo $label; ?></option><?php endforeach; ?>
          </select>
        </div>

        <section class="stats-grid">
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Total listings</span><div class="stat-icon-wrapper"><?php echo $icon('<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> +<?php echo $newThisMonth; ?> this month</p>
          </div>
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Active</span><div class="stat-icon-wrapper"><?php echo $icon('<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['active']); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> <?php echo $stats['total'] ? round($stats['active'] / $stats['total'] * 100) : 0; ?>% of total</p>
          </div>
          <button type="button" class="stat-card stat-filter" data-status="pending">
            <div class="stat-card-top"><span class="stat-label">Pending review</span><div class="stat-icon-wrapper pending-icon"><?php echo $icon('<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['pending']); ?></div>
            <p class="stat-trend<?php echo $stats['pending'] ? ' attention' : ''; ?>"><?php echo $stats['pending'] ? '⊙ Needs attention' : 'Nothing waiting'; ?></p>
          </button>
          <button type="button" class="stat-card stat-filter" data-status="flagged">
            <div class="stat-card-top"><span class="stat-label">Flagged</span><div class="stat-icon-wrapper flagged-icon"><?php echo $icon('<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['flagged']); ?></div>
            <p class="stat-trend<?php echo $stats['flagged'] ? ' danger' : ''; ?>"><?php echo $stats['flagged'] ? '⊙ Requires review' : 'None flagged'; ?></p>
          </button>
        </section>

        <nav class="users-tabs" aria-label="Filter by type">
          <?php foreach ($tabs as $key => $label): ?>
          <a href="admin_listings.php?tab=<?php echo $key; ?>" class="users-tab<?php echo $tab === $key ? ' active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo $label; ?> <span><?php echo $tabCounts[$key]; ?></span></a>
          <?php endforeach; ?>
        </nav>

        <section class="users-card">
          <div class="table-scroll">
            <table class="users-table listings-table">
              <thead><tr><th>Listing</th><th>Seller</th><th>Category</th><th>Price</th><th>Status</th><th class="col-action">Action</th></tr></thead>
              <tbody id="listingsBody">
                <?php foreach ($shown as $l):
                  [$statusLabel, $chip] = $statuses[$l['status']] ?? [ucfirst($l['status']), 'draft'];
                  $seller   = $l['store_name'] ?: $l['username'];
                  $category = $categories[$l['type']][$l['category']] ?? 'Other';
                  $photos   = sf_listing_photos($l);
                  $info = [
                      'id' => (int) $l['id'], 'title' => $l['title'], 'seller' => $seller, 'username' => $l['username'],
                      'sellerSuspended' => $l['seller_status'] === 'suspended', 'type' => $types[$l['type']] ?? $l['type'],
                      'category' => $category, 'condition' => SF_CONDITIONS[$l['term']] ?? '', 'price' => sf_listing_price($l),
                      'status' => $l['status'], 'statusLabel' => $statusLabel, 'note' => $l['moderation_note'],
                      'description' => $l['description'], 'location' => $l['location'], 'photo' => $photos[0] ?? '',
                      'photos' => count($photos), 'orders' => (int) $l['orders'], 'listed' => date('M j, Y', $l['created_ts']),
                      'typeKey' => $l['type'], 'categoryKey' => $l['category'], 'rawPrice' => rtrim(rtrim($l['price'], '0'), '.'), 'stock' => (int) $l['stock'],
                  ];
                ?>
                <tr data-search="<?php echo $e(mb_strtolower($l['title'] . ' ' . $seller . ' ' . $l['username'] . ' ' . $category)); ?>" data-status="<?php echo $e($l['status']); ?>">
                  <td>
                    <div class="listing-cell">
                      <?php if ($photos): ?><img src="<?php echo $e($photos[0]); ?>" alt="" class="listing-thumb" />
                      <?php else: ?><span class="listing-thumb icon<?php echo $l['status'] === 'flagged' ? ' danger' : ''; ?>"><?php echo $icon('<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>'); ?></span><?php endif; ?>
                      <span><strong><?php echo $e($l['title']); ?></strong><span class="<?php echo $l['status'] === 'flagged' ? 'danger-text' : ''; ?>"><?php echo $e(listing_when($l)); ?></span></span>
                    </div>
                  </td>
                  <td><?php echo $e($seller); ?><?php if ($l['seller_status'] === 'suspended'): ?> <span class="status-chip suspended">Seller suspended</span><?php endif; ?></td>
                  <td><span class="category-chip"><?php echo $e($category); ?></span></td>
                  <td class="price-cell"><?php echo $e(sf_listing_price($l)); ?></td>
                  <td><span class="status-chip <?php echo $chip; ?>"><?php echo $statusLabel; ?></span></td>
                  <td class="col-action"><button type="button" class="manage-btn<?php echo $l['status'] === 'flagged' ? ' danger' : ''; ?>" onclick='openManage(<?php echo json_encode($info, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'>Manage</button></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="empty-state" id="emptyListings"<?php echo $shown ? ' hidden' : ''; ?>><?php echo $shown ? 'No listings match your search.' : 'No ' . ($tab === 'all' ? '' : strtolower($tabs[$tab]) . ' ') . 'listings yet.'; ?></p>
        </section>
      </div>

  <!-- ==================== MANAGE LISTING MODAL ==================== -->
  <dialog class="adm-modal card-modal" id="manageModal" aria-labelledby="mHeading">
    <div class="card-modal-head">
      <h2 id="mHeading">Manage Listing</h2>
      <button type="button" class="adm-modal-close" onclick="closeManage()" aria-label="Close">&times;</button>
    </div>

    <div id="mView">
      <div class="card-modal-who">
        <strong id="mTitle"></strong>
        <span id="mSeller"></span>
      </div>
      <dl class="card-facts">
        <div><dt>Category</dt><dd id="mCategory"></dd></div>
        <div><dt>Price</dt><dd id="mPrice"></dd></div>
        <div><dt>Status</dt><dd><span class="review-status" id="mStatus"></span></dd></div>
        <div><dt>Date Listed</dt><dd id="mListed"></dd></div>
      </dl>
      <p class="card-modal-note danger-text" id="mNote"></p>

      <form method="post" id="mForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
        <input type="hidden" name="id" id="mId" />
        <div class="adm-field" id="mReasonField" hidden>
          <label for="mReason">Reason for flagging <span>(sent to the seller)</span></label>
          <input type="text" id="mReason" name="note" maxlength="255" placeholder="e.g. Photos don't match the item, or the price looks like a typo" />
        </div>
        <div class="card-modal-actions">
          <button type="button" class="filter-button outline-yellow" id="mFlag">Flag Listing</button>
          <button type="submit" name="action" value="flag" class="filter-button outline-yellow" id="mFlagConfirm" hidden>Confirm flag</button>
          <button type="submit" name="action" value="approve" class="filter-button outline-yellow" id="mApprove" hidden></button>
          <button type="button" class="export-button" onclick="showListingEdit(true)">Edit Details</button>
        </div>
        <button type="submit" name="action" value="delete" class="danger-link" id="mDelete">Remove Listing Immediately</button>
        <p class="card-modal-note" id="mDeleteNote"></p>
      </form>
    </div>

    <form method="post" id="mEditForm" hidden novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
      <input type="hidden" name="action" value="edit_listing" />
      <input type="hidden" name="id" id="eId" value="<?php echo $editValues ? (int) $editValues['id'] : ''; ?>" />
      <?php $eErr = fn($k) => isset($editErrors[$k]) ? '<span class="adm-field-error">' . $e($editErrors[$k]) . '</span>' : ''; ?>
      <?php $eBad = fn($k) => isset($editErrors[$k]) ? ' class="has-error"' : ''; ?>
      <div class="editing-box">
        <span class="editing-icon" id="eThumb"><?php echo $icon('<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>'); ?></span>
        <span><span>Currently Editing Listing</span><strong id="eCurrent"></strong></span>
      </div>
      <div class="adm-field">
        <label for="e_title">Listing Title</label>
        <input type="text" id="e_title" name="title" maxlength="100" autocomplete="off" value="<?php echo $e($editValues['title'] ?? ''); ?>"<?php echo $eBad('title'); ?> />
        <?php echo $eErr('title'); ?>
      </div>
      <div class="adm-field">
        <label for="e_category">Category</label>
        <select id="e_category" name="category"<?php echo $eBad('category'); ?>></select>
        <?php echo $eErr('category'); ?>
      </div>
      <div class="adm-field">
        <label for="e_price">Price (₱) <span id="e_unit"></span></label>
        <input type="text" id="e_price" name="price" inputmode="decimal" maxlength="12" autocomplete="off" value="<?php echo $e($editValues['price'] ?? ''); ?>"<?php echo $eBad('price'); ?> />
        <?php echo $eErr('price'); ?>
      </div>
      <div class="adm-field" id="eStockField" hidden>
        <label for="e_stock">Stock quantity</label>
        <input type="number" id="e_stock" name="stock" min="0" max="<?php echo SF_MAX_STOCK; ?>" step="1" inputmode="numeric" value="<?php echo $e($editValues['stock'] ?? ''); ?>"<?php echo $eBad('stock'); ?> />
        <?php echo $eErr('stock'); ?>
      </div>
      <div class="adm-field">
        <label for="e_status">Status</label>
        <select id="e_status" name="status" class="status-select<?php echo isset($editErrors['status']) ? ' has-error' : ''; ?>"></select>
        <?php echo $eErr('status'); ?>
      </div>
      <div class="card-modal-split">
        <button type="button" class="plain-link" onclick="showListingEdit(false)">Cancel</button>
        <button type="submit" class="export-button">Save Changes</button>
      </div>
    </form>
  </dialog>

  <!-- ==================== ADD LISTING MODAL ==================== -->
  <dialog class="adm-modal" id="addListingModal" aria-labelledby="addListingTitle">
    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
      <input type="hidden" name="action" value="add_listing" />
      <div class="adm-modal-head">
        <h2 id="addListingTitle">+ Add listing</h2>
        <button type="button" class="adm-modal-close" onclick="document.getElementById('addListingModal').close()" aria-label="Close">&times;</button>
      </div>
      <?php $err = fn($k) => isset($addErrors[$k]) ? '<span class="adm-field-error">' . $e($addErrors[$k]) . '</span>' : ''; ?>
      <?php $bad = fn($k) => isset($addErrors[$k]) ? ' class="has-error"' : ''; ?>
      <div class="adm-field">
        <label for="al_title">Listing title</label>
        <input type="text" id="al_title" name="title" value="<?php echo $e($addValues['title']); ?>" maxlength="100" autocomplete="off" placeholder="e.g. Canon AE-1 Camera" required<?php echo $bad('title'); ?> />
        <?php echo $err('title'); ?>
      </div>
      <div class="adm-field">
        <label for="al_seller">Seller name or search</label>
        <input type="search" id="al_seller" name="seller" list="sellerList" value="<?php echo $e($addValues['seller']); ?>" autocomplete="off" placeholder="Search campus sellers…" required<?php echo $bad('seller'); ?> />
        <datalist id="sellerList">
          <?php foreach ($sellers as $label => $s): ?><option value="<?php echo $e($label); ?>"><?php echo $e($s['username']); ?></option><?php endforeach; ?>
        </datalist>
        <?php echo $err('seller') ?: ($sellers ? '' : '<span class="adm-field-hint">No approved sellers yet: approve one in Seller Applications first.</span>'); ?>
      </div>
      <div class="adm-field">
        <label for="al_category">Category</label>
        <select id="al_category" name="category" required<?php echo $bad('category'); ?>>
          <option value="">Choose a category</option>
          <?php foreach (sf_categories(true) as $t => $cats): ?>
          <optgroup label="<?php echo $e($types[$t] ?? ucfirst($t)); ?>">
            <?php foreach ($cats as $slug => $name): $value = $t . '|' . $slug; ?>
            <option value="<?php echo $e($value); ?>"<?php echo $addValues['category'] === $value ? ' selected' : ''; ?>><?php echo $e(($types[$t] ?? ucfirst($t)) . ' · ' . $name); ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
        <?php echo $err('category'); ?>
      </div>
      <div class="adm-field">
        <label for="al_price">Price <span id="al_unit"></span></label>
        <input type="text" id="al_price" name="price" value="<?php echo $e($addValues['price']); ?>" inputmode="decimal" maxlength="12" autocomplete="off" placeholder="₱0.00" required<?php echo $bad('price'); ?> />
        <?php echo $err('price'); ?>
      </div>
      <div class="adm-field" id="alStockField" hidden>
        <label for="al_stock">Stock quantity</label>
        <input type="number" id="al_stock" name="stock" value="<?php echo $e($addValues['stock']); ?>" min="0" max="<?php echo SF_MAX_STOCK; ?>" step="1" inputmode="numeric" autocomplete="off"<?php echo $bad('stock'); ?> />
        <?php echo $err('stock'); ?>
      </div>
      <div class="adm-field">
        <label for="al_status">Status</label>
        <select id="al_status" name="status">
          <?php foreach ($addStatuses as $key => $label): ?>
          <option value="<?php echo $key; ?>"<?php echo $addValues['status'] === $key ? ' selected' : ''; ?>><?php echo $label; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="adm-modal-actions add-user-actions">
        <button type="button" class="filter-button" onclick="document.getElementById('addListingModal').close()">Cancel</button>
        <button type="submit" class="export-button">Add Listing</button>
      </div>
    </form>
  </dialog>

  <?php sf_admin_shell_end(); ?>

  <script>
    const modal = document.getElementById('manageModal');
    function closeManage() { modal.close(); }

    // Add Listing: show what the price is per, and re-open with errors after a failed save
    const addModal = document.getElementById('addListingModal');
    addModal.addEventListener('click', e => { if (e.target === addModal) addModal.close(); });
    const alCategory = document.getElementById('al_category');
    const syncUnit = () => {
      const type = alCategory.value.split('|')[0];
      document.getElementById('al_unit').textContent = type === 'rentals' ? '(per day)' : type === 'services' ? '(per session)' : '';
      document.getElementById('alStockField').hidden = type !== 'products';   // only products have stock
    };
    alCategory.addEventListener('change', syncUnit);
    syncUnit();
    <?php if ($addErrors): ?>addModal.showModal();<?php endif; ?>
    modal.addEventListener('click', e => { if (e.target === modal) modal.close(); });

    // Categories per type (inactive ones too, so a listing's own category always shows) and statuses
    const allCategories = <?php echo json_encode(sf_categories(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const typeLabels = <?php echo json_encode($types); ?>;
    const statusLabels = <?php echo json_encode(array_map(fn($s) => $s[0], $statuses)); ?>;
    const statusTones = { active: 'resolved', pending: 'pending', flagged: 'flagged', draft: 'dismissed' };

    function openManage(l) {
      const set = (id, text) => { document.getElementById(id).textContent = text; };
      set('mTitle', l.title);
      set('mSeller', 'Seller: ' + l.seller + (l.sellerSuspended ? ' (account suspended)' : ''));
      set('mCategory', l.type + ' · ' + l.category);
      set('mPrice', l.price + (l.typeKey === 'products' ? ' · ' + (l.stock > 0 ? l.stock + ' in stock' : 'Out of stock') : ''));
      const status = document.getElementById('mStatus');
      status.textContent = l.statusLabel;
      status.className = 'review-status ' + (statusTones[l.status] || '');
      set('mListed', l.listed);
      set('mNote', l.note ? 'Flag reason: ' + l.note : '');
      document.getElementById('mId').value = l.id;

      // Flag (asks for a reason first) or, for flagged / pending listings, approve
      const flagged = l.status === 'flagged', pending = l.status === 'pending';
      const reason = document.getElementById('mReason');
      reason.value = '';
      reason.classList.remove('has-error');
      document.getElementById('mReasonField').hidden = true;
      document.getElementById('mFlagConfirm').hidden = true;
      document.getElementById('mFlag').hidden = flagged;
      document.getElementById('mFlag').textContent = pending ? 'Keep Flagged' : 'Flag Listing';
      const approve = document.getElementById('mApprove');
      approve.hidden = !(flagged || pending);
      approve.textContent = pending ? 'Approve Changes' : 'Restore Listing';
      document.getElementById('mDelete').hidden = l.orders > 0;
      set('mDeleteNote', l.orders > 0 ? 'This listing has orders, so it can\'t be removed. Flag it to hide it instead.' : '');

      fillEdit(l.typeKey, l.status, { title: l.title, category: l.categoryKey, price: l.rawPrice, stock: l.stock, status: l.status }, l.photo);
      document.getElementById('eId').value = l.id;
      document.querySelectorAll('#mEditForm .adm-field-error').forEach(el => el.remove());
      document.querySelectorAll('#mEditForm .has-error').forEach(el => el.classList.remove('has-error'));
      showListingEdit(false);
      modal.showModal();
    }

    // Edit Details form: categories of the listing's type; Flagged only if it already is
    function fillEdit(type, current, v, photo) {
      const typeLabel = typeLabels[type] || type;
      const cat = document.getElementById('e_category');
      cat.innerHTML = '';
      Object.entries(allCategories[type] || {}).forEach(([slug, name]) => cat.add(new Option(typeLabel + ' · ' + name, slug)));
      cat.value = v.category;
      document.getElementById('e_unit').textContent = type === 'rentals' ? '(per day)' : type === 'services' ? '(per session)' : '';
      const st = document.getElementById('e_status');
      st.innerHTML = '';
      Object.entries(statusLabels).forEach(([key, label]) => { if (key !== 'flagged' || current === 'flagged') st.add(new Option(label, key)); });
      st.value = v.status;
      tintStatus();
      document.getElementById('e_title').value = v.title;
      document.getElementById('e_price').value = v.price;
      document.getElementById('e_stock').value = v.stock;
      document.getElementById('eStockField').hidden = type !== 'products';

      // "Currently Editing Listing": its cover photo when it has one
      document.getElementById('eCurrent').textContent = v.title;
      const thumb = document.getElementById('eThumb');
      thumb.querySelector('img')?.remove();
      thumb.classList.toggle('has-photo', !!photo);
      if (photo) { const img = new Image(); img.src = photo; img.alt = ''; thumb.appendChild(img); }
    }
    // The Status box takes the colour of the chosen status
    function tintStatus() {
      const st = document.getElementById('e_status');
      st.className = 'status-select ' + (statusTones[st.value] || '');
    }
    document.getElementById('e_status').addEventListener('change', tintStatus);
    function showListingEdit(on) {
      document.getElementById('mView').hidden = on;
      document.getElementById('mEditForm').hidden = !on;
      document.getElementById('mHeading').textContent = on ? 'Edit Listing Details' : 'Manage Listing';
      if (on) document.getElementById('e_title').focus();
    }
    document.getElementById('mFlag').addEventListener('click', () => {
      document.getElementById('mReasonField').hidden = false;
      document.getElementById('mFlag').hidden = true;
      document.getElementById('mFlagConfirm').hidden = false;
      document.getElementById('mReason').focus();
    });
    <?php if ($editErrors): ?>
    // Show what needs fixing
    fillEdit(<?php echo json_encode($editValues['type']); ?>, <?php echo json_encode($editValues['current']); ?>, <?php echo json_encode(['title' => $editValues['title'], 'category' => $editValues['category'], 'price' => $editValues['price'], 'stock' => $editValues['stock'], 'status' => $editValues['status']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
    showListingEdit(true);
    modal.showModal();
    <?php endif; ?>

    document.getElementById('mForm').addEventListener('submit', e => {
      const action = e.submitter ? e.submitter.value : '';
      if (action === 'flag' && !document.getElementById('mReason').value.trim()) {
        e.preventDefault();
        document.getElementById('mReason').focus();
        document.getElementById('mReason').classList.add('has-error');
      } else if (action === 'delete' && !confirm('Remove this listing and its photos? This cannot be undone.')) {
        e.preventDefault();
      }
    });

    // Search (top bar), status filter (Filters), and the Pending / Flagged cards
    const rows = [...document.querySelectorAll('#listingsBody tr')];
    const statusFilter = document.getElementById('statusFilter');
    let query = '';
    function applyFilters() {
      rows.forEach(r => {
        r.hidden = !r.dataset.search.includes(query) || (statusFilter.value !== 'all' && r.dataset.status !== statusFilter.value);
      });
      if (rows.length) document.getElementById('emptyListings').hidden = rows.some(r => !r.hidden);
      document.querySelectorAll('.stat-filter').forEach(c => c.classList.toggle('selected', c.dataset.status === statusFilter.value));
    }
    window.onAdminSearch = q => { query = q; applyFilters(); };
    statusFilter.addEventListener('change', applyFilters);
    document.querySelectorAll('.stat-filter').forEach(card => card.addEventListener('click', () => {
      statusFilter.value = statusFilter.value === card.dataset.status ? 'all' : card.dataset.status;
      applyFilters();
    }));
    document.getElementById('filterToggle').addEventListener('click', e => {
      const panel = document.getElementById('listingFilters');
      panel.hidden = !panel.hidden;
      e.currentTarget.setAttribute('aria-expanded', String(!panel.hidden));
    });
  </script>
</body>
</html>
