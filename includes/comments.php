<?php
/**
 * Questions & Comments and Ratings & Reviews on the listing pages (rental_detail.php).
 * Needs db_setup.php ($conn) and inbox.php (sf_notify).
 */

const SF_COMMENT_MAX     = 1000;   // characters per comment
const SF_COMMENTS_PER_HR = 20;     // comments one user can post in an hour

// Questions, newest first, each with its replies (oldest first)
function sf_listing_comments($conn, $listingId) {
    $stmt = $conn->prepare("SELECT c.id, c.parent_id, c.user_id, c.body, UNIX_TIMESTAMP(c.created_at) AS ts,
                                   COALESCE(NULLIF(u.full_name, ''), u.username) AS name, u.avatar
                            FROM listing_comments c JOIN users u ON u.id = c.user_id
                            WHERE c.listing_id = ? ORDER BY c.created_at, c.id");
    $stmt->bind_param("i", $listingId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $questions = [];
    foreach ($rows as $r) if ($r['parent_id'] === null) $questions[$r['id']] = $r + ['replies' => []];
    foreach ($rows as $r) if ($r['parent_id'] !== null && isset($questions[$r['parent_id']])) $questions[$r['parent_id']]['replies'][] = $r;
    return array_reverse(array_values($questions));
}

// Posts a question (or a reply when $parentId is set) and tells the people involved.
// $mentionId: the reply being answered, when someone replies to a reply (its author is told too).
// $link is the listing page, e.g. "rental_detail.php?id=listing-5". Returns an error message or null.
function sf_add_comment($conn, array $listing, $userId, $text, $parentId, $link, $mentionId = null) {
    $text = trim(str_replace("\r\n", "\n", (string) $text));
    if ($text === '') return 'Write your question or reply first.';
    if (mb_strlen($text) > SF_COMMENT_MAX) return 'Comments can be up to ' . number_format(SF_COMMENT_MAX) . ' characters.';

    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM listing_comments WHERE user_id = ? AND created_at >= NOW() - INTERVAL 1 HOUR");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $recent = (int) $stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    if ($recent >= SF_COMMENTS_PER_HR) return 'You\'ve posted a lot in the last hour. Please try again later.';

    $listingId = (int) $listing['listing_id'];
    $parent    = null;
    if ($parentId) {
        // Replies go under a question on this same listing
        $stmt = $conn->prepare("SELECT id, user_id FROM listing_comments WHERE id = ? AND listing_id = ? AND parent_id IS NULL");
        $stmt->bind_param("ii", $parentId, $listingId);
        $stmt->execute();
        $parent = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$parent) return 'That question could not be found.';
    }
    $mention = null;
    if ($parent && $mentionId) {
        // Only a reply in this same thread
        $stmt = $conn->prepare("SELECT id, user_id FROM listing_comments WHERE id = ? AND parent_id = ?");
        $parentKeyCheck = (int) $parent['id'];
        $stmt->bind_param("ii", $mentionId, $parentKeyCheck);
        $stmt->execute();
        $mention = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    $parentKey = $parent ? (int) $parent['id'] : null;
    $stmt = $conn->prepare("INSERT INTO listing_comments (listing_id, user_id, parent_id, body) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiis", $listingId, $userId, $parentKey, $text);
    $stmt->execute();
    $newId = (int) $stmt->insert_id;
    $stmt->close();
    $link .= '#comment-' . $newId;   // notifications open the page at this comment

    $title   = $listing['title'];
    $snippet = mb_strimwidth($text, 0, 120, '…');
    $ownerId = (int) $listing['seller_id'];
    $told    = [$userId => true];   // everyone gets at most one notification, never for their own post
    if ($mention && !isset($told[(int) $mention['user_id']])) {
        sf_notify($conn, (int) $mention['user_id'], 'info', 'New reply to you on ' . $title, $snippet, $link);
        $told[(int) $mention['user_id']] = true;
    }
    if ($parent && !isset($told[(int) $parent['user_id']])) {
        sf_notify($conn, (int) $parent['user_id'], 'info', 'New reply on ' . $title, $snippet, $link);
        $told[(int) $parent['user_id']] = true;
    }
    if ($ownerId && !isset($told[$ownerId])) {
        sf_notify($conn, $ownerId, 'info', ($parent ? 'New reply' : 'New question') . ' on your listing: ' . $title, $snippet, $link);
    }
    return null;
}

// Buyer reviews of a listing (hidden ones left out), newest first, with the rental length
function sf_listing_reviews($conn, $listingKey) {
    $stmt = $conn->prepare("SELECT f.rating, f.message, f.name, f.user_id, u.avatar, UNIX_TIMESTAMP(f.created_at) AS ts, oi.units, oi.item_type
                            FROM feedback f LEFT JOIN order_items oi ON oi.id = f.order_item_id LEFT JOIN users u ON u.id = f.user_id AND u.status = 'active'
                            WHERE f.listing_key = ? AND f.type = 'buyer' AND f.rating IS NOT NULL
                              AND f.status NOT IN ('flagged', 'dismissed')
                            ORDER BY f.created_at DESC, f.id DESC");
    $stmt->bind_param("s", $listingKey);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// ── Shared by product_detail.php, rental_detail.php and service_detail.php ──

// Handles a posted question or reply (action=comment). Goes back to the list when it worked;
// otherwise returns [error, draft] so the form can show what needs fixing.
function sf_handle_comment_post($conn, array $item, $userId, $pageLink) {
    $draft = ['text' => '', 'parent' => 0, 'mention' => 0];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'comment') return [null, $draft];
    $draft = ['text' => (string) ($_POST['body'] ?? ''), 'parent' => (int) ($_POST['parent'] ?? 0), 'mention' => (int) ($_POST['mention'] ?? 0)];
    $error = (int) $userId <= 0 ? 'Please log in to comment.'   // guests can read, not post
        : (!sf_check_csrf() ? 'Your session expired. Please try again.'
        : sf_add_comment($conn, $item + ['listing_id' => (int) substr($item['id'], 8)], (int) $userId, $draft['text'], $draft['parent'] ?: null, $pageLink, $draft['mention'] ?: null));
    if (!$error) {
        // After a reply, land on that question with its replies open
        header('Location: ' . $pageLink . ($draft['parent'] ? '#comment-' . $draft['parent'] : '#comments'));
        exit;
    }
    return [$error, $draft];
}

// The comments card: a box to ask, then each question with its replies and a Reply box.
// $labels: [title, placeholder, empty text]
function sf_render_comments(array $item, array $comments, $pageLink, $csrf, $error, array $draft, array $labels) {
    $e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $when = fn($ts) => date('M j, Y', $ts);
    [$title, $placeholder, $emptyText] = $labels;
    $sellerId = (int) $item['seller_id'];
    $person = function ($c, $small = false) use ($e, $when, $sellerId) { ?>
                <div class="comment-header">
                  <div class="comment-user">
                    <div class="comment-avatar<?php echo $small ? ' small' : ''; ?>"><?php echo sf_avatar_html($c['name'], $c['avatar']); ?></div>
                    <a href="profile.php?id=<?php echo (int) $c['user_id']; ?>" class="comment-author"><?php echo $e($c['name']); ?></a>
                    <?php if ((int) $c['user_id'] === $sellerId): ?><span class="owner-tag">Owner</span><?php endif; ?>
                  </div>
                  <span class="comment-date"><?php echo $when($c['ts']); ?></span>
                </div>
                <p class="comment-text"><?php echo nl2br($e($c['body'])); ?></p>
    <?php };
    ?>
        <section class="comments-card" id="comments" aria-labelledby="commentsTitle">
          <h3 class="comments-title" id="commentsTitle"><?php echo $e($title); ?></h3>
          <form method="post" action="<?php echo $e($pageLink); ?>#comments" class="comment-form">
            <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
            <input type="hidden" name="action" value="comment" />
            <textarea name="body" class="comment-textarea" maxlength="<?php echo SF_COMMENT_MAX; ?>" placeholder="<?php echo $e($placeholder); ?>" aria-label="<?php echo $e($title); ?>" required><?php echo $draft['parent'] ? '' : $e($draft['text']); ?></textarea>
            <?php if ($error && !$draft['parent']): ?><p class="comment-error" role="alert"><?php echo $e($error); ?></p><?php endif; ?>
            <button type="submit" class="btn-comment-submit">Submit</button>
          </form>

          <div class="comments-list">
            <?php if (!$comments): ?><p class="comments-empty"><?php echo $e($emptyText); ?></p><?php endif; ?>
            <?php foreach ($comments as $q): $qid = (int) $q['id']; $open = $draft['parent'] === $qid; ?>
            <div class="comment-item" id="comment-<?php echo $qid; ?>">
              <?php $person($q); ?>
              <button type="button" class="comment-reply-btn" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>" aria-controls="reply-<?php echo $qid; ?>" onclick="toggleReply(this)">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                Reply
              </button>
              <form method="post" action="<?php echo $e($pageLink); ?>#comments" class="comment-form reply-form" id="reply-<?php echo $qid; ?>"<?php echo $open ? '' : ' hidden'; ?>>
                <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
                <input type="hidden" name="action" value="comment" />
                <input type="hidden" name="parent" value="<?php echo $qid; ?>" />
                <input type="hidden" name="mention" value="<?php echo $open ? (int) ($draft['mention'] ?? 0) : 0; ?>" />
                <textarea name="body" class="comment-textarea" maxlength="<?php echo SF_COMMENT_MAX; ?>" placeholder="Write a reply to <?php echo $e($q['name']); ?>…" aria-label="Your reply" required><?php echo $open ? $e($draft['text']) : ''; ?></textarea>
                <?php if ($error && $open): ?><p class="comment-error" role="alert"><?php echo $e($error); ?></p><?php endif; ?>
                <button type="submit" class="btn-comment-submit">Reply</button>
              </form>
              <?php if ($q['replies']): $n = count($q['replies']); ?>
              <!-- Replies start collapsed (like YouTube): "▾ 2 replies" opens them, "▴ Hide replies" closes them -->
              <button type="button" class="comment-replies-toggle" aria-expanded="false" aria-controls="replies-<?php echo $qid; ?>" data-label="<?php echo $n . ($n === 1 ? ' reply' : ' replies'); ?>" onclick="toggleReplies(this)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                <span><?php echo $n . ($n === 1 ? ' reply' : ' replies'); ?></span>
              </button>
              <div class="comment-replies" id="replies-<?php echo $qid; ?>" hidden>
                <?php foreach ($q['replies'] as $r): ?>
                <div class="comment-reply" id="comment-<?php echo (int) $r['id']; ?>">
                  <?php $person($r, true); ?>
                  <button type="button" class="comment-reply-btn" aria-controls="reply-<?php echo $qid; ?>" data-mention="<?php echo (int) $r['id']; ?>" data-name="<?php echo $e($r['name']); ?>" onclick="replyTo(this)">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                    Reply
                  </button>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </section>
        <script>
          // Show or hide the reply box under a question
          function toggleReply(btn) {
            const form = document.getElementById(btn.getAttribute('aria-controls'));
            form.hidden = !form.hidden;
            btn.setAttribute('aria-expanded', String(!form.hidden));
            form.elements.mention.value = 0;
            if (!form.hidden) form.querySelector('textarea').focus();
          }
          // Replying to a reply: same box, starting with @Name, and that person is told
          function replyTo(btn) {
            const form = document.getElementById(btn.getAttribute('aria-controls'));
            const box = form.querySelector('textarea');
            const tag = '@' + btn.dataset.name + ' ';
            form.hidden = false;
            form.elements.mention.value = btn.dataset.mention;
            // Swap the @Name from an earlier Reply click rather than stacking them
            const before = form.dataset.tag || '';
            if (!box.value.startsWith(tag)) box.value = tag + (before && box.value.startsWith(before) ? box.value.slice(before.length) : box.value);
            form.dataset.tag = tag;
            box.focus();
            box.setSelectionRange(box.value.length, box.value.length);
            const toggle = form.closest('.comment-item').querySelector(':scope > .comment-reply-btn');
            if (toggle) toggle.setAttribute('aria-expanded', 'true');
          }
          // Show or hide the replies under a question (show = true/false, or leave it out to flip)
          function toggleReplies(btn, show) {
            const list = document.getElementById(btn.getAttribute('aria-controls'));
            if (show === undefined) show = list.hidden;
            list.hidden = !show;
            btn.setAttribute('aria-expanded', String(show));
            btn.querySelector('span').textContent = show ? 'Hide replies' : btn.dataset.label;
          }
          // A link to a comment (notifications, or just after replying) opens its replies so it can be seen
          function openLinkedComment() {
            const target = location.hash.startsWith('#comment-') && document.getElementById(location.hash.slice(1));
            if (!target) return;
            const item = target.closest('.comment-item');
            const toggle = item && item.querySelector(':scope > .comment-replies-toggle');
            if (!toggle) return;
            toggleReplies(toggle, true);
            target.scrollIntoView({ block: 'center' });
          }
          openLinkedComment();
          window.addEventListener('hashchange', openLinkedComment);
          <?php if ($error): ?>document.getElementById('comments').scrollIntoView();   // show what needs fixing<?php endif; ?>
        </script>
    <?php
}

// Ratings & Reviews card (product, rental and service pages): reviews buyers left on completed orders,
// worded for the listing type. $reviews comes from sf_listing_reviews().
function sf_render_reviews(array $item, array $reviews) {
    $e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $when = fn($ts) => date('M j, Y', $ts);
    $words = [
        'products' => ['purchase', 'Buyer', 'Reviews are tied to completed orders and appear after the item is delivered.', 'Bought this before?', 'a completed order'],
        'rentals'  => ['completed rental', 'Renter', 'Reviews are tied to completed transactions and appear after the rental period ends.', 'Rented this before?', 'a completed rental'],
        'services' => ['completed service', 'Client', 'Reviews are tied to completed bookings and appear after the service is done.', 'Booked this before?', 'a completed booking'],
    ][$item['type']] ?? ['purchase', 'Buyer', '', 'Bought this before?', 'a completed order'];
    [$what, $nobody, $note, $again, $from] = $words;
    $n = count($reviews);
    ?>
        <section class="comments-card reviews-card" aria-labelledby="reviewsTitle">
          <h3 class="comments-title" id="reviewsTitle">Ratings &amp; Reviews</h3>
          <div class="reviews-summary">
            <span class="reviews-avg"><?php echo $n ? number_format(array_sum(array_column($reviews, 'rating')) / $n, 1) : '–'; ?></span>
            <span class="reviews-count"><?php echo $n; ?> verified <?php echo $what . ($n === 1 ? '' : 's'); ?></span>
          </div>
          <p class="reviews-note"><?php echo $note; ?></p>
          <?php foreach ($reviews as $rv): $who = $rv['name'] ?: $nobody; ?>
          <article class="review-card">
            <div class="comment-header">
              <div class="comment-user">
                <div class="comment-avatar small"><?php echo sf_avatar_html($who, $rv['avatar'] ?? ''); ?></div>
                <?php if ($rv['user_id'] && $rv['avatar'] !== null): ?><a href="profile.php?id=<?php echo (int) $rv['user_id']; ?>" class="comment-author"><?php echo $e($who); ?></a><?php else: ?><span class="comment-author"><?php echo $e($who); ?></span><?php endif; ?>
              </div>
              <span class="comment-date"><?php echo $when($rv['ts']); ?><?php
                if ($rv['units'] && $item['type'] === 'rentals') echo ' · ' . (int) $rv['units'] . '-day rental';
                elseif ($rv['units'] && $item['type'] === 'services') echo ' · ' . (int) $rv['units'] . ' ' . $e(sf_unit_word($item['unit'], (int) $rv['units']));
              ?></span>
            </div>
            <?php if (trim((string) $rv['message']) !== ''): ?><p class="comment-text"><?php echo nl2br($e($rv['message'])); ?></p><?php endif; ?>
            <p class="review-meta">Listing rating: <?php echo (int) $rv['rating']; ?> / 5 · Verified <?php echo $what; ?></p>
          </article>
          <?php endforeach; ?>
          <p class="reviews-foot"><?php echo $n ? $again : 'No reviews yet.'; ?> Review this listing from <?php echo $from; ?> in your <a href="account.php">Buyer Dashboard</a>.</p>
        </section>
    <?php
}
