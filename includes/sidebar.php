<?php
$currentPage = $currentPage ?? 'dashboard';
?>
<!-- Sidebar -->
<aside id="sidebar"
    class="w-64 glass-panel border-r border-white/60 flex flex-col h-full relative bg-white/40 flex-shrink-0 z-30 collapsed">
    <!-- Header Sidebar -->
    <div class="p-5 flex flex-col gap-5 sticky top-0 z-10">
        <div class="flex items-center justify-between w-full">
            <img src="logo.png" alt="Logo SENAI" style="margin-left: 25%;"
                class="h-7 object-contain drop-shadow-sm transition-transform hover:scale-105 duration-300 origin-left">
            <button onclick="toggleSidebar()"
                class="md:hidden w-7 h-7 flex items-center justify-center text-slate-400 hover:text-senai-blue hover:bg-white rounded-md transition-all">
                <i class="ph ph-x"></i>
            </button>
        </div>

        <a href="sprint_create.php"
            class="flex items-center justify-center gap-2 bg-white/80 hover:bg-white border border-white text-slate-700 px-3 py-2.5 rounded-xl shadow-[0_2px_10px_rgba(0,0,0,0.02)] hover:shadow-[0_4px_15px_rgba(26,66,138,0.06)] active:scale-[0.98] transition-all duration-300 group w-full font-medium text-sm">
            <i
                class="ph-bold ph-plus text-base text-senai-blue group-hover:rotate-180 group-hover:scale-110 transition-all duration-500"></i>
            Nova Sprint
        </a>
    </div>

    <!-- Navegação Principal -->
    <div class="flex-1 overflow-y-auto no-scrollbar p-3 pt-0 w-64">
        <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2 px-3 mt-1">Menu</h3>
        <ul class="space-y-1">
            <li>
                <a href="dashboard.php"
                    class="w-full text-left px-3 py-2.5 rounded-lg text-sm font-medium transition-all flex items-center gap-2.5 group <?= $currentPage === 'dashboard' ? 'text-senai-blue bg-white shadow-sm' : 'text-slate-600 hover:bg-white hover:shadow-sm hover:text-senai-blue' ?>">
                    <i
                        class="ph-fill ph-house <?= $currentPage === 'dashboard' ? 'text-senai-blue' : 'text-slate-400 group-hover:text-senai-blue transition-colors' ?>"></i>
                    <span class="truncate block w-full">Início</span>
                </a>
            </li>
            <li>
                <a href="questions.php"
                    class="w-full text-left px-3 py-2.5 rounded-lg text-sm font-medium transition-all flex items-center gap-2.5 group <?= $currentPage === 'questions' ? 'text-senai-blue bg-white shadow-sm' : 'text-slate-600 hover:bg-white hover:shadow-sm hover:text-senai-blue' ?>">
                    <i
                        class="ph-fill ph-books <?= $currentPage === 'questions' ? 'text-senai-blue' : 'text-slate-400 group-hover:text-senai-blue transition-colors' ?>"></i>
                    <span class="truncate block w-full">Banco de Questões</span>
                </a>
            </li>
            <li>
                <a href="classes.php"
                    class="w-full text-left px-3 py-2.5 rounded-lg text-sm font-medium transition-all flex items-center gap-2.5 group <?= $currentPage === 'classes' ? 'text-senai-blue bg-white shadow-sm' : 'text-slate-600 hover:bg-white hover:shadow-sm hover:text-senai-blue' ?>">
                    <i
                        class="ph-fill ph-users-three <?= $currentPage === 'classes' ? 'text-senai-blue' : 'text-slate-400 group-hover:text-senai-blue transition-colors' ?>"></i>
                    <span class="truncate block w-full">Turmas & Alunos</span>
                </a>
            </li>
            <li>
                <a href="student_history.php"
                    class="w-full text-left px-3 py-2.5 rounded-lg text-sm font-medium transition-all flex items-center gap-2.5 group <?= $currentPage === 'student_history' ? 'text-senai-blue bg-white shadow-sm' : 'text-slate-600 hover:bg-white hover:shadow-sm hover:text-senai-blue' ?>">
                    <i
                        class="ph-fill ph-chart-line-up <?= $currentPage === 'student_history' ? 'text-senai-blue' : 'text-slate-400 group-hover:text-senai-blue transition-colors' ?>"></i>
                    <span class="truncate block w-full">Histórico do Aluno</span>
                </a>
            </li>
            <li>
                <a href="ai_tutor.php"
                    class="w-full text-left px-3 py-2.5 rounded-lg text-sm font-medium transition-all flex items-center gap-2.5 group <?= $currentPage === 'ai_tutor' ? 'text-indigo-600 bg-white shadow-sm border border-indigo-100' : 'text-slate-600 hover:bg-white hover:shadow-sm hover:text-indigo-600' ?>">
                    <i
                        class="ph-fill ph-robot <?= $currentPage === 'ai_tutor' ? 'text-indigo-600' : 'text-slate-400 group-hover:text-indigo-600 transition-colors' ?>"></i>
                    <span class="truncate block w-full">Tutor de IA</span>
                </a>
            </li>
        </ul>
        
        <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
        <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2 px-3 mt-6">Administração</h3>
        <ul class="space-y-1">
            <li>
                <a href="admin_dashboard.php"
                    class="w-full text-left px-3 py-2.5 rounded-lg text-sm font-medium transition-all flex items-center gap-2.5 group <?= $currentPage === 'admin_dashboard' ? 'text-senai-orange bg-white shadow-sm border border-orange-100' : 'text-slate-600 hover:bg-white hover:shadow-sm hover:text-senai-orange' ?>">
                    <i
                        class="ph-fill ph-globe <?= $currentPage === 'admin_dashboard' ? 'text-senai-orange' : 'text-slate-400 group-hover:text-senai-orange transition-colors' ?>"></i>
                    <span class="truncate block w-full">Visão Global</span>
                </a>
            </li>
            <li>
                <a href="admin_users.php"
                    class="w-full text-left px-3 py-2.5 rounded-lg text-sm font-medium transition-all flex items-center gap-2.5 group <?= $currentPage === 'admin_users' ? 'text-senai-orange bg-white shadow-sm border border-orange-100' : 'text-slate-600 hover:bg-white hover:shadow-sm hover:text-senai-orange' ?>">
                    <i
                        class="ph-fill ph-users-three <?= $currentPage === 'admin_users' ? 'text-senai-orange' : 'text-slate-400 group-hover:text-senai-orange transition-colors' ?>"></i>
                    <span class="truncate block w-full">Gestão de Usuários</span>
                </a>
            </li>
            <li>
                <a href="admin_units.php"
                    class="w-full text-left px-3 py-2.5 rounded-lg text-sm font-medium transition-all flex items-center gap-2.5 group <?= $currentPage === 'admin_units' ? 'text-senai-orange bg-white shadow-sm border border-orange-100' : 'text-slate-600 hover:bg-white hover:shadow-sm hover:text-senai-orange' ?>">
                    <i
                        class="ph-fill ph-buildings <?= $currentPage === 'admin_units' ? 'text-senai-orange' : 'text-slate-400 group-hover:text-senai-orange transition-colors' ?>"></i>
                    <span class="truncate block w-full">Regionais & Escolas</span>
                </a>
            </li>
            <li>
                <a href="import_saep.php"
                    class="w-full text-left px-3 py-2.5 rounded-lg text-sm font-medium transition-all flex items-center gap-2.5 group <?= $currentPage === 'import_saep' ? 'text-indigo-600 bg-white shadow-sm border border-indigo-100' : 'text-slate-600 hover:bg-white hover:shadow-sm hover:text-indigo-600' ?>">
                    <i
                        class="ph-fill ph-upload-simple <?= $currentPage === 'import_saep' ? 'text-indigo-600' : 'text-slate-400 group-hover:text-indigo-600 transition-colors' ?>"></i>
                    <span class="truncate block w-full">Importar SAEP</span>
                </a>
            </li>
        </ul>
        <?php endif; ?>
    </div>

    <!-- Perfil -->
    <div class="p-4 border-t border-white/40 bg-white/20 backdrop-blur-md w-64">
        <a href="auth.php?logout=1"
            class="flex items-center gap-2.5 w-full hover:bg-white/80 p-2 rounded-xl transition-all active:scale-[0.98] shadow-sm hover:shadow border border-white/50 group">
            <div class="relative">
                <img src="https://api.dicebear.com/7.x/initials/svg?seed=<?= urlencode($_SESSION['user_name']) ?>&backgroundColor=1A428A"
                    class="w-8 h-8 rounded-full shadow-inner group-hover:scale-105 transition-transform">
                <div class="absolute bottom-0 right-0 w-2.5 h-2.5 bg-green-500 border-2 border-white rounded-full">
                </div>
            </div>
            <div class="text-left flex-1 overflow-hidden">
                <p class="text-sm font-semibold text-slate-800 leading-tight truncate">

                    <?= htmlspecialchars($_SESSION['user_name']) ?>
                </p>

                <p class="text-[10px] text-senai-blue font-semibold uppercase tracking-wider">
                    <?php 
                        if ($_SESSION['user_role'] === 'admin') echo 'Administrador';
                        elseif ($_SESSION['user_role'] === 'teacher') echo 'Professor';
                        else echo 'Aluno';
                    ?>
                </p>
            </div>
            <i class="ph-bold ph-sign-out text-slate-400 group-hover:text-red-500 text-xs transition-colors"
                title="Sair"></i>
        </a>
    </div>
</aside>