<?php
// ============================================================
// config.php — MediaCMS
// ============================================================
// ⚠️  ANTES DE COLOCAR ONLINE, ajuste as linhas marcadas com ⚠️
// ============================================================

// ── Banco de Dados ────────────────────────────
define('DB_HOST',    'localhost');       // ⚠️ geralmente 'localhost' na hospedagem
define('DB_NAME',    'cms_db');          // ⚠️ nome do banco criado na hospedagem
define('DB_USER',    'root');            // ⚠️ usuário do banco (não use root em produção)
define('DB_PASS',    '');               // ⚠️ senha do banco
define('DB_CHARSET', 'utf8mb4');

// ── Site ──────────────────────────────────────
define('SITE_NAME', 'MediaCMS');        // Nome padrão (pode alterar nas Configurações)
define('SITE_URL',  'http://localhost/cms'); // ⚠️ URL do seu site ex: https://meusite.com.br

// ── Uploads ───────────────────────────────────
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');
define('MAX_FILE_SIZE', 500 * 1024 * 1024); // 500 MB

// ── Tipos de arquivo permitidos ───────────────
define('ALLOWED_IMAGES', ['jpg','jpeg','png','gif','webp','svg']);
define('ALLOWED_VIDEOS', ['mp4','webm','ogg','mov','avi','mkv']);
define('ALLOWED_FILES',  []);

// ── Redis para sessões (opcional) ────────────
// Se Redis estiver disponível, sessões ficam em memória em vez de arquivos
// Elimina lock de arquivo com muitos usuários simultâneos
// Para ativar: instale php-redis e configure REDIS_HOST
define('REDIS_HOST', '');   // ⚠️ ex: '127.0.0.1' — deixe vazio para desativar
define('REDIS_PORT', 6379);
define('REDIS_PASS', '');   // deixe vazio se sem senha

if (REDIS_HOST && extension_loaded('redis') && session_status() === PHP_SESSION_NONE) {
    try {
        $ini = 'tcp://' . REDIS_HOST . ':' . REDIS_PORT;
        if (REDIS_PASS) $ini .= '?auth=' . REDIS_PASS;
        ini_set('session.save_handler', 'redis');
        ini_set('session.save_path',    $ini);
    } catch (Exception $e) {} // Fallback para arquivo se Redis falhar
}

// ── Banco ─────────────────────────────────────
function ensureIndexes(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $indexes = [
        // posts — filtragens mais frequentes
        "CREATE INDEX IF NOT EXISTS idx_posts_status ON posts(status)",
        "CREATE INDEX IF NOT EXISTS idx_posts_status_created ON posts(status, created_at DESC)",
        "CREATE INDEX IF NOT EXISTS idx_posts_category ON posts(category_id)",
        "CREATE INDEX IF NOT EXISTS idx_posts_user ON posts(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_posts_featured ON posts(featured, status)",
        // media — joins por post_id e tipo
        "CREATE INDEX IF NOT EXISTS idx_media_post_type ON media(post_id, file_type)",
        // transactions — consultas financeiras
        "CREATE INDEX IF NOT EXISTS idx_tx_user ON transactions(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_tx_status ON transactions(status)",
        "CREATE INDEX IF NOT EXISTS idx_tx_created ON transactions(created_at)",
        "CREATE INDEX IF NOT EXISTS idx_tx_external ON transactions(external_id)",
        // users — filtros de admin
        "CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)",
        "CREATE INDEX IF NOT EXISTS idx_users_expires ON users(expires_at)",
        "CREATE INDEX IF NOT EXISTS idx_users_plan ON users(plan_id)",
        // post_views — cooldown check
        "CREATE INDEX IF NOT EXISTS idx_pv_post_ip ON post_views(post_id, ip_hash)",
        // referrals
        "CREATE INDEX IF NOT EXISTS idx_ref_status ON referrals(status)",
    ];
    foreach ($indexes as $sql) {
        try { $pdo->exec($sql); } catch(Exception $e) {}
    }
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => true, // Reutiliza conexões TCP — reduz latência
            ]);
            // Cria índices se não existirem (melhora performance das queries)
            static $idxDone = false;
            if (!$idxDone) {
                $idxDone = true;
                $indexes = [
                    "CREATE INDEX IF NOT EXISTS idx_posts_status    ON posts(status)",
                    "CREATE FULLTEXT INDEX IF NOT EXISTS idx_posts_ft ON posts(title, description)",
                    "CREATE INDEX IF NOT EXISTS idx_posts_featured  ON posts(featured, status)",
                    "CREATE INDEX IF NOT EXISTS idx_posts_cat       ON posts(category_id)",
                    "CREATE INDEX IF NOT EXISTS idx_posts_user      ON posts(user_id)",
                    "CREATE INDEX IF NOT EXISTS idx_media_post      ON media(post_id)",
                    "CREATE INDEX IF NOT EXISTS idx_media_type      ON media(file_type)",
                    "CREATE INDEX IF NOT EXISTS idx_users_role      ON users(role)",
                    "CREATE INDEX IF NOT EXISTS idx_users_expires   ON users(expires_at)",
                    "CREATE INDEX IF NOT EXISTS idx_tx_user         ON transactions(user_id)",
                    "CREATE INDEX IF NOT EXISTS idx_tx_status       ON transactions(status)",
                    "CREATE INDEX IF NOT EXISTS idx_tx_created     ON transactions(created_at)",
                    "CREATE INDEX IF NOT EXISTS idx_users_phone     ON users(phone)",
                ];
                // Garante colunas opcionais
                try { $pdo->exec("ALTER TABLE users ADD COLUMN expiry_warned TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
                try { $pdo->exec("ALTER TABLE media ADD COLUMN video_thumb VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}
                foreach ($indexes as $sql) {
                    try { $pdo->exec($sql); } catch(Exception $e) {}
                }
            }
        } catch (PDOException $e) {
            if (strpos(SITE_URL, 'localhost') === false) {
                die('<div style="font-family:sans-serif;padding:40px;text-align:center"><h2>Serviço temporariamente indisponível</h2><p>Tente novamente em instantes.</p></div>');
            }
            die('<div style="font-family:sans-serif;padding:40px;max-width:600px">
              <h2 style="color:#dc2626">Erro de conexão com o banco</h2>
              <p><b>Mensagem:</b> ' . htmlspecialchars($e->getMessage()) . '</p>
              <p>Verifique as credenciais em <code>includes/config.php</code></p>
            </div>');
        }
    }
    ensureIndexes($pdo);
    return $pdo;
}


// ── Audit Log ────────────────────────────────
function auditLog(string $action, string $target = '', string $detail = ''): void {
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            user_name VARCHAR(100) DEFAULT NULL,
            action VARCHAR(100) NOT NULL,
            target VARCHAR(200) DEFAULT NULL,
            detail TEXT DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_al_created (created_at),
            INDEX idx_al_action (action)
        )");
        $userId   = $_SESSION['user_id'] ?? null;
        $userName = $_SESSION['user_name'] ?? null;
        $ip       = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP'] ?? (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : ($_SERVER['REMOTE_ADDR'] ?? '')))[0]);
        $db->prepare('INSERT INTO audit_log (user_id,user_name,action,target,detail,ip) VALUES (?,?,?,?,?,?)')
           ->execute([$userId, $userName, $action, $target ?: null, $detail ?: null, $ip]);
    } catch(Exception $e) {}
}

// ── Validação de telefone brasileiro ──────────
function validateBrazilianPhone(string $phone): bool {
    $digits = preg_replace('/\D/', '', $phone);
    $len = strlen($digits);
    if ($len < 10 || $len > 11) return false;
    $ddd = (int)substr($digits, 0, 2);
    $validDDDs = [11,12,13,14,15,16,17,18,19,21,22,24,27,28,31,32,33,34,35,37,38,
                  41,42,43,44,45,46,47,48,49,51,53,54,55,61,62,63,64,65,66,67,68,69,
                  71,73,74,75,77,79,81,82,83,84,85,86,87,88,89,91,92,93,94,95,96,97,98,99];
    if (!in_array($ddd, $validDDDs)) return false;
    if ($len === 11 && substr($digits, 2, 1) !== '9') return false;
    if (preg_match('/^(\d)\1+$/', $digits)) return false;
    return true;
}

// ── Cache de settings (APCu se disponível, memória se não) ──
function getSetting(string $key, $default = null) {
    static $all = null;
    if ($key === '__reload__') {
        $all = null;
        if (function_exists('apcu_delete')) apcu_delete('cms_settings');
        return $default;
    }
    if ($all === null) {
        // Tenta APCu primeiro (sobrevive entre requests, TTL configurável)
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch('cms_settings', $ok);
            if ($ok) { $all = $cached; return $all[$key] ?? $default; }
        }
        try {
            $all = [];
            foreach (getDB()->query('SELECT key_name,value FROM settings') as $r) {
                $all[$r['key_name']] = $r['value'];
            }
            if (function_exists('apcu_store')) {
                $ttl = defined('APCU_SETTINGS_TTL') ? APCU_SETTINGS_TTL : 60;
                apcu_store('cms_settings', $all, $ttl);
            }
        } catch (Exception $e) { $all = []; }
    }
    return $all[$key] ?? $default;
}

// ── Helpers ───────────────────────────────────
function formatFileSize(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes/1073741824, 2).' GB';
    if ($bytes >= 1048576)    return round($bytes/1048576, 2).' MB';
    if ($bytes >= 1024)       return round($bytes/1024, 2).' KB';
    return $bytes.' B';
}


// ── Rate Limit simples via APCu ───────────────
// Retorna true se PERMITIDO, false se bloqueado
function rateLimit(string $key, int $maxRequests, int $windowSeconds): bool {
    if (!function_exists('apcu_fetch')) return true; // sem APCu, não limita
    $cKey   = 'rl_' . $key;
    $count  = (int)apcu_fetch($cKey, $ok);
    if (!$ok) {
        apcu_store($cKey, 1, $windowSeconds);
        return true;
    }
    if ($count >= $maxRequests) return false;
    apcu_inc($cKey);
    return true;
}

// ── Cache de output de página (HTML) ──────────
function pageCache(int $ttl = 0): void {
    // Desativado por padrão — só ativa se TTL > 0 E usuário não logado
    if ($ttl <= 0) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;

    // NUNCA cacheia para nenhum usuário logado
    if (!empty($_SESSION['user_id'])
        || !empty($_SESSION['expired_user_id'])
        || !empty($_SESSION['pending_user_id'])) return;

    // NUNCA cacheia área admin
    if (strpos($_SERVER['PHP_SELF'] ?? '', '/admin/') !== false) return;

    $cacheDir = __DIR__ . '/../cache/pages/';
    $cKey     = md5((defined('SITE_URL') ? SITE_URL : '') . ($_SERVER['REQUEST_URI'] ?? ''));
    $file     = $cacheDir . $cKey . '.html';

    // Serve do cache se ainda válido
    if (file_exists($file) && (time() - filemtime($file)) < $ttl) {
        if (!headers_sent()) header('X-Cache: HIT');
        readfile($file);
        exit;
    }

    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

    // Captura o output completo ao final sem interferir em outros buffers
    ob_start();
    register_shutdown_function(function() use ($file) {
        $html = ob_get_clean();
        if ($html && stripos($html, '</html>') !== false && strlen($html) > 500) {
            @file_put_contents($file, $html);
        }
        echo $html;
    });
}

function clearPageCache(): void {
    $dir = __DIR__ . '/../cache/pages/';
    if (!is_dir($dir)) return;
    foreach (glob($dir . '*.html') as $f) @unlink($f);
}

function pageCacheInvalidate(string $prefix = ''): void {
    clearPageCache();
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'agora';
    if ($diff < 3600)   return floor($diff/60).'min';
    if ($diff < 86400)  return floor($diff/3600).'h';
    if ($diff < 604800) return floor($diff/86400).'d';
    return date('d/m/Y', strtotime($datetime));
}

function slugify(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $text = strtr($text, ['á'=>'a','ã'=>'a','â'=>'a','à'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','õ'=>'o','ô'=>'o','ú'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n']);
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', trim($text));
    return trim($text, '-');
}

function getPhpUploadLimit(): int {
    $toBytes = function(string $v): int {
        $v = trim($v);
        $last = strtolower($v[-1] ?? '');
        $n = (int)$v;
        return match($last) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    };
    return min(
        $toBytes(ini_get('upload_max_filesize') ?: '8M'),
        $toBytes(ini_get('post_max_size')       ?: '8M')
    );
}

function sendMail(string $to, string $subject, string $htmlBody): bool {
    $from    = getSetting('smtp_from', '');
    $host    = getSetting('smtp_host', '');
    $port    = (int)getSetting('smtp_port', '587');
    $user    = getSetting('smtp_user', '');
    $pass    = getSetting('smtp_pass', '');
    $siteName= getSetting('site_name', SITE_NAME);

    if (!$from || !$host) {
        return @mail($to, $subject, strip_tags($htmlBody), "From: noreply@".parse_url(SITE_URL,PHP_URL_HOST)."\r\nContent-Type: text/html\r\n");
    }

    $boundary = md5(uniqid());
    $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: {$siteName} <{$from}>\r\nTo: {$to}\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\n";

    $sock = @fsockopen(($port === 465 ? 'ssl://' : '').$host, $port, $errno, $errstr, 10);
    if (!$sock) return false;
    $send = function(string $cmd) use ($sock): string {
        fwrite($sock, $cmd."\r\n");
        return fgets($sock, 1024);
    };
    $send(""); fgets($sock, 1024);
    $send("EHLO localhost");
    if ($port == 587) { $send("STARTTLS"); stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT); $send("EHLO localhost"); }
    $send("AUTH LOGIN");
    $send(base64_encode($user));
    $send(base64_encode($pass));
    $send("MAIL FROM:<{$from}>");
    $send("RCPT TO:<{$to}>");
    $send("DATA");
    fwrite($sock, $headers."\r\n".$htmlBody."\r\n.\r\n");
    fgets($sock, 1024);
    $send("QUIT");
    fclose($sock);
    return true;
}


// Mostra: Anterior | 1 ... 4 5 [6] 7 8 ... 115 | Próxima
if (!function_exists('renderPagination')):
function renderPagination(int $page, int $totalPages, string $baseUrl, string $pageParam = 'page'): string {
    if ($totalPages <= 1) return '';

    $sep = strpos($baseUrl, '?') !== false ? '&' : '?';
    $urlBase = $baseUrl . $sep . $pageParam . '=';

    // Quais páginas mostrar
    $show = [];
    $show[] = 1;
    $show[] = $totalPages;
    for ($i = max(2, $page - 2); $i <= min($totalPages - 1, $page + 2); $i++) $show[] = $i;
    sort($show); $show = array_unique($show);

    $html  = '<div class="pagination">';

    // Anterior
    if ($page > 1) {
        $html .= '<a href="' . ($urlBase . ($page - 1)) . '" class="pg-btn pg-arrow">← Anterior</a>';
    } else {
        $html .= '<span class="pg-btn pg-arrow disabled">← Anterior</span>';
    }

    $prev = 0;
    foreach ($show as $p) {
        if ($prev && $p - $prev > 1) {
            $html .= '<span class="pg-btn pg-dots">…</span>';
        }
        $active = $p === $page ? ' pg-active' : '';
        $html .= '<a href="' . ($urlBase . $p) . '" class="pg-btn' . $active . '">' . $p . '</a>';
        $prev = $p;
    }

    // Próxima
    if ($page < $totalPages) {
        $html .= '<a href="' . ($urlBase . ($page + 1)) . '" class="pg-btn pg-arrow">Próxima →</a>';
    } else {
        $html .= '<span class="pg-btn pg-arrow disabled">Próxima →</span>';
    }

    $html .= '<span class="pg-info">Página ' . $page . ' de ' . $totalPages . '</span>';
    $html .= '</div>';
    return $html;
}
endif;
