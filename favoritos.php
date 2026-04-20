<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
session_write_close(); // libera lock de sessão imediatamente

$db        = getDB();
$user      = currentUser();
$userId    = (int)$user['id'];
$pageTitle = 'Meus Favoritos';

// Garante tabela
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_favorites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        post_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_fav (user_id, post_id),
        INDEX idx_fav_user (user_id),
        INDEX idx_fav_post (post_id)
    )");
} catch(Exception $e) {}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)getSetting('per_page', '12');
$offset  = ($page - 1) * $perPage;

$total = $db->prepare('SELECT COUNT(*) FROM user_favorites WHERE user_id=?');
$total->execute([$userId]);
$totalCount = (int)$total->fetchColumn();
$totalPages = (int)ceil($totalCount / $perPage);

$stmt = $db->prepare("
    SELECT p.*,
           c.name AS cat_name, c.color AS cat_color,
           COALESCE(p.thumbnail,
               (SELECT file_path FROM media WHERE post_id=p.id AND file_type='image' ORDER BY sort_order,id LIMIT 1)
           ) AS thumb_image,
           (SELECT file_path FROM media WHERE post_id=p.id AND file_type='video' ORDER BY sort_order,id LIMIT 1) AS thumb_video,
           f.created_at AS saved_at
    FROM user_favorites f
    JOIN posts p ON p.id = f.post_id AND p.status = 'published'
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE f.user_id = ?
    ORDER BY f.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute([$userId]);
$posts = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<style>
.fav-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px}
@media(max-width:600px){.fav-grid{grid-template-columns:repeat(2,1fr);gap:10px}}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
  <div>
    <h1 style="font-family:'Roboto',sans-serif;font-size:22px;font-weight:800;margin-bottom:3px">❤️ Meus Favoritos</h1>
    <div style="font-size:13px;color:var(--muted)"><?= $totalCount ?> post<?= $totalCount !== 1 ? 's' : '' ?> salvos</div>
  </div>
</div>

<?php if (!$posts): ?>
<div class="card" style="padding:60px;text-align:center">
  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--border2)" stroke-width="1.5" style="display:block;margin:0 auto 16px">
    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
  </svg>
  <div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum favorito ainda</div>
  <div style="font-size:13px;color:var(--muted);margin-bottom:20px">Clique no coração nos posts para salvá-los aqui.</div>
  <a href="<?= SITE_URL ?>/posts" class="btn btn-primary" style="padding:9px 22px">Explorar posts</a>
</div>
<?php else: ?>

<div class="fav-grid">
<?php foreach ($posts as $p): ?>
  <div style="position:relative">
    <?php include __DIR__ . '/includes/post_card.php'; ?>
    <!-- Botão remover favorito direto do card -->
    <button onclick="removeFav(<?= $p['id'] ?>, this)"
            title="Remover dos favoritos"
            style="position:absolute;top:8px;right:8px;background:rgba(0,0,0,.55);border:none;border-radius:50%;width:30px;height:30px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#ff6b6b;font-size:16px;line-height:1;z-index:2;transition:background .15s"
            onmouseover="this.style.background='rgba(0,0,0,.8)'"
            onmouseout="this.style.background='rgba(0,0,0,.55)'">❤️</button>
  </div>
<?php endforeach; ?>
</div>

<?php echo renderPagination($page, $totalPages, '?'); ?>

<script>
var CSRF    = <?= json_encode(csrf_token()) ?>;
var BASE    = <?= json_encode(SITE_URL) ?>;

function removeFav(postId, btn) {
  var card = btn.parentElement;
  var fd   = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('post_id', postId);

  btn.textContent = '🤍';
  btn.disabled = true;

  fetch(BASE + '/ajax-favorito.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (!d.saved) {
        card.style.transition = 'opacity .3s, transform .3s';
        card.style.opacity = '0';
        card.style.transform = 'scale(.95)';
        setTimeout(function() { card.remove(); }, 300);
      } else {
        btn.textContent = '❤️';
        btn.disabled = false;
      }
    })
    .catch(function() { btn.textContent = '❤️'; btn.disabled = false; });
}
</script>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
