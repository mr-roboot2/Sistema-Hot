<?php
/**
 * deploy.php — Webhook de auto-deploy via GitHub.
 *
 * Fluxo:
 *   GitHub push → POST aqui → valida HMAC-SHA256 → git fetch + reset hard.
 *
 * Configuração no GitHub:
 *   Settings → Webhooks → Add webhook
 *     Payload URL:   https://ninjahot.vip/deploy.php
 *     Content type:  application/json
 *     Secret:        (mesmo valor de DEPLOY_SECRET abaixo)
 *     SSL:           Enable SSL verification
 *     Events:        Just the push event
 */

// ============================================================
// CONFIGURAÇÃO — ajuste antes do primeiro deploy
// ============================================================

// Token compartilhado com o GitHub. Gere uma string longa aleatória,
// ex: `openssl rand -hex 32` no terminal. Mantenha em segredo.
const DEPLOY_SECRET = '21374f50185d82084980718519f39bb1cfd8c618944ec4d9931524008388608b';

// Branch que aciona deploy. Pulls em outras branches são ignorados.
const DEPLOY_BRANCH = 'master';

// Caminho absoluto da raiz do repositório no servidor.
// __DIR__ funciona se este arquivo está na raiz do clone.
const DEPLOY_PATH = __DIR__;

// Binário do git. Use só 'git' se já está no PATH; caso contrário use o path
// completo (ex: '/usr/bin/git'). Descobrir com `which git` via SSH.
const GIT_BIN = 'git';

// Arquivo de log. Coloque fora do diretório público OU bloqueie via .htaccess.
const DEPLOY_LOG = __DIR__ . '/deploy.log';

// ============================================================

header('Content-Type: application/json; charset=utf-8');

function deploy_log(string $msg): void {
    if (DEPLOY_LOG === null) return;
    @file_put_contents(DEPLOY_LOG, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function deploy_fail(int $status, string $reason, array $extra = []): void {
    http_response_code($status);
    deploy_log("FAIL ({$status}): {$reason}");
    echo json_encode(['ok' => false, 'reason' => $reason] + $extra);
    exit;
}

// 1. Só aceita POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    deploy_fail(405, 'method_not_allowed');
}

// 2. Lê payload bruto (necessário para validar a assinatura)
$payload = file_get_contents('php://input');
if (!$payload) {
    deploy_fail(400, 'empty_payload');
}

// 3. Valida assinatura HMAC-SHA256 do GitHub
$signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!$signatureHeader || strpos($signatureHeader, 'sha256=') !== 0) {
    deploy_fail(401, 'missing_signature');
}
$expected = 'sha256=' . hash_hmac('sha256', $payload, DEPLOY_SECRET);
if (!hash_equals($expected, $signatureHeader)) {
    deploy_fail(401, 'invalid_signature');
}

// 4. Filtra evento — aceita 'push' e responde 'ping'
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') {
    deploy_log('PING ok');
    echo json_encode(['ok' => true, 'msg' => 'pong']);
    exit;
}
if ($event !== 'push') {
    deploy_log("ignored event: {$event}");
    echo json_encode(['ok' => true, 'msg' => "ignored event: {$event}"]);
    exit;
}

// 5. Decodifica e valida branch
$data = json_decode($payload, true);
if (!is_array($data)) {
    deploy_fail(400, 'invalid_json');
}
$ref    = $data['ref'] ?? '';
$branch = preg_replace('#^refs/heads/#', '', $ref);
if ($branch !== DEPLOY_BRANCH) {
    deploy_log("ignored branch: {$branch}");
    echo json_encode(['ok' => true, 'msg' => "ignored branch: {$branch}"]);
    exit;
}

// 6. Verifica se shell_exec está disponível (alguns hosts desabilitam)
if (!function_exists('shell_exec')) {
    deploy_fail(500, 'shell_exec_disabled');
}

// 7. Confere se o diretório é mesmo um repo git
$repoPath = DEPLOY_PATH;
if (!is_dir($repoPath . '/.git')) {
    deploy_fail(500, 'not_a_git_repo', ['path' => $repoPath]);
}

// 8. Executa o deploy
//    fetch + reset hard descarta qualquer alteração local em produção,
//    deixando o working tree IGUAL ao origin/<branch>.
//    (Arquivos no .gitignore — config.php, uploads/, cache/ — não são tocados.)
$cmd = 'cd ' . escapeshellarg($repoPath)
     . ' && ' . GIT_BIN . ' fetch --all 2>&1'
     . ' && ' . GIT_BIN . ' reset --hard origin/' . escapeshellarg(DEPLOY_BRANCH) . ' 2>&1';

$output = shell_exec($cmd);

deploy_log("DEPLOY ok (branch={$branch}, commit=" . substr((string)($data['after'] ?? ''), 0, 7) . ")\n" . trim((string)$output));

echo json_encode([
    'ok'     => true,
    'branch' => $branch,
    'commit' => substr((string)($data['after'] ?? ''), 0, 7),
    'output' => $output,
]);
