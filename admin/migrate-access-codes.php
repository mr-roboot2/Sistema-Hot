<?php
/**
 * admin/migrate-access-codes.php
 *
 * Migração one-shot: adiciona em users as colunas necessárias para o
 * recurso de códigos de acesso pré-pagos (admin/acessos.php).
 *
 * Idempotente — pode rodar quantas vezes quiser. Só faz ALTER do que falta.
 * Acesso restrito a admin logado.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
requireLoginAlways();
if ((currentUser()['role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/index'); exit;
}

header('Content-Type: text/html; charset=utf-8');
$db = getDB();

$columns = [
    'access_code'    => "ALTER TABLE users ADD COLUMN access_code VARCHAR(20) DEFAULT NULL",
    'is_anonymous'   => "ALTER TABLE users ADD COLUMN is_anonymous TINYINT(1) DEFAULT 0",
    'first_login_at' => "ALTER TABLE users ADD COLUMN first_login_at DATETIME DEFAULT NULL",
    'created_by'     => "ALTER TABLE users ADD COLUMN created_by INT DEFAULT NULL",
];

$existing = [];
$stmt = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) $existing[strtolower($col)] = true;

$report = [];
foreach ($columns as $col => $sql) {
    if (isset($existing[$col])) {
        $report[] = ['col' => $col, 'status' => 'skip', 'msg' => 'já existe'];
        continue;
    }
    try {
        $db->exec($sql);
        $report[] = ['col' => $col, 'status' => 'ok', 'msg' => 'criada'];
    } catch (Exception $e) {
        $report[] = ['col' => $col, 'status' => 'err', 'msg' => $e->getMessage()];
    }
}

$idxStmt = $db->query("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                         AND INDEX_NAME = 'idx_users_access_code'");
if (!$idxStmt->fetch()) {
    try {
        $db->exec("CREATE INDEX idx_users_access_code ON users (access_code)");
        $report[] = ['col' => 'idx_users_access_code', 'status' => 'ok', 'msg' => 'índice criado'];
    } catch (Exception $e) {
        $report[] = ['col' => 'idx_users_access_code', 'status' => 'err', 'msg' => $e->getMessage()];
    }
} else {
    $report[] = ['col' => 'idx_users_access_code', 'status' => 'skip', 'msg' => 'índice já existe'];
}
?><!doctype html>
<html lang="pt-br"><head><meta charset="utf-8"><title>Migração · access codes</title>
<style>
body{font-family:system-ui,sans-serif;background:#0e0f17;color:#e5e7eb;padding:40px;max-width:680px;margin:0 auto}
h1{font-size:18px;margin:0 0 16px}
table{width:100%;border-collapse:collapse;background:#171823;border-radius:8px;overflow:hidden}
th,td{padding:10px 14px;border-bottom:1px solid #2a2c3a;text-align:left;font-size:13px}
th{background:#1f2030;color:#9ca3af;font-weight:600;text-transform:uppercase;font-size:11px;letter-spacing:.5px}
.ok{color:#10b981;font-weight:700}.skip{color:#9ca3af}.err{color:#ff4d6a;font-weight:700}
.note{margin-top:18px;font-size:12px;color:#9ca3af;line-height:1.6}
a{color:#7c6aff}
</style></head><body>
<h1>🛠️  Migração — colunas de códigos de acesso</h1>
<table>
  <thead><tr><th>Coluna / índice</th><th>Status</th><th>Detalhe</th></tr></thead>
  <tbody>
  <?php foreach ($report as $r): ?>
    <tr>
      <td><code><?= htmlspecialchars($r['col']) ?></code></td>
      <td class="<?= $r['status'] ?>"><?= $r['status']==='ok'?'✓ aplicada':($r['status']==='skip'?'– pulada':'✗ erro') ?></td>
      <td><?= htmlspecialchars($r['msg']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="note">
  Se aparecer erro de permissão (algo como <em>“ALTER command denied”</em>),
  o usuário do MySQL configurado em <code>includes/config.php</code> não tem
  permissão DDL nesse banco. Nesse caso rode o SQL via phpMyAdmin
  (que costuma usar credenciais com permissão total no seu banco).<br><br>
  → <a href="<?= htmlspecialchars(SITE_URL) ?>/admin/acessos">Voltar para Códigos de acesso</a>
</p>
</body></html>
