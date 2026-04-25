<?php
// auth.php precisa ser o PRIMEIRO require — inicia a sessão com o handler
// correto (Redis se redis_enabled=1). Sem isso a sessão fica em arquivo e
// não bate com o resto do site, causando "logged-in vira deslogado" entre páginas.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pix.php';
require_once __DIR__ . '/includes/affiliate.php';

$siteName = getSetting('site_name', SITE_NAME);

// Aceita: logado normal, expirado, ou pendente de pagamento
$userId   = $_SESSION['user_id'] ?? $_SESSION['expired_user_id'] ?? $_SESSION['pending_user_id'] ?? null;
$isPending = !empty($_SESSION['pending_user_id']) && empty($_SESSION['user_id']);

if (!$userId) { header('Location: ' . SITE_URL . '/login'); exit; }

$db   = getDB();
$user = $db->prepare('SELECT u.*, p.name AS plan_name FROM users u LEFT JOIN plans p ON u.plan_id=p.id WHERE u.id=?');
$user->execute([$userId]); $user = $user->fetch();
if (!$user) { header('Location: ' . SITE_URL . '/login'); exit; }

$plans = $db->query('SELECT * FROM plans WHERE active=1 AND price > 0 ORDER BY price ASC')->fetchAll();

// Plano pré-selecionado via URL (?plan=ID)
$preselect = (int)($_GET['plan'] ?? 0);

// ── AJAX: verificar pagamento ─────────────────
if (isset($_GET['check_tx'])) {
    header('Content-Type: application/json');
    $txId = (int)$_GET['check_tx'];
    $chk  = $db->prepare('SELECT user_id FROM transactions WHERE id=?');
    $chk->execute([$txId]); $chk = $chk->fetch();
    if (!$chk || (int)$chk['user_id'] !== (int)$userId) { echo json_encode(['status'=>'error']); exit; }
    $status = checkPixStatus($txId);
    if ($status['status'] === 'paid') {
        // Faz login completo (serve para pending, expired e logado)
        $u = getDB()->prepare('SELECT * FROM users WHERE id=?');
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

// ── Gerar cobrança PIX ────────────────────────
$error      = '';
$pixData    = null;
$selPlan    = null;
$couponInfo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf_renovar'] ?? '')) {
        $error = 'Token inválido.';
    } else {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $plan   = $db->prepare('SELECT * FROM plans WHERE id=? AND active=1 AND price > 0');
        $plan->execute([$planId]); $plan = $plan->fetch();
        if (!$plan) {
            $error = 'Plano inválido.';
        } else {
            $finalPrice   = (float)$plan['price'];
            $couponCode   = strtoupper(trim($_POST['coupon'] ?? ''));
            $useCredit    = isset($_POST['use_credit']) && $_POST['use_credit'] === '1';
            $creditUsed   = 0;

            // Valida e reserva o cupom dentro de uma transação (previne burlar max_uses em requests simultâneos)
            $couponReservedId = 0;
            if ($couponCode) {
                $now = date('Y-m-d H:i:s');
                try {
                    $db->beginTransaction();
                    $cp = $db->prepare('SELECT * FROM coupons WHERE code=? AND active=1 AND (valid_until IS NULL OR valid_until > ?) AND (max_uses IS NULL OR used_count < max_uses) FOR UPDATE');
                    $cp->execute([$couponCode, $now]);
                    $cp = $cp->fetch();
                    if (!$cp) {
                        $error = 'Cupom inválido, expirado ou esgotado.';
                        $db->rollBack();
                    } else {
                        // Incrementa imediatamente — se PIX/ativação falhar, fazemos rollback pontual decrementando.
                        $db->prepare('UPDATE coupons SET used_count=used_count+1 WHERE id=?')->execute([$cp['id']]);
                        $db->commit();
                        $couponReservedId = (int)$cp['id'];
                        $discount = $cp['type'] === 'percent'
                            ? round($finalPrice * $cp['value'] / 100, 2)
                            : min((float)$cp['value'], $finalPrice);
                        $finalPrice = max(0.01, $finalPrice - $discount);
                        $couponInfo = ['code'=>$cp['code'],'discount'=>$discount,'type'=>$cp['type'],'value'=>$cp['value'],'id'=>$cp['id']];
                    }
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = 'Erro ao validar cupom.';
                }
            }

            // Aplica crédito de afiliado se solicitado (com lock para evitar double-spend)
            $affId = affiliateIdByUser((int)$userId);
            $affBalance = $affId ? affiliateBalance($affId) : 0;
            if ($useCredit && $affId && $affBalance > 0 && !$error) {
                // Lock no registro do afiliado para serializar requests simultâneos
                $db->beginTransaction();
                $db->prepare('SELECT id FROM affiliates WHERE id=? FOR UPDATE')->execute([$affId]);
                $affBalance  = affiliateBalance($affId); // re-lê dentro da transação
                $creditToUse = min($affBalance, $finalPrice);
                $finalPrice  = max(0, round($finalPrice - $creditToUse, 2));
                $creditUsed  = $creditToUse;
                // Debita imediatamente para bloquear o saldo
                if ($creditUsed > 0) {
                    $db->exec("CREATE TABLE IF NOT EXISTS affiliate_credits_used (
                        id INT AUTO_INCREMENT PRIMARY KEY, affiliate_id INT NOT NULL,
                        amount DECIMAL(10,2) NOT NULL, transaction_id INT DEFAULT NULL,
                        note VARCHAR(200) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )");
                    $db->prepare('INSERT INTO affiliate_credits_used (affiliate_id, amount, note) VALUES (?,?,?)')
                       ->execute([$affId, $creditUsed, '_reservado_']);
                    $creditReservedId = (int)$db->lastInsertId();
                }
                $db->commit();
            }

            if (!$error) {
                // Saldo cobriu 100% — ativa plano direto sem PIX
                if ($finalPrice <= 0 && $creditUsed > 0) {
                    // Registra transação com amount=0 (pago com crédito)
                    $txId = saveTransaction(
                        (int)$userId, $plan['id'],
                        'credit_' . time(), 'credit',
                        0.00,
                        '', date('Y-m-d H:i:s', time() + 3600),
                        json_encode(['method'=>'affiliate_credit','credit_used'=>$creditUsed])
                    );
                    activatePlan((int)$userId, $plan['id'], $txId, $creditUsed);
                    // Atualiza nota e tx_id do crédito reservado
                    if (!empty($creditReservedId)) {
                        $db->prepare("UPDATE affiliate_credits_used SET transaction_id=?, note=? WHERE id=?")
                           ->execute([$txId, 'Pagamento completo com créditos — plano '.$plan['name'], $creditReservedId]);
                    }
                    affiliateOnPurchase((int)$userId, $txId, $creditUsed);
                    // Cupom já foi incrementado acima dentro da transação — nada a fazer aqui.
                    // Faz login completo se era pendente
                    if (!empty($_SESSION['pending_user_id'])) {
                        $u = $db->prepare('SELECT id,name,role FROM users WHERE id=?');
                        $u->execute([$userId]); $u = $u->fetch();
                        if ($u) {
                            session_regenerate_id(true);
                            $_SESSION['user_id']   = $u['id'];
                            $_SESSION['user_name'] = $u['name'];
                            $_SESSION['user_role'] = $u['role'];
                            unset($_SESSION['expired_user_id'], $_SESSION['pending_user_id']);
                        }
                    }
                    header('Location: ' . SITE_URL . '/index.php?activated=1'); exit;
                }

                $result = createPixCharge((int)$userId, $plan['id'], $finalPrice);
                if ($result['ok']) {
                    $pixData = $result; $selPlan = $plan;
                    // Se o PIX foi reusado (usuário recarregou a página), devolve o incremento do cupom
                    // — o cupom original da tx original já foi contado.
                    if (!empty($result['reused']) && !empty($couponReservedId)) {
                        $db->prepare('UPDATE coupons SET used_count=GREATEST(0, used_count-1) WHERE id=?')
                           ->execute([$couponReservedId]);
                    }
                    if ($creditUsed > 0 && $affId && !empty($creditReservedId)) {
                        $db->prepare("UPDATE affiliate_credits_used SET transaction_id=?, note=? WHERE id=?")
                           ->execute([$result['tx_id'] ?? 0, 'Desconto na compra do plano '.$plan['name'], $creditReservedId]);
                    }
                } else {
                    $error = $result['error'];
                    // Rollback do crédito reservado se PIX falhou
                    if (!empty($creditReservedId)) {
                        $db->prepare('DELETE FROM affiliate_credits_used WHERE id=? AND note="_reservado_"')
                           ->execute([$creditReservedId]);
                    }
                    // Devolve uso do cupom se a cobrança falhou
                    if (!empty($couponReservedId)) {
                        $db->prepare('UPDATE coupons SET used_count=GREATEST(0, used_count-1) WHERE id=?')
                           ->execute([$couponReservedId]);
                    }
                }
            }
        }
    }
}

// Gera CSRF simples
if (empty($_SESSION['csrf_renovar'])) $_SESSION['csrf_renovar'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_renovar'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Renovar Acesso — <?= htmlspecialchars($siteName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/renovar.css">
<?php
if (!function_exists('trackingCaptureClick')) require_once __DIR__ . '/includes/tracking.php';
trackingCaptureClick();
trackingCaptureGoogleClick();
?>
</head>
<body>
<div class="orb orb-1"></div><div class="orb orb-2"></div>
<div class="wrap">
  <div class="logo"><?= htmlspecialchars($siteName) ?></div>
  <h1>Renovar Acesso</h1>
  <p class="subtitle">Olá, <strong style="color:var(--text)"><?= htmlspecialchars($user['name']) ?></strong>! Escolha um plano para continuar.</p>

  <?php if ($error): ?>
  <div class="alert-err">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <?= htmlspecialchars($error) ?>
  </div>
  <?php endif; ?>

  <?php if (!$pixData): ?>
  <!-- Seleção de plano -->
  <?php if (!$plans): ?>
  <div style="text-align:center;padding:40px;color:var(--muted);background:var(--surface);border-radius:16px">
    <div style="font-size:32px;margin-bottom:12px">⚙️</div>
    Nenhum plano com preço configurado. Entre em contato com o administrador.
  </div>
  <?php else: ?>
  <form method="POST" id="plan-form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="plan_id" id="plan-id-input" value="">

    <div class="plans-grid">
      <?php foreach ($plans as $pl): ?>
      <div class="plan-card" onclick="selPlan(<?= $pl['id'] ?>)" id="pc-<?= $pl['id'] ?>">
        <div class="plan-chk">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="plan-badge" style="background:<?= htmlspecialchars($pl['color']) ?>22;color:<?= htmlspecialchars($pl['color']) ?>">
          <?= $pl['duration_days'] ?> dia<?= $pl['duration_days']>1?'s':'' ?>
        </div>
        <div class="plan-name"><?= htmlspecialchars($pl['name']) ?></div>
        <div class="plan-price">R$ <?= number_format((float)$pl['price'],2,',','.') ?><small>/acesso</small></div>
        <?php if ($pl['description']): ?>
        <div class="plan-desc"><?= htmlspecialchars($pl['description']) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <button type="submit" class="btn-pix" id="btn-pix" disabled>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      <span id="btn-pix-label">Gerar PIX</span>
    </button>

    <!-- Cupom de desconto -->
    <div style="margin-top:14px">
      <div style="display:flex;gap:8px">
        <input type="text" name="coupon" placeholder="Cupom de desconto (opcional)"
               style="flex:1;background:var(--surface2);border:1px solid var(--border2);border-radius:8px;padding:10px 14px;font-size:13px;color:var(--text);text-transform:uppercase"
               oninput="this.value=this.value.toUpperCase()">
      </div>
      <?php if($couponInfo): ?>
      <div style="margin-top:8px;font-size:13px;color:var(--success);font-weight:600">
        ✅ Cupom <?= htmlspecialchars($couponInfo['code']) ?> aplicado —
        desconto de <?= $couponInfo['type']==='percent' ? $couponInfo['value'].'%' : 'R$ '.number_format($couponInfo['discount'],2,',','.') ?>
      </div>
      <?php endif; ?>
    </div>

    <?php
    if (!function_exists('affiliateIdByUser')) require_once __DIR__ . '/includes/affiliate.php';
    $affId4  = affiliateIdByUser((int)$userId);
    $affBal4 = $affId4 ? affiliateBalance($affId4) : 0;
    if ($affBal4 > 0):
    ?>
    <div style="margin-top:10px;padding:12px 14px;background:rgba(124,106,255,.08);border:1px solid rgba(124,106,255,.25);border-radius:8px">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
        <input type="checkbox" name="use_credit" value="1"
               id="chk-credit"
               <?= (isset($_POST['use_credit'])&&$_POST['use_credit']==='1')?'checked':'' ?>
               onchange="updatePixBtn()"
               style="width:16px;height:16px;accent-color:var(--accent);cursor:pointer">
        <div>
          <div style="font-size:13px;font-weight:600;color:var(--accent)">
            🎁 Usar créditos de afiliado
          </div>
          <div style="font-size:12px;color:var(--muted2)" id="credit-desc">
            Saldo disponível: <b>R$ <?= number_format($affBal4,2,',','.') ?></b>
            — será aplicado como desconto no valor do plano
          </div>
        </div>
      </label>
      <?php if(isset($creditUsed) && $creditUsed > 0): ?>
      <div style="margin-top:8px;font-size:13px;color:var(--success);font-weight:600">
        ✅ Crédito de R$ <?= number_format($creditUsed,2,',','.') ?> aplicado
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </form>
  <?php endif; ?>

  <?php else: ?>
  <!-- QR Code -->
  <div class="pix-box">
    <div style="font-family:'Roboto',sans-serif;font-size:17px;font-weight:800;margin-bottom:4px">Pague via PIX</div>
    <div style="font-size:13px;color:var(--muted);margin-bottom:20px">
      <?= htmlspecialchars($selPlan['name']) ?> — R$ <?= number_format((float)$selPlan['price'],2,',','.') ?>
    </div>
    <div class="pix-qr">
      <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode($pixData['pix_code']) ?>" alt="QR PIX">
    </div>
    <div style="font-size:12px;color:var(--muted2);margin-bottom:8px;font-weight:600">Ou copie o código PIX:</div>
    <div class="pix-code-wrap">
      <div class="pix-code" id="pix-txt"><?= htmlspecialchars($pixData['pix_code']) ?></div>
      <button class="btn-copy" onclick="copyPix()">📋 Copiar</button>
    </div>
    <div class="pix-timer">⏱ Expira em <span id="timer">--:--</span></div>
    <div class="status-bar s-wait show" id="s-wait"><div class="spin"></div>Aguardando pagamento...</div>
    <div class="status-bar s-exp" id="s-exp">⛔ PIX expirado. <a href="<?= SITE_URL ?>/renovar" style="color:inherit;text-decoration:underline">Gerar novo</a></div>
    <a href="<?= SITE_URL ?>/carteira" class="btn-back" style="margin-top:18px" id="btn-back-carteira">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Ir para minha carteira
    </a>
  </div>

  <!-- Tela de sucesso (hidden até pagamento confirmado) -->
  <div id="s-paid" style="display:none;text-align:center;padding:8px 0">
    <div style="font-size:64px;margin-bottom:8px;animation:pop .4s ease">🎉</div>
    <div style="font-family:'Roboto',sans-serif;font-size:22px;font-weight:800;color:var(--success);margin-bottom:8px">Pagamento aprovado!</div>
    <div style="font-size:14px;color:var(--muted2);margin-bottom:28px">Seu acesso foi renovado com sucesso.</div>
    <a href="<?= SITE_URL ?>/index.php" style="display:flex;align-items:center;justify-content:center;gap:8px;background:var(--success);color:#fff;padding:14px 24px;border-radius:12px;font-size:15px;font-weight:700;text-decoration:none;font-family:'Roboto',sans-serif">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
      Acessar conteúdo
    </a>
  </div>
  <?php endif; ?>

  <a href="<?= SITE_URL ?>/<?= !empty($_SESSION['user_id']) ? 'index.php' : 'login.php' ?>" class="btn-back">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
    <?= !empty($_SESSION['user_id']) ? 'Voltar ao início' : 'Voltar ao login' ?>
  </a>
</div>

<script>
var AFF_BALANCE = <?= json_encode($affBal4 ?? 0) ?>;

// Planos disponíveis com preços para calcular cobertura de crédito
var PLAN_PRICES = <?= json_encode(array_column($plans ?? [], 'price', 'id')) ?>;
var selectedPlanId = null;

function selPlan(id) {
  document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('sel'));
  document.getElementById('pc-' + id)?.classList.add('sel');
  document.getElementById('plan-id-input').value = id;
  selectedPlanId = id;
  updatePixBtn();
}

function updatePixBtn() {
  var btn   = document.getElementById('btn-pix');
  var label = document.getElementById('btn-pix-label');
  if (!btn || !selectedPlanId) { if(btn) btn.disabled = true; return; }

  btn.disabled = false;

  var chk   = document.getElementById('chk-credit');
  var using = chk && chk.checked;
  var price = parseFloat(PLAN_PRICES[selectedPlanId] || 0);
  var final = using ? Math.max(0, price - AFF_BALANCE) : price;

  if (using && AFF_BALANCE >= price) {
    // Crédito cobre tudo
    label.textContent = '✅ Ativar plano com créditos';
    btn.style.background = 'var(--success)';
  } else if (using && AFF_BALANCE > 0) {
    // Crédito cobre parte
    label.textContent = '⚡ Gerar PIX — R$ ' + final.toFixed(2).replace('.', ',');
    btn.style.background = '';
  } else {
    label.textContent = 'Gerar PIX';
    btn.style.background = '';
  }
}

// Pré-seleciona plano se vier da URL
<?php if ($preselect): ?>
document.addEventListener('DOMContentLoaded', () => selPlan(<?= $preselect ?>));
<?php endif; ?>

function copyPix() {
  const text = document.getElementById('pix-txt').textContent.trim();
  const btn  = document.querySelector('.btn-copy');
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(() => {
      btn.textContent = '✅ Copiado!';
      setTimeout(() => btn.textContent = '📋 Copiar', 2000);
    }).catch(() => fallbackCopy(text, btn));
  } else {
    fallbackCopy(text, btn);
  }
}
function fallbackCopy(text, btn) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0';
  document.body.appendChild(ta);
  ta.focus(); ta.select();
  try {
    document.execCommand('copy');
    if (btn) { btn.textContent = '✅ Copiado!'; setTimeout(() => btn.textContent = '📋 Copiar', 2000); }
  } catch(e) { if (btn) btn.textContent = '❌ Erro'; }
  document.body.removeChild(ta);
}

<?php if ($pixData): ?>
var renovarExpires = new Date(<?= strtotime($pixData['expires_at']) ?> * 1000);
var renovarCheck   = '<?= SITE_URL ?>/renovar?check_tx=<?= (int)$pixData['tx_id'] ?>';
var renovarDone    = false;
var renovarPollI   = null;
var renovarTimerI  = null;
var TRACK_VALUE    = <?= json_encode((float)($pixData['amount'] ?? $selPlan['price'] ?? 0)) ?>;
var TRACK_TXID     = <?= json_encode((string)$pixData['tx_id']) ?>;
var TRACK_PLAN     = <?= json_encode($selPlan['name'] ?? '') ?>;

function renovarTick() {
  if (renovarDone) return;
  var diff = Math.max(0, Math.floor((renovarExpires - new Date()) / 1000));
  var el = document.getElementById('timer');
  if (el) el.textContent = String(Math.floor(diff/60)).padStart(2,'0') + ':' + String(diff%60).padStart(2,'0');
  if (diff === 0) {
    var sw = document.getElementById('s-wait');
    var se = document.getElementById('s-exp');
    if (sw) sw.classList.remove('show');
    if (se) se.classList.add('show');
    clearInterval(renovarTimerI);
    clearInterval(renovarPollI);
    renovarDone = true;
  }
}

function renovarPoll() {
  if (renovarDone) return;
  fetch(renovarCheck, {cache: 'no-store'})
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.status === 'paid') {
        renovarDone = true;
        clearInterval(renovarPollI);
        clearInterval(renovarTimerI);
        var sw  = document.getElementById('s-wait');
        var qr  = document.querySelector('.pix-qr');
        var cw  = document.querySelector('.pix-code-wrap');
        var pt  = document.querySelector('.pix-timer');
        var bc  = document.getElementById('btn-back-carteira');
        var sp  = document.getElementById('s-paid');
        if (sw) sw.classList.remove('show');
        if (qr) qr.style.display  = 'none';
        if (cw) cw.style.display  = 'none';
        if (pt) pt.style.display  = 'none';
        if (bc) bc.style.display  = 'none';
        if (sp) sp.style.display  = 'block';
      } else if (d.status === 'expired') {
        clearInterval(renovarPollI);
      }
    })
    .catch(function() {});
}

renovarTimerI = setInterval(renovarTick, 1000);
renovarPollI  = setInterval(renovarPoll, 3000);
renovarTick();
renovarPoll();

document.addEventListener('visibilitychange', function() {
  if (!document.hidden && !renovarDone) renovarPoll();
});
<?php endif; ?>
</script>
</body>
</html>
