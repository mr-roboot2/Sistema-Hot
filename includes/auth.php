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
    $expiry   = time() + 90 * 86400;
    $isHttps  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    // HttpOnly=false porque pixel JS precisa ler esses cookies no cliente
    $opts   = ['path' => '/', 'samesite' => 'Lax', 'secure' => $isHttps, 'httponly' => false];

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

// Garante que colunas opcionais (access_code, is_anonymous) existam antes de
// currentUser() fazer o SELECT. Sem isso o SELECT falha em bancos pré-existentes
// e gera loop de redirect (session_destroy → /login → ...).
ensureAccessCodeColumn();

// Auto-login via cookie "remember me" — só dispara se não há NENHUMA sessão
// (nem ativa, nem expirada, nem pendente). Evita re-entrar em loops de redirect.
if (empty($_SESSION['user_id'])
    && empty($_SESSION['expired_user_id'])
    && empty($_SESSION['pending_user_id'])
    && !empty($_COOKIE['remember_me'])) {
    tryRememberLogin();
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function ensureRememberTokensTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            selector VARCHAR(32) NOT NULL UNIQUE,
            validator_hash VARCHAR(64) NOT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rt_user (user_id)
        )");
    } catch(Exception $e) {}
}

/**
 * Cria um token remember-me para o usuário e seta cookie com 30 dias.
 * Usa o padrão selector:validator — o selector é armazenado em claro (para lookup),
 * o validator é comparado via hash (não reconstruível a partir do DB).
 */
function setRememberMe(int $userId, int $days = 30): void {
    ensureRememberTokensTable();
    $selector  = bin2hex(random_bytes(12));   // 24 chars
    $validator = bin2hex(random_bytes(32));   // 64 chars
    $hash      = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + $days * 86400);

    try {
        getDB()->prepare('INSERT INTO remember_tokens (user_id,selector,validator_hash,ip,user_agent,expires_at) VALUES (?,?,?,?,?,?)')
            ->execute([
                $userId, $selector, $hash,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                $expiresAt,
            ]);
    } catch(Exception $e) { return; }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    setcookie('remember_me', $selector . ':' . $validator, [
        'expires'  => time() + $days * 86400,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['remember_me'] = $selector . ':' . $validator;
}

/**
 * Valida cookie remember-me e recria a sessão se o token for válido.
 * Faz rotação do token (invalida o atual e emite um novo) a cada auto-login.
 */
function tryRememberLogin(): bool {
    $cookie = $_COOKIE['remember_me'] ?? '';
    if (!$cookie || strpos($cookie, ':') === false) return false;

    [$selector, $validator] = explode(':', $cookie, 2);
    if (strlen($selector) < 16 || strlen($validator) < 32) {
        clearRememberMe(); return false;
    }

    try {
        ensureRememberTokensTable();
        $db = getDB();
        // Remove expirados
        try { $db->exec("DELETE FROM remember_tokens WHERE expires_at < NOW()"); } catch(Exception $e) {}

        $stmt = $db->prepare('SELECT * FROM remember_tokens WHERE selector=? LIMIT 1');
        $stmt->execute([$selector]);
        $row = $stmt->fetch();
        if (!$row) { clearRememberMe(); return false; }

        $expectedHash = hash('sha256', $validator);
        if (!hash_equals($row['validator_hash'], $expectedHash)) {
            // Token pode ter sido comprometido — invalida TODOS os tokens do user
            try { $db->prepare('DELETE FROM remember_tokens WHERE user_id=?')->execute([$row['user_id']]); } catch(Exception $e) {}
            clearRememberMe();
            return false;
        }

        $u = $db->prepare('SELECT id,name,role,suspended_at,expires_at FROM users WHERE id=?');
        $u->execute([$row['user_id']]); $u = $u->fetch();
        if (!$u || !empty($u['suspended_at'])) {
            try { $db->prepare('DELETE FROM remember_tokens WHERE id=?')->execute([$row['id']]); } catch(Exception $e) {}
            clearRememberMe();
            return false;
        }

        // Rotação — invalida o atual e emite um novo token
        try { $db->prepare('DELETE FROM remember_tokens WHERE id=?')->execute([$row['id']]); } catch(Exception $e) {}
        $daysLeft = max(7, (int)ceil((strtotime($row['expires_at']) - time()) / 86400));
        setRememberMe((int)$u['id'], $daysLeft);

        session_regenerate_id(true);

        // Plano expirado → só marca expired_user_id para o fluxo de renovação
        // (evita loop com checkExpiry que redirecionaria para /expired)
        if (!empty($u['expires_at']) && strtotime($u['expires_at']) < time() && $u['role'] !== 'admin') {
            $_SESSION['expired_user_id'] = (int)$u['id'];
            return false;
        }

        $_SESSION['user_id']   = (int)$u['id'];
        $_SESSION['user_name'] = $u['name'];
        $_SESSION['user_role'] = $u['role'];

        return true;
    } catch(Exception $e) {
        return false;
    }
}

/**
 * Remove o cookie remember-me e o registro correspondente no DB.
 */
function clearRememberMe(): void {
    $cookie = $_COOKIE['remember_me'] ?? '';
    if ($cookie && strpos($cookie, ':') !== false) {
        [$selector] = explode(':', $cookie, 2);
        try { getDB()->prepare('DELETE FROM remember_tokens WHERE selector=?')->execute([$selector]); } catch(Exception $e) {}
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!headers_sent()) {
        setcookie('remember_me', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    unset($_COOKIE['remember_me']);
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
                // Token não existe mais — foi revogado por outro login.
                // Limpa só o auth (não chama session_destroy: se o storage falhar
                // em confirmar a destruição, o user_id volta no próximo request
                // e o /login redireciona pro /index = loop).
                unset(
                    $_SESSION['user_id'], $_SESSION['user_name'],
                    $_SESSION['user_role'], $_SESSION['session_token']
                );
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
    // Preserva contexto de impersonação para que o admin consiga voltar
    $imp = [
        'id'   => $_SESSION['impersonator_user_id']   ?? null,
        'name' => $_SESSION['impersonator_user_name'] ?? null,
        'role' => $_SESSION['impersonator_user_role'] ?? null,
    ];
    $_SESSION = []; session_destroy(); session_start();
    $_SESSION['expired_user_id'] = $uid;
    if ($imp['id']) {
        $_SESSION['impersonator_user_id']   = $imp['id'];
        $_SESSION['impersonator_user_name'] = $imp['name'];
        $_SESSION['impersonator_user_role'] = $imp['role'];
    }
    header('Location: ' . SITE_URL . '/expired');
    exit;
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    static $user = null;
    if ($user === null) {
        $db     = getDB();
        $userId = (int)$_SESSION['user_id'];

        // Tentativa 1 — schema completo (com access_code/is_anonymous)
        try {
            $stmt = $db->prepare('SELECT u.id,u.name,u.email,u.phone,u.role,u.plan_id,u.expires_at,u.expired_notified,u.expiry_warned,u.access_code,u.is_anonymous,p.name AS plan_name,p.color AS plan_color
                FROM users u LEFT JOIN plans p ON u.plan_id=p.id WHERE u.id=?');
            $stmt->execute([$userId]);
            $user = $stmt->fetch() ?: null;
        } catch(Exception $e) { $user = null; }

        // Tentativa 2 — sem access_code/is_anonymous
        if ($user === null) {
            try {
                $stmt = $db->prepare('SELECT u.id,u.name,u.email,u.phone,u.role,u.plan_id,u.expires_at,u.expired_notified,u.expiry_warned,p.name AS plan_name,p.color AS plan_color
                    FROM users u LEFT JOIN plans p ON u.plan_id=p.id WHERE u.id=?');
                $stmt->execute([$userId]);
                $user = $stmt->fetch() ?: null;
                if ($user) { $user['access_code'] = null; $user['is_anonymous'] = 0; }
            } catch(Exception $e) { $user = null; }
        }

        // Tentativa 3 — schema mínimo (banco antigo sem expiry_warned/expired_notified/phone)
        if ($user === null) {
            try {
                $stmt = $db->prepare('SELECT id,name,email,role FROM users WHERE id=?');
                $stmt->execute([$userId]);
                $user = $stmt->fetch() ?: null;
                if ($user) {
                    $user += [
                        'phone' => null, 'plan_id' => null, 'expires_at' => null,
                        'expired_notified' => 0, 'expiry_warned' => 0,
                        'access_code' => null, 'is_anonymous' => 0,
                        'plan_name' => null, 'plan_color' => null,
                    ];
                }
            } catch(Exception $e) { $user = null; }
        }

        if (!$user) {
            // Usuário realmente não existe (deletado) ou DB inacessível.
            // NÃO chamar session_destroy: se o storage falhar em confirmar,
            // o cookie volta com user_id e cai em loop /login ↔ /index.
            // Em vez disso, limpa só os campos de auth e segue pro /login.
            unset(
                $_SESSION['user_id'], $_SESSION['user_name'],
                $_SESSION['user_role'], $_SESSION['session_token']
            );
            if (!headers_sent()) {
                header('Location: ' . SITE_URL . '/login');
                header('Cache-Control: no-cache, must-revalidate');
            }
            exit;
        }
    }
    return $user;
}

function isAdmin(): bool {
    $u = currentUser(); return $u && in_array($u['role'], ['admin','editor']);
}

function login(string $e, string $p): bool { return loginExtended($e,$p)['ok']; }

/**
 * Gera um código de acesso único no formato XXXX-XXXX-XXXX (12 chars alfanuméricos sem caracteres ambíguos).
 */
function ensureAccessCodeColumn(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = getDB();

    $existing = [];
    try {
        $stmt = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) $existing[strtolower($col)] = true;
    } catch(Exception $e) {}

    $needed = [
        'access_code'    => "ALTER TABLE users ADD COLUMN access_code VARCHAR(20) DEFAULT NULL",
        'is_anonymous'   => "ALTER TABLE users ADD COLUMN is_anonymous TINYINT(1) DEFAULT 0",
        'first_login_at' => "ALTER TABLE users ADD COLUMN first_login_at DATETIME DEFAULT NULL",
        'created_by'     => "ALTER TABLE users ADD COLUMN created_by INT DEFAULT NULL",
    ];
    foreach ($needed as $col => $sql) {
        if (isset($existing[$col])) continue;
        // Tolerante: a função roda em TODA request via auth.php. Se faltar
        // permissão ou der race, não pode quebrar a página inteira.
        try { $db->exec($sql); } catch(Exception $e) {}
    }
    try { $db->exec("CREATE INDEX idx_users_access_code ON users (access_code)"); } catch(Exception $e) {}
}

function generateAccessCode(): string {
    ensureAccessCodeColumn();
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem 0/O/1/I
    $db = getDB();
    for ($tries = 0; $tries < 10; $tries++) {
        $raw = '';
        for ($i = 0; $i < 12; $i++) $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
        $chk = $db->prepare('SELECT id FROM users WHERE access_code=? LIMIT 1');
        $chk->execute([$code]);
        if (!$chk->fetch()) return $code;
    }
    // Fallback extremamente improvável — usa timestamp pra garantir unicidade
    return 'ACS-' . strtoupper(bin2hex(random_bytes(4))) . '-' . dechex(time() % 0xFFFF);
}

/**
 * Login apenas com código de acesso (usuários anônimos). Retorna mesmo formato de loginExtended().
 */
function loginByAccessCode(string $code): array {
    ensureAccessCodeColumn();
    $ip       = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip       = trim(explode(',', $ip)[0]);
    $lockKey  = 'login_fail_' . md5($ip);
    $attempts = (int)($_SESSION[$lockKey . '_count'] ?? 0);
    $lastFail = (int)($_SESSION[$lockKey . '_time']  ?? 0);
    if ($lastFail && (time() - $lastFail) > 900) {
        $attempts = 0;
        unset($_SESSION[$lockKey . '_count'], $_SESSION[$lockKey . '_time']);
    }
    if ($attempts >= 5) {
        return ['ok'=>false,'reason'=>'locked','wait'=>max(0, 900 - (time() - $lastFail))];
    }

    $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code));
    if (strlen($normalized) !== 12) {
        $_SESSION[$lockKey . '_count'] = $attempts + 1;
        $_SESSION[$lockKey . '_time']  = time();
        return ['ok'=>false,'reason'=>'invalid','remaining'=>max(0, 4 - $attempts)];
    }
    $formatted = substr($normalized, 0, 4) . '-' . substr($normalized, 4, 4) . '-' . substr($normalized, 8, 4);

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE access_code=? LIMIT 1');
    $stmt->execute([$formatted]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION[$lockKey . '_count'] = $attempts + 1;
        $_SESSION[$lockKey . '_time']  = time();
        return ['ok'=>false,'reason'=>'invalid','remaining'=>max(0, 4 - $attempts)];
    }

    unset($_SESSION[$lockKey . '_count'], $_SESSION[$lockKey . '_time']);

    if (!empty($user['suspended_at'])) return ['ok'=>false,'reason'=>'suspended'];

    // Ativação no primeiro uso: se o código foi gerado sem expires_at,
    // calcula agora baseado no duration_days do plano vinculado.
    if (empty($user['first_login_at'])) {
        $newExpires = null;
        if (empty($user['expires_at']) && !empty($user['plan_id'])) {
            try {
                $p = $db->prepare('SELECT duration_days FROM plans WHERE id=?');
                $p->execute([$user['plan_id']]);
                $dur = (int)$p->fetchColumn();
                if ($dur > 0) $newExpires = date('Y-m-d H:i:s', time() + $dur * 86400);
            } catch(Exception $e) {}
        }
        try {
            if ($newExpires) {
                $db->prepare('UPDATE users SET first_login_at=NOW(), expires_at=? WHERE id=?')
                   ->execute([$newExpires, $user['id']]);
                $user['expires_at'] = $newExpires;
            } else {
                $db->prepare('UPDATE users SET first_login_at=NOW() WHERE id=?')->execute([$user['id']]);
            }
        } catch(Exception $e) {}
    }

    if (!empty($user['expires_at']) && strtotime($user['expires_at']) < time() && $user['role'] !== 'admin') {
        // Abre sessão parcial para que /expired → /renovar consiga identificar o usuário
        session_regenerate_id(true);
        $_SESSION['expired_user_id'] = (int)$user['id'];
        return ['ok'=>false,'reason'=>'expired'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];

    $maxSessions = (int)readSettingDirect('max_sessions', '0');
    if ($maxSessions > 0 && $user['role'] !== 'admin') {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS user_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
                session_token VARCHAR(64) NOT NULL UNIQUE, ip VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_us_user (user_id), INDEX idx_us_token (session_token)
            )");
        } catch(Exception $e) {}
        try { $db->exec("DELETE FROM user_sessions WHERE last_seen < DATE_SUB(NOW(), INTERVAL 12 HOUR)"); } catch(Exception $e) {}
        $count = $db->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id=?');
        $count->execute([$user['id']]);
        $active = (int)$count->fetchColumn();
        if ($active >= $maxSessions) {
            $oldest = $db->prepare('SELECT id FROM user_sessions WHERE user_id=? ORDER BY last_seen ASC LIMIT ?');
            $oldest->execute([$user['id'], $active - $maxSessions + 1]);
            foreach ($oldest->fetchAll() as $old) {
                $db->prepare('DELETE FROM user_sessions WHERE id=?')->execute([$old['id']]);
            }
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['session_token'] = $token;
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $db->prepare('INSERT INTO user_sessions (user_id, session_token, ip, user_agent) VALUES (?,?,?,?)')
           ->execute([$user['id'], $token, $ip, $ua]);
    }

    return ['ok'=>true];
}

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
    clearRememberMe();
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
