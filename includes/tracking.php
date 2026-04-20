<?php
/**
 * includes/tracking.php
 *
 * Só rastreia conversões vindas do Facebook (fbclid ou ?fb= na URL).
 * NÃO dispara PageView em todas as páginas.
 * Persiste o fbclid em cookie por 90 dias para atribuir a compra.
 * Dispara Purchase via Conversions API (server-side) quando o PIX é confirmado.
 */

// ── Captura fbclid e inicializa pixel só para quem veio do FB ──
function trackingCaptureClick(): void {
    $pixelId = getSetting('meta_pixel_id', '');
    if (!$pixelId) return;

    // Suporta ?fbclid= (padrão Meta) e ?fb= (link personalizado)
    // Também lê do $_COOKIE['_fbc_cms'] que já foi setado no auth.php
    $fbclid = $_GET['fbclid'] ?? $_GET['fb'] ?? $_COOKIE['_fbc_cms'] ?? '';
    if (!$fbclid) return;

    // Persiste por 90 dias (pode já ter sido feito no auth.php, redundância segura)
    $expiry = time() + 90 * 86400;
    if (!headers_sent()) {
        setcookie('_fbc_cms', $fbclid, ['expires'=>$expiry,'path'=>'/','samesite'=>'Lax']);
        if (empty($_COOKIE['_fbc_ts'])) {
            setcookie('_fbc_ts', (string)time(), ['expires'=>$expiry,'path'=>'/','samesite'=>'Lax']);
        }
    }

    // Carrega o pixel e dispara PageView imediatamente
    $id  = htmlspecialchars($pixelId);
    $fbc = htmlspecialchars($fbclid);
    echo "<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];
t=b.createElement(e);t.async=!0;t.src=v;
s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}
(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init','{$id}');
fbq('track','PageView');
window._FBC_ID='{$fbc}';
</script>\n";
}

// ── Verifica se veio do Facebook ───────────────────────────────
function trackingFromFacebook(): bool {
    return !empty($_COOKIE['_fbc_cms'])
        || !empty($_GET['fbclid'])
        || !empty($_GET['fb']);
}

// ── Monta fbc no formato exigido pela Meta ─────────────────────
function getFbc(): string {
    $fbclid = $_COOKIE['_fbc_cms'] ?? $_GET['fbclid'] ?? $_GET['fb'] ?? '';
    if (!$fbclid) return '';
    $ts = (int)($_COOKIE['_fbc_ts'] ?? time());
    return 'fb.1.' . $ts . '.' . $fbclid;
}


// ── Captura gclid e carrega gtag só para quem veio do Google Ads ──
function trackingCaptureGoogleClick(): void {
    $adsId = getSetting('google_ads_id', '');
    if (!$adsId) return;

    // Suporta ?gclid= (padrão Google Ads) e ?gad= (link personalizado)
    $gclid = $_GET['gclid'] ?? $_GET['gad'] ?? $_COOKIE['_gcl_cms'] ?? '';
    if (!$gclid) return;

    // Persiste por 90 dias
    $expiry = time() + 90 * 86400;
    if (!headers_sent()) {
        setcookie('_gcl_cms', $gclid, ['expires'=>$expiry,'path'=>'/','samesite'=>'Lax']);
        if (empty($_COOKIE['_gcl_ts'])) {
            setcookie('_gcl_ts', (string)time(), ['expires'=>$expiry,'path'=>'/','samesite'=>'Lax']);
        }
    }

    // Carrega gtag só para quem veio do Google Ads
    $id    = htmlspecialchars($adsId);
    $label = htmlspecialchars(getSetting('google_ads_label', ''));
    $gc    = htmlspecialchars($gclid);
    echo "<script async src='https://www.googletagmanager.com/gtag/js?id={$id}'></script>
<script>
window.dataLayer=window.dataLayer||[];
function gtag(){dataLayer.push(arguments);}
gtag('js',new Date());
gtag('config','{$id}');
window.GADS_ID='{$id}';
window.GADS_LABEL='{$label}';
window.GADS_GCLID='{$gc}';
</script>\n";
}

// ── Verifica se veio do Google Ads ─────────────────────────────
function trackingFromGoogle(): bool {
    return !empty($_COOKIE['_gcl_cms'])
        || !empty($_GET['gclid'])
        || !empty($_GET['gad']);
}

// ── Dispara conversão Google Ads via API (server-side) ─────────
// Usa Google Ads Conversions Upload API
function googleAdsConversion(float $value, string $currency = 'BRL', string $orderId = ''): void {
    if (getSetting('track_purchase','1') !== '1') return;

    $devToken    = getSetting('google_ads_dev_token', '');
    $customerId  = getSetting('google_ads_customer_id', '');
    $actionId    = getSetting('google_ads_conversion_action_id', '');
    $oauthToken  = getSetting('google_ads_oauth_token', '');

    // Fallback browser-side: injeta gtag('event','conversion') via cookie
    // Se não tiver API configurada, o gtag carregado no click já faz o trabalho
    if (!$devToken || !$customerId || !$actionId || !$oauthToken) {
        // Sem API — a conversão browser-side (gtag) é suficiente se o usuário
        // não fechou o browser entre o clique e a compra
        return;
    }

    $gclid = $_COOKIE['_gcl_cms'] ?? $_GET['gclid'] ?? '';
    if (!$gclid) return;

    $conversionTime = date('Y-m-d\TH:i:sP'); // RFC3339

    $payload = [
        'conversions' => [[
            'gclid'          => $gclid,
            'conversionValue' => $value,
            'currencyCode'   => $currency,
            'conversionDateTime' => $conversionTime,
            'orderId'        => $orderId ?: null,
        ]],
        'partialFailure' => true,
    ];

    $cid = preg_replace('/[^0-9]/','',$customerId); // remove hífens
    $url = "https://googleads.googleapis.com/v16/customers/{$cid}/conversionActions/{$actionId}:uploadClickConversions";

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $oauthToken,
                'developer-token: ' . $devToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

// ── Conversions API server-side ────────────────────────────────
function metaServerEvent(string $event, array $userData = [], array $customData = [], string $eventId = '', string $sourceUrl = ''): void {
    $pixelId     = getSetting('meta_pixel_id', '');
    $accessToken = getSetting('meta_access_token', '');
    $testCode    = getSetting('meta_test_event', '');
    if (!$pixelId || !$accessToken) return;

    $fbc = getFbc();
    $fbp = $_COOKIE['_fbp'] ?? '';

    // Purchase sempre envia (atribuição fica na Meta)
    // Outros eventos só se tiver fbc ou fbp
    if ($event !== 'Purchase' && !$fbc && !$fbp) return;

    $ip  = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : ($_SERVER['REMOTE_ADDR'] ?? ''));
    $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // URL do evento — usa a salva na transação ou monta a atual
    if (!$sourceUrl) {
        $sourceUrl = SITE_URL . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    // event_id para deduplicação browser-side vs server-side
    if (!$eventId) {
        $eventId = hash('sha256', $event . '_' . time() . '_' . ($customData['order_id'] ?? rand()));
    }

    // Hasha PII (obrigatório Meta)
    $ud = array_filter([
        'em'                => isset($userData['email']) ? hash('sha256',strtolower(trim($userData['email']))) : null,
        'ph'                => isset($userData['phone']) ? hash('sha256',preg_replace('/\D+/','',trim($userData['phone']))) : null,
        'fn'                => isset($userData['name'])  ? hash('sha256',strtolower(explode(' ',trim($userData['name']))[0])) : null,
        'client_ip_address' => $ip ?: null,
        'client_user_agent' => $ua ?: null,
        'fbp'               => $fbp ?: null,
        'fbc'               => $fbc ?: null,
    ]);

    $payload = ['data' => [[
        'event_name'       => $event,
        'event_time'       => time(),
        'event_id'         => $eventId,
        'event_source_url' => $sourceUrl,
        'action_source'    => 'website',
        'user_data'        => $ud ?: new stdClass(),
        'custom_data'      => $customData ?: new stdClass(),
    ]]];
    if ($testCode) $payload['test_event_code'] = $testCode;

    if (function_exists('curl_init')) {
        $ch = curl_init("https://graph.facebook.com/v19.0/{$pixelId}/events?access_token={$accessToken}");
        curl_setopt_array($ch,[
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

// ── Atalhos ────────────────────────────────────────────────────
function trackLead(array $userData = []): void {
    if (getSetting('track_register','1') !== '1') return;
    metaServerEvent('Lead', $userData);
}

function trackPurchase(float $value, string $currency = 'BRL', array $userData = [], string $orderId = '', string $eventId = '', string $sourceUrl = ''): void {
    if (getSetting('track_purchase','1') !== '1') return;
    // Meta Conversions API
    metaServerEvent('Purchase', $userData, [
        'currency' => $currency,
        'value'    => $value,
        'order_id' => $orderId,
    ], $eventId, $sourceUrl);
    // Google Ads Conversions API
    googleAdsConversion($value, $currency, $orderId);
}

// ── Stubs para não quebrar chamadas antigas ────────────────────
function trackingHeadCode(): string { return ''; }
function trackingBodyCode(): string { return ''; }
function trackingEventJs(string $event, array $params = []): string { return ''; }
