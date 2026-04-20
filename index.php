<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
session_write_close(); // libera lock de sessão imediatamente
pageCache((int)getSetting('cache_html_ttl', '0'));

$db        = getDB();
$pageTitle = 'Início';
$perPage   = (int)getSetting('per_page_home', 8);
$search    = trim($_GET['q'] ?? '');

// Stats — 2 queries em vez de 6
$statsRow = $db->query('SELECT COUNT(*) AS posts, COALESCE(SUM(views),0) AS views FROM posts WHERE status="published"')->fetch();
$mediaRow = $db->query('SELECT COUNT(*) AS total, SUM(file_type="image") AS images, SUM(file_type="video") AS videos FROM media')->fetch();
$totalPosts  = (int)$statsRow['posts'];
$totalViews  = (int)$statsRow['views'];
$totalMedia  = (int)$mediaRow['total'];
$totalImages = (int)$mediaRow['images'];
$totalVideos = (int)$mediaRow['videos'];
$totalCats   = (int)$db->query('SELECT COUNT(*) FROM categories')->fetchColumn();

$mediaParts    = [];
if ($totalImages > 0) $mediaParts[] = 'imagens';
if ($totalVideos > 0) $mediaParts[] = 'vídeos';
$mediaSubtitle = $mediaParts ? implode(' e ', $mediaParts) : 'arquivos';

$thumbSQL = 'COALESCE(p.thumbnail, (SELECT file_path FROM media WHERE post_id=p.id AND file_type="image" ORDER BY id LIMIT 1))';

// Busca
if ($search) {
    $like = '%' . $search . '%';
    $stmt = $db->prepare("
        SELECT p.*, c.name AS cat_name, c.color AS cat_color, u.name AS author_name,
               $thumbSQL AS thumb_image,
               (SELECT file_path FROM media WHERE post_id=p.id AND file_type='video' ORDER BY id LIMIT 1) AS thumb_video,
               (SELECT COUNT(*) FROM media WHERE post_id=p.id) AS media_count
        FROM posts p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN users u ON p.user_id = u.id
        WHERE p.status='published' AND MATCH(p.title, p.description) AGAINST(? IN BOOLEAN MODE)
        ORDER BY p.created_at DESC
        LIMIT 48
    ");
    $stmt->execute([$search . '*']);
    $searchResults = $stmt->fetchAll();
    $latestPosts = [];
    $featured    = [];
} else {
    $searchResults = [];

    // Latest posts
    $stmtLatest = $db->prepare("
        SELECT p.*, c.name AS cat_name, c.color AS cat_color, u.name AS author_name,
               $thumbSQL AS thumb_image,
               (SELECT file_path FROM media WHERE post_id=p.id AND file_type='video' ORDER BY id LIMIT 1) AS thumb_video,
               (SELECT COUNT(*) FROM media WHERE post_id=p.id) AS media_count
        FROM posts p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN users u ON p.user_id = u.id
        WHERE p.status = 'published'
        ORDER BY p.created_at DESC
        LIMIT $perPage
    ");
    $stmtLatest->execute();
    $latestPosts = $stmtLatest->fetchAll();

    // Featured
    $featuredLimit = max(3, (int)($perPage / 2));
    $stmtFeatured = $db->prepare("
        SELECT p.*, c.name AS cat_name, c.color AS cat_color,
               $thumbSQL AS thumb_image,
               (SELECT file_path FROM media WHERE post_id=p.id AND file_type='video' ORDER BY id LIMIT 1) AS thumb_video,
               (SELECT COUNT(*) FROM media WHERE post_id=p.id) AS media_count
        FROM posts p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.status = 'published' AND p.featured = 1
        ORDER BY p.created_at DESC LIMIT $featuredLimit
    ");
    $stmtFeatured->execute();
    $featured = $stmtFeatured->fetchAll();
}

require __DIR__ . '/includes/header.php';
?>

<?php if (!isLoggedIn() && getSetting('preview_mode','0') === '1'): ?>
<?php
  $previewLimit = max(1,(int)getSetting('preview_post_limit','3'));
  $previewSeen  = (int)($_COOKIE['_preview_seen'] ?? 0);
  $previewLeft  = max(0, $previewLimit - $previewSeen);
?>
<div style="margin-bottom:20px;padding:14px 18px;background:linear-gradient(135deg,rgba(124,106,255,.12),rgba(255,106,158,.08));border:1px solid rgba(124,106,255,.3);border-radius:12px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
  <div style="flex:1;min-width:200px">
    <div style="font-size:14px;font-weight:700;margin-bottom:3px">
      👋 Você está no modo preview
    </div>
    <div style="font-size:13px;color:var(--muted2)">
      <?php if($previewLeft > 0): ?>
        Ainda pode abrir <b style="color:var(--accent)"><?= $previewLeft ?> post<?= $previewLeft > 1 ? 's' : '' ?></b> gratuitamente. Assine para acesso ilimitado.
      <?php else: ?>
        Você atingiu o limite de visualizações gratuitas. Assine para continuar.
      <?php endif; ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-shrink:0">
    <a href="<?= SITE_URL ?>/bem-vindo" class="btn btn-primary" style="padding:8px 18px;font-size:13px">🚀 Assinar</a>
    <a href="<?= SITE_URL ?>/login"     class="btn btn-secondary" style="padding:8px 14px;font-size:13px">Entrar</a>
  </div>
</div>
<?php endif; ?>

<!-- Busca -->
<form method="GET" style="margin-bottom:28px;display:flex;gap:8px">
  <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
         placeholder="Buscar posts por título ou descrição..."
         class="form-control" style="flex:1;padding:11px 16px;font-size:15px">
  <button type="submit" class="btn btn-primary" style="padding:11px 20px;font-size:15px">🔍</button>
  <?php if ($search): ?>
  <a href="<?= SITE_URL ?>/index" class="btn btn-secondary" style="padding:11px 14px;font-size:15px">✕</a>
  <?php endif; ?>
</form>

<?php if ($search): ?>
<!-- Resultados da busca -->
<div class="section-header" style="margin-bottom:16px">
  <div>
    <div class="section-title">🔍 Resultados para "<?= htmlspecialchars($search) ?>"</div>
    <div class="section-sub"><?= count($searchResults) ?> post<?= count($searchResults) != 1 ? 's' : '' ?> encontrado<?= count($searchResults) != 1 ? 's' : '' ?></div>
  </div>
</div>
<?php if ($searchResults): ?>
<div class="grid-4">
  <?php foreach ($searchResults as $p): ?>
  <?php include __DIR__ . '/includes/post_card.php'; ?>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div style="text-align:center;padding:60px 0;color:var(--muted)">
  <div style="font-size:40px;margin-bottom:12px">🔍</div>
  <p style="font-size:16px;font-weight:600;margin-bottom:6px">Nenhum resultado encontrado</p>
  <p style="font-size:13px">Tente buscar por outras palavras</p>
</div>
<?php endif; ?>

<?php else: ?>
<!-- Stats -->
<div class="grid-4" style="margin-bottom:32px">
  <a href="<?= SITE_URL ?>/posts" class="stat-card" style="text-decoration:none;transition:border-color .2s" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor=''">
    <div class="stat-label">Total de Posts</div>
    <div class="stat-value" style="color:var(--accent)"><?= number_format($totalPosts) ?></div>
    <div class="stat-sub">publicados</div>
  </a>
  <a href="<?= SITE_URL ?>/media" class="stat-card" style="text-decoration:none;transition:border-color .2s" onmouseover="this.style.borderColor='var(--accent2)'" onmouseout="this.style.borderColor=''">
    <div class="stat-label">Arquivos de Mídia</div>
    <div class="stat-value" style="color:var(--accent2)"><?= number_format($totalMedia) ?></div>
    <div class="stat-sub"><?= $mediaSubtitle ?></div>
  </a>
  <div class="stat-card">
    <div class="stat-label">Visualizações</div>
    <div class="stat-value" style="color:var(--accent3)"><?= number_format($totalViews) ?></div>
    <div class="stat-sub">total acumulado</div>
  </div>
  <?php if (isAdmin()): ?>
  <a href="<?= SITE_URL ?>/admin/stats.php" class="stat-card" style="text-decoration:none;transition:border-color .2s" onmouseover="this.style.borderColor='var(--warning)'" onmouseout="this.style.borderColor=''">
    <div class="stat-label">Categorias</div>
    <div class="stat-value" style="color:var(--warning)"><?= $totalCats ?></div>
    <div class="stat-sub">ativas</div>
  </a>
  <?php else: ?>
  <div class="stat-card">
    <div class="stat-label">Categorias</div>
    <div class="stat-value" style="color:var(--warning)"><?= $totalCats ?></div>
    <div class="stat-sub">ativas</div>
  </div>
  <?php endif; ?>
</div>

<?php if ($featured): ?>
<div class="section-header">
  <div>
    <div class="section-title">⭐ Em Destaque</div>
    <div class="section-sub">Posts marcados como destaque</div>
  </div>
</div>
<div class="grid-4" style="margin-bottom:36px">
  <?php foreach ($featured as $p): ?>
  <?php include __DIR__ . '/includes/post_card.php'; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="section-header">
  <div>
    <div class="section-title">🕒 Últimas Postagens</div>
    <div class="section-sub">Conteúdo adicionado recentemente</div>
  </div>
  <a href="<?= SITE_URL ?>/posts" class="btn btn-secondary">Ver todos</a>
</div>

<?php if ($latestPosts): ?>
<div class="grid-4">
  <?php foreach ($latestPosts as $p): ?>
  <?php include __DIR__ . '/includes/post_card.php'; ?>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div style="text-align:center;padding:60px 0;color:var(--muted)">
  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 12px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
  <p style="font-size:16px;font-weight:600;margin-bottom:6px">Nenhum post ainda</p>
  <?php if (isAdmin()): ?>
  <a href="<?= SITE_URL ?>/admin/upload.php" class="btn btn-primary" style="margin-top:12px">Criar primeiro post</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
