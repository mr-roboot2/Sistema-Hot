<?php
require_once __DIR__ . '/../includes/auth.php';
requireLoginAlways();
if (!isAdmin()) { header('Location: ' . SITE_URL . '/index'); exit; }

$db        = getDB();
$pageTitle = 'Novo Post';
$editId    = (int)($_GET['edit'] ?? 0);
$post      = null;
$postMedia = [];
$message   = '';
$error     = '';

if ($editId) {
    $s = $db->prepare('SELECT * FROM posts WHERE id = ?');
    $s->execute([$editId]);
    $post = $s->fetch();
    if ($post) {
        $pageTitle = 'Editar: ' . $post['title'];
        $ms = $db->prepare('SELECT * FROM media WHERE post_id = ? ORDER BY sort_order, id');
        $ms->execute([$editId]);
        $postMedia = $ms->fetchAll();
    }
}

$cats = $db->query('SELECT * FROM categories ORDER BY name')->fetchAll();

// Salva metadados do post (sem arquivos — upload é feito via AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_meta'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido.';
    } else {
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $content     = trim($_POST['content'] ?? '');
        $catId       = (int)($_POST['category_id'] ?? 0) ?: null;
        $status      = $_POST['status'] ?? 'published';
        $featured    = isset($_POST['featured']) ? 1 : 0;
        $type        = $_POST['post_type'] ?? 'mixed';

        if (!$title) {
            $error = 'Título é obrigatório.';
        } else {
            $slug = slugify($title);
            $base = $slug; $i = 1;
            while (true) {
                $chk = $db->prepare('SELECT id FROM posts WHERE slug = ?' . ($editId ? ' AND id != ?' : ''));
                $chk->execute($editId ? [$slug, $editId] : [$slug]);
                if (!$chk->fetch()) break;
                $slug = $base . '-' . $i++;
            }

            if ($editId && $post) {
                $db->prepare('UPDATE posts SET title=?,slug=?,description=?,content=?,category_id=?,type=?,status=?,featured=?,updated_at=NOW() WHERE id=?')
                   ->execute([$title, $slug, $description, $content, $catId, $type, $status, $featured, $editId]);
                $message = 'Post atualizado!';
            } else {
                clearPageCache();
                $db->prepare('INSERT INTO posts (user_id,title,slug,description,content,category_id,type,status,featured) VALUES (?,?,?,?,?,?,?,?,?)')
                   ->execute([$_SESSION['user_id'], $title, $slug, $description, $content, $catId, $type, $status, $featured]);
                $newId = (int)$db->lastInsertId();
                header('Location: ' . SITE_URL . '/admin/upload.php?edit=' . $newId . '&created=1');
                exit;
            }

            // Recarrega mídia
            $ms2 = $db->prepare('SELECT * FROM media WHERE post_id = ? ORDER BY sort_order, id');
            $ms2->execute([$editId]);
            $postMedia = $ms2->fetchAll();
        }
    }
}

if (isset($_GET['created'])) $message = 'Post criado! Agora faça os uploads abaixo.';

require __DIR__ . '/../includes/header.php';

// Dados para o JS
$jsPostId  = $editId ?: 0;
$jsCsrf    = csrf_token();
$jsBaseUrl = SITE_URL;

// Token temporário para autenticar o upload cross-origin (válido 2h)
$_cu = currentUser();
$uploadToken = hash_hmac('sha256', ($_cu['id'] ?? 0) . '|' . $jsPostId . '|' . floor(time()/7200), DB_PASS . DB_NAME);
$jsUploadToken = json_encode($uploadToken);
$jsMedia   = json_encode(array_map(function($m) use ($post) { return [
    'id'            => (int)$m['id'],
    'original_name' => $m['original_name'],
    'file_path'     => $m['file_path'],
    'file_type'     => $m['file_type'],
    'file_size'     => (int)$m['file_size'],
    'url'           => UPLOAD_URL . $m['file_path'],
    'is_thumb'      => ($post['thumbnail'] ?? '') === $m['file_path'],
]; }, $postMedia));
$jsThumb   = json_encode($post['thumbnail'] ?? '');
?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/upload.css">

<?php if ($message): ?>
<div class="alert alert-success" id="top-msg">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
  <?= htmlspecialchars($message) ?>
  <?php if ($editId): ?>
  <a href="<?= SITE_URL ?>/post/<?= $editId ?>" style="margin-left:auto;color:var(--accent)">Ver post →</a>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php
// Aviso de limite php.ini
$phpLimit = getPhpUploadLimit();
$showWarn = getSetting('show_php_limit_warn','1') === '1';
if ($showWarn && $phpLimit < (50 * 1024 * 1024)):
?>
<div style="display:flex;align-items:flex-start;gap:12px;padding:14px 18px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.3);border-radius:12px;margin-bottom:20px;font-size:13px;color:var(--warning)">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
  <div>
    <strong>Limite de upload baixo:</strong> seu servidor aceita no máximo <strong><?= formatFileSize($phpLimit) ?></strong> por arquivo.
    Uploads maiores vão falhar silenciosamente.<br>
    <span style="opacity:.8">Para aumentar, edite o <code>php.ini</code> do XAMPP:
    <code>upload_max_filesize = 500M</code> e <code>post_max_size = 512M</code> → reinicie o Apache.</span>
    <br><a href="<?= SITE_URL ?>/admin/settings.php" style="color:var(--warning);font-size:12px">→ Ver detalhes em Configurações</a>
  </div>
</div>
<?php endif; ?>

<form method="POST" id="meta-form">
  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
  <input type="hidden" name="save_meta" value="1">

  <div class="up-layout">
  <!-- COLUNA ESQUERDA -->
  <div>
    <!-- Título + descrição -->
    <div class="card" style="padding:22px;margin-bottom:20px">
      <div style="font-family:'Roboto',sans-serif;font-size:15px;font-weight:700;margin-bottom:16px">
        <?= $editId ? 'Editar Post' : 'Criar Post' ?>
      </div>
      <div class="form-group">
        <label class="form-label">Título *</label>
        <input type="text" name="title" class="form-control" required placeholder="Título do post"
               value="<?= htmlspecialchars($post['title'] ?? '') ?>" id="title-input">
      </div>
      <div class="form-group">
        <label class="form-label">Descrição curta</label>
        <textarea name="description" class="form-control" rows="3" placeholder="Resumo do conteúdo..."><?= htmlspecialchars($post['description'] ?? '') ?></textarea>
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label">Conteúdo completo <span style="color:var(--muted);font-weight:400">(opcional)</span></label>
        <textarea name="content" class="form-control" rows="5" placeholder="Texto, informações adicionais..."><?= htmlspecialchars($post['content'] ?? '') ?></textarea>
      </div>
    </div>

    <!-- Drop zone de upload (só aparece depois que o post existe) -->
    <?php if ($editId): ?>
    <div class="card" style="padding:22px;margin-bottom:20px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px">
        <div style="font-family:'Roboto',sans-serif;font-size:15px;font-weight:700">Upload de Arquivos</div>
        <?php
          $maxSysMb  = (int)round(getPhpUploadLimit() / 1024 / 1024);
          $maxCfgMb  = (int)getSetting('max_file_mb','500');
          $maxRealMb = min($maxSysMb, $maxCfgMb);
        ?>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <div class="txt-muted-sm">
            Sistema: <b style="color:<?= $maxSysMb >= $maxCfgMb ? 'var(--success)' : 'var(--warning)' ?>"><?= $maxSysMb ?>MB</b>
            &nbsp;·&nbsp;Configurado: <b><?= $maxCfgMb ?>MB</b>
            &nbsp;·&nbsp;Efetivo: <b style="color:var(--accent)"><?= formatFileSize($maxRealMb * 1024 * 1024) ?></b>
          </div>
          <?php if ($maxSysMb < $maxCfgMb): ?>
          <span style="font-size:11px;background:rgba(245,158,11,.15);color:var(--warning);padding:2px 8px;border-radius:8px">
            ⚠️ php.ini limita em <?= $maxSysMb ?>MB
          </span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Stats bar + botões de ação (hidden until files selected) -->
      <div class="stats-bar" id="stats-bar" style="display:none">
        <span>Na fila: <b id="stat-total">0</b></span>
        <span class="stat-ok">✓ <b id="stat-done">0</b></span>
        <span class="stat-err">✗ <b id="stat-err">0</b></span>
        <span class="stat-wait">⏳ <b id="stat-wait">0</b></span>
        <span class="txt-muted-xs" id="stat-size"></span>
        <div style="margin-left:auto;display:flex;gap:6px;align-items:center">
          <button type="button" id="btn-clear-done" class="btn btn-secondary" style="display:none;padding:4px 10px;font-size:11px">✓ Limpar enviados</button>
          <button type="button" id="btn-clear-all"  class="btn btn-secondary" style="display:none;padding:4px 10px;font-size:11px">Limpar fila</button>
        </div>
      </div>

      <!-- Barra de progresso geral -->
      <div class="upload-overall" id="overall-bar-wrap">
        <div class="overall-info">
          <span id="overall-label" style="font-weight:600;font-size:12px">Enviando...</span>
          <span id="overall-right" style="font-size:11px"></span>
        </div>
        <div class="overall-bar-wrap">
          <div class="overall-bar" id="overall-bar"></div>
        </div>
      </div>

      <!-- Drop zone -->
      <div class="dropzone" id="dropzone">
        <div class="dz-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
        </div>
        <div class="dz-title">Arraste arquivos aqui</div>
        <div class="dz-sub">
          Imagens: jpg, png, gif, webp, svg<br>
          Vídeos: mp4, webm, mov, avi, mkv
        </div>
        <button type="button" class="dz-btn" onclick="document.getElementById('file-input').click()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Selecionar arquivos
        </button>
      </div>
      <input type="file" id="file-input" multiple
             accept="image/*,video/*"
             style="display:none">

      <!-- Fila de upload -->
      <div class="queue" id="queue"></div>

      <!-- Botões de enviar -->
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button type="button" id="btn-upload" class="send-btn" disabled>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
          <span id="btn-upload-label">Enviar arquivos</span>
        </button>
        <button type="button" id="btn-retry-all" class="btn btn-secondary" style="display:none;flex-shrink:0;color:var(--warning)">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.95"/></svg>
          Reenviar com erros
        </button>
      </div>
    </div>

    <!-- Mídia já enviada — com abas e paginação -->
    <div class="card" style="padding:22px" id="media-section">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
        <div style="font-family:'Roboto',sans-serif;font-size:15px;font-weight:700">
          Arquivos do Post <span id="media-count-badge" class="badge badge-accent" style="font-size:12px"><?= count($postMedia) ?></span>
        </div>
        <div class="txt-muted-sm">
          <span style="color:var(--warning)">★</span> = capa &nbsp;·&nbsp; clique na imagem para definir capa
        </div>
      </div>

      <!-- Abas -->
      <div class="media-tabs" id="media-tabs">
        <button type="button" class="media-tab active" data-tab="image" onclick="switchTab('image')">
          🖼️ Imagens <span class="tab-count" id="tab-count-image">0</span>
        </button>
        <button type="button" class="media-tab" data-tab="video" onclick="switchTab('video')">
          🎬 Vídeos <span class="tab-count" id="tab-count-video">0</span>
        </button>
      </div>

      <!-- Grid de cada aba -->
      <div id="tab-panel-image" class="tab-panel">
        <div class="media-grid" id="media-grid-image"></div>
        <div class="tab-empty" id="empty-image">Nenhuma imagem enviada ainda.</div>
        <div class="media-pagination" id="pag-image"></div>
      </div>
      <div id="tab-panel-video" class="tab-panel" style="display:none">
        <div class="media-grid" id="media-grid-video"></div>
        <div class="tab-empty" id="empty-video">Nenhum vídeo enviado ainda.</div>
        <div class="media-pagination" id="pag-video"></div>
        <?php if ($editId): ?>
        <div style="padding:10px 0;text-align:right">
          <button type="button" id="btn-regen-thumbs" onclick="regenThumbs()"
                  style="font-size:11px;padding:5px 12px;background:var(--surface2);border:1px solid var(--border2);border-radius:6px;cursor:pointer;color:var(--muted2)">
            🖼️ Regenerar capas
          </button>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php else: ?>
    <!-- Aviso: salve o post primeiro -->
    <div class="card" style="padding:28px;text-align:center;border:2px dashed var(--border2)">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.5" style="margin:0 auto 12px"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
      <div style="font-size:15px;font-weight:600;margin-bottom:6px">Salve o post primeiro</div>
      <div style="font-size:13px;color:var(--muted)">Preencha o título e salve para habilitar o upload de arquivos.</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- COLUNA DIREITA -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- Foto de destaque (capa) -->
    <div class="card" style="padding:20px">
      <div style="font-family:'Roboto',sans-serif;font-size:14px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:7px">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--warning)" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        Foto de Capa
      </div>
      <?php
        $thumbPath = $post['thumbnail'] ?? '';
        $thumbUrl  = $thumbPath ? UPLOAD_URL . $thumbPath : '';
        // Detecta se a capa salva é um vídeo
        $thumbIsVideo = false;
        if ($thumbPath) {
            $ext = strtolower(pathinfo($thumbPath, PATHINFO_EXTENSION));
            $thumbIsVideo = in_array($ext, ['mp4','webm','ogg','mov','avi','mkv']);
        }
      ?>
      <div id="thumb-wrap">
        <?php if ($thumbUrl && !$thumbIsVideo): ?>
          <img src="<?= htmlspecialchars($thumbUrl) ?>" id="thumb-preview" class="thumb-preview" alt="Capa">
        <?php elseif ($thumbUrl && $thumbIsVideo): ?>
          <div style="position:relative;width:100%;aspect-ratio:9/16;border-radius:10px;overflow:hidden;border:2px solid var(--warning)">
            <canvas id="thumb-canvas-preview" class="img-cover"></canvas>
            <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none">
              <div style="background:rgba(255,106,158,.8);border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="#fff"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              </div>
            </div>
          </div>
          <script>/* captura feita no init JS abaixo */</script>
        <?php else: ?>
          <div id="thumb-placeholder" class="thumb-placeholder">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            <span>Selecione uma imagem ou vídeo<br>como capa do post</span>
          </div>
        <?php endif; ?>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-top:10px;line-height:1.5">
        Clique em uma imagem ou vídeo para definir como capa.
      </div>
    </div>

    <!-- Configurações -->
    <div class="card" style="padding:20px">
      <div style="font-family:'Roboto',sans-serif;font-size:14px;font-weight:700;margin-bottom:16px">Configurações</div>

      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" class="form-control">
          <option value="published" <?= ($post['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>✅ Publicado</option>
          <option value="draft"     <?= ($post['status'] ?? '') === 'draft'     ? 'selected' : '' ?>>📝 Rascunho</option>
          <option value="archived"  <?= ($post['status'] ?? '') === 'archived'  ? 'selected' : '' ?>>📦 Arquivado</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Categoria</label>
        <select name="category_id" class="form-control">
          <option value="">— Sem categoria —</option>
          <?php foreach ($cats as $c): ?>
          <option value="<?= $c['id'] ?>" <?= ($post['category_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Tipo de conteúdo</label>
        <select name="post_type" class="form-control">
          <?php foreach (['mixed'=>'🔀 Misto','image'=>'🖼️ Imagens','video'=>'🎬 Vídeos','file'=>'📄 Arquivos'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= ($post['type'] ?? 'mixed') === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;padding:10px;background:var(--surface2);border-radius:8px">
        <input type="checkbox" name="featured" value="1" <?= !empty($post['featured']) ? 'checked' : '' ?>
               style="width:16px;height:16px;accent-color:var(--warning)">
        ⭐ Marcar como destaque
      </label>
    </div>

    <!-- Botão salvar -->
    <button type="submit" class="btn btn-primary" style="width:100%;padding:14px;justify-content:center;font-size:15px">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px;height:16px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      <?= $editId ? 'Salvar alterações' : 'Criar post e continuar' ?>
    </button>

    <?php if ($editId): ?>
    <a href="<?= SITE_URL ?>/post/<?= $editId ?>" class="btn btn-secondary" style="width:100%;justify-content:center">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
      Visualizar post
    </a>
    <?php endif; ?>
  </div>
  </div><!-- .up-layout -->
</form>

<script>
(function(){
'use strict';

const POST_ID  = <?= $jsPostId ?>;
const CSRF     = <?= json_encode($jsCsrf) ?>;
const BASE_URL = <?= json_encode($jsBaseUrl) ?>;
const UPLOAD_TOKEN = <?= $jsUploadToken ?>;
const MAX_SIZE = <?= min(getPhpUploadLimit(), (int)getSetting('max_file_mb','500') * 1024 * 1024) ?>;
const ALLOWED_EXT = <?= json_encode(array_merge(ALLOWED_IMAGES, ALLOWED_VIDEOS, ALLOWED_FILES)) ?>;
const CONCURRENCY = <?= max(1, min(10, (int)getSetting('upload_concurrency','3'))) ?>;

// ── Estado ──────────────────────────────────
let queue    = [];   // { id, file, status: 'wait'|'uploading'|'done'|'error', progress, result, errorMsg }
let isUploading = false;
let mediaItems  = <?= $jsMedia ?>;   // arquivos já no servidor
let currentThumb = <?= $jsThumb ?>;  // file_path da thumbnail atual

let nextId = 1;

// ── Performance: throttle e RAF ─────────────
// Evita re-renderizar a cada evento de progresso XHR (dezenas/segundo)
let _rafPending = false;
let _pendingProgressItems = new Set();

function scheduleProgressUpdate(item) {
  _pendingProgressItems.add(item.id);
  if (!_rafPending) {
    _rafPending = true;
    requestAnimationFrame(() => {
      _rafPending = false;
      _pendingProgressItems.forEach(id => {
        const item = queue.find(i => i.id === id);
        if (item) _doUpdateProgress(item);
      });
      _pendingProgressItems.clear();
    });
  }
}

function _doUpdateProgress(item) {
  const bar = document.querySelector(`#qi-${item.id} .q-bar`);
  if (bar) bar.style.width = item.progress + '%';
  // Atualiza speed/ETA inline sem re-render completo
  const el = document.getElementById(`qi-${item.id}`);
  if (!el) return;
  let metaEl = el.querySelector('.q-meta');
  if (item.speed) {
    const html = `<span class="q-speed">${fmtSpeed(item.speed)}</span>${item.eta ? `<span class="q-eta">· ETA ${fmtTime(item.eta)}</span>` : ''}<span class="q-pct">${item.progress}%</span>`;
    if (!metaEl) {
      metaEl = document.createElement('div');
      metaEl.className = 'q-meta';
      el.querySelector('.q-info').appendChild(metaEl);
    }
    metaEl.innerHTML = html;
  }
}

// updateStats com throttle — no máximo 1x por 200ms
let _statsTimer = null;
function updateStatsThrottled() {
  if (_statsTimer) return;
  _statsTimer = setTimeout(() => { _statsTimer = null; updateStats(); }, 200);
}

// renderMediaGrid adiado — só executa 1s após o último arquivo terminar
let _mediaGridTimer = null;
function scheduleMediaGrid() {
  clearTimeout(_mediaGridTimer);
  _mediaGridTimer = setTimeout(renderMediaGrid, 1000);
}

// ── Elementos ───────────────────────────────
const dropzone    = document.getElementById('dropzone');
const fileInput   = document.getElementById('file-input');
const queueEl     = document.getElementById('queue');
const statsBar    = document.getElementById('stats-bar');
const btnUpload   = document.getElementById('btn-upload');
const btnLabel    = document.getElementById('btn-upload-label');
const btnClearAll = document.getElementById('btn-clear-all');
const btnClearDone= document.getElementById('btn-clear-done');
const mediaCount  = document.getElementById('media-count-badge');

// ── Drag & Drop ──────────────────────────────
if (dropzone) {
  dropzone.addEventListener('dragover',  e => { e.preventDefault(); dropzone.classList.add('hover'); });
  dropzone.addEventListener('dragleave', () => dropzone.classList.remove('hover'));
  dropzone.addEventListener('drop', e => {
    e.preventDefault(); dropzone.classList.remove('hover');
    addFiles([...e.dataTransfer.files]);
  });
  dropzone.addEventListener('click', e => {
    if (e.target.tagName !== 'BUTTON') fileInput.click();
  });
}

if (fileInput) {
  fileInput.addEventListener('change', () => {
    addFiles([...fileInput.files]);
    fileInput.value = '';
  });
}

// ── Adicionar arquivos à fila ────────────────
function addFiles(files) {
  files.forEach(file => {
    const ext = file.name.split('.').pop().toLowerCase();
    if (!ALLOWED_EXT.includes(ext)) {
      showToast(`❌ "${file.name}" — extensão .${ext} não permitida.`, 'danger');
      return;
    }
    if (file.size > MAX_SIZE) {
      showToast(`❌ "${file.name}" — arquivo muito grande (máx. ${fmtSize(MAX_SIZE)}).`, 'danger');
      return;
    }
    // Verifica duplicata nos arquivos já enviados
    const alreadyUploaded = mediaItems.some(m => m.original_name === file.name);
    if (alreadyUploaded) {
      showToast(`⚠️ "${file.name}" já existe neste post. Remova o original antes de reenviar.`, 'warning');
      return;
    }
    // Verifica duplicata na fila atual
    const alreadyQueued = queue.some(q => q.file.name === file.name && q.status !== 'error');
    if (alreadyQueued) {
      showToast(`⚠️ "${file.name}" já está na fila.`, 'warning');
      return;
    }
    // Avisa se maior que limite do servidor (mas ainda deixa tentar)
    const PHP_LIMIT = <?= getPhpUploadLimit() ?>;
    if (file.size > PHP_LIMIT) {
      showToast(`⚠️ "${file.name}" (${fmtSize(file.size)}) excede o limite do servidor (${fmtSize(PHP_LIMIT)}). O upload pode falhar.`, 'warning');
    }
    queue.push({ id: nextId++, file, status: 'wait', progress: 0, result: null, errorMsg: '' });
  });
  renderQueue();
  updateStats();
  updateUploadBtn();
}

// ── Renderizar fila ──────────────────────────
function renderQueue() {
  if (!queueEl) return;
  queueEl.innerHTML = '';
  if (queue.length === 0) {
    statsBar.style.display = 'none';
    btnClearAll.style.display = 'none';
    if (btnClearDone) btnClearDone.style.display = 'none';
    return;
  }

  statsBar.style.display = 'flex';
  btnClearAll.style.display = '';

  queue.forEach(item => {
    const isImg   = item.file.type.startsWith('image');
    const isVideo = item.file.type.startsWith('video');
    const ext     = item.file.name.split('.').pop().toUpperCase();

    const div = document.createElement('div');
    div.className = `q-item ${item.status}`;
    div.id = `qi-${item.id}`;

    let thumbHtml = '';
    if (isImg && item.status === 'wait') {
      thumbHtml = `<img class="q-thumb" src="${URL.createObjectURL(item.file)}" alt="">`;
    } else if (isImg && item.result) {
      thumbHtml = `<img class="q-thumb" src="${item.result.url}" alt="">`;
    } else {
      const color = isVideo ? 'var(--accent2)' : 'var(--accent3)';
      const icon  = isVideo
        ? `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="${color}" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>`
        : `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="${color}" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>`;
      thumbHtml = `<div class="q-icon">${icon}</div>`;
    }

    const statusMap = {
      wait:      '<span class="q-status wait">Aguardando...</span>',
      uploading: '<span class="q-status up">Enviando...</span>',
      done:      `<span class="q-status ok">✓ Enviado em ${item.elapsed ? fmtTime(item.elapsed) : ''}</span>`,
      error:     `<span class="q-status err">✗ ${item.errorMsg}</span>`,
    };

    const canRemove = item.status === 'wait' || item.status === 'done' || item.status === 'error';
    const retryBtn  = item.status === 'error'
      ? `<button class="btn btn-secondary" onclick="retryItem(${item.id})" title="Tentar novamente"
           style="padding:3px 8px;font-size:11px;color:var(--warning);border-color:rgba(245,158,11,.35);flex-shrink:0">
           <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.95"/></svg>
         </button>`
      : '';

    const speedHtml = item.status === 'uploading' && item.speed
      ? `<div class="q-meta">
           <span class="q-speed">${fmtSpeed(item.speed)}</span>
           ${item.eta ? `<span class="q-eta">· ETA ${fmtTime(item.eta)}</span>` : ''}
           <span class="q-pct">${item.progress}%</span>
         </div>`
      : '';

    div.innerHTML = `
      ${thumbHtml}
      <div class="q-info">
        <div class="q-name" title="${esc(item.file.name)}">${esc(item.file.name)}</div>
        <div class="q-size">${fmtSize(item.file.size)} · ${ext}</div>
        ${statusMap[item.status] || ''}
        ${speedHtml}
        <div class="q-bar-wrap" style="${item.status === 'wait' ? 'display:none' : ''}">
          <div class="q-bar ${item.status === 'error' ? 'err' : item.status === 'done' ? 'done' : ''}"
               style="width:${item.progress}%"></div>
        </div>
      </div>
      <div style="display:flex;gap:4px;align-items:center">
        ${retryBtn}
        ${canRemove ? `<button class="q-remove" onclick="removeFromQueue(${item.id})" title="Remover">
          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>` : '<div style="width:26px"></div>'}
      </div>
    `;
    queueEl.appendChild(div);
  });
}

window.removeFromQueue = function(id) {
  queue = queue.filter(i => i.id !== id);
  renderQueue(); updateStats(); updateUploadBtn();
};

window.retryItem = function(id) {
  const item = queue.find(i => i.id === id);
  if (!item || item.status !== 'error') return;
  item.status   = 'wait';
  item.progress = 0;
  item.errorMsg = '';
  renderQueue(); updateStats(); updateUploadBtn();
};

const btnRetryAll = document.getElementById('btn-retry-all');
if (btnRetryAll) {
  btnRetryAll.addEventListener('click', () => {
    queue.filter(i => i.status === 'error').forEach(item => {
      item.status   = 'wait';
      item.progress = 0;
      item.errorMsg = '';
    });
    renderQueue(); updateStats(); updateUploadBtn();
    // Inicia o upload automaticamente após resetar
    startUpload();
  });
}

// ── Upload ───────────────────────────────────
if (btnUpload) {
  btnUpload.addEventListener('click', startUpload);
}

async function startUpload() {
  if (isUploading) return;
  const pending = queue.filter(i => i.status === 'wait');
  if (pending.length === 0) return;

  isUploading = true;
  btnUpload.disabled = true;
  dropzone.classList.add('disabled');
  startOverallTracking();

  // Upload paralelo — usa valor configurado nas Configurações
  let idx = 0;

  async function worker() {
    while (idx < pending.length) {
      const item = pending[idx++];
      await uploadOne(item);
    }
  }

  const workers = Array.from({ length: Math.min(CONCURRENCY, pending.length) }, worker);
  await Promise.all(workers);

  isUploading = false;
  stopOverallTracking();
  dropzone.classList.remove('disabled');
  updateUploadBtn();
  updateStats();
  scheduleMediaGrid();
}

// ── Semáforo global de conexões ──────────────
// Limita o total de requisições HTTP simultâneas (arquivos + chunks)
// Evita saturar o servidor independente do número de arquivos/chunks
const MAX_CONNECTIONS = CONCURRENCY * 2; // ex: 3 arquivos → máx 6 conexões simultâneas

let activeConnections = 0;
const connectionQueue = [];

function acquireConnection() {
  return new Promise(resolve => {
    if (activeConnections < MAX_CONNECTIONS) {
      activeConnections++;
      resolve();
    } else {
      connectionQueue.push(resolve);
    }
  });
}

function releaseConnection() {
  activeConnections--;
  if (connectionQueue.length > 0) {
    const next = connectionQueue.shift();
    activeConnections++;
    next();
  }
}

// Tamanho de cada chunk: 20MB (menos chunks = menos overhead)
const CHUNK_SIZE = 20 * 1024 * 1024;

function uploadOne(item) {
  // Arquivos acima de CHUNK_SIZE usam upload em chunks
  if (item.file.size > CHUNK_SIZE) {
    return uploadChunked(item);
  }
  return uploadDirect(item);
}

// ── Upload direto (arquivos pequenos) ────────
function uploadDirect(item) {
  return new Promise(async resolve => {
    item.status = 'uploading';
    item.progress = 0;
    renderQueueItem(item);

    await acquireConnection();

    const startTime = Date.now();
    let lastLoaded = 0;
    let lastTime   = startTime;

    const fd = new FormData();
    fd.append('file', item.file);
    fd.append('post_id', POST_ID);
    fd.append('csrf_token', CSRF);
    fd.append('upload_token', UPLOAD_TOKEN);

    const xhr = new XMLHttpRequest();
    xhr.timeout = 600000;

    xhr.upload.addEventListener('progress', e => {
      if (e.lengthComputable) {
        item.progress = Math.round((e.loaded / e.total) * 95);
        // Velocidade
        const now  = Date.now();
        const dt   = (now - lastTime) / 1000;
        if (dt > 0.3) {
          item.speed = (e.loaded - lastLoaded) / dt;
          item.eta   = item.speed > 0 ? (e.total - e.loaded) / item.speed : 0;
          lastLoaded = e.loaded; lastTime = now;
        }
        scheduleProgressUpdate(item);
      }
    });

    xhr.addEventListener('load', () => {
      item.elapsed = (Date.now() - startTime) / 1000;
      item.speed   = 0; item.eta = 0;
      releaseConnection();
      handleUploadResponse(xhr, item, resolve);
    });
    xhr.addEventListener('timeout', () => {
      item.elapsed = 0; item.speed = 0;
      releaseConnection();
      item.status = 'error';
      item.progress = 100;
      item.errorMsg = '❌ Timeout — tente reduzir uploads paralelos ou aumente max_execution_time.';
      renderQueueItem(item); updateStatsThrottled(); resolve();
    });
    xhr.addEventListener('error', () => {
      releaseConnection();
      item.status = 'error';
      item.errorMsg = '❌ Falha de rede — verifique sua conexão.';
      renderQueueItem(item); updateStatsThrottled(); resolve();
    });

    xhr.open('POST', BASE_URL + '/admin/ajax_upload.php');
    xhr.send(fd);
  });
}

// ── Upload em chunks paralelos (arquivos grandes) ──
async function uploadChunked(item) {
  item.status = 'uploading';
  item.progress = 0;
  renderQueueItem(item);

  const file        = item.file;
  const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
  const fileId      = 'f' + Date.now().toString(36) + Math.random().toString(36).slice(2);

  // Chunks paralelos por arquivo (semáforo global controla o total real)
  const CHUNK_PARALLEL = Math.min(2, totalChunks);

  // Progresso por chunk
  const chunkProgress = new Array(totalChunks).fill(0);
  function updateProgress() {
    const total = chunkProgress.reduce((a, b) => a + b, 0);
    item.progress = Math.round((total / totalChunks) * 98);
    scheduleProgressUpdate(item);
  }

  let failed  = false;
  let finalResult = null;
  let chunkIdx = 0;

  async function chunkWorker() {
    while (chunkIdx < totalChunks && !failed) {
      const i = chunkIdx++;
      const start = i * CHUNK_SIZE;
      const end   = Math.min(start + CHUNK_SIZE, file.size);
      const chunk = file.slice(start, end);

      const fd = new FormData();
      fd.append('chunk',         chunk);
      fd.append('post_id',       POST_ID);
      fd.append('csrf_token',    CSRF);
      fd.append('upload_token',  UPLOAD_TOKEN);
      fd.append('file_id',       fileId);
      fd.append('chunk_index',   i);
      fd.append('total_chunks',  totalChunks);
      fd.append('original_name', file.name);
      fd.append('total_size',    file.size);

      try {
        const res = await sendChunk(fd, item, i, totalChunks, chunkProgress, updateProgress);
        if (!res.success) {
          failed = true;
          item.status   = 'error';
          item.progress = 100;
          item.errorMsg = '❌ ' + (res.error || 'Erro no chunk ' + i);
          renderQueueItem(item); updateStatsThrottled(); return;
        }
        if (res.status === 'complete') {
          finalResult = res;
        }
      } catch(e) {
        failed = true;
        item.status   = 'error';
        item.progress = 100;
        item.errorMsg = '❌ Falha no chunk ' + i + ': ' + e.message;
        renderQueueItem(item); updateStatsThrottled(); return;
      }
    }
  }

  // Lança N workers em paralelo
  const workers = Array.from({ length: CHUNK_PARALLEL }, chunkWorker);
  await Promise.all(workers);

  if (!failed && finalResult) {
    item.status   = 'done';
    item.progress = 100;
    item.result   = finalResult;
    mediaItems.push({
      id:            finalResult.id,
      original_name: finalResult.original_name || file.name,
      file_path:     finalResult.file_path,
      file_type:     finalResult.file_type,
      file_size:     file.size,
      url:           finalResult.url,
      is_thumb:      false,
    });
    if (!currentThumb && finalResult.file_type === 'image') {
      setThumbnail(finalResult.id, finalResult.file_path, finalResult.url);
    }
    // Captura thumbnail do vídeo no browser (igual ao upload direto)
    if (finalResult.file_type === 'video' && item.file) {
      const mediaEntry = mediaItems[mediaItems.length - 1];
      captureAndSaveThumb(item.file, finalResult.id, mediaEntry);
    }
    renderQueueItem(item); updateStats();
  } else if (!failed) {
    // Todos os chunks enviados mas servidor ainda não montou
    item.status   = 'error';
    item.errorMsg = '❌ Arquivo enviado mas servidor não confirmou montagem.';
    renderQueueItem(item); updateStats();
  }
}

function sendChunk(fd, item, chunkIndex, totalChunks, chunkProgress, updateProgress) {
  return new Promise(async (resolve, reject) => {
    await acquireConnection();

    const xhr = new XMLHttpRequest();
    xhr.timeout = 120000;

    xhr.upload.addEventListener('progress', e => {
      if (e.lengthComputable && chunkProgress) {
        chunkProgress[chunkIndex] = e.loaded / e.total;
        updateProgress();
      }
    });

    xhr.addEventListener('load', () => {
      releaseConnection();
      try {
        let txt = xhr.responseText.trim();
        const j = txt.indexOf('{');
        if (j > 0) txt = txt.substring(j);
        resolve(JSON.parse(txt));
      } catch(e) {
        if (xhr.status === 413) {
          reject(new Error('HTTP 413 — chunk muito grande.'));
        } else {
          reject(new Error('HTTP ' + xhr.status + ' — resposta inválida'));
        }
      }
    });
    xhr.addEventListener('timeout', () => { releaseConnection(); reject(new Error('Timeout no chunk')); });
    xhr.addEventListener('error',   () => { releaseConnection(); reject(new Error('Falha de rede')); });

    xhr.open('POST', BASE_URL + '/admin/ajax_chunk.php');
    xhr.send(fd);
  });
}

// ── Processa resposta do upload direto ───────
function handleUploadResponse(xhr, item, resolve) {
  try {
    let jsonText = xhr.responseText.trim();
    const jsonStart = jsonText.indexOf('{');
    if (jsonStart > 0) jsonText = jsonText.substring(jsonStart);

    const res = JSON.parse(jsonText);
    if (res.success) {
      item.status   = 'done';
      item.progress = 100;
      item.result   = res;
      const mediaEntry = {
        id:            res.id,
        original_name: res.original_name || item.file.name,
        file_path:     res.file_path,
        file_type:     res.file_type,
        file_size:     item.file.size,
        url:           res.url,
        video_thumb:   null,
        is_thumb:      false,
      };
      mediaItems.push(mediaEntry);
      if (!currentThumb && res.file_type === 'image') {
        setThumbnail(res.id, res.file_path, res.url);
      }
      // Captura thumbnail do vídeo no browser e envia para o servidor
      if (res.file_type === 'video' && item.file) {
        captureAndSaveThumb(item.file, res.id, mediaEntry);
      }
    } else {
      item.status   = 'error';
      item.progress = 100;
      item.errorMsg = res.error || 'Erro desconhecido';
    }
  } catch(e) {
    item.status   = 'error';
    item.progress = 100;
    if (xhr.status === 413) {
      item.errorMsg = '❌ HTTP 413 — limite excedido. Verifique OLS Virtual Host → Max Request Body Size.';
    } else if (xhr.status === 0) {
      item.errorMsg = '❌ Conexão interrompida.';
    } else if (xhr.status === 500) {
      item.errorMsg = '❌ Erro interno (HTTP 500) — veja logs do servidor.';
    } else {
      const htmlErr = xhr.responseText.match(/(?:Fatal error|Parse error|Warning).*?(?:<br|<\/b)/i);
      item.errorMsg = htmlErr
        ? '❌ Erro PHP: ' + htmlErr[0].replace(/<[^>]+>/g,'').trim()
        : `❌ Resposta inválida (HTTP ${xhr.status})`;
    }
  }
  renderQueueItem(item); updateStatsThrottled(); resolve();
}

function renderQueueItem(item) {
  const el = document.getElementById(`qi-${item.id}`);
  if (!el) { renderQueue(); return; }
  // atualiza apenas classe e conteúdo sem recriar tudo
  el.className = `q-item ${item.status}`;
  const bar  = el.querySelector('.q-bar');
  const barW = el.querySelector('.q-bar-wrap');
  if (bar) {
    bar.style.width = item.progress + '%';
    bar.className = `q-bar ${item.status === 'error' ? 'err' : item.status === 'done' ? 'done' : ''}`;
  }
  if (barW) barW.style.display = '';
  const statusEl = el.querySelector('.q-status');
  if (statusEl) {
    statusEl.className = `q-status ${item.status === 'done' ? 'ok' : item.status === 'error' ? 'err' : 'up'}`;
    statusEl.textContent = item.status === 'done' ? '✓ Enviado'
      : item.status === 'error' ? '✗ ' + item.errorMsg
      : 'Enviando...';
  }
}

function updateQueueItemProgress(item) {
  const bar = document.querySelector(`#qi-${item.id} .q-bar`);
  if (bar) bar.style.width = item.progress + '%';
}

// ── Stats ────────────────────────────────────
function updateStats() {
  if (!statsBar) return;
  const total = queue.length;
  const done  = queue.filter(i => i.status === 'done').length;
  const err   = queue.filter(i => i.status === 'error').length;
  const wait  = queue.filter(i => i.status === 'wait').length;

  document.getElementById('stat-total').textContent = total;
  document.getElementById('stat-done').textContent  = done;
  document.getElementById('stat-err').textContent   = err;
  document.getElementById('stat-wait').textContent  = wait;

  btnClearDone.style.display = done > 0 ? '' : 'none';

  const sizeEl = document.getElementById('stat-size');
  if (sizeEl) {
    const totalBytes = queue.reduce((s, i) => s + i.file.size, 0);
    sizeEl.textContent = totalBytes > 0 ? fmtSize(totalBytes) + ' total' : '';
  }

  const retryAllBtn = document.getElementById('btn-retry-all');
  if (retryAllBtn) retryAllBtn.style.display = err > 0 && !isUploading ? '' : 'none';
}

function updateUploadBtn() {
  if (!btnUpload) return;
  const pending = queue.filter(i => i.status === 'wait').length;
  btnUpload.disabled = pending === 0 || isUploading;
  btnLabel.textContent = pending > 0
    ? `Enviar ${pending} arquivo${pending > 1 ? 's' : ''}`
    : 'Enviar arquivos';
}

// ── Clear buttons ────────────────────────────
if (btnClearAll) {
  btnClearAll.addEventListener('click', () => {
    queue = queue.filter(i => i.status === 'uploading');
    renderQueue(); updateStats(); updateUploadBtn();
  });
}
if (btnClearDone) {
  btnClearDone.addEventListener('click', () => {
    queue = queue.filter(i => i.status !== 'done');
    renderQueue(); updateStats(); updateUploadBtn();
  });
}

// ── Media — abas + paginação ─────────────────
const PER_PAGE  = <?= (int)getSetting('media_per_post', 12) ?>;
let activeTab   = 'image';
let tabPages    = { image: 1, video: 1, file: 1 };

// Expõe switchTab globalmente (chamada pelo onclick no HTML)
window.switchTab = function(tab) {
  activeTab = tab;
  document.querySelectorAll('.media-tab').forEach(b =>
    b.classList.toggle('active', b.dataset.tab === tab)
  );
  document.querySelectorAll('.tab-panel').forEach(p =>
    p.style.display = (p.id === 'tab-panel-' + tab) ? '' : 'none'
  );
  renderTab(tab);
};

function renderMediaGrid() {
  mediaCount.textContent = mediaItems.length;
  ['image','video'].forEach(t => {
    const count = mediaItems.filter(m => m.file_type === t).length;
    const el = document.getElementById('tab-count-' + t);
    if (el) el.textContent = count;
  });
  renderTab(activeTab);
}

function renderTab(tab) {
  const items   = mediaItems.filter(m => m.file_type === tab);
  const grid    = document.getElementById('media-grid-' + tab);
  const empty   = document.getElementById('empty-' + tab);
  const pagEl   = document.getElementById('pag-' + tab);
  if (!grid) return;

  const page    = tabPages[tab] || 1;
  const total   = items.length;
  const pages   = Math.max(1, Math.ceil(total / PER_PAGE));
  const curPage = Math.min(page, pages);
  tabPages[tab] = curPage;
  const slice   = items.slice((curPage - 1) * PER_PAGE, curPage * PER_PAGE);

  // Empty state
  empty.classList.toggle('show', total === 0);
  grid.style.display = total === 0 ? 'none' : '';

  if (tab === 'file') {
    // Lista de documentos
    grid.className = 'file-list-tab';
    grid.innerHTML = '';
    slice.forEach(m => {
      const ext = m.file_path.split('.').pop().toUpperCase();
      const colors = {PDF:'#ef4444',DOC:'#3b82f6',DOCX:'#3b82f6',XLS:'#10b981',XLSX:'#10b981',ZIP:'#f59e0b',RAR:'#f59e0b'};
      const c = colors[ext] || 'var(--accent3)';
      const row = document.createElement('div');
      row.className = 'file-item';
      row.innerHTML = `
        <div class="file-icon" style="background:${c}22">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="${c}" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <div class="file-info">
          <div class="file-name">${esc(m.original_name)}</div>
          <div class="file-size">${fmtSize(m.file_size)} · ${ext}</div>
        </div>
        <a href="${esc(m.url)}" download="${esc(m.original_name)}" class="file-download" style="font-size:12px;display:flex;align-items:center;gap:4px;color:var(--accent)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Baixar
        </a>
        <button class="mc-del" onclick="deleteMedia(${m.id})" type="button" title="Remover" style="opacity:1;position:static;width:28px;height:28px">
          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>`;
      grid.appendChild(row);
    });
  } else {
    // Grade de imagens / vídeos
    grid.className = 'media-grid';
    grid.innerHTML = '';
    slice.forEach(m => {
      const isThumb = m.file_path === currentThumb;
      const card = document.createElement('div');
      card.className = `media-card ${isThumb ? 'is-thumb' : ''}`;
      card.id = `mc-${m.id}`;

      let inner = '';
      if (tab === 'image') {
        inner = `<img class="mc-img" src="${esc(m.url)}" loading="lazy" alt="">`;
        inner += `<button class="mc-set-thumb" onclick="setThumbClick(${m.id},'${esc(m.file_path)}','${esc(m.url)}')" type="button">
          ${isThumb ? '★ É a capa' : '☆ Definir capa'}
        </button>`;
        if (isThumb) inner += `<div class="mc-thumb-badge">★ CAPA</div>`;
      } else {
        // vídeo — canvas para thumbnail + botão de capa
        const canvasId = `vthumb-admin-${m.id}`;
        inner = `<canvas id="${canvasId}" class="img-cover"></canvas>`;
        // overlay play
        inner += `<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none">
          <div style="width:32px;height:32px;border-radius:50%;background:rgba(255,106,158,.85);display:flex;align-items:center;justify-content:center">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="#fff"><polygon points="5 3 19 12 5 21 5 3"/></svg>
          </div>
        </div>`;
        inner += `<button class="mc-set-thumb" onclick="setThumbClick(${m.id},'${esc(m.file_path)}','${esc(m.url)}','video')" type="button">
          ${isThumb ? '★ É a capa' : '☆ Definir capa'}
        </button>`;
        if (isThumb) inner += `<div class="mc-thumb-badge">★ CAPA</div>`;
        // agenda captura do frame após inserção no DOM
        setTimeout(() => captureVideoThumb(canvasId, m.url), 50);
      }
      inner += `<button class="mc-del" onclick="deleteMedia(${m.id})" type="button" title="Remover arquivo">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>`;
      inner += `<div class="mc-name">${esc(m.original_name)}</div>`;
      card.innerHTML = inner;
      grid.appendChild(card);
    });
  }

  // Paginação inteligente
  pagEl.innerHTML = '';
  if (pages > 1) {
    // Páginas a mostrar: 1, última, e 2 vizinhas da atual
    const show = new Set([1, pages]);
    for (let i = Math.max(2, curPage-2); i <= Math.min(pages-1, curPage+2); i++) show.add(i);
    const sorted = [...show].sort((a,b) => a-b);

    const mk = (label, cls, onclick, disabled=false) => {
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'pag-btn ' + cls;
      b.textContent = label; if (onclick) b.onclick = onclick;
      if (disabled) b.disabled = true;
      return b;
    };

    pagEl.appendChild(mk('← Ant', 'arrow', () => { tabPages[tab]=Math.max(1,curPage-1); renderTab(tab); }, curPage<=1));

    let prev = 0;
    sorted.forEach(p => {
      if (prev && p - prev > 1) pagEl.appendChild(mk('…','dots',null));
      pagEl.appendChild(mk(p, p===curPage?'active':'', () => { tabPages[tab]=p; renderTab(tab); }));
      prev = p;
    });

    pagEl.appendChild(mk('Prox →', 'arrow', () => { tabPages[tab]=Math.min(pages,curPage+1); renderTab(tab); }, curPage>=pages));

    const info = document.createElement('span');
    info.style.cssText = 'margin-left:auto;font-size:11px;color:var(--muted);align-self:center';
    info.textContent = `Página ${curPage} de ${pages}`;
    pagEl.appendChild(info);
  }
}

// ── Captura frame do vídeo via canvas ────────
function captureVideoThumb(canvasId, videoUrl) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;

  const vid = document.createElement('video');
  vid.src        = videoUrl + '#t=1';
  vid.preload    = 'metadata';
  vid.muted      = true;
  vid.playsInline = true;
  vid.style.display = 'none';
  document.body.appendChild(vid);

  const draw = () => {
    try {
      canvas.width  = vid.videoWidth  || 320;
      canvas.height = vid.videoHeight || 180;
      if (canvas.width > 0) {
        canvas.getContext('2d').drawImage(vid, 0, 0, canvas.width, canvas.height);
      }
    } catch(e) { drawAdminFallback(canvas); }
    vid.remove();
  };

  vid.addEventListener('loadeddata', draw);
  vid.addEventListener('canplay',    draw);
  vid.addEventListener('error', () => { drawAdminFallback(canvas); vid.remove(); });
  setTimeout(() => { if (!canvas.width) { drawAdminFallback(canvas); vid.remove(); } }, 4000);
}

function drawAdminFallback(canvas) {
  canvas.width = 320; canvas.height = 180;
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#13131a'; ctx.fillRect(0,0,320,180);
  ctx.fillStyle = '#2a2a3a'; ctx.beginPath(); ctx.arc(160,90,30,0,Math.PI*2); ctx.fill();
  ctx.fillStyle = '#ff6a9e';
  ctx.beginPath(); ctx.moveTo(151,76); ctx.lineTo(151,104); ctx.lineTo(174,90); ctx.closePath(); ctx.fill();
}

// ── Thumbnail ────────────────────────────────
window.setThumbClick = function(mediaId, filePath, url, type) {
  setThumbnail(mediaId, filePath, url, type || 'image');
};

function setThumbnail(mediaId, filePath, url, type) {
  fetch(BASE_URL + '/admin/ajax_thumbnail.php', {
    method: 'POST',
    body: new URLSearchParams({ post_id: POST_ID, media_id: mediaId, csrf_token: CSRF }),
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      currentThumb = filePath;
      mediaItems.forEach(m => m.is_thumb = (m.file_path === filePath));
      renderMediaGrid();
      // Preview da capa: se for vídeo, mostra o canvas capturado; se imagem, mostra img
      if (type === 'video') {
        updateThumbPreviewVideo(mediaId, url);
      } else {
        updateThumbPreview(url);
      }
      showToast('✅ Capa definida!', 'success');
    } else {
      showToast('❌ ' + (res.error || 'Erro ao definir capa'), 'danger');
    }
  });
}

function updateThumbPreview(url) {
  const wrap = document.getElementById('thumb-wrap');
  if (!wrap) return;
  wrap.innerHTML = url
    ? `<img src="${url}" id="thumb-preview" class="thumb-preview" alt="Capa">`
    : `<div id="thumb-placeholder" class="thumb-placeholder">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        <span>Selecione uma imagem<br>como capa do post</span>
       </div>`;
}

function updateThumbPreviewVideo(mediaId, videoUrl) {
  const wrap = document.getElementById('thumb-wrap');
  if (!wrap) return;
  // Mostra canvas com frame do vídeo como preview da capa
  wrap.innerHTML = `
    <div style="position:relative;width:100%;aspect-ratio:9/16;border-radius:10px;overflow:hidden;border:2px solid var(--warning)">
      <canvas id="thumb-canvas-preview" class="img-cover"></canvas>
      <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none">
        <div style="background:rgba(255,106,158,.8);border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="#fff"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        </div>
      </div>
    </div>`;
  captureVideoThumb('thumb-canvas-preview', videoUrl);
}

// ── Deletar mídia ────────────────────────────
window.deleteMedia = function(mediaId) {
  if (!confirm('Remover este arquivo permanentemente?')) return;
  fetch(BASE_URL + '/admin/ajax_delete_media.php', {
    method: 'POST',
    body: new URLSearchParams({ media_id: mediaId, csrf_token: CSRF }),
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      const m = mediaItems.find(x => x.id === mediaId);
      if (m && m.file_path === currentThumb) {
        currentThumb = '';
        updateThumbPreview('');
      }
      mediaItems = mediaItems.filter(x => x.id !== mediaId);
      renderMediaGrid();
      showToast('🗑️ Arquivo removido.', 'success');
    } else {
      showToast('❌ ' + (res.error || 'Erro ao remover'), 'danger');
    }
  });
};

// ── Toast ────────────────────────────────────
function showToast(msg, type) {
  const t = document.createElement('div');
  t.style.cssText = `
    position:fixed;bottom:24px;right:24px;z-index:9999;
    background:${type === 'success' ? 'var(--success)' : 'var(--danger)'};
    color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:600;
    box-shadow:0 4px 20px rgba(0,0,0,.4);
    animation:slideIn .3s ease;
  `;
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 3500);
}

// ── Helpers ──────────────────────────────────
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmtSize(b) {
  if (b >= 1073741824) return (b/1073741824).toFixed(1)+' GB';
  if (b >= 1048576)    return (b/1048576).toFixed(1)+' MB';
  if (b >= 1024)       return (b/1024).toFixed(0)+' KB';
  return b+' B';
}
function fmtSpeed(bps) {
  if (bps >= 1048576) return (bps/1048576).toFixed(1)+' MB/s';
  if (bps >= 1024)    return (bps/1024).toFixed(0)+' KB/s';
  return bps+' B/s';
}
function fmtTime(s) {
  if (s < 0 || !isFinite(s)) return '';
  if (s < 60)  return Math.round(s)+'s';
  if (s < 3600) return Math.floor(s/60)+'m '+Math.round(s%60)+'s';
  return Math.floor(s/3600)+'h '+Math.floor((s%3600)/60)+'m';
}

// ── Progresso geral e velocidade ─────────────
let overallStartTime = 0;
let overallBytesAtStart = 0;
let speedSamples = [];

function getOverallProgress() {
  const all   = queue.filter(i => i.status !== 'wait' || i.progress > 0);
  if (!all.length) return { pct: 0, done: 0, total: 0 };
  const totalBytes = queue.reduce((s, i) => s + i.file.size, 0);
  const sentBytes  = queue.reduce((s, i) => {
    if (i.status === 'done')  return s + i.file.size;
    if (i.status === 'error') return s + i.file.size;
    return s + (i.file.size * (i.progress / 100));
  }, 0);
  return { pct: totalBytes ? Math.round(sentBytes / totalBytes * 100) : 0, done: sentBytes, total: totalBytes };
}

function updateOverallBar() {
  const wrap  = document.getElementById('overall-bar-wrap');
  const bar   = document.getElementById('overall-bar');
  const label = document.getElementById('overall-label');
  const right = document.getElementById('overall-right');
  if (!wrap || !bar) return;

  if (!isUploading) { wrap.classList.remove('show'); return; }
  wrap.classList.add('show');

  const { pct, done, total } = getOverallProgress();
  bar.style.width = pct + '%';

  const now     = Date.now();
  const elapsed = (now - overallStartTime) / 1000;
  const bytesSinceStart = done - overallBytesAtStart;
  const speed   = elapsed > 0.5 ? bytesSinceStart / elapsed : 0;

  // Média deslizante de velocidade (últimas 5 amostras)
  if (speed > 0) {
    speedSamples.push(speed);
    if (speedSamples.length > 5) speedSamples.shift();
  }
  const avgSpeed = speedSamples.length ? speedSamples.reduce((a,b)=>a+b,0)/speedSamples.length : 0;
  const remaining = avgSpeed > 0 ? (total - done) / avgSpeed : 0;

  const done_count  = queue.filter(i => i.status === 'done').length;
  const total_count = queue.length;
  label.textContent = `Enviando ${done_count}/${total_count} arquivos · ${pct}%`;
  right.textContent = avgSpeed > 0
    ? `${fmtSpeed(avgSpeed)} · ETA ${fmtTime(remaining)} · ${fmtSize(done)} de ${fmtSize(total)}`
    : `${fmtSize(done)} de ${fmtSize(total)}`;
}

// Atualiza a barra geral a cada 300ms enquanto está enviando
let overallInterval = null;
function startOverallTracking() {
  overallStartTime    = Date.now();
  overallBytesAtStart = 0;
  speedSamples        = [];
  overallInterval     = setInterval(() => {
    if (!document.hidden) updateOverallBar(); // não atualiza se aba está em background
  }, 500);
}
function stopOverallTracking() {
  clearInterval(overallInterval);
  updateOverallBar();
  setTimeout(() => {
    const wrap = document.getElementById('overall-bar-wrap');
    if (wrap) wrap.classList.remove('show');
  }, 3000);
}

// ── Init ─────────────────────────────────────
renderMediaGrid();

// Se a capa salva é um vídeo, captura o frame ao carregar
<?php if ($thumbIsVideo && $thumbUrl): ?>
captureVideoThumb('thumb-canvas-preview', <?= json_encode($thumbUrl) ?>);
<?php endif; ?>

document.head.insertAdjacentHTML('beforeend', `<style>
@keyframes slideIn { from { transform: translateX(60px); opacity:0; } to { transform: translateX(0); opacity:1; } }
</style>`);

// ── Captura thumbnail do vídeo no browser e salva no servidor ──
function captureAndSaveThumb(file, mediaId, mediaEntry) {
  var objectUrl = URL.createObjectURL(file);
  var vid = document.createElement('video');
  vid.muted = true;
  vid.playsInline = true;
  vid.preload = 'metadata';
  vid.crossOrigin = 'anonymous';

  var captured = false;

  function doCapture() {
    if (captured) return;
    // Só captura se tiver dimensões válidas
    if (!vid.videoWidth || !vid.videoHeight) {
      // Tenta de novo em 500ms
      setTimeout(doCapture, 500);
      return;
    }
    captured = true;
    try {
      var canvas = document.createElement('canvas');
      var w = vid.videoWidth;
      var h = vid.videoHeight;
      if (w > 480) { h = Math.round(h * 480 / w); w = 480; }
      canvas.width  = w;
      canvas.height = h;
      canvas.getContext('2d').drawImage(vid, 0, 0, w, h);
      var dataUrl = canvas.toDataURL('image/jpeg', 0.85);

      // Limpa recursos
      vid.pause();
      vid.removeAttribute('src');
      vid.load();
      URL.revokeObjectURL(objectUrl);

      // Só envia se capturou algo real (> 5KB base64)
      if (dataUrl.length < 5000) return;

      var fd = new FormData();
      fd.append('media_id', mediaId);
      fd.append('thumb_data', dataUrl);
      fetch(BASE_URL + '/admin/ajax_save_thumb.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (d.ok && mediaEntry) {
            mediaEntry.video_thumb = d.video_thumb;
            mediaEntry.thumb_url   = d.url;
            var img = document.getElementById('vthumb-saved-' + mediaId);
            if (img) { img.src = d.url; img.style.display = ''; }
            var cvs = document.getElementById('vthumb-admin-' + mediaId);
            if (cvs) cvs.style.display = 'none';
          }
        })
        .catch(function() {});
    } catch(e) {
      URL.revokeObjectURL(objectUrl);
    }
  }

  // Estratégia: tentar seek no loadedmetadata, confirmar no seeked ou canplay
  vid.addEventListener('loadedmetadata', function() {
    var seekTo = vid.duration > 2 ? 1 : (vid.duration * 0.1) || 0;
    try { vid.currentTime = seekTo; } catch(e) {}
  });

  vid.addEventListener('seeked', doCapture);

  // canplay como fallback — acontece depois de seeked na maioria dos casos
  vid.addEventListener('canplay', function() {
    setTimeout(doCapture, 200);
  });

  // Timeout final — tenta capturar o que tiver
  setTimeout(function() {
    if (!captured) doCapture();
    if (!captured) URL.revokeObjectURL(objectUrl);
  }, 8000);

  vid.src = objectUrl;
  vid.load();
}

// ── Regenerar capas de vídeo — sequencial com status ──
window.regenThumbs = function() {
  var btn = document.getElementById('btn-regen-thumbs');
  if (!btn || btn.disabled) return;

  // Só processa vídeos SEM thumb
  var videos = mediaItems.filter(function(m) {
    return m.file_type === 'video' && !m.video_thumb;
  });

  if (videos.length === 0) {
    btn.textContent = '✅ Todas as capas já existem';
    setTimeout(function(){ btn.textContent = '🖼️ Regenerar capas'; }, 2500);
    return;
  }

  btn.disabled = true;

  // Cria/atualiza barra de progresso
  var progWrap = document.getElementById('regen-progress-wrap');
  if (!progWrap) {
    progWrap = document.createElement('div');
    progWrap.id = 'regen-progress-wrap';
    progWrap.style.cssText = 'margin-top:10px;padding:12px 14px;background:var(--surface2);border:1px solid var(--border2);border-radius:8px;font-size:12px';
    btn.parentNode.insertBefore(progWrap, btn.nextSibling);
  }

  var total = videos.length;
  var done  = 0;
  var errors = 0;

  function updateProgress(current, name) {
    var pct = Math.round((done / total) * 100);
    progWrap.innerHTML =
      '<div style="display:flex;justify-content:space-between;margin-bottom:6px">'
      + '<span style="color:var(--text)">⏳ Gerando capa ' + (done+1) + ' de ' + total + '</span>'
      + '<span style="color:var(--muted)">' + pct + '%</span>'
      + '</div>'
      + '<div style="background:var(--border);border-radius:4px;height:6px;overflow:hidden">'
      + '<div style="background:var(--accent);height:100%;width:' + pct + '%;transition:width .3s;border-radius:4px"></div>'
      + '</div>'
      + (name ? '<div style="color:var(--muted);margin-top:5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">📹 ' + name + '</div>' : '');
  }

  function showDone() {
    var pct = 100;
    progWrap.innerHTML =
      '<div style="display:flex;justify-content:space-between;margin-bottom:6px">'
      + '<span style="color:var(--success)">✅ ' + done + ' capa(s) gerada(s)' + (errors ? ' · ⚠️ ' + errors + ' erro(s)' : '') + '</span>'
      + '<span style="color:var(--muted)">100%</span>'
      + '</div>'
      + '<div style="background:var(--border);border-radius:4px;height:6px;overflow:hidden">'
      + '<div style="background:var(--success);height:100%;width:100%;border-radius:4px"></div>'
      + '</div>';
    btn.disabled = false;
    btn.textContent = '🖼️ Regenerar capas';
    setTimeout(function(){ renderMediaGrid(); }, 800);
    setTimeout(function(){ if(progWrap) progWrap.remove(); }, 4000);
  }

  // Processa UM vídeo por vez (sequencial)
  function processNext(idx) {
    if (idx >= total) { showDone(); return; }

    var m = videos[idx];
    updateProgress(idx, m.original_name);

    var vid = document.createElement('video');
    vid.muted = true;
    vid.playsInline = true;
    vid.preload = 'metadata';
    vid.crossOrigin = 'anonymous';

    var handled = false;
    function next(success) {
      if (handled) return;
      handled = true;
      vid.src = '';
      if (success) done++; else errors++;
      processNext(idx + 1);
    }

    vid.addEventListener('loadeddata', function() {
      vid.currentTime = Math.min(1, (vid.duration || 0) * 0.1);
    });

    vid.addEventListener('seeked', function() {
      try {
        var canvas = document.createElement('canvas');
        var w = Math.min(vid.videoWidth || 480, 480);
        var h = Math.round((vid.videoHeight || 270) * w / (vid.videoWidth || 480));
        canvas.width = w; canvas.height = h;
        canvas.getContext('2d').drawImage(vid, 0, 0, w, h);
        var dataUrl = canvas.toDataURL('image/jpeg', 0.82);

        var fd = new FormData();
        fd.append('media_id', m.id);
        fd.append('thumb_data', dataUrl);
        fetch(BASE_URL + '/admin/ajax_save_thumb.php', { method: 'POST', body: fd })
          .then(function(r) { return r.json(); })
          .then(function(d) {
            if (d.ok) {
              m.video_thumb = d.video_thumb;
              m.thumb_url   = d.url;
            }
            next(!!d.ok);
          })
          .catch(function() { next(false); });
      } catch(e) { next(false); }
    }, { once: true });

    vid.addEventListener('error', function() { next(false); });
    // Timeout por vídeo: 12s
    setTimeout(function() { next(false); }, 12000);

    vid.src = m.url;
    vid.load();
  }

  processNext(0);
};

})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
