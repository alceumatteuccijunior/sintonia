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
                    <h1 class="text-3xl md:text-4xl font-bold tracking-tight mb-2 drop-shadow-sm text-slate-800">
                        Olá, <span class="text-gradient"><?= htmlspecialchars(explode(' ', trim($_SESSION['user_name']))[0]) ?></span>
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
                            $stmt = $pdo->query("SELECT count(*) FROM questions");
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
                            $stmt = $pdo->query("SELECT count(*) FROM classes");
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
                            $stmt = $pdo->query("SELECT count(*) FROM sprints");
                            echo $stmt->fetchColumn();
                            ?>
                        </h3>
                        <p class="text-sm text-slate-500 font-medium">Sprints Realizadas</p>
                    </div>
                </div>
                
                <div class="bg-white/70 backdrop-blur-md border border-white/80 rounded-2xl p-6 shadow-sm mb-10">
                    <h2 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                        <i class="ph-fill ph-clock-counter-clockwise text-senai-blue"></i> Últimas Atividades
                    </h2>
                    <div class="text-center text-slate-500 py-10">
                        <i class="ph-thin ph-ghost text-4xl mb-3 opacity-50"></i>
                        <p class="font-medium">Nenhuma atividade recente.</p>
                        <p class="text-sm mt-1">Crie sua primeira Sprint para começar a coletar dados.</p>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>
