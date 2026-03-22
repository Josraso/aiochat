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

ACCESO AL CATÁLOGO — MUY IMPORTANTE:
El catálogo completo de productos de la tienda está incluido directamente en este prompt (sección PRODUCTOS DISPONIBLES).
NUNCA digas que no tienes acceso al catálogo, que no puedes ver los productos o que necesitas consultar una base de datos externa.
Toda la información que necesitas ya está aquí. Úsala directamente.
Si un producto concreto no aparece en el listado, dilo de forma natural («no lo tengo en mi catálogo en este momento»), pero JAMÁS digas que no tienes acceso.

REGLAS DE RESPUESTA:
- Responde siempre en el mismo idioma que el cliente (español o inglés).
- NUNCA recomiendes productos de otras tiendas o competidores.
- Sé amable, conciso y directo. Una o dos frases suelen ser suficientes.
- NO añadas datos de contacto, teléfono ni email al final de cada respuesta. Solo si el cliente los pide.
- Si el cliente ya dijo que no necesita más ayuda, responde brevemente («¡De nada! ¡Hasta pronto!» etc.).
- Si el cliente quiere hablar con una persona, responde exactamente con: [HUMAN_REQUESTED]
- Si el cliente está frustrado o insatisfecho, ofrece hablar con una persona.
- Todos los productos del listado están activos y disponibles para comprar; no digas que no están disponibles por figura con stock 0.
- Cuando menciones un producto concreto, escribe su nombre como enlace Markdown: [Nombre del producto](URL). NUNCA pongas la URL en crudo.

RECOMENDACIONES COMPLEMENTARIAS:
Cuando el cliente pregunta por un producto concreto y lo encuentras en el catálogo, puedes sugerir 1 o 2 productos complementarios de la sección «OTROS PRODUCTOS DEL CATÁLOGO» solo si tienen sentido real juntos (ej: accesorios para el mismo uso, protección, mantenimiento...). No fuerces recomendaciones si no hay nada que encaje. Nunca inventes productos que no estén en el listado.

INFORMACIÓN DE LA TIENDA:
";

        $prompt .= $this->getCustomContext();
        $prompt .= $this->getProductsContext($userMessage);
        $prompt .= $this->getShippingContext();
        $prompt .= $this->getCmsContext();
        $prompt .= $this->getDocumentsContext();

        return $prompt;
    }

    private function getProductsContext($userMessage)
    {
        $context = "\n--- PRODUCTOS DISPONIBLES ---\n";

        // Correlated subquery para categoría → evita GROUP BY con ONLY_FULL_GROUP_BY
        $baseSelect = 'SELECT p.id_product, pl.name, pl.link_rewrite, pl.description_short, p.price,
                           (SELECT cl2.name
                            FROM `' . _DB_PREFIX_ . 'category_product` cp2
                            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl2
                                 ON cp2.id_category = cl2.id_category AND cl2.id_lang = ' . (int)$this->idLang . '
                            WHERE cp2.id_product = p.id_product LIMIT 1) as category
                       FROM `' . _DB_PREFIX_ . 'product` p
                       LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                            ON p.id_product = pl.id_product AND pl.id_lang = ' . (int)$this->idLang;

        $words    = $this->extractKeywords($userMessage);
        $products = [];

        if (!empty($words)) {
            $conditions = implode(' OR ', array_map(function ($w) {
                $w = pSQL($w);
                return 'pl.name LIKE "%' . $w . '%" OR pl.description_short LIKE "%' . $w . '%"';
            }, $words));
            $products = Db::getInstance()->executeS(
                $baseSelect . ' WHERE p.active = 1 AND (' . $conditions . ') LIMIT 15'
            );
            if ($products === false) {
                $products = [];
            }
        }

        // Fallback: catálogo general si no hay coincidencias exactas
        if (empty($products)) {
            $products = Db::getInstance()->executeS(
                $baseSelect . ' WHERE p.active = 1 ORDER BY p.id_product DESC LIMIT 40'
            );
            if ($products === false) {
                $products = [];
            }
        }

        if (empty($products)) {
            return $context . "No hay productos disponibles.\n";
        }

        $link     = Context::getContext()->link;
        $foundIds = [];

        foreach ($products as $p) {
            $foundIds[] = (int)$p['id_product'];
            $context   .= $this->formatProduct($p, $link);
        }

        // Productos complementarios: otros del catálogo excluyendo los ya mostrados
        $excludeSql = empty($foundIds) ? '' : ' AND p.id_product NOT IN (' . implode(',', $foundIds) . ')';
        $others = Db::getInstance()->executeS(
            $baseSelect . ' WHERE p.active = 1' . $excludeSql . ' ORDER BY RAND() LIMIT 15'
        );

        if (!empty($others)) {
            $context .= "\n--- OTROS PRODUCTOS DEL CATÁLOGO (pueden complementar) ---\n";
            foreach ($others as $p) {
                $context .= $this->formatProduct($p, $link);
            }
        }

        return $context;
    }

    private function formatProduct($p, $link)
    {
        try {
            // Precio con IVA incluido usando el país por defecto
            $priceWithTax = Product::getPriceStatic((int)$p['id_product'], true);
            $price = Tools::displayPrice($priceWithTax);
        } catch (Exception $e) {
            $price = number_format((float)$p['price'], 2) . ' €';
        }
        $desc = mb_substr(strip_tags($p['description_short']), 0, 180);
        $cat  = $p['category'] ?: '';
        try {
            $url = $link->getProductLink((int)$p['id_product'], $p['link_rewrite']);
        } catch (Exception $e) {
            $url = '';
        }
        $line = "- {$p['name']} | Precio: {$price} | Categoría: {$cat}";
        if ($url)  { $line .= " | URL: {$url}"; }
        if ($desc) { $line .= " | {$desc}"; }
        return $line . "\n";
    }

    private function getCustomContext()
    {
        $custom = trim(Configuration::get('AIOCHAT_CUSTOM_CONTEXT'));
        if (!$custom) {
            return '';
        }
        return "\n--- INFORMACIÓN PERSONALIZADA DE LA TIENDA ---\n" . $custom . "\n";
    }

    private function getShippingContext()
    {
        $context = "\n--- TARIFAS DE ENVÍO ---\n";

        // 1. Leer carriers activos
        $carriers = Db::getInstance()->executeS(
            'SELECT c.id_carrier, cl.name, c.shipping_method
             FROM `' . _DB_PREFIX_ . 'carrier` c
             LEFT JOIN `' . _DB_PREFIX_ . 'carrier_lang` cl
                  ON c.id_carrier = cl.id_carrier AND cl.id_lang = ' . (int)$this->idLang . '
             WHERE c.active = 1 AND c.deleted = 0
             LIMIT 10'
        );

        if (empty($carriers) || $carriers === false) {
            return $context . "Información de envío no disponible.\n";
        }

        foreach ($carriers as $c) {
            $carrierId = (int)$c['id_carrier'];
            $carrierName = $c['name'] ?: "Transportista #{$carrierId}";

            // 2. Precios por zona (delivery + zone), compatible con price y weight ranges
            $deliveries = Db::getInstance()->executeS(
                'SELECT d.price, z.name as zone_name
                 FROM `' . _DB_PREFIX_ . 'delivery` d
                 LEFT JOIN `' . _DB_PREFIX_ . 'zone` z ON d.id_zone = z.id_zone
                 WHERE d.id_carrier = ' . $carrierId . '
                 GROUP BY d.id_zone
                 ORDER BY d.price ASC
                 LIMIT 10'
            );

            if (!empty($deliveries) && $deliveries !== false) {
                foreach ($deliveries as $d) {
                    $zone  = $d['zone_name'] ?: 'general';
                    $price = Tools::displayPrice((float)$d['price']);
                    $context .= "- {$carrierName} | Zona: {$zone} | Coste: {$price}\n";
                }
            } else {
                $context .= "- {$carrierName} | (sin tarifas configuradas)\n";
            }
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
        $words = preg_split('/[\s\-_]+/', $text);
        $stopwords = ['el', 'la', 'lo', 'los', 'las', 'un', 'una', 'de', 'del', 'en', 'con', 'por', 'para', 'que',
                      'me', 'te', 'se', 'es', 'son', 'su', 'mi', 'si', 'no', 'ya',
                      'the', 'a', 'an', 'is', 'are', 'of', 'to', 'for', 'and', 'or', 'in', 'it',
                      'quiero', 'tengo', 'necesito', 'busco', 'hay', 'tienes', 'tiene', 'puedo',
                      'como', 'cual', 'cuales', 'donde', 'cuando', 'quien'];
        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word, '.,;:?!¿¡"\'/()[]');
            if (strlen($word) >= 2 && !in_array($word, $stopwords)) {
                $keywords[] = $word;
            }
        }
        return array_slice(array_unique($keywords), 0, 8);
    }
}
