<?php
/**
 * ajax_upload.php — recebe UM arquivo por vez via AJAX
 * Retorna JSON: { success, id, file_type, file_path, original_name, file_size, url, error }
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';

@set_time_limit(600);
@ini_set('memory_limit', '512M');

header('Content-Type: application/json; charset=utf-8');
if (ob_get_level()) ob_end_clean();

if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Sem permissão.']);
    exit;
}

// Rate limit: máx 60 uploads por minuto por IP
$_rlIp = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP'] ?? (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : ($_SERVER['REMOTE_ADDR'] ?? '')))[0]);
if (!rateLimit('upload_' . $_rlIp, 300, 60)) {
    echo json_encode(['success'=>false,'error'=>'Muitas requisições. Aguarde um momento.']); exit;
}

// Fecha sessão imediatamente — libera o lock para uploads paralelos funcionarem
session_write_close();

$postId = (int)($_POST['post_id'] ?? 0);
if (!$postId) {
    echo json_encode(['success' => false, 'error' => 'post_id inválido.']);
    exit;
}

$db   = getDB();
$stmt = $db->prepare('SELECT id FROM posts WHERE id=?');
$stmt->execute([$postId]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Post não encontrado.']);
    exit;
}

if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['success' => false, 'error' => 'Nenhum arquivo recebido.']);
    exit;
}

$file = $_FILES['file'];

$phpErrors = [
    UPLOAD_ERR_INI_SIZE   => 'Arquivo excede upload_max_filesize no php.ini.',
    UPLOAD_ERR_FORM_SIZE  => 'Arquivo excede MAX_FILE_SIZE do formulário.',
    UPLOAD_ERR_PARTIAL    => 'Upload incompleto. Tente novamente.',
    UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária não encontrada no servidor.',
    UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar arquivo no disco.',
    UPLOAD_ERR_EXTENSION  => 'Upload bloqueado por extensão PHP.',
];
if ($file['error'] !== UPLOAD_ERR_OK) {
    $msg = $phpErrors[$file['error']] ?? 'Erro desconhecido (código ' . $file['error'] . ').';
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$result = uploadFile($file, $postId);

// Limpeza ocasional de chunks orphãos (1% das requisições)
if (rand(1, 100) === 1) cleanOldChunks();

echo json_encode($result);
exit;
