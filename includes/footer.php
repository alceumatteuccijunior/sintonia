    <!-- Global Help Modal -->
    <div id="global-help-modal" class="fixed inset-0 z-[100] hidden">
        <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeHelpModal()"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-[90%] max-w-lg bg-white rounded-3xl shadow-2xl flex flex-col overflow-hidden animate-slide-up">
            <div class="relative h-24 bg-gradient-to-r from-senai-blue to-senai-cyan p-6 flex items-center justify-between overflow-hidden">
                <div class="absolute top-[-20px] right-[-20px] w-32 h-32 bg-white/10 rounded-full mix-blend-overlay pointer-events-none"></div>
                <div class="relative z-10 flex items-center gap-3">
                    <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center text-white backdrop-blur-md">
                        <i class="ph-bold ph-info text-2xl"></i>
                    </div>
                    <h3 id="help-modal-title" class="text-xl font-black text-white tracking-tight">Como Usar</h3>
                </div>
                <button onclick="closeHelpModal()" class="relative z-10 w-8 h-8 flex items-center justify-center rounded-full bg-black/10 hover:bg-black/20 text-white transition-colors">
                    <i class="ph-bold ph-x"></i>
                </button>
            </div>
            <div id="help-modal-body" class="p-6 md:p-8 text-slate-600 font-medium leading-relaxed max-h-[60vh] overflow-y-auto space-y-4">
                <!-- Conteúdo Injetado via JS -->
            </div>
            <div class="p-4 bg-slate-50 border-t border-slate-100 flex justify-end">
                <button onclick="closeHelpModal()" class="bg-senai-blue text-white px-6 py-2.5 rounded-xl font-bold hover:bg-blue-700 transition-colors shadow-sm">
                    Entendi, valeu!
                </button>
            </div>
        </div>
    </div>

    <!-- Global Blocking Import & Processing Overlay -->
    <div id="global-import-overlay" class="fixed inset-0 z-[200] hidden items-center justify-center bg-slate-900/65 backdrop-blur-sm p-4 select-none">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-100 p-7 md:p-8 max-w-md w-full flex flex-col items-center text-center animate-slide-up">
            <div class="relative w-16 h-16 mb-5 flex items-center justify-center">
                <div class="absolute inset-0 rounded-full border-4 border-indigo-100"></div>
                <div class="absolute inset-0 rounded-full border-4 border-senai-blue border-t-transparent animate-spin"></div>
                <i class="ph-bold ph-cloud-arrow-up text-2xl text-senai-blue"></i>
            </div>
            <h3 id="global-import-overlay-title" class="text-lg font-extrabold text-slate-800 tracking-tight">Processando Importação...</h3>
            <p id="global-import-overlay-subtitle" class="text-xs md:text-sm text-slate-500 font-medium mt-2 leading-relaxed">
                Aguarde enquanto os dados são validados e salvos.
            </p>
            <div class="mt-5 inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full bg-amber-50 border border-amber-200 text-amber-800 text-[11px] font-bold">
                <i class="ph-fill ph-hand-palm text-amber-600"></i> Por favor, aguarde e não feche nem clique novamente
            </div>
        </div>
    </div>

    <!-- Footer Scripts Compartilhados -->
    <script>
        function showImportOverlay(title, subtitle) {
            const overlay = document.getElementById('global-import-overlay');
            const titleEl = document.getElementById('global-import-overlay-title');
            const subEl = document.getElementById('global-import-overlay-subtitle');
            if (!overlay) return;
            if (titleEl && title) titleEl.textContent = title;
            if (subEl && subtitle) subEl.textContent = subtitle;
            overlay.classList.remove('hidden');
            overlay.classList.add('flex');
        }

        function hideImportOverlay() {
            const overlay = document.getElementById('global-import-overlay');
            if (!overlay) return;
            overlay.classList.add('hidden');
            overlay.classList.remove('flex');
        }

        function openHelpModal(title, content) {
            document.getElementById('help-modal-title').innerText = title;
            document.getElementById('help-modal-body').innerHTML = content;
            document.getElementById('global-help-modal').classList.remove('hidden');
        }
        function closeHelpModal() {
            document.getElementById('global-help-modal').classList.add('hidden');
        }

        // ==== TOGGLE SIDEBAR COM PERSISTÊNCIA ====
        let isSidebarOpen = true;
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar');
            const sidebarIcon = document.getElementById('sidebar-icon');
            if (!sidebar) return;

            const savedState = localStorage.getItem('sidebar_open');
            // No desktop (>= 768px), se estava aberto ou se é primeira visita, mantém aberto
            if (window.innerWidth >= 768) {
                if (savedState === 'false') {
                    sidebar.classList.add('collapsed');
                    isSidebarOpen = false;
                    if (sidebarIcon) sidebarIcon.classList.replace('ph-list', 'ph-list-plus');
                } else {
                    sidebar.classList.remove('collapsed');
                    isSidebarOpen = true;
                    if (sidebarIcon) sidebarIcon.classList.replace('ph-list-plus', 'ph-list');
                }
            } else {
                // No mobile, começa recolhido
                sidebar.classList.add('collapsed');
                isSidebarOpen = false;
                if (sidebarIcon) sidebarIcon.classList.replace('ph-list', 'ph-list-plus');
            }
        });
        
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const sidebarIcon = document.getElementById('sidebar-icon');
            if(!sidebar) return;
            
            isSidebarOpen = !isSidebarOpen;
            if(!isSidebarOpen) {
                sidebar.classList.add('collapsed');
                if(sidebarIcon) sidebarIcon.classList.replace('ph-list', 'ph-list-plus');
                localStorage.setItem('sidebar_open', 'false');
            } else {
                sidebar.classList.remove('collapsed');
                if(sidebarIcon) sidebarIcon.classList.replace('ph-list-plus', 'ph-list');
                localStorage.setItem('sidebar_open', 'true');
            }
        }
        
        // ==== EFEITO PARALLAX MOUSE (Background Blob) ====
        document.addEventListener('mousemove', (e) => {
            const blob1 = document.getElementById('blob1');
            const blob2 = document.getElementById('blob2');
            const blob3 = document.getElementById('blob3');
            
            if (blob1 && blob2 && blob3) {
                const x = (e.clientX / window.innerWidth - 0.5) * 30;
                const y = (e.clientY / window.innerHeight - 0.5) * 30;
                
                blob1.style.transform = `translate(${x}px, ${y}px) scale(1.02)`;
                blob2.style.transform = `translate(${-x}px, ${-y}px) scale(1.02)`;
                blob3.style.transform = `translate(${x*0.5}px, ${-y*0.5}px) scale(1.02)`;
            }
        });
        
        // ==== INICIALIZAÇÃO TOM SELECT ====
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof TomSelect !== 'undefined') {
                document.querySelectorAll('.search-select').forEach((el) => {
                    new TomSelect(el, {
                        create: false,
                        sortField: {
                            field: "text",
                            direction: "asc"
                        },
                        render: {
                            no_results: function(data, escape) {
                                return '<div class="no-results p-3 text-slate-500 text-sm font-medium text-center">Nenhum resultado encontrado para "' + escape(data.input) + '"</div>';
                            }
                        }
                    });
                });
            }
        });

        // ==== ASSINATURA DE AUTORIA NO CONSOLE ====
        console.log(
            "%c iaS - Inteligência Pedagógica SENAI %c Desenvolvido por Alceu Matteucci Junior & Equipe Pedagógica do SENAI Luzerna ",
            "background:#1A428A; color:#ffffff; font-weight:bold; padding:4px 8px; border-radius:4px 0 0 4px;",
            "background:#F25C27; color:#ffffff; font-weight:bold; padding:4px 8px; border-radius:0 4px 4px 0;"
        );
    </script>
</body>
</html>
