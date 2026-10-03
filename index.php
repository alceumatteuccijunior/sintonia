<?php
// Teste de Deploy Automático cPanel - aiS
session_start();
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'student') {
        header("Location: student_dashboard.php");
    } else {
        header("Location: dashboard.php");
    }
    exit;
}
include 'includes/header.php';
?>
<!-- ==================== SPLASH SCREEN ==================== -->
<div id="splash-screen"
    class="fixed inset-0 z-[100] flex flex-col items-center justify-center transition-opacity duration-1000 ease-in-out"
    style="background: linear-gradient(-45deg, #F25C27, #1A428A, #F25C27, #1A428A); background-size: 400% 400%; animation: gradientBG 8s ease infinite;">
    <img src="logo-ias.png?v=2" alt="aiS Logo" class="w-64 md:w-80 h-auto animate-pulse drop-shadow-lg">
    <style>
        @keyframes gradientBG {
            0% {
                background-position: 0% 50%;
            }

            50% {
                background-position: 100% 50%;
            }

            100% {
                background-position: 0% 50%;
            }
        }

        body.splash-active {
            overflow: hidden;
        }
    </style>
</div>
<script>
    document.body.classList.add('splash-active');
    window.addEventListener('load', () => {
        setTimeout(() => {
            const splash = document.getElementById('splash-screen');
            if (splash) {
                splash.style.opacity = '0';
                setTimeout(() => {
                    splash.remove();
                    document.body.classList.remove('splash-active');
                }, 1000);
            }
        }, 2000); // 2 segundos de exibição
    });
</script>

<!-- ==================== TELA DE LOGIN ==================== -->
<div id="login-screen" class="absolute inset-0 z-50 flex bg-white fade-transition fade-in">
    <!-- Lado Esquerdo: Banner Decorativo -->
    <div
        class="hidden md:flex md:w-1/2 relative bg-gradient-to-br from-senai-blue to-senai-dark overflow-hidden items-center justify-center p-12">
        <!-- Bolha Abstrata Superior -->
        <div class="absolute top-0 left-0 w-full h-full opacity-30 pointer-events-none mix-blend-overlay">
            <svg viewBox="0 0 800 800" class="absolute -top-[20%] -left-[20%] w-[120%] h-[120%] text-senai-cyan"
                fill="currentColor">
                <path
                    d="M410.5,-455.5C522.5,-371.5,597.5,-231.5,630.5,-80.5C663.5,70.5,654.5,232.5,572.5,355.5C490.5,478.5,335.5,562.5,178.5,595.5C21.5,628.5,-137.5,610.5,-276.5,547.5C-415.5,484.5,-534.5,376.5,-602.5,241.5C-670.5,106.5,-687.5,-55.5,-629.5,-187.5C-571.5,-319.5,-438.5,-421.5,-304.5,-495.5C-170.5,-569.5,-35.5,-615.5,103.5,-596.5C242.5,-577.5,381.5,-493.5,410.5,-455.5Z"
                    transform="translate(400 400)"></path>
            </svg>
        </div>
        <!-- Linhas Topográficas Inferiores -->
        <svg class="absolute -bottom-20 -right-20 w-[80%] h-[80%] opacity-20 pointer-events-none" viewBox="0 0 200 200"
            xmlns="http://www.w3.org/2000/svg">
            <path fill="none" stroke="#FFFFFF" stroke-width="0.5"
                d="M45,-26C59.6,-13.4,73.8,0.1,72.4,12.7C71,25.4,53.9,37.3,37.6,48.5C21.3,59.7,5.7,70.3,-10.8,74.5C-27.3,78.8,-44.6,76.6,-57.4,66C-70.1,55.5,-78.3,36.5,-82.3,16.5C-86.4,-3.5,-86.3,-24.5,-76.3,-41.2C-66.3,-57.9,-46.3,-70.3,-28.9,-75C-11.4,-79.8,3.5,-76.9,18.1,-69.5C32.7,-62.1,47.1,-50.2,45,-26Z"
                transform="translate(100 100) scale(1.5)" />
            <path fill="none" stroke="#FFFFFF" stroke-width="0.5"
                d="M38.1,-21.8C49.5,-11.5,60.2,0.6,58.8,11.5C57.4,22.4,43.9,32.1,30.3,41.2C16.8,50.3,3.3,58.8,-9.5,61.9C-22.3,65,-34.5,62.7,-45.3,53.9C-56.1,45.1,-65.5,27.8,-68.2,9.6C-70.9,-8.6,-66.9,-27.7,-56.3,-42.1C-45.7,-56.5,-28.5,-66.2,-13.6,-70.1C1.3,-74,16.4,-72.1,26.7,-64.5C37.1,-56.9,42.7,-43.6,38.1,-21.8Z"
                transform="translate(100 100) scale(1.5)" />
            <path fill="none" stroke="#FFFFFF" stroke-width="0.5"
                d="M31,-16.9C40,-8.7,48.5,0.8,47,9.3C45.5,17.9,34.5,25.5,24,32.3C13.5,39.1,3.5,45.1,-6.6,46.9C-16.7,48.7,-26.9,46.4,-34.7,39C-42.5,31.6,-47.9,18.1,-49.5,4.3C-51.1,-9.6,-48.9,-23.8,-41.3,-35.1C-33.8,-46.4,-20.9,-54.7,-9.6,-57.6C1.7,-60.6,12.5,-58.2,20.8,-51.2C29,-44.1,34.7,-32.4,31,-16.9Z"
                transform="translate(100 100) scale(1.5)" />
        </svg>
        <!-- Grid de Pontos Superior Direita -->
        <div class="absolute top-[10%] right-[10%] w-24 h-48 opacity-40"
            style="background-image: radial-gradient(#fff 2px, transparent 2px); background-size: 20px 20px;"></div>
        <!-- Sinais de Plus e Círculos Espalhados -->
        <div class="absolute top-[20%] left-[30%] text-white/50 text-2xl font-light pointer-events-none">+</div>
        <div class="absolute bottom-[30%] left-[20%] text-white/50 text-2xl font-light pointer-events-none">+</div>
        <div class="absolute top-[30%] right-[40%] w-4 h-4 border-2 border-white/40 rounded-full pointer-events-none">
        </div>
        <div class="absolute bottom-[20%] left-[15%] w-4 h-4 border-2 border-white/40 rounded-full pointer-events-none">
        </div>

        <!-- Conteúdo Textual -->
        <div class="relative z-10 max-w-lg">
            <h1 class="text-5xl lg:text-6xl font-bold mb-6 text-white leading-tight">aiS<br><span
                    class="text-3xl lg:text-4xl font-normal text-indigo-200">Evolução em cada desafio</span></h1>
            <p class="text-lg lg:text-xl text-blue-100 font-medium leading-relaxed opacity-90">
                Faça login na plataforma para gerenciar turmas, simulados e engajamento.
            </p>
        </div>
    </div>

    <!-- Lado Direito: Formulário de Login -->
    <div class="w-full md:w-1/2 flex items-center justify-center p-8 bg-white relative">
        <div class="absolute top-0 right-0 w-64 h-64 bg-slate-50 rounded-bl-[100px] -z-10 opacity-50"></div>
        <div class="max-w-sm w-full">
            <div class="mb-10 text-center md:text-left">
                <!-- <img src="logo.png" alt="aiS" class="h-10 mb-8 mx-auto md:mx-0 object-contain"> -->
                <h2 class="text-3xl font-bold text-slate-800 tracking-tight">aiS</h2>
                <p class="text-slate-500 mt-2 font-medium">Acesso restrito</p>
            </div>

            <?php if (isset($_GET['error'])): ?>
                <div class="bg-red-50 text-red-500 p-3 rounded-lg text-sm font-medium mb-6">
                    <?= htmlspecialchars($_GET['error']) ?>
                </div>
            <?php endif; ?>

            <form action="auth.php" method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">E-mail</label>
                    <input type="email" name="email" required
                        class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue transition-all"
                        placeholder="professor@senai.br">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Senha</label>
                    <input type="password" name="password" required
                        class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-senai-blue/20 focus:border-senai-blue transition-all"
                        placeholder="••••••••">
                </div>
                <div class="pt-2">
                    <button type="submit"
                        class="w-full bg-senai-blue text-white font-semibold py-3.5 px-4 rounded-xl shadow-[0_8px_20px_-6px_rgba(26,66,138,0.5)] hover:shadow-[0_12px_25px_-6px_rgba(26,66,138,0.6)] hover:-translate-y-0.5 hover:bg-[#153673] transition-all duration-300">
                        Entrar no Sistema
                    </button>
                </div>
            </form>

            <div class="relative flex items-center py-6">
                <div class="flex-grow border-t border-slate-200"></div>
                <span class="flex-shrink-0 mx-4 text-slate-400 text-[11px] uppercase tracking-widest font-bold">Primeiro
                    Acesso?</span>
                <div class="flex-grow border-t border-slate-200"></div>
            </div>

            <div class="space-y-3">
                <a href="register.php"
                    class="w-full border border-slate-200 text-slate-600 font-medium py-3.5 px-4 rounded-xl hover:bg-slate-50 hover:text-senai-orange transition-all flex items-center justify-center gap-2 group">
                    <i class="ph-fill ph-student text-senai-orange group-hover:scale-110 transition-transform"></i>
                    Sou Aluno e quero me cadastrar
                </a>
            </div>

        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>