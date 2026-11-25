<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

$sprint_id = $_GET['id'] ?? null;
if (!$sprint_id) {
    die("ID da Sprint não fornecido.");
}

// 1. Busca dados completos da Sprint (Turma, Curso, Unidade, Regional e Professor)
$stmt = $pdo->prepare("
    SELECT s.*,
           c.name as class_name,
           co.name as course_name,
           un.name as unit_name,
           reg.name as regional_name,
           u.name as teacher_name
    FROM sprints s 
    JOIN classes c ON s.class_id = c.id 
    LEFT JOIN courses co ON c.course_id = co.id
    LEFT JOIN units un ON c.unit_id = un.id
    LEFT JOIN regionals reg ON un.regional_id = reg.id
    LEFT JOIN users u ON s.teacher_id = u.id
    WHERE s.id = ?
");
$stmt->execute([$sprint_id]);
$sprint = $stmt->fetch();
if (!$sprint) {
    die("Sprint não encontrada.");
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS course_capacities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            course_id INT NOT NULL,
            capacity_code VARCHAR(50) NOT NULL,
            description TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_course_cap (course_id, capacity_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// 2. Busca as Questões da Sprint em ordem (incluindo descrição oficial da capacidade e curso do módulo)
$stmtQ = $pdo->prepare("
    SELECT q.*,
           m.name as module_name,
           co_m.name as module_course_name,
           COALESCE(cc.description, mc.description) as capacity_desc
    FROM sprint_questions sq
    JOIN questions q ON sq.question_id = q.id
    LEFT JOIN modules m ON q.module_id = m.id
    LEFT JOIN courses co_m ON m.course_id = co_m.id
    LEFT JOIN course_capacities cc ON cc.course_id = m.course_id AND cc.capacity_code = q.capacity
    LEFT JOIN module_capacities mc ON mc.module_id = q.module_id AND mc.capacity_code = q.capacity
    WHERE sq.sprint_id = ?
    ORDER BY sq.order_num ASC
");
$stmtQ->execute([$sprint_id]);
$questions = $stmtQ->fetchAll();

// Helper para converter imagem local em Base64 (para embutir offline no arquivo .doc) ou manter URL externa
function resolveWordImageSrc(?string $imgUrl): ?string {
    $imgUrl = trim((string)$imgUrl);
    if ($imgUrl === '') return null;
    if (preg_match('/^https?:\/\//i', $imgUrl) || strpos($imgUrl, 'data:image/') === 0) {
        return $imgUrl;
    }
    $localPath = __DIR__ . '/' . ltrim($imgUrl, '/\\');
    if (file_exists($localPath) && is_file($localPath)) {
        $ext = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
        $mime = 'image/' . ($ext === 'jpg' ? 'jpeg' : ($ext === 'svg' ? 'svg+xml' : $ext));
        $data = @file_get_contents($localPath);
        if ($data !== false) {
            return 'data:' . $mime . ';base64,' . base64_encode($data);
        }
    }
    return $imgUrl;
}

// Consolida Curso, Unidades Curriculares e Capacidades avaliadas na prova
$courseNameDisplay = trim((string)($sprint['course_name'] ?? ''));
$modulesMap = [];
$capacitiesMap = [];

foreach ($questions as $qItem) {
    if ($courseNameDisplay === '' && !empty($qItem['module_course_name'])) {
        $courseNameDisplay = $qItem['module_course_name'];
    }
    if (!empty($qItem['module_name'])) {
        $modulesMap[$qItem['module_name']] = $qItem['module_name'];
    }
    $cCode = trim((string)($qItem['capacity'] ?? ''));
    if ($cCode !== '') {
        if (!isset($capacitiesMap[$cCode]) || ($capacitiesMap[$cCode] === '' && !empty($qItem['capacity_desc']))) {
            $capacitiesMap[$cCode] = trim((string)($qItem['capacity_desc'] ?? ''));
        }
    }
}
uksort($capacitiesMap, 'strnatcasecmp');

$ucDisplay = !empty($modulesMap) ? implode(' / ', array_values($modulesMap)) : $sprint['name'];
$examDateDisplay = !empty($sprint['start_time'])
    ? date('d/m/Y', strtotime($sprint['start_time']))
    : date('d/m/Y', strtotime($sprint['created_at'] ?? 'now'));
$regionalDisplay = !empty($sprint['regional_name']) ? $sprint['regional_name'] : 'Santa Catarina';
$logoUrl = resolveWordImageSrc('logo.png') ?: 'https://fiesc.com.br/sites/default/files/styles/large/public/2026-02/marca-senai-_reduzida_cor.png?itok=lZKkNM26';

// Array para armazenar o gabarito
$gabarito = [];

// Sanitiza nome do arquivo para download
$safeFilename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $sprint['name']);

// Envia cabeçalhos para forçar o download como documento do Word (.doc)
header("Content-Type: application/vnd.ms-word; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"Avaliacao_Objetiva_SENAI_{$safeFilename}.doc\"");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
header("Expires: 0");
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40" lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Avaliação Objetiva - SENAI - <?= htmlspecialchars($sprint['name']) ?></title>
    <!--[if gte mso 9]>
    <xml>
        <w:WordDocument>
            <w:View>Print</w:View>
            <w:Zoom>100</w:Zoom>
            <w:DoNotOptimizeForBrowser/>
        </w:WordDocument>
    </xml>
    <![endif]-->
    <style>
        @page WordSection1 {
            size: 210mm 297mm;
            margin: 12mm 14mm 12mm 14mm;
        }
        div.WordSection1 {
            page: WordSection1;
        }
        body {
            font-family: "Segoe UI", Calibri, Arial, sans-serif;
            font-size: 10.5pt;
            line-height: 1.45;
            color: #111111;
        }

        /* CABEÇALHO INSTITUCIONAL SENAI */
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            border: 1.5pt solid #111111;
        }
        .header-table td {
            border: 1pt solid #111111;
            padding: 5px 8px;
            vertical-align: middle;
            font-size: 10pt;
        }
        .header-logo-cell {
            width: 22%;
            text-align: center;
            padding: 10px 8px;
        }
        .instituicao-nome {
            font-size: 8.5pt;
            font-weight: bold;
            line-height: 1.25;
            color: #111111;
            margin-top: 4px;
        }
        .instituicao-estado {
            font-size: 8pt;
            font-weight: normal;
            margin-top: 2px;
            color: #111111;
        }
        .header-title-cell {
            width: 62%;
            text-align: center;
            font-size: 12pt;
            font-weight: bold;
            letter-spacing: 0.3px;
            padding: 8px;
        }
        .header-field-label {
            font-weight: bold;
            color: #111111;
        }
        .header-field-value {
            color: #1a66ff;
            font-style: italic;
        }
        .header-field-value-bold {
            color: #1a66ff;
            font-style: italic;
            font-weight: bold;
        }
        .header-score-cell {
            width: 16%;
            text-align: center;
            font-weight: bold;
            font-size: 10pt;
        }

        /* SEÇÃO DE CAPACIDADES */
        .capacidades-table {
            width: 100%;
            border-collapse: collapse;
            border: 1.5pt solid #111111;
            margin-bottom: 16px;
        }
        .section-bar-cell {
            background-color: #004b8d;
            color: #ffffff;
            font-weight: bold;
            font-size: 9.5pt;
            text-transform: uppercase;
            padding: 5px 10px;
            letter-spacing: 0.3px;
        }
        .capacidades-body-cell {
            padding: 8px 12px;
            font-size: 9.5pt;
            line-height: 1.5;
        }
        .cap-item {
            margin: 0 0 4px 0;
        }

        /* CARDS DE QUESTÕES (ITENS) */
        .item-table {
            width: 100%;
            border-collapse: collapse;
            border: 1.2pt solid #444444;
            margin-bottom: 16px;
            page-break-inside: avoid;
        }
        .item-header-left {
            background-color: #004b8d;
            color: #ffffff;
            padding: 6px 12px;
            font-size: 10pt;
            font-weight: bold;
            text-transform: uppercase;
            border-bottom: 1pt solid #444444;
        }
        .item-header-right {
            background-color: #004b8d;
            color: #ffffff;
            padding: 6px 12px;
            text-align: right;
            border-bottom: 1pt solid #444444;
            width: 110px;
        }
        .badge {
            display: inline-block;
            padding: 2px 10px;
            font-size: 8.5pt;
            font-weight: bold;
            color: #ffffff;
            text-align: center;
        }
        .badge-facil { background-color: #16a34a; }
        .badge-medio { background-color: #f0a500; }
        .badge-dificil { background-color: #dc2626; }

        .item-body-cell {
            padding: 12px 14px 14px 14px;
            font-size: 10pt;
            background-color: #ffffff;
        }
        .item-block {
            margin: 0 0 9px 0;
            text-align: justify;
        }
        .label-blue {
            color: #1a66ff;
            font-weight: bold;
            font-style: italic;
        }
        .alternativa-p {
            margin: 0 0 6px 0;
            font-style: italic;
            line-height: 1.45;
        }

        /* TABELA DE GABARITO */
        .gabarito-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
            border: 1.2pt solid #111111;
        }
        .gabarito-table th {
            background-color: #004b8d;
            color: #ffffff;
            border: 1pt solid #111111;
            padding: 6px 8px;
            font-size: 9.5pt;
            text-align: center;
        }
        .gabarito-table td {
            border: 1pt solid #111111;
            padding: 6px 8px;
            font-size: 9.5pt;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="WordSection1">

    <!-- ==================== CABEÇALHO INSTITUCIONAL SENAI ==================== -->
    <table class="header-table">
        <tbody>
            <tr>
                <td rowspan="7" class="header-logo-cell">
                    <?php if ($logoUrl): ?>
                        <img src="<?= htmlspecialchars($logoUrl) ?>" width="105" alt="SENAI" style="display:block; margin:0 auto 4px auto; max-width:105px; height:auto;">
                    <?php else: ?>
                        <div style="font-size:18pt; font-weight:bold; color:#004b8d;">SENAI</div>
                    <?php endif; ?>
                    <div class="instituicao-nome">
                        Serviço Nacional de<br>
                        Aprendizagem<br>
                        Industrial
                    </div>
                    <div class="instituicao-estado"><?= htmlspecialchars($regionalDisplay) ?></div>
                </td>
                <td class="header-title-cell">AVALIAÇÃO OBJETIVA</td>
                <td rowspan="7" class="header-score-cell">Desempenho</td>
            </tr>
            <tr>
                <td>
                    <span class="header-field-label">Data:</span>
                    <span class="header-field-value"><?= htmlspecialchars($examDateDisplay) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="header-field-label">Docente:</span>
                    <span class="header-field-value"><?= htmlspecialchars($sprint['teacher_name'] ?? '') ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="header-field-label">Curso Técnico em:</span>
                    <span class="header-field-value-bold"><?= htmlspecialchars($courseNameDisplay ?: $sprint['class_name']) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="header-field-label">Unidade Curricular:</span>
                    <span class="header-field-value-bold"><?= htmlspecialchars($ucDisplay) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="header-field-label">Turma:</span>
                    <span class="header-field-value-bold"><?= htmlspecialchars($sprint['class_name']) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="header-field-label">Estudante:</span>
                    <span class="header-field-value">&nbsp;</span>
                </td>
            </tr>
        </tbody>
    </table>

    <!-- ==================== SEÇÃO DE CAPACIDADES ==================== -->
    <?php if (!empty($capacitiesMap)): ?>
    <table class="capacidades-table">
        <tbody>
            <tr>
                <td class="section-bar-cell">CAPACIDADES</td>
            </tr>
            <tr>
                <td class="capacidades-body-cell">
                    <?php foreach ($capacitiesMap as $cCode => $cDesc): ?>
                        <p class="cap-item">
                            <strong><?= htmlspecialchars($cCode) ?>:</strong>
                            <?= htmlspecialchars($cDesc !== '' ? $cDesc : 'Capacidade técnica avaliada nesta unidade curricular.') ?>
                        </p>
                    <?php endforeach; ?>
                </td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- ==================== ITENS / QUESTÕES ==================== -->
    <?php 
    $q_number = 1;
    $letras_min = ['a', 'b', 'c', 'd', 'e', 'f'];
    $letras_mai = ['A', 'B', 'C', 'D', 'E', 'F'];

    foreach ($questions as $q): 
        // Busca as alternativas na ordem oficial cadastrada (sem embaralhar com RAND())
        $stmtOpt = $pdo->prepare("SELECT * FROM question_options WHERE question_id = ? ORDER BY id ASC");
        $stmtOpt->execute([$q['id']]);
        $options = $stmtOpt->fetchAll();

        $diff = trim((string)($q['difficulty'] ?: 'Médio'));
        $badgeClass = 'badge-medio';
        if (stripos($diff, 'Fácil') !== false || stripos($diff, 'Facil') !== false) {
            $badgeClass = 'badge-facil';
        } elseif (stripos($diff, 'Difícil') !== false || stripos($diff, 'Dificil') !== false) {
            $badgeClass = 'badge-dificil';
        }

        // Suporta tanto 'context' (coluna real do banco) quanto 'base_text' (legado)
        $contextText = trim((string)($q['context'] ?? ($q['base_text'] ?? '')));
        $commandText = trim((string)($q['command'] ?? ''));
        $stmtImgSrc  = resolveWordImageSrc($q['image_url'] ?? null);
        $correct_letter = '-';
    ?>
    <table class="item-table">
        <tbody>
            <tr>
                <td class="item-header-left">ITEM <?= $q_number ?></td>
                <td class="item-header-right">
                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($diff) ?></span>
                </td>
            </tr>
            <tr>
                <td colspan="2" class="item-body-cell">
                    <p class="item-block">
                        <span class="label-blue">CAPACIDADE:</span> <?= htmlspecialchars($q['capacity'] ?: '-') ?>
                    </p>

                    <?php if ($contextText !== ''): ?>
                    <p class="item-block">
                        <span class="label-blue">Contexto:</span>
                        <?= nl2br(htmlspecialchars($contextText)) ?>
                    </p>
                    <?php endif; ?>

                    <?php if ($stmtImgSrc): ?>
                    <div style="text-align:center; margin: 10px 0 12px 0;">
                        <img src="<?= htmlspecialchars($stmtImgSrc) ?>" style="max-width:440px; max-height:300px; height:auto;" alt="Figura do Item <?= $q_number ?>">
                    </div>
                    <?php endif; ?>

                    <?php if ($commandText !== ''): ?>
                    <p class="item-block">
                        <span class="label-blue">Comando:</span>
                        <?= nl2br(htmlspecialchars($commandText)) ?>
                    </p>
                    <?php endif; ?>

                    <p class="item-block" style="margin-top:10px; margin-bottom:6px;">
                        <span class="label-blue">Alternativas:</span>
                    </p>

                    <?php 
                    foreach ($options as $opt_idx => $opt): 
                        $letraMin = $letras_min[$opt_idx] ?? '?';
                        $letraMai = $letras_mai[$opt_idx] ?? '?';
                        if (!empty($opt['is_correct'])) {
                            $correct_letter = $letraMai;
                        }
                        $optImgSrc = resolveWordImageSrc($opt['image_url'] ?? null);
                        $optText   = trim((string)($opt['text'] ?? ''));
                    ?>
                        <p class="alternativa-p">
                            <strong><?= $letraMin ?>)</strong> <?= $optText !== '' ? nl2br(htmlspecialchars($optText)) : '' ?>
                        </p>
                        <?php if ($optImgSrc): ?>
                        <div style="margin: 4px 0 10px 18px;">
                            <img src="<?= htmlspecialchars($optImgSrc) ?>" style="max-width:320px; max-height:200px; height:auto;" alt="Alternativa <?= $letraMai ?>">
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </td>
            </tr>
        </tbody>
    </table>
    <?php 
        $capLabel = $q['capacity'] ?: '-';
        if (!empty($q['capacity_desc'])) {
            $capLabel .= ' — ' . $q['capacity_desc'];
        }
        $gabarito[] = [
            'num'     => $q_number,
            'cap'     => $capLabel,
            'diff'    => $diff,
            'correct' => $correct_letter
        ];
        $q_number++;
    endforeach; 
    ?>

    <!-- ==================== QUEBRA DE PÁGINA PARA O GABARITO ==================== -->
    <br clear="all" style="page-break-before:always; mso-break-type:section-break;" />

    <table class="capacidades-table" style="margin-bottom:0;">
        <tbody>
            <tr>
                <td class="section-bar-cell" style="text-align:center;">
                    GABARITO OFICIAL DO DOCENTE — METODOLOGIA SENAI DE EDUCAÇÃO PROFISSIONAL (MSEP)
                </td>
            </tr>
        </tbody>
    </table>

    <table class="gabarito-table">
        <thead>
            <tr>
                <th width="10%">Item</th>
                <th width="62%">Capacidade Avaliada (Código + Descrição Oficial)</th>
                <th width="14%">Dificuldade</th>
                <th width="14%">Gabarito</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($gabarito as $g): ?>
            <tr>
                <td><strong>ITEM <?= $g['num'] ?></strong></td>
                <td style="text-align:left;"><?= htmlspecialchars($g['cap']) ?></td>
                <td><?= htmlspecialchars($g['diff']) ?></td>
                <td><strong><?= htmlspecialchars($g['correct']) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

</div>
</body>
</html>
