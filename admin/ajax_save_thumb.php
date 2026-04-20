<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
ob_end_clean();
session_write_close();

function jsonErr(string $msg): void {
    echo json_encode(['error' => $msg]); exit;
}

if (!isAdmin()) jsonErr('forbidden');

$mediaId  = (int)($_POST['media_id'] ?? 0);
$imageB64 = trim($_POST['thumb_data'] ?? '');

if (!$mediaId) jsonErr('missing media_id');
if (!$imageB64) jsonErr('missing thumb_data');

// Garante coluna (pode não existir em produção antiga)
$db = getDB();
try { $db->exec("ALTER TABLE media ADD COLUMN video_thumb VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}

$media = $db->prepare('SELECT id, file_type FROM media WHERE id=?');
$media->execute([$mediaId]);
$media = $media->fetch();
if (!$media)                          jsonErr('media not found');
if ($media['file_type'] !== 'video')  jsonErr('not a video');

// Decodifica base64
$base64  = preg_replace('/^data:image\/\w+;base64,/', '', $imageB64);
$imgData = base64_decode($base64);
if ($imgData === false || strlen($imgData) < 100) jsonErr('invalid base64 data');

// Salva na pasta thumbs/
$thumbDir = UPLOAD_DIR . 'thumbs/';
if (!is_dir($thumbDir)) {
    if (!@mkdir($thumbDir, 0755, true)) jsonErr('cannot create thumbs dir');
}

// Remove thumb anterior se existir
$old = $db->prepare('SELECT video_thumb FROM media WHERE id=?');
$old->execute([$mediaId]);
$oldThumb = $old->fetchColumn();
if ($oldThumb && file_exists(UPLOAD_DIR . $oldThumb)) {
    @unlink(UPLOAD_DIR . $oldThumb);
}

$thumbName = 'vt_' . $mediaId . '.jpg';
$thumbPath = $thumbDir . $thumbName;
$thumbRel  = 'thumbs/' . $thumbName;

$written = file_put_contents($thumbPath, $imgData);
if ($written === false) jsonErr('failed to write file — check folder permissions');

// Valida que o arquivo salvo é realmente uma imagem
$imgInfo = @getimagesize($thumbPath);
if (!$imgInfo || !in_array($imgInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP])) {
    @unlink($thumbPath);
    jsonErr('invalid image content');
}

// Atualiza banco
$db->prepare('UPDATE media SET video_thumb=? WHERE id=?')->execute([$thumbRel, $mediaId]);

echo json_encode([
    'ok'          => true,
    'video_thumb' => $thumbRel,
    'url'         => UPLOAD_URL . $thumbRel,
    'bytes'       => $written,
]);
