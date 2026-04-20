<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tracking.php';
if (isLoggedIn()) { header('Location: ' . SITE_URL); exit; }
if (getSetting('allow_register','0') !== '1') { header('Location: ' . SITE_URL . '/login'); exit; }

$siteName = getSetting('site_name', SITE_NAME);
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Token inválido.';
    } else {
        $name  = trim($_POST['name'] ?? '');
        $phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $pass2 = $_POST['password2'] ?? '';

        if (!$name || !$phone || !$pass)       $error = 'Preencha todos os campos.';
        elseif (!validateBrazilianPhone($phone)) $error = 'Telefone inválido. Use o formato (DDD) + número, ex: (11) 99999-9999.';
        elseif (strlen($pass) < 6)              $error = 'Senha deve ter no mínimo 6 caracteres.';
        elseif ($pass !== $pass2)               $error = 'As senhas não coincidem.';
        else {
            $db = getDB();
            // Garante coluna phone
            try { $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL"); } catch(Exception $e) {}

            // Gera e-mail único a partir do telefone
            $email = 'user_' . $phone . '@local.cms';

            $chk = $db->prepare('SELECT id FROM users WHERE phone=?');
            $chk->execute([$phone]);
            if ($chk->fetch()) $error = 'Este telefone já está cadastrado.';
            else {
                $db->prepare('INSERT INTO users (name, email, phone, password, role) VALUES (?,?,?,?,?)')
                   ->execute([$name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT), 'viewer']);
                $newId  = (int)$db->lastInsertId();
                $planId = (int)($_GET['plan'] ?? 0);
                // Dispara Lead server-side (Meta Conversions API)
                trackLead(['name' => $name, 'email' => $email, 'phone' => $phone]);
                // Registra indicação de afiliado
                if (!function_exists('affiliateOnRegister')) require_once __DIR__ . '/includes/affiliate.php';
                affiliateOnRegister($newId);
                $_SESSION['pending_user_id'] = $newId;
                header('Location: ' . SITE_URL . '/renovar' . ($planId ? '?plan='.$planId : ''));
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html><html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Criar conta — <?= htmlspecialchars($siteName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0a0a0f;--surface:#13131a;--surface2:#1c1c27;--border:#2a2a3a;--accent:#7c6aff;--accent2:#ff6a9e;--text:#e8e8f0;--muted:#6b6b80;--danger:#ff4d6a;--success:#10b981}
body{font-family:'Roboto',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center}
.orb{position:fixed;border-radius:50%;filter:blur(80px);opacity:.12;pointer-events:none}
.orb-1{width:500px;height:500px;background:var(--accent);top:-150px;left:-100px}
.orb-2{width:400px;height:400px;background:var(--accent2);bottom:-100px;right:-100px}
.wrap{position:relative;z-index:1;width:100%;max-width:420px;padding:24px}
.logo{text-align:center;margin-bottom:28px;font-family:'Roboto',sans-serif;font-size:24px;font-weight:800;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:28px}
.card-title{font-family:'Roboto',sans-serif;font-size:20px;font-weight:700;margin-bottom:4px}
.card-sub{color:var(--muted);font-size:13px;margin-bottom:20px}
.field{margin-bottom:14px}
.field label{display:block;font-size:12px;font-weight:500;color:#b0b0c0;margin-bottom:5px}
.field input{width:100%;padding:11px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:9px;color:var(--text);font-size:14px;font-family:'Roboto',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s}
.field input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(124,106,255,.15)}
.btn{width:100%;padding:13px;background:linear-gradient(135deg,var(--accent),#5b4ef0);border:none;border-radius:9px;color:#fff;font-size:14px;font-weight:600;font-family:'Roboto',sans-serif;cursor:pointer;transition:opacity .2s;margin-top:4px}
.btn:hover{opacity:.9}
.alert-err{padding:10px 14px;border-radius:9px;font-size:13px;margin-bottom:14px;background:rgba(255,77,106,.1);border:1px solid rgba(255,77,106,.3);color:var(--danger);display:flex;align-items:center;gap:8px}
.links{text-align:center;margin-top:16px;font-size:13px;color:var(--muted)}
.links a{color:var(--accent)}
.phone-wrap{position:relative}
.phone-prefix{position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:14px;color:var(--muted2);pointer-events:none}
.phone-wrap input{padding-left:36px}
</style>
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
  <div class="card">
    <div class="card-title">Criar sua conta</div>
    <div class="card-sub">Preencha os dados para se cadastrar</div>

    <?php if ($error): ?>
    <div class="alert-err">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <div class="field">
        <label>Nome completo</label>
        <input type="text" name="name" required placeholder="Seu nome completo" value="<?= htmlspecialchars($_POST['name']??'') ?>" autofocus>
      </div>
      <div class="field">
        <label>Telefone / WhatsApp</label>
        <div class="phone-wrap">
          <span class="phone-prefix">🇧🇷</span>
          <input type="tel" name="phone" required placeholder="(11) 99999-9999"
                 value="<?= htmlspecialchars($_POST['phone']??'') ?>"
                 oninput="formatPhone(this)">
        </div>
      </div>
      <div class="field">
        <label>Senha</label>
        <input type="password" name="password" required placeholder="Mínimo 6 caracteres">
      </div>
      <div class="field">
        <label>Confirmar senha</label>
        <input type="password" name="password2" required placeholder="Repita a senha">
      </div>
      <button type="submit" class="btn">Criar conta</button>
    </form>
    <div class="links">Já tem conta? <a href="<?= SITE_URL ?>/login">Entrar</a></div>
  </div>
</div>
<script>
function formatPhone(el) {
  let v = el.value.replace(/\D/g,'');
  if (v.length <= 11) {
    v = v.replace(/^(\d{2})(\d)/,'($1) $2');
    v = v.replace(/(\d{4,5})(\d{4})$/,'$1-$2');
  }
  el.value = v;
}
</script>
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
</body></html>
