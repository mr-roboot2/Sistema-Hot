<?php
require_once __DIR__ . '/../includes/auth.php';

requireLoginAlways();
if ((currentUser()['role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/index'); exit;
}

$db        = getDB();
$pageTitle = 'Configurações';
$message   = '';

// ── Garante defaults ─────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key_name VARCHAR(100) PRIMARY KEY,
        value    VARCHAR(500) NOT NULL DEFAULT '',
        label    VARCHAR(200) NOT NULL DEFAULT '',
        type     VARCHAR(30)  NOT NULL DEFAULT 'text'
    )");
    // Garante que a coluna type aceita 'toggle' (banco antigo usava ENUM)
    try {
        $db->exec("ALTER TABLE settings MODIFY COLUMN type VARCHAR(30) NOT NULL DEFAULT 'text'");
    } catch(Exception $e) {}
    $defaults = [
        // Acesso
        ['require_login',      '1',        'Exigir login para acessar o site',    'toggle'],
        ['max_sessions',       '0',        'Máx. logins simultâneos por usuário (0 = ilimitado)', 'number'],
        ['allow_register',     '0',        'Permitir auto-cadastro de usuários',  'toggle'],
        ['preview_mode',       '0',        'Modo Preview ativo',                  'toggle'],
        ['preview_post_limit', '3',        'Posts gratuitos visíveis sem login',  'number'],
        ['preview_blur_media', '1',        'Borrar mídias no preview',            'toggle'],
        ['protect_uploads',    '0',        'Proteger uploads (exigir login)',      'toggle'],
        ['allow_download',     '1',        'Permitir download de mídias',          'toggle'],
        // Upload
        ['show_php_limit_warn','1',        'Mostrar aviso de limite do php.ini',  'toggle'],
        // Paginação
        ['per_page_home',      '8',        'Posts — Página Inicial',              'number'],
        ['per_page_listing',   '12',       'Posts — Listagem',                    'number'],
        ['per_page_media',     '24',       'Arquivos — Biblioteca',               'number'],
        ['media_per_post',     '12',       'Arquivos — Edição de post (por aba)', 'number'],
        ['media_view_limit',   '0',        'Mídias visíveis por post (0 = todas)','number'],
        // Geral
        ['site_name',          'MediaCMS', 'Nome do site',                        'text'],
        ['max_file_mb',        '500',      'Tamanho máximo de upload (MB)',        'number'],
        ['upload_concurrency', '3',        'Uploads paralelos simultâneos',        'number'],
        ['affiliate_enabled',          '0',        'Programa de afiliados ativo',          'toggle'],
        ['affiliate_commission_type',  'percent', 'Tipo de comissão afiliados',           'text'],
        ['affiliate_commission_value', '10',      'Valor da comissão afiliados',          'number'],

        ['convert_webp',       '0',        'Converter imagens para WebP',          'toggle'],
        ['image_max_dim',      '1920',     'Dimensão máxima de imagem (px)',       'number'],
        ['image_quality',      '85',       'Qualidade de compressão (1-100)',      'number'],
        ['helix_webhook_secret','',         'Webhook Secret da Helix',              'text'],
        // Tracking & Pixels
        ['meta_pixel_id',      '',         'Meta Pixel ID',                        'text'],
        ['meta_access_token',  '',         'Meta Conversions API Token',           'text'],
        ['meta_test_event',    '',         'Meta Test Event Code (dev)',           'text'],
        ['google_ads_id',      '',         'Google Ads ID (AW-XXXXXXXXX)',        'text'],
        ['google_ads_customer_id','',      'Google Ads Customer ID',              'text'],
        ['google_ads_conversion_action_id','', 'Google Ads Conversion Action ID', 'text'],
        ['google_ads_dev_token',  '',      'Google Ads Developer Token',          'text'],
        ['google_ads_oauth_token','',      'Google Ads OAuth Token',              'text'],
        ['google_ads_label',   '',         'Google Ads Conversion Label',         'text'],
        ['gtm_id',             '',         'Google Tag Manager ID (GTM-XXXXX)',   'text'],
        ['track_register',     '1',        'Disparar Lead no cadastro',           'toggle'],
        ['track_purchase',     '1',        'Disparar Purchase na conversão',      'toggle'],
        ['video_thumb_ttl',    '30',       'Cache de capa de vídeo (dias)',        'number'],
        ['cache_assets_days',  '30',       'Cache de assets estáticos (dias)',     'number'],
        // Performance & Cache
        ['cache_html_ttl',     '0',        'Cache de páginas públicas (segundos)', 'number'],
        ['redis_enabled',      '0',        'Sessões via Redis',                    'toggle'],
        ['redis_host',         '127.0.0.1','Host do Redis',                        'text'],
        ['redis_port',         '6379',     'Porta do Redis',                       'text'],
        ['redis_password',     '',         'Senha do Redis (opcional)',            'text'],
        ['apcu_enabled',       '0',        'Cache APCu de settings',              'toggle'],
        ['apcu_ttl',           '60',       'TTL do APCu (segundos)',              'number'],
        // Suporte
        ['support_telegram',   '',         'Link do Telegram (suporte)',           'text'],
        ['support_email',      '',         'E-mail de suporte',                    'text'],
        ['support_hours',      '',         'Horário de atendimento',               'text'],
        ['support_text',       '',         'Texto da página de suporte',           'textarea'],
        ['support_contact_form','0',       'Formulário de contato na página de suporte', 'toggle'],
        // SMTP
        ['smtp_host',          '',         'SMTP Host',                           'text'],
        ['smtp_port',          '587',      'SMTP Porta',                          'number'],
        ['smtp_user',          '',         'SMTP Usuário / E-mail',               'text'],
        ['smtp_pass',          '',         'SMTP Senha',                          'password'],
        ['smtp_from',          '',         'E-mail remetente (From)',             'text'],
        // PIX
        ['helix_url',          '',   'URL da API Helix PIX',             'text'],
        ['helix_verify_ssl',   '1',  'Verificar SSL da API Helix',       'toggle'],
        ['pix_expiry_minutes', '30', 'Expiração PIX (minutos)',          'number'],
        // Remarketing
        ['rm_msg_pix',
         "Olá, {nome}! 👋\n\nVi que você tentou assinar o *{plano}* no *{site}* por *{valor}*, mas o pagamento ainda não foi confirmado.\n\nSe quiser garantir seu acesso, aqui está o código PIX:\n\n{pix_code}\n\nOu acesse diretamente: {url}\n\nQualquer dúvida estou aqui! 😊",
         'Remarketing — Mensagem com PIX', 'textarea'],
        ['rm_msg_lembrete',
         "Olá, {nome}! 🔔\n\nPassando para lembrar que seu acesso ao *{site}* ainda está disponível.\n\nPlano: *{plano}* — {valor}\n\nPara concluir, acesse: {url}\n\nTe esperamos por lá! 🚀",
         'Remarketing — Lembrete', 'textarea'],
        ['rm_msg_urgente',
         "⚠️ {nome}, seu PIX expirou!\n\nSeu acesso ao *{site}* ({plano} — {valor}) não foi confirmado.\n\nGere um novo PIX aqui: {url}\n\n🔥 Garanta seu acesso antes que acabe!",
         'Remarketing — Urgente', 'textarea'],
    ];
    $ins = $db->prepare("INSERT IGNORE INTO settings (key_name,value,label,type) VALUES (?,?,?,?)");
    foreach ($defaults as $d) $ins->execute($d);
} catch (Exception $e) {}

// ── Backup do banco ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_backup']) && csrf_verify($_POST['csrf_token'] ?? '')) {
    // Gera backup via PDO (funciona sem mysqldump)
    $tables = [];
    foreach ($db->query('SHOW TABLES') as $row) $tables[] = array_values($row)[0];

    $sql  = "-- MediaCMS Backup -- " . date('Y-m-d H:i:s') . "\n";
    $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        // Estrutura
        $create = $db->query("SHOW CREATE TABLE `$table`")->fetch();
        if (!$create || empty($create['Create Table'])) continue;
        $sql .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql .= $create['Create Table'] . ";\n\n";
        // Dados
        $rows = $db->query("SELECT * FROM `$table`")->fetchAll();
        if ($rows) {
            $cols = '`' . implode('`,`', array_keys($rows[0])) . '`';
            foreach (array_chunk($rows, 100) as $chunk) {
                $vals = [];
                foreach ($chunk as $row) {
                    $escaped = array_map(function($v) use ($db) {
                        return $v === null ? 'NULL' : $db->quote((string)$v);
                    }, array_values($row));
                    $vals[] = '(' . implode(',', $escaped) . ')';
                }
                $sql .= "INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $vals) . ";\n";
            }
            $sql .= "\n";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    auditLog('backup_database');
    $filename = 'backup_' . DB_NAME . '_' . date('Y-m-d_H-i-s') . '.sql';
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

// ── Teste Redis ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_redis']) && csrf_verify($_POST['csrf_token'] ?? '')) {
    $host = getSetting('redis_host', '127.0.0.1');
    $port = (int)getSetting('redis_port', '6379');
    $pass = getSetting('redis_password', '');
    try {
        if (!extension_loaded('redis')) throw new Exception('Extensão php-redis não instalada.');
        $r = new Redis();
        if (!$r->connect($host, $port, 2)) throw new Exception("Não foi possível conectar em {$host}:{$port}");
        if ($pass) $r->auth($pass);
        $r->set('cms_test_' . time(), '1', 5);
        $message = "✅ Redis conectado com sucesso em {$host}:{$port}!";
    } catch(Exception $e) {
        $message = '❌ Redis: ' . $e->getMessage();
    }
}

// ── Limpar cache HTML ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_html_cache']) && csrf_verify($_POST['csrf_token'] ?? '')) {
        clearPageCache();
    $message = '✅ Cache de páginas limpo!';
}

// ── Aplica Redis para sessões se configurado ──
if (getSetting('redis_enabled','0') === '1' && extension_loaded('redis')) {
    $rHost = getSetting('redis_host','127.0.0.1');
    $rPort = getSetting('redis_port','6379');
    $rPass = getSetting('redis_password','');
    $savePath = "tcp://{$rHost}:{$rPort}" . ($rPass ? "?auth={$rPass}" : '');
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.save_handler', 'redis');
        ini_set('session.save_path', $savePath);
    }
}

// ── Aplica APCu TTL se configurado ───────────
if (getSetting('apcu_enabled','0') === '1' && function_exists('apcu_store')) {
    // APCu TTL é lido pelo getSetting() automaticamente
    define('APCU_SETTINGS_TTL', (int)getSetting('apcu_ttl','60'));
}

// ── Teste de SMTP ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_smtp']) && csrf_verify($_POST['csrf_token'] ?? '')) {
    $to   = currentUser()['email'];
    $sent = sendMail($to, 'Teste SMTP — ' . getSetting('site_name', SITE_NAME),
        '<p style="font-family:sans-serif">✅ SMTP configurado corretamente! E-mail enviado para <b>' . htmlspecialchars($to) . '</b>.</p>');
    $message = $sent ? "✅ E-mail de teste enviado para {$to}!" : '❌ Falha ao enviar. Verifique as configurações SMTP.';
}

// ── Salvar ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save']) && csrf_verify($_POST['csrf_token'] ?? '')) {
    $upd = $db->prepare('UPDATE settings SET value=? WHERE key_name=?');

    // Busca todos os tipos (coluna pode ser ENUM ou VARCHAR)
    $types = [];
    foreach ($db->query('SELECT key_name, type FROM settings') as $r) {
        $types[$r['key_name']] = $r['type'];
    }

    // Todos os toggles conhecidos — salva 0 se não enviado no form
    $toggleKeys = ['require_login','allow_register','allow_password_reset','protect_uploads','show_php_limit_warn','webhook_send_email','support_contact_form','redis_enabled','apcu_enabled','convert_webp','track_register','track_purchase','affiliate_enabled','preview_mode','preview_blur_media','allow_download'];
    foreach ($toggleKeys as $tk) {
        if (array_key_exists($tk, $types)) { // só salva se existir na tabela
            $val = !empty($_POST['settings'][$tk]) ? '1' : '0';
            $upd->execute([$val, $tk]);
        }
    }

    // Demais campos
    foreach ($_POST['settings'] ?? [] as $k => $v) {
        if (in_array($k, $toggleKeys)) continue; // já tratado
        $t = $types[$k] ?? 'text';
        if ($t === 'number') {
            if ($k === 'max_file_mb')         $v = (string)max(1, min(10240, (int)$v));
            elseif ($k === 'upload_concurrency') $v = (string)max(1, min(10, (int)$v));
            elseif ($k === 'cache_assets_days')  $v = (string)max(0, min(365, (int)$v));
            elseif ($k === 'image_max_dim')     $v = (string)max(480, min(4096, (int)$v));
            elseif ($k === 'image_quality')     $v = (string)max(1, min(100, (int)$v));
            elseif ($k === 'cache_html_ttl')     $v = (string)max(0, min(86400, (int)$v));
            elseif ($k === 'apcu_ttl')           $v = (string)max(10, min(3600, (int)$v));
            elseif ($k === 'redis_port')         $v = (string)max(1, min(65535, (int)$v));
            else $v = (string)max(1, min(500, (int)$v));
        }
        if ($t === 'password' && trim($v) === '') continue;
        // textarea: não faz trim agressivo, preserva quebras de linha
        if ($t === 'textarea') $v = rtrim($v);
        else $v = trim($v);
        $upd->execute([$v, $k]);
    }

    getSetting('__reload__'); // limpa cache
    auditLog('save_settings');
    $message = '✅ Configurações salvas com sucesso!';
}

$all = [];
foreach ($db->query('SELECT * FROM settings ORDER BY key_name') as $r) $all[$r['key_name']] = $r;

// Helper para pegar valor
$val = function(string $k, string $def='') use (&$all) { return $all[$k]['value'] ?? $def; };
$isOn = function(string $k) use (&$all) { return ($all[$k]['value'] ?? '0') === '1'; };

// Limites do PHP
$phpLimit    = getPhpUploadLimit();
$phpLimitStr = formatFileSize($phpLimit);
$phpOk       = $phpLimit >= (50 * 1024 * 1024); // >= 50MB é OK

require __DIR__ . '/../includes/header.php';
?>
<style>
.cfg-section{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:20px}
.cfg-section-title{font-family:'Roboto',sans-serif;font-size:15px;font-weight:700;margin-bottom:4px;display:flex;align-items:center;gap:8px}
.cfg-section-sub{font-size:13px;color:var(--muted);margin-bottom:20px}
.cfg-row{display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--border)}
.cfg-row:last-child{border:none;padding-bottom:0}
.cfg-row-info{flex:1;min-width:0;padding-right:20px}
.cfg-row-label{font-size:14px;font-weight:500}
.cfg-row-desc{font-size:12px;color:var(--muted);margin-top:3px;line-height:1.5}
/* Toggle */
.tog-wrap{position:relative;display:inline-block;width:48px;height:26px;flex-shrink:0;cursor:pointer}
.tog-wrap input{opacity:0;width:0;height:0;position:absolute}
.tog-track{position:absolute;inset:0;border-radius:26px;background:var(--surface3);border:1px solid var(--border2);transition:background .2s,border-color .2s}
.tog-thumb{position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.3);transition:left .2s}
.tog-wrap input:checked ~ .tog-track{background:var(--success);border-color:var(--success)}
.tog-wrap input:checked ~ .tog-thumb{left:27px}
/* Stepper */
.stepper{display:flex;align-items:center;gap:6px}
.step-btn{width:28px;height:28px;border-radius:6px;border:1px solid var(--border);background:var(--surface2);color:var(--text);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s}
.step-btn:hover{background:var(--surface3)}
.step-inp{width:60px;text-align:center;padding:5px 4px;background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:14px;font-weight:700;font-family:'Roboto',sans-serif}
/* PHP limit bar */
.limit-bar{height:8px;background:var(--surface2);border-radius:4px;overflow:hidden;margin-top:8px}
.limit-fill{height:100%;border-radius:4px;transition:width .6s}
/* Warning box */
.warn-box{padding:12px 16px;border-radius:10px;font-size:13px;display:flex;align-items:flex-start;gap:10px;margin-top:12px}
.warn-box.warn{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);color:var(--warning)}
.warn-box.ok{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.25);color:var(--success)}
.warn-box.info{background:rgba(124,106,255,.08);border:1px solid rgba(124,106,255,.25);color:var(--accent)}
</style>

<?php if ($message): ?>
<div class="alert <?= strpos($message, '✅') === 0 ? 'alert-success' : 'alert-danger' ?>" style="margin-bottom:20px">
  <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<div style="max-width:680px">
<form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<input type="hidden" name="save" value="1">

<!-- ══ MODO PREVIEW ═════════════════════════════ -->
<div class="cfg-section">
  <div class="cfg-section-title">👁️ Modo Preview</div>
  <div class="cfg-section-sub">
    Permite que visitantes não cadastrados vejam uma quantidade limitada de posts gratuitamente.
    Ao atingir o limite, exibe um bloqueio convidando para assinar.
  </div>

  <!-- Ativar modo preview -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Ativar modo preview</div>
      <div class="cfg-row-desc">
        Quando ativo, visitantes sem login podem navegar livremente até o limite de posts configurado.
        Incompatível com <b>Login obrigatório</b> — desative-o para o preview funcionar.
      </div>
    </div>
    <label class="tog-wrap">
      <input type="checkbox" name="settings[preview_mode]" value="1" <?= $isOn('preview_mode') ? 'checked' : '' ?>>
      <span class="tog-track"></span><span class="tog-thumb"></span>
    </label>
  </div>

  <?php if ($isOn('preview_mode')): ?>

  <!-- Limite de posts gratuitos -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Posts gratuitos por visita</div>
      <div class="cfg-row-desc">
        Quantos posts o visitante pode abrir antes de ver o bloqueio de assinatura.
        O contador fica em cookie — zera quando o usuário limpa o browser.
      </div>
    </div>
    <div class="stepper">
      <button type="button" class="step-btn" onclick="step('preview_post_limit',-1)">−</button>
      <input type="number" class="step-inp" id="inp-preview_post_limit"
             name="settings[preview_post_limit]"
             value="<?= (int)$val('preview_post_limit','3') ?>"
             min="1" max="50" step="1" style="width:60px">
      <button type="button" class="step-btn" onclick="step('preview_post_limit',1)">+</button>
    </div>
  </div>

  <!-- Borrar mídias -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Borrar mídias no preview</div>
      <div class="cfg-row-desc">
        Quando o visitante atingiu o limite, as imagens e vídeos ficam borrados com um overlay de assinatura
        em vez de bloquear o acesso completamente.
      </div>
    </div>
    <label class="tog-wrap">
      <input type="checkbox" name="settings[preview_blur_media]" value="1" <?= $isOn('preview_blur_media') ? 'checked' : '' ?>>
      <span class="tog-track"></span><span class="tog-thumb"></span>
    </label>
  </div>

  <!-- Preview de como ficará -->
  <div style="padding:12px 14px;background:rgba(124,106,255,.07);border:1px solid rgba(124,106,255,.2);border-radius:8px;font-size:12px;color:var(--muted);line-height:1.8">
    📋 <b style="color:var(--text)">Como funciona:</b><br>
    1. Visitante acessa o site sem login<br>
    2. Pode ver livremente a listagem de posts (index, posts.php)<br>
    3. Ao abrir um post, o contador incrementa (salvo em cookie)<br>
    4. Ao atingir <b><?= (int)$val('preview_post_limit','3') ?> post<?= (int)$val('preview_post_limit','3') > 1 ? 's' : '' ?></b>,
       exibe um overlay de bloqueio com CTA para assinar<br>
    5. Usuários cadastrados e com plano ativo nunca são bloqueados
  </div>

  <?php endif; ?>
</div>

<!-- ══ ACESSO ══════════════════════════════════ -->
<div class="cfg-section">
  <div class="cfg-section-title">🔐 Acesso ao Site</div>
  <div class="cfg-section-sub">Controle quem pode visualizar e se cadastrar no site.</div>

  <!-- Login obrigatório -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Login obrigatório</div>
      <div class="cfg-row-desc">Visitantes precisam fazer login para ver qualquer conteúdo.</div>
    </div>
    <label class="tog-wrap">
      <input type="checkbox" name="settings[require_login]" value="1" <?= $isOn('require_login') ? 'checked' : '' ?>>
      <span class="tog-track"></span><span class="tog-thumb"></span>
    </label>
  </div>

  <!-- Logins simultâneos -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Logins simultâneos por usuário</div>
      <div class="cfg-row-desc">
        Limita quantos dispositivos/abas podem estar logados com a mesma conta ao mesmo tempo.
        <b>0 = ilimitado</b>. Com <b>1</b>, ao logar em outro lugar a sessão anterior é encerrada e o usuário vê um aviso.
        Útil para impedir compartilhamento de senha.
      </div>
    </div>
    <div class="stepper">
      <button type="button" class="step-btn" onclick="step('max_sessions',-1)">−</button>
      <input type="number" class="step-inp" id="inp-max_sessions"
             name="settings[max_sessions]"
             value="<?= (int)$val('max_sessions','0') ?>"
             min="0" max="99" step="1" style="width:60px">
      <button type="button" class="step-btn" onclick="step('max_sessions',1)">+</button>
    </div>
  </div>

  <!-- Auto-cadastro -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Permitir auto-cadastro</div>
      <div class="cfg-row-desc">
        Exibe o link "Criar conta" na tela de login. Novos usuários entram como <b>viewer</b>.
        <?php if ($isOn('allow_register')): ?>
        <br><a href="<?= SITE_URL ?>/register.php" target="_blank" style="color:var(--accent);font-size:11px">→ Ver página de cadastro</a>
        <?php endif; ?>
      </div>
    </div>
    <label class="tog-wrap">
      <input type="checkbox" name="settings[allow_register]" value="1" <?= $isOn('allow_register') ? 'checked' : '' ?>>
      <span class="tog-track"></span><span class="tog-thumb"></span>
    </label>
  </div>

  <!-- Recuperação de senha -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Recuperação de senha por e-mail</div>
      <div class="cfg-row-desc">
        Exibe "Esqueci minha senha" no login. Requer SMTP configurado abaixo.
        <?php if ($isOn('allow_password_reset')): ?>
        <br><a href="<?= SITE_URL ?>/forgot-password.php" target="_blank" style="color:var(--accent);font-size:11px">→ Ver página de recuperação</a>
        <?php endif; ?>
      </div>
    </div>
    <label class="tog-wrap">
      <input type="checkbox" name="settings[allow_password_reset]" value="1" <?= $isOn('allow_password_reset') ? 'checked' : '' ?>>
      <span class="tog-track"></span><span class="tog-thumb"></span>
    </label>
  </div>

  <!-- Proteger uploads -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Proteger arquivos de upload</div>
      <div class="cfg-row-desc">
        Impede acesso direto via URL a imagens e vídeos sem estar logado.
        Quando ativo, todos os arquivos passam pelo <code>file-proxy.php</code>.
      </div>
    </div>
    <label class="tog-wrap">
      <input type="checkbox" name="settings[protect_uploads]" value="1" <?= $isOn('protect_uploads') ? 'checked' : '' ?>>
      <span class="tog-track"></span><span class="tog-thumb"></span>
    </label>
  </div>
  <?php if ($isOn('protect_uploads')): ?>
  <div class="warn-box info" style="margin-top:0">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span>Adicione ao <code>.htaccess</code> da pasta <code>uploads/</code>:<br>
    <code style="font-size:11px;opacity:.8">deny from all</code><br>
    Isso bloqueia acesso direto; o proxy PHP serve os arquivos autenticados.</span>
  </div>
  <?php endif; ?>

  <!-- Permitir download -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Permitir download de mídias</div>
      <div class="cfg-row-desc">
        Exibe botão de download em imagens, vídeos e arquivos dentro dos posts.
        Quando desativado, o botão fica oculto e o download direto é bloqueado.
      </div>
    </div>
    <label class="tog-wrap">
      <input type="checkbox" name="settings[allow_download]" value="1" <?= $isOn('allow_download') ? 'checked' : '' ?>>
      <span class="tog-track"></span><span class="tog-thumb"></span>
    </label>
  </div>
</div>

<!-- ══ UPLOAD / PHP.INI ═══════════════════════ -->
<div class="cfg-section">
  <div class="cfg-section-title">📤 Upload & Limites</div>
  <div class="cfg-section-sub">Status dos limites do servidor e avisos de upload.</div>

  <!-- Diagnóstico php.ini -->
  <div style="background:var(--surface2);border-radius:10px;padding:16px;margin-bottom:16px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
      <div style="font-size:13px;font-weight:600">Limite atual do servidor</div>
      <span class="badge <?= $phpOk ? 'badge-success' : 'badge-warning' ?>" style="font-size:12px"><?= $phpLimitStr ?></span>
    </div>
    <?php
      $pct = min(100, round($phpLimit / (500*1024*1024) * 100));
      $barColor = $phpOk ? 'var(--success)' : 'var(--warning)';
    ?>
    <div class="limit-bar"><div class="limit-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div></div>
    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:6px">
      <span>upload_max_filesize: <b><?= ini_get('upload_max_filesize') ?></b></span>
      <span>post_max_size: <b><?= ini_get('post_max_size') ?></b></span>
    </div>

    <?php if (!$phpOk): ?>
    <div class="warn-box warn" style="margin-top:10px">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      <div>
        <b>Limite muito baixo!</b> Uploads grandes vão falhar silenciosamente.<br>
        Para aumentar, edite o <code>php.ini</code> do XAMPP:<br>
        <code style="font-size:11px">upload_max_filesize = 500M<br>post_max_size = 512M</code><br>
        <span style="font-size:11px;opacity:.8">Localização: <code>C:\xampp\php\php.ini</code> → reinicie o Apache.</span>
      </div>
    </div>
    <?php else: ?>
    <div class="warn-box ok" style="margin-top:10px">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg>
      Limite adequado para uploads grandes.
    </div>
    <?php endif; ?>
  </div>

  <!-- Toggle aviso no upload -->
  <div class="cfg-row" style="padding-top:0">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Mostrar aviso de limite na tela de upload</div>
      <div class="cfg-row-desc">Exibe alerta quando o limite do php.ini é menor que 50 MB.</div>
    </div>
    <label class="tog-wrap">
      <input type="checkbox" name="settings[show_php_limit_warn]" value="1" <?= $isOn('show_php_limit_warn') ? 'checked' : '' ?>>
      <span class="tog-track"></span><span class="tog-thumb"></span>
    </label>
  </div>

  <!-- Tamanho máximo por arquivo -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Tamanho máximo por arquivo</div>
      <div class="cfg-row-desc">
        Limite aplicado pelo sistema (em MB). Não pode ultrapassar o limite do servidor
        <b>(<?= $phpLimitStr ?>)</b>.
      </div>
    </div>
    <div class="stepper">
      <button type="button" class="step-btn" onclick="step('max_file_mb',-50)">−</button>
      <input type="number" class="step-inp" id="inp-max_file_mb" name="settings[max_file_mb]"
             value="<?= (int)($all['max_file_mb']['value'] ?? 500) ?>" min="1" max="2048" style="width:70px">
      <button type="button" class="step-btn" onclick="step('max_file_mb',50)">+</button>
      <span style="font-size:12px;color:var(--muted);margin-left:6px">MB</span>
    </div>
  </div>

  <!-- Converter para WebP -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Converter imagens para WebP</div>
      <div class="cfg-row-desc">
        Converte JPEG e PNG automaticamente para WebP no upload.
        WebP é <b>50-70% menor</b> com a mesma qualidade visual — reduz banda e acelera o carregamento.
        GIF e WebP já enviados não são alterados.
        Requer extensão <code>GD</code> com suporte a WebP no PHP.
        Status: <?php echo function_exists('imagewebp') ? '<span style="color:var(--success)">✅ Disponível</span>' : '<span style="color:var(--danger)">❌ Não disponível</span>'; ?>
      </div>
    </div>
    <label class="tog-wrap">
      <input type="checkbox" name="settings[convert_webp]" value="1" <?= ($val('convert_webp','0')==='1')?'checked':'' ?>>
      <span class="tog-track"></span><span class="tog-thumb"></span>
    </label>
  </div>

  <!-- Qualidade de compressão -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Qualidade de compressão</div>
      <div class="cfg-row-desc">
        Qualidade das imagens após compressão (1–100). <b>85</b> é o padrão — bom equilíbrio entre tamanho e qualidade.
        Valores abaixo de 70 podem gerar artefatos visíveis.
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <div class="stepper">
        <button type="button" class="step-btn" onclick="step('image_quality',-5)">−</button>
        <input type="number" class="step-inp" id="inp-image_quality" name="settings[image_quality]"
               value="<?= (int)($all['image_quality']['value'] ?? 85) ?>" min="1" max="100" style="width:55px">
        <button type="button" class="step-btn" onclick="step('image_quality',5)">+</button>
      </div>
      <span class="txt-muted-sm">%</span>
    </div>
  </div>

  <!-- Dimensão máxima -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Dimensão máxima de imagem</div>
      <div class="cfg-row-desc">
        Imagens maiores que este valor (em pixels) são redimensionadas proporcionalmente.
        <b>1920px</b> é suficiente para qualquer tela. Use <b>1280px</b> para economizar mais espaço.
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <div class="stepper">
        <button type="button" class="step-btn" onclick="step('image_max_dim',-160)">−</button>
        <input type="number" class="step-inp" id="inp-image_max_dim" name="settings[image_max_dim]"
               value="<?= (int)($all['image_max_dim']['value'] ?? 1920) ?>" min="480" max="4096" style="width:70px">
        <button type="button" class="step-btn" onclick="step('image_max_dim',160)">+</button>
      </div>
      <span class="txt-muted-sm">px</span>
    </div>
  </div>
</div>

<!-- ══ GERAL ═══════════════════════════════════ -->
<div class="cfg-section">
  <div class="cfg-section-title">⚙️ Geral</div>
  <div class="cfg-section-sub">Configurações gerais do site.</div>
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Nome do site</div>
      <div class="cfg-row-desc">Aparece no logo, título das páginas e mensagens automáticas.</div>
    </div>
    <input type="text" name="settings[site_name]" class="form-control"
           style="width:220px" placeholder="MediaCMS"
           value="<?= htmlspecialchars($val('site_name','MediaCMS')) ?>">
  </div>
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Uploads paralelos</div>
      <div class="cfg-row-desc">Quantos arquivos enviar ao mesmo tempo. Aumente para conexões rápidas, diminua se o servidor travar.</div>
    </div>
    <div class="stepper">
      <button type="button" class="step-btn" onclick="step('upload_concurrency',-1)">−</button>
      <input type="number" class="step-inp" id="inp-upload_concurrency" name="settings[upload_concurrency]"
             value="<?= (int)($all['upload_concurrency']['value'] ?? 3) ?>" min="1" max="10" style="width:50px">
      <button type="button" class="step-btn" onclick="step('upload_concurrency',1)">+</button>
    </div>
  </div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Cache de capa de vídeo</div>
      <div class="cfg-row-desc">
        Dias que o thumbnail gerado pelo servidor fica salvo antes de ser regenerado.
        <b>0</b> = sempre regenera. Recomendado: <b>30 dias</b>.
        Requer <b>FFmpeg</b> instalado no servidor.
      </div>
    </div>
    <div class="stepper">
      <button type="button" class="step-btn" onclick="step('video_thumb_ttl',-1)">−</button>
      <input type="number" class="step-inp" id="inp-video_thumb_ttl" name="settings[video_thumb_ttl]"
             value="<?= (int)($all['video_thumb_ttl']['value'] ?? 30) ?>" min="0" max="365" style="width:60px">
      <button type="button" class="step-btn" onclick="step('video_thumb_ttl',1)">+</button>
    </div>
  </div>
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Cache de assets estáticos</div>
      <div class="cfg-row-desc">
        Tempo que imagens, vídeos, CSS e JS ficam em cache no browser do usuário.
        <b>0</b> = sem cache (útil durante desenvolvimento).
        Recomendado: <b>30 dias</b> em produção.
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <div class="stepper">
        <button type="button" class="step-btn" onclick="step('cache_assets_days',-1)">−</button>
        <input type="number" class="step-inp" id="inp-cache_assets_days" name="settings[cache_assets_days]"
               value="<?= (int)($all['cache_assets_days']['value'] ?? 30) ?>" min="0" max="365" style="width:55px">
        <button type="button" class="step-btn" onclick="step('cache_assets_days',1)">+</button>
      </div>
      <span class="txt-muted-sm">dias</span>
      <?php $cd = (int)($all['cache_assets_days']['value'] ?? 30); ?>
      <span style="font-size:11px;padding:2px 8px;border-radius:8px;<?= $cd===0 ? 'background:rgba(245,158,11,.15);color:var(--warning)' : 'background:rgba(16,185,129,.12);color:var(--success)' ?>">
        <?= $cd === 0 ? '⚠️ Desativado' : '✅ Ativo' ?>
      </span>
    </div>
  </div>
</div>

<!-- ══ PERFORMANCE & CACHE ══════════════════════ -->
<div class="cfg-section">
  <div class="cfg-section-title">⚡ Performance & Cache</div>
  <div class="cfg-section-sub">Configure cache de páginas, Redis e APCu para suportar alto volume de acessos.</div>

  <!-- Cache HTML de páginas públicas -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Cache de páginas públicas</div>
      <div class="cfg-row-desc">
        Segundos que index, posts e view ficam em cache para visitantes <b>não logados</b>.
        <b>0</b> = desativado. <b>120</b> = recomendado. Admin nunca é cacheado.
        Cache é limpo automaticamente ao publicar ou deletar posts.
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <div class="stepper">
        <button type="button" class="step-btn" onclick="step('cache_html_ttl',-30)">−</button>
        <input type="number" class="step-inp" id="inp-cache_html_ttl" name="settings[cache_html_ttl]"
               value="<?= (int)($all['cache_html_ttl']['value'] ?? 0) ?>" min="0" max="86400" style="width:65px">
        <button type="button" class="step-btn" onclick="step('cache_html_ttl',30)">+</button>
      </div>
      <span class="txt-muted-sm">segundos</span>
      <?php $ht = (int)($all['cache_html_ttl']['value'] ?? 0); ?>
      <span style="font-size:11px;padding:2px 8px;border-radius:8px;<?= $ht===0 ? 'background:rgba(245,158,11,.15);color:var(--warning)' : 'background:rgba(16,185,129,.12);color:var(--success)' ?>">
        <?= $ht === 0 ? '⚠️ Desativado' : '✅ ' . $ht . 's' ?>
      </span>
      <?php if($ht > 0): ?>
      <form method="POST" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <button type="submit" name="clear_html_cache" value="1" class="btn btn-secondary"
                style="padding:3px 10px;font-size:11px;color:var(--danger)">🗑️ Limpar cache agora</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- APCu -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Cache APCu de configurações</div>
      <div class="cfg-row-desc">
        Armazena as configurações do site em memória RAM em vez de consultar o banco a cada request.
        Requer extensão <code>apcu</code> instalada no PHP.
        Status: <?php echo function_exists('apcu_store') ? '<span style="color:var(--success)">✅ APCu disponível</span>' : '<span style="color:var(--danger)">❌ APCu não instalado</span>'; ?>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end">
      <label class="toggle"><input type="checkbox" name="settings[apcu_enabled]" value="1" <?= ($val('apcu_enabled','0')==='1')?'checked':'' ?>><span class="toggle-slider"></span></label>
      <?php if($val('apcu_enabled','0')==='1'): ?>
      <div style="display:flex;align-items:center;gap:8px">
        <span class="txt-muted-sm">TTL:</span>
        <div class="stepper">
          <button type="button" class="step-btn" onclick="step('apcu_ttl',-10)">−</button>
          <input type="number" class="step-inp" id="inp-apcu_ttl" name="settings[apcu_ttl]"
                 value="<?= (int)($all['apcu_ttl']['value'] ?? 60) ?>" min="10" max="3600" style="width:60px">
          <button type="button" class="step-btn" onclick="step('apcu_ttl',10)">+</button>
        </div>
        <span class="txt-muted-sm">segundos</span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Redis -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Sessões via Redis</div>
      <div class="cfg-row-desc">
        Substitui sessões em arquivo por Redis (memória). Essencial para alto volume de usuários simultâneos —
        elimina locks de arquivo. Requer Redis instalado e extensão <code>php-redis</code>.
        Após salvar, configure também no <code>php.ini</code>: <code>session.save_handler = redis</code>
      </div>
    </div>
    <label class="toggle"><input type="checkbox" name="settings[redis_enabled]" value="1" <?= ($val('redis_enabled','0')==='1')?'checked':'' ?>><span class="toggle-slider"></span></label>
  </div>

  <?php if($val('redis_enabled','0')==='1'): ?>
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Host do Redis</div>
      <div class="cfg-row-desc">Endereço do servidor Redis. Geralmente <code>127.0.0.1</code> para local.</div>
    </div>
    <input type="text" name="settings[redis_host]" class="form-control" style="width:180px"
           placeholder="127.0.0.1" value="<?= htmlspecialchars($val('redis_host','127.0.0.1')) ?>">
  </div>
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Porta do Redis</div>
    </div>
    <input type="number" name="settings[redis_port]" class="form-control" style="width:100px"
           placeholder="6379" min="1" max="65535" value="<?= (int)($val('redis_port','6379')) ?>">
  </div>
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Senha do Redis</div>
      <div class="cfg-row-desc">Deixe em branco se não tiver senha configurada.</div>
    </div>
    <input type="password" name="settings[redis_password]" class="form-control" style="width:220px"
           placeholder="Deixe vazio se não tiver senha"
           value="<?= htmlspecialchars($val('redis_password','')) ?>">
  </div>

  <!-- Teste Redis -->
  <div class="cfg-row" style="border-top:.5px solid var(--border);margin-top:4px;padding-top:16px">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Testar conexão Redis</div>
      <div class="cfg-row-desc">Verifica se o Redis está acessível com as configurações acima.</div>
    </div>
    <form method="POST" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <button type="submit" name="test_redis" value="1" class="btn btn-secondary" style="padding:7px 14px;font-size:13px">
        🔌 Testar Redis
      </button>
    </form>
  </div>
  <?php endif; ?>

<!-- ══ PAGINAÇÃO ══════════════════════════════ -->
<div class="cfg-section">
  <div class="cfg-section-title">📄 Paginação</div>
  <div class="cfg-section-sub">Quantidade de itens por página em cada seção.</div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
    <?php
    $pagItems = [
      'per_page_home'    => ['🏠 Página Inicial',   'Posts na home'],
      'per_page_listing' => ['📋 Listagem',          'Posts em /posts.php'],
      'per_page_media'   => ['🖼️ Biblioteca',        'Arquivos em /media.php'],
      'media_per_post'   => ['📁 Editor de post',    'Itens por aba (img/vid)'],
      'media_view_limit' => ['👁️ Mídias por post',   '0 = exibe todas'],
    ];
    foreach ($pagItems as $k => [$title, $sub]):
      $v = (int)($all[$k]['value'] ?? 8);
    ?>
    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px">
      <div style="font-size:13px;font-weight:600;margin-bottom:2px"><?= $title ?></div>
      <div style="font-size:11px;color:var(--muted);margin-bottom:10px"><?= $sub ?></div>
      <div class="stepper">
        <button type="button" class="step-btn" onclick="step('<?= $k ?>',-1)">−</button>
        <input type="number" class="step-inp" id="inp-<?= $k ?>" name="settings[<?= $k ?>]" value="<?= $v ?>" min="1" max="200">
        <button type="button" class="step-btn" onclick="step('<?= $k ?>',1)">+</button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ══ SMTP ═══════════════════════════════════ -->
<div class="cfg-section">
  <div class="cfg-section-title">📧 E-mail / SMTP</div>
  <div class="cfg-section-sub">Necessário para recuperação de senha. Deixe em branco para usar o <code>mail()</code> nativo do PHP.</div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
    <?php
    $smtpFields = [
      'smtp_host' => ['SMTP Host', 'smtp.gmail.com', 'text'],
      'smtp_port' => ['Porta',     '587',             'number'],
      'smtp_user' => ['Usuário / E-mail', 'seu@gmail.com', 'text'],
      'smtp_pass' => ['Senha / App Password', '••••••••',  'password'],
      'smtp_from' => ['E-mail remetente (From)', 'seu@gmail.com', 'text'],
    ];
    foreach ($smtpFields as $k => [$lbl, $ph, $type]):
      $v = $all[$k]['value'] ?? '';
    ?>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label"><?= $lbl ?></label>
      <input type="<?= $type === 'password' ? 'password' : ($type==='number'?'number':'text') ?>"
             name="settings[<?= $k ?>]"
             class="form-control"
             placeholder="<?= htmlspecialchars($ph) ?>"
             value="<?= $type === 'password' ? '' : htmlspecialchars($v) ?>"
             <?= $type === 'password' ? 'autocomplete="new-password"' : '' ?>>
      <?php if ($type === 'password' && $v): ?>
      <div style="font-size:11px;color:var(--success);margin-top:4px">✓ Senha salva (deixe vazio para manter)</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="warn-box info" style="margin-top:16px">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span>Para Gmail: ative <b>verificação em 2 etapas</b> e gere uma <b>Senha de App</b> em <a href="https://myaccount.google.com/apppasswords" target="_blank" style="color:var(--accent)">myaccount.google.com/apppasswords</a>. Use porta 587 (TLS) ou 465 (SSL).</span>
  </div>
</div>

<!-- ══ PIX / PAGAMENTO ════════════════════════ -->
<div class="cfg-section">
  <div class="cfg-section-title">💳 Integração PIX — Helix</div>
  <div class="cfg-section-sub">Configure a URL da sua API Helix para gerar cobranças PIX automaticamente.</div>

  <div class="cfg-row" style="padding-top:0">
    <div class="cfg-row-info">
      <div class="cfg-row-label">URL da API Helix</div>
      <div class="cfg-row-desc">
        Ex: <code style="font-size:11px">https://helix.vmpainel.com</code><br>
        O sistema chama <code style="font-size:11px">{url}/?valor=XX</code> para gerar e <code style="font-size:11px">{url}/status/{id}</code> para verificar.
      </div>
    </div>
    <input type="text" name="settings[helix_url]" class="form-control" style="max-width:280px"
           placeholder="https://helix.vmpainel.com"
           value="<?= htmlspecialchars($val('helix_url','')) ?>">
  </div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Expiração do PIX (minutos)</div>
      <div class="cfg-row-desc">Tempo que o QR Code fica válido para pagamento.</div>
    </div>
    <div class="stepper">
      <button type="button" class="step-btn" onclick="step('pix_expiry_minutes',-5)">−</button>
      <input type="number" class="step-inp" id="inp-pix_expiry_minutes"
             name="settings[pix_expiry_minutes]"
             value="<?= (int)($val('pix_expiry_minutes','30')) ?>" min="5" max="1440" style="width:70px">
      <button type="button" class="step-btn" onclick="step('pix_expiry_minutes',5)">+</button>
      <span class="txt-muted-sm">min</span>
    </div>
  </div>

  <div style="margin-top:14px;padding:12px 14px;background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.2);border-radius:8px;font-size:12px;color:var(--muted);line-height:1.9">
    📡 <b style="color:var(--text)">URLs para configurar na Helix:</b><br>
    Webhook/Callback: <code style="font-size:11px;color:var(--success)"><?= SITE_URL ?>/helix-callback.php</code><br>
    <span style="font-size:11px">A Helix chama essa URL quando o PIX for pago e o plano é ativado automaticamente.</span>

  <div class="cfg-row" style="margin-top:12px;padding-top:12px;border-top:.5px solid rgba(16,185,129,.2)">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Webhook Secret</div>
      <div class="cfg-row-desc">Token secreto para validar que o callback veio da Helix. Configure o mesmo valor na Helix como <code>X-Helix-Token</code>. Deixe vazio para desativar a validação (não recomendado).</div>
    </div>
    <input type="text" name="settings[helix_webhook_secret]" class="form-control" style="max-width:260px;font-family:monospace"
           placeholder="token-secreto-aqui"
           value="<?= htmlspecialchars($val('helix_webhook_secret','')) ?>">
  </div>
  </div>
</div>

<!-- ══ REMARKETING ════════════════════════════ -->
<div class="cfg-section">
  <div class="cfg-section-title">📣 Mensagens de Remarketing</div>
  <div class="cfg-section-sub">
    Textos enviados via WhatsApp para clientes que geraram PIX mas não pagaram.<br>
    <span style="font-size:12px">Variáveis disponíveis: <code>{nome}</code> <code>{plano}</code> <code>{valor}</code> <code>{site}</code> <code>{url}</code> <code>{pix_code}</code></span>
  </div>

  <?php foreach ([
    ['rm_msg_pix',      '💬 Mensagem com PIX',  'Inclui o código PIX na mensagem'],
    ['rm_msg_lembrete', '🔔 Lembrete',           'Mensagem suave de lembrete'],
    ['rm_msg_urgente',  '🔥 Urgente',            'Para PIX expirado — cria urgência'],
  ] as [$key, $label, $desc]): ?>
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label"><?= $label ?></div>
      <div class="cfg-row-desc"><?= $desc ?></div>
    </div>
    <textarea name="settings[<?= $key ?>]" rows="6"
      style="width:100%;max-width:420px;background:var(--surface2);border:1px solid var(--border);border-radius:9px;padding:10px 12px;color:var(--text);font-size:12px;font-family:monospace;line-height:1.6;resize:vertical;outline:none;transition:border-color .2s"
      onfocus="this.style.borderColor='var(--accent)'" onblur="this.style.borderColor='var(--border)'"
    ><?= htmlspecialchars($val($key, '')) ?></textarea>
  </div>
  <?php endforeach; ?>
</div>

<!-- ══ AFILIADOS ═════════════════════════════════ -->
<div class="cfg-section">
  <div class="cfg-section-title">🤝 Programa de Afiliados</div>
  <div class="cfg-section-sub">Configure o sistema de indicações. Afiliados recebem comissão quando indicam novos assinantes.</div>

  <!-- Ativar/desativar -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Ativar programa de afiliados</div>
      <div class="cfg-row-desc">
        Quando ativo, links com <code>?ref=CODIGO</code> rastreiam indicações e pagam comissão automaticamente ao confirmar o PIX.
        Quando desativado, nenhum clique ou indicação é registrado.
      </div>
    </div>
    <label class="tog-wrap">
      <input type="checkbox" name="settings[affiliate_enabled]" value="1" <?= ($val('affiliate_enabled','0')==='1')?'checked':'' ?>>
      <span class="tog-track"></span><span class="tog-thumb"></span>
    </label>
  </div>

  <?php if ($val('affiliate_enabled','0') === '1'): ?>

  <!-- Tipo de comissão -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Tipo de recompensa</div>
      <div class="cfg-row-desc">
        <b>Percentual</b> — ex: 10% da venda de R$ 97 = R$ 9,70<br>
        <b>Valor fixo</b> — ex: R$ 20 por cada conversão<br>
        <b>Plano grátis</b> — o afiliado recebe o mesmo plano que o indicado comprou (os dias são somados se já tiver plano ativo)
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px">
      <?php foreach(['percent'=>'% Percentual da venda','fixed'=>'R$ Valor fixo por conversão','plan'=>'🎁 Plano grátis (mesmo plano do indicado)'] as $v=>$l): ?>
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
        <input type="radio" name="settings[affiliate_commission_type]" value="<?= $v ?>"
               <?= $val('affiliate_commission_type','percent')===$v?'checked':'' ?>
               style="accent-color:var(--accent)">
        <?= $l ?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>

  <?php $affType = $val('affiliate_commission_type','percent'); ?>
  <?php if ($affType !== 'plan'): ?>
  <!-- Valor -->
  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Valor da comissão</div>
      <div class="cfg-row-desc">
        <?= $affType === 'percent' ? 'Percentual sobre o valor da venda.' : 'Valor em R$ pago por cada conversão (limitado ao valor da venda).' ?>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <div class="stepper">
        <button type="button" class="step-btn" onclick="step('affiliate_commission_value',-<?= $affType==='percent'?'1':'5' ?>)">−</button>
        <input type="number" class="step-inp" id="inp-affiliate_commission_value"
               name="settings[affiliate_commission_value]"
               value="<?= (float)$val('affiliate_commission_value','10') ?>"
               min="0" max="<?= $affType==='percent'?'100':'99999' ?>" step="0.01" style="width:80px">
        <button type="button" class="step-btn" onclick="step('affiliate_commission_value',<?= $affType==='percent'?'1':'5' ?>)">+</button>
      </div>
      <span class="txt-muted-sm"><?= $affType === 'percent' ? '%' : 'R$' ?></span>
      <?php
        $ex = $affType === 'percent'
            ? 'R$ ' . number_format(97 * floatval($val('affiliate_commission_value','10')) / 100, 2, ',', '.')
            : 'R$ ' . number_format(floatval($val('affiliate_commission_value','10')), 2, ',', '.');
      ?>
      <span style="font-size:12px;color:var(--muted)">→ ex: <b><?= $ex ?></b> em uma venda de R$ 97</span>
    </div>
  </div>
  <?php else: ?>
  <div style="padding:12px 14px;background:rgba(124,106,255,.08);border:1px solid rgba(124,106,255,.2);border-radius:8px;font-size:12px;color:var(--muted);line-height:1.8;margin:4px 0 8px">
    🎁 <b style="color:var(--text)">Como funciona o plano grátis:</b><br>
    Quando um indicado pagar, o afiliado recebe automaticamente o mesmo plano comprado.<br>
    Se o afiliado já tiver um plano ativo, os dias são <b>somados</b> ao prazo atual.<br>
    O valor equivalente do plano é registrado como comissão para fins de relatório.
  </div>
  <?php endif; ?>



  <!-- Link de exemplo -->
  <div style="padding:10px 14px;background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.2);border-radius:8px;font-size:12px;color:var(--muted);margin-top:4px">
    🔗 <b style="color:var(--text)">Formato do link de afiliado:</b>
    <code style="color:var(--success)"><?= SITE_URL ?>/?ref=CODIGO</code><br>
    <span style="font-size:11px">Gerencie os afiliados e veja relatórios em
      <a href="<?= SITE_URL ?>/admin/afiliados.php" style="color:var(--accent)">Admin → Afiliados</a>
    </span>
  </div>

  <?php endif; // affiliate_enabled ?>
</div>

<!-- ══ TRACKING & PIXELS ════════════════════════ -->
<div class="cfg-section">
  <div class="cfg-section-title">📊 Tracking & Pixels</div>
  <div class="cfg-section-sub">
    Rastreamento de conversões do Facebook. O pixel só é carregado para visitantes que chegarem via link do Facebook
    (com <code>?fbclid=</code> ou <code>?fb=</code> na URL). O evento de <b>Purchase</b> é enviado pelo servidor
    (Conversions API) quando o PIX é pago — funciona mesmo com ad blockers.
  </div>

  <!-- Meta Pixel -->
  <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin:0 0 10px">Meta (Facebook)</div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Meta Pixel ID</div>
      <div class="cfg-row-desc">ID do seu pixel da Meta. Encontre em Meta Business → Gerenciador de Eventos → Pixel. Formato: <code>1234567890123456</code></div>
    </div>
    <input type="text" name="settings[meta_pixel_id]" class="form-control" style="width:220px;font-family:monospace"
           placeholder="1234567890123456"
           value="<?= htmlspecialchars($val('meta_pixel_id','')) ?>">
  </div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Conversions API Token</div>
      <div class="cfg-row-desc">Token de acesso para enviar eventos pelo servidor (server-side). Mais confiável que o pixel do browser — funciona mesmo com ad blockers. Gere em: Meta Business → Pixel → Configurações → Conversions API.</div>
    </div>
    <input type="password" name="settings[meta_access_token]" class="form-control" style="width:280px;font-family:monospace"
           placeholder="EAAxxxxxxxxx..."
           value="<?= htmlspecialchars($val('meta_access_token','')) ?>">
  </div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Test Event Code</div>
      <div class="cfg-row-desc">Código de teste para validar eventos no Gerenciador de Eventos da Meta. Deixe vazio em produção. Ex: <code>TEST12345</code></div>
    </div>
    <input type="text" name="settings[meta_test_event]" class="form-control" style="width:160px;font-family:monospace"
           placeholder="TEST12345"
           value="<?= htmlspecialchars($val('meta_test_event','')) ?>">
  </div>

  <!-- Google -->
  <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin:16px 0 10px">Google</div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Google Ads ID</div>
      <div class="cfg-row-desc">ID da conta Google Ads. Encontre em Ferramentas → Acompanhamento de conversões. Formato: <code>AW-123456789</code></div>
    </div>
    <input type="text" name="settings[google_ads_id]" class="form-control" style="width:200px;font-family:monospace"
           placeholder="AW-123456789"
           value="<?= htmlspecialchars($val('google_ads_id','')) ?>">
  </div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Google Ads Conversion Label</div>
      <div class="cfg-row-desc">Label da conversão específica. Encontre junto ao ID da conversão. Formato: <code>AbCdEfGhIjKlMnO</code></div>
    </div>
    <input type="text" name="settings[google_ads_label]" class="form-control" style="width:220px;font-family:monospace"
           placeholder="AbCdEfGhIjKlMnO"
           value="<?= htmlspecialchars($val('google_ads_label','')) ?>">
  </div>

  <div style="padding:12px 14px;background:rgba(66,133,244,.07);border:1px solid rgba(66,133,244,.2);border-radius:8px;font-size:12px;margin:6px 0 14px;line-height:1.8">
    💡 <b style="color:var(--text)">Como funciona:</b> O gtag é carregado <b>apenas</b> para visitantes que chegaram via link do Google Ads
    (com <code>?gclid=</code> ou <code>?gad=</code> na URL). O <code>gclid</code> fica salvo em cookie por 90 dias.<br>
    Para conversões <b>server-side</b> (mais confiável, funciona com ad blockers), preencha os campos da API abaixo.
    Se deixar em branco, a conversão é registrada pelo gtag no browser do usuário — funciona se ele não fechar o browser antes de pagar.
  </div>

  <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin:0 0 10px">Google Ads API (server-side — opcional)</div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Customer ID</div>
      <div class="cfg-row-desc">ID da conta Google Ads sem hífens. Ex: <code>1234567890</code>. Encontre no canto superior direito do Google Ads.</div>
    </div>
    <input type="text" name="settings[google_ads_customer_id]" class="form-control" style="width:180px;font-family:monospace"
           placeholder="1234567890"
           value="<?= htmlspecialchars($val('google_ads_customer_id','')) ?>">
  </div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Conversion Action ID</div>
      <div class="cfg-row-desc">ID numérico da ação de conversão. Encontre em Ferramentas → Conversões → clique na conversão → detalhes. Ex: <code>987654321</code></div>
    </div>
    <input type="text" name="settings[google_ads_conversion_action_id]" class="form-control" style="width:180px;font-family:monospace"
           placeholder="987654321"
           value="<?= htmlspecialchars($val('google_ads_conversion_action_id','')) ?>">
  </div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Developer Token</div>
      <div class="cfg-row-desc">Token de desenvolvedor da API do Google Ads. Solicite em: Google Ads → Ferramentas → Centro de API.</div>
    </div>
    <input type="password" name="settings[google_ads_dev_token]" class="form-control" style="width:280px;font-family:monospace"
           placeholder="ABcDeFgHiJkLmNoPqRsT"
           value="<?= htmlspecialchars($val('google_ads_dev_token','')) ?>">
  </div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">OAuth Token</div>
      <div class="cfg-row-desc">Token de acesso OAuth 2.0 para autenticar as chamadas da API. Gere em: <a href="https://developers.google.com/google-ads/api/docs/oauth/overview" target="_blank" style="color:var(--accent)">Google Ads API OAuth</a>.</div>
    </div>
    <input type="password" name="settings[google_ads_oauth_token]" class="form-control" style="width:280px;font-family:monospace"
           placeholder="ya29.xxxxxxxxx..."
           value="<?= htmlspecialchars($val('google_ads_oauth_token','')) ?>">
  </div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Google Tag Manager ID</div>
      <div class="cfg-row-desc">Se usar GTM para gerenciar tags. Formato: <code>GTM-XXXXXXX</code>. Se preenchido, o GTM é carregado no lugar do pixel direto.</div>
    </div>
    <input type="text" name="settings[gtm_id]" class="form-control" style="width:180px;font-family:monospace"
           placeholder="GTM-XXXXXXX"
           value="<?= htmlspecialchars($val('gtm_id','')) ?>">
  </div>

  <!-- Eventos -->
  <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin:16px 0 10px">Eventos disparados</div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Lead no cadastro</div>
      <div class="cfg-row-desc">Dispara evento <code>Lead</code> quando um novo usuário se cadastra.</div>
    </div>
    <label class="tog-wrap">
      <input type="checkbox" name="settings[track_register]" value="1" <?= ($val('track_register','1')==='1')?'checked':'' ?>>
      <span class="tog-track"></span><span class="tog-thumb"></span>
    </label>
  </div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Purchase na conversão</div>
      <div class="cfg-row-desc">Dispara evento <code>Purchase</code> quando o PIX é confirmado. Envia o valor da venda.</div>
    </div>
    <label class="tog-wrap">
      <input type="checkbox" name="settings[track_purchase]" value="1" <?= ($val('track_purchase','1')==='1')?'checked':'' ?>>
      <span class="tog-track"></span><span class="tog-thumb"></span>
    </label>
  </div>

  <?php if($val('meta_pixel_id','') || $val('google_ads_id','') || $val('gtm_id','')): ?>
  <div style="margin-top:12px;padding:12px 14px;background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.15);border-radius:8px;font-size:12px;color:var(--muted);line-height:1.8">
    ✅ <b style="color:var(--text)">Pixels ativos:</b>
    <?= $val('meta_pixel_id','') ? ' Meta Pixel (' . htmlspecialchars($val('meta_pixel_id','')) . ')' : '' ?>
    <?= $val('meta_access_token','') ? ' + Conversions API' : '' ?>
    <?= $val('google_ads_id','') ? ' · Google Ads (' . htmlspecialchars($val('google_ads_id','')) . ')' : '' ?>
    <?= $val('gtm_id','') ? ' · GTM (' . htmlspecialchars($val('gtm_id','')) . ')' : '' ?>
  </div>
  <?php endif; ?>
</div>

<!-- ══ SUPORTE ═════════════════════════════════ -->
<div class="cfg-section">
  <div class="cfg-section-title">💬 Suporte</div>
  <div class="cfg-section-sub">Configure os canais de atendimento exibidos na página de suporte.</div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Link do Telegram</div>
      <div class="cfg-row-desc">URL completa do seu canal ou bot. Ex: <code>https://t.me/seucanalaqui</code></div>
    </div>
    <input type="url" name="settings[support_telegram]" class="form-control" style="width:280px"
           placeholder="https://t.me/seucanalaqui"
           value="<?= htmlspecialchars($val('support_telegram','')) ?>">
  </div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">E-mail de suporte</div>
      <div class="cfg-row-desc">Endereço de e-mail para contato. Deixe vazio para não exibir.</div>
    </div>
    <input type="email" name="settings[support_email]" class="form-control" style="width:280px"
           placeholder="suporte@seusite.com.br"
           value="<?= htmlspecialchars($val('support_email','')) ?>">
  </div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Horário de atendimento</div>
      <div class="cfg-row-desc">Exibido na página de suporte. Ex: Seg–Sex, 9h–18h</div>
    </div>
    <input type="text" name="settings[support_hours]" class="form-control" style="width:280px"
           placeholder="Seg–Sex, 9h às 18h"
           value="<?= htmlspecialchars($val('support_hours','')) ?>">
  </div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Texto da página de suporte</div>
      <div class="cfg-row-desc">Mensagem exibida no topo da página. Aceita quebras de linha.</div>
    </div>
    <textarea name="settings[support_text]" rows="4"
      style="width:100%;max-width:420px;background:var(--surface2);border:1px solid var(--border);border-radius:9px;padding:10px 12px;color:var(--text);font-size:13px;line-height:1.6;resize:vertical;outline:none;transition:border-color .2s"
      onfocus="this.style.borderColor='var(--accent)'" onblur="this.style.borderColor='var(--border)'"
      placeholder="Olá! Para dúvidas ou problemas, entre em contato pelos canais abaixo..."
    ><?= htmlspecialchars($val('support_text','')) ?></textarea>
  </div>

  <div class="cfg-row">
    <div class="cfg-row-info">
      <div class="cfg-row-label">Formulário de contato</div>
      <div class="cfg-row-desc">Exibe formulário na página de suporte para usuários enviarem mensagens direto para o e-mail de suporte.</div>
    </div>
    <label class="toggle"><input type="checkbox" name="settings[support_contact_form]" value="1" <?= $val('support_contact_form','0')==='1'?'checked':'' ?>><span class="toggle-slider"></span></label>
  </div>

  <?php if ($val('support_telegram','')): ?>
  <div class="warn-box info" style="margin-top:0">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span>Página de suporte: <a href="<?= SITE_URL ?>/suporte" target="_blank" style="color:var(--accent)"><?= SITE_URL ?>/suporte.php</a></span>
  </div>
  <?php endif; ?>
</div>

<!-- Salvar -->
<button type="submit" class="btn btn-primary" style="width:100%;padding:14px;justify-content:center;font-size:15px">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px;height:16px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
  Salvar todas as configurações
</button>
</form>

<!-- Testar SMTP (fora do form principal) -->
<form method="POST" style="margin-top:10px">
  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
  <input type="hidden" name="test_smtp" value="1">
  <button type="submit" class="btn btn-secondary" style="width:100%;justify-content:center">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.91a16 16 0 0 0 6 6l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.73 16.92z"/></svg>
    Enviar e-mail de teste para <?= htmlspecialchars(currentUser()['email']) ?>
  </button>
</form>
</div>

<script>
function step(k, d) {
  const i = document.getElementById('inp-' + k);
  i.value = Math.max(1, Math.min(200, (parseInt(i.value)||1) + d));
}
</script>

<!-- Backup -->
<div class="card" style="padding:22px;margin-top:20px">
  <div style="font-family:'Roboto',sans-serif;font-size:15px;font-weight:700;margin-bottom:6px">💾 Backup do Banco de Dados</div>
  <div style="font-size:13px;color:var(--muted2);margin-bottom:16px">
    Gera um arquivo <code>.sql</code> com toda a estrutura e dados do banco. Guarde em local seguro.
  </div>
  <form method="POST" onsubmit="this.querySelector('button').textContent='⏳ Gerando...'">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <button type="submit" name="do_backup" value="1" class="btn btn-secondary" style="padding:10px 20px;font-size:13px;color:var(--success);border-color:rgba(16,185,129,.3)">
      💾 Baixar backup agora
    </button>
  </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
