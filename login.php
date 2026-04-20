<?php
require_once __DIR__ . '/includes/auth.php';
if (isLoggedIn()) { header('Location: ' . SITE_URL . '/index'); exit; }

$error        = '';
$kicked       = isset($_GET['kicked']) || !empty($_SESSION['kicked_out']);
if ($kicked) unset($_SESSION['kicked_out']);
$allowReg     = getSetting('allow_register',      '0') === '1';
$allowReset   = getSetting('allow_password_reset', '0') === '1';
$siteName     = getSetting('site_name', SITE_NAME);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Token inválido. Recarregue a página.';
    } else {
        $lr = loginExtended(trim($_POST['phone'] ?? ''), $_POST['password'] ?? '');
        if ($lr['ok']) {
            // Verifica se tem PIX pendente — redireciona para carteira
            try {
                $nowTs = date('Y-m-d H:i:s');
                $hasPix = getDB()->prepare('SELECT COUNT(*) FROM transactions WHERE user_id=? AND status="pending" AND (pix_expires_at IS NULL OR pix_expires_at > ?)');
                $hasPix->execute([$_SESSION['user_id'], $nowTs]);
                $dest = (int)$hasPix->fetchColumn() > 0 ? '/carteira' : '/index';
            } catch(Exception $e) { $dest = '/index'; }
            header('Location: ' . SITE_URL . $dest);
            exit;
        } elseif ($lr['reason'] === 'expired') {
            header('Location: ' . SITE_URL . '/expired');
            exit;
        } elseif ($lr['reason'] === 'locked') {
            $mins = ceil(($lr['wait'] ?? 900) / 60);
            $error = "Muitas tentativas incorretas. Tente novamente em {$mins} minuto" . ($mins > 1 ? 's' : '') . '.';
        } elseif ($lr['reason'] === 'suspended') {
            $error = 'Sua conta está suspensa. Entre em contato com o suporte.';
        } else {
            $remaining = $lr['remaining'] ?? null;
            $error = 'Telefone ou senha incorretos.' . ($remaining !== null && $remaining <= 2 ? " ({$remaining} tentativa" . ($remaining != 1 ? 's' : '') . " restante" . ($remaining != 1 ? 's' : '') . ")" : '');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Entrar — <?= htmlspecialchars($siteName) ?></title>

<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0a0a0f;--surface:#13131a;--surface2:#1c1c27;--border:#2a2a3a;--accent:#7c6aff;--accent2:#ff6a9e;--text:#e8e8f0;--muted:#6b6b80;--danger:#ff4d6a}
body{font-family:'Roboto',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;overflow:hidden}
.orb{position:fixed;border-radius:50%;filter:blur(80px);opacity:.13;pointer-events:none}
.orb-1{width:500px;height:500px;background:var(--accent);top:-150px;left:-100px;animation:drift 8s ease-in-out infinite}
.orb-2{width:400px;height:400px;background:var(--accent2);bottom:-100px;right:-100px;animation:drift 10s ease-in-out infinite reverse}
@keyframes drift{0%,100%{transform:translate(0,0)}50%{transform:translate(30px,20px)}}
.wrap{position:relative;z-index:1;width:100%;max-width:420px;padding:24px}
.logo{text-align:center;margin-bottom:36px}
.logo-mark{display:inline-flex;align-items:center;gap:10px;font-family:'Roboto',sans-serif;font-size:26px;font-weight:800;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.logo-icon{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;flex-shrink:0}
.logo-sub{color:var(--muted);font-size:13px;margin-top:6px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:36px}
.card-title{font-family:'Roboto',sans-serif;font-size:22px;font-weight:700;margin-bottom:4px}
.card-sub{color:var(--muted);font-size:13px;margin-bottom:28px}
.field{margin-bottom:18px}
.field label{display:block;font-size:12px;font-weight:500;color:#b0b0c0;margin-bottom:7px}
.field input{width:100%;padding:12px 15px;background:var(--surface2);border:1px solid var(--border);border-radius:10px;color:var(--text);font-size:15px;font-family:'Roboto',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s}
.field input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(124,106,255,.15)}
.field-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:7px}
.field-row label{font-size:12px;font-weight:500;color:#b0b0c0}
.field-row a{font-size:12px;color:var(--accent)}
.btn{width:100%;padding:13px;background:linear-gradient(135deg,var(--accent),#5b4ef0);border:none;border-radius:10px;color:#fff;font-size:15px;font-weight:600;font-family:'Roboto',sans-serif;cursor:pointer;transition:opacity .2s,transform .1s;margin-top:4px}
.btn:hover{opacity:.9;transform:translateY(-1px)}
.error-box{background:rgba(255,77,106,.1);border:1px solid rgba(255,77,106,.3);border-radius:10px;padding:11px 15px;color:var(--danger);font-size:13px;margin-bottom:20px;display:flex;align-items:center;gap:8px}
.links{text-align:center;margin-top:20px;font-size:13px;color:var(--muted);display:flex;flex-direction:column;gap:8px}
.links a{color:var(--accent)}
.divider{border:none;border-top:1px solid var(--border);margin:20px 0}
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
  <div class="logo">
    <div class="logo-mark">
      <div class="logo-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      </div>
      <?= htmlspecialchars($siteName) ?>
    </div>
    <div class="logo-sub">Plataforma de Conteúdo</div>
  </div>

  <div class="card">
    <div class="card-title">Bem-vindo de volta</div>
    <div class="card-sub">Entre com sua conta para continuar</div>

    <?php if ($kicked): ?>
    <div style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.4);border-radius:10px;padding:12px 14px;margin-bottom:16px;display:flex;align-items:flex-start;gap:10px;font-size:13px;color:#f59e0b">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <div>
        <div style="font-weight:700;margin-bottom:2px">Sessão encerrada</div>
        <div style="opacity:.9">Sua conta foi acessada em outro dispositivo e você foi desconectado automaticamente.</div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="error-box">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <div class="field">
        <label>Telefone ou E-mail</label>
        <input type="text" name="phone" placeholder="(11) 99999-9999" required autofocus
               autocomplete="username" inputmode="tel"
               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
      </div>
      <div class="field">
        <div class="field-row">
          <label>Senha</label>
          <?php if ($allowReset): ?>
          <a href="<?= SITE_URL ?>/forgot-password">Esqueci a senha</a>
          <?php endif; ?>
        </div>
        <input type="password" name="password" placeholder="••••••••" required>
      </div>
      <button type="submit" class="btn">Entrar na plataforma</button>
    </form>

    <?php if ($allowReg): ?>
    <hr class="divider">
    <div class="links">
      <span>Não tem conta? <a href="<?= SITE_URL ?>/register">Criar conta grátis</a></span>
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
