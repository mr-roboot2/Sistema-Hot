<?php
/**
 * file-proxy.php — serve arquivos de upload autenticados
 * Ativado quando protect_uploads=1 nas configurações.
 * Uso: file-proxy.php?f=images/arquivo.jpg
 */
require_once __DIR__ . '/includes/auth.php';

// Se proteção está desativada, redireciona direto
if (getSetting('protect_uploads','0') !== '1') {
    $f = ltrim($_GET['f'] ?? '', '/');
    header('Location: ' . UPLOAD_URL . $f);
    exit;
}

// Exige login
if (!isLoggedIn()) {
    http_response_code(403);
    die('Acesso negado. <a href="' . SITE_URL . '/login">Fazer login</a>');
}

$f    = $_GET['f'] ?? '';
$f    = ltrim(str_replace(['..','\\'], '', $f), '/');
$path = UPLOAD_DIR . $f;

if (!$f || !file_exists($path) || !is_file($path)) {
    http_response_code(404); die('Arquivo não encontrado.');
}

// Garante que está dentro da pasta uploads
$real     = realpath($path);
$uploadR  = realpath(UPLOAD_DIR);
if (!$real || strpos($real, $uploadR) !== 0) {
    http_response_code(403); die('Acesso negado.');
}

// Detecta tipo MIME
$ext  = strtolower(pathinfo($f, PATHINFO_EXTENSION));
$mimeMap = [
    'jpg'  => 'image/jpeg',  'jpeg' => 'image/jpeg',
    'png'  => 'image/png',   'gif'  => 'image/gif',
    'webp' => 'image/webp',  'svg'  => 'image/svg+xml',
    'mp4'  => 'video/mp4',   'webm' => 'video/webm',
    'ogg'  => 'video/ogg',   'mov'  => 'video/quicktime',
];
$mime = isset($mimeMap[$ext]) ? $mimeMap[$ext] : (mime_content_type($path) ?: 'application/octet-stream');

// Headers de cache
$etag    = md5_file($path);
$lastMod = gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT';

if ((isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) ||
    (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $_SERVER['HTTP_IF_MODIFIED_SINCE'] === $lastMod)) {
    http_response_code(304); exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('ETag: ' . $etag);
header('Last-Modified: ' . $lastMod);
// Cache baseado nas configurações
$cacheDays = (int)getSetting('cache_assets_days', '30');
if ($cacheDays > 0) {
    header('Cache-Control: private, max-age=' . ($cacheDays * 86400) . ', immutable');
} else {
    header('Cache-Control: no-store, no-cache');
}
header('Accept-Ranges: bytes');

// Suporte a range requests (streaming de vídeo)
$size  = filesize($path);
$start = 0;
$end   = $size - 1;

if (isset($_SERVER['HTTP_RANGE'])) {
    preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m);
    $start = $m[1] !== '' ? (int)$m[1] : 0;
    $end   = $m[2] !== '' ? (int)$m[2] : $size - 1;
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
    header('Content-Length: ' . ($end - $start + 1));
}

$fp = fopen($path, 'rb');
fseek($fp, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($fp)) {
    $chunk = min(8192, $remaining);
    echo fread($fp, $chunk);
    $remaining -= $chunk;
    if (ob_get_level()) ob_flush();
    flush();
}
fclose($fp);
