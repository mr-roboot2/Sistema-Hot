<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
requireLoginAlways();
if (!isAdmin()) { header('Location: ' . SITE_URL . '/index'); exit; }
session_write_close(); // libera lock de sessão

$db        = getDB();
$pageTitle = 'Gerenciar Posts';

$search  = trim($_GET['q'] ?? '');
$status  = $_GET['status'] ?? '';
$catFil  = (int)($_GET['cat'] ?? 0);
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
if ($search)  { $where[] = 'p.title LIKE ?';        $params[] = "%$search%"; }
if ($status)  { $where[] = 'p.status = ?';           $params[] = $status; }
if ($catFil)  { $where[] = 'p.category_id = ?';      $params[] = $catFil; }
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$totalCount = (int)$db->prepare("SELECT COUNT(*) FROM posts p $whereSQL")->execute($params) ? null : null;
$tc = $db->prepare("SELECT COUNT(*) FROM posts p $whereSQL");
$tc->execute($params);
$totalCount = (int)$tc->fetchColumn();
$totalPages = (int)ceil($totalCount / $perPage);

$stmt = $db->prepare("
    SELECT p.*, c.name AS cat_name, u.name AS author_name,
           (SELECT COUNT(*) FROM media WHERE post_id=p.id) AS media_count
    FROM posts p
    LEFT JOIN categories c ON p.category_id=c.id
    LEFT JOIN users u ON p.user_id=u.id
    $whereSQL
    ORDER BY p.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$posts = $stmt->fetchAll();

$cats = $db->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<style>
.cb-row input[type=checkbox]{width:15px;height:15px;accent-color:var(--accent);cursor:pointer}
.bulk-bar{display:none;align-items:center;gap:10px;padding:10px 16px;background:rgba(124,106,255,.1);border:1px solid rgba(124,106,255,.3);border-radius:8px;margin-bottom:12px;flex-wrap:wrap}
.bulk-bar.show{display:flex}
.bulk-count{font-size:13px;font-weight:600;color:var(--accent);white-space:nowrap}
.bulk-actions{display:flex;gap:6px;flex-wrap:wrap}
.tr-sel{background:rgba(124,106,255,.06)!important}
/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:var(--surface);border:1px solid var(--border2);border-radius:12px;padding:24px;width:320px;max-width:90vw}
</style>

<!-- Barra de busca e filtros -->
<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
  <form method="GET" style="display:flex;gap:6px;flex:1;min-width:200px;max-width:300px">
    <?php if($status): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>"><?php endif; ?>
    <input type="text" class="form-control" name="q" placeholder="Buscar título..." value="<?= htmlspecialchars($search) ?>" style="flex:1">
    <button type="submit" class="btn btn-primary" style="padding:7px 12px">🔍</button>
  </form>

  <div style="display:flex;gap:5px;flex-wrap:wrap">
    <?php foreach ([''=> 'Todos','published'=>'Publicados','draft'=>'Rascunhos','archived'=>'Arquivados'] as $v => $l): ?>
    <a href="?status=<?= $v ?><?= $search ? '&q='.urlencode($search) : '' ?>"
       class="btn <?= $status===$v ? 'btn-primary' : 'btn-secondary' ?>"
       style="padding:5px 12px;font-size:12px"><?= $l ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($cats): ?>
  <select onchange="location='?status=<?= urlencode($status) ?>&q=<?= urlencode($search) ?>&cat='+this.value"
          class="form-control" style="width:150px;font-size:12px">
    <option value="0" <?= !$catFil?'selected':'' ?>>Todas categorias</option>
    <?php foreach($cats as $c): ?>
    <option value="<?= $c['id'] ?>" <?= $catFil===$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>

  <a href="<?= SITE_URL ?>/admin/upload.php" class="btn btn-primary" style="margin-left:auto;white-space:nowrap">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:13px;height:13px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Novo post
  </a>
</div>

<!-- Barra de ações em lote -->
<div class="bulk-bar" id="bulk-bar">
  <span class="bulk-count" id="bulk-count">0 selecionados</span>
  <div class="bulk-actions">
    <button onclick="bulkAction('publish')"   class="btn btn-secondary" style="font-size:12px;padding:5px 10px;color:var(--success);border-color:rgba(16,185,129,.4)">✅ Publicar</button>
    <button onclick="bulkAction('draft')"     class="btn btn-secondary" style="font-size:12px;padding:5px 10px;color:var(--warning);border-color:rgba(245,158,11,.4)">📝 Rascunho</button>
    <button onclick="bulkAction('archive')"   class="btn btn-secondary" style="font-size:12px;padding:5px 10px;color:var(--muted2)">📦 Arquivar</button>
    <button onclick="openCatModal()"          class="btn btn-secondary" style="font-size:12px;padding:5px 10px;color:var(--accent);border-color:rgba(124,106,255,.4)">📁 Mover categoria</button>
    <button onclick="bulkAction('delete',true)" class="btn btn-danger"  style="font-size:12px;padding:5px 10px">🗑️ Deletar</button>
  </div>
  <button onclick="clearSelection()" style="margin-left:auto;background:none;border:none;cursor:pointer;color:var(--muted);font-size:18px;line-height:1" title="Limpar seleção">×</button>
</div>

<!-- Tabela -->
<div class="card" style="overflow:hidden">
  <table style="width:100%;border-collapse:collapse">
    <thead>
      <tr style="border-bottom:1px solid var(--border)">
        <th style="padding:12px 16px;width:36px">
          <input type="checkbox" id="cb-all" onchange="toggleAll(this)"
                 style="width:15px;height:15px;accent-color:var(--accent);cursor:pointer">
        </th>
        <th style="padding:12px 16px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">TÍTULO</th>
        <th style="padding:12px 16px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">CATEGORIA</th>
        <th style="padding:12px 16px;text-align:center;font-size:11px;color:var(--muted);font-weight:600">STATUS</th>
        <th style="padding:12px 16px;text-align:right;font-size:11px;color:var(--muted);font-weight:600">MÍDIA</th>
        <th style="padding:12px 16px;text-align:right;font-size:11px;color:var(--muted);font-weight:600">VIEWS</th>
        <th style="padding:12px 16px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">DATA</th>
        <th style="padding:12px 16px;text-align:right;font-size:11px;color:var(--muted);font-weight:600">AÇÕES</th>
      </tr>
    </thead>
    <tbody id="posts-tbody">
      <?php foreach ($posts as $p): ?>
      <tr class="cb-row" id="row-<?= $p['id'] ?>"
          style="border-bottom:1px solid var(--border);transition:background .12s"
          onmouseover="if(!this.classList.contains('tr-sel'))this.style.background='var(--surface2)'"
          onmouseout="if(!this.classList.contains('tr-sel'))this.style.background=''">
        <td style="padding:12px 16px">
          <input type="checkbox" class="post-cb" value="<?= $p['id'] ?>" onchange="onCheck(this)">
        </td>
        <td style="padding:12px 16px;max-width:260px">
          <div style="font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            <?php if ($p['featured']): ?><span style="color:var(--warning);margin-right:4px" title="Destaque">⭐</span><?php endif; ?>
            <?= htmlspecialchars($p['title']) ?>
          </div>
          <div style="font-size:11px;color:var(--muted);margin-top:1px"><?= htmlspecialchars($p['author_name']) ?></div>
        </td>
        <td style="padding:12px 16px;font-size:12px;color:var(--muted2)"><?= htmlspecialchars($p['cat_name'] ?? '—') ?></td>
        <td style="padding:12px 16px;text-align:center">
          <span class="badge <?= $p['status']==='published'?'badge-success':($p['status']==='draft'?'badge-warning':'badge-danger') ?>"
                style="font-size:11px">
            <?= ['published'=>'Publicado','draft'=>'Rascunho','archived'=>'Arquivado'][$p['status']] ?>
          </span>
        </td>
        <td style="padding:12px 16px;text-align:right;font-size:12px;color:var(--muted2)"><?= $p['media_count'] ?></td>
        <td style="padding:12px 16px;text-align:right;font-size:12px;color:var(--muted2)"><?= number_format($p['views']) ?></td>
        <td style="padding:12px 16px;font-size:11px;color:var(--muted)"><?= date('d/m/Y', strtotime($p['created_at'])) ?></td>
        <td style="padding:12px 16px">
          <div style="display:flex;gap:4px;justify-content:flex-end">
            <a href="<?= SITE_URL ?>/post/<?= $p['id'] ?>" class="btn btn-secondary" style="padding:4px 8px;font-size:11px" target="_blank">Ver</a>
            <a href="<?= SITE_URL ?>/admin/upload.php?edit=<?= $p['id'] ?>" class="btn btn-secondary" style="padding:4px 8px;font-size:11px">Editar</a>
            <a href="<?= SITE_URL ?>/admin/delete.php?id=<?= $p['id'] ?>&csrf_token=<?= csrf_token() ?>"
               class="btn btn-danger" style="padding:4px 8px;font-size:11px"
               onclick="return confirm('Excluir definitivamente?')">✕</a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$posts): ?>
  <div style="text-align:center;padding:48px;color:var(--muted)">Nenhum post encontrado.</div>
  <?php endif; ?>
</div>

<?php
$_pBase = '?' . http_build_query(array_filter(['q'=>$search,'status'=>$status,'cat'=>$catFil?:null]));
echo renderPagination($page, $totalPages, rtrim($_pBase,'?&'));
?>

<!-- Modal mover categoria -->
<div class="modal-overlay" id="cat-modal">
  <div class="modal-box">
    <div style="font-weight:700;font-size:15px;margin-bottom:14px">📁 Mover para categoria</div>
    <select id="cat-select" class="form-control" style="margin-bottom:14px">
      <option value="0">— Sem categoria —</option>
      <?php foreach($cats as $c): ?>
      <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button onclick="closeCatModal()" class="btn btn-secondary" style="padding:7px 14px">Cancelar</button>
      <button onclick="bulkAction('move_category')" class="btn btn-primary" style="padding:7px 14px">Mover</button>
    </div>
  </div>
</div>

<script>
var CSRF    = <?= json_encode(csrf_token()) ?>;
var BASE    = <?= json_encode(SITE_URL) ?>;
var selected = new Set();

function getChecked() {
  return Array.from(document.querySelectorAll('.post-cb:checked')).map(function(c){ return parseInt(c.value); });
}

function updateBulkBar() {
  var n   = selected.size;
  var bar = document.getElementById('bulk-bar');
  var cnt = document.getElementById('bulk-count');
  cnt.textContent = n + (n === 1 ? ' selecionado' : ' selecionados');
  bar.classList.toggle('show', n > 0);
}

function onCheck(cb) {
  var id  = parseInt(cb.value);
  var row = document.getElementById('row-' + id);
  if (cb.checked) { selected.add(id);    row.classList.add('tr-sel'); row.style.background=''; }
  else            { selected.delete(id); row.classList.remove('tr-sel'); }
  // Atualiza checkbox "selecionar tudo"
  var all = document.querySelectorAll('.post-cb');
  var chk = document.querySelectorAll('.post-cb:checked');
  var cbAll = document.getElementById('cb-all');
  cbAll.indeterminate = chk.length > 0 && chk.length < all.length;
  cbAll.checked = chk.length === all.length && all.length > 0;
  updateBulkBar();
}

function toggleAll(cb) {
  document.querySelectorAll('.post-cb').forEach(function(c) {
    c.checked = cb.checked;
    var id  = parseInt(c.value);
    var row = document.getElementById('row-' + id);
    if (cb.checked) { selected.add(id);    row.classList.add('tr-sel'); row.style.background=''; }
    else            { selected.delete(id); row.classList.remove('tr-sel'); }
  });
  updateBulkBar();
}

function clearSelection() {
  document.querySelectorAll('.post-cb').forEach(function(c){ c.checked = false; });
  document.getElementById('cb-all').checked = false;
  document.getElementById('cb-all').indeterminate = false;
  document.querySelectorAll('.tr-sel').forEach(function(r){ r.classList.remove('tr-sel'); });
  selected.clear();
  updateBulkBar();
}

function openCatModal()  { document.getElementById('cat-modal').classList.add('open'); }
function closeCatModal() { document.getElementById('cat-modal').classList.remove('open'); }
document.getElementById('cat-modal').addEventListener('click', function(e){ if(e.target===this) closeCatModal(); });

function bulkAction(action, confirmNeeded) {
  if (selected.size === 0) return;
  if (confirmNeeded) {
    if (!confirm('Deletar ' + selected.size + ' post(s) permanentemente? Esta ação não pode ser desfeita.')) return;
  }
  closeCatModal();

  var fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', action);
  fd.append('ids', JSON.stringify(Array.from(selected)));
  if (action === 'move_category') {
    fd.append('category_id', document.getElementById('cat-select').value);
  }

  // Feedback visual
  var bar = document.getElementById('bulk-bar');
  bar.style.opacity = '.5';
  bar.style.pointerEvents = 'none';

  fetch(BASE + '/admin/ajax_bulk.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d) {
      if (d.ok) {
        // Remove linhas deletadas ou recarrega para refletir mudanças
        if (action === 'delete') {
          selected.forEach(function(id) {
            var row = document.getElementById('row-' + id);
            if (row) {
              row.style.transition = 'opacity .25s';
              row.style.opacity = '0';
              setTimeout(function(){ row.remove(); }, 250);
            }
          });
          setTimeout(function(){ clearSelection(); }, 300);
        } else {
          // Recarrega a página para refletir novos status/categorias
          location.reload();
        }
      } else {
        alert('Erro: ' + (d.error || 'Tente novamente'));
        bar.style.opacity = '';
        bar.style.pointerEvents = '';
      }
    })
    .catch(function() {
      alert('Erro de conexão. Tente novamente.');
      bar.style.opacity = '';
      bar.style.pointerEvents = '';
    });
}

// Atalho teclado: Escape limpa seleção
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') clearSelection();
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
