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

// Buscar todas as questões deste curso com o nome do módulo
$stmtQs = $pdo->prepare("
    SELECT q.*, m.name as module_name 
    FROM questions q 
    JOIN modules m ON q.module_id = m.id 
    WHERE m.course_id = ?
    ORDER BY m.name, q.id
");
$stmtQs->execute([$courseId]);
$questoes = $stmtQs->fetchAll();

$modulos_unicos = [];
$capacidades_unicas = [];
$questoes_json = [];

foreach ($questoes as &$q) {
    $modulos_unicos[$q['module_id']] = $q['module_name'];
    if (!empty($q['capacity'])) {
        $capacidades_unicas[$q['capacity']] = $q['capacity'];
    }

    // Buscar alternativas
    $stmtOpt = $pdo->prepare("SELECT text, is_correct FROM question_options WHERE question_id = ?");
    $stmtOpt->execute([$q['id']]);
    $q['options'] = $stmtOpt->fetchAll();

    $questoes_json[$q['id']] = $q;
}
unset($q);

asort($modulos_unicos);
ksort($capacidades_unicas);

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
                <div class="flex flex-col md:flex-row md:items-center justify-between mb-8 animate-slide-up">
                    <div>
                        <div class="flex items-center gap-2 text-senai-cyan font-bold text-xs uppercase tracking-wider mb-2">
                            <i class="ph-fill ph-books"></i> Curso Selecionado
                        </div>
                        <h1 class="text-3xl font-bold tracking-tight mb-2 text-slate-800 flex items-center gap-3">
                            <?= htmlspecialchars($curso['name']) ?>
                            <button onclick="openHelpModal('Questões do Curso', 'Visualize o acervo completo de questões deste curso específico.<br><br><b>Ações disponíveis:</b><br>- Utilize os filtros por Módulo e Competência para encontrar questões rapidamente.<br>- Clique em <b>Ver Mais</b> para visualizar todas as alternativas e identificar a resposta correta.<br>- Adicione, edite ou remova questões para manter seu banco sempre atualizado.')" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-senai-blue hover:bg-blue-50 transition-colors shadow-inner" title="Como Usar">
                                <i class="ph-bold ph-question text-lg"></i>
                            </button>
                        </h1>
                        <p class="text-slate-500 font-medium">
                            Explore todo o acervo de questões deste curso.
                        </p>
                    </div>
                    <?php if (in_array($_SESSION['user_role'], ['admin', 'teacher'])): ?>
                    <a href="question_create.php" class="mt-4 md:mt-0 bg-senai-blue text-white px-5 py-2.5 rounded-xl font-semibold shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all flex items-center gap-2">
                        <i class="ph-bold ph-plus"></i> Nova Questão
                    </a>
                    <?php endif; ?>
                </div>

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
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Capacidade</label>
                            <select id="filter-capacity" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-lg px-3 py-2 focus:ring-1 focus:ring-senai-blue outline-none transition-all">
                                <option value="all">Todas as capacidades</option>
                                <?php foreach($capacidades_unicas as $cap): ?>
                                    <option value="<?= htmlspecialchars($cap) ?>"><?= htmlspecialchars($cap) ?></option>
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
                        ?>
                            <div class="question-card bg-white border border-slate-200 rounded-xl p-5 shadow-sm hover:shadow-md hover:border-senai-cyan/40 transition-all group relative flex flex-col cursor-pointer"
                                 data-module="<?= $q['module_id'] ?>"
                                 data-capacity="<?= htmlspecialchars($q['capacity']) ?>"
                                 data-difficulty="<?= htmlspecialchars($q['difficulty']) ?>"
                                 onclick="showQuestionDetails(<?= $q['id'] ?>)">
                                
                                <div class="flex justify-between items-start mb-3">
                                    <span class="text-[10px] font-bold px-2 py-1 rounded-md bg-slate-100 text-slate-600 uppercase tracking-wider truncate max-w-[150px]" title="<?= htmlspecialchars($q['module_name']) ?>">
                                        <?= htmlspecialchars($q['module_name']) ?>
                                    </span>
                                </div>
                                
                                <p class="text-sm font-semibold text-slate-800 mb-4 line-clamp-4 flex-grow" title="<?= htmlspecialchars($q['command']) ?>">
                                    <?= htmlspecialchars($q['command']) ?>
                                </p>
                                
                                <div class="pt-3 border-t border-slate-100 flex justify-between items-center mt-auto">
                                    <div class="flex items-center gap-1">
                                        <span class="text-[10px] font-bold px-2 py-1 rounded-md bg-senai-cyan/10 text-senai-cyan uppercase tracking-wider">
                                            CAP: <?= htmlspecialchars($q['capacity']) ?>
                                        </span>
                                        <span class="text-[10px] font-bold px-2 py-1 rounded-md <?= $diffColor ?> uppercase tracking-wider">
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
            <button onclick="closeDetailsModal()" class="text-slate-400 hover:text-red-500 transition-colors bg-white rounded-full p-1 shadow-sm border border-slate-200">
                <i class="ph-bold ph-x text-lg"></i>
            </button>
        </div>
        
        <div class="p-6 overflow-y-auto flex-1 bg-white">
            <div class="flex gap-2 mb-4" id="modal-badges">
                <!-- Badges injetadas via JS -->
            </div>
            
            <div class="mb-5 bg-slate-50 p-4 rounded-xl border border-slate-200">
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Contexto</h4>
                <p class="text-sm text-slate-700" id="modal-context"></p>
            </div>
            
            <div class="mb-6">
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Comando da Questão</h4>
                <p class="text-base font-semibold text-slate-900" id="modal-command"></p>
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

<script>
    // Dicionário de questões em JSON
    const questionsData = <?= json_encode($questoes_json, JSON_INVALID_UTF8_SUBSTITUTE) ?>;

    // Elementos de Filtro
    const fModule = document.getElementById('filter-module');
    const fCapacity = document.getElementById('filter-capacity');
    const fDifficulty = document.getElementById('filter-difficulty');
    const cards = document.querySelectorAll('.question-card');
    const noResults = document.getElementById('no-results');

    function applyFilters() {
        if(!fModule) return; // Se não houver questões
        
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

        if (visibleCount === 0) {
            noResults.classList.remove('hidden');
        } else {
            noResults.classList.add('hidden');
        }
    }

    if(fModule) {
        fModule.addEventListener('change', applyFilters);
        fCapacity.addEventListener('change', applyFilters);
        fDifficulty.addEventListener('change', applyFilters);
    }

    // Lógica do Modal de Detalhes
    function showQuestionDetails(id) {
        const q = questionsData[id];
        if(!q) return;

        // Limpa e popula dados
        document.getElementById('modal-context').textContent = q.context || 'Nenhum contexto fornecido.';
        document.getElementById('modal-command').textContent = q.command;
        
        let diffColor = 'bg-slate-100 text-slate-600';
        if (q.difficulty === 'Fácil') diffColor = 'bg-green-100 text-green-700';
        if (q.difficulty === 'Médio') diffColor = 'bg-yellow-100 text-yellow-700';
        if (q.difficulty === 'Difícil') diffColor = 'bg-red-100 text-red-700';

        document.getElementById('modal-badges').innerHTML = `
            <span class="text-[10px] font-bold px-2 py-1 rounded-md bg-slate-100 text-slate-600 uppercase tracking-wider">${q.module_name}</span>
            <span class="text-[10px] font-bold px-2 py-1 rounded-md bg-senai-cyan/10 text-senai-cyan uppercase tracking-wider">CAP: ${q.capacity}</span>
            <span class="text-[10px] font-bold px-2 py-1 rounded-md ${diffColor} uppercase tracking-wider">${q.difficulty}</span>
        `;

        const optsContainer = document.getElementById('modal-options');
        optsContainer.innerHTML = '';
        
        if (q.options && q.options.length > 0) {
            const labels = ['A', 'B', 'C', 'D', 'E'];
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
                        <div class="text-sm font-medium pt-0.5 flex-1">${opt.text}</div>
                        ${icon}
                    </div>
                `;
            });
        } else {
            optsContainer.innerHTML = '<p class="text-sm text-slate-400 italic">Nenhuma alternativa cadastrada.</p>';
        }

        // Abre o modal
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
</script>

<?php include 'includes/footer.php'; ?>
