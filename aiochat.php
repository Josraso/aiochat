<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

class AioChat extends Module
{
    public function __construct()
    {
        $this->name = 'aiochat';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Jose';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->module_key = '';

        parent::__construct();

        $this->displayName = $this->l('AI Chat Assistant');
        $this->description = $this->l('Chatbot con IA para atender clientes en tu tienda.');
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayFooter')
            && $this->registerHook('displayBeforeBodyClosingTag')
            && $this->createTables();
    }

    public function uninstall()
    {
        return parent::uninstall()
            && $this->dropTables();
    }

    private function createTables()
    {
        $sql = [];

        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'aiochat_conversations` (
            `id_conversation` INT(11) NOT NULL AUTO_INCREMENT,
            `session_id` VARCHAR(64) NOT NULL,
            `id_customer` INT(11) DEFAULT 0,
            `customer_name` VARCHAR(128) DEFAULT "",
            `customer_email` VARCHAR(128) DEFAULT "",
            `status` ENUM("bot","waiting","live","closed") DEFAULT "bot",
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_conversation`),
            KEY `session_id` (`session_id`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'aiochat_messages` (
            `id_message` INT(11) NOT NULL AUTO_INCREMENT,
            `id_conversation` INT(11) NOT NULL,
            `sender` ENUM("customer","bot","agent") DEFAULT "customer",
            `message` TEXT NOT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_message`),
            KEY `id_conversation` (`id_conversation`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'aiochat_documents` (
            `id_document` INT(11) NOT NULL AUTO_INCREMENT,
            `filename` VARCHAR(255) NOT NULL,
            `original_name` VARCHAR(255) NOT NULL,
            `content` LONGTEXT NOT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_document`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }
        return true;
    }

    private function dropTables()
    {
        $tables = ['aiochat_conversations', 'aiochat_messages', 'aiochat_documents'];
        foreach ($tables as $table) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
        }
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* DIAGNÓSTICO                                                          */
    /* ------------------------------------------------------------------ */

    private function handleDiagnosticAjax()
    {
        $test   = Tools::getValue('aiochat_test');
        switch ($test) {
            case 'api':  $result = $this->diagTestApi();  break;
            case 'db':   $result = $this->diagTestDb();   break;
            case 'docs': $result = $this->diagTestDocs(); break;
            default:     $result = ['ok' => false, 'message' => 'Test desconocido'];
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode($result));
    }

    private function diagTestApi()
    {
        $apiKey = Configuration::get('AIOCHAT_API_KEY');
        if (!$apiKey) {
            return ['ok' => false, 'message' => "❌ API Key NO configurada.\n\nIntroduce tu API Key de Anthropic en la sección de arriba y guarda.\nConsíguela en: console.anthropic.com"];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => "❌ cURL no disponible en el servidor.\n\nHabla con tu hosting para que activen la extensión PHP cURL."];
        }

        $payload = json_encode([
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 20,
            'messages'   => [['role' => 'user', 'content' => 'Di solo la palabra: OK']],
        ]);
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['ok' => false, 'message' => "❌ Error de red: {$curlError}\n\nEl servidor no puede alcanzar api.anthropic.com.\nCausas habituales: firewall del hosting, SSL bloqueado, sin salida a internet."];
        }
        $data = json_decode($response, true);
        if ($httpCode === 200) {
            $text      = isset($data['content'][0]['text']) ? trim($data['content'][0]['text']) : '(vacío)';
            $keyMasked = substr($apiKey, 0, 12) . '...' . substr($apiKey, -4);
            return ['ok' => true, 'message' => "✅ Conexión con Anthropic OK.\n\nAPI Key: {$keyMasked}\nModelo:  claude-haiku-4-5-20251001\nRespuesta de prueba: \"{$text}\""];
        } elseif ($httpCode === 401) {
            return ['ok' => false, 'message' => "❌ API Key inválida o revocada (HTTP 401).\n\nComprueba que la key es correcta en console.anthropic.com\ny que no la has regenerado o borrado."];
        } else {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : substr($response, 0, 300);
            return ['ok' => false, 'message' => "❌ Error HTTP {$httpCode}:\n{$msg}"];
        }
    }

    private function diagTestDb()
    {
        $lines = [];
        $allOk = true;
        try {
            $db      = Db::getInstance();
            $version = $db->getValue('SELECT VERSION()');
            $lines[] = "✅ Conexión a la base de datos OK (MySQL {$version})";
        } catch (Exception $e) {
            return ['ok' => false, 'message' => "❌ Error de conexión a la BD:\n" . $e->getMessage()];
        }

        $tables = ['aiochat_conversations', 'aiochat_messages', 'aiochat_documents'];
        foreach ($tables as $t) {
            $exists = $db->executeS('SHOW TABLES LIKE "' . _DB_PREFIX_ . $t . '"');
            if ($exists) {
                $count   = (int)$db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . $t . '`');
                $lines[] = "✅ Tabla {$t}: {$count} registros";
            } else {
                $lines[] = "❌ Tabla {$t}: NO EXISTE — reinstala el módulo";
                $allOk   = false;
            }
        }

        $products = (int)$db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product` WHERE active = 1');
        $lines[]  = "\n📦 Productos activos en la tienda: {$products}";

        $convs = (int)$db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'aiochat_conversations`');
        if ($convs > 0) {
            $lines[] = "💬 Conversaciones registradas: {$convs}";
        }

        return ['ok' => $allOk, 'message' => implode("\n", $lines)];
    }

    private function diagTestDocs()
    {
        try {
            $docs = Db::getInstance()->executeS(
                'SELECT * FROM `' . _DB_PREFIX_ . 'aiochat_documents` ORDER BY date_add DESC'
            );
        } catch (Exception $e) {
            return ['ok' => false, 'message' => "❌ Error al leer tabla de documentos:\n" . $e->getMessage()];
        }

        if (empty($docs)) {
            return ['ok' => true, 'message' => "ℹ️ No hay documentos subidos todavía.\n\nUsa la sección 'Documentos de contexto' para subir PDFs o TXT.\nEl bot los usará para responder preguntas sobre tu tienda."];
        }

        $lines = ['✅ Documentos cargados en el bot: ' . count($docs) . "\n"];
        foreach ($docs as $doc) {
            $chars   = mb_strlen($doc['content']);
            $preview = mb_substr(strip_tags($doc['content']), 0, 120);
            $lines[] = "📄 {$doc['original_name']}\n   Tamaño: {$chars} caracteres | Subido: {$doc['date_add']}\n   Vista previa: {$preview}...";
        }
        return ['ok' => true, 'message' => implode("\n\n", $lines)];
    }

    /* ------------------------------------------------------------------ */
    /* CONFIGURACIÓN                                                        */
    /* ------------------------------------------------------------------ */

    public function getContent()
    {
        // AJAX: diagnóstico del sistema
        if (Tools::isSubmit('aiochat_diagnostic')) {
            $this->handleDiagnosticAjax();
        }

        $output = '';

        if (Tools::isSubmit('submit_aiochat')) {
            Configuration::updateValue('AIOCHAT_API_KEY', Tools::getValue('AIOCHAT_API_KEY'));
            Configuration::updateValue('AIOCHAT_WHATSAPP', Tools::getValue('AIOCHAT_WHATSAPP'));
            Configuration::updateValue('AIOCHAT_PHONE', Tools::getValue('AIOCHAT_PHONE'));
            Configuration::updateValue('AIOCHAT_EMAIL', Tools::getValue('AIOCHAT_EMAIL'));
            Configuration::updateValue('AIOCHAT_YOUTUBE', Tools::getValue('AIOCHAT_YOUTUBE'));
            Configuration::updateValue('AIOCHAT_BOT_NAME', Tools::getValue('AIOCHAT_BOT_NAME'));
            Configuration::updateValue('AIOCHAT_WELCOME_MSG', Tools::getValue('AIOCHAT_WELCOME_MSG'));
            Configuration::updateValue('AIOCHAT_AGENT_ONLINE', Tools::getValue('AIOCHAT_AGENT_ONLINE'));
            Configuration::updateValue('AIOCHAT_CUSTOM_CONTEXT', Tools::getValue('AIOCHAT_CUSTOM_CONTEXT'));
            $pos = Tools::getValue('AIOCHAT_POSITION');
            Configuration::updateValue('AIOCHAT_POSITION', in_array($pos, ['left', 'right']) ? $pos : 'right');
            $output .= $this->displayConfirmation($this->l('Configuración guardada correctamente.'));
        }

        // Subida de PDF
        if (Tools::isSubmit('submit_aiochat_pdf') && isset($_FILES['aiochat_pdf'])) {
            $output .= $this->handlePdfUpload();
        }

        // Borrar documento
        if (Tools::getValue('delete_doc')) {
            $id = (int) Tools::getValue('delete_doc');
            Db::getInstance()->delete('aiochat_documents', 'id_document = ' . $id);
            $output .= $this->displayConfirmation($this->l('Documento eliminado.'));
        }

        $this->context->smarty->assign([
            'aiochat_api_key'      => Configuration::get('AIOCHAT_API_KEY'),
            'aiochat_whatsapp'     => Configuration::get('AIOCHAT_WHATSAPP'),
            'aiochat_phone'        => Configuration::get('AIOCHAT_PHONE'),
            'aiochat_email'        => Configuration::get('AIOCHAT_EMAIL'),
            'aiochat_youtube'      => Configuration::get('AIOCHAT_YOUTUBE'),
            'aiochat_bot_name'     => Configuration::get('AIOCHAT_BOT_NAME'),
            'aiochat_welcome_msg'  => Configuration::get('AIOCHAT_WELCOME_MSG'),
            'aiochat_agent_online'    => Configuration::get('AIOCHAT_AGENT_ONLINE'),
            'aiochat_custom_context'  => Configuration::get('AIOCHAT_CUSTOM_CONTEXT'),
            'aiochat_position'        => Configuration::get('AIOCHAT_POSITION') ?: 'right',
            'aiochat_documents'    => Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'aiochat_documents` ORDER BY date_add DESC'),
            'aiochat_module_url'   => $this->getPathUri(),
            'aiochat_config_url'   => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
            'aiochat_conversations' => Db::getInstance()->executeS(
                'SELECT c.*, COUNT(m.id_message) as total_messages 
                 FROM `' . _DB_PREFIX_ . 'aiochat_conversations` c
                 LEFT JOIN `' . _DB_PREFIX_ . 'aiochat_messages` m ON c.id_conversation = m.id_conversation
                 GROUP BY c.id_conversation
                 ORDER BY c.date_upd DESC LIMIT 50'
            ),
        ]);

        return $output . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    private function handlePdfUpload()
    {
        $file = $_FILES['aiochat_pdf'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return $this->displayError($this->l('Error al subir el archivo.'));
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'txt'])) {
            return $this->displayError($this->l('Solo se permiten archivos PDF o TXT.'));
        }

        $content = '';
        if ($ext === 'txt') {
            $content = file_get_contents($file['tmp_name']);
        } elseif ($ext === 'pdf') {
            // Extraer texto del PDF con pdftotext si está disponible
            $tmpPath = _PS_MODULE_DIR_ . $this->name . '/uploads/' . uniqid() . '.pdf';
            move_uploaded_file($file['tmp_name'], $tmpPath);
            $content = shell_exec('pdftotext ' . escapeshellarg($tmpPath) . ' -');
            if (!$content) {
                $content = '[PDF subido: ' . $file['name'] . ' - sin extracción de texto automática]';
            }
            unlink($tmpPath);
        }

        Db::getInstance()->insert('aiochat_documents', [
            'filename'      => pSQL(uniqid()),
            'original_name' => pSQL($file['name']),
            'content'       => pSQL(substr($content, 0, 100000)),
            'date_add'      => date('Y-m-d H:i:s'),
        ]);

        return $this->displayConfirmation($this->l('Documento subido correctamente.'));
    }

    public function hookDisplayHeader($params)
    {
        $this->context->controller->addCSS($this->getPathUri() . 'views/css/aiochat.css');
        $this->context->controller->addJS($this->getPathUri() . 'views/js/aiochat.js');
        return '';
    }

    public function hookDisplayFooter($params)
    {
        return $this->renderChatWidget();
    }

    public function hookDisplayBeforeBodyClosingTag($params)
    {
        return $this->renderChatWidget();
    }

    private static $widgetRendered = false;

    private function renderChatWidget()
    {
        if (self::$widgetRendered) {
            return '';
        }
        self::$widgetRendered = true;

        $customer = $this->context->customer;

        $this->context->smarty->assign([
            'aiochat_bot_name'     => Configuration::get('AIOCHAT_BOT_NAME') ?: 'Asistente',
            'aiochat_welcome_msg'  => Configuration::get('AIOCHAT_WELCOME_MSG') ?: '¡Hola! ¿En qué puedo ayudarte?',
            'aiochat_whatsapp'     => Configuration::get('AIOCHAT_WHATSAPP'),
            'aiochat_phone'        => Configuration::get('AIOCHAT_PHONE'),
            'aiochat_agent_online' => Configuration::get('AIOCHAT_AGENT_ONLINE'),
            'aiochat_chat_url'     => $this->context->link->getModuleLink($this->name, 'chat'),
            'aiochat_live_url'     => $this->context->link->getModuleLink($this->name, 'livechat'),
            'aiochat_customer_name'  => $customer->isLogged() ? $customer->firstname . ' ' . $customer->lastname : '',
            'aiochat_customer_email' => $customer->isLogged() ? $customer->email : '',
            'aiochat_position'     => Configuration::get('AIOCHAT_POSITION') ?: 'right',
        ]);

        $html = $this->display(__FILE__, 'views/templates/front/chat.tpl');

        // Fallback: si Smarty no renderiza nada, inyectar botón mínimo directamente
        if (empty(trim($html))) {
            $chatUrl = $this->context->link->getModuleLink($this->name, 'chat');
            $html = '<div id="aiochat-widget" style="position:fixed!important;bottom:30px!important;right:30px!important;z-index:2147483647!important;">'
                  . '<div id="aiochat-bubble" onclick="aiochatToggle()" style="width:70px;height:70px;background:#e63946;border-radius:50%;display:flex!important;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 20px rgba(0,0,0,.5);border:3px solid #fff;">'
                  . '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="white"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>'
                  . '</div>'
                  . '<div id="aiochat-box" style="display:none;"></div>'
                  . '<script>var AIOCHAT={chatUrl:"' . addslashes($chatUrl) . '",sessionId:null,history:[],isLive:false,opened:false};'
                  . 'AIOCHAT.sessionId=localStorage.getItem("aiochat_session")||("ac_"+Math.random().toString(36).substr(2,9)+"_"+Date.now());'
                  . 'localStorage.setItem("aiochat_session",AIOCHAT.sessionId);'
                  . 'function aiochatToggle(){var b=document.getElementById("aiochat-box");var btn=document.getElementById("aiochat-bubble");if(!b.style.display||b.style.display==="none"){b.style.display="flex";btn.style.display="none";}else{b.style.display="none";btn.style.display="flex";}}'
                  . '</script>'
                  . '</div>';
        }

        return $html;
    }
}
