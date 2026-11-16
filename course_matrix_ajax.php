<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    echo json_encode(['success' => false, 'message' => 'Acesso não autorizado.']);
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection(true);

/**
 * Garante que a tabela course_capacities exista no banco de dados
 */
function ensureCourseCapacitiesTable($pdo) {
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
}

/**
 * Sincroniza as capacidades oficiais da matriz do curso (course_capacities)
 * com todos os módulos daquele curso na tabela module_capacities (usada nos relatórios de Sprints).
 */
function syncCourseCapacitiesToModules($pdo, $courseId) {
    if (!$pdo->inTransaction()) {
        ensureCourseCapacitiesTable($pdo);
    }

    // Se course_capacities estiver vazia mas module_capacities já tiver dados antigos deste curso, hidrata course_capacities
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM course_capacities WHERE course_id = ?");
    $stmtCheck->execute([$courseId]);
    if ((int)$stmtCheck->fetchColumn() === 0) {
        $stmtLegacy = $pdo->prepare("
            SELECT DISTINCT mc.capacity_code, mc.description
            FROM module_capacities mc
            JOIN modules m ON mc.module_id = m.id
            WHERE m.course_id = ? AND mc.capacity_code != '' AND mc.description != ''
            ORDER BY LENGTH(mc.capacity_code), mc.capacity_code
        ");
        $stmtLegacy->execute([$courseId]);
        $legacyRows = $stmtLegacy->fetchAll();
        if (!empty($legacyRows)) {
            $stmtIns = $pdo->prepare("
                INSERT IGNORE INTO course_capacities (course_id, capacity_code, description)
                VALUES (?, ?, ?)
            ");
            foreach ($legacyRows as $lr) {
                $stmtIns->execute([$courseId, trim($lr['capacity_code']), trim($lr['description'])]);
            }
        }
    }

    // Busca todas as capacidades oficiais do curso
    $stmtCaps = $pdo->prepare("
        SELECT capacity_code, description
        FROM course_capacities
        WHERE course_id = ?
        ORDER BY CAST(SUBSTRING(capacity_code, 2) AS UNSIGNED) ASC, capacity_code ASC
    ");
    $stmtCaps->execute([$courseId]);
    $caps = $stmtCaps->fetchAll();

    if (empty($caps)) {
        return [];
    }

    // Busca todos os módulos do curso
    $stmtMods = $pdo->prepare("SELECT id FROM modules WHERE course_id = ?");
    $stmtMods->execute([$courseId]);
    $modIds = $stmtMods->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($modIds)) {
        $stmtSyncMod = $pdo->prepare("
            INSERT INTO module_capacities (module_id, capacity_code, description)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE description = VALUES(description)
        ");
        foreach ($modIds as $mid) {
            foreach ($caps as $c) {
                $stmtSyncMod->execute([$mid, $c['capacity_code'], $c['description']]);
            }
        }
    }

    return $caps;
}

/**
 * Lê um arquivo binário .xls (BIFF8 / Excel 97-2004 gerado pelo sistema de Matriz SAEP)
 * extraindo o nome do Curso (A1), Competência Geral (A2) e as Capacidades C1, C2, C3... (Linha 7: C7, D7, E7, F7...)
 */
function parseSaepMatrixXlsPhp($filePath) {
    $data = @file_get_contents($filePath);
    if ($data === false || strlen($data) < 512) {
        return ['success' => false, 'message' => 'Arquivo vazio ou ilegível.'];
    }

    // Localiza o registro SST (Shared String Table: 0x00FC)
    $sstPos = strpos($data, "\xFC\x00\x1F\x20");
    if ($sstPos === false) {
        // Procura qualquer registro 0x00FC válido
        $offset = 0;
        $len = strlen($data);
        while (($p = strpos($data, "\xFC\x00", $offset)) !== false) {
            if ($p + 12 < $len) {
                $rlen = unpack('v', substr($data, $p + 2, 2))[1];
                $uCnt = unpack('V', substr($data, $p + 8, 4))[1];
                if ($rlen > 10 && $rlen <= 8228 && $uCnt > 5 && $uCnt < 10000) {
                    $sstPos = $p;
                    break;
                }
            }
            $offset = $p + 2;
        }
    }

    if ($sstPos === false) {
        return ['success' => false, 'message' => 'Não foi possível localizar a tabela de textos (SST) no arquivo .xls.'];
    }

    $recLen = unpack('v', substr($data, $sstPos + 2, 2))[1];
    $sstBuf = substr($data, $sstPos + 4, $recLen);
    $uniqueStrings = unpack('V', substr($sstBuf, 4, 4))[1];

    // Lê as primeiras ~60 strings da SST (onde ficam cabeçalho e linha 7 de capacidades da aba Visualização)
    $pos = 8;
    $bufLen = strlen($sstBuf);
    $strings = [];
    $maxToRead = min($uniqueStrings, 80);

    for ($i = 0; $i < $maxToRead && $pos + 3 <= $bufLen; $i++) {
        $charCount = unpack('v', substr($sstBuf, $pos, 2))[1];
        $flags = ord($sstBuf[$pos + 2]);
        $pos += 3;

        $richRuns = 0;
        if ($flags & 0x08) {
            if ($pos + 2 > $bufLen) break;
            $richRuns = unpack('v', substr($sstBuf, $pos, 2))[1];
            $pos += 2;
        }

        $extLen = 0;
        if ($flags & 0x04) {
            if ($pos + 4 > $bufLen) break;
            $extLen = unpack('V', substr($sstBuf, $pos, 4))[1];
            $pos += 4;
        }

        if ($flags & 0x01) {
            $byteLen = $charCount * 2;
            if ($pos + $byteLen > $bufLen) break;
            $rawStr = substr($sstBuf, $pos, $byteLen);
            $str = mb_convert_encoding($rawStr, 'UTF-8', 'UTF-16LE');
            $pos += $byteLen;
        } else {
            $byteLen = $charCount;
            if ($pos + $byteLen > $bufLen) break;
            $rawStr = substr($sstBuf, $pos, $byteLen);
            $str = mb_convert_encoding($rawStr, 'UTF-8', 'ISO-8859-1');
            $pos += $byteLen;
        }

        $pos += ($richRuns * 4) + $extLen;
        $strings[$i] = trim($str);
    }

    // Agora percorre os registros da primeira Worksheet (após o SST até o primeiro EOF 0x000A)
    $cur = $sstPos + 4 + $recLen;
    $totalLen = strlen($data);
    $inFirstSheet = false;
    $cells = []; // [row][col] => texto

    while ($cur + 4 <= $totalLen) {
        $rid = unpack('v', substr($data, $cur, 2))[1];
        $rlen = unpack('v', substr($data, $cur + 2, 2))[1];

        if ($rid === 0x0809) { // BOF (Início de Worksheet)
            $inFirstSheet = true;
        } elseif ($rid === 0x000A && $inFirstSheet) { // EOF da primeira Worksheet (Visualização)
            break;
        } elseif ($inFirstSheet && $rid === 0x00FD && $rlen === 10) { // LABELSST
            $cellData = unpack('vrow/vcol/vxf/Vsst', substr($data, $cur + 4, 10));
            $r = $cellData['row']; // 0-indexed (linha 7 do Excel = índice 6)
            $c = $cellData['col']; // 0-indexed (coluna C do Excel = índice 2)
            $sIdx = $cellData['sst'];
            if (isset($strings[$sIdx])) {
                $cells[$r][$c] = $strings[$sIdx];
            }
        }

        $cur += 4 + $rlen;
    }

    // Extrai Curso (A1 -> row 0, col 0) e Competência Geral (A2 -> row 1, col 0)
    $headerText = $cells[0][0] ?? ($strings[0] ?? '');
    $courseName = '';
    if (preg_match('/Habilita[cç][aã]o\s+Profissional\s*:\s*([^\r\n]+)/iu', $headerText, $m)) {
        $courseName = trim($m[1]);
    }

    $generalCompetence = $cells[1][0] ?? ($strings[1] ?? '');

    // Extrai capacidades da linha 7 (row index 6, a partir da coluna C -> col index >= 2)
    // E códigos da linha 6 (row index 5, ex: C1, C2, C3...)
    $capacities = [];
    if (isset($cells[6]) && is_array($cells[6])) {
        ksort($cells[6]);
        $seq = 1;
        foreach ($cells[6] as $colIdx => $descText) {
            if ($colIdx < 2) continue; // Ignora colunas A e B
            $descText = trim(preg_replace('/\s+/', ' ', $descText));
            if ($descText === '') continue;

            // Verifica se na linha 6 (row index 5) acima desta coluna há o código C1, C2...
            $codeAbove = isset($cells[5][$colIdx]) ? trim($cells[5][$colIdx]) : '';
            if (!preg_match('/^C\d+$/i', $codeAbove)) {
                $codeAbove = 'C' . $seq;
            } else {
                $codeAbove = strtoupper($codeAbove);
            }

            $capacities[] = [
                'capacity_code' => $codeAbove,
                'description'   => $descText
            ];
            $seq++;
        }
    }

    if (empty($capacities)) {
        return [
            'success' => false,
            'message' => 'Não foram encontradas capacidades nas células C7, D7, E7... da planilha enviada.'
        ];
    }

    return [
        'success'            => true,
        'course_name'        => $courseName,
        'general_competence' => $generalCompetence,
        'capacities'         => $capacities
    ];
}

$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true);
$action = $_POST['action'] ?? ($_GET['action'] ?? ($jsonInput['action'] ?? ''));

ensureCourseCapacitiesTable($pdo);

// ============================================================================
// 1. OBTER MATRIZ DE CAPACIDADES DE UM CURSO (get_course_matrix)
// ============================================================================
if ($action === 'get_course_matrix') {
    $courseId = (int)($_GET['course_id'] ?? ($_POST['course_id'] ?? ($jsonInput['course_id'] ?? 0)));
    $courseName = trim($_GET['course_name'] ?? ($_POST['course_name'] ?? ($jsonInput['course_name'] ?? '')));

    if (!$courseId && $courseName !== '') {
        $stmt = $pdo->prepare("SELECT id, name FROM courses WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmt->execute([$courseName]);
        $row = $stmt->fetch();
        if ($row) {
            $courseId = (int)$row['id'];
            $courseName = $row['name'];
        }
    } elseif ($courseId) {
        $stmt = $pdo->prepare("SELECT name FROM courses WHERE id = ? LIMIT 1");
        $stmt->execute([$courseId]);
        $courseName = (string)$stmt->fetchColumn();
    }

    if (!$courseId) {
        echo json_encode([
            'success'        => true,
            'course_exists'  => false,
            'has_matrix'     => false,
            'capacities'     => []
        ]);
        exit;
    }

    $caps = syncCourseCapacitiesToModules($pdo, $courseId);

    echo json_encode([
        'success'       => true,
        'course_exists' => true,
        'course_id'     => $courseId,
        'course_name'   => $courseName,
        'has_matrix'    => !empty($caps),
        'count'         => count($caps),
        'capacities'    => $caps
    ]);
    exit;
}

// ============================================================================
// 2. IMPORTAR E SALVAR MATRIZ SAEP (.XLS / .XLSX) (parse_and_save_matrix)
// ============================================================================
if ($action === 'parse_and_save_matrix') {
    $courseNameInput = trim($_POST['course_name'] ?? ($jsonInput['course_name'] ?? ''));
    $courseIdInput   = (int)($_POST['course_id'] ?? ($jsonInput['course_id'] ?? 0));
    $clientCaps      = $jsonInput['capacities'] ?? (isset($_POST['capacities_json']) ? json_decode($_POST['capacities_json'], true) : null);
    $extractedCourse = '';
    $generalComp     = '';
    $capacitiesToSave = [];

    // Caso 1: Arquivo .xls enviado via upload (ou leitura do arquivo local se solicitado)
    if (isset($_FILES['matrix_file']) && $_FILES['matrix_file']['error'] === UPLOAD_ERR_OK) {
        $parsed = parseSaepMatrixXlsPhp($_FILES['matrix_file']['tmp_name']);
        if ($parsed['success']) {
            $capacitiesToSave = $parsed['capacities'];
            $extractedCourse  = $parsed['course_name'] ?? '';
            $generalComp      = $parsed['general_competence'] ?? '';
        }
    }

    // Caso 2: Capacidades extraídas no cliente via SheetJS (para .xlsx ou fallback)
    if (empty($capacitiesToSave) && is_array($clientCaps) && !empty($clientCaps)) {
        $seq = 1;
        foreach ($clientCaps as $cc) {
            $desc = trim(preg_replace('/\s+/', ' ', $cc['description'] ?? ''));
            if ($desc === '') continue;
            $code = strtoupper(trim($cc['capacity_code'] ?? ('C' . $seq)));
            if (!preg_match('/^C\d+$/', $code)) {
                $code = 'C' . $seq;
            }
            $capacitiesToSave[] = [
                'capacity_code' => $code,
                'description'   => $desc
            ];
            $seq++;
        }
        if (!empty($_POST['extracted_course_name'])) {
            $extractedCourse = trim($_POST['extracted_course_name']);
        } elseif (!empty($jsonInput['extracted_course_name'])) {
            $extractedCourse = trim($jsonInput['extracted_course_name']);
        }
    }

    if (empty($capacitiesToSave)) {
        echo json_encode([
            'success' => false,
            'message' => 'Não foi possível extrair as capacidades (C7, D7, E7...) da planilha enviada. Verifique se o arquivo segue o padrão da Matriz SAEP.'
        ]);
        exit;
    }

    // Resolve o Curso
    if ($courseIdInput > 0) {
        $stmtC = $pdo->prepare("SELECT id, name FROM courses WHERE id = ? LIMIT 1");
        $stmtC->execute([$courseIdInput]);
        $cRow = $stmtC->fetch();
        if (!$cRow) {
            echo json_encode(['success' => false, 'message' => 'Curso não encontrado.']);
            exit;
        }
        $courseId = (int)$cRow['id'];
        $finalCourseName = $cRow['name'];
    } else {
        $finalCourseName = $courseNameInput !== '' ? $courseNameInput : $extractedCourse;
        if ($finalCourseName === '') {
            echo json_encode(['success' => false, 'message' => 'Informe o nome do Curso ou envie uma Matriz SAEP contendo a Habilitação Profissional em A1.']);
            exit;
        }

        $stmtC = $pdo->prepare("SELECT id, name FROM courses WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmtC->execute([$finalCourseName]);
        $cRow = $stmtC->fetch();
        if ($cRow) {
            $courseId = (int)$cRow['id'];
            $finalCourseName = $cRow['name'];
        } else {
            $descCurso = $generalComp !== '' ? $generalComp : 'Curso cadastrado com Matriz de Referência SAEP';
            $stmtInsC = $pdo->prepare("INSERT INTO courses (name, description) VALUES (?, ?)");
            $stmtInsC->execute([$finalCourseName, $descCurso]);
            $courseId = (int)$pdo->lastInsertId();
        }
    }

    try {
        $pdo->beginTransaction();

        // Garante que exista ao menos o módulo "Sem UC vinculada" caso o curso não tenha módulos ainda
        $stmtModCheck = $pdo->prepare("SELECT COUNT(*) FROM modules WHERE course_id = ?");
        $stmtModCheck->execute([$courseId]);
        if ((int)$stmtModCheck->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO modules (course_id, name) VALUES (?, 'Sem UC vinculada')")->execute([$courseId]);
        }

        // Atualiza as capacidades oficiais do curso (invioláveis conforme a matriz)
        $stmtUpsertCap = $pdo->prepare("
            INSERT INTO course_capacities (course_id, capacity_code, description)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE description = VALUES(description)
        ");

        foreach ($capacitiesToSave as $cap) {
            $stmtUpsertCap->execute([$courseId, $cap['capacity_code'], $cap['description']]);
        }

        $pdo->commit();

        $savedCaps = syncCourseCapacitiesToModules($pdo, $courseId);

        echo json_encode([
            'success'     => true,
            'message'     => count($savedCaps) . " capacidades oficiais (C1 a C" . count($savedCaps) . ") vinculadas com sucesso ao curso \"{$finalCourseName}\"!",
            'course_id'   => $courseId,
            'course_name' => $finalCourseName,
            'capacities'  => $savedCaps
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Erro ao salvar matriz do curso: ' . $e->getMessage()]);
        exit;
    }
}

// ============================================================================
// 3. EDITAR QUESTÃO COMPLETA JÁ IMPORTADA (update_question)
// ============================================================================
if ($action === 'update_question') {
    $questionId    = (int)($jsonInput['question_id'] ?? ($_POST['question_id'] ?? 0));
    $courseId      = (int)($jsonInput['course_id'] ?? ($_POST['course_id'] ?? 0));
    $moduleId      = (int)($jsonInput['module_id'] ?? ($_POST['module_id'] ?? 0));
    $newModuleName = trim($jsonInput['new_module_name'] ?? ($_POST['new_module_name'] ?? ''));
    $capacity      = trim($jsonInput['capacity'] ?? ($_POST['capacity'] ?? ''));
    $context       = trim($jsonInput['context'] ?? ($_POST['context'] ?? ''));
    $command       = trim($jsonInput['command'] ?? ($_POST['command'] ?? ''));
    $difficulty    = trim($jsonInput['difficulty'] ?? ($_POST['difficulty'] ?? 'Médio'));
    $importTag     = trim($jsonInput['import_tag'] ?? ($_POST['import_tag'] ?? ''));
    $options       = $jsonInput['options'] ?? [];

    if (!$questionId || $command === '') {
        echo json_encode(['success' => false, 'message' => 'O comando/enunciado da questão é obrigatório.']);
        exit;
    }

    if ($capacity === '') {
        echo json_encode(['success' => false, 'message' => 'Selecione obrigatoriamente uma Capacidade oficial da Matriz do Curso.']);
        exit;
    }

    if (!in_array($difficulty, ['Fácil', 'Médio', 'Difícil'])) {
        $difficulty = 'Médio';
    }

    try {
        $pdo->beginTransaction();

        // Verifica o curso da questão caso não tenha vindo
        if (!$courseId) {
            $stmtFindC = $pdo->prepare("
                SELECT m.course_id
                FROM questions q
                JOIN modules m ON q.module_id = m.id
                WHERE q.id = ?
            ");
            $stmtFindC->execute([$questionId]);
            $courseId = (int)$stmtFindC->fetchColumn();
        }

        // Valida se a capacidade pertence à matriz oficial do curso (se o curso já tiver matriz cadastrada)
        $stmtMatrixCheck = $pdo->prepare("SELECT capacity_code FROM course_capacities WHERE course_id = ?");
        $stmtMatrixCheck->execute([$courseId]);
        $validCodes = $stmtMatrixCheck->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($validCodes) && !in_array($capacity, $validCodes)) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'message' => "A capacidade \"{$capacity}\" não faz parte da Matriz Oficial deste curso. Selecione uma capacidade válida (" . implode(', ', $validCodes) . ")."
            ]);
            exit;
        }

        // Se o usuário digitou uma nova Unidade Curricular / Módulo, cria ou localiza
        if ($newModuleName !== '' && $courseId > 0) {
            $stmtFindM = $pdo->prepare("SELECT id FROM modules WHERE course_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
            $stmtFindM->execute([$courseId, $newModuleName]);
            $foundMid = $stmtFindM->fetchColumn();
            if ($foundMid) {
                $moduleId = (int)$foundMid;
            } else {
                $stmtInsM = $pdo->prepare("INSERT INTO modules (course_id, name) VALUES (?, ?)");
                $stmtInsM->execute([$courseId, $newModuleName]);
                $moduleId = (int)$pdo->lastInsertId();
            }
        }

        if (!$moduleId) {
            $stmtCurrM = $pdo->prepare("SELECT module_id FROM questions WHERE id = ?");
            $stmtCurrM->execute([$questionId]);
            $moduleId = (int)$stmtCurrM->fetchColumn();
        }

        $importTagDb = $importTag !== '' ? $importTag : null;

        // Atualiza dados da questão
        $stmtUpdQ = $pdo->prepare("
            UPDATE questions
            SET module_id = ?, capacity = ?, context = ?, command = ?, difficulty = ?, import_tag = ?
            WHERE id = ?
        ");
        $stmtUpdQ->execute([$moduleId, $capacity, $context, $command, $difficulty, $importTagDb, $questionId]);

        // Atualiza alternativas preservando IDs existentes para não quebrar respostas históricas de sprints
        if (is_array($options) && count($options) >= 2) {
            $stmtExistingOpts = $pdo->prepare("SELECT id FROM question_options WHERE question_id = ? ORDER BY id ASC");
            $stmtExistingOpts->execute([$questionId]);
            $existingIds = $stmtExistingOpts->fetchAll(PDO::FETCH_COLUMN);

            $hasCorrect = false;
            $cleanOpts = [];
            foreach ($options as $opt) {
                $txt = trim($opt['text'] ?? '');
                if ($txt === '') continue;
                $isCorr = (!empty($opt['is_correct']) && !$hasCorrect) ? 1 : 0;
                if ($isCorr === 1) $hasCorrect = true;
                $cleanOpts[] = ['text' => $txt, 'is_correct' => $isCorr];
            }

            if (!$hasCorrect && !empty($cleanOpts)) {
                $cleanOpts[0]['is_correct'] = 1;
            }

            $stmtUpdOpt = $pdo->prepare("UPDATE question_options SET text = ?, is_correct = ? WHERE id = ? AND question_id = ?");
            $stmtInsOpt = $pdo->prepare("INSERT INTO question_options (question_id, text, is_correct) VALUES (?, ?, ?)");

            foreach ($cleanOpts as $idx => $co) {
                if (isset($existingIds[$idx])) {
                    $stmtUpdOpt->execute([$co['text'], $co['is_correct'], $existingIds[$idx], $questionId]);
                } else {
                    $stmtInsOpt->execute([$questionId, $co['text'], $co['is_correct']]);
                }
            }

            // Se agora há menos alternativas que antes, remove apenas as excedentes
            if (count($existingIds) > count($cleanOpts)) {
                $idsToRemove = array_slice($existingIds, count($cleanOpts));
                $placeholders = implode(',', array_fill(0, count($idsToRemove), '?'));
                $stmtDelOpt = $pdo->prepare("DELETE FROM question_options WHERE id IN ($placeholders) AND question_id = ?");
                $stmtDelOpt->execute(array_merge($idsToRemove, [$questionId]));
            }
        }

        // Busca o nome atualizado do módulo
        $stmtModName = $pdo->prepare("SELECT name FROM modules WHERE id = ?");
        $stmtModName->execute([$moduleId]);
        $moduleName = (string)$stmtModName->fetchColumn();

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        // Sincroniza module_capacities após o commit para evitar commit implícito de DDL no MySQL
        if ($courseId > 0) {
            syncCourseCapacitiesToModules($pdo, $courseId);
        }

        // Recarrega alternativas atualizadas
        $stmtReloadOpts = $pdo->prepare("SELECT id, text, is_correct FROM question_options WHERE question_id = ? ORDER BY id ASC");
        $stmtReloadOpts->execute([$questionId]);
        $updatedOptions = $stmtReloadOpts->fetchAll();

        echo json_encode([
            'success'  => true,
            'message'  => 'Questão atualizada com sucesso!',
            'question' => [
                'id'          => $questionId,
                'module_id'   => $moduleId,
                'module_name' => $moduleName,
                'capacity'    => $capacity,
                'context'     => $context,
                'command'     => $command,
                'difficulty'  => $difficulty,
                'import_tag'  => $importTagDb,
                'options'     => $updatedOptions
            ]
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Erro ao atualizar questão: ' . $e->getMessage()]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Ação inválida.']);

