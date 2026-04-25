<?php
require_once __DIR__ . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) { header('Location: ' . SITE_URL . '/index'); exit; }

// Restaura sessão ao ir para carteira
if (isset($_GET['goto']) && $_GET['goto'] === 'carteira' && !empty($_SESSION['expired_user_id'])) {
    $_SESSION['user_id'] = $_SESSION['expired_user_id'];
    unset($_SESSION['expired_user_id']);
    header('Location: ' . SITE_URL . '/carteira');
    exit;
}

$siteName   = getSetting('site_name', SITE_NAME);
$hasSession = !empty($_SESSION['expired_user_id']);

// Verifica planos com preço + identifica se é usuário anônimo (pra lembrar o código)
$hasPlans = false;
$anonCode = null;
try {
    $db = getDB();
    $hasPlans = (bool)$db->query('SELECT COUNT(*) FROM plans WHERE active=1 AND price > 0')->fetchColumn();
    if ($hasSession) {
        $u = $db->prepare('SELECT access_code, is_anonymous FROM users WHERE id=?');
        $u->execute([(int)$_SESSION['expired_user_id']]); $u = $u->fetch();
        if ($u && !empty($u['is_anonymous']) && !empty($u['access_code'])) {
            $anonCode = $u['access_code'];
        }
    }
} catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Acesso Expirado — <?= htmlspecialchars($siteName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/expired.css">
<?php
if (!function_exists('trackingCaptureClick')) require_once __DIR__ . '/includes/tracking.php';
trackingCaptureClick();
trackingCaptureGoogleClick();
?>
</head>
<body>
<div class="orb orb-1" id="orb"></div>
<div class="wrap">
  <div class="card" id="card">
    <div class="icon-wrap" id="icon-wrap">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="1.8">
        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
      </svg>
    </div>

    <h1 id="title">Seu acesso expirou</h1>
    <p class="sub">Seu plano no <strong style="color:var(--text)"><?= htmlspecialchars($siteName) ?></strong> chegou ao fim.</p>
    <p class="sub" id="sub2">Renove abaixo para continuar acessando — seu código de acesso permanece o mesmo.</p>

    <?php if ($anonCode): ?>
    <div style="background:rgba(124,106,255,.08);border:1px dashed rgba(124,106,255,.45);border-radius:10px;padding:12px 14px;margin:14px 0;font-size:12px;color:var(--muted2);text-align:left">
      <div style="color:var(--accent);font-weight:700;margin-bottom:4px">🔐 Seu código de acesso</div>
      <div style="font-family:'Courier New',monospace;font-size:16px;font-weight:800;color:var(--text);letter-spacing:2px;text-align:center;padding:6px 0"><?= htmlspecialchars($anonCode) ?></div>
      <div style="font-size:11px;line-height:1.5;margin-top:4px">Após renovar, continue usando este mesmo código para entrar.</div>
    </div>
    <?php endif; ?>

    <hr class="divider">

    <?php if ($hasPlans): ?>
    <a href="<?= SITE_URL ?>/renovar" class="btn-green">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Renovar agora via PIX
    </a>
    <?php endif; ?>

    <?php if ($hasSession): ?>
    <button type="button" class="btn-check" id="btn-check" onclick="checkAccess()">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg>
      Verificar meu acesso
    </button>
    <div class="msg msg-check" id="msg-check"><div class="spin"></div>Verificando...</div>
    <div class="msg msg-err"   id="msg-err"><span id="err-txt">Acesso ainda não renovado.</span></div>
    <div class="msg msg-ok"    id="msg-ok"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Renovado! Redirecionando...</div>
    <div style="font-size:11px;color:var(--muted);margin:10px 0 14px">
      <span class="auto-dot"></span>Verificando automaticamente a cada 30s
    </div>
    <?php endif; ?>

    <a href="<?= SITE_URL ?>/login" class="btn-back">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
      Voltar ao login
    </a>
    <?php if ($hasSession): ?>
    <a href="<?= SITE_URL ?>/expired?goto=carteira" class="btn-back" style="margin-top:8px">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Ir para minha carteira
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($hasSession): ?>
<script>
const CHECK_URL = '<?= SITE_URL ?>/check-access.php';
let checking = false;
let autoTimer = setInterval(() => checkAccess(true), 30000);

function show(id) { document.getElementById(id).classList.add('show'); }
function hide(id) { document.getElementById(id).classList.remove('show'); }

async function checkAccess(auto = false) {
  if (checking) return;
  checking = true;
  hide('msg-check'); hide('msg-err'); hide('msg-ok');
  show('msg-check');
  const btn = document.getElementById('btn-check');
  btn.disabled = true;
  document.getElementById('icon-wrap').classList.add('checking');

  try {
    const res  = await fetch(CHECK_URL, {cache:'no-store'});
    const data = await res.json();
    hide('msg-check');
    document.getElementById('icon-wrap').classList.remove('checking');

    if (data.renewed) {
      clearInterval(autoTimer);
      show('msg-ok');
      document.getElementById('card').style.borderColor = 'rgba(16,185,129,.4)';
      document.getElementById('orb').style.background = 'var(--success)';
      document.getElementById('title').textContent = '✅ Acesso renovado!';
      document.getElementById('title').style.color = 'var(--success)';
      document.getElementById('sub2').textContent = 'Redirecionando...';
      if (data.redirect) setTimeout(() => window.location.href = data.redirect, 2000);
    } else {
      document.getElementById('err-txt').textContent =
        data.reason === 'no_session' ? 'Sessão expirada. Use o login abaixo.' : 'Acesso ainda não foi renovado.';
      show('msg-err');
      btn.disabled = false;
    }
  } catch(e) {
    hide('msg-check');
    document.getElementById('icon-wrap').classList.remove('checking');
    document.getElementById('err-txt').textContent = 'Erro de conexão.';
    show('msg-err');
    btn.disabled = false;
  }
  checking = false;
}
</script>
<?php endif; ?>
</body>
</html>
