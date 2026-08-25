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

include 'includes/header.php';
?>

<!-- ==================== APLICAÇÃO PRINCIPAL ==================== -->
<div id="app-screen" class="absolute inset-0 z-40 flex w-full h-full">
    
    <!-- Fundo Vivo com Parallax -->
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0 bg-[#F8FAFC]">
        <div id="blob1" class="absolute top-[-5%] right-[-5%] w-[500px] h-[500px] bg-senai-cyan/15 rounded-full mix-blend-multiply filter blur-[80px] opacity-70 animate-blob transition-transform duration-1000 ease-out"></div>
        <div id="blob2" class="absolute bottom-[-5%] left-[-5%] w-[600px] h-[600px] bg-senai-orange/10 rounded-full mix-blend-multiply filter blur-[90px] opacity-60 animate-blob transition-transform duration-1000 ease-out" style="animation-delay: -5s;"></div>
        <div id="blob3" class="absolute top-[25%] left-[25%] w-[400px] h-[400px] bg-senai-blue/10 rounded-full mix-blend-multiply filter blur-[80px] opacity-50 animate-blob transition-transform duration-1000 ease-out" style="animation-delay: -10s;"></div>
    </div>

    <?php
    $currentPage = 'questions';
    include 'includes/sidebar.php'; 
    ?>

    <!-- Main Content -->
    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        
        <header class="h-14 flex items-center justify-between px-4 md:px-6 absolute top-0 w-full z-20 pointer-events-none">
            <button onclick="toggleSidebar()" class="pointer-events-auto w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl backdrop-blur-md shadow-sm border border-white/60 transition-all active:scale-95 group text-senai-dark hover:text-senai-cyan">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl transition-transform duration-300"></i>
            </button>
            <div class="flex-1"></div>
            <a href="questions.php" class="pointer-events-auto text-[11px] font-bold text-slate-600 hover:text-senai-blue flex items-center gap-1.5 bg-white/70 backdrop-blur-xl border border-white/80 shadow-sm px-4 py-2 rounded-full hover:shadow transition-all duration-300 active:scale-95">
                <i class="ph-fill ph-arrow-left text-slate-400"></i> Voltar aos Cursos
            </a>
        </header>

        <!-- Area de Scroll Dinâmica -->
        <div id="content-scroll-area" class="flex-1 overflow-y-auto no-scrollbar w-full flex flex-col items-center pt-24 pb-20 relative z-10 px-4 md:px-8">
            
            <div class="w-full max-w-5xl">
                <div class="flex flex-col md:flex-row md:items-center justify-between mb-8 animate-slide-up">
                    <div>
                        <div class="flex items-center gap-2 text-senai-cyan font-bold text-xs uppercase tracking-wider mb-2">
                            <i class="ph-fill ph-books"></i> Curso Selecionado
                        </div>
                        <h1 class="text-3xl font-bold tracking-tight mb-2 text-slate-800">
                            <?= htmlspecialchars($curso['name']) ?>
                        </h1>
                        <p class="text-slate-500 font-medium">
                            Visualizando unidades curriculares e questões vinculadas.
                        </p>
                    </div>
                    <?php if ($_SESSION['user_role'] === 'teacher'): ?>
                    <a href="question_create.php" class="mt-4 md:mt-0 bg-senai-blue text-white px-5 py-2.5 rounded-xl font-semibold shadow-[0_4px_15px_-3px_rgba(26,66,138,0.4)] hover:shadow-[0_8px_20px_-3px_rgba(26,66,138,0.5)] hover:-translate-y-0.5 transition-all flex items-center gap-2">
                        <i class="ph-bold ph-plus"></i> Nova Questão
                    </a>
                    <?php endif; ?>
                </div>

                <div class="bg-white/70 backdrop-blur-md border border-white/80 rounded-2xl p-6 shadow-sm mb-10 animate-fade-in delay-200">
                    
                    <div class="space-y-8">
                        <?php
                        $stmtMods = $pdo->prepare("SELECT * FROM modules WHERE course_id = ? ORDER BY name");
                        $stmtMods->execute([$curso['id']]);
                        $modulos = $stmtMods->fetchAll();
                        
                        if (empty($modulos)):
                        ?>
                            <div class="text-center text-slate-500 py-10">
                                <i class="ph-thin ph-folder-dashed text-4xl mb-3 opacity-50"></i>
                                <p class="font-medium">Nenhuma Unidade Curricular encontrada.</p>
                            </div>
                        <?php endif; ?>

                        <?php foreach ($modulos as $modulo): ?>
                            <div class="mb-6">
                                <h3 class="text-lg font-bold text-slate-700 mb-4 flex items-center gap-2 border-b border-slate-200 pb-2">
                                    <i class="ph-fill ph-folder text-senai-orange"></i> <?= htmlspecialchars($modulo['name']) ?>
                                </h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                    <?php
                                    $stmtQs = $pdo->prepare("SELECT * FROM questions WHERE module_id = ?");
                                    $stmtQs->execute([$modulo['id']]);
                                    $questoes = $stmtQs->fetchAll();
                                    
                                    if (empty($questoes)):
                                        echo "<p class='text-sm text-slate-400 italic col-span-full py-4 text-center'>Nenhuma questão cadastrada nesta unidade.</p>";
                                    endif;

                                    foreach ($questoes as $q):
                                        $diffColor = 'bg-slate-100 text-slate-600';
                                        if ($q['difficulty'] === 'Fácil') $diffColor = 'bg-green-100 text-green-700';
                                        if ($q['difficulty'] === 'Médio') $diffColor = 'bg-yellow-100 text-yellow-700';
                                        if ($q['difficulty'] === 'Difícil') $diffColor = 'bg-red-100 text-red-700';
                                    ?>
                                        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm hover:shadow-md hover:border-senai-cyan/40 transition-all group relative flex flex-col h-full cursor-pointer">
                                            <div class="flex justify-between items-start mb-3">
                                                <span class="text-[10px] font-bold px-2 py-1 rounded-md bg-senai-cyan/10 text-senai-cyan uppercase tracking-wider">
                                                    CAP: <?= htmlspecialchars($q['capacity']) ?>
                                                </span>
                                                <span class="text-[10px] font-bold px-2 py-1 rounded-md <?= $diffColor ?> uppercase tracking-wider">
                                                    <?= htmlspecialchars($q['difficulty']) ?>
                                                </span>
                                            </div>
                                            
                                            <p class="text-xs text-slate-500 mb-2 line-clamp-2" title="<?= htmlspecialchars($q['context']) ?>">
                                                <span class="font-semibold text-slate-700">Contexto:</span> 
                                                <?= htmlspecialchars($q['context']) ?>
                                            </p>
                                            
                                            <p class="text-sm font-semibold text-slate-800 mb-4 line-clamp-3 flex-grow" title="<?= htmlspecialchars($q['command']) ?>">
                                                <?= htmlspecialchars($q['command']) ?>
                                            </p>
                                            
                                            <div class="pt-3 border-t border-slate-100 flex justify-between items-center mt-auto">
                                                <span class="text-xs text-slate-400 font-medium">Múltipla Escolha</span>
                                                <button class="text-senai-blue hover:text-senai-orange text-sm font-bold flex items-center gap-1 transition-colors">
                                                    Detalhes <i class="ph-bold ph-arrow-right"></i>
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>
