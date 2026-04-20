<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
requireLoginAlways();
if (!isAdmin()) { header('Location: ' . SITE_URL . '/index'); exit; }
session_write_close(); // libera lock de sessão

$db        = getDB();
$pageTitle = 'Financeiro';
$message   = '';

try {
    $db->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT, plan_id INT, external_id VARCHAR(150),
        gateway VARCHAR(50) DEFAULT 'helix', amount DECIMAL(10,2),
        currency VARCHAR(10) DEFAULT 'BRL',
        status ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
        payment_method VARCHAR(50) DEFAULT 'pix',
        pix_code TEXT, pix_expires_at DATETIME, paid_at DATETIME,
        payload LONGTEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch(Exception $e) {}

// ── Exportar CSV ──────────────────────────────
if (isset($_GET['export_csv']) && csrf_verify($_GET['csrf_token'] ?? '')) {
    $rows = $db->query('
        SELECT t.id, u.name AS usuario, u.phone, p.name AS plano,
               t.amount, t.status, t.gateway, t.payment_method,
               t.created_at, t.paid_at
        FROM transactions t
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN plans p ON p.id = t.plan_id
        ORDER BY t.created_at DESC
    ')->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transacoes_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8 para Excel
    fputcsv($out, ['ID','Usuário','Telefone','Plano','Valor','Status','Gateway','Método','Criado em','Pago em'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['usuario'], $r['phone'], $r['plano'],
            number_format((float)$r['amount'], 2, ',', '.'),
            $r['status'], $r['gateway'], $r['payment_method'],
            $r['created_at'] ? date('d/m/Y H:i', strtotime($r['created_at'])) : '',
            $r['paid_at']    ? date('d/m/Y H:i', strtotime($r['paid_at']))    : '',
        ], ';');
    }
    fclose($out);
    exit;
}

// ── Confirmar PIX manualmente ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_pix']) && csrf_verify($_POST['csrf_token'] ?? '')) {
    $txId = (int)($_POST['tx_id'] ?? 0);
    if ($txId) {
        $tx = $db->prepare('SELECT t.*,u.id AS uid FROM transactions t LEFT JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.status="pending"');
        $tx->execute([$txId]); $tx = $tx->fetch();
        if ($tx) {
            $db->beginTransaction();
            try {
                // Marca como pago
                $db->prepare('UPDATE transactions SET status="paid", paid_at=NOW() WHERE id=?')->execute([$txId]);
                // Ativa plano do usuário
                if ($tx['uid'] && $tx['plan_id']) {
                    require_once __DIR__ . '/../includes/pix.php';
                    activatePlan((int)$tx['uid'], (int)$tx['plan_id']);
                }
                $db->commit();
                auditLog('confirm_pix_manual', 'tx:'.$txId, 'user:'.$tx['uid']);
                $message = '✅ PIX #'.$txId.' confirmado manualmente e plano ativado!';
            } catch(Exception $e) {
                $db->rollBack();
                $message = '❌ Erro: '.$e->getMessage();
            }
        } else {
            $message = '❌ Transação não encontrada ou já confirmada.';
        }
    }
}

// ── Filtros ───────────────────────────────────
$fStatus  = $_GET['status']   ?? '';
$fPlan    = (int)($_GET['plan']    ?? 0);
$fUser    = (int)($_GET['user']    ?? 0);
$fDateFrom= trim($_GET['date_from'] ?? '');
$fDateTo  = trim($_GET['date_to']   ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 25;

// ── KPIs — usa PHP timestamp para evitar problema de fuso ──
$nowTs = date('Y-m-d H:i:s'); // mesmo fuso que foi gravado pelo PHP
$kpi = [
    'total_paid'      => (float)$db->query('SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status="paid"')->fetchColumn(),
    'this_month'      => (float)$db->query('SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status="paid" AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())')->fetchColumn(),
    'last_month'      => (float)$db->query('SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status="paid" AND MONTH(paid_at)=MONTH(DATE_SUB(NOW(),INTERVAL 1 MONTH)) AND YEAR(paid_at)=YEAR(DATE_SUB(NOW(),INTERVAL 1 MONTH))')->fetchColumn(),
    'today'           => (float)$db->query('SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status="paid" AND DATE(paid_at)=CURDATE()')->fetchColumn(),
    'paid_count'      => (int)$db->query('SELECT COUNT(*) FROM transactions WHERE status="paid"')->fetchColumn(),
    'total_generated' => (int)$db->query('SELECT COUNT(*) FROM transactions')->fetchColumn(),
    'failed_count'    => (int)$db->query('SELECT COUNT(*) FROM transactions WHERE status IN ("failed","refunded")')->fetchColumn(),
    'pending_count'   => 0,
    'pending_value'   => 0.0,
    'expired_count'   => 0,
    'expired_value'   => 0.0,
];
// Pending e expired: usa parâmetro PHP para garantir mesmo fuso
$s = $db->prepare('SELECT COUNT(*), COALESCE(SUM(amount),0) FROM transactions WHERE status="pending" AND (pix_expires_at IS NULL OR pix_expires_at > ?)');
$s->execute([$nowTs]); $r = $s->fetch(PDO::FETCH_NUM);
$kpi['pending_count'] = (int)$r[0]; $kpi['pending_value'] = (float)$r[1];

$s = $db->prepare('SELECT COUNT(*), COALESCE(SUM(amount),0) FROM transactions WHERE status="pending" AND pix_expires_at IS NOT NULL AND pix_expires_at < ?');
$s->execute([$nowTs]); $r = $s->fetch(PDO::FETCH_NUM);
$kpi['expired_count'] = (int)$r[0]; $kpi['expired_value'] = (float)$r[1];

// Taxa de conversão
$kpi['conv_rate'] = $kpi['total_generated'] > 0
    ? round($kpi['paid_count'] / $kpi['total_generated'] * 100)
    : 0;

// ── Receita por mês ───────────────────────────
$revenueByMonth = $db->query('
    SELECT DATE_FORMAT(paid_at,"%Y-%m") AS month,
           DATE_FORMAT(paid_at,"%b/%Y") AS label,
           SUM(amount) AS total, COUNT(*) AS cnt
    FROM transactions WHERE status="paid"
      AND paid_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month ORDER BY month ASC
')->fetchAll();

// ── PIX por usuário ───────────────────────────
$puStmt = $db->prepare('
    SELECT u.id, u.name, u.phone,
           COUNT(t.id) AS total_tx,
           SUM(t.status="paid") AS paid_count,
           SUM(CASE WHEN t.status="paid" THEN t.amount ELSE 0 END) AS total_paid,
           SUM(CASE WHEN t.status="pending" AND (t.pix_expires_at IS NULL OR t.pix_expires_at > ?) THEN 1 ELSE 0 END) AS pending_count,
           SUM(CASE WHEN t.status="pending" AND (t.pix_expires_at IS NULL OR t.pix_expires_at > ?) THEN t.amount ELSE 0 END) AS pending_value,
           MAX(t.created_at) AS last_tx
    FROM users u
    INNER JOIN transactions t ON t.user_id = u.id
    GROUP BY u.id ORDER BY total_paid DESC, total_tx DESC
    LIMIT 30
');
$puStmt->execute([$nowTs, $nowTs]);
$pixByUser = $puStmt->fetchAll();


// ── Listagem filtrada ─────────────────────────
$where = ['1=1']; $params = [];
if ($fStatus && in_array($fStatus, ['paid','pending','failed','refunded','expired'])) {
    if ($fStatus === 'expired') {
        $where[] = 't.status="pending" AND t.pix_expires_at IS NOT NULL AND t.pix_expires_at < ?';
        $params[] = $nowTs;
    } else {
        $where[] = 't.status=?'; $params[] = $fStatus;
        if ($fStatus === 'pending') {
            $where[] = '(t.pix_expires_at IS NULL OR t.pix_expires_at > ?)';
            $params[] = $nowTs;
        }
    }
}
if ($fPlan) { $where[] = 't.plan_id=?'; $params[] = $fPlan; }
if ($fUser) { $where[] = 't.user_id=?'; $params[] = $fUser; }
if ($fDateFrom) { $where[] = 'DATE(t.created_at) >= ?'; $params[] = $fDateFrom; }
if ($fDateTo)   { $where[] = 'DATE(t.created_at) <= ?'; $params[] = $fDateTo; }
$sql = implode(' AND ', $where);

$totalRows = $db->prepare("SELECT COUNT(*) FROM transactions t WHERE $sql");
$totalRows->execute($params); $totalRows = (int)$totalRows->fetchColumn();
$pages  = (int)ceil($totalRows / $perPage);
$offset = ($page - 1) * $perPage;

// Soma do período filtrado
$sumFiltered = null;
if ($fDateFrom || $fDateTo || $fStatus || $fPlan || $fUser) {
    $s = $db->prepare("SELECT COALESCE(SUM(t.amount),0), COUNT(*) FROM transactions t WHERE $sql AND t.status='paid'");
    $s->execute($params);
    $row = $s->fetch(PDO::FETCH_NUM);
    $sumFiltered = ['total' => (float)$row[0], 'count' => (int)$row[1]];
}

$txStmt = $db->prepare("
    SELECT t.*, u.name AS user_name, u.phone,
           p.name AS plan_name, p.color AS plan_color
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN plans p ON t.plan_id = p.id
    WHERE $sql
    ORDER BY t.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$txStmt->execute($params);
$transactions = $txStmt->fetchAll();

$plans = $db->query('SELECT id, name FROM plans ORDER BY name')->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/financeiro.css">

<?php if($message): ?>
<div class="alert alert-<?= strpos($message,'❌')!==false?'danger':'success' ?>" style="margin-bottom:16px"><?= $message ?></div>
<?php endif; ?>

<!-- Botão exportar CSV -->
<div style="display:flex;justify-content:flex-end;margin-bottom:16px">
  <a href="?export_csv=1&csrf_token=<?= csrf_token() ?>"
     class="btn btn-secondary" style="font-size:13px;padding:7px 14px;display:inline-flex;align-items:center;gap:6px">
    📥 Exportar CSV
  </a>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:12px;margin-bottom:24px">

  <div class="fi-kpi green">
    <div class="kpi-lbl">Total Recebido</div>
    <div class="kpi-num" style="color:var(--success)" id="sse-total-paid">R$ <?= number_format($kpi['total_paid'],2,',','.') ?></div>
    <div class="kpi-sub"><?= $kpi['paid_count'] ?> pago<?= $kpi['paid_count']!=1?'s':'' ?></div>
  </div>

  <div class="fi-kpi purple">
    <div class="kpi-lbl">Este Mês</div>
    <div class="kpi-num" style="color:var(--accent)">R$ <?= number_format($kpi['this_month'],2,',','.') ?></div>
    <?php if ($kpi['last_month'] > 0):
      $pct = round(($kpi['this_month']-$kpi['last_month'])/$kpi['last_month']*100); $up=$pct>=0; ?>
    <div class="kpi-sub" style="color:<?= $up?'var(--success)':'var(--danger)' ?>"><?= $up?'▲':'▼' ?> <?= abs($pct) ?>% vs anterior</div>
    <?php else: ?><div class="kpi-sub">Mês ant: R$ <?= number_format($kpi['last_month'],2,',','.') ?></div><?php endif; ?>
  </div>

  <div class="fi-kpi teal">
    <div class="kpi-lbl">Hoje</div>
    <div class="kpi-num" style="color:#0fd494" id="sse-today">R$ <?= number_format($kpi['today'],2,',','.') ?></div>
    <div class="kpi-sub"><?= date('d/m/Y') ?></div>
  </div>

  <div class="fi-kpi orange">
    <div class="kpi-lbl">PIX Aguardando</div>
    <div class="kpi-num" style="color:var(--warning)" id="sse-pending"><?= $kpi['pending_count'] ?></div>
    <div class="kpi-sub">R$ <?= number_format($kpi['pending_value'],2,',','.') ?> pendente</div>
  </div>

  <div class="fi-kpi red">
    <div class="kpi-lbl">PIX Não Pagos</div>
    <div class="kpi-num" style="color:var(--danger)"><?= $kpi['expired_count'] ?></div>
    <div class="kpi-sub" title="Valor gerado que expirou sem pagamento">R$ <?= number_format($kpi['expired_value'],2,',','.') ?> perdidos</div>
  </div>

  <div class="fi-kpi pink">
    <div class="kpi-lbl">Conversão</div>
    <div class="kpi-num" style="color:var(--accent2)"><?= $kpi['conv_rate'] ?>%</div>
    <div class="kpi-sub"><?= $kpi['total_generated'] ?> PIX gerados no total</div>
  </div>

</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start">

<!-- ── COLUNA ESQUERDA ── -->
<div>

  <!-- Gráfico receita -->
  <?php if ($revenueByMonth): ?>
  <div class="card" style="padding:20px;margin-bottom:18px">
    <div style="font-family:'Roboto',sans-serif;font-size:14px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between">
      📈 Receita por Mês
      <span style="font-size:12px;color:var(--muted);font-weight:400">últimos 6 meses</span>
    </div>
    <?php $maxR = max(array_column($revenueByMonth,'total')) ?: 1; ?>
    <?php foreach ($revenueByMonth as $m): $pct = round((float)$m['total']/$maxR*100); ?>
    <div class="bar-row">
      <div class="bar-label"><?= $m['label'] ?></div>
      <div class="bar-track">
        <div class="bar-fill" style="width:<?= $pct ?>%;background:linear-gradient(90deg,var(--success),#0fd494)">
          <?php if ($pct > 14): ?><span style="font-size:11px;font-weight:700;color:#fff;white-space:nowrap">R$ <?= number_format((float)$m['total'],2,',','.') ?></span><?php endif; ?>
        </div>
      </div>
      <div style="font-size:11px;color:var(--muted);width:30px;text-align:right;flex-shrink:0"><?= $m['cnt'] ?>x</div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Tabela de transações -->
  <div class="card" style="overflow:hidden">

    <!-- Cabeçalho + filtros -->
    <div style="padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div style="font-family:'Roboto',sans-serif;font-size:15px;font-weight:700">
        🧾 Todas as Transações
        <span style="font-size:12px;color:var(--muted);font-weight:400;margin-left:6px"><?= number_format($totalRows) ?> registro<?= $totalRows!=1?'s':'' ?></span>
      </div>
      <?php if ($fUser): ?>
      <?php $un = $db->prepare('SELECT name FROM users WHERE id=?'); $un->execute([$fUser]); $un=$un->fetchColumn(); ?>
      <div style="display:flex;align-items:center;gap:6px;background:rgba(124,106,255,.1);border:1px solid rgba(124,106,255,.2);border-radius:8px;padding:5px 10px;font-size:12px">
        👤 <?= htmlspecialchars($un) ?>
        <a href="?<?= http_build_query(array_merge(array_filter($_GET), ['user'=>'','page'=>'1'])) ?>" style="color:var(--muted);margin-left:4px">✕</a>
      </div>
      <?php endif; ?>
      <?php if ($sumFiltered): ?>
      <div style="font-size:12px;color:var(--success);font-weight:600">
        Filtrado: R$ <?= number_format($sumFiltered['total'],2,',','.') ?> (<?= $sumFiltered['count'] ?> pago<?= $sumFiltered['count']!=1?'s':'' ?>)
      </div>
      <?php endif; ?>
    </div>

    <!-- Barra de filtros -->
    <form method="GET" class="fbar">
      <select name="status" class="form-control" style="width:150px" onchange="this.form.submit()">
        <option value="">Todos os status</option>
        <option value="paid"    <?= $fStatus==='paid'?'selected':''    ?>>✅ Pago</option>
        <option value="pending" <?= $fStatus==='pending'?'selected':'' ?>>⏳ Aguardando</option>
        <option value="expired" <?= $fStatus==='expired'?'selected':'' ?>>⛔ Não pagos (exp.)</option>
        <option value="failed"  <?= $fStatus==='failed'?'selected':''  ?>>❌ Falhou</option>
      </select>
      <select name="plan" class="form-control" style="width:150px" onchange="this.form.submit()">
        <option value="">Todos os planos</option>
        <?php foreach ($plans as $pl): ?>
        <option value="<?= $pl['id'] ?>" <?= $fPlan==$pl['id']?'selected':'' ?>><?= htmlspecialchars($pl['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div style="display:flex;align-items:center;gap:6px">
        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($fDateFrom) ?>" placeholder="De" title="Data inicial" style="width:140px">
        <span style="color:var(--muted);font-size:12px">→</span>
        <input type="date" name="date_to"   class="form-control" value="<?= htmlspecialchars($fDateTo) ?>"   placeholder="Até" title="Data final"   style="width:140px">
        <button type="submit" class="btn btn-secondary" style="padding:6px 12px;font-size:12px">Filtrar</button>
      </div>
      <?php if ($fStatus||$fPlan||$fUser||$fDateFrom||$fDateTo): ?>
      <a href="<?= SITE_URL ?>/admin/financeiro.php" class="btn btn-secondary" style="font-size:12px;padding:6px 12px;color:var(--danger)">✕ Limpar</a>
      <?php endif; ?>
      <?php if ($fUser): ?><input type="hidden" name="user" value="<?= $fUser ?>"><?php endif; ?>
    </form>

    <!-- Tabela -->
    <?php if ($transactions): ?>
    <table class="ft">
      <thead>
        <tr>
          <th>Cliente</th>
          <th>Plano</th>
          <th style="text-align:right">Valor</th>
          <th style="text-align:center">Status</th>
          <th>Criado</th>
          <th style="text-align:center">Pago</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($transactions as $tx):
        $isPaid    = $tx['status'] === 'paid';
        $isPending = $tx['status'] === 'pending';
        $isExpPix  = $isPending && !empty($tx['pix_expires_at']) && strtotime($tx['pix_expires_at']) < time();
        $rowId     = 'r'.$tx['id'];
      ?>
      <tr>
        <td>
          <div style="font-size:13px;font-weight:600"><?= htmlspecialchars($tx['user_name']??'—') ?></div>
          <?php if (!empty($tx['phone'])): ?>
          <div class="txt-muted-xs">📱 <?= htmlspecialchars($tx['phone']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($tx['plan_name']): ?>
          <span style="font-size:12px;font-weight:700;color:<?= htmlspecialchars($tx['plan_color']) ?>"><?= htmlspecialchars($tx['plan_name']) ?></span>
          <?php else: ?><span style="color:var(--muted);font-size:12px">—</span><?php endif; ?>
        </td>
        <td style="text-align:right">
          <span style="font-family:'Roboto',sans-serif;font-size:14px;font-weight:800;color:<?= $isPaid?'var(--success)':'var(--muted2)' ?>">
            R$ <?= number_format((float)$tx['amount'],2,',','.') ?>
          </span>
        </td>
        <td style="text-align:center">
          <?php if ($isPaid): ?>
            <span class="sb sb-paid">✅ Pago</span>
          <?php elseif ($isExpPix): ?>
            <span class="sb sb-expired">⛔ Expirado</span>
          <?php elseif ($isPending): ?>
            <span class="sb sb-pending">⏳ Aguardando</span>
          <?php else: ?>
            <span class="sb sb-failed">❌ <?= ucfirst($tx['status']) ?></span>
          <?php endif; ?>
        </td>
        <td style="font-size:11px;color:var(--muted);white-space:nowrap">
          <?= date('d/m/Y', strtotime($tx['created_at'])) ?><br>
          <span style="font-size:10px"><?= date('H:i', strtotime($tx['created_at'])) ?></span>
          <?php if ($isPending && !$isExpPix && !empty($tx['pix_expires_at'])): ?>
          <br><span style="color:var(--warning);font-size:10px">exp <?= date('H:i d/m', strtotime($tx['pix_expires_at'])) ?></span>
          <?php endif; ?>
        </td>
        <td style="text-align:center;font-size:11px;color:var(--muted)">
          <?php if ($isPaid && $tx['paid_at']): ?>
          <span style="color:var(--success)"><?= date('d/m H:i', strtotime($tx['paid_at'])) ?></span>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td style="text-align:right">
          <div style="display:flex;gap:5px;justify-content:flex-end">
          <?php if (!empty($tx['pix_code'])): ?>
          <button onclick="togglePix('<?= $rowId ?>')" class="btn btn-secondary" style="padding:4px 8px;font-size:10px">PIX</button>
          <?php endif; ?>
          <?php if (($isPending && !$isExpPix) || $isExpPix): ?>
          <button onclick="openRemarketing(<?= htmlspecialchars(json_encode([
              'name'     => $tx['user_name'] ?? 'cliente',
              'phone'    => $tx['phone'] ?? '',
              'plan'     => $tx['plan_name'] ?? '',
              'amount'   => 'R$ '.number_format((float)$tx['amount'],2,',','.'),
              'pix_code' => $tx['pix_code'] ?? '',
              'expired'  => $isExpPix,
              'url'      => SITE_URL.'/renovar.php',
          ])) ?>)"
            class="btn btn-secondary" style="padding:4px 8px;font-size:10px;color:var(--warning);border-color:rgba(245,158,11,.3)">
            📣
          </button>
          <?php endif; ?>
          <?php if ($isPending && !$isExpPix): ?>
          <form method="POST" style="display:inline" onsubmit="return confirm('Confirmar PIX #<?= $tx['id'] ?> manualmente?\nIsso ativará o plano do usuário.')">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="tx_id" value="<?= $tx['id'] ?>">
            <button type="submit" name="confirm_pix" value="1"
                    class="btn btn-secondary" style="padding:4px 8px;font-size:10px;color:var(--success);border-color:rgba(16,185,129,.3)">
              ✅
            </button>
          </form>
          <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php if (!empty($tx['pix_code'])): ?>
      <tr id="<?= $rowId ?>" class="px-row">
        <td colspan="7" style="padding:0 14px 10px">
          <div style="background:var(--surface2);border-radius:8px;padding:10px 12px;display:flex;gap:8px;align-items:flex-start;margin-top:4px">
            <div class="px-code" style="flex:1"><?= htmlspecialchars($tx['pix_code']) ?></div>
            <button onclick="cpPix('<?= htmlspecialchars(addslashes($tx['pix_code'])) ?>',this)" class="btn btn-secondary" style="padding:4px 10px;font-size:10px;flex-shrink:0">📋</button>
          </div>
        </td>
      </tr>
      <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Paginação -->
    <?php
    $_pBase = '?' . http_build_query(array_diff_key($_GET, ['page'=>'']));
    echo renderPagination($page, $pages, rtrim($_pBase,'?&'));
    ?>

    <?php else: ?>
    <div style="padding:48px;text-align:center;color:var(--muted)">
      <div style="font-size:36px;margin-bottom:12px">💳</div>
      <p style="font-size:14px;font-weight:600">Nenhuma transação encontrada</p>
      <?php if ($fStatus||$fPlan||$fUser||$fDateFrom||$fDateTo): ?>
      <a href="<?= SITE_URL ?>/admin/financeiro.php" class="btn btn-secondary" style="margin-top:14px">Limpar filtros</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /col esquerda -->

<!-- ── COLUNA DIREITA: PIX por usuário ── -->
<div>
  <div style="font-family:'Roboto',sans-serif;font-size:14px;font-weight:700;margin-bottom:12px">
    👥 PIX por Usuário
    <span style="font-size:11px;color:var(--muted);font-weight:400;margin-left:6px">clique para filtrar</span>
  </div>

  <?php if ($pixByUser): ?>
  <div style="display:flex;flex-direction:column;gap:8px">
    <?php foreach ($pixByUser as $pu): ?>
    <div class="uc <?= $fUser==$pu['id']?'active-filter':'' ?>" onclick="filterUser(<?= $pu['id'] ?>)">
      <div style="width:34px;height:34px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff;flex-shrink:0">
        <?= strtoupper(mb_substr($pu['name'],0,1)) ?>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($pu['name']) ?></div>
        <?php if (!empty($pu['phone'])): ?>
        <div class="txt-muted-xs">📱 <?= htmlspecialchars($pu['phone']) ?></div>
        <?php endif; ?>
        <div style="display:flex;gap:5px;margin-top:3px;flex-wrap:wrap">
          <span style="font-size:10px;background:rgba(16,185,129,.12);color:var(--success);padding:1px 7px;border-radius:10px">✅ <?= $pu['paid_count'] ?></span>
          <?php if ($pu['pending_count'] > 0): ?>
          <span style="font-size:10px;background:rgba(245,158,11,.12);color:var(--warning);padding:1px 7px;border-radius:10px">⏳ <?= $pu['pending_count'] ?> · R$ <?= number_format((float)$pu['pending_value'],2,',','.') ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div style="text-align:right;flex-shrink:0">
        <div style="font-family:'Roboto',sans-serif;font-size:13px;font-weight:800;color:var(--success)">
          R$ <?= number_format((float)$pu['total_paid'],2,',','.') ?>
        </div>
        <div style="font-size:10px;color:var(--muted)"><?= $pu['total_tx'] ?> PIX</div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div style="text-align:center;padding:32px 16px;color:var(--muted);background:var(--surface);border-radius:13px;border:1px solid var(--border)">
    <div style="font-size:28px;margin-bottom:8px">📭</div>
    <p style="font-size:13px">Nenhum usuário com transações.</p>
  </div>
  <?php endif; ?>
</div>

</div><!-- /grid -->

<!-- Modal de Remarketing -->
<div id="rm-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:200;align-items:center;justify-content:center;padding:20px">
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:28px;width:100%;max-width:480px">
    <div style="font-family:'Roboto',sans-serif;font-size:17px;font-weight:700;margin-bottom:4px">📣 Remarketing</div>
    <div style="font-size:13px;color:var(--muted);margin-bottom:20px" id="rm-sub">Mensagem para o cliente</div>

    <!-- Abas de mensagem -->
    <div style="display:flex;gap:6px;margin-bottom:14px">
      <button onclick="rmTab('pix')"    id="tab-pix"    class="btn btn-primary"    style="font-size:12px;padding:6px 12px">💬 WhatsApp + PIX</button>
      <button onclick="rmTab('lembr')"  id="tab-lembr"  class="btn btn-secondary" style="font-size:12px;padding:6px 12px">🔔 Lembrete</button>
      <button onclick="rmTab('urgente')" id="tab-urgente" class="btn btn-secondary" style="font-size:12px;padding:6px 12px">🔥 Urgente</button>
    </div>

    <!-- Mensagem editável -->
    <textarea id="rm-msg" rows="8" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:12px;color:var(--text);font-size:13px;font-family:monospace;line-height:1.6;resize:vertical;outline:none"></textarea>

    <div style="display:flex;gap:10px;margin-top:16px">
      <button onclick="copyRm()" class="btn btn-primary" style="flex:1;justify-content:center">
        📋 Copiar mensagem
      </button>
      <a id="rm-wa-link" href="#" target="_blank" class="btn btn-secondary" style="flex:1;justify-content:center;text-decoration:none;background:rgba(37,211,102,.12);border-color:rgba(37,211,102,.35);color:#25d366">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
        Abrir WhatsApp
      </a>
      <button onclick="closeRm()" class="btn btn-secondary" style="padding:8px 16px">✕</button>
    </div>
  </div>
</div>

<script>
function togglePix(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.toggle('open');
}
function cpPix(code, btn) {
  navigator.clipboard.writeText(code).then(() => {
    btn.textContent = '✅'; setTimeout(() => btn.textContent = '📋', 2000);
  });
}
function filterUser(uid) {
  const params = new URLSearchParams(window.location.search);
  if (params.get('user') == uid) {
    params.delete('user');
  } else {
    params.set('user', uid);
    params.set('page', 1);
  }
  window.location.href = '<?= SITE_URL ?>/admin/financeiro.php?' + params.toString();
}

// ── Remarketing ─────────────────────────────
let rmData = {};
const siteName = '<?= addslashes(getSetting('site_name', SITE_NAME)) ?>';
const siteUrl  = '<?= SITE_URL ?>';

// Templates carregados das configurações
const tplPix     = <?= json_encode(getSetting('rm_msg_pix',     "Olá, {nome}! 👋

Vi que você tentou assinar o *{plano}* no *{site}* por *{valor}*, mas o pagamento ainda não foi confirmado.

Se quiser garantir seu acesso, aqui está o código PIX:

{pix_code}

Ou acesse diretamente: {url}

Qualquer dúvida estou aqui! 😊")) ?>;
const tplLembr   = <?= json_encode(getSetting('rm_msg_lembrete', "Olá, {nome}! 🔔

Passando para lembrar que seu acesso ao *{site}* ainda está disponível.

Plano: *{plano}* — {valor}

Para concluir, acesse: {url}

Te esperamos por lá! 🚀")) ?>;
const tplUrgente = <?= json_encode(getSetting('rm_msg_urgente',  "⚠️ {nome}, seu PIX expirou!

Seu acesso ao *{site}* ({plano} — {valor}) não foi confirmado.

Gere um novo PIX aqui: {url}

🔥 Garanta seu acesso antes que acabe!")) ?>;

function fillTpl(tpl, d) {
  return tpl
    .replace(/{nome}/g,     d.name)
    .replace(/{plano}/g,    d.plan)
    .replace(/{valor}/g,    d.amount)
    .replace(/{site}/g,     siteName)
    .replace(/{url}/g,      d.url)
    .replace(/{pix_code}/g, d.pix_code || '');
}

const templates = {
  pix:     (d) => fillTpl(tplPix,     d),
  lembr:   (d) => fillTpl(tplLembr,   d),
  urgente: (d) => fillTpl(tplUrgente, d),
};

let currentTab = 'pix';

function openRemarketing(data) {
  rmData = data;
  document.getElementById('rm-sub').textContent = data.phone ? `📱 ${data.phone} — ${data.name}` : data.name;
  rmTab('pix');
  const modal = document.getElementById('rm-modal');
  modal.style.display = 'flex';
  // WhatsApp link
  const waLink = document.getElementById('rm-wa-link');
  if (waLink) {
    if (data.phone) {
      const phone = data.phone.replace(/\D/g,'');
      const intlPhone = phone.startsWith('55') ? phone : '55'+phone;
      const msg = encodeURIComponent(templates[currentTab](rmData));
      waLink.href = `https://wa.me/${intlPhone}?text=${msg}`;
      waLink.style.opacity = '1';
      waLink.style.pointerEvents = '';
    } else {
      waLink.href = '#';
      waLink.style.opacity = '0.4';
      waLink.style.pointerEvents = 'none';
      waLink.title = 'Usuário sem telefone cadastrado';
    }
  }
}

function rmTab(tab) {
  currentTab = tab;
  ['pix','lembr','urgente'].forEach(t => {
    const btn = document.getElementById('tab-'+t);
    if (btn) btn.className = t === tab ? 'btn btn-primary' : 'btn btn-secondary';
    if (btn) btn.style.fontSize = '12px';
    if (btn) btn.style.padding = '6px 12px';
  });
  const msg = templates[tab](rmData);
  document.getElementById('rm-msg').value = msg;
  // Atualiza link WhatsApp com a nova mensagem
  const waLink = document.getElementById('rm-wa-link');
  if (waLink && rmData.phone) {
    const phone = rmData.phone.replace(/\D/g,'');
    const intlPhone = phone.startsWith('55') ? phone : '55'+phone;
    waLink.href = `https://wa.me/${intlPhone}?text=${encodeURIComponent(msg)}`;
  }
}

function copyRm() {
  const msg = document.getElementById('rm-msg').value;
  navigator.clipboard.writeText(msg).then(() => {
    const btn = event.target.closest('button');
    const orig = btn.innerHTML;
    btn.innerHTML = '✅ Copiado!';
    setTimeout(() => btn.innerHTML = orig, 2000);
  });
}

function closeRm() {
  document.getElementById('rm-modal').style.display = 'none';
}
document.getElementById('rm-modal').addEventListener('click', e => {
  if (e.target === e.currentTarget) closeRm();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeRm(); });
</script>


<script>
(function(){
  if (!window.EventSource) return;
  var src = new EventSource(<?= json_encode(SITE_URL . '/admin/sse.php') ?>);

  function fmt(v){ return 'R$ ' + parseFloat(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); }

  src.addEventListener('tick', function(e){
    var d = JSON.parse(e.data);
    var kpi = d.kpis;
    var el;
    if ((el=document.getElementById('sse-total-paid'))) el.textContent = fmt(kpi.receita_total);
    if ((el=document.getElementById('sse-today')))      el.textContent = fmt(kpi.receita_hoje);
    if ((el=document.getElementById('sse-pending')))    el.textContent = kpi.pendentes;
  });

  src.addEventListener('new_payment', function(e){
    var d = JSON.parse(e.data);
    var kpi = d.kpis;
    var el;
    if ((el=document.getElementById('sse-total-paid'))) el.textContent = fmt(kpi.receita_total);
    if ((el=document.getElementById('sse-today')))      el.textContent = fmt(kpi.receita_hoje);
    if ((el=document.getElementById('sse-pending')))    el.textContent = kpi.pendentes;
    // Notificação visual
    var toast = document.createElement('div');
    toast.textContent = '💰 Novo pagamento recebido!';
    toast.style.cssText = 'position:fixed;bottom:28px;right:24px;background:rgba(16,185,129,.95);color:#fff;padding:12px 20px;border-radius:30px;font-size:14px;font-weight:600;z-index:9999;animation:fadeIn .3s';
    document.body.appendChild(toast);
    setTimeout(function(){ toast.remove(); }, 4000);
  });

  src.onerror = function(){ src.close(); };
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
