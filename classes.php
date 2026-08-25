<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
$pdo = getPDOConnection(true);

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
    $currentPage = 'classes';
    include 'includes/sidebar.php'; 
    ?>

    <!-- Main Content -->
    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        
        <header class="h-14 flex items-center justify-between px-4 md:px-6 absolute top-0 w-full z-20 pointer-events-none">
            <button onclick="toggleSidebar()" class="pointer-events-auto w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl backdrop-blur-md shadow-sm border border-white/60 transition-all active:scale-95 group text-senai-dark hover:text-senai-cyan">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl transition-transform duration-300"></i>
            </button>
            <div class="flex-1"></div>
        </header>

        <!-- Area de Scroll Dinâmica -->
        <div id="content-scroll-area" class="flex-1 overflow-y-auto no-scrollbar w-full flex flex-col items-center pt-24 pb-20 relative z-10 px-4 md:px-8">
            
            <div class="w-full max-w-5xl">
                <div class="flex flex-col md:flex-row md:items-center justify-between mb-8 animate-slide-up">
                    <div>
                        <h1 class="text-3xl font-bold tracking-tight mb-2 text-slate-800">
                            Turmas & Alunos
                        </h1>
                        <p class="text-slate-500 font-medium">
                            Gerencie as turmas, matricule alunos e acompanhe o engajamento.
                        </p>
                    </div>
                    <?php if ($_SESSION['user_role'] === 'teacher'): ?>
                    <a href="class_create.php" class="mt-4 md:mt-0 bg-senai-blue text-white px-5 py-2.5 rounded-xl font-semibold shadow-[0_4px_15px_-3px_rgba(26,66,138,0.4)] hover:shadow-[0_8px_20px_-3px_rgba(26,66,138,0.5)] hover:-translate-y-0.5 transition-all flex items-center gap-2">
                        <i class="ph-bold ph-plus"></i> Nova Turma
                    </a>
                    <?php endif; ?>
                </div>

                <div class="bg-white/70 backdrop-blur-md border border-white/80 rounded-2xl p-6 shadow-sm mb-10 animate-fade-in delay-200">
                    
                    <?php
                    $stmtClasses = $pdo->query("
                        SELECT c.*, co.name as course_name 
                        FROM classes c 
                        JOIN courses co ON c.course_id = co.id 
                        ORDER BY c.year DESC, c.semester DESC, c.name ASC
                    ");
                    $classes = $stmtClasses->fetchAll();
                    
                    if (empty($classes)):
                    ?>
                        <div class="text-center text-slate-500 py-10">
                            <i class="ph-thin ph-users-three text-4xl mb-3 opacity-50"></i>
                            <p class="font-medium">Nenhuma turma cadastrada no momento.</p>
                            <p class="text-sm mt-1">Clique em "Nova Turma" para começar.</p>
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <?php foreach ($classes as $classe): 
                                $stmtCount = $pdo->prepare("SELECT count(*) FROM class_students WHERE class_id = ?");
                                $stmtCount->execute([$classe['id']]);
                                $alunos = $stmtCount->fetchColumn();
                            ?>
                                <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm hover:shadow-md transition-shadow group relative">
                                    <div class="flex justify-between items-start mb-3">
                                        <div class="w-10 h-10 rounded-xl bg-senai-orange/10 flex items-center justify-center text-senai-orange">
                                            <i class="ph-fill ph-users-three text-xl"></i>
                                        </div>
                                        <span class="text-[10px] font-bold px-2 py-1 rounded-md bg-slate-100 text-slate-600 uppercase tracking-wider">
                                            <?= htmlspecialchars($classe['year']) ?>/<?= htmlspecialchars($classe['semester']) ?>
                                        </span>
                                    </div>
                                    <h3 class="text-lg font-bold text-slate-800 mb-1">
                                        <?= htmlspecialchars($classe['name']) ?>
                                    </h3>
                                    <p class="text-xs text-senai-blue font-bold uppercase tracking-wider mb-4">
                                        <?= htmlspecialchars($classe['course_name']) ?>
                                    </p>
                                    
                                    <div class="pt-4 border-t border-slate-100 flex justify-between items-center mt-auto">
                                        <span class="text-xs font-semibold text-slate-500 flex items-center gap-1">
                                            <i class="ph-fill ph-student text-slate-400"></i> <?= $alunos ?> alunos
                                        </span>
                                        <button class="text-senai-blue hover:text-senai-orange text-sm font-bold flex items-center gap-1 transition-colors">
                                            Gerenciar <i class="ph-bold ph-arrow-right"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                </div>

            </div>
        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>
