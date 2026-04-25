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
        // Se for usuário anônimo, devolve o código de acesso pro front exibir
        try {
            $u = getDB()->prepare('SELECT access_code, is_anonymous FROM users WHERE id=?');
            $u->execute([$userId]); $u = $u->fetch();
            if ($u && !empty($u['is_anonymous']) && !empty($u['access_code'])) {
                $status['access_code'] = $u['access_code'];
            }
        } catch(Exception $e) {}
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
        $isAnon = isset($_POST['anonymous']) && $_POST['anonymous'] === '1';

        if ($isAnon) {
            // ── Cadastro anônimo — só plano, sem dados pessoais ──
            ensureAccessCodeColumn();
            $accessCode = generateAccessCode();
            $name       = 'Anônimo';
            $emailSynth = 'anon_' . strtolower(str_replace('-', '', $accessCode)) . '@local.cms';

            $db->prepare('INSERT INTO users (name,email,password,role,access_code,is_anonymous) VALUES (?,?,?,?,?,1)')
               ->execute([$name, $emailSynth, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'viewer', $accessCode]);
            $userId = (int)$db->lastInsertId();

            if (!function_exists('affiliateOnRegister')) require_once __DIR__ . '/includes/affiliate.php';
            affiliateOnRegister($userId);

            session_regenerate_id(true);
            $_SESSION['user_id']       = $userId;
            $_SESSION['user_name']     = $name;
            $_SESSION['user_role']     = 'viewer';
            $_SESSION['bv_access_code'] = $accessCode;

            $plan = $_SESSION['bv_plan'] ?? null;
            if ($plan) {
                $pix = createPixCharge($userId, (int)$plan['id'], (float)$plan['price']);
                if ($pix['ok']) {
                    $_SESSION['bv_pix']  = $pix;
                    $_SESSION['bv_step'] = 'pix';
                    $pixData = $pix; $step = 'pix';
                } else {
                    $error = 'Código gerado! Mas houve erro ao gerar PIX: ' . htmlspecialchars((string)($pix['error'] ?? ''));
                    $_SESSION['bv_step'] = 'pix';
                    $step = 'pix';
                }
            } else {
                unset($_SESSION['bv_step'],$_SESSION['bv_plan'],$_SESSION['bv_pix']);
                header('Location: ' . SITE_URL . '/renovar'); exit;
            }
        } else {
            $name  = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $pass  = $_POST['password'] ?? '';

            if (!$name || !$email || strlen($pass) < 6) {
                $error = 'Preencha todos os campos. Senha mínimo 6 caracteres.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'E-mail inválido.';
            } else {
                $chk = $db->prepare('SELECT id FROM users WHERE email=?');
                $chk->execute([$email]);
                if ($chk->fetch()) {
                    $error = 'Este e-mail já está cadastrado. <a href="'.SITE_URL.'/login.php" style="color:var(--accent)">Fazer login</a>';
                } else {
                    $db->prepare('INSERT INTO users (name,email,password,role) VALUES (?,?,?,?)')
                       ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), 'viewer']);
                    $userId = (int)$db->lastInsertId();
                    if (!function_exists('affiliateOnRegister')) require_once __DIR__ . '/includes/affiliate.php';
                    affiliateOnRegister($userId);

                    session_regenerate_id(true);
                    $_SESSION['user_id']   = $userId;
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_role'] = 'viewer';

                    $plan = $_SESSION['bv_plan'] ?? null;
                    if ($plan) {
                        $pix = createPixCharge($userId, (int)$plan['id'], (float)$plan['price']);
                        if ($pix['ok']) {
                            $_SESSION['bv_pix']  = $pix;
                            $_SESSION['bv_step'] = 'pix';
                            $pixData = $pix; $step = 'pix';
                        } else {
                            $error = 'Conta criada! Mas houve erro ao gerar PIX: ' . htmlspecialchars((string)($pix['error'] ?? ''));
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

    <form method="POST" id="register-form">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="do_register" value="1">
      <input type="hidden" name="anonymous" id="anon-input" value="0">

      <div id="fields-normal">
        <div class="field">
          <label>Nome completo</label>
          <input type="text" name="name" id="f-name" placeholder="Seu nome completo" autofocus value="<?= htmlspecialchars($_POST['name']??'') ?>">
        </div>
        <div class="field">
          <label>E-mail</label>
          <input type="email" name="email" id="f-email" placeholder="voce@exemplo.com"
                 value="<?= htmlspecialchars($_POST['email']??'') ?>" autocomplete="email">
        </div>
        <div class="field">
          <label>Senha <span style="color:var(--muted);font-weight:400">(mín. 6 caracteres)</span></label>
          <input type="password" name="password" id="f-pass" placeholder="••••••••" minlength="6">
        </div>
      </div>

      <div id="fields-anon" style="display:none;padding:14px;border:1px dashed var(--accent);border-radius:10px;background:rgba(124,106,255,.06);margin-bottom:14px">
        <div style="font-size:13px;font-weight:700;color:var(--accent);margin-bottom:6px">🕶️ Compra anônima</div>
        <div style="font-size:12px;color:var(--muted2);line-height:1.5">
          Não pedimos nome, e-mail nem senha.<br>
          Após confirmar o pagamento, você receberá um <b>código de acesso</b> único.
          Guarde esse código — ele é a única forma de acessar a plataforma.
        </div>
      </div>

      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;color:var(--muted2);margin-bottom:14px;user-select:none">
        <input type="checkbox" id="anon-toggle" style="width:16px;height:16px;accent-color:var(--accent);cursor:pointer">
        <span>🕶️ Prefiro ficar anônimo — receber apenas um código de acesso</span>
      </label>

      <button type="submit" class="btn-full">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        <span id="btn-register-label">Criar conta e gerar PIX</span>
      </button>
    </form>

    <script>
    (function(){
      var toggle = document.getElementById('anon-toggle');
      var anonInput = document.getElementById('anon-input');
      var fN = document.getElementById('fields-normal');
      var fA = document.getElementById('fields-anon');
      var lbl = document.getElementById('btn-register-label');
      var inputs = ['f-name','f-email','f-pass'].map(function(id){return document.getElementById(id);});
      function apply(){
        var on = toggle.checked;
        anonInput.value = on ? '1' : '0';
        fN.style.display = on ? 'none' : '';
        fA.style.display = on ? '' : 'none';
        inputs.forEach(function(el){ if(el){ el.required = !on; el.disabled = on; } });
        if (lbl) lbl.textContent = on ? 'Gerar PIX anônimo' : 'Criar conta e gerar PIX';
      }
      toggle.addEventListener('change', apply);
      apply();
    })();
    </script>

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
      <div style="font-size:14px;color:var(--muted2);margin-bottom:20px">Seu acesso foi liberado com sucesso.</div>

      <!-- Bloco do código de acesso (só aparece para usuários anônimos) -->
      <div id="access-code-block" style="display:none;background:linear-gradient(135deg,rgba(124,106,255,.14),rgba(255,106,158,.1));border:1px solid var(--accent);border-radius:12px;padding:18px;margin-bottom:20px;text-align:left">
        <div style="font-size:12px;color:var(--accent);font-weight:700;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px">🔐 Seu código de acesso</div>
        <div style="font-size:12px;color:var(--muted2);margin-bottom:12px;line-height:1.5">Guarde bem este código. É a única forma de entrar novamente na plataforma:</div>
        <div style="display:flex;gap:8px;align-items:center">
          <div id="access-code-value" style="flex:1;font-family:'Courier New',monospace;font-size:20px;font-weight:800;letter-spacing:2px;color:var(--text);background:var(--surface2);padding:12px 14px;border-radius:8px;text-align:center;border:1px solid var(--border)">——————</div>
          <button onclick="copyAccessCode()" id="btn-copy-code" style="background:var(--accent);color:#fff;border:none;padding:12px 16px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px">📋 Copiar</button>
        </div>
        <div style="margin-top:10px;font-size:11px;color:var(--danger)">⚠️ Este código não pode ser recuperado se você perdê-lo.</div>
      </div>

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

        // Se for compra anônima, mostra o código de acesso
        if (d.access_code) {
          var blk = document.getElementById('access-code-block');
          var val = document.getElementById('access-code-value');
          if (val) val.textContent = d.access_code;
          if (blk) blk.style.display = 'block';
        }
      }
    })
    .catch(function() {});
}

function copyAccessCode() {
  var val = document.getElementById('access-code-value');
  var btn = document.getElementById('btn-copy-code');
  if (!val) return;
  var text = val.textContent.trim();
  function done(){ if(btn){ btn.textContent = '✅ Copiado!'; setTimeout(function(){ btn.textContent='📋 Copiar'; },2000); } }
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done).catch(function(){
      var ta=document.createElement('textarea'); ta.value=text; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy');done();}catch(e){} document.body.removeChild(ta);
    });
  } else {
    var ta=document.createElement('textarea'); ta.value=text; ta.style.cssText='position:fixed;top:-9999px'; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy');done();}catch(e){} document.body.removeChild(ta);
  }
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
</body>
</html>
