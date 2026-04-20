<?php
// includes/post_card.php — expects $p array
// Prioridade: thumbnail definido pelo admin > primeira imagem > nada
$thumbUrl = '';
if (!empty($p['thumbnail']))       $thumbUrl = UPLOAD_URL . $p['thumbnail'];
elseif (!empty($p['thumb_image'])) $thumbUrl = UPLOAD_URL . $p['thumb_image'];

$hasVideo = !empty($p['thumb_video']);
$hasImage = !empty($thumbUrl);

// Badge só aparece se tiver vídeo ou imagem
$typeLabel = $hasVideo ? 'Vídeo' : ($hasImage ? 'Imagem' : '');
$typeClass = $hasVideo ? 'type-video' : 'type-image';
?>
<a href="<?= SITE_URL ?>/post/<?= $p['id'] ?>" class="card" style="display:block">
  <div class="card-thumb">
    <?php if ($thumbUrl): ?>
      <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="<?= htmlspecialchars($p['title']) ?>" loading="lazy">
    <?php else: ?>
      <div class="card-thumb-placeholder">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--border2)" stroke-width="1.5">
          <?php if ($hasVideo): ?>
          <polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/>
          <?php else: ?>
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
          <?php endif; ?>
        </svg>
      </div>
    <?php endif; ?>
    <?php if ($typeLabel): ?>
    <span class="type-badge <?= $typeClass ?>"><?= $typeLabel ?></span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if (!empty($p['cat_name'])): ?>
    <div class="card-cat" style="color:<?= htmlspecialchars($p['cat_color'] ?? 'var(--accent)') ?>">
      <?= htmlspecialchars($p['cat_name']) ?>
    </div>
    <?php endif; ?>
    <div class="card-title-text"><?= htmlspecialchars($p['title']) ?></div>
    <div class="card-meta">
      <span>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        <?= number_format($p['views'] ?? 0) ?>
      </span>
      <?php if (!empty($p['media_count'])): ?>
      <span>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        <?= $p['media_count'] ?> arquivo<?= $p['media_count'] > 1 ? 's' : '' ?>
      </span>
      <?php endif; ?>
      <span><?= timeAgo($p['created_at']) ?></span>
    </div>
  </div>
</a>
