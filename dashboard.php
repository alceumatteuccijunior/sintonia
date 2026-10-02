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
    $currentPage = 'dashboard';
    include 'includes/sidebar.php'; 
    ?>

    <!-- Main Content -->
    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        
        <header class="h-14 flex items-center justify-between px-4 md:px-6 absolute top-0 w-full z-20 pointer-events-none">
            <button onclick="toggleSidebar()" class="pointer-events-auto w-10 h-10 flex items-center justify-center bg-white/50 hover:bg-white rounded-xl backdrop-blur-md shadow-sm border border-white/60 transition-all active:scale-95 group text-senai-dark hover:text-senai-cyan">
                <i id="sidebar-icon" class="ph-bold ph-list text-xl transition-transform duration-300"></i>
            </button>
            <div class="flex-1"></div>
            
            <button class="pointer-events-auto text-[11px] font-bold text-slate-600 hover:text-senai-blue flex items-center gap-1.5 bg-white/70 backdrop-blur-xl border border-white/80 shadow-sm px-4 py-2 rounded-full hover:shadow transition-all duration-300 active:scale-95 group mr-2">
                <i class="ph-fill ph-clock text-senai-orange group-hover:animate-pulse"></i> 0 Sprints Ativas
            </button>
        </header>

        <!-- Area de Scroll Dinâmica -->
        <div id="content-scroll-area" class="flex-1 overflow-y-auto no-scrollbar w-full flex flex-col items-center pt-24 pb-20 relative z-10 px-4 md:px-8">
            
            <div class="w-full max-w-5xl">
                <div class="mb-10 animate-slide-up text-center md:text-left">
                    <h1 class="text-3xl md:text-4xl font-bold tracking-tight mb-2 drop-shadow-sm text-slate-800 flex items-center gap-3">
                        <span>Olá, <span class="text-gradient"><?= htmlspecialchars(explode(' ', trim($_SESSION['user_name']))[0]) ?></span></span>
                        <button onclick="openHelpModal('Painel do Professor', 'Bem-vindo ao seu painel principal! <br><br>Aqui você tem um resumo rápido das suas turmas e das avaliações (Sprints) que você aplicou. <br><br>Use os atalhos rápidos para criar uma nova avaliação (Sprint) ou consultar o banco de questões.')" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-senai-blue hover:bg-blue-50 transition-colors shadow-inner" title="Como Usar">
                            <i class="ph-bold ph-question text-lg"></i>
                        </button>
                    </h1>
                    <p class="text-base text-slate-500 font-medium opacity-0 animate-fade-in delay-200">
                        Aqui está o resumo das suas atividades e turmas.
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 w-full mb-10">
                    <div class="bg-white/60 backdrop-blur-md border border-white/80 p-6 rounded-2xl shadow-sm hover:shadow-md transition-all group">
                        <div class="w-12 h-12 rounded-full bg-senai-blue/10 flex items-center justify-center text-senai-blue mb-4 group-hover:scale-110 transition-transform">
                            <i class="ph-fill ph-files text-2xl"></i>
                        </div>
                        <h3 class="text-3xl font-bold text-slate-800 mb-1">
                            <?php
                            $stmt = $pdo->query("SELECT count(*) FROM questions"); // Questões é global
                            echo $stmt->fetchColumn();
                            ?>
                        </h3>
                        <p class="text-sm text-slate-500 font-medium">Questões no Banco</p>
                    </div>

                    <div class="bg-white/60 backdrop-blur-md border border-white/80 p-6 rounded-2xl shadow-sm hover:shadow-md transition-all group">
                        <div class="w-12 h-12 rounded-full bg-senai-orange/10 flex items-center justify-center text-senai-orange mb-4 group-hover:scale-110 transition-transform">
                            <i class="ph-fill ph-users-three text-2xl"></i>
                        </div>
                        <h3 class="text-3xl font-bold text-slate-800 mb-1">
                            <?php
                            $unit_id = $_SESSION['unit_id'] ?? null;
                            $where_unit = $unit_id ? "WHERE unit_id = ?" : "";
                            $params = $unit_id ? [$unit_id] : [];
                            
                            $stmt = $pdo->prepare("SELECT count(*) FROM classes $where_unit");
                            $stmt->execute($params);
                            echo $stmt->fetchColumn();
                            ?>
                        </h3>
                        <p class="text-sm text-slate-500 font-medium">Turmas Cadastradas</p>
                    </div>

                    <div class="bg-white/60 backdrop-blur-md border border-white/80 p-6 rounded-2xl shadow-sm hover:shadow-md transition-all group">
                        <div class="w-12 h-12 rounded-full bg-senai-cyan/10 flex items-center justify-center text-senai-cyan mb-4 group-hover:scale-110 transition-transform">
                            <i class="ph-fill ph-target text-2xl"></i>
                        </div>
                        <h3 class="text-3xl font-bold text-slate-800 mb-1">
                            <?php
                            $where_sprint = $unit_id ? "WHERE class_id IN (SELECT id FROM classes WHERE unit_id = ?)" : "";
                            $stmt = $pdo->prepare("SELECT count(*) FROM sprints $where_sprint");
                            $stmt->execute($params);
                            echo $stmt->fetchColumn();
                            ?>
                        </h3>
                        <p class="text-sm text-slate-500 font-medium">Sprints na Unidade</p>
                    </div>
                </div>
                
                <div class="mt-8 animate-slide-up delay-200">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-xl font-bold text-slate-800">Sprints Recentes</h2>
                        <a href="sprint_create.php" class="text-sm font-bold text-senai-blue hover:text-senai-orange transition-colors">Nova Sprint +</a>
                    </div>
                    
                    <div class="bg-white/70 backdrop-blur-md border border-white/80 rounded-3xl p-6 shadow-sm overflow-hidden">
                        <?php
                        $stmtSprints = $pdo->prepare("
                            SELECT s.*, c.name as class_name,
                            (SELECT count(*) FROM sprint_questions WHERE sprint_id = s.id) as total_questions,
                            (SELECT count(DISTINCT student_id) FROM sprint_attempts WHERE sprint_id = s.id) as students_started
                            FROM sprints s
                            JOIN classes c ON s.class_id = c.id
                            WHERE s.teacher_id = ?
                            ORDER BY s.created_at DESC LIMIT 5
                        ");
                        $stmtSprints->execute([$_SESSION['user_id']]);
                        $sprints = $stmtSprints->fetchAll();
                        
                        if(empty($sprints)):
                        ?>
                            <div class="text-center py-8 text-slate-500">
                                <i class="ph-thin ph-clipboard-text text-4xl mb-2 opacity-50"></i>
                                <p>Nenhuma Sprint criada ainda.</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="border-b border-slate-100 text-xs uppercase tracking-widest text-slate-400">
                                            <th class="pb-3 font-semibold">Nome da Prova</th>
                                            <th class="pb-3 font-semibold">Turma</th>
                                            <th class="pb-3 font-semibold">Status</th>
                                            <th class="pb-3 font-semibold">Engajamento</th>
                                            <th class="pb-3 font-semibold text-right">Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody class="text-sm">
                                        <?php foreach($sprints as $s): ?>
                                        <tr class="border-b border-slate-50 hover:bg-slate-50/50 transition-colors group">
                                            <td class="py-4 font-bold text-slate-700"><?= htmlspecialchars($s['name']) ?></td>
                                            <td class="py-4 font-medium text-slate-500"><?= htmlspecialchars($s['class_name']) ?></td>
                                            <td class="py-4">
                                                <?php if($s['status'] === 'active'): ?>
                                                    <span class="bg-green-100 text-green-700 px-2 py-1 rounded-md text-[10px] font-bold uppercase">Ativa</span>
                                                <?php else: ?>
                                                    <span class="bg-slate-100 text-slate-600 px-2 py-1 rounded-md text-[10px] font-bold uppercase"><?= $s['status'] ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-4 font-semibold text-senai-blue">
                                                <i class="ph-fill ph-users mr-1"></i> <?= $s['students_started'] ?> iniciaram
                                            </td>
                                            <td class="py-4 text-right">
                                                <a href="sprint_report.php?id=<?= $s['id'] ?>" class="inline-flex items-center gap-1.5 bg-slate-100 hover:bg-senai-blue text-slate-600 hover:text-white px-3 py-1.5 rounded-lg font-bold transition-all text-xs">
                                                    <i class="ph-bold ph-chart-bar"></i> Relatório
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>
