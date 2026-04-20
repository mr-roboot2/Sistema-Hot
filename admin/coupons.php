<?php
require_once __DIR__ . '/../includes/auth.php';
requireLoginAlways();
if ((currentUser()['role'] ?? '') !== 'admin') { header('Location: ' . SITE_URL . '/index'); exit; }

$db = getDB();
$pageTitle = 'Cupons';
$message = $error = '';

// Cria tabela
try {
    $db->exec("CREATE TABLE IF NOT EXISTS coupons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL UNIQUE,
        type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
        value DECIMAL(10,2) NOT NULL,
        max_uses INT DEFAULT NULL,
        used_count INT DEFAULT 0,
        valid_until DATETIME DEFAULT NULL,
        active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch(Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $code  = strtoupper(trim($_POST['code'] ?? ''));
        $type  = in_array($_POST['type']??'', ['percent','fixed']) ? $_POST['type'] : 'percent';
        $value = (float)($_POST['value'] ?? 0);
        $max   = (int)($_POST['max_uses'] ?? 0) ?: null;
        $until = $_POST['valid_until'] ? date('Y-m-d H:i:s', strtotime($_POST['valid_until'])) : null;

        if (!$code || $value <= 0) {
            $error = 'Código e valor são obrigatórios.';
        } elseif ($type === 'percent' && $value > 100) {
            $error = 'Desconto percentual não pode ser maior que 100%.';
        } else {
            try {
                $db->prepare('INSERT INTO coupons (code,type,value,max_uses,valid_until) VALUES (?,?,?,?,?)')
                   ->execute([$code, $type, $value, $max, $until]);
                auditLog('create_coupon', 'coupon:'.$code);
                $message = "✅ Cupom <b>$code</b> criado!";
            } catch(Exception $e) {
                $error = 'Código já existe.';
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('UPDATE coupons SET active = 1-active WHERE id=?')->execute([$id]);
        $message = '✅ Status alterado.';
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $c  = $db->prepare('SELECT code FROM coupons WHERE id=?'); $c->execute([$id]); $c=$c->fetchColumn();
        $db->prepare('DELETE FROM coupons WHERE id=?')->execute([$id]);
        auditLog('delete_coupon', 'coupon:'.$c);
        $message = '✅ Cupom removido.';
    }
}

$coupons = $db->query('SELECT * FROM coupons ORDER BY created_at DESC')->fetchAll();
$plans   = $db->query('SELECT id,name FROM plans WHERE active=1 ORDER BY name')->fetchAll();
require __DIR__ . '/../includes/header.php';
?>
<?php if($message):?><div class="alert alert-success" style="margin-bottom:16px">&#10003; <?= $message ?></div><?php endif;?>
<?php if($error):?><div class="alert alert-danger" style="margin-bottom:16px">&#10007; <?= htmlspecialchars($error) ?></div><?php endif;?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">

<!-- Lista -->
<div class="card" style="overflow:hidden">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border);font-weight:700;font-size:15px">
    Cupons (<?= count($coupons) ?>)
  </div>
  <?php if($coupons): ?>
  <table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead>
      <tr style="border-bottom:1px solid var(--border)">
        <th style="padding:9px 14px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">CÓDIGO</th>
        <th style="padding:9px 14px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">DESCONTO</th>
        <th style="padding:9px 14px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">USOS</th>
        <th style="padding:9px 14px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">VALIDADE</th>
        <th style="padding:9px 14px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">STATUS</th>
        <th style="padding:9px 14px"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($coupons as $cp):
      $expired = $cp['valid_until'] && strtotime($cp['valid_until']) < time();
      $exhausted = $cp['max_uses'] && $cp['used_count'] >= $cp['max_uses'];
    ?>
    <tr style="border-bottom:1px solid var(--border)">
      <td style="padding:9px 14px;font-family:monospace;font-weight:700;font-size:14px"><?= htmlspecialchars($cp['code']) ?></td>
      <td style="padding:9px 14px">
        <span style="color:var(--success);font-weight:600">
          <?= $cp['type']==='percent' ? $cp['value'].'%' : 'R$ '.number_format($cp['value'],2,',','.') ?>
        </span>
      </td>
      <td style="padding:9px 14px;color:var(--muted2)">
        <?= $cp['used_count'] ?><?= $cp['max_uses'] ? ' / '.$cp['max_uses'] : '' ?>
      </td>
      <td style="padding:9px 14px;color:var(--muted2);font-size:12px">
        <?= $cp['valid_until'] ? date('d/m/Y', strtotime($cp['valid_until'])) : '—' ?>
      </td>
      <td style="padding:9px 14px">
        <?php if($expired||$exhausted): ?>
          <span style="font-size:11px;color:var(--danger)">⛔ <?= $expired?'Expirado':'Esgotado' ?></span>
        <?php elseif($cp['active']): ?>
          <span style="font-size:11px;color:var(--success)">✅ Ativo</span>
        <?php else: ?>
          <span style="font-size:11px;color:var(--muted)">⏸ Inativo</span>
        <?php endif; ?>
      </td>
      <td style="padding:9px 14px;text-align:right;display:flex;gap:6px;justify-content:flex-end">
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="id" value="<?= $cp['id'] ?>">
          <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:11px">
            <?= $cp['active'] ? 'Desativar' : 'Ativar' ?>
          </button>
        </form>
        <form method="POST" style="display:inline" onsubmit="return confirm('Remover cupom <?= htmlspecialchars($cp['code']) ?>?')">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $cp['id'] ?>">
          <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:11px;color:var(--danger)">🗑️</button>
        </form>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  <?php else: ?>
  <div style="padding:40px;text-align:center;color:var(--muted)">Nenhum cupom criado ainda.</div>
  <?php endif; ?>
</div>

<!-- Criar -->
<div class="card" style="padding:20px">
  <div style="font-weight:700;font-size:15px;margin-bottom:16px">➕ Novo Cupom</div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="create">
    <div style="margin-bottom:12px">
      <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:5px">CÓDIGO</label>
      <input type="text" name="code" class="form-control" placeholder="EX: PROMO20" required
             style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()">
    </div>
    <div style="margin-bottom:12px">
      <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:5px">TIPO</label>
      <select name="type" class="form-control">
        <option value="percent">Percentual (%)</option>
        <option value="fixed">Valor fixo (R$)</option>
      </select>
    </div>
    <div style="margin-bottom:12px">
      <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:5px">VALOR</label>
      <input type="number" name="value" class="form-control" placeholder="Ex: 20" min="0.01" step="0.01" required>
    </div>
    <div style="margin-bottom:12px">
      <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:5px">USOS MÁXIMOS (vazio = ilimitado)</label>
      <input type="number" name="max_uses" class="form-control" placeholder="Ex: 100" min="1">
    </div>
    <div style="margin-bottom:16px">
      <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:5px">VÁLIDO ATÉ (opcional)</label>
      <input type="date" name="valid_until" class="form-control">
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%">Criar Cupom</button>
  </form>
</div>

</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
