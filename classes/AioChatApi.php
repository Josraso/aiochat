<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class AioChatApi
{
    private $apiKey;
    private $model = 'claude-haiku-4-5-20251001';
    private $maxTokens = 1024;

    public function __construct()
    {
        $this->apiKey = Configuration::get('AIOCHAT_API_KEY');
    }

    public function sendMessage($systemPrompt, $messages)
    {
        if (!$this->apiKey) {
            return ['error' => 'API Key no configurada.'];
        }

        $payload = json_encode([
            'model'      => $this->model,
            'max_tokens' => $this->maxTokens,
            'system'     => $systemPrompt,
            'messages'   => $messages,
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$response) {
            return ['error' => 'Error de conexión con la API.'];
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $errorMsg = isset($data['error']['message']) ? $data['error']['message'] : 'Error desconocido';
            return ['error' => $errorMsg];
        }

        $text = isset($data['content'][0]['text']) ? $data['content'][0]['text'] : '';
        return ['response' => $text];
    }
}
