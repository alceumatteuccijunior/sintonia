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

// 1. Busca dados da Sprint
$stmt = $pdo->prepare("
    SELECT s.*, c.name as class_name, u.name as teacher_name
    FROM sprints s 
    JOIN classes c ON s.class_id = c.id 
    JOIN users u ON s.teacher_id = u.id
    WHERE s.id = ?
");
$stmt->execute([$sprint_id]);
$sprint = $stmt->fetch();
if (!$sprint) {
    die("Sprint não encontrada.");
}

// 2. Busca as Questões da Sprint em ordem
$stmtQ = $pdo->prepare("
    SELECT q.*, m.name as module_name
    FROM sprint_questions sq
    JOIN questions q ON sq.question_id = q.id
    LEFT JOIN modules m ON q.module_id = m.id
    WHERE sq.sprint_id = ?
    ORDER BY sq.order_num ASC
");
$stmtQ->execute([$sprint_id]);
$questions = $stmtQ->fetchAll();

// Array para armazenar o gabarito
$gabarito = [];

// Envia cabeçalhos para forçar o download como documento do Word
header("Content-Type: application/vnd.ms-word; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"Prova_SENAI_{$sprint['name']}.doc\"");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
header("Expires: 0");
?>
<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'>
<head>
    <meta charset="utf-8">
    <title>Prova SENAI</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            font-size: 11pt;
            color: #000;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .header-table td {
            border: 1px solid #000;
            padding: 5px;
            font-size: 10pt;
        }
        .bold {
            font-weight: bold;
        }
        .center {
            text-align: center;
        }
        .title {
            font-size: 14pt;
            font-weight: bold;
            text-align: center;
            margin-bottom: 20px;
            text-transform: uppercase;
        }
        .question-box {
            margin-bottom: 30px;
        }
        .question-header {
            font-weight: bold;
            margin-bottom: 10px;
        }
        .base-text {
            margin-bottom: 10px;
            text-align: justify;
        }
        .command-text {
            margin-bottom: 10px;
            font-weight: bold;
        }
        .options {
            list-style-type: none;
            padding-left: 0;
        }
        .options li {
            margin-bottom: 5px;
        }
        .gabarito-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 40px;
        }
        .gabarito-table th, .gabarito-table td {
            border: 1px solid #000;
            padding: 5px;
            text-align: center;
        }
        .gabarito-title {
            font-weight: bold;
            font-size: 12pt;
            text-align: center;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>

    <!-- CABEÇALHO PADRÃO SENAI MSEP -->
    <table class="header-table">
        <tr>
            <td rowspan="4" width="20%" class="center bold" style="font-size:20pt;">SENAI</td>
            <td colspan="3"><span class="bold">Unidade Operacional:</span> ______________________________________________________</td>
        </tr>
        <tr>
            <td colspan="3"><span class="bold">Curso:</span> _________________________________________________________________</td>
        </tr>
        <tr>
            <td colspan="3"><span class="bold">Unidade Curricular:</span> <?= htmlspecialchars($sprint['name']) ?></td>
        </tr>
        <tr>
            <td colspan="3"><span class="bold">Docente:</span> <?= htmlspecialchars($sprint['teacher_name']) ?></td>
        </tr>
        <tr>
            <td colspan="2" width="60%"><span class="bold">Estudante:</span> ______________________________________________________</td>
            <td width="20%"><span class="bold">Turma:</span> <?= htmlspecialchars($sprint['class_name']) ?></td>
            <td width="20%"><span class="bold">Data:</span> ____/____/______</td>
        </tr>
    </table>

    <div class="title">Avaliação de Conhecimentos</div>

    <?php 
    $q_number = 1;
    foreach ($questions as $q): 
        // Busca as alternativas desta questão
        $stmtOpt = $pdo->prepare("SELECT * FROM question_options WHERE question_id = ? ORDER BY RAND()");
        $stmtOpt->execute([$q['id']]);
        $options = $stmtOpt->fetchAll();
        
        $letras = ['A', 'B', 'C', 'D', 'E'];
        $correct_letter = '-';
        
    ?>
    <div class="question-box">
        <div class="question-header">QUESTÃO <?= $q_number ?></div>
        
        <?php if (!empty($q['base_text'])): ?>
            <div class="base-text"><?= nl2br(htmlspecialchars($q['base_text'])) ?></div>
        <?php endif; ?>
        
        <?php if (!empty($q['command'])): ?>
            <div class="command-text"><?= nl2br(htmlspecialchars($q['command'])) ?></div>
        <?php endif; ?>
        
        <ul class="options">
            <?php 
            $opt_idx = 0;
            foreach ($options as $opt): 
                $letra = $letras[$opt_idx] ?? '?';
                if ($opt['is_correct']) {
                    $correct_letter = $letra;
                }
            ?>
            <li><span class="bold"><?= $letra ?>)</span> <?= htmlspecialchars($opt['text']) ?></li>
            <?php 
                $opt_idx++;
            endforeach; 
            ?>
        </ul>
    </div>
    <?php 
        // Salva dados pro gabarito
        $gabarito[] = [
            'num' => $q_number,
            'cap' => $q['capacity'] ?: '-',
            'diff' => $q['difficulty'] ?: 'Normal',
            'correct' => $correct_letter
        ];
        $q_number++;
    endforeach; 
    ?>

    <!-- QUEBRA DE PÁGINA PARA O GABARITO -->
    <br clear="all" style="page-break-before:always" />

    <div class="gabarito-title">Gabarito gerado pelo Sistema Gerador Inteligente SENAI - Metodologia SENAI de Educação Profissional (MSEP)</div>
    
    <table class="gabarito-table">
        <thead>
            <tr>
                <th>Questão</th>
                <th>Capacidade (C1/C2/C3...)</th>
                <th>Dificuldade</th>
                <th>Alternativa Correta</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($gabarito as $g): ?>
            <tr>
                <td class="bold"><?= $g['num'] ?></td>
                <td><?= htmlspecialchars($g['cap']) ?></td>
                <td><?= htmlspecialchars($g['diff']) ?></td>
                <td class="bold"><?= $g['correct'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

</body>
</html>
