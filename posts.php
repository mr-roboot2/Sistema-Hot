<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
session_write_close(); // libera lock de sessão imediatamente
pageCache((int)getSetting('cache_html_ttl', '0'));

$db        = getDB();
$pageTitle = 'Postagens';

$catId  = (int)($_GET['cat']  ?? 0);
$type   = $_GET['type']  ?? '';
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)getSetting('per_page_listing', 12);
$offset  = ($page - 1) * $perPage;

$where  = ["p.status = 'published'"];
$params = [];

if ($catId) { $where[] = 'p.category_id = ?'; $params[] = $catId; }
if ($type)  { $where[] = 'p.type = ?';        $params[] = $type; }
if ($search) {
    // Tenta FULLTEXT (muito mais rápido com índice)
    try {
        $ftTest = getDB()->prepare('SELECT 1 FROM posts WHERE MATCH(title,description) AGAINST(? IN BOOLEAN MODE) LIMIT 1');
        $ftTest->execute([$search]);
        $where[]  = 'MATCH(p.title, p.description) AGAINST(? IN BOOLEAN MODE)';
        $params[] = $search . '*';
    } catch(Exception $e) {
        // Fallback para LIKE se FULLTEXT não existir ainda
        $where[]  = '(p.title LIKE ? OR p.description LIKE ?)';
        $params[] = "%$search%"; $params[] = "%$search%";
    }
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM posts p $whereSQL");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();
$totalPages = (int)ceil($totalCount / $perPage);

$stmt = $db->prepare("
    SELECT p.*, c.name AS cat_name, c.color AS cat_color, u.name AS author_name,
           COALESCE(p.thumbnail,(SELECT file_path FROM media WHERE post_id=p.id AND file_type='image' ORDER BY id LIMIT 1)) AS thumb_image,
           (SELECT file_path FROM media WHERE post_id=p.id AND file_type='video' LIMIT 1) AS thumb_video,
           (SELECT COUNT(*) FROM media WHERE post_id=p.id) AS media_count
    FROM posts p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN users u ON p.user_id = u.id
    $whereSQL
    ORDER BY p.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$posts = $stmt->fetchAll();

$cats = $db->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$currentCat = $catId ? $db->prepare('SELECT * FROM categories WHERE id=?') : null;
if ($currentCat) { $currentCat->execute([$catId]); $currentCat = $currentCat->fetch(); }
if ($currentCat) $pageTitle = $currentCat['name'];

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
    <div style="font-size:14px;font-weight:700;margin-bottom:3px">👋 Você está no modo preview</div>
    <div style="font-size:13px;color:var(--muted2)">
      <?php if($previewLeft > 0): ?>
        Ainda pode abrir <b style="color:var(--accent)"><?= $previewLeft ?> post<?= $previewLeft > 1 ? 's' : '' ?></b> gratuitamente.
      <?php else: ?>
        Limite atingido. Assine para acesso ilimitado.
      <?php endif; ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-shrink:0">
    <a href="<?= SITE_URL ?>/bem-vindo" class="btn btn-primary" style="padding:8px 18px;font-size:13px">🚀 Assinar</a>
    <a href="<?= SITE_URL ?>/login"     class="btn btn-secondary" style="padding:8px 14px;font-size:13px">Entrar</a>
  </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:24px">
  <form method="GET" style="display:flex;gap:8px;flex:1;max-width:380px">
    <?php if ($catId): ?><input type="hidden" name="cat" value="<?= $catId ?>"><?php endif; ?>
    <input type="text" class="form-control" name="q" placeholder="Buscar posts..." value="<?= htmlspecialchars($search) ?>" style="flex:1">
    <button type="submit" class="btn btn-primary">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    </button>
  </form>

  <div style="display:flex;gap:6px;flex-wrap:wrap">
    <?php
    $baseQ = array_filter(['cat'=>$catId,'q'=>$search]);
    $types = [''=> 'Todos', 'image'=>'Imagens', 'video'=>'Vídeos', 'file'=>'Arquivos'];
    foreach ($types as $v => $l):
      $active = ($type === $v);
      $q = http_build_query(array_merge($baseQ, $v ? ['type'=>$v] : []));
    ?>
    <a href="?<?= $q ?>" class="btn <?= $active ? 'btn-primary' : 'btn-secondary' ?>" style="padding:6px 14px;font-size:12px">
      <?= $l ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Category pills -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:28px">
  <a href="<?= SITE_URL ?>/posts<?= $search ? '?q='.urlencode($search) : '' ?>" class="badge <?= !$catId ? 'badge-accent' : '' ?>" style="<?= !$catId ? '' : 'background:var(--surface2);color:var(--muted)' ?>;font-size:12px;padding:5px 14px">
    Todas
  </a>
  <?php foreach ($cats as $cat): ?>
  <a href="?cat=<?= $cat['id'] ?><?= $search ? '&q='.urlencode($search) : '' ?>"
     class="badge"
     style="background:<?= $catId == $cat['id'] ? htmlspecialchars($cat['color']) : 'var(--surface2)' ?>;color:<?= $catId == $cat['id'] ? '#fff' : 'var(--muted)' ?>;font-size:12px;padding:5px 14px">
    <?= htmlspecialchars($cat['name']) ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="section-header" style="margin-bottom:20px">
  <div>
    <div class="section-title"><?= $search ? "Resultados para \"$search\"" : htmlspecialchars($pageTitle) ?></div>
    <div class="section-sub"><?= number_format($totalCount) ?> post<?= $totalCount !== 1 ? 's' : '' ?> encontrado<?= $totalCount !== 1 ? 's' : '' ?></div>
  </div>
</div>

<?php if ($posts): ?>
<div class="grid-4">
  <?php foreach ($posts as $p): ?>
  <?php include __DIR__ . '/includes/post_card.php'; ?>
  <?php endforeach; ?>
</div>

<?php
$_pBase = '?' . http_build_query(array_merge($baseQ, ['type'=>$type]));
echo renderPagination($page, $totalPages, rtrim($_pBase, '?&'));
?>

<?php else: ?>
<div style="text-align:center;padding:80px 0;color:var(--muted)">
  <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="margin:0 auto 16px;opacity:.4"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
  <p style="font-size:17px;font-weight:600;margin-bottom:6px">Nenhum resultado</p>
  <p style="font-size:14px">Tente outros filtros ou termos de busca.</p>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
