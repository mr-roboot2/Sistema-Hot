<?php
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');
session_write_close();

if (!isLoggedIn()) { echo json_encode(['error' => 'auth']); exit; }
if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['error' => 'csrf']); exit; }

// Rate limit: máx 30 toggles por minuto por usuário
$rlKey = 'fav_' . ($_SESSION['user_id'] ?? 0);
if (!rateLimit($rlKey, 30, 60)) {
    echo json_encode(['error' => 'rate_limit']); exit;
}

$db     = getDB();
$userId = (int)$_SESSION['user_id'];
$postId = (int)($_POST['post_id'] ?? 0);

if (!$postId) { echo json_encode(['error' => 'invalid']); exit; }

// Garante tabela
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_favorites (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        post_id    INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_fav (user_id, post_id),
        INDEX idx_fav_user (user_id),
        INDEX idx_fav_post (post_id)
    )");
} catch(Exception $e) {}

// Verifica se já existe
$exists = $db->prepare('SELECT id FROM user_favorites WHERE user_id=? AND post_id=?');
$exists->execute([$userId, $postId]);

if ($exists->fetch()) {
    // Remove
    $db->prepare('DELETE FROM user_favorites WHERE user_id=? AND post_id=?')->execute([$userId, $postId]);
    echo json_encode(['saved' => false]);
} else {
    // Verifica se o post existe
    $post = $db->prepare('SELECT id FROM posts WHERE id=? AND status="published"');
    $post->execute([$postId]);
    if (!$post->fetch()) { echo json_encode(['error' => 'post not found']); exit; }

    $db->prepare('INSERT INTO user_favorites (user_id, post_id) VALUES (?,?)')->execute([$userId, $postId]);
    echo json_encode(['saved' => true]);
}
