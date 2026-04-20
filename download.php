<?php
/**
 * download.php — força download de mídia pelo ID
 * Uso: download.php?id=MEDIA_ID
 * Respeita: protect_uploads, allow_download, login obrigatório
 */
require_once __DIR__ . '/includes/auth.php';

// Download precisa estar habilitado
if (getSetting('allow_download', '1') !== '1') {
    http_response_code(403);
    exit('Download desabilitado.');
}

// Requer login se o site exige
$required = getSetting('require_login', '1') === '1';
if ($required && !isLoggedIn()) {
    header('Location: ' . SITE_URL . '/login'); exit;
}

$db      = getDB();
$mediaId = (int)($_GET['id'] ?? 0);
if (!$mediaId) { http_response_code(400); exit('ID inválido.'); }

// Busca a mídia com validação de post publicado (ou admin vê tudo)
if (isAdmin()) {
    $stmt = $db->prepare('SELECT m.*, p.status FROM media m JOIN posts p ON p.id=m.post_id WHERE m.id=?');
} else {
    $stmt = $db->prepare('SELECT m.*, p.status FROM media m JOIN posts p ON p.id=m.post_id WHERE m.id=? AND p.status="published"');
}
$stmt->execute([$mediaId]);
$media = $stmt->fetch();

if (!$media) { http_response_code(404); exit('Mídia não encontrada.'); }

// Monta caminho real do arquivo
$filePath = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($media['file_path'], '/');
$filePath = realpath($filePath);

// Proteção path traversal
if (!$filePath || !str_starts_with($filePath, realpath(UPLOAD_DIR))) {
    http_response_code(403); exit('Acesso negado.');
}

if (!file_exists($filePath)) { http_response_code(404); exit('Arquivo não encontrado.'); }

// Detecta MIME
$mime = $media['mime_type'] ?: 'application/octet-stream';
if (function_exists('finfo_open')) {
    $fi   = finfo_open(FILEINFO_MIME_TYPE);
    $real = finfo_file($fi, $filePath);
    finfo_close($fi);
    if ($real) $mime = $real;
}

$filename = $media['original_name'] ?: basename($filePath);
// Sanitiza nome do arquivo para o header
$filename = preg_replace('/[^\w.\-áéíóúàèìòùâêîôûãõäëïöüçÁÉÍÓÚÀÈÌÒÙÂÊÎÔÛÃÕÄËÏÖÜÇ ]/', '_', $filename);

$size = filesize($filePath);

// Headers
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $size);
header('Cache-Control: private, no-cache');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// Serve o arquivo em chunks (evita timeout em arquivos grandes)
$chunk = 1024 * 1024; // 1 MB
$fp    = fopen($filePath, 'rb');
while (!feof($fp)) {
    echo fread($fp, $chunk);
    flush();
}
fclose($fp);
