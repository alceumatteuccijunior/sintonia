<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>aiS - Assistente Pedagógico SENAI</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com?plugins=typography"></script>

    <!-- Phosphor Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    
    <!-- Marked.js para Markdown -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

    <!-- Chart.js para Gráficos -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- Tom Select para Selects Pesquisáveis -->
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.default.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Configuração de Cores e Animações Mágicas -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        senai: {
                            blue: '#1A428A',
                            orange: '#F25C27',
                            cyan: '#00B5E2',
                            light: '#F8FAFC',
                            dark: '#0F2650'
                        }
                    },
                    animation: {
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'slide-up': 'slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards',
                        'fade-in': 'fadeIn 0.6s ease-out forwards',
                        'blob': 'blob 15s infinite alternate ease-in-out',
                        'float': 'float 6s ease-in-out infinite',
                        'shimmer': 'shimmer 4s linear infinite',
                        'shimmer-fast': 'shimmer 1s linear infinite',
                        'sparkle': 'sparkle 1s forwards ease-out',
                        'scale-in': 'scaleIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards'
                    },
                    keyframes: {
                        slideUp: {
                            '0%': { opacity: '0', transform: 'translateY(30px) scale(0.98)' },
                            '100%': { opacity: '1', transform: 'translateY(0) scale(1)' },
                        },
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        blob: {
                            '0%': { transform: 'scale(1)' },
                            '50%': { transform: 'scale(1.05) rotate(3deg)' },
                            '100%': { transform: 'scale(1) rotate(-3deg)' },
                        },
                        float: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-8px)' },
                        },
                        shimmer: {
                            from: { backgroundPosition: '200% 0' },
                            to: { backgroundPosition: '-200% 0' },
                        },
                        sparkle: {
                            '0%': { transform: 'translate(0, 0) scale(0)', opacity: '1' },
                            '50%': { opacity: '1', transform: 'scale(1)' },
                            '100%': { transform: 'translate(var(--tx), var(--ty)) scale(0)', opacity: '0' },
                        },
                        scaleIn: {
                            '0%': { opacity: '0', transform: 'scale(0.95)' },
                            '100%': { opacity: '1', transform: 'scale(1)' }
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body { background-color: #F8FAFC; }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* View Transitions */
        .fade-transition { transition: opacity 0.5s ease; }
        .fade-out { opacity: 0; pointer-events: none; }
        .fade-in { opacity: 1; pointer-events: auto; }

        /* Estilização Tom Select para integrar com nosso design */
        .ts-control { border-radius: 0.75rem !important; border: 1px solid #e2e8f0 !important; padding: 0.75rem 1.25rem !important; min-height: 50px; background-color: rgba(255, 255, 255, 0.5) !important; font-family: 'Inter', sans-serif !important; font-weight: 500 !important; color: #1e293b !important; }
        .ts-control.focus { border-color: #00B5E2 !important; box-shadow: 0 0 0 2px rgba(0, 181, 226, 0.2) !important; }
        .ts-dropdown { border-radius: 0.75rem !important; border: 1px solid #e2e8f0 !important; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05) !important; margin-top: 4px !important; z-index: 50 !important; overflow: hidden; padding: 4px; }
        .ts-dropdown .option { border-radius: 0.5rem; margin-bottom: 2px; padding: 0.5rem 1rem !important; transition: all 0.2s; font-family: 'Inter', sans-serif; font-size: 0.875rem; color: #334155; }
        .ts-dropdown .active { background-color: #F8FAFC !important; color: #1A428A !important; font-weight: 600; }
        .ts-wrapper.single .ts-control:after { border-width: 5px 5px 0 5px !important; border-color: #94a3b8 transparent transparent transparent !important; right: 1rem !important; }
        
        /* Transições do Chat/Home */
        .chat-container { display: none; opacity: 0; transform: translateY(15px); transition: all 0.6s cubic-bezier(0.16, 1, 0.3, 1); }
        .chat-container.active { display: flex; opacity: 1; transform: translateY(0); }
        .home-container { display: flex; transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1); transform-origin: top center; }
        .home-container.hidden-smooth { opacity: 0; transform: scale(0.97) translateY(-15px); pointer-events: none; position: absolute; }
        
        /* Glassmorphism Refinado */
        .glass-panel {
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.9);
            box-shadow: 0 4px 20px -5px rgba(0, 0, 0, 0.04);
        }

        .text-gradient {
            background: linear-gradient(135deg, #1A428A 0%, #00B5E2 50%, #F25C27 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-size: 200% auto;
            animation: shimmer 5s linear infinite;
        }

        /* Input Animado - Glow Mais Suave */
        .input-glow-wrapper { position: relative; z-index: 10; }
        .input-glow-wrapper::before {
            content: "";
            position: absolute;
            inset: -1.5px;
            border-radius: 1.5rem;
            background: linear-gradient(90deg, #1A428A, #00B5E2, #F25C27, #00B5E2, #1A428A);
            background-size: 300% 100%;
            z-index: -1;
            opacity: 0.1;
            transition: opacity 0.4s ease, filter 0.4s ease;
            animation: shimmer 4s linear infinite;
            filter: blur(3px);
        }
        
        .input-glow-wrapper.focused::before { opacity: 0.4; filter: blur(6px); }
        
        .input-glow-wrapper.typing-magic::before {
            animation: shimmer 1.5s linear infinite !important;
            opacity: 0.7;
            filter: blur(8px);
        }

        .magic-particle {
            position: absolute;
            pointer-events: none;
            border-radius: 50%;
            z-index: 50;
            animation: sparkle 0.8s cubic-bezier(0.25, 1, 0.5, 1) forwards;
        }

        /* Sidebar Toggle - Proporções Menores */
        #sidebar {
            transition: width 0.3s ease, opacity 0.3s ease, transform 0.3s ease;
        }
        #sidebar.collapsed { 
            width: 0 !important; 
            opacity: 0; 
            transform: translateX(-100%); 
            pointer-events: none; 
            overflow: hidden; 
            padding: 0;
            border: none;
        }
        @media (max-width: 768px) {
            #sidebar { position: absolute; z-index: 50; }
        }
        
        #main-content { transition: margin-left 0.5s cubic-bezier(0.16, 1, 0.3, 1); }

        .stagger-1 { animation-delay: 80ms; }
        .stagger-2 { animation-delay: 160ms; }
        .stagger-3 { animation-delay: 240ms; }
    </style>
</head>
<body class="text-slate-800 h-screen overflow-hidden selection:bg-senai-cyan selection:text-white relative font-sans text-sm md:text-base">
