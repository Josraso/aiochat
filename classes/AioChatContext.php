<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class AioChatContext
{
    private $idLang;
    private $idShop;

    public function __construct()
    {
        $this->idLang = Context::getContext()->language->id;
        $this->idShop = Context::getContext()->shop->id;
    }

    public function buildSystemPrompt($userMessage)
    {
        $shopName = Configuration::get('PS_SHOP_NAME');
        $botName  = Configuration::get('AIOCHAT_BOT_NAME') ?: 'Asistente';

        $prompt = "Eres {$botName}, el asistente virtual de la tienda online \"{$shopName}\". 
Tu objetivo es ayudar a los clientes con información sobre productos, precios, envíos y cualquier duda sobre la tienda.

REGLAS IMPORTANTES:
- Responde siempre en el mismo idioma que el cliente (español o inglés).
- NUNCA recomiendes productos de otras tiendas o competidores.
- Si no tienes información suficiente para responder, dilo con naturalidad y ofrece contactar con una persona.
- Usa tu conocimiento general sobre productos cuando la descripción de la tienda sea escasa.
- Sé amable, conciso y útil.
- Si el cliente quiere hablar con una persona en cualquier momento, responde exactamente con: [HUMAN_REQUESTED]
- Si detectas que el cliente está frustrado o insatisfecho, ofrece hablar con una persona.

INFORMACIÓN DE LA TIENDA:
";

        $prompt .= $this->getProductsContext($userMessage);
        $prompt .= $this->getShippingContext();
        $prompt .= $this->getCmsContext();
        $prompt .= $this->getDocumentsContext();

        return $prompt;
    }

    private function getProductsContext($userMessage)
    {
        $context = "\n--- PRODUCTOS DISPONIBLES ---\n";

        // Búsqueda relevante por palabras del mensaje
        $words = $this->extractKeywords($userMessage);
        $products = [];

        if (!empty($words)) {
            $searchSql = 'SELECT p.id_product, pl.name, pl.description_short, pl.description, 
                            p.price, p.quantity, cl.name as category
                          FROM `' . _DB_PREFIX_ . 'product` p
                          LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON p.id_product = pl.id_product AND pl.id_lang = ' . (int)$this->idLang . '
                          LEFT JOIN `' . _DB_PREFIX_ . 'category_product` cp ON p.id_product = cp.id_product
                          LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cp.id_category = cl.id_category AND cl.id_lang = ' . (int)$this->idLang . '
                          WHERE p.active = 1 AND (' .
                            implode(' OR ', array_map(function($w) {
                                return 'pl.name LIKE "%' . pSQL($w) . '%" OR pl.description_short LIKE "%' . pSQL($w) . '%"';
                            }, $words)) .
                          ')
                          GROUP BY p.id_product
                          LIMIT 10';

            $products = Db::getInstance()->executeS($searchSql);
        }

        // Si no hay resultados por búsqueda, coger los más vendidos
        if (empty($products)) {
            $products = Db::getInstance()->executeS(
                'SELECT p.id_product, pl.name, pl.description_short, p.price, p.quantity, cl.name as category
                 FROM `' . _DB_PREFIX_ . 'product` p
                 LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON p.id_product = pl.id_product AND pl.id_lang = ' . (int)$this->idLang . '
                 LEFT JOIN `' . _DB_PREFIX_ . 'category_product` cp ON p.id_product = cp.id_product
                 LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cp.id_category = cl.id_category AND cl.id_lang = ' . (int)$this->idLang . '
                 WHERE p.active = 1
                 GROUP BY p.id_product
                 ORDER BY p.id_product DESC
                 LIMIT 20'
            );
        }

        if (empty($products)) {
            return $context . "No hay productos disponibles.\n";
        }

        foreach ($products as $p) {
            try {
                $price = Tools::displayPrice($p['price']);
            } catch (Exception $e) {
                $price = number_format((float)$p['price'], 2) . ' €';
            }
            $stock = (int)$p['quantity'] > 0 ? 'En stock' : 'Sin stock';
            $desc     = strip_tags($p['description_short']);
            $desc     = $desc ?: strip_tags($p['description']);
            $desc     = mb_substr($desc, 0, 200);
            $category = $p['category'] ?: '';

            $context .= "- {$p['name']} | Precio: {$price} | {$stock} | Categoría: {$category}";
            if ($desc) {
                $context .= " | {$desc}";
            }
            $context .= "\n";
        }

        return $context;
    }

    private function getShippingContext()
    {
        $context = "\n--- TARIFAS DE ENVÍO ---\n";

        $carriers = Db::getInstance()->executeS(
            'SELECT c.id_carrier, cl.name, c.url, c.active,
                    cd.price as price_range_start,
                    cr.price as shipping_price,
                    z.name as zone_name
             FROM `' . _DB_PREFIX_ . 'carrier` c
             LEFT JOIN `' . _DB_PREFIX_ . 'carrier_lang` cl ON c.id_carrier = cl.id_carrier AND cl.id_lang = ' . (int)$this->idLang . '
             LEFT JOIN `' . _DB_PREFIX_ . 'delivery` cr ON c.id_carrier = cr.id_carrier
             LEFT JOIN `' . _DB_PREFIX_ . 'range_price` cd ON cr.id_range_price = cd.id_range_price
             LEFT JOIN `' . _DB_PREFIX_ . 'zone` z ON cr.id_zone = z.id_zone
             WHERE c.active = 1 AND c.deleted = 0
             GROUP BY c.id_carrier, z.id_zone
             LIMIT 20'
        );

        if (empty($carriers)) {
            return $context . "Información de envío no disponible.\n";
        }

        foreach ($carriers as $c) {
            $price = isset($c['shipping_price']) ? Tools::displayPrice($c['shipping_price']) : 'variable';
            $zone  = $c['zone_name'] ?: 'general';
            $context .= "- {$c['name']} | Zona: {$zone} | Coste: {$price}\n";
        }

        return $context;
    }

    private function getCmsContext()
    {
        $context = "\n--- INFORMACIÓN DE LA TIENDA (páginas CMS) ---\n";

        $pages = Db::getInstance()->executeS(
            'SELECT cl.meta_title, cl.content
             FROM `' . _DB_PREFIX_ . 'cms` c
             LEFT JOIN `' . _DB_PREFIX_ . 'cms_lang` cl ON c.id_cms = cl.id_cms AND cl.id_lang = ' . (int)$this->idLang . '
             WHERE c.active = 1
             LIMIT 10'
        );

        if (empty($pages)) {
            return $context . "Sin páginas CMS disponibles.\n";
        }

        foreach ($pages as $page) {
            $content = strip_tags($page['content']);
            $content = mb_substr($content, 0, 500);
            $context .= "### {$page['meta_title']}\n{$content}\n\n";
        }

        return $context;
    }

    private function getDocumentsContext()
    {
        $docs = Db::getInstance()->executeS(
            'SELECT original_name, content FROM `' . _DB_PREFIX_ . 'aiochat_documents` ORDER BY date_add DESC LIMIT 5'
        );

        if (empty($docs)) {
            return '';
        }

        $context = "\n--- DOCUMENTACIÓN ADICIONAL ---\n";
        foreach ($docs as $doc) {
            $content = mb_substr($doc['content'], 0, 2000);
            $context .= "### {$doc['original_name']}\n{$content}\n\n";
        }

        return $context;
    }

    private function extractKeywords($text)
    {
        $text  = strtolower($text);
        $words = preg_split('/\s+/', $text);
        $stopwords = ['el', 'la', 'los', 'las', 'un', 'una', 'de', 'del', 'en', 'con', 'por', 'para', 'que', 'me', 'te', 'se', 'es', 'son', 'the', 'a', 'an', 'is', 'are', 'of', 'to', 'for', 'and', 'or', 'i', 'you', 'do', 'have', 'quiero', 'tengo', 'necesito', 'busco', 'hay'];
        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word, '.,;:?!¿¡"\'');
            if (strlen($word) > 3 && !in_array($word, $stopwords)) {
                $keywords[] = $word;
            }
        }
        return array_slice($keywords, 0, 5);
    }
}
