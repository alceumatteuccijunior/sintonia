<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';
$pdo = getPDOConnection(true);

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

// Parâmetros podem vir via POST (do Montador de Prova no Banco de Questões) ou via GET (?sprint_id=X)
$sprint_id = isset($_REQUEST['sprint_id']) ? (int)$_REQUEST['sprint_id'] : 0;
$raw_qids  = $_REQUEST['question_ids'] ?? '';

$exam_title       = trim((string)($_REQUEST['exam_title'] ?? 'AVALIAÇÃO OBJETIVA'));
$exam_date        = trim((string)($_REQUEST['exam_date'] ?? date('d/m/Y')));
$teacher_name     = trim((string)($_REQUEST['teacher_name'] ?? ($_SESSION['user_name'] ?? '')));
$course_name      = trim((string)($_REQUEST['course_name'] ?? ''));
$module_name      = trim((string)($_REQUEST['module_name'] ?? ''));
$class_name       = trim((string)($_REQUEST['class_name'] ?? ''));
$state_name       = trim((string)($_REQUEST['state_name'] ?? 'Santa Catarina'));
$include_gabarito = isset($_REQUEST['include_gabarito']) ? (bool)$_REQUEST['include_gabarito'] : true;
$auto_print       = !empty($_REQUEST['auto_print']);

$questions = [];

if ($sprint_id > 0) {
    // Modo Sprint Existente
    if ($_SESSION['user_role'] === 'admin') {
        $stmtSp = $pdo->prepare("
            SELECT s.*, c.name as class_name, co.name as course_name, reg.name as regional_name, u.name as teacher_name
            FROM sprints s
            JOIN classes c ON s.class_id = c.id
            LEFT JOIN courses co ON c.course_id = co.id
            LEFT JOIN units un ON c.unit_id = un.id
            LEFT JOIN regionals reg ON un.regional_id = reg.id
            LEFT JOIN users u ON s.teacher_id = u.id
            WHERE s.id = ?
        ");
        $stmtSp->execute([$sprint_id]);
    } else {
        $stmtSp = $pdo->prepare("
            SELECT s.*, c.name as class_name, co.name as course_name, reg.name as regional_name, u.name as teacher_name
            FROM sprints s
            JOIN classes c ON s.class_id = c.id
            LEFT JOIN courses co ON c.course_id = co.id
            LEFT JOIN units un ON c.unit_id = un.id
            LEFT JOIN regionals reg ON un.regional_id = reg.id
            LEFT JOIN users u ON s.teacher_id = u.id
            WHERE s.id = ? AND s.teacher_id = ?
        ");
        $stmtSp->execute([$sprint_id, $_SESSION['user_id']]);
    }
    $sprint = $stmtSp->fetch();
    if (!$sprint) {
        die("Avaliação não encontrada ou sem permissão.");
    }

    if (!isset($_REQUEST['exam_date'])) {
        $exam_date = !empty($sprint['start_time'])
            ? date('d/m/Y', strtotime($sprint['start_time']))
            : date('d/m/Y', strtotime($sprint['created_at'] ?? 'now'));
    }
    if (!isset($_REQUEST['teacher_name']) && !empty($sprint['teacher_name'])) {
        $teacher_name = $sprint['teacher_name'];
    }
    if ($course_name === '' && !empty($sprint['course_name'])) {
        $course_name = $sprint['course_name'];
    }
    if ($class_name === '' && !empty($sprint['class_name'])) {
        $class_name = $sprint['class_name'];
    }
    if (!isset($_REQUEST['state_name']) && !empty($sprint['regional_name'])) {
        $state_name = $sprint['regional_name'];
    }

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
    $questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Modo Seleção de Questões do Banco de Questões
    $qIds = [];
    if (is_array($raw_qids)) {
        foreach ($raw_qids as $idVal) {
            $idInt = (int)$idVal;
            if ($idInt > 0) $qIds[] = $idInt;
        }
    } elseif (is_string($raw_qids) && trim($raw_qids) !== '') {
        foreach (explode(',', $raw_qids) as $idVal) {
            $idInt = (int)trim($idVal);
            if ($idInt > 0) $qIds[] = $idInt;
        }
    }

    $qIds = array_values(array_unique($qIds));
    if (empty($qIds)) {
        die("Nenhuma questão selecionada para montar a avaliação.");
    }

    $placeholders = implode(',', array_fill(0, count($qIds), '?'));
    $stmtQ = $pdo->prepare("
        SELECT q.*,
               m.name as module_name,
               co_m.name as module_course_name,
               COALESCE(cc.description, mc.description) as capacity_desc
        FROM questions q
        LEFT JOIN modules m ON q.module_id = m.id
        LEFT JOIN courses co_m ON m.course_id = co_m.id
        LEFT JOIN course_capacities cc ON cc.course_id = m.course_id AND cc.capacity_code = q.capacity
        LEFT JOIN module_capacities mc ON mc.module_id = q.module_id AND mc.capacity_code = q.capacity
        WHERE q.id IN ($placeholders)
    ");
    $stmtQ->execute($qIds);
    $fetchedMap = [];
    foreach ($stmtQ->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fetchedMap[(int)$row['id']] = $row;
    }

    // Preserva exatamente a ordem de seleção definida pelo professor
    foreach ($qIds as $orderedId) {
        if (isset($fetchedMap[$orderedId])) {
            $questions[] = $fetchedMap[$orderedId];
        }
    }
}

if (empty($questions)) {
    die("Nenhuma questão encontrada para gerar a avaliação.");
}

// Busca as alternativas de todas as questões em uma única query
$allQIds = array_column($questions, 'id');
$optionsByQ = [];
if (!empty($allQIds)) {
    $phOpt = implode(',', array_fill(0, count($allQIds), '?'));
    $stmtOpts = $pdo->prepare("SELECT * FROM question_options WHERE question_id IN ($phOpt) ORDER BY id ASC");
    $stmtOpts->execute($allQIds);
    foreach ($stmtOpts->fetchAll(PDO::FETCH_ASSOC) as $optRow) {
        $optionsByQ[(int)$optRow['question_id']][] = $optRow;
    }
}

// Consolida Curso, Unidades Curriculares e Capacidades avaliadas nas questões selecionadas
$detectedModules = [];
$capacitiesList = []; // code => description

foreach ($questions as $qItem) {
    if ($course_name === '' && !empty($qItem['module_course_name'])) {
        $course_name = $qItem['module_course_name'];
    }
    if (!empty($qItem['module_name'])) {
        $detectedModules[$qItem['module_name']] = $qItem['module_name'];
    }
    $capCode = trim((string)($qItem['capacity'] ?? ''));
    if ($capCode !== '') {
        $capDesc = trim((string)($qItem['capacity_desc'] ?? ''));
        if (!isset($capacitiesList[$capCode]) || ($capacitiesList[$capCode] === '' && $capDesc !== '')) {
            $capacitiesList[$capCode] = $capDesc;
        }
    }
}

if ($module_name === '' && !empty($detectedModules)) {
    $module_name = implode(' / ', array_values($detectedModules));
}
if ($exam_title === '') {
    $exam_title = 'AVALIAÇÃO OBJETIVA';
}
if ($state_name === '') {
    $state_name = 'Santa Catarina';
}

// Monta lista de gabarito
$gabarito = [];
$lettersLower = ['a', 'b', 'c', 'd', 'e', 'f'];
$lettersUpper = ['A', 'B', 'C', 'D', 'E', 'F'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($exam_title) ?> - SENAI<?= $course_name !== '' ? ' - ' . htmlspecialchars($course_name) : '' ?></title>
  <style>
    /* ==========================================================================
       VARIÁVEIS DE CORES E TIPOGRAFIA (IDÊNTICO AO TEMPLATE OFICIAL SENAI)
       ========================================================================== */
    :root {
      --senai-blue: #004b8d;         /* Azul institucional das barras */
      --accent-blue: #1a66ff;        /* Azul dos rótulos em itálico e campos preenchidos */
      --border-dark: #111111;        /* Borda principal das tabelas e caixas */
      --border-card: #444444;        /* Borda dos cards de itens */
      --text-main: #111111;          /* Cor padrão do texto */
      --bg-page: #e5e7eb;            /* Fundo cinza na visualização em tela */
      --bg-paper: #ffffff;           /* Fundo branco da folha A4 */

      /* Cores dos badges de dificuldade */
      --badge-facil: #16a34a;
      --badge-medio: #f0a500;
      --badge-dificil: #dc2626;
    }

    /* ==========================================================================
       RESET BÁSICO E CONFIGURAÇÃO DE PÁGINA
       ========================================================================== */
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: "Segoe UI", Calibri, Arial, sans-serif;
      font-size: 13px;
      line-height: 1.45;
      color: var(--text-main);
      background-color: var(--bg-page);
      padding: 24px 16px 48px;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }

    /* Barra superior apenas para visualização em tela (oculta na impressão) */
    .toolbar {
      max-width: 210mm;
      margin: 0 auto 16px;
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
      background-color: #ffffff;
      padding: 12px 16px;
      border-radius: 10px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
    }

    .toolbar-info {
      font-size: 12.5px;
      color: #334155;
      font-weight: 600;
    }

    .toolbar-actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px;
    }

    .btn-print {
      background-color: var(--senai-blue);
      color: #ffffff;
      border: none;
      padding: 8px 16px;
      border-radius: 6px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.12);
      transition: background-color 0.2s ease, transform 0.1s ease;
    }

    .btn-print:hover {
      background-color: #003a6e;
    }

    .btn-print:active {
      transform: scale(0.98);
    }

    .btn-secondary {
      background-color: #f1f5f9;
      color: #334155;
      border: 1px solid #cbd5e1;
      padding: 7px 14px;
      border-radius: 6px;
      font-size: 12.5px;
      font-weight: 600;
      cursor: pointer;
      transition: background-color 0.2s ease;
    }

    .btn-secondary:hover {
      background-color: #e2e8f0;
    }

    /* Container simulando folha A4 na tela */
    .page {
      width: 100%;
      max-width: 210mm;
      min-height: 297mm;
      margin: 0 auto;
      background-color: var(--bg-paper);
      padding: 15mm 16mm;
      box-shadow: 0 6px 24px rgba(0, 0, 0, 0.12);
    }

    /* ==========================================================================
       CABEÇALHO INSTITUCIONAL (TABELA PRINCIPAL)
       ========================================================================== */
    .header-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 14px;
      border: 1.2px solid var(--border-dark);
    }

    .header-table td {
      border: 1px solid var(--border-dark);
      padding: 5px 8px;
      vertical-align: middle;
    }

    /* Coluna da Logo (Esquerda) */
    .header-logo-cell {
      width: 22%;
      text-align: center;
      padding: 10px 8px !important;
    }

    .header-logo-cell img {
      max-width: 115px;
      height: auto;
      display: block;
      margin: 0 auto 6px;
    }

    .instituicao-nome {
      font-size: 10.5px;
      font-weight: 700;
      line-height: 1.25;
      color: var(--text-main);
    }

    .instituicao-estado {
      font-size: 10px;
      font-weight: 400;
      margin-top: 2px;
      color: var(--text-main);
    }

    /* Coluna Central (Dados da Prova) */
    .header-title-cell {
      width: 62%;
      text-align: center;
      font-size: 14.5px;
      font-weight: 700;
      letter-spacing: 0.3px;
      padding: 9px 8px !important;
    }

    .header-field-label {
      font-weight: 700;
      color: var(--text-main);
      margin-right: 4px;
    }

    .header-field-value {
      color: var(--accent-blue);
      font-style: italic;
      font-weight: 400;
    }

    .header-field-value.bold {
      font-weight: 700;
    }

    /* Coluna da Direita (Desempenho) */
    .header-score-cell {
      width: 16%;
      text-align: center;
      font-weight: 700;
      font-size: 12.5px;
    }

    /* ==========================================================================
       SEÇÃO DE CAPACIDADES
       ========================================================================== */
    .capacidades-box {
      border: 1.2px solid var(--border-dark);
      margin-bottom: 18px;
    }

    .section-bar {
      background-color: var(--senai-blue);
      color: #ffffff;
      font-weight: 700;
      font-size: 11.5px;
      text-transform: uppercase;
      padding: 5px 10px;
      letter-spacing: 0.3px;
    }

    .capacidades-list {
      list-style: none;
      padding: 10px 12px;
      font-size: 11.5px;
      line-height: 1.55;
    }

    .capacidades-list li strong {
      font-weight: 700;
    }

    /* ==========================================================================
       CARDS DE QUESTÕES (ITENS)
       ========================================================================== */
    .item-card {
      border: 1.2px solid var(--border-card);
      margin-bottom: 18px;
      background-color: #ffffff;
      /* Evita que uma questão seja cortada no meio ao imprimir */
      break-inside: avoid;
      page-break-inside: avoid;
    }

    .item-header {
      background-color: var(--senai-blue);
      color: #ffffff;
      padding: 6px 12px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .item-title {
      font-size: 12.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.3px;
    }

    /* Badges de Nível de Dificuldade */
    .badge {
      display: inline-block;
      padding: 2px 10px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: 700;
      color: #ffffff;
      line-height: 1.3;
      min-width: 52px;
      text-align: center;
    }

    .badge-facil {
      background-color: var(--badge-facil);
    }

    .badge-medio {
      background-color: var(--badge-medio);
    }

    .badge-dificil {
      background-color: var(--badge-dificil);
    }

    /* Corpo da Questão */
    .item-body {
      padding: 14px 16px 16px;
      font-size: 12.5px;
    }

    .item-block {
      margin-bottom: 10px;
      white-space: pre-line;
    }

    .item-block:last-child {
      margin-bottom: 0;
    }

    .item-image-wrap {
      margin: 10px 0 12px;
      text-align: center;
    }

    .item-image-wrap img {
      max-width: 100%;
      max-height: 310px;
      height: auto;
      object-fit: contain;
      border: 1px solid #d1d5db;
      border-radius: 4px;
      padding: 4px;
      background: #ffffff;
    }

    .label-blue {
      color: var(--accent-blue);
      font-weight: 700;
      font-style: italic;
    }

    /* Lista de Alternativas */
    .alternativas-heading {
      display: block;
      margin-top: 12px;
      margin-bottom: 6px;
    }

    .alternativas-list {
      list-style: none;
      padding-left: 0;
    }

    .alternativas-list li {
      font-style: italic;
      margin-bottom: 6px;
      line-height: 1.45;
      white-space: pre-line;
    }

    .alternativas-list li:last-child {
      margin-bottom: 0;
    }

    .opt-image-wrap {
      margin: 6px 0 10px 18px;
    }

    .opt-image-wrap img {
      max-width: 340px;
      max-height: 200px;
      height: auto;
      object-fit: contain;
      border: 1px solid #d1d5db;
      border-radius: 4px;
      padding: 3px;
      background: #ffffff;
    }

    /* Folha de Gabarito do Docente (Opcional ao Final) */
    .gabarito-section {
      page-break-before: always;
      break-before: page;
      margin-top: 28px;
      padding-top: 10px;
    }

    .gabarito-table {
      width: 100%;
      border-collapse: collapse;
      border: 1.2px solid var(--border-dark);
      font-size: 12px;
    }

    .gabarito-table th {
      background-color: var(--senai-blue);
      color: #ffffff;
      border: 1px solid var(--border-dark);
      padding: 6px 10px;
      text-align: center;
      font-weight: 700;
      text-transform: uppercase;
      font-size: 11px;
    }

    .gabarito-table td {
      border: 1px solid var(--border-dark);
      padding: 6px 10px;
      text-align: center;
    }

    .hidden-section {
      display: none !important;
    }

    /* ==========================================================================
       ESTILOS DE IMPRESSÃO (A4)
       ========================================================================== */
    @page {
      size: A4 portrait;
      margin: 12mm 14mm;
    }

    @media print {
      body {
        background-color: #ffffff;
        padding: 0;
      }

      .toolbar {
        display: none !important;
      }

      .page {
        max-width: 100%;
        min-height: auto;
        margin: 0;
        padding: 0;
        box-shadow: none;
      }
    }
  </style>
</head>
<body>

  <!-- Barra de ação visível apenas na tela -->
  <div class="toolbar">
    <div class="toolbar-info">
      Avaliação pronta com <strong><?= count($questions) ?> item(ns)</strong> e <strong><?= count($capacitiesList) ?> capacidade(s)</strong>
    </div>
    <div class="toolbar-actions">
      <button type="button" id="btn-toggle-gabarito" class="btn-secondary" onclick="toggleGabaritoPage()">
        <?= $include_gabarito ? '✓ Com Folha de Gabarito no Final (Clique para ocultar)' : '+ Incluir Folha de Gabarito no Final' ?>
      </button>
      <button type="button" class="btn-print" onclick="window.print()">
        Imprimir / Salvar PDF
      </button>
    </div>
  </div>

  <!-- Folha da Avaliação -->
  <main class="page">

    <!-- ==================== CABEÇALHO ==================== -->
    <table class="header-table">
      <tbody>
        <tr>
          <td rowspan="7" class="header-logo-cell">
            <img
              src="https://fiesc.com.br/sites/default/files/styles/large/public/2026-02/marca-senai-_reduzida_cor.png?itok=lZKkNM26"
              alt="Logo SENAI"
              onerror="if(this.src.indexOf('logo.png')===-1){this.src='logo.png';}"
            />
            <div class="instituicao-nome">
              Serviço Nacional de<br />
              Aprendizagem<br />
              Industrial
            </div>
            <div class="instituicao-estado"><?= htmlspecialchars($state_name) ?></div>
          </td>
          <td class="header-title-cell"><?= htmlspecialchars($exam_title) ?></td>
          <td rowspan="7" class="header-score-cell">Desempenho</td>
        </tr>
        <tr>
          <td>
            <span class="header-field-label">Data:</span>
            <span class="header-field-value"><?= htmlspecialchars($exam_date) ?></span>
          </td>
        </tr>
        <tr>
          <td>
            <span class="header-field-label">Docente:</span>
            <span class="header-field-value"><?= htmlspecialchars($teacher_name) ?></span>
          </td>
        </tr>
        <tr>
          <td>
            <span class="header-field-label">Curso Técnico em:</span>
            <span class="header-field-value bold"><?= htmlspecialchars($course_name) ?></span>
          </td>
        </tr>
        <tr>
          <td>
            <span class="header-field-label">Unidade Curricular:</span>
            <span class="header-field-value bold"><?= htmlspecialchars($module_name) ?></span>
          </td>
        </tr>
        <tr>
          <td>
            <span class="header-field-label">Turma:</span>
            <span class="header-field-value bold"><?= htmlspecialchars($class_name) ?></span>
          </td>
        </tr>
        <tr>
          <td>
            <span class="header-field-label">Estudante:</span>
            <span class="header-field-value"></span>
          </td>
        </tr>
      </tbody>
    </table>

    <!-- ==================== CAPACIDADES ==================== -->
    <?php if (!empty($capacitiesList)): ?>
    <section class="capacidades-box">
      <div class="section-bar">CAPACIDADES</div>
      <ul class="capacidades-list">
        <?php foreach ($capacitiesList as $cCode => $cDesc): ?>
          <li>
            <strong><?= htmlspecialchars($cCode) ?>:</strong>
            <?= htmlspecialchars($cDesc !== '' ? $cDesc : 'Capacidade técnica avaliada nesta unidade curricular.') ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <!-- ==================== ITENS ==================== -->
    <?php foreach ($questions as $idx => $q):
        $itemNum = $idx + 1;
        $diff = trim((string)($q['difficulty'] ?: 'Médio'));
        $badgeClass = 'badge-medio';
        if (stripos($diff, 'Fácil') !== false || stripos($diff, 'Facil') !== false) {
            $badgeClass = 'badge-facil';
        } elseif (stripos($diff, 'Difícil') !== false || stripos($diff, 'Dificil') !== false) {
            $badgeClass = 'badge-dificil';
        }

        $contextText = trim((string)($q['context'] ?? ($q['base_text'] ?? '')));
        $commandText = trim((string)($q['command'] ?? ''));
        $stmtImage   = trim((string)($q['image_url'] ?? ''));
        $opts        = $optionsByQ[(int)$q['id']] ?? [];
        $correctLetter = '-';
    ?>
    <article class="item-card">
      <header class="item-header">
        <span class="item-title">ITEM <?= $itemNum ?></span>
        <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($diff) ?></span>
      </header>
      <div class="item-body">
        <p class="item-block"><span class="label-blue">CAPACIDADE:</span> <?= htmlspecialchars($q['capacity'] ?: '-') ?></p>

        <?php if ($contextText !== ''): ?>
        <p class="item-block"><span class="label-blue">Contexto:</span> <?= htmlspecialchars($contextText) ?></p>
        <?php endif; ?>

        <?php if ($stmtImage !== ''): ?>
        <div class="item-image-wrap">
          <img src="<?= htmlspecialchars($stmtImage) ?>" alt="Figura do Item <?= $itemNum ?>" />
        </div>
        <?php endif; ?>

        <?php if ($commandText !== ''): ?>
        <p class="item-block"><span class="label-blue">Comando:</span> <?= htmlspecialchars($commandText) ?></p>
        <?php endif; ?>

        <span class="label-blue alternativas-heading">Alternativas:</span>
        <ul class="alternativas-list">
          <?php foreach ($opts as $optIdx => $opt):
              $letLower = $lettersLower[$optIdx] ?? '?';
              $letUpper = $lettersUpper[$optIdx] ?? '?';
              if ((int)($opt['is_correct'] ?? 0) === 1) {
                  $correctLetter = $letUpper;
              }
              $optText = trim((string)($opt['text'] ?? ''));
              $optImg  = trim((string)($opt['image_url'] ?? ''));
          ?>
            <li><?= $letLower ?>) <?= htmlspecialchars($optText) ?><?php if ($optImg !== ''): ?><div class="opt-image-wrap"><img src="<?= htmlspecialchars($optImg) ?>" alt="Alternativa <?= $letUpper ?>" /></div><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </article>
    <?php
        $capFull = $q['capacity'] ?: '-';
        if (!empty($q['capacity_desc'])) {
            $capFull .= ' — ' . $q['capacity_desc'];
        }
        $gabarito[] = [
            'num'     => $itemNum,
            'cap'     => $capFull,
            'diff'    => $diff,
            'correct' => $correctLetter
        ];
    endforeach; ?>

    <!-- ==================== FOLHA DE GABARITO DO DOCENTE (OPCIONAL) ==================== -->
    <section id="gabarito-section" class="gabarito-section <?= $include_gabarito ? '' : 'hidden-section' ?>">
      <div class="capacidades-box" style="margin-bottom: 12px;">
        <div class="section-bar" style="text-align: center;">GABARITO OFICIAL DO DOCENTE — <?= htmlspecialchars($exam_title) ?></div>
      </div>
      <table class="gabarito-table">
        <thead>
          <tr>
            <th style="width: 12%;">Item</th>
            <th style="width: 60%;">Capacidade Avaliada</th>
            <th style="width: 14%;">Dificuldade</th>
            <th style="width: 14%;">Gabarito</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($gabarito as $g): ?>
          <tr>
            <td><strong>ITEM <?= $g['num'] ?></strong></td>
            <td style="text-align: left;"><?= htmlspecialchars($g['cap']) ?></td>
            <td><?= htmlspecialchars($g['diff']) ?></td>
            <td><strong><?= htmlspecialchars($g['correct']) ?></strong></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

  </main>

  <script>
    let showGabarito = <?= $include_gabarito ? 'true' : 'false' ?>;
    function toggleGabaritoPage() {
      showGabarito = !showGabarito;
      const sec = document.getElementById('gabarito-section');
      const btn = document.getElementById('btn-toggle-gabarito');
      if (showGabarito) {
        sec.classList.remove('hidden-section');
        btn.textContent = '✓ Com Folha de Gabarito no Final (Clique para ocultar)';
      } else {
        sec.classList.add('hidden-section');
        btn.textContent = '+ Incluir Folha de Gabarito no Final';
      }
    }

    <?php if ($auto_print): ?>
    window.addEventListener('load', () => {
      setTimeout(() => {
        window.print();
      }, 400);
    });
    <?php endif; ?>
  </script>
</body>
</html>
