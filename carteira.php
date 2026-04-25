<?php
ob_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/pix.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Aceita logado normal, expirado ou pendente — igual ao renovar.php
$userId = $_SESSION['user_id'] ?? $_SESSION['expired_user_id'] ?? $_SESSION['pending_user_id'] ?? null;
if (!$userId) { ob_end_clean(); header('Location: ' . SITE_URL . '/login'); exit; }

$db   = getDB();
$user = $db->prepare('SELECT u.*, p.name AS plan_name FROM users u LEFT JOIN plans p ON u.plan_id=p.id WHERE u.id=?');
$user->execute([$userId]); $user = $user->fetch();
if (!$user) { ob_end_clean(); header('Location: ' . SITE_URL . '/login'); exit; }

// ── AJAX: verificar pagamento — idêntico ao renovar.php ──
if (isset($_GET['check_tx'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    $txId = (int)$_GET['check_tx'];
    $chk  = $db->prepare('SELECT user_id FROM transactions WHERE id=?');
    $chk->execute([$txId]); $chk = $chk->fetch();
    if (!$chk || (int)$chk['user_id'] !== (int)$userId) {
        echo json_encode(['status'=>'error']); exit;
    }
    $status = checkPixStatus($txId);
    if ($status['status'] === 'paid') {
        $u = $db->prepare('SELECT id,name,role FROM users WHERE id=?');
        $u->execute([$userId]); $u = $u->fetch();
        if ($u) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = $u['id'];
            $_SESSION['user_name'] = $u['name'];
            $_SESSION['user_role'] = $u['role'];
            unset($_SESSION['expired_user_id'], $_SESSION['pending_user_id']);
        }
        $status['redirect'] = SITE_URL . '/index';
    }
    echo json_encode($status); exit;
}

ob_end_clean();

// Carrega auth.php apenas para renderização da página (header, nav, etc.)
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Minha Carteira';
$userId    = (int)$user['id'];

// Limpar transações (apenas não-pending)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_tx']) && csrf_verify($_POST['csrf_token'] ?? '')) {
    $db->prepare('DELETE FROM transactions WHERE user_id=? AND (status IN ("failed","refunded") OR (status="pending" AND pix_expires_at IS NOT NULL AND pix_expires_at < ?))')
       ->execute([$userId, date('Y-m-d H:i:s')]);
    header('Location: ' . SITE_URL . '/carteira'); exit;
}

// Busca PIX pendente mais recente em UMA query
$nowTs = date('Y-m-d H:i:s');
$pendingPix = null;
$latestStmt = $db->prepare('
    SELECT t.*, p.name AS plan_name, p.color AS plan_color
    FROM transactions t LEFT JOIN plans p ON t.plan_id=p.id
    WHERE t.user_id=?
    ORDER BY t.created_at DESC LIMIT 1
');
$latestStmt->execute([$user['id']]);
$latestTx = $latestStmt->fetch();
if ($latestTx && $latestTx['status'] === 'pending'
    && ($latestTx['pix_expires_at'] === null || $latestTx['pix_expires_at'] > $nowTs)) {
    $pendingPix = $latestTx;
}

// Paginação
$page    = max(1,(int)($_GET['page']??1));
$perPage = 10;
$offset  = ($page-1)*$perPage;

$total = $db->prepare('SELECT COUNT(*) FROM transactions WHERE user_id=?');
$total->execute([$user['id']]); $total = (int)$total->fetchColumn();
$pages = (int)ceil($total/$perPage);

$txStmt = $db->prepare('
    SELECT t.*, p.name AS plan_name, p.color AS plan_color, p.duration_days
    FROM transactions t
    LEFT JOIN plans p ON t.plan_id = p.id
    WHERE t.user_id = ?
    ORDER BY t.created_at DESC
    LIMIT '.$perPage.' OFFSET '.$offset
);
$txStmt->execute([$user['id']]);
$transactions = $txStmt->fetchAll();

// Totais
// Uma query em vez de 3 — busca todos os totais de uma vez
$stats = $db->prepare('
    SELECT
        COALESCE(SUM(CASE WHEN status="paid" THEN amount ELSE 0 END), 0) AS total_paid,
        SUM(CASE WHEN status="paid" THEN 1 ELSE 0 END)    AS count_paid,
        SUM(CASE WHEN status="pending" THEN 1 ELSE 0 END) AS count_pending
    FROM transactions WHERE user_id=?
');
$stats->execute([$user['id']]);
$stats    = $stats->fetch();
$totPaid    = (float)$stats['total_paid'];
$totCount   = (int)$stats['count_paid'];
$totPending = (int)$stats['count_pending'];

require __DIR__ . '/includes/header.php';
?>
<style>
.tx-row{display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid var(--border);transition:background .15s}
.tx-row:last-child{border:none}
.tx-row:hover{background:var(--surface2)}
.tx-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:18px}
.tx-info{flex:1;min-width:0}
.tx-title{font-size:14px;font-weight:600}
.tx-sub{font-size:12px;color:var(--muted);margin-top:2px}
.tx-amount{font-family:'Roboto',sans-serif;font-size:16px;font-weight:800;flex-shrink:0;text-align:right}
.tx-status{font-size:11px;font-weight:700;margin-top:3px;text-align:right}
.s-paid   {color:var(--success)}
.s-pending{color:var(--warning)}
.s-failed {color:var(--danger)}
.s-refunded{color:var(--muted)}
.pix-expand{background:var(--surface2);border-radius:8px;padding:12px 14px;margin:0 18px 12px;display:none}
.pix-expand.open{display:block}
.pix-code-mini{font-family:monospace;font-size:11px;word-break:break-all;color:var(--muted2);line-height:1.6}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
@keyframes pop{0%{transform:scale(0);opacity:0}70%{transform:scale(1.2)}100%{transform:scale(1);opacity:1}}
</style>

<!-- Banner PIX pendente -->
<?php if ($pendingPix): ?>
<?php $pixCode = $pendingPix['pix_code']; $pixTxId = (int)$pendingPix['id']; ?>
<div id="pix-banner" style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.3);border-radius:14px;padding:20px;margin-bottom:24px">

  <div id="state-wait">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
      <div style="font-size:20px">⏳</div>
      <div>
        <div style="font-family:'Roboto',sans-serif;font-size:15px;font-weight:700;color:var(--warning)">PIX aguardando pagamento</div>
        <div style="font-size:13px;color:var(--muted2);margin-top:2px">
          <strong>R$ <?= number_format((float)$pendingPix['amount'],2,',','.') ?></strong>
          <?php if ($pendingPix['plan_name']): ?> — <?= htmlspecialchars($pendingPix['plan_name']) ?><?php endif; ?>
          <?php if ($pendingPix['pix_expires_at']): ?>
           · <span id="pix-countdown" style="font-size:12px;color:var(--muted2)">⏱ Expira <?= date('H:i \d\e d/m', strtotime($pendingPix['pix_expires_at'])) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div style="font-size:11px;font-weight:600;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;text-align:center">Código PIX copia e cola</div>
    <input id="pix-input" type="text" readonly value="<?= htmlspecialchars($pixCode) ?>"
           style="width:100%;background:var(--surface);border:1px solid var(--border2);border-radius:8px;padding:10px 12px;font-family:monospace;font-size:11px;color:var(--muted2);margin-bottom:8px;cursor:pointer;box-sizing:border-box">
    <button id="btn-copy-banner" style="width:100%;padding:12px;background:var(--warning);color:#000;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;margin-bottom:14px">
      📋 Copiar código PIX
    </button>

    <div style="display:flex;justify-content:center;margin-bottom:14px">
      <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode($pixCode) ?>"
           style="width:160px;height:160px;border-radius:10px;background:#fff;padding:6px;display:block">
    </div>

    <div style="display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap">
      <div id="banner-status" style="font-size:13px;color:var(--warning);font-weight:600;display:flex;align-items:center;gap:6px">
        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--warning);animation:pulse 1.5s infinite"></span>
        Aguardando pagamento...
      </div>
      <button id="btn-check-manual" style="padding:8px 16px;background:var(--surface2);border:1px solid var(--border2);border-radius:8px;font-size:13px;cursor:pointer;color:var(--text);font-weight:600">
        🔄 Verificar pagamento
      </button>
    </div>
  </div>

  <div id="state-paid" style="display:none;text-align:center;padding:8px 0">
    <div style="font-size:56px;margin-bottom:8px;animation:pop .4s ease">🎉</div>
    <div style="font-family:'Roboto',sans-serif;font-size:20px;font-weight:800;color:var(--success);margin-bottom:6px">Pagamento aprovado!</div>
    <div style="font-size:14px;color:var(--muted2);margin-bottom:20px">Seu acesso foi liberado com sucesso.</div>
    <a href="<?= SITE_URL ?>/index.php" style="display:inline-flex;align-items:center;justify-content:center;gap:8px;background:var(--success);color:#fff;padding:14px 28px;border-radius:12px;font-size:15px;font-weight:700;text-decoration:none;width:100%;box-sizing:border-box">
      Acessar conteúdo →
    </a>
  </div>

</div>

<div id="toast-pix" style="position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(20px);background:rgba(16,185,129,.95);color:#fff;padding:10px 22px;border-radius:30px;font-size:14px;font-weight:600;opacity:0;transition:all .3s;pointer-events:none;z-index:9999;white-space:nowrap">
  ✅ PIX copiado!
</div>

<?php endif; ?>

<!-- Resumo -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-label">Total pago</div>
    <div class="stat-value" style="color:var(--success);font-size:22px">R$ <?= number_format($totPaid,2,',','.') ?></div>
    <div class="stat-sub"><?= $totCount ?> pagamento<?= $totCount!=1?'s':'' ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">PIX aguardando</div>
    <div class="stat-value" style="color:var(--warning);font-size:22px"><?= $totPending ?></div>
    <div class="stat-sub">pendente<?= $totPending!=1?'s':'' ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Plano atual</div>
    <div class="stat-value" style="font-size:16px;color:<?= htmlspecialchars($user['plan_color'] ?? 'var(--accent)') ?>">
      <?= htmlspecialchars($user['plan_name'] ?? 'Sem plano') ?>
    </div>
    <?php if (!empty($user['expires_at'])): ?>
    <?php $exp = strtotime($user['expires_at']); $days = ceil(($exp-time())/86400); ?>
    <div class="stat-sub" style="color:<?= $days<=3?'var(--danger)':($days<=7?'var(--warning)':'var(--muted)') ?>">
      <?= $exp > time() ? "Expira em {$days}d — ".date('d/m/Y',$exp) : 'Expirado' ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Botão renovar -->
<?php
$hasPlans = false;
try { $hasPlans = (bool)$db->query('SELECT COUNT(*) FROM plans WHERE active=1 AND price > 0')->fetchColumn(); } catch(Exception $e) {}
?>
<?php
$hasActivePlan = !empty($user['expires_at']) && strtotime($user['expires_at']) > time();
?>
<?php if ($hasPlans && !$hasActivePlan): ?>
<div style="margin-bottom:20px">
  <a href="<?= SITE_URL ?>/renovar" class="btn btn-primary" style="display:inline-flex;gap:8px">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
    Comprar plano
  </a>
</div>
<?php endif; ?>

<!-- Histórico -->
<div class="section-header" style="margin-bottom:14px">
  <div>
    <div class="section-title">📋 Histórico de Pagamentos</div>
    <div class="section-sub"><?= number_format($total) ?> transaç<?= $total!=1?'ões':'ão' ?></div>
  </div>
  <?php
  // Mostra botão apenas se houver transações limpáveis (expiradas ou falhas)
  $hasCleanable = $db->prepare('SELECT COUNT(*) FROM transactions WHERE user_id=? AND (status IN ("failed","refunded") OR (status="pending" AND pix_expires_at IS NOT NULL AND pix_expires_at < ?))');
  $hasCleanable->execute([$userId, $nowTs]);
  if ((int)$hasCleanable->fetchColumn() > 0):
  ?>
  <form method="POST" onsubmit="return confirm('Remover transações expiradas e com falha? Pagamentos confirmados não serão afetados.')">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <button type="submit" name="clear_tx" value="1" class="btn btn-secondary" style="font-size:12px;padding:6px 12px;color:var(--danger);border-color:rgba(255,77,106,.3)">
      🗑️ Limpar expirados
    </button>
  </form>
  <?php endif; ?>
</div>

<div class="card" style="overflow:hidden">
  <?php if ($transactions): ?>
  <?php foreach ($transactions as $tx):
    $isPaid    = $tx['status'] === 'paid';
    $isPending = $tx['status'] === 'pending';
    $isExpPix  = $isPending && !empty($tx['pix_expires_at']) && ($pixTs = strtotime($tx['pix_expires_at'])) !== false && $pixTs < time();
    $icon      = $isPaid ? '✅' : ($isExpPix ? '⛔' : ($isPending ? '⏳' : '❌'));
    $statusLbl = $isPaid ? 'Pago' : ($isExpPix ? 'PIX expirado' : ($isPending ? 'Aguardando' : ucfirst($tx['status'])));
    $amtColor  = $isPaid ? 'var(--success)' : ($isPending && !$isExpPix ? 'var(--warning)' : 'var(--muted)');
  ?>
  <div class="tx-row" onclick="togglePix(<?= $tx['id'] ?>)" style="cursor:pointer">
    <div class="tx-icon" style="background:<?= $tx['plan_color'] ? htmlspecialchars($tx['plan_color']).'22' : 'var(--surface2)' ?>">
      <?= $icon ?>
    </div>
    <div class="tx-info">
      <div class="tx-title"><?= htmlspecialchars($tx['plan_name'] ?? 'Plano') ?></div>
      <div class="tx-sub">
        <?= date('d/m/Y H:i', strtotime($tx['created_at'])) ?>
        · PIX
        <?php if ($isPaid && $tx['paid_at']): ?> · Pago em <?= date('d/m/Y H:i',strtotime($tx['paid_at'])) ?><?php endif; ?>
        <?php if ($isPending && !$isExpPix && !empty($tx['pix_expires_at'])): ?>
        · Expira <?= date('H:i', strtotime($tx['pix_expires_at'])) ?>
        <?php endif; ?>
      </div>
    </div>
    <div>
      <div class="tx-amount" style="color:<?= $amtColor ?>">
        R$ <?= number_format((float)$tx['amount'],2,',','.') ?>
      </div>
      <div class="tx-status s-<?= $tx['status'] ?>"><?= $statusLbl ?></div>
    </div>
  </div>
  <!-- PIX expansível -->
  <?php if (!empty($tx['pix_code'])): ?>
  <div class="pix-expand" id="pix-<?= $tx['id'] ?>">
    <div style="font-size:11px;font-weight:600;color:var(--muted);margin-bottom:6px">Código PIX copia e cola:</div>
    <div style="display:flex;align-items:flex-start;gap:8px">
      <div class="pix-code-mini"><?= htmlspecialchars($tx['pix_code']) ?></div>
      <?php if ($isPending && !$isExpPix): ?>
      <button onclick="event.stopPropagation();copyCode(this,'<?= htmlspecialchars(addslashes($tx['pix_code'])) ?>')"
              style="flex-shrink:0;padding:6px 10px;background:var(--accent);color:#fff;border:none;border-radius:7px;font-size:11px;cursor:pointer">📋 Copiar</button>
      <?php endif; ?>
    </div>
    <?php if ($isPending && !$isExpPix): ?>
    <div style="margin-top:10px">
      <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=<?= urlencode($tx['pix_code']) ?>"
           style="width:120px;height:120px;border-radius:8px;background:#fff;display:block">
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endforeach; ?>

  <?php echo renderPagination($page, $pages, '?'); ?>

  <?php else: ?>
  <div style="padding:48px;text-align:center;color:var(--muted)">
    <div style="font-size:40px;margin-bottom:12px">💳</div>
    <p style="font-size:15px;font-weight:600;margin-bottom:6px">Nenhuma transação ainda</p>
    <?php if ($hasPlans): ?>
    <a href="<?= SITE_URL ?>/renovar" class="btn btn-primary" style="margin-top:12px;display:inline-flex">Comprar um plano</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

function togglePix(id) {
  var el = document.getElementById('pix-' + id);
  if (el) el.classList.toggle('open');
}

function copyCode(btn, code) {
  var ta = document.createElement('textarea');
  ta.value = code; ta.style.cssText = 'position:fixed;top:0;left:0;opacity:.01';
  document.body.appendChild(ta); ta.focus(); ta.select();
  try { document.execCommand('copy'); btn.textContent = '✅ Copiado!'; setTimeout(function(){ btn.textContent = '📋 Copiar'; }, 2000); }
  catch(e) {}
  document.body.removeChild(ta);
}

// Expõe togglePix e copyCode globalmente (usados em onclick no histórico)
window.togglePix = togglePix;
window.copyCode  = copyCode;

<?php if ($pendingPix): ?>
var PIX_TX_ID   = <?= json_encode($pixTxId) ?>;
var PIX_CODE    = <?= json_encode($pixCode) ?>;
var RENOVAR_URL = <?= json_encode(SITE_URL . '/renovar') ?>;
var POLL_URL    = <?= json_encode(SITE_URL . '/carteira?check_tx=' . $pixTxId) ?>;
var TRACK_VALUE = <?= json_encode((float)($pendingPix['amount'] ?? 0)) ?>;
var TRACK_PLAN  = <?= json_encode($pendingPix['plan_name'] ?? '') ?>;

var pixDone           = false;
var pollInterval      = null;
var countdownInterval = null;

var btnCopy   = document.getElementById('btn-copy-banner');
var btnCheck  = document.getElementById('btn-check-manual');
var pixInput  = document.getElementById('pix-input');

if (pixInput) pixInput.addEventListener('click', function(){ this.select(); });

if (btnCopy) btnCopy.addEventListener('click', function() {
  var btn = this;
  function ok() {
    btn.textContent = '✅ Copiado!';
    var t = document.getElementById('toast-pix');
    if (t) { t.style.opacity='1'; t.style.transform='translateX(-50%) translateY(0)'; clearTimeout(t._t); t._t=setTimeout(function(){ t.style.opacity='0'; t.style.transform='translateX(-50%) translateY(20px)'; },2500); }
    setTimeout(function(){ btn.textContent='📋 Copiar código PIX'; }, 2000);
  }
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(PIX_CODE).then(ok).catch(function(){
      pixInput.select(); pixInput.setSelectionRange(0,99999);
      try { document.execCommand('copy'); ok(); } catch(e) {}
    });
  } else {
    pixInput.select(); pixInput.setSelectionRange(0,99999);
    try { document.execCommand('copy'); ok(); } catch(e) { btn.textContent = '❌ Erro'; }
  }
});

if (btnCheck) btnCheck.addEventListener('click', function() {
  var btn = this;
  if (btn.disabled) return;
  btn.disabled = true; btn.textContent = '⏳ Verificando...';
  fetch(POLL_URL, {cache:'no-store'})
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.status === 'paid') {
        showPaid();
      } else if (d.status === 'expired') {
        var st = document.getElementById('banner-status');
        if (st) st.innerHTML = '<span style="color:var(--danger)">⛔ PIX expirado. <a href="'+RENOVAR_URL+'" style="color:var(--danger);text-decoration:underline">Gerar novo</a></span>';
        btn.style.display = 'none';
      } else {
        btn.disabled = false; btn.textContent = '🔄 Verificar pagamento';
      }
    })
    .catch(function(){ btn.disabled=false; btn.textContent='🔄 Verificar pagamento'; });
});

function showPaid() {
  pixDone = true;
  if (pollInterval)      clearInterval(pollInterval);
  if (countdownInterval) clearInterval(countdownInterval);
  var w = document.getElementById('state-wait');
  var p = document.getElementById('state-paid');
  if (w) w.style.display = 'none';
  if (p) p.style.display = 'block';
}

function hideBanner() {
  if (pollInterval) clearInterval(pollInterval);
  var b = document.getElementById('pix-banner');
  if (!b) return;
  b.style.transition = 'opacity .5s'; b.style.opacity = '0';
  setTimeout(function(){ b.style.display='none'; }, 500);
}

function pollPix() {
  if (pixDone) return;
  fetch(POLL_URL, {cache:'no-store'})
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.status === 'paid') showPaid();
      else if (d.status === 'expired') hideBanner();
    })
    .catch(function(){});
}

pollInterval = setInterval(pollPix, 3000);
pollPix();

document.addEventListener('visibilitychange', function(){
  if (!document.hidden && !pixDone) pollPix();
});

<?php if (!empty($pendingPix['pix_expires_at'])): ?>
var PIX_EXP = <?= json_encode(strtotime($pendingPix['pix_expires_at']) * 1000) ?>;
countdownInterval = setInterval(function(){
  var el = document.getElementById('pix-countdown');
  if (!el) return;
  var diff = Math.floor((PIX_EXP - Date.now()) / 1000);
  if (diff <= 0) {
    el.textContent = '⛔ Expirado'; el.style.color = 'var(--danger)';
    clearInterval(countdownInterval); setTimeout(hideBanner, 2000); return;
  }
  var m = Math.floor(diff/60), s = diff%60;
  el.textContent = '⏱ Expira em ' + m + ':' + (s<10?'0':'') + s;
  el.style.color = diff<120 ? 'var(--danger)' : diff<300 ? 'var(--warning)' : 'var(--muted2)';
}, 1000);
<?php endif; ?>

<?php endif; ?>

}); // DOMContentLoaded
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
