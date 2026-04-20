<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pix.php';

// check_tx precisa ser processado ANTES de qualquer redirect
// (usuário pode estar logado e na tela de PIX do bem-vindo)
if (isset($_GET['check_tx'])) {
    header('Content-Type: application/json');
    $txId   = (int)$_GET['check_tx'];
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) { echo json_encode(['status'=>'error','reason'=>'no_session']); exit; }

    $txChk = getDB()->prepare('SELECT user_id FROM transactions WHERE id=?');
    $txChk->execute([$txId]); $txChk = $txChk->fetch();
    if (!$txChk || (int)$txChk['user_id'] !== (int)$userId) {
        echo json_encode(['status'=>'error','reason'=>'mismatch']); exit;
    }

    $status = checkPixStatus($txId);
    if ($status['status'] === 'paid') {
        unset($_SESSION['bv_step'],$_SESSION['bv_plan'],$_SESSION['bv_pix']);
        $status['redirect'] = SITE_URL . '/index';
    }
    echo json_encode($status); exit;
}

// Já logado e sem fluxo de bem-vindo ativo → redireciona
if (!empty($_SESSION['user_id'])) {
    if (!empty($_SESSION['bv_pix']) || !empty($_SESSION['bv_step'])) {
        unset($_SESSION['bv_step'],$_SESSION['bv_plan'],$_SESSION['bv_pix']);
        header('Location: ' . SITE_URL . '/carteira'); exit;
    }
    header('Location: ' . SITE_URL . '/index'); exit;
}

$siteName = getSetting('site_name', SITE_NAME);

$db = getDB();
$plans = $db->query('SELECT * FROM plans WHERE active=1 AND price > 0 AND visible=1 ORDER BY sort_order ASC, price ASC')->fetchAll();

// ── Estados da página ──────────────────────────
// step: 'plans' → 'register' → 'pix' → 'done'
$step    = $_SESSION['bv_step']   ?? 'plans';
$selPlan = $_SESSION['bv_plan']   ?? null;   // array do plano
$pixData = $_SESSION['bv_pix']    ?? null;   // dados do PIX gerado
$error   = '';

// ── URL /assinar/ID ou ?plano=ID — pré-seleciona o plano ──
// Sempre tem prioridade sobre a sessão — limpa qualquer plano anterior
$planoIdUrl = (int)($_GET['plano'] ?? $_GET['plan'] ?? 0);
if ($planoIdUrl) {
    $planUrl = $db->prepare('SELECT * FROM plans WHERE id=? AND active=1 AND price > 0');
    $planUrl->execute([$planoIdUrl]);
    $planUrl = $planUrl->fetch();
    if ($planUrl) {
        $_SESSION['bv_plan'] = $planUrl;
        $_SESSION['bv_step'] = 'register';
        $selPlan = $planUrl;
        $step    = 'register';
        unset($_SESSION['bv_pix']);
    }
} elseif (isset($_GET['reset_plan'])) {
    // Acessou /assinar sem ID — limpa sessão e volta para escolha de planos
    unset($_SESSION['bv_plan'], $_SESSION['bv_step'], $_SESSION['bv_pix']);
    $step    = 'plans';
    $selPlan = null;
}

// ── POST: escolher plano ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['choose_plan'])) {
    $pid  = (int)($_POST['plan_id'] ?? 0);
    $plan = $db->prepare('SELECT * FROM plans WHERE id=? AND active=1 AND price > 0 AND price > 0');
    $plan->execute([$pid]); $plan = $plan->fetch();
    if ($plan) {
        $_SESSION['bv_plan'] = $plan;
        $_SESSION['bv_step'] = 'register';
        $selPlan = $plan; $step = 'register';
    }
}

// ── POST: criar conta + gerar PIX ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_register'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Token inválido.';
    } else {
        $name  = trim($_POST['name'] ?? '');
        $phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
        $pass  = $_POST['password'] ?? '';

        if (!$name || !$phone || strlen($pass) < 6) {
            $error = 'Preencha todos os campos. Senha mínimo 6 caracteres.';
        } elseif (!validateBrazilianPhone($phone)) {
            $error = 'Telefone inválido (mín. 10 dígitos com DDD).';
        } else {
            // Verifica duplicata por telefone
            try { $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL"); } catch(Exception $e) {}
            $chk = $db->prepare('SELECT id FROM users WHERE phone=?');
            $chk->execute([$phone]);
            if ($chk->fetch()) {
                $error = 'Este telefone já está cadastrado. <a href="'.SITE_URL.'/login.php" style="color:var(--accent)">Fazer login</a>';
            } else {
                $email = 'user_' . $phone . '@local.cms';
                $db->prepare('INSERT INTO users (name,email,phone,password,role) VALUES (?,?,?,?,?)')
                   ->execute([$name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT), 'viewer']);
                $userId = (int)$db->lastInsertId();
                // Registra indicação de afiliado
                if (!function_exists('affiliateOnRegister')) require_once __DIR__ . '/includes/affiliate.php';
                affiliateOnRegister($userId);

                // Faz login imediato — acesso só liberado após pagar
                session_regenerate_id(true);
                $_SESSION['user_id']   = $userId;
                $_SESSION['user_name'] = $name;
                $_SESSION['user_role'] = 'viewer';

                // Gera PIX
                $plan = $_SESSION['bv_plan'] ?? null;
                if ($plan) {
                    $pix = createPixCharge($userId, (int)$plan['id'], (float)$plan['price']);
                    if ($pix['ok']) {
                        $_SESSION['bv_pix']  = $pix;
                        $_SESSION['bv_step'] = 'pix';
                        $pixData = $pix; $step = 'pix';
                    } else {
                        $error = 'Conta criada! Mas houve erro ao gerar PIX: ' . $pix['error'];
                        $_SESSION['bv_step'] = 'pix';
                        $step = 'pix';
                    }
                } else {
                    unset($_SESSION['bv_step'],$_SESSION['bv_plan'],$_SESSION['bv_pix']);
                    header('Location: ' . SITE_URL . '/renovar'); exit;
                }
            }
        }
    }
}

// ── Volta ao passo anterior ────────────────────
if (isset($_GET['back'])) {
    $back = $_GET['back'];
    if ($back === 'plans') {
        unset($_SESSION['bv_step'],$_SESSION['bv_plan'],$_SESSION['bv_pix']);
        $step = 'plans'; $selPlan = null; $pixData = null;
    } elseif ($back === 'register') {
        $_SESSION['bv_step'] = 'register';
        unset($_SESSION['bv_pix']);
        $step = 'register'; $pixData = null;
    }
    header('Location: ' . SITE_URL . '/bem-vindo'); exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($siteName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/bem-vindo.css">
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

  <!-- Steps -->
  <div class="steps">
    <?php
    $stepsDef = [['plans','Plano'],['register','Cadastro'],['pix','Pagamento']];
    $currentIdx = array_search($step, array_column($stepsDef,0));
    foreach ($stepsDef as $i => [$sid,$slabel]):
      $cls = $i < $currentIdx ? 'done' : ($i === $currentIdx ? 'active' : '');
      if ($i > 0): ?><div class="step-line <?= $i <= $currentIdx ? 'done' : '' ?>"></div><?php endif; ?>
      <div class="step-wrap">
        <div class="step-dot <?= $cls ?>">
          <?php if ($i < $currentIdx): ?>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
          <?php else: ?><?= $i+1 ?><?php endif; ?>
        </div>
        <div class="step-label" style="color:<?= $cls==='active'?'var(--accent)':($cls==='done'?'var(--success)':'var(--muted)') ?>"><?= $slabel ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($error): ?>
  <div class="alert-err" style="max-width:460px;margin:0 auto 16px"><?= $error ?></div>
  <?php endif; ?>

  <!-- ══ STEP 1: Planos ══ -->
  <?php if ($step === 'plans'): ?>
  <div style="text-align:center;margin-bottom:28px">
    <div style="font-family:'Roboto',sans-serif;font-size:22px;font-weight:800;margin-bottom:8px">Escolha seu plano</div>
    <div style="font-size:14px;color:var(--muted2)">Pague via PIX e acesse instantaneamente</div>
  </div>
  <div class="plans-grid">
    <?php
    $popularIdx = count($plans) >= 3 ? (int)floor(count($plans)/2) : -1;
    foreach ($plans as $i => $pl):
      $isPop = ($i === $popularIdx);
    ?>
    <div class="plan-card <?= $isPop?'popular':'' ?>">
      <?php if ($isPop): ?><div class="popular-badge">⭐ Popular</div><?php endif; ?>
      <div style="width:10px;height:10px;border-radius:50%;background:<?= htmlspecialchars($pl['color']) ?>;margin:0 auto 12px"></div>
      <div class="plan-name"><?= htmlspecialchars($pl['name']) ?></div>
      <div class="plan-price" style="color:<?= htmlspecialchars($pl['color']) ?>">R$ <?= number_format((float)$pl['price'],2,',','.') ?></div>
      <div class="plan-days"><?= $pl['duration_days'] ?> dia<?= $pl['duration_days']>1?'s':'' ?> de acesso</div>
      <?php if (!empty($pl['description'])): ?>
      <div style="font-size:12px;color:var(--muted);margin-bottom:14px"><?= htmlspecialchars($pl['description']) ?></div>
      <?php endif; ?>
      <form method="POST">
        <input type="hidden" name="plan_id" value="<?= $pl['id'] ?>">
        <input type="hidden" name="choose_plan" value="1">
        <button type="submit" class="plan-btn <?= $isPop?'plan-btn-pri':'plan-btn-sec' ?>">
          Selecionar →
        </button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="login-link">Já tem conta? <a href="<?= SITE_URL ?>/login">Entrar aqui</a></div>

  <!-- ══ STEP 2: Cadastro ══ -->
  <?php elseif ($step === 'register' && $selPlan): ?>
  <div class="card">
    <!-- Plano selecionado -->
    <div class="sel-plan-badge" style="background:<?= htmlspecialchars($selPlan['color']) ?>18;border:1px solid <?= htmlspecialchars($selPlan['color']) ?>33;color:<?= htmlspecialchars($selPlan['color']) ?>">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
      <?= htmlspecialchars($selPlan['name']) ?> — R$ <?= number_format((float)$selPlan['price'],2,',','.') ?>
      · <?= $selPlan['duration_days'] ?> dia<?= $selPlan['duration_days']>1?'s':'' ?>
    </div>

    <div class="card-title">Criar sua conta</div>
    <div class="card-sub">Preencha os dados e gere o PIX para pagamento</div>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="do_register" value="1">
      <div class="field">
        <label>Nome completo</label>
        <input type="text" name="name" required placeholder="Seu nome completo" autofocus value="<?= htmlspecialchars($_POST['name']??'') ?>">
      </div>
      <div class="field">
        <label>Telefone / WhatsApp</label>
        <input type="tel" name="phone" required placeholder="(11) 99999-9999"
               value="<?= htmlspecialchars($_POST['phone']??'') ?>"
               oninput="this.value=this.value.replace(/\D/g,'').replace(/^(\d{2})(\d)/,'($1) $2').replace(/(\d{4,5})(\d{4})$/,'$1-$2')">
      </div>
      <div class="field">
        <label>Senha <span style="color:var(--muted);font-weight:400">(mín. 6 caracteres)</span></label>
        <input type="password" name="password" required placeholder="••••••••" minlength="6">
      </div>
      <button type="submit" class="btn-full">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        Criar conta e gerar PIX
      </button>
    </form>

    <a href="<?= SITE_URL ?>/bem-vindo.php?back=plans" class="btn-back-link">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
      Mudar de plano
    </a>
    <div class="login-link">Já tem conta? <a href="<?= SITE_URL ?>/login">Entrar aqui</a></div>
  </div>

  <!-- ══ STEP 3: PIX ══ -->
  <?php elseif ($step === 'pix'): ?>
  <div class="card">
    <?php if ($pixData && !empty($pixData['pix_code'])): ?>
    <div style="text-align:center">
      <div style="font-family:'Roboto',sans-serif;font-size:18px;font-weight:800;margin-bottom:4px">Pague via PIX</div>
      <?php if ($selPlan): ?>
      <div style="font-size:13px;color:var(--muted);margin-bottom:18px">
        <?= htmlspecialchars($selPlan['name']) ?> — R$ <?= number_format((float)$selPlan['price'],2,',','.') ?>
      </div>
      <?php endif; ?>

      <div class="pix-qr">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode($pixData['pix_code']) ?>" alt="QR PIX">
      </div>

      <div style="font-size:12px;color:var(--muted2);margin-bottom:6px;font-weight:600">Código PIX copia e cola:</div>
      <div class="pix-code-wrap">
        <div class="pix-code" id="pix-txt"><?= htmlspecialchars($pixData['pix_code']) ?></div>
        <button class="btn-copy" onclick="copyPix()">📋 Copiar</button>
      </div>

      <div class="pix-timer">⏱ Expira em <span id="timer">--:--</span></div>

      <div class="status-bar s-wait show" id="s-wait"><div class="spin"></div>Aguardando pagamento...</div>
      <div class="status-bar s-exp"  id="s-exp">⛔ PIX expirado. <a href="<?= SITE_URL ?>/renovar" style="color:inherit;text-decoration:underline">Gerar novo</a></div>

      <a href="<?= SITE_URL ?>/carteira.php" class="btn-back-link" style="margin-top:16px">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        Ir para minha carteira
      </a>
    </div>

    <!-- Tela de sucesso (hidden até pagamento confirmado) -->
    <div id="s-paid" style="display:none;text-align:center;padding:8px 0">
      <div style="font-size:64px;margin-bottom:8px;animation:pop .4s ease">🎉</div>
      <div style="font-family:'Roboto',sans-serif;font-size:22px;font-weight:800;color:var(--success);margin-bottom:8px">Pagamento aprovado!</div>
      <div style="font-size:14px;color:var(--muted2);margin-bottom:28px">Seu acesso foi liberado com sucesso.</div>
      <a href="<?= SITE_URL ?>/index.php" class="btn-full" style="text-decoration:none;background:var(--success);display:flex;align-items:center;justify-content:center;gap:8px">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        Acessar conteúdo
      </a>
    </div>
    <?php else: ?>
    <!-- Erro ao gerar PIX mas conta foi criada -->
    <div style="text-align:center">
      <div style="font-size:32px;margin-bottom:12px">⚠️</div>
      <div class="card-title">Conta criada!</div>
      <div class="card-sub">Houve um problema ao gerar o PIX automaticamente. Você pode gerar manualmente.</div>
      <a href="<?= SITE_URL ?>/renovar" class="btn-full" style="margin-top:16px;text-decoration:none">
        Ir para Renovar Acesso
      </a>
    </div>
    <?php endif; ?>
  </div>

  <?php else: ?>
  <!-- Fallback: sem planos configurados -->
  <div class="card" style="text-align:center">
    <div style="font-size:40px;margin-bottom:14px">🔐</div>
    <div class="card-title">Acesso restrito</div>
    <div class="card-sub">Entre em contato com o administrador para obter acesso.</div>
    <a href="<?= SITE_URL ?>/login" class="btn-full" style="margin-top:16px;text-decoration:none">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
      Fazer login
    </a>
  </div>
  <?php endif; ?>
</div>

<?php if ($step === 'pix' && !empty($pixData['pix_code'])): ?>
<script>
var expiresAt = new Date(<?= strtotime($pixData['expires_at']) ?> * 1000);
var txId = <?= (int)$pixData['tx_id'] ?>;
var CHECK = '<?= SITE_URL ?>/bem-vindo.php?check_tx=' + txId;

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

function tick() {
  if (bvDone) return;
  var diff = Math.max(0, Math.floor((expiresAt - new Date()) / 1000));
  var el = document.getElementById('timer');
  if (el) el.textContent = String(Math.floor(diff/60)).padStart(2,'0') + ':' + String(diff%60).padStart(2,'0');
  if (diff === 0) {
    var sw = document.getElementById('s-wait');
    var se = document.getElementById('s-exp');
    if (sw) sw.classList.remove('show');
    if (se) se.classList.add('show');
    clearInterval(ti); clearInterval(pi); bvDone = true;
  }
}

function poll() {
  if (bvDone) return;
  fetch(CHECK, {cache: 'no-store'})
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.status === 'paid') {
        bvDone = true;
        clearInterval(ti); clearInterval(pi);
        var sw = document.getElementById('s-wait');
        var qr = document.querySelector('.pix-qr');
        var cw = document.querySelector('.pix-code-wrap');
        var pt = document.querySelector('.pix-timer');
        var bl = document.querySelector('.btn-back-link');
        var sp = document.getElementById('s-paid');
        if (sw) sw.classList.remove('show');
        if (qr) qr.style.display = 'none';
        if (cw) cw.style.display = 'none';
        if (pt) pt.style.display = 'none';
        if (bl) bl.style.display = 'none';
        if (sp) sp.style.display = 'block';
      }
    })
    .catch(function() {});
}

var bvDone = false;
var ti = setInterval(tick, 1000);
var pi = setInterval(poll, 3000);
tick(); poll();

document.addEventListener('visibilitychange', function() {
  if (!document.hidden && !bvDone) poll();
});
</script>
<?php endif; ?>
<script>
document.querySelectorAll('input[name=phone]').forEach(function(inp){
  inp.addEventListener('input',function(){
    var v=this.value.replace(/\D/g,'').slice(0,11);
    if(v.length<=10) v=v.replace(/^(\d{2})(\d{4})(\d{0,4})/,'($1) $2-$3');
    else v=v.replace(/^(\d{2})(\d{5})(\d{0,4})/,'($1) $2-$3');
    this.value=v.replace(/-$/,'');
  });
});
</script>
</body>
</html>
