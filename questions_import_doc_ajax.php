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

// ============================================================================
// ETAPA 1: EXTRAÇÃO E ESTRUTURAÇÃO DE QUESTÕES VIA IA (parse_text)
// ============================================================================
if ($action === 'parse_text') {
    if (!defined('OPENAI_API_KEY') || trim(OPENAI_API_KEY) === '') {
        echo json_encode(['success' => false, 'message' => 'A chave da API da OpenAI (OPENAI_API_KEY) não está configurada no arquivo config.php.']);
        exit;
    }

    $rawText       = trim($_POST['raw_text'] ?? ($jsonInput['raw_text'] ?? ''));
    $courseName    = trim($_POST['course_name'] ?? ($jsonInput['course_name'] ?? ''));
    $defaultModule = trim($_POST['default_module'] ?? ($jsonInput['default_module'] ?? 'Módulo Geral'));
    $defaultCap    = trim($_POST['default_capacity'] ?? ($jsonInput['default_capacity'] ?? ''));
    $answerKey     = trim($_POST['answer_key'] ?? ($jsonInput['answer_key'] ?? ''));

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
      "command": "Texto completo do enunciado (incluindo texto-base, contexto, itens I, II, III se houverem e a pergunta/comando final). Não inclua o número da questão no início.",
      "module": "Nome do módulo/unidade curricular se citado na questão, ou o módulo padrão informado",
      "capacity": "Código ou nome da capacidade/competência/habilidade se citado na questão (ex: C1, H2), ou vazio",
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

2. PRESERVAÇÃO DO ENUNCIADO:
- Mantenha o texto integral do enunciado e contexto técnico sem resumir.
- Se a questão tiver afirmações em algarismos romanos (I, II, III...) ou código-fonte, preserve-os dentro de "command" usando quebras de linha (\n).
- Remova apenas o prefixo de numeração ("Questão 01 -", "1.", "1)") do início do enunciado.

3. ALTERNATIVAS E GABARITO:
- Extraia todas as alternativas da questão (normalmente 4 ou 5 alternativas).
- Remova as letras de prefixo ("a)", "A -", "(A)") do campo "text" de cada alternativa.
- Determine qual alternativa é a correta ("is_correct": true) seguindo esta prioridade:
  a) Se o usuário forneceu um GABARITO RÁPIDO nas instruções ou se há uma tabela de gabarito / marcação explícita no documento (ex: [X], *(correta)*, Gabarito: C), use esse gabarito e defina "ai_solved_key": false.
  b) Se NÃO houver gabarito informado para a questão, resolva tecnicamente a questão com seu conhecimento especialista, marque a alternativa correta com "is_correct": true e defina "ai_solved_key": true.
- Exatamente UMA alternativa por questão deve ter "is_correct": true.

4. DETECÇÃO DE IMAGEM ("needs_image"):
- Defina "needs_image": true se o enunciado fizer referência a qualquer figura, imagem, diagrama, circuito, gráfico, esquema, desenho ou quadro visual que não veio no texto (ex: "Observe a figura a seguir", "No circuito abaixo", "De acordo com o diagrama"). Caso contrário, false.

5. CLASSIFICAÇÃO ("difficulty"):
- O campo "difficulty" deve ser estritamente um destes três valores: "Fácil", "Médio" ou "Difícil".
PROMPT;

    $userPrompt = "CONTEXTO PADRÃO INFORMADO PELO PROFESSOR:\n";
    $userPrompt .= "- Curso: " . ($courseName !== '' ? $courseName : 'Não especificado') . "\n";
    $userPrompt .= "- Módulo Padrão (usar se a questão não especificar outro): " . ($defaultModule !== '' ? $defaultModule : 'Módulo Geral') . "\n";
    if ($defaultCap !== '') {
        $userPrompt .= "- Capacidade Padrão (usar se a questão não especificar outra): " . $defaultCap . "\n";
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
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        echo json_encode(['success' => false, 'message' => 'Erro de comunicação com a IA (OpenAI): ' . $curlErr]);
        exit;
    }

    $decoded = json_decode($response, true);

    // Fallback automático para gpt-3.5-turbo caso a chave não tenha acesso ao gpt-4o-mini
    if (isset($decoded['error'])) {
        $payload['model'] = 'gpt-3.5-turbo';
        $payload['max_tokens'] = 4000;
        unset($payload['response_format']);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY
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
            echo json_encode(['success' => false, 'message' => 'Erro retornado pela OpenAI: ' . ($decoded['error']['message'] ?? 'Erro desconhecido')]);
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

    $normalizedQuestions = [];
    foreach ($parsedJson['questions'] as $idx => $q) {
        $cmd = trim($q['command'] ?? '');
        if ($cmd === '') {
            continue;
        }

        $mod = trim($q['module'] ?? '');
        if ($mod === '') {
            $mod = $defaultModule !== '' ? $defaultModule : 'Módulo Geral';
        }

        $cap = trim($q['capacity'] ?? '');
        if ($cap === '' && $defaultCap !== '') {
            $cap = $defaultCap;
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

        foreach ($rawOptions as $opt) {
            $optText = trim($opt['text'] ?? '');
            // Remove prefixo residual como "a) " ou "A - " caso tenha escapado
            $optText = preg_replace('/^[a-eA-E][\)\.\-]\s+/', '', $optText);
            if ($optText === '') continue;

            $isCorr = !empty($opt['is_correct']) && !$hasCorrect;
            if ($isCorr) {
                $hasCorrect = true;
            }
            $cleanOptions[] = [
                'text' => $optText,
                'is_correct' => $isCorr
            ];
        }

        if (count($cleanOptions) < 2) {
            continue;
        }

        // Garante que exatamente 1 alternativa esteja marcada como correta
        if (!$hasCorrect) {
            $cleanOptions[0]['is_correct'] = true;
        }

        $needsImg = !empty($q['needs_image']) || detectNeedsImage($cmd);
        $aiSolved = !empty($q['ai_solved_key']);

        $normalizedQuestions[] = [
            'number' => $idx + 1,
            'command' => $cmd,
            'module' => $mod,
            'capacity' => $cap,
            'difficulty' => $diff,
            'needs_image' => $needsImg,
            'ai_solved_key' => $aiSolved,
            'options' => $cleanOptions
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
        'success' => true,
        'count' => count($normalizedQuestions),
        'questions' => $normalizedQuestions
    ]);
    exit;
}

// ============================================================================
// ETAPA 2: SALVAR QUESTÕES REVISADAS NO BANCO DE DADOS (save_questions)
// ============================================================================
if ($action === 'save_questions') {
    $courseName    = trim($jsonInput['course_name'] ?? '');
    $defaultModule = trim($jsonInput['default_module'] ?? 'Módulo Geral');
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

        // Statements preparados para Módulos, Questões e Alternativas
        $stmtFindMod   = $pdo->prepare("SELECT id FROM modules WHERE course_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
        $stmtInsertMod = $pdo->prepare("INSERT INTO modules (course_id, name) VALUES (?, ?)");
        $stmtInsertQ   = $pdo->prepare("INSERT INTO questions (module_id, capacity, command, difficulty, import_tag, teacher_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtInsertOpt = $pdo->prepare("INSERT INTO question_options (question_id, text, is_correct) VALUES (?, ?, ?)");

        foreach ($questions as $q) {
            $command = trim($q['command'] ?? '');
            $options = isset($q['options']) && is_array($q['options']) ? $q['options'] : [];

            if ($command === '' || count($options) < 2) {
                continue;
            }

            $modName = trim($q['module'] ?? '');
            if ($modName === '') {
                $modName = $defaultModule !== '' ? $defaultModule : 'Módulo Geral';
            }

            $capacity = trim($q['capacity'] ?? '');
            $difficulty = trim($q['difficulty'] ?? 'Médio');
            if (!in_array($difficulty, ['Fácil', 'Médio', 'Difícil'])) {
                $difficulty = 'Médio';
            }

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
                $modulosCache[$modKey] = $moduleId;
            }

            // 3. Inserir Questão
            $stmtInsertQ->execute([$moduleId, $capacity, $command, $difficulty, $importTagDb, $teacher_id]);
            $questionId = $pdo->lastInsertId();

            // 4. Embaralhar e Inserir Alternativas
            $validOpts = [];
            $hasCorrect = false;
            foreach ($options as $opt) {
                $txt = trim($opt['text'] ?? '');
                if ($txt === '') continue;
                $isCorr = !empty($opt['is_correct']) && !$hasCorrect ? 1 : 0;
                if ($isCorr === 1) $hasCorrect = true;
                $validOpts[] = ['text' => $txt, 'is_correct' => $isCorr];
            }

            if (!$hasCorrect && !empty($validOpts)) {
                $validOpts[0]['is_correct'] = 1;
            }

            shuffle($validOpts);

            foreach ($validOpts as $vo) {
                $stmtInsertOpt->execute([$questionId, $vo['text'], $vo['is_correct']]);
            }

            $needsImg = !empty($q['needs_image']) || detectNeedsImage($command);

            $importedQuestionsList[] = [
                'id'          => (int)$questionId,
                'command'     => $command,
                'course'      => $courseName,
                'module'      => $modName,
                'capacity'    => $capacity,
                'difficulty'  => $difficulty,
                'needs_image' => $needsImg
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
