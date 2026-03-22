<?php
/**
 * AioChatDocParser — extracción de texto de documentos sin dependencias externas.
 * Soporta: .txt, .pdf (PHP nativo + fallback pdftotext), .docx, .doc
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AioChatDocParser
{
    /**
     * Punto de entrada principal.
     * @param  string $filePath  Ruta al archivo temporal o permanente
     * @param  string $ext       Extensión sin punto, en minúsculas
     * @return string            Texto plano extraído
     */
    public static function extract($filePath, $ext)
    {
        switch ($ext) {
            case 'txt':  return (string)file_get_contents($filePath);
            case 'pdf':  return self::extractPdf($filePath);
            case 'docx': return self::extractDocx($filePath);
            case 'doc':  return self::extractDoc($filePath);
            default:     return '';
        }
    }

    /* ------------------------------------------------------------------ */
    /* PDF                                                                  */
    /* ------------------------------------------------------------------ */

    private static function extractPdf($filePath)
    {
        // Intentar pdftotext si está disponible en el servidor
        if (self::commandExists('pdftotext')) {
            $text = @shell_exec('pdftotext ' . escapeshellarg($filePath) . ' - 2>/dev/null');
            if ($text && strlen(trim($text)) > 10) {
                return $text;
            }
        }

        // Fallback: parser PHP puro
        return self::extractPdfPhp($filePath);
    }

    private static function extractPdfPhp($filePath)
    {
        $raw = file_get_contents($filePath);
        if (!$raw) {
            return '';
        }

        $text = '';

        // Descomprimir streams FlateDecode y extraer texto de ellos
        // Patrón: detectar streams que puedan contener texto
        $offset = 0;
        while (($pos = strpos($raw, 'stream', $offset)) !== false) {
            // Buscar el encabezado del objeto para ver si tiene FlateDecode
            $headerStart = max(0, $pos - 400);
            $header      = substr($raw, $headerStart, $pos - $headerStart);

            $isFlate = (stripos($header, 'FlateDecode') !== false || stripos($header, 'Fl ') !== false);

            // Avanzar al inicio del contenido del stream (después del \n tras "stream")
            $streamStart = strpos($raw, "\n", $pos) + 1;
            if ($streamStart === false) {
                $offset = $pos + 1;
                continue;
            }

            $streamEnd = strpos($raw, 'endstream', $streamStart);
            if ($streamEnd === false) {
                $offset = $pos + 1;
                continue;
            }

            $streamData = substr($raw, $streamStart, $streamEnd - $streamStart);
            // Quitar posible \r al final
            $streamData = rtrim($streamData, "\r\n");

            if ($isFlate && strlen($streamData) > 0) {
                $decoded = @gzuncompress($streamData);
                if ($decoded === false) {
                    $decoded = @gzinflate($streamData);
                }
                if ($decoded && strlen($decoded) > 0) {
                    $text .= self::extractTextOpsFromStream($decoded) . "\n";
                }
            } else {
                // Stream no comprimido: intentar extraer texto directamente
                $text .= self::extractTextOpsFromStream($streamData) . "\n";
            }

            $offset = $streamEnd + 9;
        }

        // Limpiar y devolver
        $text = preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * Extrae texto de operadores PDF dentro de un stream ya descomprimido.
     * Operadores soportados: Tj, TJ, ' (apóstrofe), " (comillas).
     */
    private static function extractTextOpsFromStream($stream)
    {
        $text = '';

        // Extraer bloques BT…ET
        preg_match_all('/BT\s+(.*?)\s*ET/s', $stream, $blocks);
        $targets = $blocks[1];

        // Si no hay BT/ET, intentar con todo el stream (a veces el texto está suelto)
        if (empty($targets)) {
            $targets = [$stream];
        }

        foreach ($targets as $block) {
            // Operador Tj: (texto) Tj
            preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*Tj/s', $block, $m);
            foreach ($m[1] as $s) {
                $decoded = self::decodePdfString($s);
                if (self::isReadableText($decoded)) {
                    $text .= $decoded . ' ';
                }
            }

            // Operador TJ: [(texto)(texto) ...] TJ
            preg_match_all('/\[([^\]]*)\]\s*TJ/s', $block, $m);
            foreach ($m[1] as $arr) {
                preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/s', $arr, $strings);
                $chunk = '';
                foreach ($strings[1] as $s) {
                    $chunk .= self::decodePdfString($s);
                }
                if (self::isReadableText($chunk)) {
                    $text .= $chunk . ' ';
                }
            }

            // Saltos de línea (operadores Td, TD, T*, ', ")
            $text = preg_replace('/\s*(T\*|\'|")\s*/', "\n", $text);
        }

        return $text;
    }

    private static function decodePdfString($str)
    {
        // Secuencias de escape PDF
        $str = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($m) {
            return chr(octdec($m[1]));
        }, $str);
        $str = str_replace(
            ['\\n', '\\r', '\\t', '\\b', '\\f', '\\(', '\\)', '\\\\'],
            ["\n",  "\r",  "\t",  "\x08", "\x0C", '(',   ')',   '\\'],
            $str
        );
        return $str;
    }

    /** Descarta cadenas que solo contienen caracteres de control o binarios */
    private static function isReadableText($str)
    {
        $str = trim($str);
        if (strlen($str) < 1) {
            return false;
        }
        // Si más del 40 % son caracteres no imprimibles, descartar
        $nonPrint = preg_match_all('/[^\x09\x0A\x0D\x20-\x7E]/', $str);
        return ($nonPrint / max(1, strlen($str))) < 0.4;
    }

    /* ------------------------------------------------------------------ */
    /* DOCX (Office Open XML — ZIP con word/document.xml)                  */
    /* ------------------------------------------------------------------ */

    private static function extractDocx($filePath)
    {
        if (!class_exists('ZipArchive')) {
            return '';
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!$xml) {
            return '';
        }

        // Insertar saltos de párrafo antes de eliminar etiquetas
        $xml = preg_replace('/<\/w:p>/', "\n", $xml);
        $xml = preg_replace('/<w:br[^\/]*\/>/', "\n", $xml);
        $xml = preg_replace('/<\/w:r>/', ' ', $xml);

        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /* ------------------------------------------------------------------ */
    /* DOC (binario Word 97-2003 — extracción básica)                      */
    /* ------------------------------------------------------------------ */

    private static function extractDoc($filePath)
    {
        $content = file_get_contents($filePath);
        if (!$content) {
            return '';
        }

        // Extraer cadenas ASCII imprimibles de al menos 4 caracteres
        preg_match_all('/[\x20-\x7E\x0A\x0D]{4,}/', $content, $m);
        $text = implode(' ', $m[0]);

        // Filtrar líneas que parecen datos binarios (mayoritariamente símbolos raros)
        $lines  = explode("\n", $text);
        $clean  = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (strlen($line) > 3) {
                $clean[] = $line;
            }
        }

        return implode("\n", $clean);
    }

    /* ------------------------------------------------------------------ */
    /* Utilidades                                                           */
    /* ------------------------------------------------------------------ */

    private static function commandExists($cmd)
    {
        if (!function_exists('shell_exec')) {
            return false;
        }
        $result = @shell_exec('which ' . escapeshellarg($cmd) . ' 2>/dev/null');
        return !empty(trim((string)$result));
    }
}
