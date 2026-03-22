<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'aiochat/classes/AioChatLive.php';

class AioChatLivechatModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        header('Content-Type: application/json');

        $action    = Tools::getValue('action');
        $sessionId = Tools::getValue('session_id');

        if (!$sessionId) {
            echo json_encode(['error' => 'Sin sesión']);
            exit;
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
                echo json_encode(['error' => 'Acción no válida']);
        }

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
            echo json_encode(['messages' => [], 'status' => 'bot']);
            return;
        }

        $messages = AioChatLive::getMessages($conv['id_conversation'], $since);
        // Solo enviar mensajes del agente en el polling de live
        $agentMessages = array_filter($messages, function($m) { return $m['sender'] === 'agent'; });

        echo json_encode([
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
            echo json_encode(['error' => 'Error al enviar']);
            return;
        }

        AioChatLive::saveMessage($conv['id_conversation'], 'customer', $message);
        echo json_encode(['ok' => true]);
    }

    // Agente hace polling para ver conversaciones pendientes y mensajes
    private function handleAgentPoll()
    {
        if (!$this->isAdmin()) {
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $idConv = (int)Tools::getValue('id_conversation');
        $since  = Tools::getValue('since');

        if ($idConv) {
            $messages = AioChatLive::getMessages($idConv, $since);
            echo json_encode(['messages' => $messages]);
        } else {
            $pending = AioChatLive::getPendingConversations();
            echo json_encode(['conversations' => $pending]);
        }
    }

    // Agente envía mensaje al cliente
    private function handleAgentSend()
    {
        if (!$this->isAdmin()) {
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $idConv  = (int)Tools::getValue('id_conversation');
        $message = trim(Tools::getValue('message'));

        if (!$idConv || !$message) {
            echo json_encode(['error' => 'Datos incompletos']);
            return;
        }

        AioChatLive::saveMessage($idConv, 'agent', $message);
        AioChatLive::setStatus($idConv, 'live');
        echo json_encode(['ok' => true]);
    }

    // Agente cierra la conversación
    private function handleAgentClose()
    {
        if (!$this->isAdmin()) {
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $idConv = (int)Tools::getValue('id_conversation');
        if ($idConv) {
            AioChatLive::setStatus($idConv, 'closed');
            AioChatLive::saveMessage($idConv, 'agent', 'Conversación cerrada por el agente. ¡Hasta pronto!');
        }
        echo json_encode(['ok' => true]);
    }

    private function isAdmin()
    {
        // Verificar token simple de administrador
        $token = Tools::getValue('agent_token');
        return $token && $token === md5(Configuration::get('AIOCHAT_API_KEY') . 'agent');
    }
}
