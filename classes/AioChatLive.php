<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class AioChatLive
{
    public static function getOrCreateConversation($sessionId, $customerName = '', $customerEmail = '')
    {
        $conv = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'aiochat_conversations` WHERE session_id = "' . pSQL($sessionId) . '"'
        );

        if (!$conv) {
            Db::getInstance()->insert('aiochat_conversations', [
                'session_id'     => pSQL($sessionId),
                'id_customer'    => (int)(Context::getContext()->customer->id ?: 0),
                'customer_name'  => pSQL($customerName),
                'customer_email' => pSQL($customerEmail),
                'status'         => 'bot',
                'date_add'       => date('Y-m-d H:i:s'),
                'date_upd'       => date('Y-m-d H:i:s'),
            ]);
            $conv = Db::getInstance()->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'aiochat_conversations` WHERE session_id = "' . pSQL($sessionId) . '"'
            );
        }

        return $conv;
    }

    public static function saveMessage($idConversation, $sender, $message)
    {
        Db::getInstance()->insert('aiochat_messages', [
            'id_conversation' => (int)$idConversation,
            'sender'          => pSQL($sender),
            'message'         => pSQL($message),
            'date_add'        => date('Y-m-d H:i:s'),
        ]);

        Db::getInstance()->update('aiochat_conversations', [
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_conversation = ' . (int)$idConversation);
    }

    public static function setStatus($idConversation, $status)
    {
        Db::getInstance()->update('aiochat_conversations', [
            'status'   => pSQL($status),
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_conversation = ' . (int)$idConversation);
    }

    public static function getMessages($idConversation, $since = null)
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'aiochat_messages` 
                WHERE id_conversation = ' . (int)$idConversation;
        if ($since) {
            $sql .= ' AND date_add > "' . pSQL($since) . '"';
        }
        $sql .= ' ORDER BY date_add ASC';
        return Db::getInstance()->executeS($sql);
    }

    public static function getPendingConversations()
    {
        return Db::getInstance()->executeS(
            'SELECT c.*, 
                    (SELECT message FROM `' . _DB_PREFIX_ . 'aiochat_messages` m WHERE m.id_conversation = c.id_conversation ORDER BY date_add DESC LIMIT 1) as last_message
             FROM `' . _DB_PREFIX_ . 'aiochat_conversations` c
             WHERE c.status IN ("waiting", "live")
             ORDER BY c.date_upd DESC'
        );
    }
}
