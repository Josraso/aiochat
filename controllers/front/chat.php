<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'aiochat/classes/AioChatApi.php';
require_once _PS_MODULE_DIR_ . 'aiochat/classes/AioChatContext.php';
require_once _PS_MODULE_DIR_ . 'aiochat/classes/AioChatLive.php';

class AioChatChatModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        header('Content-Type: application/json');

        $action = Tools::getValue('action');

        switch ($action) {
            case 'send':
                $this->handleSend();
                break;
            case 'request_human':
                $this->handleRequestHuman();
                break;
            case 'send_email':
                $this->handleSendEmail();
                break;
            default:
                $this->jsonResponse(['error' => 'Acción no válida']);
        }

        exit;
    }

    private function handleSend()
    {
        $message    = trim(Tools::getValue('message'));
        $sessionId  = Tools::getValue('session_id');
        $history    = Tools::getValue('history');
        $customerName  = Tools::getValue('customer_name', '');
        $customerEmail = Tools::getValue('customer_email', '');

        if (!$message || !$sessionId) {
            $this->jsonResponse(['error' => 'Datos incompletos']);
            return;
        }

        // Guardar conversación y mensaje del cliente
        $conv = AioChatLive::getOrCreateConversation($sessionId, $customerName, $customerEmail);
        AioChatLive::saveMessage($conv['id_conversation'], 'customer', $message);

        // Construir historial de mensajes para la API
        $messages = [];
        if (is_array($history)) {
            foreach ($history as $h) {
                if (isset($h['role']) && isset($h['content'])) {
                    $messages[] = [
                        'role'    => $h['role'] === 'bot' ? 'assistant' : 'user',
                        'content' => (string)$h['content'],
                    ];
                }
            }
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        // Construir system prompt con contexto de la tienda
        $contextBuilder = new AioChatContext();
        $systemPrompt   = $contextBuilder->buildSystemPrompt($message);

        // Llamar a la API
        $api    = new AioChatApi();
        $result = $api->sendMessage($systemPrompt, $messages);

        if (isset($result['error'])) {
            $this->jsonResponse(['error' => $result['error']]);
            return;
        }

        $responseText = $result['response'];

        // Detectar si el bot pide un humano
        $humanRequested = false;
        if (strpos($responseText, '[HUMAN_REQUESTED]') !== false) {
            $humanRequested = true;
            $responseText   = str_replace('[HUMAN_REQUESTED]', '', trim($responseText));
            if (!$responseText) {
                $responseText = 'Entendido, voy a conectarte con una persona. ¿Cómo prefieres que te contactemos?';
            }
            AioChatLive::setStatus($conv['id_conversation'], 'waiting');
        }

        // Guardar respuesta del bot
        AioChatLive::saveMessage($conv['id_conversation'], 'bot', $responseText);

        $this->jsonResponse([
            'response'       => $responseText,
            'human_requested' => $humanRequested,
            'whatsapp'       => Configuration::get('AIOCHAT_WHATSAPP'),
            'phone'          => Configuration::get('AIOCHAT_PHONE'),
            'agent_online'   => (bool)Configuration::get('AIOCHAT_AGENT_ONLINE'),
            'id_conversation' => $conv['id_conversation'],
        ]);
    }

    private function handleRequestHuman()
    {
        $sessionId = Tools::getValue('session_id');
        if (!$sessionId) {
            $this->jsonResponse(['error' => 'Sin sesión']);
            return;
        }

        $conv = AioChatLive::getOrCreateConversation($sessionId);
        AioChatLive::setStatus($conv['id_conversation'], 'waiting');
        AioChatLive::saveMessage($conv['id_conversation'], 'bot', '[El cliente ha solicitado hablar con una persona]');

        $this->jsonResponse([
            'ok'           => true,
            'whatsapp'     => Configuration::get('AIOCHAT_WHATSAPP'),
            'phone'        => Configuration::get('AIOCHAT_PHONE'),
            'agent_online' => (bool)Configuration::get('AIOCHAT_AGENT_ONLINE'),
        ]);
    }

    private function handleSendEmail()
    {
        $name    = Tools::getValue('name');
        $email   = Tools::getValue('email');
        $message = Tools::getValue('message');

        if (!$name || !$email || !$message) {
            $this->jsonResponse(['error' => 'Rellena todos los campos']);
            return;
        }

        $shopEmail = Configuration::get('AIOCHAT_EMAIL') ?: Configuration::get('PS_SHOP_EMAIL');
        $shopName  = Configuration::get('PS_SHOP_NAME');

        $subject = "[{$shopName}] Mensaje desde el chat - {$name}";
        $body    = "Nombre: {$name}\nEmail: {$email}\n\nMensaje:\n{$message}";

        $result = Mail::Send(
            Context::getContext()->language->id,
            'contact',
            $subject,
            ['{message}' => nl2br($body), '{name}' => $name, '{email}' => $email],
            $shopEmail,
            $shopName,
            $email,
            $name
        );

        // Fallback si el template no existe
        if (!$result) {
            mail($shopEmail, $subject, $body, "From: {$email}\r\nReply-To: {$email}");
        }

        $this->jsonResponse(['ok' => true]);
    }

    private function jsonResponse($data)
    {
        echo json_encode($data);
        exit;
    }
}
