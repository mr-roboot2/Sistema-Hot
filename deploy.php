<?php
/**
 * deploy.php — Auto-deploy via webhook do GitHub.
 *
 * Padrão:
 *   - HMAC-SHA256 com secret lido de arquivo FORA do public_html
 *   - git pull no diretório atual
 *   - Restart do OpenLiteSpeed via sudo (NOPASSWD em /etc/sudoers.d/)
 *   - Log estruturado em storage/logs/deploy.log
 *
 * Configuração no GitHub:
 *   Settings → Webhooks → Add webhook
 *     Payload URL:   https://ninjahot.vip/deploy.php
 *     Content type:  application/json
 *     Secret:        (mesmo conteúdo do arquivo abaixo)
 *     SSL:           Enable SSL verification
 *     Events:        Just the push event
 */

// Path do arquivo com o HMAC secret. FICA FORA DO PUBLIC_HTML.
// Pode ser sobrescrito via env var WEBHOOK_SECRET_FILE pra testes locais.
$secret_file = getenv('WEBHOOK_SECRET_FILE')
    ?: '/home/ninjahot.vip/ninjahot-webhook.secret';

header('Content-Type: application/json; charset=utf-8');

// 1. Só aceita POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'method not allowed']);
    exit;
}

// 2. Carrega o secret
if (!is_readable($secret_file)) {
    http_response_code(500);
    error_log('deploy: secret file not readable: ' . $secret_file);
    echo json_encode(['status' => 'error', 'message' => 'server misconfigured']);
    exit;
}
$secret = trim(file_get_contents($secret_file));
if ($secret === '') {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'server misconfigured']);
    exit;
}

// 3. Lê body bruto e valida assinatura HMAC-SHA256
$body       = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if (!preg_match('/^sha256=([a-f0-9]{64})$/', $sig_header, $m)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'invalid signature format']);
    exit;
}
$received = $m[1];
$expected = hash_hmac('sha256', $body, $secret);
if (!hash_equals($expected, $received)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'invalid signature']);
    exit;
}

// 4. Trata ping do GitHub (primeiro setup do webhook)
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') {
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'pong']);
    exit;
}

// 5. Só aceita push events
if ($event !== 'push') {
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'message' => "event=$event"]);
    exit;
}

// 6. Parse payload e filtra por branch master
$payload = json_decode($body, true);
if (!is_array($payload) || !isset($payload['ref'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'invalid payload']);
    exit;
}
if ($payload['ref'] !== 'refs/heads/master') {
    http_response_code(200);
    echo json_encode([
        'status'  => 'ignored',
        'message' => 'not master branch',
        'ref'     => $payload['ref'],
    ]);
    exit;
}

// 7. Verifica exec disponível
if (!function_exists('exec')) {
    http_response_code(500);
    error_log('deploy: exec desabilitado no php.ini');
    echo json_encode(['status' => 'error', 'message' => 'exec disabled']);
    exit;
}

// 8. Executa git pull no diretório do projeto, capturando exit code
$project_dir   = __DIR__;
$cmd           = 'cd ' . escapeshellarg($project_dir) . ' && git pull 2>&1';
$output_lines  = [];
$exit_code     = 0;
exec($cmd, $output_lines, $exit_code);
$output = trim(implode("\n", $output_lines));

// 9. Extrai metadados do commit pra log e resposta
$commit  = $payload['head_commit']['id']               ?? 'unknown';
$author  = $payload['head_commit']['author']['name']   ?? 'unknown';
$message = $payload['head_commit']['message']          ?? '';

// 10. Loga em storage/logs/deploy.log
$log_dir = $project_dir . '/storage/logs';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}
$log_entry  = '[' . date('Y-m-d H:i:s') . '] ';
$log_entry .= "commit=$commit by=$author\n";
$log_entry .= $output . "\n";
$log_entry .= str_repeat('-', 60) . "\n";
@file_put_contents($log_dir . '/deploy.log', $log_entry, FILE_APPEND | LOCK_EX);

// 11. Sucesso = exit code 0 do git pull
$success = ($exit_code === 0);

// 12. Restart do OpenLiteSpeed (pra recarregar .htaccess, OPcache e workers).
// Requer regra sudoers NOPASSWD em /etc/sudoers.d/deploy-lsws:
//   <php-user> ALL=(root) NOPASSWD: /usr/local/lsws/bin/lswsctrl restart
// Se a regra não existir, o restart falha mas o deploy continua sendo reportado.
$restart_note = 'skipped';
if ($success) {
    $restart_output_lines = [];
    $restart_exit         = 0;
    exec('sudo -n /usr/local/lsws/bin/lswsctrl restart 2>&1', $restart_output_lines, $restart_exit);
    $restart_note = ($restart_exit === 0) ? 'ok' : 'failed exit=' . $restart_exit;
    $restart_log  = '[' . date('Y-m-d H:i:s') . '] lsws restart: ' . $restart_note . "\n";
    $restart_log .= trim(implode("\n", $restart_output_lines)) . "\n";
    $restart_log .= str_repeat('-', 60) . "\n";
    @file_put_contents($log_dir . '/deploy.log', $restart_log, FILE_APPEND | LOCK_EX);
}

http_response_code($success ? 200 : 500);
echo json_encode([
    'status'      => $success ? 'ok' : 'error',
    'exit_code'   => $exit_code,
    'commit'      => $commit,
    'author'      => $author,
    'message'     => $message,
    'output'       => $output,
    'lsws_restart' => $restart_note,
]);
