<?php
// includes/header.php — recebe $pageTitle
if (!function_exists('currentUser')) require_once __DIR__ . '/auth.php';
if (!function_exists('trackingHeadCode')) require_once __DIR__ . '/tracking.php';
if (!function_exists('affiliateCaptureRef')) require_once __DIR__ . '/affiliate.php';
affiliateCaptureRef();
if (!isset($user)) $user = currentUser();
$db   = getDB();
$cats = $db->query('SELECT id, name, color FROM categories ORDER BY name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $siteName = getSetting('site_name', SITE_NAME); ?>
<title><?= htmlspecialchars($pageTitle ?? $siteName) ?> — <?= htmlspecialchars($siteName) ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700;900&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<noscript><link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700;900&display=swap" rel="stylesheet"></noscript>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root{
  --bg:#0a0a0f;
  --surface:#13131a;
  --surface2:#1c1c27;
  --surface3:#232334;
  --border:#2a2a3a;
  --border2:#353549;
  --accent:#7c6aff;
  --accent2:#ff6a9e;
  --accent3:#00d4aa;
  --text:#e8e8f0;
  --muted:#6b6b80;
  --muted2:#9090a8;
  --danger:#ff4d6a;
  --warning:#f59e0b;
  --success:#10b981;
  --sidebar:240px;
  --header:64px;
}

html{scroll-behavior:smooth}

body{
  font-family:'Roboto',sans-serif;
  background:var(--bg);
  color:var(--text);
  min-height:100vh;
  display:flex;
  flex-direction:column;
}

a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}
button,input,select,textarea{font-family:inherit}

/* ── SCROLLBAR ── */
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}

/* ── LAYOUT ── */
.layout{display:flex;min-height:100vh}

/* ── SIDEBAR ── */
.sidebar{
  width:var(--sidebar);
  background:var(--surface);
  border-right:1px solid var(--border);
  display:flex;flex-direction:column;
  position:fixed;top:0;left:0;bottom:0;z-index:100;
  transition:transform .3s;
}

.sidebar-logo{
  padding:20px 20px 16px;
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:10px;
  font-family:'Roboto',sans-serif;font-size:20px;font-weight:800;
}
.sidebar-logo span{
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.logo-icon{
  width:34px;height:34px;border-radius:9px;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}

.sidebar-section{
  padding:16px 12px 8px;
  font-size:10px;font-weight:700;letter-spacing:1.5px;
  color:var(--muted);text-transform:uppercase;
}

.nav-item{
  display:flex;align-items:center;gap:10px;
  padding:9px 12px;margin:1px 8px;
  border-radius:8px;font-size:14px;font-weight:500;color:var(--muted2);
  transition:background .15s,color .15s;cursor:pointer;
}
.nav-item:hover{background:var(--surface2);color:var(--text)}
.nav-item.active{background:rgba(124,106,255,.15);color:var(--accent)}
.nav-item svg{width:17px;height:17px;flex-shrink:0}
.nav-badge{margin-left:auto;background:var(--accent);color:#fff;font-size:11px;padding:1px 7px;border-radius:20px;font-weight:600}

.sidebar-cats{
  flex:1;overflow-y:auto;padding:0 8px;
}
.cat-item{
  display:flex;align-items:center;gap:9px;
  padding:7px 12px;border-radius:8px;font-size:13px;color:var(--muted2);
  transition:background .15s,color .15s;
}
.cat-item:hover{background:var(--surface2);color:var(--text)}
.cat-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}

.sidebar-user{
  padding:14px 16px;border-top:1px solid var(--border);
  display:flex;align-items:center;gap:10px;
}
.user-avatar{
  width:34px;height:34px;border-radius:50%;overflow:hidden;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  display:flex;align-items:center;justify-content:center;
  font-size:13px;font-weight:700;color:#fff;flex-shrink:0;
}
.user-info{flex:1;min-width:0}
.user-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role{font-size:11px;color:var(--muted)}
.user-logout{color:var(--muted);transition:color .15s}
.user-logout:hover{color:var(--danger)}

/* ── MAIN ── */
.main{
  margin-left:var(--sidebar);
  flex:1;
  display:flex;flex-direction:column;
}

.topbar{
  height:var(--header);
  background:var(--surface);
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;padding:0 28px;gap:16px;
  position:sticky;top:0;z-index:90;
}
.topbar-title{font-family:'Roboto',sans-serif;font-size:18px;font-weight:700;flex:1}
.topbar-actions{display:flex;align-items:center;gap:10px}

.btn{
  display:inline-flex;align-items:center;gap:7px;
  padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;
  border:none;cursor:pointer;transition:all .2s;
}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:#6b59ef;transform:translateY(-1px)}
.btn-secondary{background:var(--surface2);color:var(--text);border:1px solid var(--border)}
.btn-secondary:hover{background:var(--surface3)}
.btn-danger{background:var(--danger);color:#fff}
.btn-danger:hover{opacity:.85}
.btn svg{width:15px;height:15px}

.page-content{flex:1;padding:28px;max-width:1280px;width:100%}

/* ── CARDS ── */
.grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.grid-4{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px}

.card{
  background:var(--surface);border:1px solid var(--border);
  border-radius:14px;overflow:hidden;
  transition:border-color .2s,transform .2s,box-shadow .2s;
}
.card:hover{border-color:var(--border2);transform:translateY(-2px);box-shadow:0 8px 30px rgba(0,0,0,.3)}

.card-thumb{
  position:relative;width:100%;padding-top:177.78%;overflow:hidden;
  background:var(--surface2);
}
.card-thumb img,.card-thumb video{
  position:absolute;inset:0;width:100%;height:100%;object-fit:cover;
}
.card-thumb-placeholder{
  position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  background:var(--surface2);
}
.type-badge{
  position:absolute;top:10px;left:10px;
  padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;
  backdrop-filter:blur(10px);
}
.type-image{background:rgba(124,106,255,.8);color:#fff}
.type-video{background:rgba(255,106,158,.8);color:#fff}
.type-file{background:rgba(0,212,170,.8);color:#000}

.card-body{padding:16px}
.card-cat{
  font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;
  color:var(--accent);margin-bottom:6px;
}
.card-title-text{
  font-family:'Roboto',sans-serif;font-size:15px;font-weight:700;
  line-height:1.4;margin-bottom:8px;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
}
.card-meta{
  display:flex;align-items:center;gap:12px;font-size:12px;color:var(--muted);flex-wrap:wrap;
}
.card-meta span{display:flex;align-items:center;gap:4px}
.card-meta svg{width:12px;height:12px}

/* ── STATS ── */
.stat-card{
  background:var(--surface);border:1px solid var(--border);
  border-radius:14px;padding:20px;
}
.stat-label{font-size:12px;color:var(--muted);font-weight:500;margin-bottom:8px}
.stat-value{font-family:'Roboto',sans-serif;font-size:28px;font-weight:800}
.stat-sub{font-size:12px;color:var(--muted);margin-top:4px}

/* ── BADGE ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
.badge-accent{background:rgba(124,106,255,.15);color:var(--accent)}
.badge-success{background:rgba(16,185,129,.15);color:var(--success)}
.badge-danger{background:rgba(255,77,106,.15);color:var(--danger)}
.badge-warning{background:rgba(245,158,11,.15);color:var(--warning)}

/* ── ALERT ── */
.alert{padding:14px 18px;border-radius:10px;font-size:14px;margin-bottom:20px;display:flex;align-items:center;gap:10px}
.alert-success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:var(--success)}
.alert-danger{background:rgba(255,77,106,.1);border:1px solid rgba(255,77,106,.3);color:var(--danger)}

/* ── FORM ── */
.form-group{margin-bottom:20px}
.form-label{display:block;font-size:13px;font-weight:500;color:#b0b0c0;margin-bottom:8px}
.form-control{
  width:100%;padding:11px 14px;
  background:var(--surface2);border:1px solid var(--border);
  border-radius:10px;color:var(--text);font-size:14px;
  outline:none;transition:border-color .2s,box-shadow .2s;
}
.form-control:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(124,106,255,.15)}
textarea.form-control{resize:vertical;min-height:100px}
select.form-control{cursor:pointer}

/* ── UPLOAD ZONE ── */
.upload-zone{
  border:2px dashed var(--border2);border-radius:14px;
  padding:40px;text-align:center;cursor:pointer;
  transition:border-color .2s,background .2s;
}
.upload-zone:hover,.upload-zone.dragover{
  border-color:var(--accent);background:rgba(124,106,255,.05);
}
.upload-zone-icon{width:48px;height:48px;margin:0 auto 12px;color:var(--muted)}
.upload-zone-text{font-size:15px;font-weight:600;margin-bottom:4px}
.upload-zone-sub{font-size:13px;color:var(--muted)}

/* ── MEDIA PREVIEW ── */
.media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-top:16px}
.media-item{
  position:relative;border-radius:10px;overflow:hidden;
  border:1px solid var(--border);aspect-ratio:1;
  background:var(--surface2);
}
.media-item img,.media-item video{width:100%;height:100%;object-fit:cover}
.media-remove{
  position:absolute;top:6px;right:6px;
  width:24px;height:24px;border-radius:50%;
  background:rgba(255,77,106,.9);border:none;cursor:pointer;
  display:flex;align-items:center;justify-content:center;color:#fff;
  transition:transform .2s;
}
.media-remove:hover{transform:scale(1.1)}

/* ── SECTION HEADER ── */
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.section-title{font-family:'Roboto',sans-serif;font-size:20px;font-weight:700}
.section-sub{font-size:13px;color:var(--muted);margin-top:3px}

/* ── TABS ── */
.tabs{display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:24px}
.tab{
  padding:10px 18px;font-size:14px;font-weight:500;color:var(--muted);
  border-bottom:2px solid transparent;cursor:pointer;transition:color .2s;
  margin-bottom:-1px;
}
.tab.active,.tab:hover{color:var(--text)}
.tab.active{border-bottom-color:var(--accent);color:var(--accent)}

/* ── MOBILE ── */
.mobile-toggle{
  display:none;background:none;border:none;cursor:pointer;
  color:var(--text);padding:4px;
}
.sidebar-overlay{
  display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99;
}

@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .main{margin-left:0}
  .mobile-toggle{display:block}
  .grid-4{grid-template-columns:repeat(auto-fill,minmax(130px,1fr))}
  .grid-3{grid-template-columns:repeat(3,1fr)}
}
@media(max-width:600px){
  .grid-4{grid-template-columns:repeat(auto-fill,minmax(110px,1fr))}
  .grid-3,.grid-2{grid-template-columns:repeat(2,1fr)}
  .page-content{padding:16px}
}
/* ── PAGINATION ── */
.pagination{display:flex;align-items:center;gap:5px;flex-wrap:wrap;padding:16px 0 4px}
.pg-btn{
  display:inline-flex;align-items:center;justify-content:center;
  min-width:34px;height:34px;padding:0 10px;
  background:var(--surface);border:1px solid var(--border);
  border-radius:8px;color:var(--muted2);font-size:13px;font-weight:500;
  text-decoration:none;transition:all .15s;cursor:pointer;white-space:nowrap;
}
.pg-btn:hover{background:var(--surface2);border-color:var(--border2);color:var(--text)}
.pg-active{background:var(--accent)!important;border-color:var(--accent)!important;color:#fff!important;font-weight:700}
.pg-dots{border:none;background:none;color:var(--muted);cursor:default;min-width:20px;padding:0}
.pg-dots:hover{background:none;border:none;color:var(--muted)}
.pg-arrow{padding:0 14px;gap:6px}
.pg-arrow.disabled{opacity:.35;pointer-events:none;cursor:default}
.pg-info{margin-left:auto;font-size:12px;color:var(--muted);white-space:nowrap}

/* ── Utilitários ── */
.txt-muted-xs{font-size:11px;color:var(--muted)}
.txt-muted-sm{font-size:12px;color:var(--muted)}
.txt-muted-mt{font-size:11px;color:var(--muted);margin-top:4px}
.txt-sm-bold{font-size:13px;font-weight:600}
.img-cover{width:100%;height:100%;object-fit:cover;display:block}
.flex-center{width:100%;justify-content:center}
.th-cell{padding:9px 14px;text-align:left;font-size:11px;color:var(--muted);font-weight:600}
.th-cell-sm{padding:5px 10px;font-size:11px}
</style>
<?php trackingCaptureClick(); trackingCaptureGoogleClick(); ?>
</head>
<body>
<?php /* trackingBodyCode removido — sem snippet em todas as páginas */ ?>
<?php if (!empty($_SESSION['impersonator_user_id'])): ?>
<div style="background:linear-gradient(90deg,#ff6a9e,#7c6aff);color:#fff;padding:10px 18px;display:flex;align-items:center;justify-content:center;gap:14px;font-size:13px;font-weight:600;position:sticky;top:0;z-index:9999;box-shadow:0 2px 12px rgba(0,0,0,.25)">
  <span>👤 Você está acessando como <b><?= htmlspecialchars($user['name'] ?? '?') ?></b> — admin original: <?= htmlspecialchars($_SESSION['impersonator_user_name'] ?? '') ?></span>
  <a href="<?= SITE_URL ?>/stop-impersonate.php" style="background:#fff;color:#7c6aff;padding:5px 14px;border-radius:20px;text-decoration:none;font-weight:700;font-size:12px">↩ Voltar ao admin</a>
</div>
<?php endif; ?>
<div class="layout">
<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    </div>
    <span><?= htmlspecialchars($siteName) ?></span>
  </div>

  <nav style="padding:8px 0">
    <div class="sidebar-section">Menu</div>
    <?php
    $p = $_SERVER['PHP_SELF'];
    function navItem(string $url, string $label, string $icon, string $match): string {
        $active = (strpos($_SERVER['PHP_SELF'], $match) !== false) ? 'active' : '';
        return "<a href=\"{$url}\" class=\"nav-item {$active}\">{$icon}{$label}</a>";
    }
    ?>
    <a href="<?= SITE_URL ?>/index" class="nav-item <?= basename($p)==='index.php'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      Início
    </a>

    <?php if ($user): ?>
    <a href="<?= SITE_URL ?>/posts" class="nav-item <?= basename($p)==='posts.php'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Postagens
    </a>
    <a href="<?= SITE_URL ?>/media" class="nav-item <?= basename($p)==='media.php'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
      Biblioteca
    </a>
    <?php if (!isAdmin()): ?>
    <a href="<?= SITE_URL ?>/carteira" class="nav-item <?= basename($p)==='carteira.php'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Carteira
    </a>
    <a href="<?= SITE_URL ?>/favoritos" class="nav-item <?= basename($p)==='favoritos.php'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
      Favoritos
    </a>
    <?php
    $navUnread = 0;
    try {
        $navStmt = getDB()->prepare("SELECT COUNT(*) FROM support_replies r JOIN support_messages sm ON sm.id=r.message_id WHERE sm.user_id=? AND r.sender='admin' AND r.read_at IS NULL");
        $navStmt->execute([$user['id']]); $navUnread = (int)$navStmt->fetchColumn();
    } catch(Exception $e) {}
    $navCredit = 0;
    try {
        if (!function_exists('affiliateIdByUser')) require_once __DIR__ . '/affiliate.php';
        $navAffId = affiliateIdByUser((int)$user['id']);
        if ($navAffId) $navCredit = affiliateBalance($navAffId);
    } catch(Exception $e) {}
    ?>
    <a href="<?= SITE_URL ?>/minha-indicacao" class="nav-item <?= basename($p)==='minha-indicacao.php'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Indicações<?php if ($navCredit > 0): ?> <span style="background:var(--success);color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;margin-left:4px">R$ <?= number_format($navCredit,2,',','.') ?></span><?php endif; ?>
    </a>
    <a href="<?= SITE_URL ?>/suporte" class="nav-item <?= basename($p)==='suporte.php'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      Suporte<?php if ($navUnread): ?> <span style="background:var(--accent);color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;margin-left:4px"><?= $navUnread ?></span><?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if (isAdmin()): ?>
    <div class="sidebar-section" style="margin-top:8px">Admin</div>
    <a href="<?= SITE_URL ?>/admin/upload.php" class="nav-item <?= strpos($p,'upload')!==false?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
      Novo Upload
    </a>
    <a href="<?= SITE_URL ?>/admin/manage.php" class="nav-item <?= strpos($p,'manage')!==false?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
      Gerenciar Posts
    </a>
    <a href="<?= SITE_URL ?>/admin/stats.php" class="nav-item <?= strpos($p,'stats')!==false?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
      Estatísticas
    </a>
    <a href="<?= SITE_URL ?>/admin/financeiro.php" class="nav-item <?= strpos($p,'financeiro')!==false?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Financeiro
    </a>
    <a href="<?= SITE_URL ?>/admin/categories.php" class="nav-item <?= strpos($p,'categories')!==false?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
      Categorias
    </a>
    <?php if (($user['role'] ?? '') === 'admin'): ?>
    <a href="<?= SITE_URL ?>/admin/users.php" class="nav-item <?= strpos($p,'users')!==false?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Usuários
    </a>
    <a href="<?= SITE_URL ?>/admin/plans.php" class="nav-item <?= strpos($p,'plans')!==false?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-4 0v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>
      Planos
    </a>
    <a href="<?= SITE_URL ?>/admin/webhook.php" class="nav-item <?= strpos($p,'webhook')!==false?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.15c-.05.21-.08.43-.08.66 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92-1.31-2.92-2.92-2.92z"/></svg>
      Webhook
    </a>
    <a href="<?= SITE_URL ?>/admin/coupons.php" class="nav-item <?= strpos($p,'coupons')!==false?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
      Cupons
    </a>
    <a href="<?= SITE_URL ?>/admin/acessos.php" class="nav-item <?= strpos($p,'acessos')!==false?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Códigos
    </a>
    <a href="<?= SITE_URL ?>/admin/mensagens.php" class="nav-item <?= strpos($p,'admin/mensagens')!==false?'active':'' ?>">
      <?php
      try {
          $adminUnread = getDB()->query("SELECT COUNT(DISTINCT message_id) FROM support_replies WHERE sender='user' AND read_at IS NULL")->fetchColumn();
      } catch(Exception $e) { $adminUnread = 0; }
      ?>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      Mensagens<?php if ($adminUnread): ?> <span style="background:var(--accent);color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;margin-left:4px"><?= $adminUnread ?></span><?php endif; ?>
    </a>
    <a href="<?= SITE_URL ?>/admin/audit.php" class="nav-item <?= strpos($p,'audit')!==false?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
      Auditoria
    </a>
    <a href="<?= SITE_URL ?>/admin/afiliados.php" class="nav-item <?= strpos($p,'afiliados')!==false?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Afiliados
    </a>
    <a href="<?= SITE_URL ?>/admin/settings.php" class="nav-item <?= strpos($p,'settings')!==false?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      Configurações
    </a>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
  </nav>

  <div class="sidebar-section" style="padding-top:8px">Categorias</div>
  <div class="sidebar-cats">
    <?php foreach ($cats as $cat): ?>
    <a href="<?= SITE_URL ?>/posts?cat=<?= $cat['id'] ?>" class="cat-item">
      <div class="cat-dot" style="background:<?= htmlspecialchars($cat['color']) ?>"></div>
      <?= htmlspecialchars($cat['name']) ?>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($user)): ?>
  <div class="sidebar-user">
    <a href="<?= SITE_URL ?>/profile" style="display:flex;align-items:center;gap:10px;flex:1;min-width:0;text-decoration:none">
      <div class="user-avatar" style="<?= !empty($user['plan_color']) ? 'background:linear-gradient(135deg,'.$user['plan_color'].','.$user['plan_color'].'99)' : '' ?>">
        <?= strtoupper(mb_substr($user['name'] ?? '?', 0, 1)) ?>
      </div>
      <div class="user-info" style="min-width:0">
        <div class="user-name" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($user['name'] ?? '') ?></div>
        <div class="user-role" style="display:flex;align-items:center;gap:5px;flex-wrap:nowrap">
          <?php if (!empty($user['plan_name'])): ?>
            <span style="color:<?= htmlspecialchars($user['plan_color']??'var(--accent)') ?>;font-size:10px;font-weight:700;white-space:nowrap"><?= htmlspecialchars($user['plan_name']) ?></span>
            <?php if (!empty($user['expires_at'])): ?>
            <?php $expDays = ceil((strtotime($user['expires_at'])-time())/86400); ?>
            <span style="font-size:10px;color:<?= $expDays<=2?'var(--danger)':($expDays<=7?'var(--warning)':'var(--muted)') ?>;white-space:nowrap">
              · <?= $expDays > 0 ? $expDays.'d' : 'Hoje' ?>
            </span>
            <?php endif; ?>
          <?php else: ?>
            <span style="font-size:10px;color:var(--muted)"><?= ucfirst($user['role'] ?? 'user') ?></span>
          <?php endif; ?>
        </div>
      </div>
    </a>
    <a href="<?= SITE_URL ?>/logout" class="user-logout" title="Sair" style="padding:6px">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    </a>
  </div>
  <?php endif; ?>
</aside>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>

<!-- MAIN -->
<div class="main">
  <header class="topbar">
    <button class="mobile-toggle" onclick="toggleSidebar()">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="topbar-title"><?= htmlspecialchars($pageTitle ?? 'Início') ?></div>
    <div class="topbar-actions">
      <?php if (isAdmin()): ?>
      <a href="<?= SITE_URL ?>/admin/upload.php" class="btn btn-primary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Novo Post
      </a>
      <?php endif; ?>
    </div>
  </header>
  <div class="page-content">
<?php
// Banner de aviso de expiração iminente (3 dias ou menos)
if (!empty($user) && $user['role'] !== 'admin' && !empty($user['expires_at'])) {
    $expTs   = strtotime($user['expires_at']);
    $expDays = ceil(($expTs - time()) / 86400);
    if ($expDays > 0 && $expDays <= 3 && basename($_SERVER['PHP_SELF']) !== 'renovar.php') {
        echo '<div style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:10px;padding:10px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:13px">
            <span style="font-size:18px">⚠️</span>
            <span style="color:var(--warning);font-weight:600">Seu plano expira em ' . $expDays . ' dia' . ($expDays > 1 ? 's' : '') . '.</span>
            <a href="' . SITE_URL . '/renovar" style="margin-left:auto;background:var(--warning);color:#000;padding:5px 14px;border-radius:7px;font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap">Renovar agora</a>
        </div>';
    }
}
?>
<script>
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('open');
}
</script>
