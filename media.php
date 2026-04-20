<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db        = getDB();
$pageTitle = 'Biblioteca de Mídia';

$type   = $_GET['type'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)getSetting('per_page_media', 24);
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
// Usuários normais só veem suas próprias mídias; admin vê todas
if (!isAdmin()) {
    $where[] = 'p.user_id = ?'; $params[] = currentUser()['id'];
}
if ($type && in_array($type, ['image','video','file'])) {
    $where[] = 'm.file_type = ?'; $params[] = $type;
}
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM media m $whereSQL");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();
$totalPages = (int)ceil($totalCount / $perPage);

$stmt = $db->prepare("
    SELECT m.*, p.title AS post_title, p.id AS post_id
    FROM media m JOIN posts p ON m.post_id=p.id
    $whereSQL
    ORDER BY m.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$items = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<div style="display:flex;align-items:center;gap:10px;margin-bottom:24px;flex-wrap:wrap">
  <div style="display:flex;gap:6px">
    <?php foreach ([''=> 'Todos','image'=>'Imagens','video'=>'Vídeos','file'=>'Documentos'] as $v => $l): ?>
    <a href="?type=<?= $v ?>" class="btn <?= $type === $v ? 'btn-primary' : 'btn-secondary' ?>" style="padding:7px 16px;font-size:13px"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
  <span style="margin-left:auto;font-size:13px;color:var(--muted)"><?= number_format($totalCount) ?> arquivo<?= $totalCount !== 1 ? 's' : '' ?></span>
</div>

<?php if ($items): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px">
  <?php foreach ($items as $idx => $m): ?>
  <a href="<?= SITE_URL ?>/post/<?= $m['post_id'] ?>" class="card" style="display:block">
    <div style="aspect-ratio:9/16;background:var(--surface2);overflow:hidden;position:relative">

      <?php if ($m['file_type'] === 'image'): ?>
        <img src="<?= UPLOAD_URL . htmlspecialchars($m['file_path']) ?>" loading="lazy"
             class="img-cover">

      <?php elseif ($m['file_type'] === 'video'): ?>
        <!-- Canvas para captura de frame -->
        <canvas id="vcanvas-<?= $idx ?>"
                class="img-cover"></canvas>
        <!-- Overlay play -->
        <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.25)">
          <div style="width:40px;height:40px;border-radius:50%;background:rgba(255,106,158,.88);display:flex;align-items:center;justify-content:center;box-shadow:0 3px 12px rgba(0,0,0,.5)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="#fff"><polygon points="5 3 19 12 5 21 5 3"/></svg>
          </div>
        </div>
        <!-- Vídeo oculto só para captura -->
        <video id="vsrc-<?= $idx ?>"
               src="<?= UPLOAD_URL . htmlspecialchars($m['file_path']) ?>#t=1"
               preload="metadata" muted playsinline
               style="display:none" data-idx="<?= $idx ?>"></video>

      <?php endif; ?>

      <div style="position:absolute;bottom:0;left:0;right:0;padding:6px 8px;background:linear-gradient(transparent,rgba(0,0,0,.7));font-size:10px;color:rgba(255,255,255,.8)">
        <?= formatFileSize($m['file_size']) ?>
      </div>
    </div>
    <div style="padding:8px 10px">
      <div style="font-size:12px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
           title="<?= htmlspecialchars($m['original_name']) ?>"><?= htmlspecialchars($m['original_name']) ?></div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($m['post_title']) ?></div>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<script>
document.querySelectorAll('video[data-idx]').forEach(vid => {
  const idx    = parseInt(vid.dataset.idx);
  const canvas = document.getElementById('vcanvas-' + idx);
  if (!canvas) return;

  const draw = () => {
    try {
      canvas.width  = vid.videoWidth  || 320;
      canvas.height = vid.videoHeight || 568;
      if (canvas.width > 0) canvas.getContext('2d').drawImage(vid, 0, 0, canvas.width, canvas.height);
    } catch(e) { fallback(canvas, idx); }
  };

  vid.addEventListener('loadeddata', draw);
  vid.addEventListener('canplay',    draw);
  vid.addEventListener('error',      () => fallback(canvas, idx));
  setTimeout(() => { if (!canvas.width) fallback(canvas, idx); }, 4000);
});

function fallback(canvas, idx) {
  canvas.width = 320; canvas.height = 568;
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#13131a'; ctx.fillRect(0,0,320,568);
  ctx.fillStyle = '#2a2a3a'; ctx.beginPath(); ctx.arc(160,284,38,0,Math.PI*2); ctx.fill();
  ctx.fillStyle = '#ff6a9e';
  ctx.beginPath(); ctx.moveTo(149,268); ctx.lineTo(149,300); ctx.lineTo(176,284); ctx.closePath(); ctx.fill();
}
</script>

<?= renderPagination($page, $totalPages, '?type=' . urlencode($type)) ?>

<?php else: ?>
<div style="text-align:center;padding:80px 0;color:var(--muted)">
  <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="margin:0 auto 16px;opacity:.3"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
  <p style="font-size:17px;font-weight:600">Nenhum arquivo encontrado</p>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
