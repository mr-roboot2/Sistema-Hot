<?php
/**
 * ajax_chunk.php — recebe chunks de arquivo e os monta no servidor
 * Fluxo: JS divide arquivo em pedaços de 5MB e envia um por vez
 * Quando todos chegam, monta o arquivo final e registra no banco
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';

@set_time_limit(600);
@ini_set('memory_limit', '256M');

header('Content-Type: application/json; charset=utf-8');
if (ob_get_level()) ob_end_clean();

if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Sem permissão.']); exit;
}

// Rate limit: máx 200 chunks por minuto por IP
$_rlIp = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP'] ?? (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : ($_SERVER['REMOTE_ADDR'] ?? '')))[0]);
if (!rateLimit('chunk_' . $_rlIp, 1000, 60)) {
    echo json_encode(['success'=>false,'error'=>'Muitas requisições. Aguarde um momento.']); exit;
}

// Fecha sessão imediatamente — libera o lock para uploads paralelos funcionarem
session_write_close();

// Limpeza de chunks abandonados (mais de 2h) — roda com 5% de chance para não impactar performance
if (mt_rand(1, 20) === 1) {
    $chunksBase = UPLOAD_DIR . 'chunks/';
    if (is_dir($chunksBase)) {
        foreach (glob($chunksBase . '*', GLOB_ONLYDIR) as $dir) {
            if ((time() - filemtime($dir)) > 7200) {
                array_map('unlink', glob($dir . '/*'));
                @rmdir($dir);
            }
        }
    }
}

$postId      = (int)($_POST['post_id']       ?? 0);
$chunkIndex  = (int)($_POST['chunk_index']   ?? 0);
$totalChunks = (int)($_POST['total_chunks']  ?? 1);
$fileId      = trim($_POST['file_id']        ?? ''); // UUID gerado pelo JS por arquivo
$origName    = trim($_POST['original_name']  ?? '');
$totalSize   = (int)($_POST['total_size']    ?? 0);

if (!$postId || !$fileId || !$origName || $totalChunks < 1) {
    echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos.']); exit;
}

// Sanitiza fileId para usar como nome de pasta temporária
$fileId = preg_replace('/[^a-zA-Z0-9_-]/', '', $fileId);
if (!$fileId) { echo json_encode(['success' => false, 'error' => 'file_id inválido.']); exit; }

// Pasta temporária para os chunks deste arquivo
$tmpDir = UPLOAD_DIR . 'chunks/' . $fileId . '/';
if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

// Recebe o chunk
if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Chunk não recebido.']); exit;
}

$chunkPath = $tmpDir . $chunkIndex . '.part';
if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkPath)) {
    echo json_encode(['success' => false, 'error' => 'Falha ao salvar chunk.']); exit;
}

// Verifica quantos chunks chegaram
$received = count(glob($tmpDir . '*.part'));

// Ainda esperando chunks
if ($received < $totalChunks) {
    echo json_encode(['success' => true, 'status' => 'chunk_received', 'received' => $received, 'total' => $totalChunks]);
    exit;
}

// Todos os chunks chegaram — usa lock de arquivo para evitar montagem dupla (chunks paralelos)
$lockFile = $tmpDir . '.assembling';
$lock = fopen($lockFile, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // Outro processo já está montando — aguarda e retorna o resultado
    flock($lock, LOCK_SH); // espera terminar
    fclose($lock);
    // Verifica se já foi inserido no banco
    $db  = getDB();
    $med = $db->prepare('SELECT * FROM media WHERE post_id=? AND original_name=?');
    $med->execute([$postId, $origName]); $med = $med->fetch();
    if ($med) {
        echo json_encode([
            'success' => true, 'status' => 'complete',
            'id'      => (int)$med['id'], 'filename' => $med['filename'],
            'original_name' => $med['original_name'], 'file_path' => $med['file_path'],
            'file_type'     => $med['file_type'],     'file_size' => $med['file_size'],
            'url'     => UPLOAD_URL . $med['file_path'],
        ]);
    } else {
        echo json_encode(['success' => true, 'status' => 'chunk_received', 'received' => $received, 'total' => $totalChunks]);
    }
    exit;
}
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$fileType = detectFileType($ext);
$allowed  = array_merge(ALLOWED_IMAGES, ALLOWED_VIDEOS, ALLOWED_FILES);

if (!in_array($ext, $allowed)) {
    array_map('unlink', glob($tmpDir . '*.part'));
    rmdir($tmpDir);
    echo json_encode(['success' => false, 'error' => "Extensão .$ext não é permitida."]); exit;
}

// Verifica duplicata
$db  = getDB();
$dup = $db->prepare('SELECT id FROM media WHERE post_id=? AND original_name=?');
$dup->execute([$postId, $origName]);
if ($dup->fetch()) {
    array_map('unlink', glob($tmpDir . '*.part'));
    @rmdir($tmpDir);
    echo json_encode(['success' => false, 'error' => 'Arquivo "' . $origName . '" já existe neste post.']); exit;
}

$subDir  = $fileType === 'image' ? 'images' : ($fileType === 'video' ? 'videos' : 'files');
$destDir = UPLOAD_DIR . $subDir . '/';
if (!is_dir($destDir)) mkdir($destDir, 0755, true);

$filename = uniqid('', true) . '_' . time() . '.' . $ext;
$destPath = $destDir . $filename;

// Concatena chunks em ordem
$out = fopen($destPath, 'wb');
if (!$out) {
    echo json_encode(['success' => false, 'error' => 'Não foi possível criar o arquivo final.']); exit;
}
for ($i = 0; $i < $totalChunks; $i++) {
    $part = $tmpDir . $i . '.part';
    if (!file_exists($part)) {
        fclose($out);
        unlink($destPath);
        echo json_encode(['success' => false, 'error' => "Chunk $i ausente. Tente novamente."]); exit;
    }
    $in = fopen($part, 'rb');
    while (!feof($in)) fwrite($out, fread($in, 1024 * 1024)); // 1MB buffer
    fclose($in);
    unlink($part);
}
fclose($out);
@unlink($lockFile);
flock($lock, LOCK_UN);
fclose($lock);
@rmdir($tmpDir);

// Dimensões para imagem
$width = $height = null;
if ($fileType === 'image' && function_exists('getimagesize')) {
    $info = @getimagesize($destPath);
    if ($info) { $width = $info[0]; $height = $info[1]; }
}

$actualSize = filesize($destPath);

// Garante coluna video_thumb
try { $db->exec("ALTER TABLE media ADD COLUMN video_thumb VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}

$stmt = $db->prepare('INSERT INTO media (post_id,filename,original_name,file_path,file_type,mime_type,file_size,width,height,duration) VALUES (?,?,?,?,?,?,?,?,?,?)');
$stmt->execute([$postId, $filename, $origName, $subDir.'/'.$filename, $fileType, '', $actualSize, $width, $height, null]);

echo json_encode([
    'success'       => true,
    'status'        => 'complete',
    'id'            => (int)$db->lastInsertId(),
    'filename'      => $filename,
    'original_name' => $origName,
    'file_path'     => $subDir . '/' . $filename,
    'file_type'     => $fileType,
    'file_size'     => $actualSize,
    'url'           => UPLOAD_URL . $subDir . '/' . $filename,
]);
exit;
