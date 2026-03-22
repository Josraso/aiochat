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
            && $this->registerHook('displayFooterAfter')
            && $this->registerHook('displayHeader')
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

    public function getContent()
    {
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
            'aiochat_agent_online' => Configuration::get('AIOCHAT_AGENT_ONLINE'),
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

    public function hookDisplayFooterAfter($params)
    {
        return $this->renderChatWidget();
    }

    private function renderChatWidget()
    {
        $idLang = $this->context->language->id;
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
        ]);

        return $this->display(__FILE__, 'views/templates/front/chat.tpl');
    }
}
