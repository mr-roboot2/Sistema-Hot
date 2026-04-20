<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    // Aplica Redis se configurado e disponível
    try {
        if (extension_loaded('redis')) {
            $rEnabled = readSettingDirect('redis_enabled', '0');
            if ($rEnabled === '1') {
                $rHost = readSettingDirect('redis_host', '127.0.0.1');
                $rPort = readSettingDirect('redis_port', '6379');
                $rPass = readSettingDirect('redis_password', '');
                $savePath = "tcp://{$rHost}:{$rPort}" . ($rPass ? "?auth={$rPass}" : '');
                ini_set('session.save_handler', 'redis');
                ini_set('session.save_path', $savePath);
            }
        }
    } catch(Exception $e) {}
    session_start();
}

// ── Captura fbclid/gclid ANTES de qualquer redirect ───────────
// Sem isso, o cookie não é salvo se houver redirect na mesma request
(function() {
    $expiry = time() + 90 * 86400;
    $opts   = ['path' => '/', 'samesite' => 'Lax'];

    // Meta — ?fbclid= (padrão) ou ?fb= (link curto personalizado)
    $fbclid = $_GET['fbclid'] ?? $_GET['fb'] ?? '';
    if ($fbclid && empty($_COOKIE['_fbc_cms'])) {
        setcookie('_fbc_cms', $fbclid, array_merge($opts, ['expires' => $expiry]));
        setcookie('_fbc_ts',  (string)time(), array_merge($opts, ['expires' => $expiry]));
        $_COOKIE['_fbc_cms'] = $fbclid; // disponibiliza na mesma request
    }

    // Google Ads — ?gclid=
    $gclid = $_GET['gclid'] ?? $_GET['gad'] ?? '';
    if ($gclid && empty($_COOKIE['_gcl_cms'])) {
        setcookie('_gcl_cms', $gclid, array_merge($opts, ['expires' => $expiry]));
        setcookie('_gcl_ts',  (string)time(), array_merge($opts, ['expires' => $expiry]));
        $_COOKIE['_gcl_cms'] = $gclid;
    }
})();

// Garante que PHP e MySQL usam o mesmo fuso
try { getDB()->exec("SET time_zone = '" . date('P') . "'"); } catch(Exception $e) {}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function readSettingDirect(string $key, string $default = ''): string {
    try {
        $stmt = getDB()->prepare('SELECT value FROM settings WHERE key_name=? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return ($row !== false) ? (string)$row['value'] : $default;
    } catch (Exception $e) { return $default; }
}

// Páginas liberadas sem plano pago
const PAYMENT_FREE_PAGES = [
    'bem-vindo.php','renovar.php','carteira.php',
    'logout.php','check-access.php','helix-callback.php',
    'expired.php','login.php','register.php',
    'suporte.php',
    'suporte-poll.php',
    'minha-indicacao.php',
    'favoritos.php',
    'ajax-favorito.php',
    'download.php',
];

function isPaymentFreePage(): bool {
    return in_array(basename($_SERVER['PHP_SELF']), PAYMENT_FREE_PAGES);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        $required    = (readSettingDirect('require_login', '1') === '1');
        $previewMode = (readSettingDirect('preview_mode',  '0') === '1');

        // Modo preview ativo
        if (!$required && $previewMode) {
            $page = basename($_SERVER['PHP_SELF']);
            if ($page === 'view.php') {
                $limit   = max(1, (int)readSettingDirect('preview_post_limit', '3'));
                $seen    = (int)($_COOKIE['_preview_seen'] ?? 0);
                $postId  = (int)($_GET['id'] ?? 0);
                $seenIds = array_filter(explode(',', $_COOKIE['_preview_ids'] ?? ''));
                if ($postId && !in_array((string)$postId, $seenIds)) {
                    $seenIds[] = (string)$postId;
                    $seen++;
                    $opts = ['expires' => time() + 30 * 86400, 'path' => '/', 'samesite' => 'Lax'];
                    if (!headers_sent()) {
                        setcookie('_preview_seen', (string)$seen, $opts);
                        setcookie('_preview_ids',  implode(',', array_slice($seenIds, -50)), $opts);
                    }
                }
                define('PREVIEW_BLOCKED', $seen > $limit);
            }
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            return;
        }

        if ($required) {
            try {
                $n = (int)getDB()->query('SELECT COUNT(*) FROM plans WHERE active=1 AND price > 0')->fetchColumn();
                $dest = $n > 0 ? SITE_URL.'/bem-vindo' : SITE_URL.'/login';
            } catch(Exception $e) { $dest = SITE_URL.'/login'; }

            // Preserva fbclid/gclid no redirect para o pixel carregar na próxima página
            $qs = [];
            if (!empty($_GET['fbclid'])) $qs[] = 'fbclid=' . urlencode($_GET['fbclid']);
            elseif (!empty($_GET['fb']))  $qs[] = 'fb='     . urlencode($_GET['fb']);
            if (!empty($_GET['gclid']))  $qs[] = 'gclid='  . urlencode($_GET['gclid']);
            elseif (!empty($_GET['gad'])) $qs[] = 'gad='   . urlencode($_GET['gad']);
            if ($qs) $dest .= '?' . implode('&', $qs);

            header('Location: ' . $dest);
            header('Cache-Control: no-cache, must-revalidate');
            exit;
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        return;
    }
    checkExpiry();
    checkPaymentStatus();
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}

function requireLoginAlways(): void {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login');
        header('Cache-Control: no-cache, must-revalidate');
        exit;
    }
    checkExpiry();
    checkPaymentStatus();
    // Fecha sessão após verificar auth — libera o lock para uploads paralelos não bloquearem o site
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}

/**
 * Bloqueia usuário que não tem plano ativo.
 * Único critério: expires_at no futuro OU admin.
 * Se não tem plano: só pode acessar páginas de pagamento.
 */
function checkPaymentStatus(): void {
    if (!isLoggedIn()) return;
    if (isPaymentFreePage()) return;

    $user = currentUser();
    if (!$user || $user['role'] === 'admin') return;

    // ── Verifica se sessão foi revogada (desconectado em outro lugar) ──
    $maxSessions = (int)readSettingDirect('max_sessions', '0');
    if ($maxSessions > 0 && !empty($_SESSION['session_token'])) {
        try {
            $db    = getDB();
            $token = $_SESSION['session_token'];
            $valid = $db->prepare('SELECT id FROM user_sessions WHERE session_token=? AND user_id=?');
            $valid->execute([$token, $user['id']]);
            if (!$valid->fetch()) {
                // Token não existe mais — foi revogado por outro login
                // Salva flag na sessão para mostrar aviso
                $_SESSION['kicked_out'] = true;
                $_SESSION = [];
                session_destroy();
                session_start();
                $_SESSION['kicked_out'] = true;
                header('Location: ' . SITE_URL . '/login?kicked=1');
                header('Cache-Control: no-cache, must-revalidate');
                exit;
            }
            // Atualiza last_seen
            $db->prepare('UPDATE user_sessions SET last_seen=NOW() WHERE session_token=?')->execute([$token]);
        } catch(Exception $e) {}
    }

    // Tem plano ativo (expires_at no futuro)?
    $hasActive = !empty($user['expires_at']) && strtotime($user['expires_at']) > time();
    if ($hasActive) return; // OK

    // Sem plano ativo — redireciona para carteira
    header('Location: ' . SITE_URL . '/carteira');
    header('Cache-Control: no-cache, must-revalidate');
    exit;
}

function checkExpiry(): void {
    if (!isLoggedIn()) return;
    $user = currentUser();
    if (!$user || $user['role'] === 'admin') return;
    if (empty($user['expires_at'])) return;

    $expiresTs = strtotime($user['expires_at']);
    $now       = time();

    // Aviso de expiração iminente (3 dias antes) — envia uma vez
    if ($expiresTs > $now) {
        $daysLeft = ($expiresTs - $now) / 86400;
        if ($daysLeft <= 3 && empty($user['expiry_warned'])) {
            try {
                try {
                    getDB()->prepare('UPDATE users SET expiry_warned=1 WHERE id=?')->execute([$user['id']]);
                } catch(Exception $e) {}
                $site = getSetting('site_name', SITE_NAME);
                $dias = ceil($daysLeft);
                $html = "<div style='font-family:sans-serif;padding:32px;background:#0a0a0f;color:#e8e8f0;border-radius:12px'>
                  <h2 style='color:#f59e0b'>⚠️ Seu acesso expira em breve</h2>
                  <p style='color:#9090a8;margin-top:8px'>Olá <b style='color:#e8e8f0'>{$user['name']}</b>, seu plano em <b>{$site}</b> expira em <b style='color:#f59e0b'>{$dias} dia" . ($dias > 1 ? 's' : '') . "</b>.</p>
                  <p style='margin-top:16px'><a href='" . SITE_URL . "/renovar.php' style='background:#7c6aff;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700'>Renovar agora</a></p>
                </div>";
                sendMail($user['email'], "Seu acesso expira em {$dias} dia" . ($dias > 1 ? 's' : '') . " — {$site}", $html);
            } catch(Exception $e) {}
        }
        return; // ainda válido
    }

    // Expirou — envia e-mail de notificação
    if (empty($user['expired_notified'])) {
        try {
            getDB()->prepare('UPDATE users SET expired_notified=1 WHERE id=?')->execute([$user['id']]);
            $site = getSetting('site_name', SITE_NAME);
            $html = "<div style='font-family:sans-serif;padding:32px;background:#0a0a0f;color:#e8e8f0;border-radius:12px'>
              <h2 style='color:#ff6a9e'>Acesso expirado</h2>
              <p style='color:#9090a8;margin-top:8px'>Olá <b style='color:#e8e8f0'>{$user['name']}</b>, seu plano em <b>{$site}</b> expirou.</p>
              <p style='margin-top:16px'><a href='" . SITE_URL . "/renovar.php' style='background:#7c6aff;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700'>Renovar agora</a></p>
            </div>";
            sendMail($user['email'], "Acesso expirado — {$site}", $html);
        } catch(Exception $e) {}
    }

    // Em páginas de pagamento mantém a sessão intacta
    if (isPaymentFreePage()) return;

    $uid = $user['id'];
    $_SESSION = []; session_destroy(); session_start();
    $_SESSION['expired_user_id'] = $uid;
    header('Location: ' . SITE_URL . '/expired');
    exit;
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    static $user = null;
    if ($user === null) {
        try {
            $stmt = getDB()->prepare('SELECT u.id,u.name,u.email,u.phone,u.role,u.plan_id,u.expires_at,u.expired_notified,u.expiry_warned,p.name AS plan_name,p.color AS plan_color
                FROM users u LEFT JOIN plans p ON u.plan_id=p.id WHERE u.id=?');
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch() ?: null;
        } catch(Exception $e) { $user = null; }
        if (!$user) { session_destroy(); header('Location: '.SITE_URL.'/login'); exit; }
    }
    return $user;
}

function isAdmin(): bool {
    $u = currentUser(); return $u && in_array($u['role'], ['admin','editor']);
}

function login(string $e, string $p): bool { return loginExtended($e,$p)['ok']; }

function loginExtended(string $identifier, string $password): array {
    // Brute force protection — máx 5 tentativas por IP em 15 minutos
    $ip       = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip       = trim(explode(',', $ip)[0]);
    $lockKey  = 'login_fail_' . md5($ip);
    $attempts = (int)($_SESSION[$lockKey . '_count'] ?? 0);
    $lastFail = (int)($_SESSION[$lockKey . '_time']  ?? 0);

    // Reset contador se passou 15 minutos
    if ($lastFail && (time() - $lastFail) > 900) {
        $attempts = 0;
        unset($_SESSION[$lockKey . '_count'], $_SESSION[$lockKey . '_time']);
    }

    if ($attempts >= 5) {
        $wait = 900 - (time() - $lastFail);
        return ['ok'=>false,'reason'=>'locked','wait'=>max(0,$wait)];
    }

    $db    = getDB();
    // Aceita telefone (só dígitos) ou e-mail
    $phone = preg_replace('/\D/', '', $identifier);
    $stmt  = $db->prepare('SELECT * FROM users WHERE phone=? OR email=? LIMIT 1');
    $stmt->execute([$phone ?: '__none__', strtolower(trim($identifier))]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        $_SESSION[$lockKey . '_count'] = $attempts + 1;
        $_SESSION[$lockKey . '_time']  = time();
        $remaining = 4 - $attempts;
        return ['ok'=>false,'reason'=>'invalid','remaining'=>max(0,$remaining)];
    }

    // Login bem sucedido — reseta contador
    unset($_SESSION[$lockKey . '_count'], $_SESSION[$lockKey . '_time']);

    // Verifica suspensão
    if (!empty($user['suspended_at'])) {
        return ['ok'=>false,'reason'=>'suspended'];
    }

    if (!empty($user['expires_at']) && strtotime($user['expires_at']) < time() && $user['role'] !== 'admin')
        return ['ok'=>false,'reason'=>'expired'];

    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];

    // ── Controle de sessões simultâneas ──────────────────────
    $maxSessions = (int)readSettingDirect('max_sessions', '0');
    if ($maxSessions > 0 && $user['role'] !== 'admin') {
        $db = getDB();
        // Cria tabela de tokens se não existir
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS user_sessions (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                user_id      INT NOT NULL,
                session_token VARCHAR(64) NOT NULL UNIQUE,
                ip           VARCHAR(45) DEFAULT NULL,
                user_agent   VARCHAR(255) DEFAULT NULL,
                created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_seen    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_us_user (user_id),
                INDEX idx_us_token (session_token)
            )");
        } catch(Exception $e) {}

        // Remove sessões expiradas (mais de 12h sem atividade)
        try { $db->exec("DELETE FROM user_sessions WHERE last_seen < DATE_SUB(NOW(), INTERVAL 12 HOUR)"); } catch(Exception $e) {}

        // Conta sessões ativas deste usuário
        $count = $db->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id=?');
        $count->execute([$user['id']]);
        $active = (int)$count->fetchColumn();

        if ($active >= $maxSessions) {
            // Remove a(s) sessão(ões) mais antiga(s) para abrir espaço
            $oldest = $db->prepare('SELECT id FROM user_sessions WHERE user_id=? ORDER BY last_seen ASC LIMIT ?');
            $oldest->execute([$user['id'], $active - $maxSessions + 1]);
            foreach ($oldest->fetchAll() as $old) {
                $db->prepare('DELETE FROM user_sessions WHERE id=?')->execute([$old['id']]);
            }
            // Marca que este usuário foi desconectado em outro lugar
            try {
                $db->exec("ALTER TABLE users ADD COLUMN session_kicked TINYINT(1) DEFAULT 0");
            } catch(Exception $e) {}
            $db->prepare('UPDATE users SET session_kicked=1 WHERE id=?')->execute([$user['id']]);
        }

        // Salva nova sessão
        $token = bin2hex(random_bytes(32));
        $_SESSION['session_token'] = $token;
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $db->prepare('INSERT INTO user_sessions (user_id, session_token, ip, user_agent) VALUES (?,?,?,?)')
           ->execute([$user['id'], $token, $ip, $ua]);
    }

    return ['ok'=>true];
}

function logout(): void {
    // Remove token de sessão do banco
    if (!empty($_SESSION['session_token'])) {
        try {
            getDB()->prepare('DELETE FROM user_sessions WHERE session_token=?')
                   ->execute([$_SESSION['session_token']]);
        } catch(Exception $e) {}
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
    }
    session_destroy();
    header('Location: '.SITE_URL.'/login'); exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrf_verify(string $token): bool {
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
