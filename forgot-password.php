<?php
require_once __DIR__ . '/includes/auth.php';
if (isLoggedIn()) { header('Location: ' . SITE_URL); exit; }
if (getSetting('allow_password_reset','0') !== '1') { header('Location: ' . SITE_URL . '/login'); exit; }

$siteName = getSetting('site_name', SITE_NAME);
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Token inválido.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $db    = getDB();
        $stmt  = $db->prepare('SELECT id, name FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user  = $stmt->fetch();

        $success = 'Se o e-mail estiver cadastrado, você receberá as instruções em breve.';

        if ($user) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);
            try {
                $db->prepare('ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(100) DEFAULT NULL')->execute();
                $db->prepare('ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_expires DATETIME DEFAULT NULL')->execute();
            } catch(Exception $e) {}
            $db->prepare('UPDATE users SET reset_token=?, reset_expires=? WHERE id=?')
               ->execute([$token, $expires, $user['id']]);

            $link = SITE_URL . '/reset-password?token=' . $token;
            $html = "<div style='font-family:sans-serif;max-width:520px;margin:0 auto;background:#0a0a0f;color:#e8e8f0;padding:40px;border-radius:16px'>
              <h2 style='font-size:22px;margin-bottom:8px;color:#e8e8f0'>Redefinir sua senha</h2>
              <p style='color:#9090a8;margin-bottom:24px'>Olá, <b style='color:#e8e8f0'>{$user['name']}</b>! Clique no botão abaixo para criar uma nova senha.</p>
              <a href='{$link}' style='display:inline-block;padding:13px 28px;background:#7c6aff;color:#fff;border-radius:9px;text-decoration:none;font-weight:600;margin-bottom:24px'>Redefinir senha</a>
              <p style='color:#6b6b80;font-size:12px'>Este link expira em <b>1 hora</b>. Se não foi você, ignore.</p>
              <hr style='border:none;border-top:1px solid #2a2a3a;margin:20px 0'>
              <p style='color:#6b6b80;font-size:11px'>Link: <a href='{$link}' style='color:#7c6aff'>{$link}</a></p>
            </div>";
            sendMail($email, "Redefinir senha — {$siteName}", $html);
        }
    }
}
?>
<!DOCTYPE html><html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Esqueci a senha — <?= htmlspecialchars($siteName) ?></title>
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
.field{margin-bottom:16px}
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
    <div class="card-title">Esqueceu a senha?</div>
    <div class="card-sub">Informe seu e-mail e enviaremos um link de redefinição</div>
    <?php if ($error): ?><div class="alert alert-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-ok">✓ <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (!$success): ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <div class="field"><label>E-mail cadastrado</label><input type="email" name="email" required placeholder="seu@email.com" autofocus></div>
      <button type="submit" class="btn">Enviar link de redefinição</button>
    </form>
    <?php endif; ?>
    <div class="links"><a href="<?= SITE_URL ?>/login">← Voltar ao login</a></div>
  </div>
</div>
</body></html>
