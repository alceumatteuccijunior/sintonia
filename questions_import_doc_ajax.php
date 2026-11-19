<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    echo json_encode(['success' => false, 'message' => 'Acesso não autorizado.']);
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection(true);
$teacher_id = $_SESSION['user_id'];

// Suporta requisição via JSON (application/json) ou FormData (multipart/form-data)
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true);

$action = $_POST['action'] ?? ($jsonInput['action'] ?? '');

/**
 * Função auxiliar: extrai texto de um arquivo .docx no servidor via ZipArchive (fallback caso o navegador não extraia)
 */
function extractTextFromDocxServer($filePath) {
    if (!class_exists('ZipArchive')) {
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return '';
    }
    $xmlContent = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xmlContent === false) {
        return '';
    }

    // Preserva quebras de parágrafo e linha antes de remover tags XML
    $xmlContent = str_replace(['</w:p>', '<w:br/>', '<w:br />', '</w:tr>'], ["\n", "\n", "\n", "\n"], $xmlContent);
    $xmlContent = str_replace(['</w:tc>'], ["\t"], $xmlContent);
    $text = strip_tags($xmlContent);
    return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Verifica se o texto do enunciado menciona elementos visuais (figuras, circuitos, diagramas, etc.)
 */
function detectNeedsImage($text) {
    $pattern = '/\b(figura|imagem|diagrama|circuito|gr[aá]fico|esquema|ilustra[cç][aã]o|desenho|fluxograma|quadro\s+a\s+seguir|tabela\s+a\s+seguir|imagem\s+abaixo|figura\s+abaixo|figura\s+a\s+seguir|observe\s+a\s+imagem|observe\s+o\s+esquema|analise\s+a\s+figura)\b/iu';
    return (bool) preg_match($pattern, $text);
}

/**
 * Garante que a tabela questions possua external_code e image_url VARCHAR(500)
 * e que question_options possua image_url VARCHAR(500).
 * Deve sempre ser chamada ANTES de iniciar transações no MySQL (evita commit implícito de DDL).
 */
function ensureQuestionSchemaColumns($pdo) {
    try {
        $stmtExt = $pdo->query("SHOW COLUMNS FROM questions LIKE 'external_code'");
        if ($stmtExt && !$stmtExt->fetch()) {
            $pdo->exec("ALTER TABLE questions ADD COLUMN external_code VARCHAR(100) NULL DEFAULT NULL AFTER import_tag");
        }
    } catch (Exception $e) {}

    try {
        $stmtQImg = $pdo->query("SHOW COLUMNS FROM questions LIKE 'image_url'");
        $colQImg = $stmtQImg ? $stmtQImg->fetch() : null;
        if (!$colQImg) {
            $pdo->exec("ALTER TABLE questions ADD COLUMN image_url VARCHAR(500) NULL DEFAULT NULL AFTER command");
        } elseif (isset($colQImg['Type']) && preg_match('/varchar\((\d+)\)/i', $colQImg['Type'], $mLen) && (int)$mLen[1] < 500) {
            $pdo->exec("ALTER TABLE questions MODIFY COLUMN image_url VARCHAR(500) NULL DEFAULT NULL");
        }
    } catch (Exception $e) {}

    try {
        $stmtOptImg = $pdo->query("SHOW COLUMNS FROM question_options LIKE 'image_url'");
        if ($stmtOptImg && !$stmtOptImg->fetch()) {
            $pdo->exec("ALTER TABLE question_options ADD COLUMN image_url VARCHAR(500) NULL DEFAULT NULL AFTER text");
        }
    } catch (Exception $e) {}
}

/**
 * Verifica estritamente se uma URL aponta para uma imagem (evita confundir links de API do enunciado com imagens).
 */
function isDirectImageUrl($url) {
    $url = trim((string)$url);
    if ($url === '' || !preg_match('#^https?://[^\s<>"\']+$#i', $url)) {
        return false;
    }
    // Extensão clássica de imagem na URL (antes de eventual ?query)
    if (preg_match('#\.(?:png|jpe?g|webp|gif|svg)(?:\?[^\s<>"\']*)?$#i', $url)) {
        return true;
    }
    // Endpoint específico de imagens do SISBIA SAEP
    if (preg_match('#^https?://api\.sisbia\.senai\.br/app/items/image/[^\s<>"\']+$#i', $url)) {
        return true;
    }
    return false;
}

/**
 * Estratégia Híbrida de Imagens:
 * Tenta baixar a imagem da URL para uploads/questions/ garantindo autonomia caso o servidor externo caia.
 * Se falhar ou o download estiver desativado, mantém o link externo direto como fallback seguro.
 */
function downloadImageHybrid($rawUrl, $prefix, $uploadDir, &$downloadCache, $enableDownload = true) {
    $url = trim((string)$rawUrl);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['url' => null, 'source' => null];
    }

    if (!$enableDownload) {
        return ['url' => $url, 'source' => 'external'];
    }

    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    // Se já baixamos essa mesma URL nesta requisição, cria uma cópia independente
    // para que apagar a imagem de uma alternativa não apague a da outra.
    if (isset($downloadCache[$url]) && $downloadCache[$url]['source'] === 'local') {
        $cachedAbs = $downloadCache[$url]['abs_path'];
        if (file_exists($cachedAbs) && is_file($cachedAbs)) {
            $ext = pathinfo($cachedAbs, PATHINFO_EXTENSION) ?: 'png';
            $copyName = 'saep_' . $prefix . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            if (@copy($cachedAbs, $uploadDir . $copyName)) {
                return ['url' => 'uploads/questions/' . $copyName, 'source' => 'local'];
            }
        }
    } elseif (isset($downloadCache[$url]) && $downloadCache[$url]['source'] === 'external') {
        return ['url' => $url, 'source' => 'external'];
    }

    $binary = false;
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $attemptCurl = function ($verifySsl) use ($url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Mozilla/5.0 (compatible; iaS-SENAI/2.0)']);
            $data = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);
            return [$data, $code, $errno];
        };

        list($binary, $httpCode, $curlErrno) = $attemptCurl(true);
        // Em ambientes Windows/XAMPP sem bundle CA local, tenta novamente sem verificação estrita apenas se erro de certificado CA (60/77)
        if (($binary === false || $httpCode === 0) && in_array($curlErrno, [60, 77], true)) {
            list($binary, $httpCode, $curlErrno) = $attemptCurl(false);
        }
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 12,
                'header'  => "User-Agent: Mozilla/5.0 (compatible; iaS-SENAI/2.0)\r\n"
            ]
        ]);
        $binary = @file_get_contents($url, false, $ctx);
        $httpCode = ($binary !== false) ? 200 : 0;
    }

    if ($binary !== false && $httpCode >= 200 && $httpCode < 300 && strlen($binary) > 100 && strlen($binary) <= 10 * 1024 * 1024) {
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_buffer($finfo, $binary);
                finfo_close($finfo);
            }
        }

        $mimeToExt = [
            'image/png'     => 'png',
            'image/jpeg'    => 'jpg',
            'image/jpg'     => 'jpg',
            'image/webp'    => 'webp',
            'image/gif'     => 'gif',
            'image/svg+xml' => 'svg'
        ];

        $ext = $mimeToExt[strtolower($mime)] ?? '';
        if ($ext === '') {
            $urlPath = (string)parse_url($url, PHP_URL_PATH);
            $urlExt = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
            if (in_array($urlExt, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'], true)) {
                $ext = ($urlExt === 'jpeg') ? 'jpg' : $urlExt;
            }
        }

        if ($ext !== '') {
            $fileName = 'saep_' . $prefix . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            $absPath = $uploadDir . $fileName;
            if (@file_put_contents($absPath, $binary) !== false) {
                $downloadCache[$url] = ['source' => 'local', 'abs_path' => $absPath];
                return ['url' => 'uploads/questions/' . $fileName, 'source' => 'local'];
            }
        }
    }

    // Fallback Híbrido: mantém a URL externa original caso não seja possível baixar agora
    $downloadCache[$url] = ['source' => 'external', 'abs_path' => null];
    return ['url' => $url, 'source' => 'external'];
}

/**
 * Lê um arquivo .xlsx (OpenXML) via ZipArchive e retorna array de linhas [colLetter => string]
 */
function parseSaepQuestionsXlsxPhp($filePath) {
    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'message' => 'Extensão ZipArchive não disponível no servidor.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return ['success' => false, 'message' => 'Não foi possível abrir o arquivo .xlsx.'];
    }

    // 1. Carrega Shared Strings
    $sharedStrings = [];
    $sstXmlStr = $zip->getFromName('xl/sharedStrings.xml');
    if ($sstXmlStr !== false) {
        libxml_use_internal_errors(true);
        $sstXml = simplexml_load_string($sstXmlStr);
        libxml_clear_errors();
        if ($sstXml !== false) {
            foreach ($sstXml->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } else {
                    $buf = '';
                    foreach ($si->r as $r) {
                        if (isset($r->t)) {
                            $buf .= (string)$r->t;
                        }
                    }
                    $sharedStrings[] = $buf;
                }
            }
        }
    }

    // 2. Descobre caminho da primeira planilha ativa
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wbXmlStr = $zip->getFromName('xl/workbook.xml');
    $wbRelsStr = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXmlStr !== false && $wbRelsStr !== false) {
        libxml_use_internal_errors(true);
        $wbXml = simplexml_load_string($wbXmlStr);
        $relsXml = simplexml_load_string($wbRelsStr);
        libxml_clear_errors();
        if ($wbXml && $relsXml && isset($wbXml->sheets->sheet[0])) {
            $rAttrs = $wbXml->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $firstRid = (string)($rAttrs['id'] ?? '');
            if ($firstRid !== '') {
                foreach ($relsXml->Relationship as $rel) {
                    if ((string)$rel['Id'] === $firstRid) {
                        $target = ltrim((string)$rel['Target'], '/');
                        if (strpos($target, 'xl/') !== 0) {
                            $target = 'xl/' . $target;
                        }
                        if ($zip->locateName($target) !== false) {
                            $sheetPath = $target;
                        }
                        break;
                    }
                }
            }
        }
    }

    $sheetXmlStr = $zip->getFromName($sheetPath);
    $zip->close();

    if ($sheetXmlStr === false) {
        return ['success' => false, 'message' => 'Planilha interna não encontrada dentro do .xlsx.'];
    }

    libxml_use_internal_errors(true);
    $sheetXml = simplexml_load_string($sheetXmlStr);
    libxml_clear_errors();
    if ($sheetXml === false || !isset($sheetXml->sheetData)) {
        return ['success' => false, 'message' => 'Conteúdo XML inválido na planilha .xlsx.'];
    }

    $rows = [];
    foreach ($sheetXml->sheetData->row as $rowNode) {
        $rowArr = [];
        $hasContent = false;
        foreach ($rowNode->c as $cNode) {
            $ref = (string)$cNode['r'];
            $col = preg_replace('/\d+/', '', $ref);
            $type = (string)$cNode['t'];
            $val = '';
            if ($type === 's' && isset($cNode->v)) {
                $idx = (int)$cNode->v;
                $val = $sharedStrings[$idx] ?? '';
            } elseif ($type === 'inlineStr' && isset($cNode->is)) {
                if (isset($cNode->is->t)) {
                    $val = (string)$cNode->is->t;
                } else {
                    foreach ($cNode->is->r as $r) {
                        $val .= (string)($r->t ?? '');
                    }
                }
            } elseif (isset($cNode->v)) {
                $val = (string)$cNode->v;
            }
            $rowArr[$col] = $val;
            if (trim($val) !== '') {
                $hasContent = true;
            }
        }
        if ($hasContent) {
            $rows[] = $rowArr;
        }
    }

    return ['success' => true, 'rows' => $rows];
}

/**
 * Remove links diretos de imagem de dentro do texto da questão (se houver) e separa Contexto vs Comando.
 */
function splitSaepStatementContextCommand($rawStatement) {
    $lines = preg_split('/\R/', str_replace("\r\n", "\n", (string)$rawStatement));
    $cleanLines = [];
    $inlineImgUrl = null;

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed !== '' && isDirectImageUrl($trimmed)) {
            if ($inlineImgUrl === null) {
                $inlineImgUrl = $trimmed;
            }
            continue;
        }
        // Também extrai caso uma URL de imagem apareça no fim da linha separada por espaço
        if (preg_match('#(https?://[^\s<>"\']+)#i', $line, $mUrl) && isDirectImageUrl($mUrl[1])) {
            if ($inlineImgUrl === null) {
                $inlineImgUrl = trim($mUrl[1]);
            }
            $line = trim(str_replace($mUrl[1], '', $line));
            if ($line === '') {
                continue;
            }
        }
        $cleanLines[] = $line;
    }

    $fullText = trim(implode("\n", $cleanLines));
    $fullText = preg_replace("/\n{3,}/", "\n\n", $fullText);

    if ($fullText === '') {
        return ['context' => '', 'command' => '', 'inline_image_url' => $inlineImgUrl];
    }

    // Divide em blocos significativos para separar Texto-Base (Contexto) do Comando final
    $blocks = preg_split("/\n\s*\n/", $fullText);
    $blocks = array_values(array_filter(array_map('trim', $blocks), function ($b) {
        return $b !== '';
    }));

    if (count($blocks) >= 2) {
        $lastBlock = array_pop($blocks);
        // Caso o último bloco tenha figura + pergunta na última linha ("Figura 1: ...\nQual instrução...")
        $lastLines = array_values(array_filter(array_map('trim', explode("\n", $lastBlock)), fn($l) => $l !== ''));
        if (count($lastLines) >= 2 && preg_match('/^Figura\s+\d+/iu', $lastLines[0])) {
            $cmdLine = array_pop($lastLines);
            $blocks[] = implode("\n", $lastLines);
            $lastBlock = $cmdLine;
        }
        return [
            'context'          => trim(implode("\n\n", $blocks)),
            'command'          => trim($lastBlock),
            'inline_image_url' => $inlineImgUrl
        ];
    }

    // Caso tenha apenas 1 bloco com várias linhas e termine com pergunta ("...?"), separa a última linha como Comando
    $singleLines = array_values(array_filter(array_map('trim', explode("\n", $fullText)), fn($l) => $l !== ''));
    if (count($singleLines) >= 2 && mb_substr(end($singleLines), -1, 1, 'UTF-8') === '?') {
        $cmdLine = array_pop($singleLines);
        return [
            'context'          => trim(implode("\n", $singleLines)),
            'command'          => trim($cmdLine),
            'inline_image_url' => $inlineImgUrl
        ];
    }

    return [
        'context'          => '',
        'command'          => $fullText,
        'inline_image_url' => $inlineImgUrl
    ];
}

/**
 * Faz o parse da célula de Alternativas ("A) ...\nhttps://...\nB) ...") e separa texto limpo + URL da imagem de cada opção.
 */
function parseSaepAlternativesBlock($rawText, $gabaritoLetter = 'A') {
    $text = str_replace("\r\n", "\n", (string)$rawText);
    $text = preg_replace('/^\s*Alternativas\s*\n+/iu', '', $text);

    // Encontra marcadores A), B), C), D), E) no início de linhas mantendo sequência estrita A -> B -> C -> ...
    preg_match_all('/(?:^|\n)[ \t]*([A-E])\)(?:[ \t]*)/u', $text, $matches, PREG_OFFSET_CAPTURE);

    $expectedLetters = ['A', 'B', 'C', 'D', 'E'];
    $expectedIdx = 0;
    $anchors = [];

    foreach ($matches[1] as $i => $capLetter) {
        $letter = strtoupper($capLetter[0]);
        if ($expectedIdx < count($expectedLetters) && $letter === $expectedLetters[$expectedIdx]) {
            $fullMatchStart = $matches[0][$i][1];
            $bodyStart = $fullMatchStart + strlen($matches[0][$i][0]);
            $anchors[] = [
                'letter'     => $letter,
                'marker_pos' => $fullMatchStart,
                'body_start' => $bodyStart
            ];
            $expectedIdx++;
        }
    }

    $gabarito = strtoupper(trim((string)$gabaritoLetter));
    if (preg_match('/^([A-E])\b/', $gabarito, $mGab)) {
        $gabarito = $mGab[1];
    } else {
        $gabarito = 'A';
    }

    $options = [];
    $countAnchors = count($anchors);
    for ($i = 0; $i < $countAnchors; $i++) {
        $start = $anchors[$i]['body_start'];
        $end = ($i + 1 < $countAnchors) ? $anchors[$i + 1]['marker_pos'] : strlen($text);
        $rawBody = trim(substr($text, $start, $end - $start));
        $letter = $anchors[$i]['letter'];

        // Detecta e extrai link de imagem dentro do corpo da alternativa (mantendo texto limpo sem a URL)
        $rawImgUrl = null;
        $bodyLines = explode("\n", $rawBody);
        $cleanBodyLines = [];
        foreach ($bodyLines as $bLine) {
            $trimmedLine = trim($bLine);
            if ($trimmedLine !== '' && isDirectImageUrl($trimmedLine)) {
                if ($rawImgUrl === null) {
                    $rawImgUrl = $trimmedLine;
                }
                continue;
            }
            if (preg_match('#(https?://[^\s<>"\']+)#i', $bLine, $mUrl) && isDirectImageUrl($mUrl[1])) {
                if ($rawImgUrl === null) {
                    $rawImgUrl = trim($mUrl[1]);
                }
                $bLine = trim(str_replace($mUrl[1], '', $bLine));
                if ($bLine === '') {
                    continue;
                }
            }
            $cleanBodyLines[] = $bLine;
        }

        $cleanOptText = trim(implode("\n", $cleanBodyLines));
        $cleanOptText = preg_replace("/\n{3,}/", "\n\n", $cleanOptText);

        $options[] = [
            'letter'        => $letter,
            'text'          => $cleanOptText,
            'raw_image_url' => $rawImgUrl,
            'image_url'     => null,
            'is_correct'    => ($letter === $gabarito)
        ];
    }

    return $options;
}

/**
 * Salva a chave da OpenAI permanentemente no banco de dados (system_settings) e em config.local.php
 * para que futuros deploys via Git/cPanel nunca sobrescrevam a chave em produção.
 */
function saveOpenAiKeyPermanently($pdo, $newKey) {
    $newKey = trim($newKey);
    if ($newKey === '' || $newKey === 'sua_chave_aqui') {
        return false;
    }

    // 1. Salva na tabela system_settings do MySQL (persiste entre deploys do Git)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES ('openai_api_key', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$newKey]);
    } catch (Exception $e) {
        // Ignora erro de tabela caso usuário do banco tenha restrição de DDL
    }

    // 2. Salva também em config.local.php (ignorado pelo Git) no servidor
    $localConfigPath = __DIR__ . '/config.local.php';
    $phpContent = "<?php\n// Arquivo gerado automaticamente - ignorado pelo Git para preservar a chave em produção\nif (!defined('OPENAI_API_KEY')) {\n    define('OPENAI_API_KEY', " . var_export($newKey, true) . ");\n}\n";
    @file_put_contents($localConfigPath, $phpContent);

    return true;
}

// ============================================================================
// AÇÃO AUXILIAR: SALVAR CHAVE DA OPENAI PERMANENTEMENTE NO SERVIDOR
// ============================================================================
if ($action === 'save_api_key') {
    $newKey = trim($_POST['api_key'] ?? ($jsonInput['api_key'] ?? ''));
    if (strpos($newKey, 'sk-') !== 0) {
        echo json_encode(['success' => false, 'message' => 'A chave informada parece inválida. Ela deve começar com "sk-".']);
        exit;
    }

    saveOpenAiKeyPermanently($pdo, $newKey);
    echo json_encode([
        'success' => true,
        'message' => 'Chave da OpenAI salva permanentemente no banco de dados do servidor! Ela não será mais perdida ao fazer git push.'
    ]);
    exit;
}

// ============================================================================
// ETAPA 1: EXTRAÇÃO E ESTRUTURAÇÃO DE QUESTÕES VIA IA (parse_text)
// ============================================================================
if ($action === 'parse_text') {
    $apiKeyOverride = trim($_POST['api_key_override'] ?? ($jsonInput['api_key_override'] ?? ''));
    if ($apiKeyOverride !== '' && strpos($apiKeyOverride, 'sk-') === 0) {
        saveOpenAiKeyPermanently($pdo, $apiKeyOverride);
        $activeApiKey = $apiKeyOverride;
    } else {
        $activeApiKey = defined('OPENAI_API_KEY') ? trim(OPENAI_API_KEY) : '';
    }

    if ($activeApiKey === '' || $activeApiKey === 'sua_chave_aqui') {
        echo json_encode([
            'success' => false,
            'needs_api_key' => true,
            'message' => 'A chave da OpenAI ainda não foi salva no servidor de produção (o deploy via Git sobrescreveu o config.php com "sua_chave_aqui"). Cole sua chave abaixo uma única vez para salvá-la permanentemente no Banco de Dados!'
        ]);
        exit;
    }

    $rawText       = trim($_POST['raw_text'] ?? ($jsonInput['raw_text'] ?? ''));
    $courseName    = trim($_POST['course_name'] ?? ($jsonInput['course_name'] ?? ''));
    $defaultModule = trim($_POST['default_module'] ?? ($jsonInput['default_module'] ?? ''));
    if ($defaultModule === '') {
        $defaultModule = 'Sem UC vinculada';
    }
    $defaultCap    = trim($_POST['default_capacity'] ?? ($jsonInput['default_capacity'] ?? ''));
    $answerKey     = trim($_POST['answer_key'] ?? ($jsonInput['answer_key'] ?? ''));

    // Garante tabela course_capacities e busca as capacidades oficiais da Matriz do Curso
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS course_capacities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            course_id INT NOT NULL,
            capacity_code VARCHAR(50) NOT NULL,
            description TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_course_cap (course_id, capacity_code),
            FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $matrixCapacities = [];
    if ($courseName !== '') {
        $stmtC = $pdo->prepare("SELECT id FROM courses WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmtC->execute([$courseName]);
        $cId = (int)$stmtC->fetchColumn();
        if ($cId > 0) {
            $stmtCaps = $pdo->prepare("
                SELECT capacity_code, description
                FROM course_capacities
                WHERE course_id = ?
                ORDER BY CAST(SUBSTRING(capacity_code, 2) AS UNSIGNED) ASC, capacity_code ASC
            ");
            $stmtCaps->execute([$cId]);
            $matrixCapacities = $stmtCaps->fetchAll();

            // Fallback: hidrata a partir de module_capacities caso já existissem capacidades antigas no curso
            if (empty($matrixCapacities)) {
                $stmtLeg = $pdo->prepare("
                    SELECT DISTINCT mc.capacity_code, mc.description
                    FROM module_capacities mc
                    JOIN modules m ON mc.module_id = m.id
                    WHERE m.course_id = ? AND mc.capacity_code != '' AND mc.description != ''
                    ORDER BY LENGTH(mc.capacity_code), mc.capacity_code
                ");
                $stmtLeg->execute([$cId]);
                $matrixCapacities = $stmtLeg->fetchAll();
            }
        }
    }

    if (empty($matrixCapacities)) {
        echo json_encode([
            'success'      => false,
            'needs_matrix' => true,
            'message'      => 'Este curso ainda não possui uma Matriz Curricular de Capacidades (.xls) vinculada. Por favor, anexe o arquivo .xls da Matriz SAEP do curso antes de extrair as questões!'
        ]);
        exit;
    }

    $validCapMap = [];
    $matrixPromptBlock = "MATRIZ OFICIAL DE CAPACIDADES DO CURSO (TEXTOS INVIOLÁVEIS):\n";
    foreach ($matrixCapacities as $mc) {
        $code = strtoupper(trim($mc['capacity_code']));
        $desc = trim($mc['description']);
        $validCapMap[$code] = $desc;
        $matrixPromptBlock .= "- {$code}: {$desc}\n";
    }

    // Fallback: se o arquivo .docx foi enviado diretamente via FormData e raw_text veio vazio
    if ($rawText === '' && isset($_FILES['doc_file']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION));
        if ($ext === 'docx') {
            $rawText = trim(extractTextFromDocxServer($_FILES['doc_file']['tmp_name']));
        } elseif ($ext === 'txt') {
            $rawText = trim(file_get_contents($_FILES['doc_file']['tmp_name']));
        }
    }

    if (mb_strlen($rawText, 'UTF-8') < 20) {
        echo json_encode([
            'success' => false,
            'message' => 'Não foi possível extrair texto suficiente do documento. Verifique se o PDF não é uma imagem escaneada (sem texto selecionável) ou tente um arquivo .docx.'
        ]);
        exit;
    }

    // Limita texto extremamente longo para caber com folga na janela de contexto
    if (mb_strlen($rawText, 'UTF-8') > 95000) {
        $rawText = mb_substr($rawText, 0, 95000, 'UTF-8');
    }

    $systemPrompt = <<<PROMPT
Você é um Especialista em Avaliação Educacional e Metodologia SENAI (SAEP).
Sua tarefa é ler o texto bruto extraído de uma prova, apostila ou lista de exercícios (Word/PDF) e extrair TODAS as questões de múltipla escolha contidas nele, estruturando-as rigorosamente em formato JSON.

REGRAS OBRIGATÓRIAS DE EXTRAÇÃO:
1. Retorne APENAS um objeto JSON válido no formato:
{
  "questions": [
    {
      "number": 1,
      "context": "Texto-base / contextualização / situação-problema inicial da questão (se houver). Se a questão for direta em um único parágrafo curto, deixe vazio.",
      "command": "Comando da questão (a pergunta final e eventuais afirmações I, II, III que antecedem as alternativas). Se não houver divisão clara entre contexto e comando, coloque o enunciado completo aqui.",
      "module": "Nome do módulo/unidade curricular se citado na questão, ou o módulo padrão informado",
      "capacity": "Código exato da capacidade da Matriz Oficial do Curso (ex: C1, C2, C3...)",
      "ai_matched_capacity": true ou false,
      "difficulty": "Fácil, Médio ou Difícil",
      "needs_image": true ou false,
      "ai_solved_key": true ou false,
      "options": [
        { "text": "Texto da alternativa A (sem a letra 'a)' ou 'A -' no início)", "is_correct": false },
        { "text": "Texto da alternativa B", "is_correct": true },
        { "text": "Texto da alternativa C", "is_correct": false },
        { "text": "Texto da alternativa D", "is_correct": false }
      ]
    }
  ]
}

2. VÍNCULO OBRIGATÓRIO E INVIOLÁVEL COM A MATRIZ DE CAPACIDADES DO CURSO:
- O campo "capacity" DEVE conter APENAS o código oficial (ex: "C1", "C2", "C3"...) presente na MATRIZ OFICIAL DE CAPACIDADES DO CURSO fornecida pelo usuário.
- Se a questão trouxer explícito no texto qual é a capacidade (ex: "Capacidade: C3"), use esse código e defina "ai_matched_capacity": false.
- Se a questão NÃO trouxer explícito qual é a capacidade, analise profundamente o tema técnico do enunciado e das alternativas, compare com as descrições de todas as capacidades (C1, C2, C3...) da Matriz Oficial do Curso, escolha o código (ex: "C5") que melhor se encaixa pedagogicamente e defina "ai_matched_capacity": true.

3. PRESERVAÇÃO DO ENUNCIADO (CONTEXTO E COMANDO):
- Mantenha o texto integral da questão sem resumir nem alterar nenhuma palavra.
- Remova apenas o prefixo de numeração ("Questão 01 -", "1.", "1)") do início.

4. ALTERNATIVAS (COM TEXTO E/OU IMAGEM) E GABARITO:
- Extraia todas as alternativas da questão (normalmente 4 ou 5 alternativas), removendo letras de prefixo ("a)", "A -", "(A)").
- Se alguma alternativa no documento original era composta apenas por uma imagem/figura (e por isso ficou sem texto após a letra a, b, c, d, e), gere normalmente as alternativas com o texto "[Imagem na Alternativa A]", "[Imagem na Alternativa B]" etc. e defina "needs_image": true!
- Se houver gabarito informado pelo usuário ou marcado no documento, use-o e defina "ai_solved_key": false. Caso contrário, resolva tecnicamente a questão, marque a correta com "is_correct": true e defina "ai_solved_key": true.
- Exatamente UMA alternativa por questão deve ter "is_correct": true.

5. DETECÇÃO DE IMAGEM ("needs_image"):
- Defina "needs_image": true se o contexto, comando OU qualquer alternativa fizer referência a figura, imagem, diagrama, circuito, gráfico, esquema, desenho ou quadro visual que não veio no texto. Caso contrário, false.

6. CLASSIFICAÇÃO ("difficulty"):
- Estritamente um destes três valores: "Fácil", "Médio" ou "Difícil".
PROMPT;

    $userPrompt = "CONTEXTO PADRÃO INFORMADO PELO PROFESSOR:\n";
    $userPrompt .= "- Curso: " . ($courseName !== '' ? $courseName : 'Não especificado') . "\n";
    $userPrompt .= "- Módulo Padrão (usar se a questão não especificar outro): " . ($defaultModule !== '' ? $defaultModule : 'Sem UC vinculada') . "\n\n";
    $userPrompt .= $matrixPromptBlock . "\n";
    if ($defaultCap !== '' && isset($validCapMap[strtoupper($defaultCap)])) {
        $userPrompt .= "- Capacidade Preferencial (se aplicável): " . strtoupper($defaultCap) . "\n";
    }
    if ($answerKey !== '') {
        $userPrompt .= "- GABARITO OFICIAL INFORMADO PELO PROFESSOR: " . $answerKey . " (Aplique este gabarito às respectivas questões pela ordem numérica e marque ai_solved_key: false nelas).\n";
    }
    $userPrompt .= "\n================ TEXTO DO DOCUMENTO ================\n" . $rawText;

    $url = 'https://api.openai.com/v1/chat/completions';

    // Usa gpt-4o-mini para janela ampla (128k) e JSON estruturado preciso
    $payload = [
        'model' => 'gpt-4o-mini',
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ],
        'temperature' => 0.1,
        'max_tokens' => 12000
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $activeApiKey
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        echo json_encode(['success' => false, 'message' => 'Erro de comunicação com a IA (OpenAI): ' . $curlErr]);
        exit;
    }

    $decoded = json_decode($response, true);

    // Fallback automático para gpt-4o caso ocorra falha de parâmetro com gpt-4o-mini
    if (isset($decoded['error'])) {
        $errCode = $decoded['error']['code'] ?? '';
        $errMsg  = $decoded['error']['message'] ?? '';
        if ($errCode === 'invalid_api_key' || stripos($errMsg, 'Incorrect API key') !== false) {
            echo json_encode([
                'success' => false,
                'needs_api_key' => true,
                'message' => 'A chave da OpenAI configurada no servidor é inválida ou foi sobrescrita pelo deploy ("sua_chave_aqui"). Cole sua chave sk-proj-... abaixo para salvá-la permanentemente no Banco de Dados!'
            ]);
            exit;
        }

        $payload['model'] = 'gpt-4o';
        $payload['max_tokens'] = 4096;
        unset($payload['response_format']);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $activeApiKey
        ]);
        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            echo json_encode(['success' => false, 'message' => 'Erro na conexão com a OpenAI: ' . $curlErr]);
            exit;
        }
        $decoded = json_decode($response, true);
        if (isset($decoded['error'])) {
            echo json_encode([
                'success' => false,
                'needs_api_key' => (stripos($decoded['error']['message'] ?? '', 'API key') !== false),
                'message' => 'Erro retornado pela OpenAI: ' . ($decoded['error']['message'] ?? 'Erro desconhecido')
            ]);
            exit;
        }
    }

    $aiContent = $decoded['choices'][0]['message']['content'] ?? '';
    // Limpa eventuais blocos markdown ```json ... ```
    $aiContentClean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($aiContent));
    $parsedJson = json_decode($aiContentClean, true);

    if (!is_array($parsedJson) || !isset($parsedJson['questions']) || !is_array($parsedJson['questions'])) {
        echo json_encode([
            'success' => false,
            'message' => 'A IA não conseguiu identificar questões estruturadas no texto enviado. Verifique se o documento possui questões de múltipla escolha legíveis.'
        ]);
        exit;
    }

    $defaultCapCode = array_key_first($validCapMap) ?: 'C1';
    $normalizedQuestions = [];
    foreach ($parsedJson['questions'] as $idx => $q) {
        $ctx = trim($q['context'] ?? '');
        $cmd = trim($q['command'] ?? '');
        if ($cmd === '' && $ctx !== '') {
            $cmd = $ctx;
            $ctx = '';
        }
        if ($cmd === '') {
            continue;
        }

        $mod = trim($q['module'] ?? '');
        if ($mod === '' || mb_strtolower($mod, 'UTF-8') === 'não especificado' || mb_strtolower($mod, 'UTF-8') === 'módulo geral') {
            $mod = $defaultModule !== '' ? $defaultModule : 'Sem UC vinculada';
        }

        $rawCap = strtoupper(trim($q['capacity'] ?? ''));
        // Extrai apenas o código C1, C2... caso a IA tenha retornado "C1 - ..."
        if (preg_match('/^(C\d+)\b/i', $rawCap, $mCap)) {
            $rawCap = strtoupper($mCap[1]);
        } elseif (is_numeric($rawCap)) {
            $rawCap = 'C' . $rawCap;
        }

        if (!isset($validCapMap[$rawCap])) {
            $rawCap = ($defaultCap !== '' && isset($validCapMap[strtoupper($defaultCap)]))
                ? strtoupper($defaultCap)
                : $defaultCapCode;
        }

        $diff = ucfirst(mb_strtolower(trim($q['difficulty'] ?? 'Médio'), 'UTF-8'));
        if ($diff === 'Facil') $diff = 'Fácil';
        if ($diff === 'Medio') $diff = 'Médio';
        if ($diff === 'Dificil') $diff = 'Difícil';
        if (!in_array($diff, ['Fácil', 'Médio', 'Difícil'])) {
            $diff = 'Médio';
        }

        $rawOptions = isset($q['options']) && is_array($q['options']) ? $q['options'] : [];
        $cleanOptions = [];
        $hasCorrect = false;
        $optLetters = ['A', 'B', 'C', 'D', 'E', 'F'];

        foreach ($rawOptions as $oIdx => $opt) {
            $optText = trim($opt['text'] ?? '');
            $optImg  = !empty($opt['image_url']) ? trim($opt['image_url']) : null;
            // Remove prefixo residual como "a) " ou "A - " caso tenha escapado
            $optText = preg_replace('/^[a-eA-E][\)\.\-]\s+/', '', $optText);
            if ($optText === '' && $optImg === null) {
                $letterLbl = $optLetters[$oIdx] ?? ($oIdx + 1);
                $optText = "[Imagem na Alternativa {$letterLbl}]";
            }

            $isCorr = !empty($opt['is_correct']) && !$hasCorrect;
            if ($isCorr) {
                $hasCorrect = true;
            }
            $cleanOptions[] = [
                'text'       => $optText,
                'image_url'  => $optImg,
                'is_correct' => $isCorr
            ];
        }

        // Se a questão veio sem alternativas textuais porque eram 4 imagens, cria 4 espaços padrão A..D
        if (count($cleanOptions) < 2) {
            for ($k = 0; $k < 4; $k++) {
                $cleanOptions[] = [
                    'text'       => "[Imagem na Alternativa " . $optLetters[$k] . "]",
                    'image_url'  => null,
                    'is_correct' => ($k === 0)
                ];
            }
            $hasCorrect = true;
            $q['needs_image'] = true;
        }

        // Garante que exatamente 1 alternativa esteja marcada como correta
        if (!$hasCorrect) {
            $cleanOptions[0]['is_correct'] = true;
        }

        $fullTextForImg = $ctx . ' ' . $cmd;
        $needsImg = !empty($q['needs_image']) || detectNeedsImage($fullTextForImg);
        $aiSolved = !empty($q['ai_solved_key']);
        $aiMatchedCap = !isset($q['ai_matched_capacity']) || !empty($q['ai_matched_capacity']);

        $normalizedQuestions[] = [
            'number'              => $idx + 1,
            'context'             => $ctx,
            'command'             => $cmd,
            'module'              => $mod,
            'capacity'            => $rawCap,
            'ai_matched_capacity' => $aiMatchedCap,
            'difficulty'          => $diff,
            'needs_image'         => $needsImg,
            'ai_solved_key'       => $aiSolved,
            'options'             => $cleanOptions
        ];
    }

    if (empty($normalizedQuestions)) {
        echo json_encode([
            'success' => false,
            'message' => 'Nenhuma questão de múltipla escolha válida (com enunciado e pelo menos 2 alternativas) foi encontrada no arquivo.'
        ]);
        exit;
    }

    echo json_encode([
        'success'           => true,
        'count'             => count($normalizedQuestions),
        'matrix_capacities' => $matrixCapacities,
        'questions'         => $normalizedQuestions
    ]);
    exit;
}

// ============================================================================
// ETAPA 1B: LEITURA DIRETA DE PLANILHA SAEP (.XLSX) + ESTRATÉGIA HÍBRIDA DE IMAGENS
// ============================================================================
if ($action === 'parse_xlsx_saep') {
    @set_time_limit(180);

    $courseName     = trim($_POST['course_name'] ?? ($jsonInput['course_name'] ?? ''));
    $defaultModule  = trim($_POST['default_module'] ?? ($jsonInput['default_module'] ?? ''));
    if ($defaultModule === '') {
        $defaultModule = 'Sem UC vinculada';
    }
    $downloadImages = !isset($_POST['download_images']) || $_POST['download_images'] === '1' || $_POST['download_images'] === 'true';

    // Garante tabela course_capacities e busca capacidades oficiais do curso
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS course_capacities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            course_id INT NOT NULL,
            capacity_code VARCHAR(50) NOT NULL,
            description TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_course_cap (course_id, capacity_code),
            FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $matrixCapacities = [];
    if ($courseName !== '') {
        $stmtC = $pdo->prepare("SELECT id FROM courses WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmtC->execute([$courseName]);
        $cId = (int)$stmtC->fetchColumn();
        if ($cId > 0) {
            $stmtCaps = $pdo->prepare("
                SELECT capacity_code, description
                FROM course_capacities
                WHERE course_id = ?
                ORDER BY CAST(SUBSTRING(capacity_code, 2) AS UNSIGNED) ASC, capacity_code ASC
            ");
            $stmtCaps->execute([$cId]);
            $matrixCapacities = $stmtCaps->fetchAll();

            if (empty($matrixCapacities)) {
                $stmtLeg = $pdo->prepare("
                    SELECT DISTINCT mc.capacity_code, mc.description
                    FROM module_capacities mc
                    JOIN modules m ON mc.module_id = m.id
                    WHERE m.course_id = ? AND mc.capacity_code != '' AND mc.description != ''
                    ORDER BY LENGTH(mc.capacity_code), mc.capacity_code
                ");
                $stmtLeg->execute([$cId]);
                $matrixCapacities = $stmtLeg->fetchAll();
            }
        }
    }

    if (empty($matrixCapacities)) {
        echo json_encode([
            'success'      => false,
            'needs_matrix' => true,
            'message'      => 'Este curso ainda não possui uma Matriz Curricular de Capacidades (.xls) vinculada. Carregue a Matriz SAEP (.xls) do curso antes de importar a planilha de questões!'
        ]);
        exit;
    }

    $validCapMap = [];
    foreach ($matrixCapacities as $mc) {
        $code = strtoupper(trim($mc['capacity_code']));
        $validCapMap[$code] = trim($mc['description']);
    }
    $defaultCapCode = array_key_first($validCapMap) ?: 'C1';

    // 1. Extrai linhas via PHP ZipArchive ou fallback via SheetJS rows_json enviado pelo navegador
    $rawRows = [];
    if (isset($_FILES['xlsx_file']) && $_FILES['xlsx_file']['error'] === UPLOAD_ERR_OK) {
        $phpParsed = parseSaepQuestionsXlsxPhp($_FILES['xlsx_file']['tmp_name']);
        if ($phpParsed['success'] && !empty($phpParsed['rows'])) {
            $rawRows = $phpParsed['rows'];
        }
    }
    if (empty($rawRows) && !empty($_POST['rows_json'])) {
        $decodedRows = json_decode($_POST['rows_json'], true);
        if (is_array($decodedRows)) {
            $rawRows = $decodedRows;
        }
    }

    if (empty($rawRows)) {
        echo json_encode([
            'success' => false,
            'message' => 'Não foi possível ler as linhas da planilha .xlsx. Verifique se o arquivo não está corrompido ou vazio.'
        ]);
        exit;
    }

    // 2. Identifica colunas pelo cabeçalho da linha 1 (ou usa padrão SAEP A..F)
    $colMap = [
        'id'           => 'A',
        'capacity'     => 'B',
        'statement'    => 'C',
        'alternatives' => 'D',
        'answer'       => 'E',
        'image'        => 'F'
    ];

    $startIdx = 0;
    $firstRow = $rawRows[0];
    $firstRowJoined = mb_strtolower(implode(' ', array_map('strval', $firstRow)), 'UTF-8');
    $looksLikeHeader = (
        strpos($firstRowJoined, 'identificador') !== false ||
        strpos($firstRowJoined, 'capacidade') !== false ||
        strpos($firstRowJoined, 'questão') !== false ||
        strpos($firstRowJoined, 'questao') !== false ||
        strpos($firstRowJoined, 'alternativas') !== false
    );

    if ($looksLikeHeader) {
        $startIdx = 1;
        foreach ($firstRow as $colKey => $hdrVal) {
            $h = mb_strtolower(trim((string)$hdrVal), 'UTF-8');
            if ($h === '') continue;
            if (strpos($h, 'identificador') !== false || strpos($h, 'código') !== false || strpos($h, 'codigo') !== false) {
                $colMap['id'] = $colKey;
            } elseif (strpos($h, 'capacidade') !== false) {
                $colMap['capacity'] = $colKey;
            } elseif (strpos($h, 'questão') !== false || strpos($h, 'questao') !== false || strpos($h, 'enunciado') !== false) {
                $colMap['statement'] = $colKey;
            } elseif (strpos($h, 'alternativa') !== false || strpos($h, 'opções') !== false || strpos($h, 'opcoes') !== false) {
                $colMap['alternatives'] = $colKey;
            } elseif (strpos($h, 'gabarito') !== false || strpos($h, 'resposta') !== false) {
                $colMap['answer'] = $colKey;
            } elseif (strpos($h, 'imagem') !== false || strpos($h, 'figura') !== false) {
                $colMap['image'] = $colKey;
            }
        }
    }

    $uploadDir = __DIR__ . '/uploads/questions/';
    $downloadCache = [];
    $imgStats = [
        'total_links'      => 0,
        'downloaded_local' => 0,
        'kept_external'    => 0
    ];

    $normalizedQuestions = [];
    $qNum = 1;

    for ($rIdx = $startIdx; $rIdx < count($rawRows); $rIdx++) {
        $r = $rawRows[$rIdx];
        $extCode   = trim((string)($r[$colMap['id']] ?? ''));
        $rawCap    = strtoupper(trim((string)($r[$colMap['capacity']] ?? '')));
        $rawStmt   = trim((string)($r[$colMap['statement']] ?? ''));
        $rawAlts   = trim((string)($r[$colMap['alternatives']] ?? ''));
        $rawAnswer = strtoupper(trim((string)($r[$colMap['answer']] ?? 'A')));
        $rawColImg = trim((string)($r[$colMap['image']] ?? ''));

        if ($rawStmt === '' && $rawAlts === '') {
            continue;
        }

        // Normaliza capacidade vinculando à Matriz Oficial
        $capUnmapped = false;
        if (preg_match('/^(C\d+)\b/i', $rawCap, $mCap)) {
            $rawCap = strtoupper($mCap[1]);
        } elseif (is_numeric($rawCap)) {
            $rawCap = 'C' . $rawCap;
        }
        if ($rawCap === '' || !isset($validCapMap[$rawCap])) {
            $capUnmapped = true;
            $rawCap = $defaultCapCode;
        }

        // Separa contexto, comando e eventual URL de imagem embutida no texto da questão
        $stmtParts = splitSaepStatementContextCommand($rawStmt);
        $ctx = $stmtParts['context'];
        $cmd = $stmtParts['command'];
        if ($cmd === '' && $ctx !== '') {
            $cmd = $ctx;
            $ctx = '';
        }
        if ($cmd === '') {
            continue;
        }

        // Resolve imagem do enunciado (prioriza Coluna F, senão inline no enunciado)
        $stmtRemoteUrl = '';
        if ($rawColImg !== '' && isDirectImageUrl($rawColImg)) {
            $stmtRemoteUrl = $rawColImg;
        } elseif (!empty($stmtParts['inline_image_url'])) {
            $stmtRemoteUrl = $stmtParts['inline_image_url'];
        }

        $stmtImageUrl = null;
        $stmtImageSource = null;
        if ($stmtRemoteUrl !== '') {
            $imgStats['total_links']++;
            $resImg = downloadImageHybrid($stmtRemoteUrl, 'q' . $qNum, $uploadDir, $downloadCache, $downloadImages);
            $stmtImageUrl = $resImg['url'];
            $stmtImageSource = $resImg['source'];
            if ($stmtImageSource === 'local') {
                $imgStats['downloaded_local']++;
            } elseif ($stmtImageSource === 'external') {
                $imgStats['kept_external']++;
            }
        }

        // Faz parse das alternativas A..E e extrai + baixa links de imagem das alternativas
        $parsedOpts = parseSaepAlternativesBlock($rawAlts, $rawAnswer);
        $cleanOptions = [];
        $hasOptionImg = false;
        $hasCorrect = false;

        foreach ($parsedOpts as $oIdx => $po) {
            $optImgUrl = null;
            $optImgSrc = null;
            if (!empty($po['raw_image_url'])) {
                $imgStats['total_links']++;
                $resOptImg = downloadImageHybrid($po['raw_image_url'], 'q' . $qNum . '_opt' . ($oIdx + 1), $uploadDir, $downloadCache, $downloadImages);
                $optImgUrl = $resOptImg['url'];
                $optImgSrc = $resOptImg['source'];
                if ($optImgSrc === 'local') {
                    $imgStats['downloaded_local']++;
                } elseif ($optImgSrc === 'external') {
                    $imgStats['kept_external']++;
                }
                if ($optImgUrl !== null) {
                    $hasOptionImg = true;
                }
            }

            $optText = $po['text'];
            if ($optText === '' && $optImgUrl === null) {
                $optText = "[Imagem na Alternativa " . $po['letter'] . "]";
            }

            $isCorr = !empty($po['is_correct']) && !$hasCorrect;
            if ($isCorr) {
                $hasCorrect = true;
            }

            $cleanOptions[] = [
                'text'         => $optText,
                'image_url'    => $optImgUrl,
                'image_source' => $optImgSrc,
                'is_correct'   => $isCorr
            ];
        }

        if (count($cleanOptions) < 2) {
            continue;
        }
        if (!$hasCorrect) {
            $cleanOptions[0]['is_correct'] = true;
        }

        // Só marca "needs_image" se o texto pede imagem E nem o enunciado nem as alternativas trouxeram link
        $needsImg = ($stmtImageUrl === null && !$hasOptionImg && detectNeedsImage($ctx . ' ' . $cmd));

        $normalizedQuestions[] = [
            'number'              => $qNum,
            'external_code'       => $extCode,
            'context'             => $ctx,
            'command'             => $cmd,
            'image_url'           => $stmtImageUrl,
            'image_source'        => $stmtImageSource,
            'module'              => $defaultModule,
            'capacity'            => $rawCap,
            'ai_matched_capacity' => $capUnmapped,
            'difficulty'          => 'Médio',
            'needs_image'         => $needsImg,
            'ai_solved_key'       => false,
            'options'             => $cleanOptions
        ];
        $qNum++;
    }

    if (empty($normalizedQuestions)) {
        echo json_encode([
            'success' => false,
            'message' => 'Nenhuma questão válida (com enunciado e pelo menos 2 alternativas A/B/C/D) foi encontrada na planilha .xlsx.'
        ]);
        exit;
    }

    echo json_encode([
        'success'           => true,
        'count'             => count($normalizedQuestions),
        'matrix_capacities' => $matrixCapacities,
        'image_stats'       => $imgStats,
        'questions'         => $normalizedQuestions
    ]);
    exit;
}

// ============================================================================
// ETAPA 2: SALVAR QUESTÕES REVISADAS NO BANCO DE DADOS (save_questions)
// ============================================================================
if ($action === 'save_questions') {
    $courseName    = trim($jsonInput['course_name'] ?? '');
    $defaultModule = trim($jsonInput['default_module'] ?? '');
    if ($defaultModule === '') {
        $defaultModule = 'Sem UC vinculada';
    }
    $importTag     = trim($jsonInput['import_tag'] ?? '');
    $questions     = $jsonInput['questions'] ?? [];

    if ($courseName === '') {
        echo json_encode(['success' => false, 'message' => 'Por favor, selecione ou informe o nome do Curso para vincular as questões.']);
        exit;
    }

    if (!is_array($questions) || empty($questions)) {
        echo json_encode(['success' => false, 'message' => 'Nenhuma questão enviada para salvamento.']);
        exit;
    }

    $importTagDb = $importTag !== '' ? $importTag : null;

    // Garante colunas external_code e image_url (VARCHAR 500) ANTES de abrir transação
    ensureQuestionSchemaColumns($pdo);

    $cursosCache = [];
    $modulosCache = [];
    $importedQuestionsList = [];
    $importedCount = 0;

    try {
        $pdo->beginTransaction();

        // 1. Resolver ou Criar o Curso
        $stmtFindCourse = $pdo->prepare("SELECT id FROM courses WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmtFindCourse->execute([$courseName]);
        $courseId = $stmtFindCourse->fetchColumn();

        if (!$courseId) {
            $stmtInsertCourse = $pdo->prepare("INSERT INTO courses (name, description) VALUES (?, 'Curso criado via importação inteligente Word/PDF')");
            $stmtInsertCourse->execute([$courseName]);
            $courseId = $pdo->lastInsertId();
        }

        // Busca as capacidades oficiais da Matriz do Curso
        $stmtMatrixCaps = $pdo->prepare("SELECT capacity_code, description FROM course_capacities WHERE course_id = ?");
        $stmtMatrixCaps->execute([$courseId]);
        $courseCaps = $stmtMatrixCaps->fetchAll();

        // Statements preparados para Módulos, Questões e Alternativas
        $stmtFindMod   = $pdo->prepare("SELECT id FROM modules WHERE course_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
        $stmtInsertMod = $pdo->prepare("INSERT INTO modules (course_id, name) VALUES (?, ?)");
        $stmtInsertQ   = $pdo->prepare("INSERT INTO questions (module_id, capacity, context, command, difficulty, import_tag, external_code, image_url, teacher_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtInsertOpt = $pdo->prepare("INSERT INTO question_options (question_id, text, image_url, is_correct) VALUES (?, ?, ?, ?)");
        $stmtSyncModCap = $pdo->prepare("
            INSERT INTO module_capacities (module_id, capacity_code, description)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE description = VALUES(description)
        ");

        foreach ($questions as $q) {
            $context = trim($q['context'] ?? '');
            $command = trim($q['command'] ?? '');
            $options = isset($q['options']) && is_array($q['options']) ? $q['options'] : [];

            if ($command === '' && $context !== '') {
                $command = $context;
                $context = '';
            }

            if ($command === '' || count($options) < 2) {
                continue;
            }

            $modName = trim($q['module'] ?? '');
            if ($modName === '') {
                $modName = $defaultModule !== '' ? $defaultModule : 'Sem UC vinculada';
            }

            $capacity = strtoupper(trim($q['capacity'] ?? ''));
            if (preg_match('/^(C\d+)\b/i', $capacity, $mC)) {
                $capacity = strtoupper($mC[1]);
            }

            $difficulty = trim($q['difficulty'] ?? 'Médio');
            if (!in_array($difficulty, ['Fácil', 'Médio', 'Difícil'])) {
                $difficulty = 'Médio';
            }

            $externalCode = trim((string)($q['external_code'] ?? ''));
            $externalCodeDb = $externalCode !== '' ? mb_substr($externalCode, 0, 100, 'UTF-8') : null;
            $qImageUrl = !empty($q['image_url']) ? trim((string)$q['image_url']) : null;

            // 2. Resolver ou Criar Módulo
            $modKey = $courseId . '_' . mb_strtolower($modName, 'UTF-8');
            if (isset($modulosCache[$modKey])) {
                $moduleId = $modulosCache[$modKey];
            } else {
                $stmtFindMod->execute([$courseId, $modName]);
                $moduleId = $stmtFindMod->fetchColumn();
                if (!$moduleId) {
                    $stmtInsertMod->execute([$courseId, $modName]);
                    $moduleId = $pdo->lastInsertId();
                }
                // Sincroniza todas as capacidades da Matriz do Curso para este módulo
                foreach ($courseCaps as $cc) {
                    $stmtSyncModCap->execute([$moduleId, $cc['capacity_code'], $cc['description']]);
                }
                $modulosCache[$modKey] = $moduleId;
            }

            // 3. Inserir Questão (com Contexto + Comando + Capacidade Oficial da Matriz + external_code + image_url opcional)
            $stmtInsertQ->execute([$moduleId, $capacity, $context, $command, $difficulty, $importTagDb, $externalCodeDb, $qImageUrl, $teacher_id]);
            $questionId = $pdo->lastInsertId();

            // 4. Inserir Alternativas preservando a ordem A, B, C, D, E e imagens das alternativas
            $validOpts = [];
            $hasCorrect = false;
            foreach ($options as $opt) {
                $txt = trim($opt['text'] ?? '');
                $imgUrl = !empty($opt['image_url']) ? trim($opt['image_url']) : null;
                if ($txt === '' && $imgUrl === null) continue;
                $isCorr = !empty($opt['is_correct']) && !$hasCorrect ? 1 : 0;
                if ($isCorr === 1) $hasCorrect = true;
                $validOpts[] = ['text' => $txt, 'image_url' => $imgUrl, 'is_correct' => $isCorr];
            }

            if (!$hasCorrect && !empty($validOpts)) {
                $validOpts[0]['is_correct'] = 1;
            }

            $savedOptsForStep2 = [];
            foreach ($validOpts as $vo) {
                $stmtInsertOpt->execute([$questionId, $vo['text'], $vo['image_url'], $vo['is_correct']]);
                $savedOptsForStep2[] = [
                    'id'         => (int)$pdo->lastInsertId(),
                    'text'       => $vo['text'],
                    'image_url'  => $vo['image_url'],
                    'is_correct' => $vo['is_correct']
                ];
            }

            $needsImg = ($qImageUrl === null && !$hasOptionImgFlag = !empty(array_filter($savedOptsForStep2, fn($so) => !empty($so['image_url']))))
                && (!empty($q['needs_image']) || detectNeedsImage($context . ' ' . $command));

            $importedQuestionsList[] = [
                'id'            => (int)$questionId,
                'external_code' => $externalCodeDb,
                'image_url'     => $qImageUrl,
                'context'       => $context,
                'command'       => $command,
                'course'        => $courseName,
                'module'        => $modName,
                'capacity'      => $capacity,
                'difficulty'    => $difficulty,
                'needs_image'   => $needsImg,
                'options'       => $savedOptsForStep2
            ];

            $importedCount++;
        }

        $pdo->commit();

        echo json_encode([
            'success'            => true,
            'count'              => $importedCount,
            'message'            => "Sucesso! <b>{$importedCount}</b> questão(ões) importadas para o curso <b>" . htmlspecialchars($courseName) . "</b>.",
            'imported_questions' => $importedQuestionsList
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao salvar questões no banco de dados: ' . $e->getMessage()
        ]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Ação inválida.']);

