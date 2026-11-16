<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

if (!isset($_GET['course_id'])) {
    header("Location: questions.php");
    exit;
}
$courseId = $_GET['course_id'];

$stmtCourse = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
$stmtCourse->execute([$courseId]);
$curso = $stmtCourse->fetch();

if (!$curso) {
    header("Location: questions.php");
    exit;
}

// Exclusão Lógica de Questão
if (isset($_GET['delete_q']) && in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    $delId = $_GET['delete_q'];
    // Soft delete para não quebrar sprints passadas
    $stmtDel = $pdo->prepare("UPDATE questions SET is_active = 0 WHERE id = ?");
    $stmtDel->execute([$delId]);
    header("Location: questions_view.php?course_id=" . $courseId);
    exit;
}

// Garante tabela course_capacities e busca a Matriz Oficial de Capacidades deste curso
try {
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
} catch (Exception $e) {}

$stmtCourseCaps = $pdo->prepare("
    SELECT capacity_code, description
    FROM course_capacities
    WHERE course_id = ?
    ORDER BY CAST(SUBSTRING(capacity_code, 2) AS UNSIGNED) ASC, capacity_code ASC
");
$stmtCourseCaps->execute([$courseId]);
$courseCapacities = $stmtCourseCaps->fetchAll();

// Se course_capacities estiver vazia mas module_capacities já tiver dados antigos deste curso, hidrata
if (empty($courseCapacities)) {
    $stmtLegacyCaps = $pdo->prepare("
        SELECT DISTINCT mc.capacity_code, mc.description
        FROM module_capacities mc
        JOIN modules m ON mc.module_id = m.id
        WHERE m.course_id = ? AND mc.capacity_code != '' AND mc.description != ''
        ORDER BY LENGTH(mc.capacity_code), mc.capacity_code
    ");
    $stmtLegacyCaps->execute([$courseId]);
    $legacyCaps = $stmtLegacyCaps->fetchAll();
    if (!empty($legacyCaps)) {
        $stmtInsCC = $pdo->prepare("INSERT IGNORE INTO course_capacities (course_id, capacity_code, description) VALUES (?, ?, ?)");
        foreach ($legacyCaps as $lc) {
            $stmtInsCC->execute([$courseId, trim($lc['capacity_code']), trim($lc['description'])]);
        }
        $stmtCourseCaps->execute([$courseId]);
        $courseCapacities = $stmtCourseCaps->fetchAll();
    }
}

$officialCapMap = [];
foreach ($courseCapacities as $cc) {
    $officialCapMap[$cc['capacity_code']] = $cc['description'];
}

// Busca todos os módulos do curso (mesmo os que ainda não possuem questões)
$stmtAllCourseMods = $pdo->prepare("SELECT id, name FROM modules WHERE course_id = ? ORDER BY name");
$stmtAllCourseMods->execute([$courseId]);
$allCourseModules = $stmtAllCourseMods->fetchAll();

// Buscar todas as questões ativas deste curso com o nome do módulo
$stmtQs = $pdo->prepare("
    SELECT q.*, m.name as module_name 
    FROM questions q 
    JOIN modules m ON q.module_id = m.id 
    WHERE m.course_id = ? AND (q.is_active = 1 OR q.is_active IS NULL)
    ORDER BY m.name, q.id
");
$stmtQs->execute([$courseId]);
$questoes = $stmtQs->fetchAll();

$modulos_unicos = [];
$capacidades_unicas = [];
$questoes_json = [];

foreach ($officialCapMap as $cCode => $cDesc) {
    $capacidades_unicas[$cCode] = $cCode;
}

foreach ($questoes as &$q) {
    $modulos_unicos[$q['module_id']] = $q['module_name'];
    if (!empty($q['capacity'])) {
        $capacidades_unicas[$q['capacity']] = $q['capacity'];
    }

    // Buscar alternativas (incluindo ID para edição segura sem quebrar respostas históricas)
    $stmtOpt = $pdo->prepare("SELECT id, text, is_correct FROM question_options WHERE question_id = ? ORDER BY id ASC");
    $stmtOpt->execute([$q['id']]);
    $q['options'] = $stmtOpt->fetchAll();

    $questoes_json[$q['id']] = $q;
}
unset($q);

asort($modulos_unicos);
uksort($capacidades_unicas, 'strnatcasecmp');

include 'includes/header.php';
?>

<!-- ==================== APLICAÇÃO PRINCIPAL ==================== -->
<div id="app-screen" class="absolute inset-0 z-40 flex w-full h-full">
    
    <!-- Fundo Vivo -->
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0 bg-[#F8FAFC]">
        <div id="blob1" class="absolute top-[-5%] right-[-5%] w-[500px] h-[500px] bg-senai-cyan/15 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob"></div>
    </div>

    <?php
    $currentPage = 'questions';
    include 'includes/sidebar.php'; 
    ?>

    <!-- Main Content -->
    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        
        <header class="h-14 flex items-center justify-between px-4 md:px-6 absolute top-0 w-full z-20 pointer-events-none">
            <button onclick="toggleSidebar()" class="pointer-events-auto w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl backdrop-blur-md shadow-sm border border-white/60 transition-all active:scale-95 group text-senai-dark">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl"></i>
            </button>
            <div class="flex-1"></div>
            <a href="questions.php" class="pointer-events-auto text-[11px] font-bold text-slate-600 hover:text-senai-blue flex items-center gap-1.5 bg-white/70 backdrop-blur-xl border border-white/80 shadow-sm px-4 py-2 rounded-full hover:shadow transition-all duration-300 active:scale-95">
                <i class="ph-fill ph-arrow-left text-slate-400"></i> Voltar aos Cursos
            </a>
        </header>

        <!-- Area de Scroll -->
        <div id="content-scroll-area" class="flex-1 overflow-y-auto no-scrollbar w-full flex flex-col items-center pt-24 pb-20 relative z-10 px-4 md:px-8">
            
            <div class="w-full max-w-5xl">
                <div class="flex flex-col md:flex-row md:items-center justify-between mb-6 animate-slide-up">
                    <div>
                        <div class="flex items-center gap-2 text-senai-cyan font-bold text-xs uppercase tracking-wider mb-2">
                            <i class="ph-fill ph-books"></i> Curso Selecionado
                        </div>
                        <h1 class="text-3xl font-bold tracking-tight mb-2 text-slate-800 flex items-center gap-3">
                            <?= htmlspecialchars($curso['name']) ?>
                            <button onclick="openHelpModal('Questões do Curso', 'Visualize e gerencie o acervo completo de questões deste curso.<br><br><b>Ações disponíveis:</b><br>- Clique no ícone de <b>Lápis (Editar)</b> para vincular a <b>Capacidade Oficial da Matriz SAEP</b> ou editar o <b>Contexto</b>, <b>Comando</b>, <b>Módulo</b> e <b>Alternativas</b>.<br>- Gerencie a Matriz Curricular (.xls) do curso no painel acima dos filtros.')" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-senai-blue hover:bg-blue-50 transition-colors shadow-inner" title="Como Usar">
                                <i class="ph-bold ph-question text-lg"></i>
                            </button>
                        </h1>
                        <p class="text-slate-500 font-medium">
                            Explore, edite a estrutura/contexto das questões e vincule capacidades oficiais da Matriz SAEP.
                        </p>
                    </div>
                    <?php if (in_array($_SESSION['user_role'], ['admin', 'teacher'])): ?>
                    <div class="mt-4 md:mt-0 flex flex-wrap items-center gap-2.5">
                        <input type="file" id="course-view-matrix-input" accept=".xls,.xlsx" class="hidden" onchange="uploadCourseMatrixFromView(this)">
                        <button type="button" onclick="document.getElementById('course-view-matrix-input').click()" id="btn-upload-matrix-view" class="bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200 px-4 py-2.5 rounded-xl font-bold text-sm transition-all flex items-center gap-2">
                            <i class="ph-bold ph-file-xls"></i> <?= !empty($courseCapacities) ? 'Atualizar Matriz (.xls)' : 'Vincular Matriz SAEP (.xls)' ?>
                        </button>
                        <a href="question_create.php?course_id=<?= $courseId ?>" class="bg-senai-blue text-white px-5 py-2.5 rounded-xl font-semibold shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all flex items-center gap-2 text-sm">
                            <i class="ph-bold ph-plus"></i> Nova Questão
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Painel da Matriz Curricular SAEP do Curso -->
                <?php if (!empty($courseCapacities)): ?>
                <div class="bg-emerald-50/80 border border-emerald-200 rounded-2xl p-4 mb-6 shadow-sm">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <div class="flex items-center gap-2.5">
                            <span class="w-8 h-8 rounded-xl bg-emerald-600 text-white flex items-center justify-center shrink-0">
                                <i class="ph-bold ph-seal-check text-lg"></i>
                            </span>
                            <div>
                                <h4 class="text-xs md:text-sm font-extrabold text-emerald-950">
                                    Matriz Oficial SAEP Vinculada — <?= count($courseCapacities) ?> Capacidades Invioláveis (C1 a C<?= count($courseCapacities) ?>)
                                </h4>
                                <p class="text-[11px] text-emerald-800">
                                    Clique em <b>Editar Questão (ícone de lápis)</b> em qualquer card abaixo para revisar ou alterar a Capacidade Oficial, Contexto ou Comando.
                                </p>
                            </div>
                        </div>
                    </div>
                    <details class="mt-2.5 bg-white/90 border border-emerald-200/80 rounded-xl px-3.5 py-2 text-xs">
                        <summary class="font-bold text-emerald-900 cursor-pointer select-none">
                            Ver todas as Capacidades Oficiais (C1 a C<?= count($courseCapacities) ?>) desta Matriz
                        </summary>
                        <div class="mt-2.5 grid grid-cols-1 md:grid-cols-2 gap-2 max-h-48 overflow-y-auto pr-1">
                            <?php foreach ($courseCapacities as $cc): ?>
                                <div class="p-2 rounded-lg bg-emerald-50/50 border border-emerald-100 text-[11px] text-slate-700 leading-relaxed">
                                    <b class="text-emerald-900"><?= htmlspecialchars($cc['capacity_code']) ?>:</b> <?= htmlspecialchars($cc['description']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </div>
                <?php else: ?>
                <div class="bg-amber-50 border border-amber-300 rounded-2xl p-4 mb-6 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <i class="ph-fill ph-warning-circle text-2xl text-amber-600 shrink-0 mt-0.5"></i>
                        <div>
                            <h4 class="text-sm font-extrabold text-amber-950">Este curso ainda não possui a Matriz Curricular SAEP (.xls) vinculada!</h4>
                            <p class="text-xs text-amber-800 mt-0.5 leading-relaxed">
                                Envie o arquivo <code>.xls</code> da Matriz SAEP deste curso para cadastrar automaticamente as capacidades oficiais invioláveis (<b>C1, C2, C3...</b> das células <b>C7, D7, E7...</b>) e vinculá-las às questões já importadas.
                            </p>
                        </div>
                    </div>
                    <button type="button" onclick="document.getElementById('course-view-matrix-input').click()" class="px-4 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs shrink-0 flex items-center gap-1.5 shadow-sm self-start sm:self-center">
                        <i class="ph-bold ph-file-xls text-base"></i> Carregar Matriz (.xls) Agora
                    </button>
                </div>
                <?php endif; ?>

                <!-- Barra de Filtros -->
                <div class="bg-white/80 backdrop-blur-md border border-slate-200 rounded-2xl p-4 shadow-sm mb-6 animate-fade-in sticky top-0 z-30">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Módulo/Unidade</label>
                            <select id="filter-module" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-lg px-3 py-2 focus:ring-1 focus:ring-senai-blue outline-none transition-all">
                                <option value="all">Todos os módulos</option>
                                <?php foreach($modulos_unicos as $id => $mName): ?>
                                    <option value="<?= $id ?>"><?= htmlspecialchars($mName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Capacidade da Matriz</label>
                            <select id="filter-capacity" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-lg px-3 py-2 focus:ring-1 focus:ring-senai-blue outline-none transition-all">
                                <option value="all">Todas as capacidades</option>
                                <?php foreach($capacidades_unicas as $cap): 
                                    $capTitle = isset($officialCapMap[$cap]) ? ($cap . ' — ' . mb_substr($officialCapMap[$cap], 0, 55) . '...') : $cap;
                                ?>
                                    <option value="<?= htmlspecialchars($cap) ?>"><?= htmlspecialchars($capTitle) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Dificuldade</label>
                            <select id="filter-difficulty" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-lg px-3 py-2 focus:ring-1 focus:ring-senai-blue outline-none transition-all">
                                <option value="all">Todas as dificuldades</option>
                                <option value="Fácil">Fácil</option>
                                <option value="Médio">Médio</option>
                                <option value="Difícil">Difícil</option>
                            </select>
                        </div>
                    </div>
                </div>

                <?php if (empty($questoes)): ?>
                    <div class="text-center text-slate-500 py-10">
                        <i class="ph-thin ph-folder-dashed text-4xl mb-3 opacity-50"></i>
                        <p class="font-medium">Nenhuma questão encontrada para este curso.</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="questions-grid">
                        <?php foreach ($questoes as $q): 
                            $diffColor = 'bg-slate-100 text-slate-600';
                            if ($q['difficulty'] === 'Fácil') $diffColor = 'bg-green-100 text-green-700';
                            if ($q['difficulty'] === 'Médio') $diffColor = 'bg-yellow-100 text-yellow-700';
                            if ($q['difficulty'] === 'Difícil') $diffColor = 'bg-red-100 text-red-700';

                            $isCapOfficial = !empty($q['capacity']) && isset($officialCapMap[$q['capacity']]);
                            $capTooltip = $isCapOfficial ? ($q['capacity'] . ': ' . $officialCapMap[$q['capacity']]) : 'Clique no lápis para vincular uma capacidade oficial da Matriz SAEP';
                        ?>
                            <div id="q-card-<?= $q['id'] ?>" class="question-card bg-white border border-slate-200 rounded-xl p-5 shadow-sm hover:shadow-md hover:border-senai-cyan/40 transition-all group relative flex flex-col cursor-pointer"
                                 data-module="<?= $q['module_id'] ?>"
                                 data-capacity="<?= htmlspecialchars($q['capacity']) ?>"
                                 data-difficulty="<?= htmlspecialchars($q['difficulty']) ?>"
                                 onclick="showQuestionDetails(<?= $q['id'] ?>)">
                                
                                <div class="flex justify-between items-start mb-3">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <span id="card-mod-<?= $q['id'] ?>" class="text-[10px] font-bold px-2 py-1 rounded-md bg-slate-100 text-slate-600 uppercase tracking-wider truncate max-w-[140px]" title="<?= htmlspecialchars($q['module_name']) ?>">
                                            <?= htmlspecialchars($q['module_name']) ?>
                                        </span>
                                        <span id="badge-img-<?= $q['id'] ?>" class="<?= !empty($q['image_url']) ? '' : 'hidden' ?> text-[10px] font-bold px-2 py-0.5 rounded-md bg-indigo-50 text-indigo-700 border border-indigo-200 flex items-center gap-1" title="Questão com Imagem de Apoio">
                                            <i class="ph-bold ph-image"></i> Imagem
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <?php if (in_array($_SESSION['user_role'], ['admin', 'teacher'])): ?>
                                        <button type="button" onclick="event.stopPropagation(); openEditQuestionModal(<?= $q['id'] ?>)" class="w-7 h-7 rounded-full bg-emerald-50 hover:bg-emerald-100 text-emerald-700 flex items-center justify-center transition-colors" title="Editar Questão, Contexto e Capacidade da Matriz">
                                            <i class="ph-bold ph-pencil-simple"></i>
                                        </button>
                                        <button type="button" onclick="event.stopPropagation(); openManageImageModal(<?= $q['id'] ?>)" class="w-7 h-7 rounded-full bg-indigo-50 hover:bg-indigo-100 text-indigo-600 flex items-center justify-center transition-colors" title="Adicionar / Alterar Imagem">
                                            <i class="ph-bold ph-image"></i>
                                        </button>
                                        <a href="?course_id=<?= $courseId ?>&delete_q=<?= $q['id'] ?>" onclick="event.stopPropagation(); return confirm('Tem certeza que deseja excluir/ocultar esta questão? Ela não aparecerá mais para ser selecionada em novas provas.');" class="w-7 h-7 rounded-full bg-red-50 hover:bg-red-100 text-red-400 hover:text-red-600 flex items-center justify-center transition-colors" title="Excluir Questão">
                                            <i class="ph-bold ph-trash"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <p id="card-cmd-<?= $q['id'] ?>" class="text-sm font-semibold text-slate-800 mb-4 line-clamp-4 flex-grow" title="<?= htmlspecialchars($q['command']) ?>">
                                    <?= htmlspecialchars($q['command']) ?>
                                </p>
                                
                                <div class="pt-3 border-t border-slate-100 flex justify-between items-center mt-auto">
                                    <div class="flex items-center gap-1 flex-wrap">
                                        <span id="card-cap-<?= $q['id'] ?>" title="<?= htmlspecialchars($capTooltip) ?>" class="text-[10px] font-bold px-2 py-1 rounded-md <?= $isCapOfficial ? 'bg-senai-cyan/10 text-senai-cyan' : 'bg-amber-100 text-amber-800 border border-amber-300' ?> uppercase tracking-wider">
                                            <?= !empty($q['capacity']) ? 'CAP: ' . htmlspecialchars($q['capacity']) : '⚠️ Sem Capacidade' ?>
                                        </span>
                                        <span id="card-diff-<?= $q['id'] ?>" class="text-[10px] font-bold px-2 py-1 rounded-md <?= $diffColor ?> uppercase tracking-wider">
                                            <?= htmlspecialchars($q['difficulty']) ?>
                                        </span>
                                    </div>
                                    <span class="text-senai-blue hover:text-senai-orange text-sm font-bold flex items-center gap-1 transition-colors">
                                        Detalhes <i class="ph-bold ph-arrow-right"></i>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div id="no-results" class="hidden text-center py-10 bg-white/50 backdrop-blur-sm rounded-xl border border-slate-200 mt-4">
                        <i class="ph-thin ph-magnifying-glass text-4xl text-slate-400 mb-2"></i>
                        <p class="text-slate-500 font-medium">Nenhuma questão corresponde aos filtros aplicados.</p>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </main>
</div>

<!-- Modal Detalhes da Questão -->
<div id="detailsModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl overflow-hidden scale-95 opacity-0 transition-all duration-300 flex flex-col max-h-[90vh]" id="detailsModalContent">
        
        <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/80 shrink-0">
            <h3 class="font-bold text-lg text-slate-800 flex items-center gap-2">
                <i class="ph-bold ph-magnifying-glass-plus text-senai-blue"></i> Detalhes da Questão
            </h3>
            <div class="flex items-center gap-2">
                <?php if (in_array($_SESSION['user_role'], ['admin', 'teacher'])): ?>
                <button type="button" onclick="openEditQuestionModal(currentDetailsQuestionId)" class="px-3 py-1.5 rounded-xl bg-emerald-50 text-emerald-700 hover:bg-emerald-100 text-xs font-bold transition-all flex items-center gap-1.5 border border-emerald-200 shadow-sm active:scale-95">
                    <i class="ph-bold ph-pencil-simple"></i> Editar Questão / Capacidade
                </button>
                <button type="button" onclick="openManageImageModal(currentDetailsQuestionId)" class="px-3 py-1.5 rounded-xl bg-indigo-50 text-indigo-700 hover:bg-indigo-100 text-xs font-bold transition-all flex items-center gap-1.5 border border-indigo-200 shadow-sm active:scale-95">
                    <i class="ph-bold ph-image"></i> <span id="modal-manage-img-btn-text">Gerenciar Imagem</span>
                </button>
                <?php endif; ?>
                <button onclick="closeDetailsModal()" class="text-slate-400 hover:text-red-500 transition-colors bg-white rounded-full p-1 shadow-sm border border-slate-200">
                    <i class="ph-bold ph-x text-lg"></i>
                </button>
            </div>
        </div>
        
        <div class="p-6 overflow-y-auto flex-1 bg-white">
            <div class="flex flex-wrap gap-2 mb-3" id="modal-badges">
                <!-- Badges injetadas via JS -->
            </div>

            <!-- Descrição Oficial Inviolável da Capacidade -->
            <div id="modal-official-cap-box" class="mb-4 p-3 rounded-xl bg-emerald-50/70 border border-emerald-200 text-xs text-emerald-950 hidden">
                <span class="font-extrabold uppercase tracking-wider text-[10px] text-emerald-700 block mb-0.5">
                    <i class="ph-fill ph-lock-key"></i> Capacidade Oficial da Matriz SAEP
                </span>
                <p id="modal-official-cap-text" class="font-medium leading-relaxed"></p>
            </div>
            
            <div class="mb-5 bg-slate-50 p-4 rounded-xl border border-slate-200">
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Contexto / Texto-Base</h4>
                <p class="text-sm text-slate-700 whitespace-pre-line" id="modal-context"></p>
            </div>

            <!-- Imagem de Apoio (se houver) -->
            <div id="modal-image-container" class="mb-5 bg-slate-50/80 p-4 rounded-2xl border border-slate-200 text-center hidden">
                <div class="flex items-center justify-between text-xs text-slate-500 font-bold mb-2 px-1">
                    <span class="flex items-center gap-1.5 text-indigo-700">
                        <i class="ph-bold ph-image text-sm"></i> Figura / Imagem de Apoio
                    </span>
                    <button type="button" onclick="openLightbox(document.getElementById('modal-image-preview').src)" class="text-senai-blue hover:underline flex items-center gap-1 text-[11px] font-bold">
                        <i class="ph-bold ph-magnifying-glass-plus"></i> Ampliar
                    </button>
                </div>
                <img id="modal-image-preview" src="#" alt="Imagem de apoio" onclick="openLightbox(this.src)" class="max-h-72 mx-auto rounded-xl border border-slate-200 shadow-sm object-contain bg-white cursor-zoom-in hover:shadow-md transition-all">
            </div>
            
            <div class="mb-6">
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Comando da Questão</h4>
                <p class="text-base font-semibold text-slate-900 whitespace-pre-line" id="modal-command"></p>
            </div>
            
            <div>
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3">Alternativas</h4>
                <div class="space-y-2" id="modal-options">
                    <!-- Alternativas injetadas via JS -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Editar Questão Completa (Capacidade da Matriz, Contexto, Comando, Módulo e Alternativas) -->
<div id="editQuestionModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[60] hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl overflow-hidden scale-95 opacity-0 transition-all duration-300 flex flex-col max-h-[92vh]" id="editQuestionModalContent">
        <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/80 shrink-0">
            <div>
                <h3 class="font-bold text-lg text-slate-800 flex items-center gap-2">
                    <i class="ph-bold ph-pencil-simple text-emerald-600"></i> Editar Estrutura e Capacidade da Questão
                </h3>
                <p class="text-xs text-slate-500">Vincule a Capacidade Oficial da Matriz SAEP e ajuste o Contexto, Comando ou Alternativas.</p>
            </div>
            <button onclick="closeEditQuestionModal()" class="text-slate-400 hover:text-red-500 transition-colors bg-white rounded-full p-1 shadow-sm border border-slate-200">
                <i class="ph-bold ph-x text-lg"></i>
            </button>
        </div>

        <form id="editQuestionForm" onsubmit="submitEditQuestion(event)" class="p-6 overflow-y-auto flex-1 space-y-4">
            <input type="hidden" id="edit_q_id" value="">

            <!-- 1. Capacidade Oficial da Matriz (Inviolável) -->
            <div class="p-3.5 rounded-2xl bg-emerald-50/70 border border-emerald-200">
                <label class="block text-xs font-extrabold text-emerald-950 uppercase tracking-wider mb-1.5 flex items-center gap-1.5">
                    <i class="ph-fill ph-lock-key text-emerald-600"></i> Capacidade Oficial da Matriz SAEP <span class="text-red-500">*</span>
                </label>
                <?php if (!empty($courseCapacities)): ?>
                    <select id="edit_q_capacity" required onchange="updateEditCapDescPreview(this.value)" class="w-full px-3.5 py-2.5 bg-white border border-emerald-300 rounded-xl text-xs md:text-sm text-slate-800 font-bold outline-none focus:ring-2 focus:ring-emerald-500">
                        <option value="" disabled>Selecione uma capacidade oficial da Matriz...</option>
                        <?php foreach ($courseCapacities as $cc): 
                            $shortD = mb_strlen($cc['description']) > 95 ? mb_substr($cc['description'], 0, 95) . '...' : $cc['description'];
                        ?>
                            <option value="<?= htmlspecialchars($cc['capacity_code']) ?>">
                                <?= htmlspecialchars($cc['capacity_code'] . ' — ' . $shortD) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="edit_q_cap_desc_box" class="mt-2 p-2.5 rounded-xl bg-white/90 border border-emerald-100 text-xs text-emerald-950 font-medium leading-relaxed">
                        Selecione uma capacidade acima para ver seu texto oficial inviolável.
                    </div>
                <?php else: ?>
                    <div class="text-xs text-amber-900 bg-amber-100/80 border border-amber-300 rounded-xl p-3 flex items-center justify-between gap-2">
                        <span>⚠️ Este curso ainda não tem a Matriz SAEP (<code>.xls</code>) cadastrada. Carregue o arquivo <code>.xls</code> primeiro para selecionar as capacidades oficiais C1..Cn!</span>
                        <button type="button" onclick="document.getElementById('course-view-matrix-input').click()" class="px-3 py-1.5 bg-amber-600 text-white rounded-lg font-bold shrink-0">Carregar .xls</button>
                    </div>
                    <input type="text" id="edit_q_capacity" required placeholder="Código (ex: C1)" class="mt-2 w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs">
                <?php endif; ?>
            </div>

            <!-- 2. Unidade Curricular (Módulo), Dificuldade e Tag -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Unidade Curricular (Módulo)</label>
                    <select id="edit_q_module_id" onchange="toggleNewModuleInput(this.value)" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-700 outline-none focus:bg-white focus:border-senai-blue">
                        <?php foreach ($allCourseModules as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                        <?php endforeach; ?>
                        <option value="__new__">+ Criar nova Unidade Curricular...</option>
                    </select>
                    <input type="text" id="edit_q_new_module" placeholder="Nome da nova UC..." class="hidden mt-1.5 w-full px-3 py-1.5 bg-white border border-indigo-300 rounded-lg text-xs text-slate-800 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Dificuldade</label>
                    <select id="edit_q_difficulty" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-700 outline-none focus:bg-white focus:border-senai-blue">
                        <option value="Fácil">Fácil</option>
                        <option value="Médio">Médio</option>
                        <option value="Difícil">Difícil</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Tag / Lote (Opcional)</label>
                    <input type="text" id="edit_q_tag" placeholder="Ex: SAEP2026" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium text-slate-700 outline-none focus:bg-white focus:border-senai-blue">
                </div>
            </div>

            <!-- 3. Contexto / Texto-Base -->
            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Contexto / Texto-Base da Questão (Opcional)</label>
                <textarea id="edit_q_context" rows="3" placeholder="Texto-base, estudo de caso ou contextualização inicial da questão..." class="w-full p-3 bg-slate-50 focus:bg-white border border-slate-200 rounded-xl text-xs text-slate-700 font-medium leading-relaxed outline-none focus:ring-2 focus:ring-senai-blue"></textarea>
            </div>

            <!-- 4. Comando / Enunciado -->
            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Comando / Enunciado da Questão <span class="text-red-500">*</span></label>
                <textarea id="edit_q_command" rows="3" required placeholder="Pergunta / comando da questão..." class="w-full p-3 bg-white border border-slate-200 rounded-xl text-xs text-slate-800 font-semibold leading-relaxed outline-none focus:ring-2 focus:ring-senai-blue"></textarea>
            </div>

            <!-- 5. Alternativas -->
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider">Alternativas (Clique na letra A, B, C, D, E para definir a correta)</label>
                    <button type="button" onclick="addEditQuestionOption()" class="text-[11px] font-bold text-senai-blue hover:underline flex items-center gap-1">
                        <i class="ph-bold ph-plus"></i> Adicionar Alternativa
                    </button>
                </div>
                <div id="edit_q_options_list" class="space-y-2">
                    <!-- Preenchido via JS -->
                </div>
            </div>

            <div id="edit-q-alert" class="hidden p-3 rounded-xl text-xs font-bold"></div>

            <div class="pt-3 border-t border-slate-100 flex justify-end gap-3">
                <button type="button" onclick="closeEditQuestionModal()" class="px-5 py-2.5 rounded-xl font-bold text-xs md:text-sm text-slate-500 hover:bg-slate-100 transition-colors">
                    Cancelar
                </button>
                <button type="submit" id="btn-save-edit-q" class="px-6 py-2.5 rounded-xl font-bold text-xs md:text-sm bg-emerald-600 text-white hover:bg-emerald-700 transition-all shadow-md flex items-center gap-2">
                    <i class="ph-bold ph-floppy-disk"></i> Salvar Alterações da Questão
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Gerenciar Imagem de Apoio -->
<div id="manageImageModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden scale-95 opacity-0 transition-all duration-300 flex flex-col" id="manageImageModalContent">
        
        <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/80 shrink-0">
            <h3 class="font-bold text-base text-slate-800 flex items-center gap-2">
                <i class="ph-bold ph-image text-indigo-600"></i> Imagem de Apoio da Questão
            </h3>
            <button onclick="closeManageImageModal()" class="text-slate-400 hover:text-red-500 transition-colors bg-white rounded-full p-1 shadow-sm border border-slate-200">
                <i class="ph-bold ph-x text-lg"></i>
            </button>
        </div>
        
        <div class="p-6">
            <p class="text-xs text-slate-500 mb-4">
                Vincule ou atualize a imagem de apoio (circuitos, gráficos, esquemas técnicos) para esta questão.
            </p>

            <!-- Preview atual -->
            <div id="manage-img-preview-box" class="border-2 border-dashed border-slate-200 rounded-2xl p-4 text-center mb-5 bg-slate-50/50 min-h-[160px] flex flex-col items-center justify-center">
                <div id="manage-no-img-text" class="text-slate-400 text-xs">
                    <i class="ph-bold ph-image-square text-3xl mb-1 text-slate-300 block"></i>
                    Nenhuma imagem vinculada a esta questão.
                </div>
                <div id="manage-has-img-box" class="hidden relative w-full">
                    <img id="manage-img-display" src="#" alt="Preview" class="max-h-56 mx-auto rounded-xl border border-slate-200 shadow-sm object-contain bg-white">
                </div>
            </div>

            <!-- Status do Upload -->
            <div id="manage-img-status" class="hidden mb-4 p-3 rounded-xl text-xs font-bold text-center"></div>

            <input type="file" id="manage-img-file-input" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="hidden" onchange="uploadManageImageFile(this)">

            <!-- Ações -->
            <div class="flex items-center justify-between gap-3 pt-4 border-t border-slate-100">
                <button type="button" id="btn-remove-manage-img" onclick="removeManageImageFile()" class="hidden px-4 py-2 rounded-xl text-xs font-bold text-red-600 hover:bg-red-50 transition-colors flex items-center gap-1.5">
                    <i class="ph-bold ph-trash"></i> Remover Imagem
                </button>
                <div class="flex-1"></div>
                <button type="button" onclick="document.getElementById('manage-img-file-input').click()" id="btn-select-manage-img" class="px-5 py-2.5 rounded-xl font-bold text-xs bg-indigo-600 text-white hover:bg-indigo-700 transition-all shadow-sm flex items-center gap-2">
                    <i class="ph-bold ph-cloud-arrow-up"></i> Escolher Imagem
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Lightbox Modal para Zoom -->
<div id="lightboxModal" class="fixed inset-0 z-[70] bg-slate-950/85 backdrop-blur-md hidden items-center justify-center p-4 cursor-zoom-out" onclick="closeLightbox()">
    <div class="relative max-w-5xl max-h-[92vh] flex items-center justify-center" onclick="event.stopPropagation()">
        <img id="lightbox-image" src="#" alt="Zoom" class="max-h-[90vh] max-w-[90vw] rounded-2xl shadow-2xl border border-white/20 object-contain bg-white">
        <button type="button" onclick="closeLightbox()" class="absolute -top-4 -right-4 w-10 h-10 rounded-full bg-white text-slate-800 shadow-lg flex items-center justify-center hover:bg-slate-100 transition-colors" title="Fechar visualização">
            <i class="ph-bold ph-x text-lg"></i>
        </button>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
    // Dicionário de questões e capacidades oficiais da Matriz em JSON
    const currentCourseId = <?= (int)$courseId ?>;
    const questionsData = <?= json_encode($questoes_json, JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    const officialCapacitiesMap = <?= json_encode($officialCapMap, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    let currentDetailsQuestionId = null;
    let currentManageQuestionId = null;
    let currentEditOptions = [];

    function escapeHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Elementos de Filtro
    const fModule = document.getElementById('filter-module');
    const fCapacity = document.getElementById('filter-capacity');
    const fDifficulty = document.getElementById('filter-difficulty');
    const cards = document.querySelectorAll('.question-card');
    const noResults = document.getElementById('no-results');

    function applyFilters() {
        if(!fModule) return;
        
        const mod = fModule.value;
        const cap = fCapacity.value;
        const diff = fDifficulty.value;
        
        let visibleCount = 0;

        cards.forEach(card => {
            let show = true;
            
            if (mod !== 'all' && card.dataset.module !== mod) show = false;
            if (cap !== 'all' && card.dataset.capacity !== cap) show = false;
            if (diff !== 'all' && card.dataset.difficulty !== diff) show = false;
            
            if (show) {
                card.style.display = 'flex';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        if (visibleCount === 0 && noResults) {
            noResults.classList.remove('hidden');
        } else if (noResults) {
            noResults.classList.add('hidden');
        }
    }

    if(fModule) {
        fModule.addEventListener('change', applyFilters);
        fCapacity.addEventListener('change', applyFilters);
        fDifficulty.addEventListener('change', applyFilters);
    }

    // Upload da Matriz SAEP (.xls) direto na tela do Curso
    async function uploadCourseMatrixFromView(input) {
        if (!input.files || !input.files[0]) return;
        const file = input.files[0];
        const btn = document.getElementById('btn-upload-matrix-view');
        const origHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="ph-bold ph-spinner animate-spin"></i> Lendo Matriz .xls...';
        }

        try {
            let clientCapacities = null;
            if (typeof XLSX !== 'undefined') {
                try {
                    const buf = await file.arrayBuffer();
                    const wb = XLSX.read(buf, { type: 'array' });
                    const ws = wb.Sheets[wb.SheetNames[0]];
                    if (ws) {
                        const caps = [];
                        let seq = 1;
                        for (let col = 2; col <= 30; col++) {
                            const descCell = ws[XLSX.utils.encode_cell({ r: 6, c: col })];
                            const codeCell = ws[XLSX.utils.encode_cell({ r: 5, c: col })];
                            if (!descCell || !String(descCell.v || '').trim()) continue;
                            const desc = String(descCell.v).replace(/\s+/g, ' ').trim();
                            let code = codeCell ? String(codeCell.v || '').trim().toUpperCase() : '';
                            if (!/^C\d+$/i.test(code)) code = 'C' + seq;
                            caps.push({ capacity_code: code, description: desc });
                            seq++;
                        }
                        if (caps.length > 0) clientCapacities = caps;
                    }
                } catch (ex) {}
            }

            const formData = new FormData();
            formData.append('action', 'parse_and_save_matrix');
            formData.append('course_id', currentCourseId);
            formData.append('matrix_file', file);
            if (clientCapacities) {
                formData.append('capacities_json', JSON.stringify(clientCapacities));
            }

            const res = await fetch('course_matrix_ajax.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (!data.success) {
                throw new Error(data.message || 'Erro ao processar arquivo da Matriz.');
            }

            alert(data.message);
            window.location.reload();
        } catch (err) {
            alert(err.message || 'Erro ao enviar Matriz SAEP.');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        }
    }

    // Lógica do Modal de Detalhes
    function showQuestionDetails(id) {
        const q = questionsData[id];
        if(!q) return;
        currentDetailsQuestionId = id;

        document.getElementById('modal-context').textContent = q.context || 'Nenhum contexto fornecido.';
        document.getElementById('modal-command').textContent = q.command;
        
        let diffColor = 'bg-slate-100 text-slate-600';
        if (q.difficulty === 'Fácil') diffColor = 'bg-green-100 text-green-700';
        if (q.difficulty === 'Médio') diffColor = 'bg-yellow-100 text-yellow-700';
        if (q.difficulty === 'Difícil') diffColor = 'bg-red-100 text-red-700';

        document.getElementById('modal-badges').innerHTML = `
            <span class="text-[10px] font-bold px-2 py-1 rounded-md bg-slate-100 text-slate-600 uppercase tracking-wider">${escapeHtml(q.module_name)}</span>
            <span class="text-[10px] font-bold px-2 py-1 rounded-md bg-senai-cyan/10 text-senai-cyan uppercase tracking-wider">CAP: ${escapeHtml(q.capacity || 'N/A')}</span>
            <span class="text-[10px] font-bold px-2 py-1 rounded-md ${diffColor} uppercase tracking-wider">${escapeHtml(q.difficulty)}</span>
            ${q.import_tag ? `<span class="text-[10px] font-bold px-2 py-1 rounded-md bg-violet-50 text-violet-700 border border-violet-200 uppercase tracking-wider">TAG: ${escapeHtml(q.import_tag)}</span>` : ''}
            ${q.image_url ? '<span class="text-[10px] font-bold px-2 py-1 rounded-md bg-indigo-50 text-indigo-700 border border-indigo-200 flex items-center gap-1 uppercase tracking-wider"><i class="ph-bold ph-image"></i> Imagem</span>' : ''}
        `;

        // Mostra descrição oficial inviolável da capacidade se existir na Matriz
        const capBox = document.getElementById('modal-official-cap-box');
        const capTxt = document.getElementById('modal-official-cap-text');
        if (q.capacity && officialCapacitiesMap[q.capacity]) {
            capTxt.textContent = `${q.capacity} — ${officialCapacitiesMap[q.capacity]}`;
            capBox.classList.remove('hidden');
        } else {
            capBox.classList.add('hidden');
        }

        // Imagem de apoio
        const imgBox = document.getElementById('modal-image-container');
        const imgEl = document.getElementById('modal-image-preview');
        const btnImgLabel = document.getElementById('modal-manage-img-btn-text');
        
        if (q.image_url) {
            imgEl.src = q.image_url;
            imgBox.classList.remove('hidden');
            if (btnImgLabel) btnImgLabel.textContent = 'Editar Imagem';
        } else {
            imgEl.src = '#';
            imgBox.classList.add('hidden');
            if (btnImgLabel) btnImgLabel.textContent = 'Adicionar Imagem';
        }

        const optsContainer = document.getElementById('modal-options');
        optsContainer.innerHTML = '';
        
        if (q.options && q.options.length > 0) {
            const labels = ['A', 'B', 'C', 'D', 'E', 'F'];
            q.options.forEach((opt, index) => {
                const isCorrect = opt.is_correct == 1;
                const letter = labels[index] || '-';
                
                let cssClass = 'border-slate-200 bg-white text-slate-700';
                let icon = '';
                
                if (isCorrect) {
                    cssClass = 'border-green-300 bg-green-50 text-green-800 ring-1 ring-green-300';
                    icon = '<i class="ph-bold ph-check-circle text-green-600 text-lg"></i>';
                }

                optsContainer.innerHTML += `
                    <div class="flex items-start gap-3 p-3 rounded-xl border ${cssClass}">
                        <div class="w-6 h-6 rounded bg-slate-100 flex items-center justify-center font-bold text-xs text-slate-500 shrink-0 mt-0.5">${letter}</div>
                        <div class="text-sm font-medium pt-0.5 flex-1">${escapeHtml(opt.text)}</div>
                        ${icon}
                    </div>
                `;
            });
        } else {
            optsContainer.innerHTML = '<p class="text-sm text-slate-400 italic">Nenhuma alternativa cadastrada.</p>';
        }

        const modal = document.getElementById('detailsModal');
        const content = document.getElementById('detailsModalContent');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => {
            content.classList.remove('scale-95', 'opacity-0');
        }, 10);
    }

    function closeDetailsModal() {
        const modal = document.getElementById('detailsModal');
        const content = document.getElementById('detailsModalContent');
        content.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }, 300);
    }

    // ========================================================================
    // MODAL DE EDIÇÃO COMPLETA DA QUESTÃO (CAPACIDADE, CONTEXTO, COMANDO, ETC)
    // ========================================================================
    function updateEditCapDescPreview(code) {
        const box = document.getElementById('edit_q_cap_desc_box');
        if (!box) return;
        if (code && officialCapacitiesMap[code]) {
            box.innerHTML = `<b class="text-emerald-800">${escapeHtml(code)}:</b> ${escapeHtml(officialCapacitiesMap[code])}`;
        } else {
            box.textContent = 'Selecione uma capacidade oficial da Matriz acima para visualizar seu texto completo.';
        }
    }

    function toggleNewModuleInput(val) {
        const inp = document.getElementById('edit_q_new_module');
        if (!inp) return;
        if (val === '__new__') {
            inp.classList.remove('hidden');
            inp.focus();
        } else {
            inp.classList.add('hidden');
            inp.value = '';
        }
    }

    function openEditQuestionModal(id) {
        const q = questionsData[id];
        if (!q) return;

        document.getElementById('edit_q_id').value = id;
        const capSelect = document.getElementById('edit_q_capacity');
        if (capSelect) {
            capSelect.value = q.capacity || '';
            updateEditCapDescPreview(capSelect.value);
        }

        const modSelect = document.getElementById('edit_q_module_id');
        if (modSelect) {
            modSelect.value = q.module_id;
            toggleNewModuleInput(modSelect.value);
        }

        document.getElementById('edit_q_difficulty').value = q.difficulty || 'Médio';
        document.getElementById('edit_q_tag').value = q.import_tag || '';
        document.getElementById('edit_q_context').value = q.context || '';
        document.getElementById('edit_q_command').value = q.command || '';
        document.getElementById('edit-q-alert').classList.add('hidden');

        // Clona alternativas para edição
        currentEditOptions = (q.options && q.options.length > 0)
            ? q.options.map(o => ({ id: o.id, text: o.text, is_correct: Number(o.is_correct) === 1 }))
            : [
                { text: '', is_correct: true },
                { text: '', is_correct: false },
                { text: '', is_correct: false },
                { text: '', is_correct: false }
            ];

        renderEditQuestionOptions();

        const modal = document.getElementById('editQuestionModal');
        const content = document.getElementById('editQuestionModalContent');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => {
            content.classList.remove('scale-95', 'opacity-0');
        }, 10);
    }

    function closeEditQuestionModal() {
        const modal = document.getElementById('editQuestionModal');
        const content = document.getElementById('editQuestionModalContent');
        content.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }, 300);
    }

    function renderEditQuestionOptions() {
        const container = document.getElementById('edit_q_options_list');
        if (!container) return;
        container.innerHTML = '';
        const letters = ['A', 'B', 'C', 'D', 'E', 'F'];

        currentEditOptions.forEach((opt, idx) => {
            const isCorr = !!opt.is_correct;
            const letter = letters[idx] || (idx + 1);
            const row = document.createElement('div');
            row.className = `flex items-center gap-2.5 p-2 rounded-xl border transition-all ${isCorr ? 'bg-emerald-50 border-emerald-300' : 'bg-slate-50 border-slate-200'}`;
            row.innerHTML = `
                <button type="button" onclick="setEditCorrectOption(${idx})" title="Clique para marcar esta alternativa como correta" class="w-7 h-7 rounded-lg font-extrabold text-xs flex items-center justify-center shrink-0 transition-all ${isCorr ? 'bg-emerald-600 text-white shadow-sm' : 'bg-white border border-slate-300 text-slate-500 hover:border-emerald-400'}">
                    ${letter}
                </button>
                <input type="text" value="${escapeHtml(opt.text)}" required placeholder="Texto da alternativa ${letter}..." oninput="currentEditOptions[${idx}].text = this.value" class="flex-1 bg-transparent text-xs font-medium ${isCorr ? 'text-emerald-950 font-bold' : 'text-slate-700'} outline-none py-1">
                ${isCorr ? '<span class="text-[10px] font-extrabold text-emerald-700 bg-emerald-100 px-2 py-0.5 rounded-md shrink-0">Correta</span>' : ''}
                ${currentEditOptions.length > 2 ? `<button type="button" onclick="removeEditOption(${idx})" class="text-slate-400 hover:text-red-500 p-1" title="Remover alternativa"><i class="ph-bold ph-x"></i></button>` : ''}
            `;
            container.appendChild(row);
        });
    }

    function setEditCorrectOption(correctIdx) {
        currentEditOptions.forEach((o, i) => { o.is_correct = (i === correctIdx); });
        renderEditQuestionOptions();
    }

    function addEditQuestionOption() {
        if (currentEditOptions.length >= 6) return;
        currentEditOptions.push({ text: '', is_correct: false });
        renderEditQuestionOptions();
    }

    function removeEditOption(idx) {
        if (currentEditOptions.length <= 2) return;
        const wasCorrect = currentEditOptions[idx].is_correct;
        currentEditOptions.splice(idx, 1);
        if (wasCorrect && currentEditOptions.length > 0) {
            currentEditOptions[0].is_correct = true;
        }
        renderEditQuestionOptions();
    }

    async function submitEditQuestion(e) {
        e.preventDefault();
        const qId = Number(document.getElementById('edit_q_id').value);
        const cap = document.getElementById('edit_q_capacity').value.trim();
        const modVal = document.getElementById('edit_q_module_id').value;
        const newModName = document.getElementById('edit_q_new_module').value.trim();
        const diff = document.getElementById('edit_q_difficulty').value;
        const tag = document.getElementById('edit_q_tag').value.trim();
        const ctx = document.getElementById('edit_q_context').value.trim();
        const cmd = document.getElementById('edit_q_command').value.trim();
        const btn = document.getElementById('btn-save-edit-q');
        const alertBox = document.getElementById('edit-q-alert');

        if (!cap) {
            alert('Por favor, selecione uma Capacidade Oficial da Matriz SAEP.');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="ph-bold ph-spinner animate-spin"></i> Salvando...';
        alertBox.classList.add('hidden');

        try {
            const res = await fetch('course_matrix_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update_question',
                    question_id: qId,
                    course_id: currentCourseId,
                    module_id: modVal === '__new__' ? 0 : Number(modVal),
                    new_module_name: modVal === '__new__' ? newModName : '',
                    capacity: cap,
                    context: ctx,
                    command: cmd,
                    difficulty: diff,
                    import_tag: tag,
                    options: currentEditOptions
                })
            });

            const data = await res.json();
            if (!data.success) {
                throw new Error(data.message || 'Erro ao atualizar questão.');
            }

            // Atualiza dados em memória
            const uq = data.question;
            questionsData[qId] = {
                ...questionsData[qId],
                module_id: uq.module_id,
                module_name: uq.module_name,
                capacity: uq.capacity,
                context: uq.context,
                command: uq.command,
                difficulty: uq.difficulty,
                import_tag: uq.import_tag,
                options: uq.options
            };

            // Atualiza o card na grade imediatamente
            const cardEl = document.getElementById(`q-card-${qId}`);
            if (cardEl) {
                cardEl.dataset.module = uq.module_id;
                cardEl.dataset.capacity = uq.capacity;
                cardEl.dataset.difficulty = uq.difficulty;
            }
            const modBadge = document.getElementById(`card-mod-${qId}`);
            if (modBadge) modBadge.textContent = uq.module_name;

            const cmdText = document.getElementById(`card-cmd-${qId}`);
            if (cmdText) cmdText.textContent = uq.command;

            const capBadge = document.getElementById(`card-cap-${qId}`);
            if (capBadge) {
                capBadge.textContent = `CAP: ${uq.capacity}`;
                capBadge.className = 'text-[10px] font-bold px-2 py-1 rounded-md bg-senai-cyan/10 text-senai-cyan uppercase tracking-wider';
                if (officialCapacitiesMap[uq.capacity]) {
                    capBadge.title = `${uq.capacity}: ${officialCapacitiesMap[uq.capacity]}`;
                }
            }

            const diffBadge = document.getElementById(`card-diff-${qId}`);
            if (diffBadge) {
                let dCol = 'bg-slate-100 text-slate-600';
                if (uq.difficulty === 'Fácil') dCol = 'bg-green-100 text-green-700';
                if (uq.difficulty === 'Médio') dCol = 'bg-yellow-100 text-yellow-700';
                if (uq.difficulty === 'Difícil') dCol = 'bg-red-100 text-red-700';
                diffBadge.className = `text-[10px] font-bold px-2 py-1 rounded-md ${dCol} uppercase tracking-wider`;
                diffBadge.textContent = uq.difficulty;
            }

            // Se o modal de detalhes estava aberto, atualiza-o também
            if (currentDetailsQuestionId === qId) {
                showQuestionDetails(qId);
            }

            closeEditQuestionModal();

        } catch (err) {
            alertBox.className = 'p-3 rounded-xl text-xs font-bold bg-red-50 text-red-700 border border-red-200';
            alertBox.textContent = err.message || 'Erro de comunicação.';
            alertBox.classList.remove('hidden');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="ph-bold ph-floppy-disk"></i> Salvar Alterações da Questão';
        }
    }

    // Modal Gerenciar Imagem
    function openManageImageModal(id) {
        currentManageQuestionId = id;
        const q = questionsData[id];
        if (!q) return;

        const modal = document.getElementById('manageImageModal');
        const content = document.getElementById('manageImageModalContent');
        const noImgText = document.getElementById('manage-no-img-text');
        const hasImgBox = document.getElementById('manage-has-img-box');
        const imgDisplay = document.getElementById('manage-img-display');
        const btnRemove = document.getElementById('btn-remove-manage-img');
        const statusBox = document.getElementById('manage-img-status');
        
        statusBox.classList.add('hidden');
        document.getElementById('manage-img-file-input').value = '';

        if (q.image_url) {
            imgDisplay.src = q.image_url;
            hasImgBox.classList.remove('hidden');
            noImgText.classList.add('hidden');
            btnRemove.classList.remove('hidden');
        } else {
            imgDisplay.src = '#';
            hasImgBox.classList.add('hidden');
            noImgText.classList.remove('hidden');
            btnRemove.classList.add('hidden');
        }

        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => {
            content.classList.remove('scale-95', 'opacity-0');
        }, 10);
    }

    function closeManageImageModal() {
        const modal = document.getElementById('manageImageModal');
        const content = document.getElementById('manageImageModalContent');
        content.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }, 300);
    }

    async function uploadManageImageFile(input) {
        if (!input.files || !input.files[0] || !currentManageQuestionId) return;
        const file = input.files[0];
        
        if (file.size > 10 * 1024 * 1024) {
            alert('A imagem deve ter no máximo 10MB.');
            input.value = '';
            return;
        }

        const statusBox = document.getElementById('manage-img-status');
        const btnSelect = document.getElementById('btn-select-manage-img');
        
        statusBox.classList.remove('hidden', 'bg-red-50', 'text-red-700', 'bg-green-50', 'text-green-700');
        statusBox.classList.add('bg-indigo-50', 'text-indigo-700');
        statusBox.innerHTML = '<i class="ph-bold ph-spinner animate-spin"></i> Enviando imagem...';
        btnSelect.disabled = true;

        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('question_id', currentManageQuestionId);
        formData.append('image', file);

        try {
            const res = await fetch('question_image_ajax.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success) {
                questionsData[currentManageQuestionId].image_url = data.image_url;
                
                document.getElementById('manage-img-display').src = data.image_url;
                document.getElementById('manage-has-img-box').classList.remove('hidden');
                document.getElementById('manage-no-img-text').classList.add('hidden');
                document.getElementById('btn-remove-manage-img').classList.remove('hidden');
                
                const cardBadge = document.getElementById(`badge-img-${currentManageQuestionId}`);
                if (cardBadge) cardBadge.classList.remove('hidden');

                if (currentDetailsQuestionId === currentManageQuestionId) {
                    document.getElementById('modal-image-preview').src = data.image_url;
                    document.getElementById('modal-image-container').classList.remove('hidden');
                    const btnImgLabel = document.getElementById('modal-manage-img-btn-text');
                    if (btnImgLabel) btnImgLabel.textContent = 'Editar Imagem';
                }

                statusBox.classList.remove('bg-indigo-50', 'text-indigo-700');
                statusBox.classList.add('bg-green-50', 'text-green-700');
                statusBox.innerHTML = '<i class="ph-bold ph-check-circle"></i> Imagem vinculada com sucesso!';
            } else {
                statusBox.classList.remove('bg-indigo-50', 'text-indigo-700');
                statusBox.classList.add('bg-red-50', 'text-red-700');
                statusBox.textContent = data.error || 'Erro ao enviar imagem.';
            }
        } catch (e) {
            statusBox.classList.remove('bg-indigo-50', 'text-indigo-700');
            statusBox.classList.add('bg-red-50', 'text-red-700');
            statusBox.textContent = 'Erro de comunicação ao enviar imagem.';
        } finally {
            btnSelect.disabled = false;
        }
    }

    async function removeManageImageFile() {
        if (!currentManageQuestionId || !confirm('Deseja realmente remover a imagem vinculada a esta questão?')) return;

        const statusBox = document.getElementById('manage-img-status');
        statusBox.classList.remove('hidden', 'bg-red-50', 'text-red-700', 'bg-green-50', 'text-green-700');
        statusBox.classList.add('bg-indigo-50', 'text-indigo-700');
        statusBox.innerHTML = '<i class="ph-bold ph-spinner animate-spin"></i> Removendo imagem...';

        try {
            const formData = new FormData();
            formData.append('action', 'remove');
            formData.append('question_id', currentManageQuestionId);

            const res = await fetch('question_image_ajax.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success) {
                questionsData[currentManageQuestionId].image_url = null;
                
                document.getElementById('manage-img-display').src = '#';
                document.getElementById('manage-has-img-box').classList.add('hidden');
                document.getElementById('manage-no-img-text').classList.remove('hidden');
                document.getElementById('btn-remove-manage-img').classList.add('hidden');

                const cardBadge = document.getElementById(`badge-img-${currentManageQuestionId}`);
                if (cardBadge) cardBadge.classList.add('hidden');

                if (currentDetailsQuestionId === currentManageQuestionId) {
                    document.getElementById('modal-image-preview').src = '#';
                    document.getElementById('modal-image-container').classList.add('hidden');
                    const btnImgLabel = document.getElementById('modal-manage-img-btn-text');
                    if (btnImgLabel) btnImgLabel.textContent = 'Adicionar Imagem';
                }

                statusBox.classList.remove('bg-indigo-50', 'text-indigo-700');
                statusBox.classList.add('bg-green-50', 'text-green-700');
                statusBox.innerHTML = '<i class="ph-bold ph-check-circle"></i> Imagem removida com sucesso!';
            } else {
                statusBox.classList.remove('bg-indigo-50', 'text-indigo-700');
                statusBox.classList.add('bg-red-50', 'text-red-700');
                statusBox.textContent = data.error || 'Erro ao remover imagem.';
            }
        } catch (e) {
            statusBox.classList.remove('bg-indigo-50', 'text-indigo-700');
            statusBox.classList.add('bg-red-50', 'text-red-700');
            statusBox.textContent = 'Erro de comunicação ao remover imagem.';
        }
    }

    // Lightbox
    function openLightbox(src) {
        if (!src || src === '#' || src.endsWith('#')) return;
        document.getElementById('lightbox-image').src = src;
        const lb = document.getElementById('lightboxModal');
        lb.classList.remove('hidden');
        lb.classList.add('flex');
    }

    function closeLightbox() {
        const lb = document.getElementById('lightboxModal');
        lb.classList.add('hidden');
        lb.classList.remove('flex');
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeLightbox();
        }
    });
</script>

<?php include 'includes/footer.php'; ?>
