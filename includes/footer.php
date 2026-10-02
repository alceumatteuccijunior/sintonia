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

    <!-- Footer Scripts Compartilhados -->
    <script>
        function openHelpModal(title, content) {
            document.getElementById('help-modal-title').innerText = title;
            document.getElementById('help-modal-body').innerHTML = content;
            document.getElementById('global-help-modal').classList.remove('hidden');
        }
        function closeHelpModal() {
            document.getElementById('global-help-modal').classList.add('hidden');
        }

        // ==== TOGGLE SIDEBAR ====
        let isSidebarOpen = true;
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar');
            if (sidebar && sidebar.classList.contains('collapsed')) {
                isSidebarOpen = false;
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
            } else {
                sidebar.classList.remove('collapsed');
                if(sidebarIcon) sidebarIcon.classList.replace('ph-list-plus', 'ph-list');
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
    </script>
</body>
</html>
