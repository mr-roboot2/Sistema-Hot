<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
requireLoginAlways();
if ((currentUser()['role'] ?? '') !== 'admin') { header('Location: ' . SITE_URL . '/index'); exit; }

$db = getDB();
$pageTitle = 'Log de Auditoria';

// Garante tabela
try { $db->exec("CREATE TABLE IF NOT EXISTS audit_log (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, user_name VARCHAR(100), action VARCHAR(100) NOT NULL, target VARCHAR(200), detail TEXT, ip VARCHAR(45), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"); } catch(Exception $e) {}

$page    = max(1,(int)($_GET['page']??1));
$perPage = 50;
$offset  = ($page-1)*$perPage;
$search  = trim($_GET['q']??'');
$where   = $search ? 'WHERE action LIKE ? OR user_name LIKE ? OR target LIKE ?' : '';
$params  = $search ? ["%$search%","%$search%","%$search%"] : [];

$total = $db->prepare("SELECT COUNT(*) FROM audit_log $where");
$total->execute($params); $total=(int)$total->fetchColumn();
$pages = (int)ceil($total/$perPage);

$logs = $db->prepare("SELECT * FROM audit_log $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$logs->execute($params); $logs=$logs->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap">
  <div>
    <div style="font-size:18px;font-weight:700">🔍 Log de Auditoria</div>
    <div class="txt-muted-xs"><?= number_format($total) ?> registros</div>
  </div>
  <form method="GET" style="display:flex;gap:8px;margin-left:auto">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar ação, usuário..." class="form-control" style="width:240px;font-size:13px;padding:7px 12px">
    <button type="submit" class="btn btn-secondary" style="padding:7px 14px">🔍</button>
    <?php if($search):?><a href="?" class="btn btn-secondary" style="padding:7px 12px">✕</a><?php endif;?>
  </form>
</div>

<div class="card" style="overflow:hidden">
  <?php if($logs): ?>
  <table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead>
      <tr style="border-bottom:1px solid var(--border)">
        <th style="padding:9px 14px;text-align:left;color:var(--muted);font-size:11px;font-weight:600">DATA</th>
        <th style="padding:9px 14px;text-align:left;color:var(--muted);font-size:11px;font-weight:600">USUÁRIO</th>
        <th style="padding:9px 14px;text-align:left;color:var(--muted);font-size:11px;font-weight:600">AÇÃO</th>
        <th style="padding:9px 14px;text-align:left;color:var(--muted);font-size:11px;font-weight:600">ALVO</th>
        <th style="padding:9px 14px;text-align:left;color:var(--muted);font-size:11px;font-weight:600">IP</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($logs as $log): ?>
    <tr style="border-bottom:1px solid var(--border)">
      <td style="padding:9px 14px;color:var(--muted2);white-space:nowrap"><?= date('d/m/y H:i', strtotime($log['created_at'])) ?></td>
      <td style="padding:9px 14px;font-weight:500"><?= htmlspecialchars($log['user_name'] ?? '—') ?></td>
      <td style="padding:9px 14px">
        <span style="padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;
          background:<?= strpos($log['action'],'delete')!==false?'rgba(255,77,106,.12)':
            (strpos($log['action'],'edit')!==false?'rgba(124,106,255,.12)':'rgba(16,185,129,.12)') ?>;
          color:<?= strpos($log['action'],'delete')!==false?'var(--danger)':
            (strpos($log['action'],'edit')!==false?'var(--accent)':'var(--success)') ?>">
          <?= htmlspecialchars($log['action']) ?>
        </span>
      </td>
      <td style="padding:9px 14px;color:var(--muted2)"><?= htmlspecialchars($log['target'] ?? '—') ?><?= $log['detail']?' · <span style="color:var(--muted)">'.htmlspecialchars(mb_substr($log['detail'],0,40)).'</span>':'' ?></td>
      <td style="padding:9px 14px;font-family:monospace;font-size:11px;color:var(--muted)"><?= htmlspecialchars($log['ip'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php echo renderPagination($page,$pages,'?'.http_build_query(array_diff_key($_GET,['page'=>'']))); ?>
  <?php else: ?>
  <div style="padding:48px;text-align:center;color:var(--muted)">
    <div style="font-size:32px;margin-bottom:12px">📋</div>
    <p>Nenhum registro ainda.</p>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
