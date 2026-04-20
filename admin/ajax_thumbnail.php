<?php
/**
 * ajax_thumbnail.php — define a foto de destaque do post
 */
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
session_write_close(); // libera lock de sessão para uploads paralelos

if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Sem permissão.']);
    exit;
}

$db      = getDB();
$postId  = (int)($_POST['post_id']  ?? 0);
$mediaId = (int)($_POST['media_id'] ?? 0);

if (!$postId || !$mediaId) {
    echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos.']);
    exit;
}

// Busca o file_path da mídia escolhida
$stmt = $db->prepare('SELECT file_path, file_type FROM media WHERE id = ? AND post_id = ?');
$stmt->execute([$mediaId, $postId]);
$media = $stmt->fetch();

if (!$media || !in_array($media['file_type'], ['image', 'video'])) {
    echo json_encode(['success' => false, 'error' => 'Mídia inválida (precisa ser imagem ou vídeo).']);
    exit;
}

$db->prepare('UPDATE posts SET thumbnail = ? WHERE id = ?')
   ->execute([$media['file_path'], $postId]);

echo json_encode(['success' => true, 'thumbnail' => $media['file_path']]);
exit;
