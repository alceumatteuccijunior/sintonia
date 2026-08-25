    <!-- Footer Scripts Compartilhados -->
    <script>
        // ==== TOGGLE SIDEBAR ====
        let isSidebarOpen = true;
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
    </script>
</body>
</html>
