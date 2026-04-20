<?php
require_once __DIR__ . '/includes/auth.php';
if (isLoggedIn()) { header('Location: ' . SITE_URL); exit; }
if (getSetting('allow_password_reset','0') !== '1') { header('Location: ' . SITE_URL . '/login'); exit; }

$siteName = getSetting('site_name', SITE_NAME);
$token    = trim($_GET['token'] ?? '');
$error = $success = '';
$user  = null;

if ($token) {
    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT id, name FROM users WHERE reset_token=? AND reset_expires > NOW()');
        $stmt->execute([$token]);
        $user = $stmt->fetch();
    } catch(Exception $e) {}
}

if (!$token || !$user) $error = 'Link inválido ou expirado. Solicite um novo.';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Token inválido.';
    } else {
        $pass  = $_POST['password'] ?? '';
        $pass2 = $_POST['password2'] ?? '';
        if (strlen($pass) < 6)  $error = 'Senha deve ter no mínimo 6 caracteres.';
        elseif ($pass !== $pass2) $error = 'As senhas não coincidem.';
        else {
            $db->prepare('UPDATE users SET password=?, reset_token=NULL, reset_expires=NULL WHERE id=?')
               ->execute([password_hash($pass, PASSWORD_DEFAULT), $user['id']]);
            $success = 'Senha redefinida com sucesso! Você já pode fazer login.';
            $user    = null;
        }
    }
}
?>
<!DOCTYPE html><html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Redefinir senha — <?= htmlspecialchars($siteName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0a0a0f;--surface:#13131a;--surface2:#1c1c27;--border:#2a2a3a;--accent:#7c6aff;--accent2:#ff6a9e;--text:#e8e8f0;--muted:#6b6b80;--danger:#ff4d6a;--success:#10b981}
body{font-family:'Roboto',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center}
.orb{position:fixed;border-radius:50%;filter:blur(80px);opacity:.12;pointer-events:none}
.orb-1{width:500px;height:500px;background:var(--accent);top:-150px;left:-100px}
.orb-2{width:400px;height:400px;background:var(--accent2);bottom:-100px;right:-100px}
.wrap{position:relative;z-index:1;width:100%;max-width:400px;padding:24px}
.logo{text-align:center;margin-bottom:32px;font-family:'Roboto',sans-serif;font-size:22px;font-weight:800;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:32px}
.card-title{font-family:'Roboto',sans-serif;font-size:20px;font-weight:700;margin-bottom:4px}
.card-sub{color:var(--muted);font-size:13px;margin-bottom:22px}
.field{margin-bottom:15px}
.field label{display:block;font-size:12px;font-weight:500;color:#b0b0c0;margin-bottom:6px}
.field input{width:100%;padding:11px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:9px;color:var(--text);font-size:14px;font-family:'Roboto',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s}
.field input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(124,106,255,.15)}
.btn{width:100%;padding:13px;background:linear-gradient(135deg,var(--accent),#5b4ef0);border:none;border-radius:9px;color:#fff;font-size:14px;font-weight:600;font-family:'Roboto',sans-serif;cursor:pointer;transition:opacity .2s}
.btn:hover{opacity:.9}
.alert{padding:11px 14px;border-radius:9px;font-size:13px;margin-bottom:16px}
.alert-ok{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:var(--success)}
.alert-err{background:rgba(255,77,106,.1);border:1px solid rgba(255,77,106,.3);color:var(--danger)}
.links{text-align:center;margin-top:18px;font-size:13px;color:var(--muted)}
.links a{color:var(--accent)}
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
    <div class="card-title">Criar nova senha</div>
    <?php if ($user): ?><div class="card-sub">Olá, <?= htmlspecialchars($user['name']) ?>! Escolha uma nova senha.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-ok">✓ <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($user): ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <div class="field"><label>Nova senha</label><input type="password" name="password" required placeholder="Mínimo 6 caracteres" autofocus></div>
      <div class="field"><label>Confirmar senha</label><input type="password" name="password2" required placeholder="Repita a senha"></div>
      <button type="submit" class="btn">Salvar nova senha</button>
    </form>
    <?php endif; ?>
    <div class="links"><a href="<?= SITE_URL ?>/login">← Voltar ao login</a></div>
  </div>
</div>
</body></html>
