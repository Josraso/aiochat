<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'aiochat/classes/AioChatApi.php';
require_once _PS_MODULE_DIR_ . 'aiochat/classes/AioChatContext.php';
require_once _PS_MODULE_DIR_ . 'aiochat/classes/AioChatLive.php';

class AioChatChatModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl = true;

    public function initContent()
    {
        // Evitar que notices/warnings de PHP corrompan el JSON
        @ini_set('display_errors', 0);
        @error_reporting(0);

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
            case 'debug_prompt':
                $this->handleDebugPrompt();
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
        $historyRaw = Tools::getValue('history');
        $history    = json_decode($historyRaw, true);
        if (!is_array($history)) {
            $history = [];
        }
        $customerName  = Tools::getValue('customer_name', '');
        $customerEmail = Tools::getValue('customer_email', '');

        if (!$message || !$sessionId) {
            $this->jsonResponse(['error' => 'Datos incompletos']);
            return;
        }

        // Guardar conversación y mensaje del cliente
        try {
            $conv = AioChatLive::getOrCreateConversation($sessionId, $customerName, $customerEmail);
            AioChatLive::saveMessage($conv['id_conversation'], 'customer', $message);
            $convId = $conv['id_conversation'];
        } catch (Exception $e) {
            $convId = 0;
        }

        // Construir historial de mensajes para la API
        $messages = [];
        foreach ($history as $h) {
            if (isset($h['role']) && isset($h['content'])) {
                $messages[] = [
                    'role'    => $h['role'] === 'bot' ? 'assistant' : 'user',
                    'content' => (string)$h['content'],
                ];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        // Detectar cliente logueado para incluir sus pedidos en el contexto
        $customer   = Context::getContext()->customer;
        $idCustomer = ($customer instanceof Customer && $customer->isLogged()) ? (int)$customer->id : 0;

        // Construir system prompt con contexto de la tienda
        try {
            $contextBuilder = new AioChatContext();
            $systemPrompt   = $contextBuilder->buildSystemPrompt($message, $idCustomer);
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                '[AIOCHAT] Error construyendo system prompt: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine(),
                3, null, 'AioChat'
            );
            $shopName     = Configuration::get('PS_SHOP_NAME');
            $botName      = Configuration::get('AIOCHAT_BOT_NAME') ?: 'Asistente';
            $systemPrompt = "Eres {$botName}, el asistente virtual de \"{$shopName}\". Ayuda al cliente con sus preguntas de forma amable y concisa.";
        }

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
            if ($convId) {
                AioChatLive::setStatus($convId, 'waiting');
            }
        }

        // Guardar respuesta del bot
        if ($convId) {
            AioChatLive::saveMessage($convId, 'bot', $responseText);
        }

        $this->jsonResponse([
            'response'        => $responseText,
            'human_requested' => $humanRequested,
            'whatsapp'        => Configuration::get('AIOCHAT_WHATSAPP'),
            'phone'           => Configuration::get('AIOCHAT_PHONE'),
            'agent_online'    => (bool)Configuration::get('AIOCHAT_AGENT_ONLINE'),
            'id_conversation' => $convId,
        ]);
    }

    private function handleRequestHuman()
    {
        $sessionId = Tools::getValue('session_id');
        if (!$sessionId) {
            $this->jsonResponse(['error' => 'Sin sesión']);
            return;
        }

        try {
            $conv = AioChatLive::getOrCreateConversation($sessionId);
            AioChatLive::setStatus($conv['id_conversation'], 'waiting');
            AioChatLive::saveMessage($conv['id_conversation'], 'bot', '[El cliente ha solicitado hablar con una persona]');
        } catch (Exception $e) {
            // DB puede no estar disponible
        }

        $this->jsonResponse([
            'ok'           => true,
            'whatsapp'     => Configuration::get('AIOCHAT_WHATSAPP'),
            'phone'        => Configuration::get('AIOCHAT_PHONE'),
            'agent_online' => (bool)Configuration::get('AIOCHAT_AGENT_ONLINE'),
        ]);
    }

    /**
     * Devuelve el system prompt completo que ve la IA para un mensaje dado.
     * Solo accesible con el token = API key configurada en el módulo.
     * Uso: POST action=debug_prompt&token=TU_API_KEY&message=sandalias talla 33
     */
    private function handleDebugPrompt()
    {
        $token   = Tools::getValue('token');
        $apiKey  = Configuration::get('AIOCHAT_API_KEY');

        if (!$token || !$apiKey || $token !== $apiKey) {
            $this->jsonResponse(['error' => 'No autorizado']);
            return;
        }

        $message = Tools::getValue('message', '(mensaje de prueba)');

        @error_reporting(E_ALL);
        $contextBuilder = new AioChatContext();
        $prompt = $contextBuilder->buildSystemPrompt($message);

        $this->jsonResponse([
            'message'       => $message,
            'prompt_length' => strlen($prompt),
            'prompt'        => $prompt,
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
        // Limpiar cualquier output previo (notices, warnings, HTML de PS)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data);
        exit;
    }
}
