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

$userId = (int) $_SESSION['user_id'];
$profile = sf_seller_profile($conn, $userId);
if (!$profile || $profile['status'] !== 'approved') {   // only sellers an admin has approved can list
    header('Location: ' . ($profile ? 'account.php' : 'become_seller.php'));
    exit;
}

// listing_form.php = new listing, listing_form.php?id=12 = edit listing 12
$listing = null;
if (isset($_GET['id'])) {
    $listing = sf_my_listing($conn, $userId, (int) $_GET['id']);
    if (!$listing) {
        $_SESSION['flash'] = ['err', 'That listing could not be found.'];
        header('Location: account.php?tab=seller');
        exit;
    }
}

$types = [
    'products' => ['Product', 'Sell physical items like textbooks, clothes, electronics.'],
    'rentals'  => ['Rental',  'Rent out items on a daily basis (bikes, calculators, gowns).'],
    'services' => ['Service', 'Offer skills, tutoring, moving assistance, laundry.'],
];
$typeIcons = [
    'products' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line>',
    'rentals'  => '<circle cx="7.5" cy="15.5" r="5.5"></circle><path d="m21 2-9.6 9.6"></path><path d="m15.5 7.5 3 3L22 7l-3-3"></path>',
    'services' => '<rect x="2" y="7" width="20" height="14" rx="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>',
];
$units  = ['hr' => 'per hour', 'session' => 'per session', 'slide' => 'per slide', 'shoot' => 'per shoot', 'job' => 'per job', 'order' => 'per order'];
$errors = [];
$v      = $listing ?? ['type' => $_GET['type'] ?? 'products', 'title' => '', 'category' => '', 'term' => '', 'price' => '', 'stock' => '1',
                       'unit' => '', 'description' => '', 'location' => '', 'image' => '', 'photos' => null];
if (!isset($types[$v['type']])) $v['type'] = 'products';
$photos = sf_listing_photos($v);    // photos already saved on this listing, cover first

// Categories to offer: active ones, plus this listing's own category if an admin has since turned it off
$categoryOptions = sf_categories(true);
if ($listing && !isset($categoryOptions[$listing['type']][$listing['category']])) {
    $allCategories = sf_categories();
    if (isset($allCategories[$listing['type']][$listing['category']])) {
        $categoryOptions[$listing['type']][$listing['category']] = $allCategories[$listing['type']][$listing['category']];
    }
}

// ── Form submission ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors['form'] = 'Those photos are too large to upload together. Each photo can be up to 5 MB.';
    } elseif (!sf_check_csrf()) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $type = isset($types[$_POST['type'] ?? '']) ? $_POST['type'] : 'products';
        $v = [
            'type'        => $type,
            'title'       => trim(preg_replace('/\s+/', ' ', (string) ($_POST['title'] ?? ''))),
            'category'    => (string) ($_POST['category_' . $type] ?? ''),
            'term'        => $type === 'services' ? '' : (string) ($_POST['condition'] ?? ''),
            'price'       => str_replace([',', ' '], '', trim((string) ($_POST['price'] ?? ''))),
            // How many are for sale (products only; rentals and services aren't counted)
            'stock'       => $type === 'products' ? str_replace([',', ' '], '', trim((string) ($_POST['stock'] ?? ''))) : '0',
            'unit'        => $type === 'rentals' ? 'day' : ($type === 'services' ? (string) ($_POST['unit'] ?? '') : ''),
            'description' => trim(str_replace("\r\n", "\n", (string) ($_POST['description'] ?? ''))),
            'location'    => trim(preg_replace('/\s+/', ' ', (string) ($_POST['location'] ?? ''))),
        ];
        $status = ($_POST['status'] ?? '') === 'draft' ? 'draft' : 'active';
        // A listing an admin flagged can't go live by itself: publishing sends it for review,
        // and saving a draft keeps its current state
        if ($listing && in_array($listing['status'], ['flagged', 'pending'], true)) {
            $status = $status === 'active' ? 'pending' : $listing['status'];
        }

        if (mb_strlen($v['title']) < 3 || mb_strlen($v['title']) > 100) $errors['title'] = 'Please enter a title (3–100 characters).';
        if (!isset($categoryOptions[$type][$v['category']])) $errors['category'] = 'Please choose a category.';
        if ($type !== 'services' && !isset(SF_CONDITIONS[$v['term']])) $errors['condition'] = 'Please choose the condition.';
        if ($type === 'services' && !isset($units[$v['unit']])) $errors['unit'] = 'Please choose how you charge.';
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $v['price']) || (float) $v['price'] < 1) $errors['price'] = 'Enter a price from ₱1 to ₱9,999,999.';
        if ($type === 'products' && (!ctype_digit($v['stock']) || (int) $v['stock'] > SF_MAX_STOCK)) {
            $errors['stock'] = 'Enter how many you have, from 0 to ' . number_format(SF_MAX_STOCK) . '.';
        }
        if (mb_strlen($v['location']) > 120) $errors['location'] = 'Please keep the location under 120 characters.';
        if (mb_strlen($v['description']) > 1000) $errors['description'] = 'Please keep the description under 1,000 characters.';

        // Photos: keep the saved ones not marked for removal, then add the new uploads
        $remove  = array_map('strval', (array) ($_POST['remove_photos'] ?? []));
        $kept    = array_values(array_filter($photos, fn($p) => !in_array($p, $remove, true)));
        $removed = array_values(array_diff($photos, $kept));
        $uploads = sf_uploaded_files('photos');
        $checked = [];
        foreach ($uploads as $file) {
            [$ext, $photoError] = sf_check_listing_photo($file);
            if ($photoError) { $errors['photos'] = $photoError; break; }
            $checked[] = [$file, $ext];
        }
        if (!isset($errors['photos']) && count($kept) + count($checked) > SF_MAX_PHOTOS) {
            $errors['photos'] = 'You can add up to ' . SF_MAX_PHOTOS . ' photos.';
        }
        // The browser forgets chosen files when the page reloads with errors
        if ($errors && $uploads && !isset($errors['photos'])) {
            $errors['photos'] = 'Please choose your new photos again.';
        }

        if (!$errors) {
            foreach ($checked as [$file, $ext]) {
                $saved = sf_save_listing_photo($file, $ext);
                if ($saved) $kept[] = $saved;
            }
            $cover      = $kept[0] ?? '';
            $photosJson = json_encode($kept);

            if ($listing) {
                $stmt = $conn->prepare("UPDATE listings SET type = ?, title = ?, category = ?, term = ?, price = ?, stock = ?, unit = ?, description = ?,
                                               location = ?, image = ?, photos = ?, status = ?
                                        WHERE id = ? AND seller_id = ?");
                $stock = (int) $v['stock'];
                $stmt->bind_param("ssssdissssssii", $v['type'], $v['title'], $v['category'], $v['term'], $v['price'], $stock, $v['unit'],
                    $v['description'], $v['location'], $cover, $photosJson, $status, $listing['id'], $userId);
            } else {
                $stmt = $conn->prepare("INSERT INTO listings (seller_id, type, title, category, term, price, stock, unit, description, location, image, photos, status)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stock = (int) $v['stock'];
                $stmt->bind_param("issssdissssss",$userId, $v['type'], $v['title'], $v['category'], $v['term'], $v['price'], $stock, $v['unit'],
                    $v['description'], $v['location'], $cover, $photosJson, $status);
            }
            $stmt->execute();
            $stmt->close();
            foreach ($removed as $photo) sf_delete_listing_photo($photo);

            $messages = [
                'active'  => $listing ? 'was updated and is live in the marketplace.' : 'is now live in the marketplace.',
                'draft'   => 'was saved as a draft. Publish it when you\'re ready.',
                'pending' => 'was sent to an admin for review. It goes live once it\'s approved.',
                'flagged' => 'was saved. It stays hidden until you publish it for review.',
            ];
            $_SESSION['flash'] = ['ok', '"' . $v['title'] . '" ' . $messages[$status]];
            header('Location: account.php?tab=seller');
            exit;
        }
        // Show the form again with what was typed; saved photos marked for removal stay marked
    }
}

$e   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$err = fn($key) => isset($errors[$key]) ? '<span class="field-error">' . $e($errors[$key]) . '</span>' : '';
$bad = fn($key) => isset($errors[$key]) ? ' has-error' : '';
// "450.00" from the database shows as "450"
$priceValue = is_numeric($v['price']) && (float) $v['price'] == floor((float) $v['price']) ? (string) (int) $v['price'] : (string) $v['price'];
$markedForRemoval = array_map('strval', (array) ($_POST['remove_photos'] ?? []));
$pageTitle = $listing ? 'Edit listing' : 'Create a listing';
$priceLabels = ['products' => 'Price (₱)', 'rentals' => 'Price per day (₱)', 'services' => 'Price (₱)'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $pageTitle; ?> — Station Finds</title>
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

  <main class="shop-wrapper">
    <nav class="breadcrumb" aria-label="Breadcrumb">
      <a href="account.php?tab=seller">Seller Dashboard</a>
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
      <span aria-current="page"><?php echo $pageTitle; ?></span>
    </nav>

    <h1 class="shop-title"><?php echo $pageTitle; ?></h1>
    <p class="shop-subtitle">Choose what you'd like to list, then fill in the details for students to see.</p>

    <form method="post" enctype="multipart/form-data" id="listingForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo $e(sf_csrf_token()); ?>" />

      <?php if (isset($errors['form'])): ?><div class="alert-error type-alert"><?php echo $e($errors['form']); ?></div><?php endif; ?>

      <!-- What kind of listing -->
      <fieldset class="type-grid">
        <legend class="visually-hidden">Listing type</legend>
        <?php foreach ($types as $key => [$label, $hint]): ?>
        <label class="type-card">
          <input type="radio" name="type" value="<?php echo $key; ?>"<?php echo $v['type'] === $key ? ' checked' : ''; ?> />
          <span class="type-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $typeIcons[$key]; ?></svg></span>
          <strong><?php echo $label; ?></strong>
          <span><?php echo $hint; ?></span>
        </label>
        <?php endforeach; ?>
      </fieldset>

      <div class="card listing-form-card">
        <div class="form-field">
          <label class="form-label" for="title">Listing Title</label>
          <input type="text" id="title" name="title" class="text-input<?php echo $bad('title'); ?>" value="<?php echo $e($v['title']); ?>" maxlength="100" placeholder="e.g. Vintage film camera (Canon AE-1) - Working well" required />
          <?php echo $err('title'); ?>
        </div>

        <div class="form-row-3">
          <div class="form-field">
            <label class="form-label" for="category_<?php echo $e($v['type']); ?>" id="categoryLabel">Category</label>
            <?php foreach ($categoryOptions as $type => $cats): ?>
            <select id="category_<?php echo $type; ?>" name="category_<?php echo $type; ?>" class="text-input<?php echo $bad('category'); ?>" data-show-for="<?php echo $type; ?>"<?php echo $v['type'] === $type ? '' : ' hidden'; ?>>
              <option value="">Select category...</option>
              <?php foreach ($cats as $key => $label): ?>
              <option value="<?php echo $e($key); ?>"<?php echo $v['type'] === $type && $v['category'] === $key ? ' selected' : ''; ?>><?php echo $e($label); ?></option>
              <?php endforeach; ?>
            </select>
            <?php endforeach; ?>
            <?php echo $err('category'); ?>
          </div>

          <div class="form-field" data-show-for="products rentals"<?php echo $v['type'] === 'services' ? ' hidden' : ''; ?>>
            <label class="form-label" for="condition">Condition</label>
            <select id="condition" name="condition" class="text-input<?php echo $bad('condition'); ?>">
              <option value="">Select condition...</option>
              <?php foreach (SF_CONDITIONS as $key => $label): ?>
              <option value="<?php echo $key; ?>"<?php echo $v['term'] === $key ? ' selected' : ''; ?>><?php echo $label; ?></option>
              <?php endforeach; ?>
            </select>
            <?php echo $err('condition'); ?>
          </div>

          <div class="form-field" data-show-for="services"<?php echo $v['type'] === 'services' ? '' : ' hidden'; ?>>
            <label class="form-label" for="unit">Charge</label>
            <select id="unit" name="unit" class="text-input<?php echo $bad('unit'); ?>">
              <option value="">Select pricing...</option>
              <?php foreach ($units as $key => $label): ?>
              <option value="<?php echo $key; ?>"<?php echo $v['unit'] === $key ? ' selected' : ''; ?>><?php echo ucfirst($label); ?></option>
              <?php endforeach; ?>
            </select>
            <?php echo $err('unit'); ?>
          </div>

          <div class="form-field">
            <label class="form-label" for="price" id="priceLabel"><?php echo $priceLabels[$v['type']]; ?></label>
            <input type="text" id="price" name="price" class="text-input<?php echo $bad('price'); ?>" value="<?php echo $e($priceValue); ?>" inputmode="decimal" maxlength="12" placeholder="e.g. 4500" required />
            <?php echo $err('price'); ?>
          </div>
        </div>

        <div class="form-row-loc">
        <div class="form-field">
          <label class="form-label" for="location">Campus / Location</label>
          <input type="text" id="location" name="location" class="text-input<?php echo $bad('location'); ?>" value="<?php echo $e($v['location']); ?>" maxlength="120" placeholder="e.g. Katipunan Quezon City / Ateneo De Manila" autocomplete="off" />
          <?php echo $err('location'); ?>
        </div>

        <div class="form-field" data-show-for="products"<?php echo $v['type'] === 'products' ? '' : ' hidden'; ?>>
          <label class="form-label" for="stock">Stock Quantity</label>
          <input type="number" id="stock" name="stock" class="text-input<?php echo $bad('stock'); ?>" value="<?php echo $e($v['stock']); ?>" min="0" max="<?php echo SF_MAX_STOCK; ?>" step="1" inputmode="numeric" placeholder="e.g. 5" />
          <?php if (isset($errors['stock'])): echo $err('stock'); else: ?><span class="field-hint">How many you can sell. Buyers can't order more than this; it goes down with each order.</span><?php endif; ?>
        </div>
        </div>

        <div class="form-field">
          <label class="form-label" for="description">Description</label>
          <textarea id="description" name="description" class="text-input<?php echo $bad('description'); ?>" maxlength="1000" placeholder="Provide details about the item's condition, campus meetup preferences, and bundle inclusions..."><?php echo $e($v['description']); ?></textarea>
          <?php echo $err('description'); ?>
        </div>

        <div class="form-field">
          <span class="form-label" id="photosLabel">Photos</span>

          <?php if ($photos): ?>
          <!-- Photos already on this listing: tick to remove -->
          <div class="photo-grid" aria-label="Current photos">
            <?php foreach ($photos as $i => $photo): $marked = in_array($photo, $markedForRemoval, true); ?>
            <label class="photo-thumb<?php echo $marked ? ' removing' : ''; ?>">
              <img src="<?php echo $e($photo); ?>" alt="Photo <?php echo $i + 1; ?>" />
              <?php if ($i === 0): ?><span class="cover-tag">Cover</span><?php endif; ?>
              <input type="checkbox" name="remove_photos[]" value="<?php echo $e($photo); ?>" class="saved-remove"<?php echo $marked ? ' checked' : ''; ?> />
              <span class="photo-remove-btn" title="Remove photo" aria-label="Remove photo <?php echo $i + 1; ?>">&times;</span>
            </label>
            <?php endforeach; ?>
          </div>
          <p class="photo-removed-note" id="removedNote" hidden>
            <span id="removedText"></span>
            <button type="button" id="undoRemove">Undo</button>
          </p>
          <?php endif; ?>

          <label class="dropzone<?php echo $bad('photos'); ?>" id="dropzone" for="photos">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
            <span class="dropzone-title">Drag and drop photos here, or click to browse</span>
            <span class="dropzone-hint">Supported formats: JPG, PNG, WebP • Max 5MB per file • Up to <?php echo SF_MAX_PHOTOS; ?> photos</span>
            <input type="file" id="photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple aria-labelledby="photosLabel" />
          </label>
          <div class="photo-grid" id="newPhotos" aria-label="Photos to upload"></div>
          <?php echo $err('photos'); ?>
        </div>

        <div class="form-actions">
          <?php if ($listing): ?><a href="account.php?tab=seller" class="btn-outline">Cancel</a><?php endif; ?>
          <button type="submit" name="status" value="draft" class="btn-outline">Save Draft</button>
          <button type="submit" name="status" value="active" class="btn-primary"><?php echo $listing && $listing['status'] === 'active' ? 'Save Changes' : 'Publish Listing'; ?></button>
        </div>
      </div>
    </form>
  </main>

  <?php sf_render_footer(); ?>

  <script src="js/cart.js?v=6"></script>
  <script src="js/inbox.js?v=4"></script>
  <script>
    const MAX_PHOTOS = <?php echo SF_MAX_PHOTOS; ?>;
    const MAX_BYTES  = <?php echo SF_UPLOAD_MAX_BYTES; ?>;
    const PRICE_LABELS = <?php echo json_encode($priceLabels, JSON_UNESCAPED_UNICODE); ?>;

    // ── Listing type: show the fields that apply ──
    function showFieldsFor(type) {
      document.querySelectorAll('[data-show-for]').forEach(el => {
        el.hidden = !el.dataset.showFor.split(' ').includes(type);
      });
      document.getElementById('categoryLabel').htmlFor = 'category_' + type;
      document.getElementById('priceLabel').textContent = PRICE_LABELS[type];
    }
    document.querySelectorAll('input[name="type"]').forEach(radio => {
      radio.addEventListener('change', () => showFieldsFor(radio.value));
    });

    // ── Photos: drag and drop or browse, with previews ──
    const input    = document.getElementById('photos');
    const dropzone = document.getElementById('dropzone');
    const preview  = document.getElementById('newPhotos');
    let picked = new DataTransfer();      // every new file chosen so far

    const keptCount = () => document.querySelectorAll('.saved-remove:not(:checked)').length;

    function addFiles(files) {
      for (const file of files) {
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
          SFCart.toast('"' + file.name + '" is not a JPG, PNG or WebP photo.', true);
          continue;
        }
        if (file.size > MAX_BYTES) {
          SFCart.toast('"' + file.name + '" is over 5 MB.', true);
          continue;
        }
        if (keptCount() + picked.files.length >= MAX_PHOTOS) {
          SFCart.toast('You can add up to ' + MAX_PHOTOS + ' photos.', true);
          break;
        }
        picked.items.add(file);
      }
      input.files = picked.files;
      renderPreviews();
    }

    function renderPreviews() {
      preview.querySelectorAll('img').forEach(img => URL.revokeObjectURL(img.src));
      preview.innerHTML = '';
      Array.from(picked.files).forEach((file, i) => {
        const item = document.createElement('div');
        item.className = 'photo-thumb';
        const img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        img.alt = file.name;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'photo-remove-btn';
        remove.innerHTML = '&times;';
        remove.setAttribute('aria-label', 'Remove ' + file.name);
        remove.addEventListener('click', () => {
          const next = new DataTransfer();
          Array.from(picked.files).forEach((f, j) => { if (j !== i) next.items.add(f); });
          picked = next;
          input.files = picked.files;
          renderPreviews();
        });
        item.append(img, remove);
        if (keptCount() === 0 && i === 0) {
          const tag = document.createElement('span');
          tag.className = 'cover-tag';
          tag.textContent = 'Cover';
          item.appendChild(tag);
        }
        preview.appendChild(item);
      });
    }

    input.addEventListener('change', () => {
      const chosen = Array.from(input.files);
      input.files = picked.files;           // keep earlier picks, then add these
      addFiles(chosen);
    });
    ['dragenter', 'dragover'].forEach(evt => dropzone.addEventListener(evt, e => {
      e.preventDefault();
      dropzone.classList.add('dragover');
    }));
    ['dragleave', 'drop'].forEach(evt => dropzone.addEventListener(evt, e => {
      e.preventDefault();
      dropzone.classList.remove('dragover');
    }));
    dropzone.addEventListener('drop', e => addFiles(e.dataTransfer.files));

    // Saved photos: × hides the photo straight away; it's deleted when the listing is saved
    const savedBoxes = Array.from(document.querySelectorAll('.saved-remove'));
    function updateSavedPhotos() {
      let coverSet = false;
      savedBoxes.forEach(box => {
        const thumb = box.closest('.photo-thumb');
        thumb.classList.toggle('removing', box.checked);
        // The first photo still kept is the cover
        let tag = thumb.querySelector('.cover-tag');
        const isCover = !box.checked && !coverSet;
        if (isCover) coverSet = true;
        if (isCover && !tag) {
          tag = document.createElement('span');
          tag.className = 'cover-tag';
          tag.textContent = 'Cover';
          thumb.insertBefore(tag, box);
        } else if (!isCover && tag) {
          tag.remove();
        }
      });
      const removed = savedBoxes.filter(box => box.checked).length;
      const note = document.getElementById('removedNote');
      if (note) {
        note.hidden = removed === 0;
        document.getElementById('removedText').textContent = removed + ' photo' + (removed === 1 ? '' : 's')
          + ' will be removed when you save.';
      }
      renderPreviews();
    }
    savedBoxes.forEach(box => box.addEventListener('change', updateSavedPhotos));
    const undo = document.getElementById('undoRemove');
    if (undo) undo.addEventListener('click', () => {
      savedBoxes.forEach(box => { box.checked = false; });
      updateSavedPhotos();
    });
    if (savedBoxes.length) updateSavedPhotos();   // after a reload with errors, keep earlier removals hidden
  </script>
  <?php sf_render_tabbar('account'); ?>
</body>
</html>
