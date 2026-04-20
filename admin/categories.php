<?php
require_once __DIR__ . '/../includes/auth.php';
requireLoginAlways();
if (!isAdmin()) { header('Location: ' . SITE_URL . '/index'); exit; }

$db        = getDB();
$pageTitle = 'Categorias';
$message   = '';
$error     = '';

// Delete
if (isset($_GET['del']) && csrf_verify($_GET['csrf'] ?? '')) {
    $id = (int)$_GET['del'];
    $db->prepare('UPDATE posts SET category_id=NULL WHERE category_id=?')->execute([$id]);
    $db->prepare('DELETE FROM categories WHERE id=?')->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/categories.php?deleted=1');
    exit;
}

// Save (create or edit)
$editing = null;
if (isset($_GET['edit'])) {
    $s = $db->prepare('SELECT * FROM categories WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $editing = $s->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Token inválido.';
    } else {
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#6366f1';
        $icon  = trim($_POST['icon'] ?? 'folder');
        $editId = (int)($_POST['edit_id'] ?? 0);

        if (!$name) {
            $error = 'Nome é obrigatório.';
        } else {
            $slug = slugify($name);
            $base = $slug; $i = 1;
            while (true) {
                $chk = $db->prepare('SELECT id FROM categories WHERE slug=?' . ($editId ? ' AND id!=?' : ''));
                $chk->execute($editId ? [$slug, $editId] : [$slug]);
                if (!$chk->fetch()) break;
                $slug = $base . '-' . $i++;
            }
            if ($editId) {
                $db->prepare('UPDATE categories SET name=?,slug=?,description=?,color=?,icon=? WHERE id=?')
                   ->execute([$name, $slug, $desc, $color, $icon, $editId]);
                $message = 'Categoria atualizada!';
            } else {
                $db->prepare('INSERT INTO categories (name,slug,description,color,icon) VALUES (?,?,?,?,?)')
                   ->execute([$name, $slug, $desc, $color, $icon]);
                $message = 'Categoria criada!';
            }
            $editing = null;
            header('Location: ' . SITE_URL . '/admin/categories.php?saved=1');
            exit;
        }
    }
}

if (isset($_GET['saved']))  $message = 'Categoria salva com sucesso!';
if (isset($_GET['deleted'])) $message = 'Categoria removida.';

$cats = $db->query('
    SELECT c.*, COUNT(p.id) AS post_count
    FROM categories c
    LEFT JOIN posts p ON p.category_id = c.id
    GROUP BY c.id ORDER BY c.name
')->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<style>
.color-swatch{width:28px;height:28px;border-radius:6px;display:inline-block;flex-shrink:0}
.cat-row{display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid var(--border);transition:background .15s}
.cat-row:hover{background:var(--surface2)}
.cat-row:last-child{border-bottom:none}
</style>

<?php if ($message): ?>
<div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start">

<!-- Lista -->
<div class="card">
  <div style="padding:18px 20px;border-bottom:1px solid var(--border);font-family:'Roboto',sans-serif;font-size:16px;font-weight:700">
    Categorias (<?= count($cats) ?>)
  </div>
  <?php if ($cats): ?>
  <?php foreach ($cats as $c): ?>
  <div class="cat-row">
    <div class="color-swatch" style="background:<?= htmlspecialchars($c['color']) ?>"></div>
    <div style="flex:1;min-width:0">
      <div style="font-size:14px;font-weight:600"><?= htmlspecialchars($c['name']) ?></div>
      <div class="txt-muted-sm">
        <?php $pc = (int)($c['post_count'] ?? 0); ?>
        <?= $pc ?> post<?= $pc != 1 ? 's' : '' ?>
        <?php if (!empty($c['description'])): ?> · <?= htmlspecialchars(substr($c['description'], 0, 50)) ?><?php endif; ?>
      </div>
    </div>
    <a href="<?= SITE_URL ?>/posts?cat=<?= $c['id'] ?>" class="btn btn-secondary" style="padding:5px 12px;font-size:12px">Ver</a>
    <a href="?edit=<?= $c['id'] ?>" class="btn btn-secondary" style="padding:5px 12px;font-size:12px">Editar</a>
    <a href="?del=<?= $c['id'] ?>&csrf=<?= csrf_token() ?>"
       onclick="return confirm('Excluir categoria? Os posts não serão deletados.')"
       class="btn btn-danger" style="padding:5px 12px;font-size:12px">✕</a>
  </div>
  <?php endforeach; ?>
  <?php else: ?>
  <div style="padding:40px;text-align:center;color:var(--muted)">Nenhuma categoria ainda.</div>
  <?php endif; ?>
</div>

<!-- Form -->
<div class="card" style="padding:24px">
  <div style="font-family:'Roboto',sans-serif;font-size:16px;font-weight:700;margin-bottom:20px">
    <?= $editing ? 'Editar: ' . htmlspecialchars($editing['name']) : 'Nova Categoria' ?>
  </div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="edit_id" value="<?= $editing['id'] ?? 0 ?>">

    <div class="form-group">
      <label class="form-label">Nome *</label>
      <input type="text" name="name" class="form-control" required placeholder="Ex: Tecnologia"
             value="<?= htmlspecialchars($editing['name'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label class="form-label">Descrição</label>
      <textarea name="description" class="form-control" rows="3" placeholder="Descrição opcional..."><?= htmlspecialchars($editing['description'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <label class="form-label">Cor</label>
      <div style="display:flex;align-items:center;gap:10px">
        <input type="color" name="color" value="<?= htmlspecialchars($editing['color'] ?? '#6366f1') ?>"
               style="width:48px;height:38px;border-radius:8px;border:1px solid var(--border);background:var(--surface2);cursor:pointer;padding:2px">
        <input type="text" id="color-hex" class="form-control" style="flex:1" placeholder="#6366f1"
               value="<?= htmlspecialchars($editing['color'] ?? '#6366f1') ?>">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Ícone (nome Feather/Lucide)</label>
      <input type="text" name="icon" class="form-control" placeholder="Ex: folder, image, video"
             value="<?= htmlspecialchars($editing['icon'] ?? 'folder') ?>">
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
      <?= $editing ? 'Salvar alterações' : 'Criar categoria' ?>
    </button>
    <?php if ($editing): ?>
    <a href="<?= SITE_URL ?>/admin/categories.php" class="btn btn-secondary" style="width:100%;justify-content:center;margin-top:8px">Cancelar</a>
    <?php endif; ?>
  </form>
</div>
</div>

<script>
const colorInput = document.querySelector('input[type=color]');
const hexInput   = document.getElementById('color-hex');
colorInput.addEventListener('input', () => hexInput.value = colorInput.value);
hexInput.addEventListener('input', () => {
  if (/^#[0-9a-fA-F]{6}$/.test(hexInput.value)) colorInput.value = hexInput.value;
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
