<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'aiochat/classes/AioChatLive.php';

class AioChatLivechatModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl  = true;

    public function initContent()
    {
        @ini_set('display_errors', 0);
        @error_reporting(0);

        $action    = Tools::getValue('action');
        $sessionId = Tools::getValue('session_id');

        if (!$sessionId) {
            $this->jsonResponse(['error' => 'Sin sesión']);
            return;
        }

        switch ($action) {
            case 'poll':
                $this->handlePoll($sessionId);
                break;
            case 'send':
                $this->handleSend($sessionId);
                break;
            case 'agent_poll':
                $this->handleAgentPoll();
                break;
            case 'agent_send':
                $this->handleAgentSend();
                break;
            case 'agent_close':
                $this->handleAgentClose();
                break;
            default:
                $this->jsonResponse(['error' => 'Acción no válida']);
        }
    }

    private function jsonResponse($data)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data);
        exit;
    }

    // Cliente hace polling para recibir mensajes del agente
    private function handlePoll($sessionId)
    {
        $since = Tools::getValue('since');
        $conv  = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'aiochat_conversations` WHERE session_id = "' . pSQL($sessionId) . '"'
        );

        if (!$conv) {
            $this->jsonResponse(['messages' => [], 'status' => 'bot']);
            return;
        }

        $messages = AioChatLive::getMessages($conv['id_conversation'], $since);
        // Solo enviar mensajes del agente en el polling de live
        $agentMessages = array_filter($messages, function($m) { return $m['sender'] === 'agent'; });

        $this->jsonResponse([
            'messages' => array_values($agentMessages),
            'status'   => $conv['status'],
        ]);
    }

    // Cliente envía mensaje al agente
    private function handleSend($sessionId)
    {
        $message = trim(Tools::getValue('message'));
        $conv    = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'aiochat_conversations` WHERE session_id = "' . pSQL($sessionId) . '"'
        );

        if (!$conv || !$message) {
            $this->jsonResponse(['error' => 'Error al enviar']);
            return;
        }

        AioChatLive::saveMessage($conv['id_conversation'], 'customer', $message);
        $this->jsonResponse(['ok' => true]);
    }

    // Agente hace polling para ver conversaciones pendientes y mensajes
    private function handleAgentPoll()
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'No autorizado']);
            return;
        }

        $idConv = (int)Tools::getValue('id_conversation');
        $since  = Tools::getValue('since');

        if ($idConv) {
            $messages = AioChatLive::getMessages($idConv, $since);
            $this->jsonResponse(['messages' => $messages]);
        } else {
            $pending = AioChatLive::getPendingConversations();
            $this->jsonResponse(['conversations' => $pending]);
        }
    }

    // Agente envía mensaje al cliente
    private function handleAgentSend()
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'No autorizado']);
            return;
        }

        $idConv  = (int)Tools::getValue('id_conversation');
        $message = trim(Tools::getValue('message'));

        if (!$idConv || !$message) {
            $this->jsonResponse(['error' => 'Datos incompletos']);
            return;
        }

        AioChatLive::saveMessage($idConv, 'agent', $message);
        AioChatLive::setStatus($idConv, 'live');
        $this->jsonResponse(['ok' => true]);
    }

    // Agente cierra la conversación
    private function handleAgentClose()
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'No autorizado']);
            return;
        }

        $idConv = (int)Tools::getValue('id_conversation');
        if ($idConv) {
            AioChatLive::setStatus($idConv, 'closed');
            AioChatLive::saveMessage($idConv, 'agent', 'Conversación cerrada por el agente. ¡Hasta pronto!');
        }
        $this->jsonResponse(['ok' => true]);
    }

    private function isAdmin()
    {
        // Verificar token simple de administrador
        $token = Tools::getValue('agent_token');
        return $token && $token === md5(Configuration::get('AIOCHAT_API_KEY') . 'agent');
    }
}
