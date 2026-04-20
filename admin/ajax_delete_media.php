<?php
/**
 * ajax_delete_media.php — remove um arquivo de mídia
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';

header('Content-Type: application/json; charset=utf-8');
session_write_close(); // libera lock de sessão para uploads paralelos

if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Sem permissão.']);
    exit;
}

$db      = getDB();
$mediaId = (int)($_POST['media_id'] ?? 0);

if (!$mediaId) {
    echo json_encode(['success' => false, 'error' => 'ID inválido.']);
    exit;
}

// Verifica que a mídia existe
$stmt = $db->prepare('SELECT m.id, m.file_path, p.thumbnail FROM media m JOIN posts p ON m.post_id = p.id WHERE m.id = ?');
$stmt->execute([$mediaId]);
$media = $stmt->fetch();

if (!$media) {
    echo json_encode(['success' => false, 'error' => 'Mídia não encontrada.']);
    exit;
}

// Se era a thumbnail do post, limpa
if ($media['thumbnail'] === $media['file_path']) {
    $db->prepare('UPDATE posts SET thumbnail = NULL WHERE thumbnail = ?')
       ->execute([$media['file_path']]);
}

deleteMedia($mediaId);
echo json_encode(['success' => true]);
exit;
