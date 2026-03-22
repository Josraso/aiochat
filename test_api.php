<?php
/**
 * AioChat - Script de diagnóstico de API
 * Acceder en browser: /modules/aiochat/test_api.php
 * BORRAR este archivo después del diagnóstico
 */

// Cargar PrestaShop
$psDirs = [
    dirname(dirname(dirname(__FILE__))),           // 3 niveles arriba (modules/aiochat/)
    dirname(dirname(dirname(dirname(__FILE__)))),  // 4 niveles
];
$loaded = false;
foreach ($psDirs as $dir) {
    if (file_exists($dir . '/config/config.inc.php')) {
        require_once $dir . '/config/config.inc.php';
        $loaded = true;
        break;
    }
}

header('Content-Type: text/plain; charset=utf-8');

if (!$loaded) {
    echo "ERROR: No se pudo cargar PrestaShop. Coloca este archivo en /modules/aiochat/\n";
    exit;
}

echo "=== AioChat - Diagnóstico API ===\n\n";

// 1. API Key
$apiKey = Configuration::get('AIOCHAT_API_KEY');
if (!$apiKey) {
    echo "[FAIL] API Key NO configurada en el módulo.\n";
    echo "       Ve a Admin → Módulos → AioChat → Configurar y añade tu API Key de Anthropic.\n\n";
} else {
    $masked = substr($apiKey, 0, 10) . '...' . substr($apiKey, -4);
    echo "[OK]   API Key encontrada: {$masked}\n\n";
}

// 2. cURL disponible
if (!function_exists('curl_init')) {
    echo "[FAIL] cURL NO está disponible en este servidor.\n";
    echo "       Habla con tu hosting para que activen la extensión cURL de PHP.\n\n";
    exit;
} else {
    echo "[OK]   cURL disponible.\n";
}

// 3. Conectividad a api.anthropic.com
echo "[...] Probando conexión a api.anthropic.com...\n";
$ch = curl_init('https://api.anthropic.com/v1/models');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => ['x-api-key: ' . ($apiKey ?: 'test'), 'anthropic-version: 2023-06-01'],
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo "[FAIL] cURL error al conectar: {$curlErr}\n";
    echo "       El servidor no puede alcanzar api.anthropic.com.\n";
    echo "       Posibles causas: firewall, DNS, certificado SSL bloqueado.\n\n";
} elseif ($httpCode === 0) {
    echo "[FAIL] Sin respuesta del servidor (timeout o bloqueo).\n\n";
} else {
    echo "[OK]   Conectado. HTTP {$httpCode}\n";
    if ($httpCode === 401) {
        echo "[FAIL] API Key INVÁLIDA o revocada. Comprueba en console.anthropic.com\n\n";
    } elseif ($httpCode === 200 || $httpCode === 403) {
        echo "[OK]   API Key parece válida.\n\n";
    } else {
        echo "       Respuesta: " . substr($resp, 0, 300) . "\n\n";
    }
}

// 4. Test real de mensaje
if ($apiKey && !$curlErr && $httpCode !== 0) {
    echo "[...] Enviando mensaje de prueba a Claude...\n";
    $payload = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 50,
        'messages'   => [['role' => 'user', 'content' => 'Responde solo: OK']],
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        echo "[FAIL] cURL: {$curlErr}\n";
    } elseif ($httpCode !== 200) {
        $data = json_decode($resp, true);
        $msg  = isset($data['error']['message']) ? $data['error']['message'] : $resp;
        echo "[FAIL] HTTP {$httpCode}: {$msg}\n";
    } else {
        $data = json_decode($resp, true);
        $text = isset($data['content'][0]['text']) ? $data['content'][0]['text'] : '(sin texto)';
        echo "[OK]   Claude respondió: \"{$text}\"\n";
        echo "\n¡TODO FUNCIONA! El chat debería responder correctamente.\n";
    }
}

echo "\n=== Fin del diagnóstico ===\n";
echo "IMPORTANTE: Borra este archivo del servidor cuando termines.\n";
