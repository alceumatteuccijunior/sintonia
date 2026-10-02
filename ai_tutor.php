<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'teacher' && $_SESSION['user_role'] !== 'admin')) {
    header("Location: index.php");
    exit;
}

require 'config.php';

$user_id = $_SESSION['user_id'];
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : null;

// Verifica se quer criar um novo chat via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'new_chat') {
    header("Location: ai_tutor.php");
    exit;
}

// Deletar chat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_chat') {
    $del_id = (int)$_POST['delete_id'];
    $stmtDel = $pdo->prepare("DELETE FROM ai_chat_sessions WHERE id = ? AND user_id = ?");
    $stmtDel->execute([$del_id, $user_id]);
    header("Location: ai_tutor.php");
    exit;
}

// Busca histórico de sessões
$stmtSessions = $pdo->prepare("SELECT id, title, created_at FROM ai_chat_sessions WHERE user_id = ? ORDER BY updated_at DESC");
$stmtSessions->execute([$user_id]);
$sessions = $stmtSessions->fetchAll();

// Busca as mensagens da sessão atual
$messages = [];
$current_session_title = "Nova Conversa";
if ($session_id) {
    $stmtCheck = $pdo->prepare("SELECT title FROM ai_chat_sessions WHERE id = ? AND user_id = ?");
    $stmtCheck->execute([$session_id, $user_id]);
    $sessData = $stmtCheck->fetch();
    
    if ($sessData) {
        $current_session_title = $sessData['title'];
        $stmtMsgs = $pdo->prepare("SELECT role, content, created_at FROM ai_chat_messages WHERE session_id = ? AND role != 'system' ORDER BY id ASC");
        $stmtMsgs->execute([$session_id]);
        $messages = $stmtMsgs->fetchAll();
    } else {
        $session_id = null; // Inválido ou de outro usuário
    }
}

$currentPage = 'ai_tutor';
$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>';
include 'includes/header.php';
?>

<div id="app-screen" class="absolute inset-0 z-40 flex w-full h-full bg-[#F8FAFC] overflow-hidden">
    
    <!-- Fundo Vivo -->
    <div class="fixed inset-0 w-full h-full overflow-hidden pointer-events-none z-0">
        <div class="absolute top-[-10%] right-[-5%] w-[600px] h-[600px] bg-indigo-500/10 rounded-full mix-blend-multiply filter blur-[100px] opacity-70 animate-blob"></div>
        <div class="absolute bottom-[-10%] left-[-5%] w-[600px] h-[600px] bg-senai-cyan/10 rounded-full mix-blend-multiply filter blur-[100px] opacity-50 animate-blob" style="animation-delay: -3s;"></div>
    </div>

    <?php include 'includes/sidebar.php'; ?>

    <main id="main-content" class="flex-1 flex flex-col relative h-full w-full">
        
        <!-- Header da Tela -->
        <header class="h-16 flex items-center justify-between px-4 md:px-6 bg-white/50 backdrop-blur-md border-b border-white/60 z-20 shrink-0">
            <div class="flex items-center gap-4">
                <button onclick="toggleSidebar()" class="w-10 h-10 flex items-center justify-center bg-white hover:bg-slate-50 rounded-xl shadow-sm border border-slate-100 transition-all text-slate-600">
                    <i id="sidebar-icon" class="ph-bold ph-list text-xl"></i>
                </button>
                <div class="flex items-center gap-2 text-indigo-600">
                    <div class="w-8 h-8 rounded-lg bg-indigo-100 flex items-center justify-center">
                        <i class="ph-fill ph-robot text-lg"></i>
                    </div>
                    <h1 class="font-bold text-lg hidden md:block tracking-tight">Tutor de IA Pedagógico</h1>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <button onclick="openHelpModal('Tutor de IA Pedagógico', 'O Tutor é uma Inteligência Artificial conectada ao seu banco de dados do aiS.<br><br><b>O que você pode perguntar:</b><br>- Como está o desempenho geral das minhas turmas?<br>- Quais são as maiores deficiências dos meus alunos?<br>- Crie um plano de aula sobre a capacidade X.<br><br>Ele lembra do histórico da conversa nesta sessão!')" class="w-9 h-9 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors shadow-inner" title="Como Usar">
                    <i class="ph-bold ph-question text-lg"></i>
                </button>
            </div>
        </header>

        <!-- Layout do Chat: Esquerda (Histórico) | Direita (Conversa) -->
        <div class="flex-1 flex overflow-hidden w-full max-w-7xl mx-auto pt-4 pb-4 px-4 gap-4 z-10">
            
            <!-- Painel Esquerdo: Histórico de Conversas -->
            <div class="hidden md:flex flex-col w-64 lg:w-80 bg-white/80 backdrop-blur-xl border border-white rounded-3xl shadow-sm overflow-hidden shrink-0">
                <div class="p-4 border-b border-slate-100 bg-white/50">
                    <a href="ai_tutor.php" class="w-full flex items-center justify-center gap-2 bg-indigo-600 text-white px-4 py-2.5 rounded-xl font-bold shadow-md hover:bg-indigo-700 transition-colors">
                        <i class="ph-bold ph-plus"></i> Novo Chat
                    </a>
                </div>
                <div class="flex-1 overflow-y-auto p-3 space-y-2 no-scrollbar">
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest px-2 mb-2">Histórico</p>
                    
                    <?php if(empty($sessions)): ?>
                        <div class="text-center py-6 text-slate-400">
                            <i class="ph-thin ph-chats text-3xl mb-2 opacity-50"></i>
                            <p class="text-sm font-medium">Nenhum chat salvo.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($sessions as $sess): ?>
                            <div class="group relative">
                                <a href="ai_tutor.php?session_id=<?= $sess['id'] ?>" class="block w-full text-left px-4 py-3 rounded-xl transition-all <?= $session_id == $sess['id'] ? 'bg-indigo-50 text-indigo-700 font-bold border border-indigo-100' : 'text-slate-600 font-medium hover:bg-slate-50 border border-transparent' ?>">
                                    <div class="truncate text-sm pr-6"><?= htmlspecialchars($sess['title']) ?></div>
                                    <div class="text-[10px] text-slate-400 font-normal mt-1 opacity-70"><?= date('d/m/Y H:i', strtotime($sess['created_at'])) ?></div>
                                </a>
                                <!-- Botão Excluir que aparece no hover -->
                                <form method="POST" class="absolute right-2 top-1/2 -translate-y-1/2 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <input type="hidden" name="action" value="delete_chat">
                                    <input type="hidden" name="delete_id" value="<?= $sess['id'] ?>">
                                    <button type="submit" class="w-6 h-6 bg-red-100 text-red-500 rounded-md flex items-center justify-center hover:bg-red-500 hover:text-white transition-colors" title="Excluir" onclick="return confirm('Tem certeza que deseja excluir esta conversa?');">
                                        <i class="ph-bold ph-trash text-xs"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Painel Direito: Janela de Conversa -->
            <div class="flex-1 flex flex-col bg-white/80 backdrop-blur-xl border border-white rounded-3xl shadow-sm overflow-hidden relative">
                
                <!-- Chat Window -->
                <div id="chat-window" class="flex-1 overflow-y-auto p-4 md:p-8 space-y-6 scroll-smooth">
                    
                    <?php if (empty($messages) && !$session_id): ?>
                        <!-- Welcome Screen for New Chat -->
                        <div class="h-full flex flex-col items-center justify-center text-center px-4 animate-slide-up">
                            <div class="w-20 h-20 bg-gradient-to-br from-indigo-500 to-senai-cyan rounded-3xl shadow-lg flex items-center justify-center text-white mb-6 relative">
                                <div class="absolute inset-0 bg-white/20 rounded-3xl mix-blend-overlay"></div>
                                <i class="ph-fill ph-robot text-4xl relative z-10"></i>
                            </div>
                            <h2 class="text-2xl font-bold text-slate-800 mb-2">Como posso ajudar na sua gestão pedagógica?</h2>
                            <p class="text-slate-500 font-medium mb-8 max-w-md">Sou uma inteligência artificial treinada no aiS e conectada diretamente aos dados das suas turmas e simulados.</p>
                            
                            <!-- Sugestões de Prompts -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 w-full max-w-2xl">
                                <button onclick="setPrompt('Resuma o desempenho global das minhas turmas na última semana.')" class="bg-white border border-slate-200 hover:border-indigo-300 hover:shadow-md p-4 rounded-2xl text-left transition-all group">
                                    <h4 class="font-bold text-slate-700 text-sm mb-1 group-hover:text-indigo-600 transition-colors">Resumo de Desempenho</h4>
                                    <p class="text-xs text-slate-500">Como minhas turmas estão se saindo?</p>
                                </button>
                                <button onclick="setPrompt('Quais são as competências e capacidades que meus alunos têm mais dificuldade?')" class="bg-white border border-slate-200 hover:border-indigo-300 hover:shadow-md p-4 rounded-2xl text-left transition-all group">
                                    <h4 class="font-bold text-slate-700 text-sm mb-1 group-hover:text-indigo-600 transition-colors">Mapeamento de Gaps</h4>
                                    <p class="text-xs text-slate-500">Descobrir pontos fracos.</p>
                                </button>
                                <button onclick="setPrompt('Crie um plano de aula interativo de 2 horas focado em Lógica de Programação para iniciantes.')" class="bg-white border border-slate-200 hover:border-indigo-300 hover:shadow-md p-4 rounded-2xl text-left transition-all group">
                                    <h4 class="font-bold text-slate-700 text-sm mb-1 group-hover:text-indigo-600 transition-colors">Criar Plano de Aula</h4>
                                    <p class="text-xs text-slate-500">Gerar material de estudo focado.</p>
                                </button>
                                <button onclick="setPrompt('Elabore 3 estratégias de engajamento para alunos que estão com taxa de acerto abaixo de 50%.')" class="bg-white border border-slate-200 hover:border-indigo-300 hover:shadow-md p-4 rounded-2xl text-left transition-all group">
                                    <h4 class="font-bold text-slate-700 text-sm mb-1 group-hover:text-indigo-600 transition-colors">Estratégias de Engajamento</h4>
                                    <p class="text-xs text-slate-500">Recuperação de alunos em risco.</p>
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Loop de Mensagens Salvas -->
                        <?php foreach($messages as $msg): ?>
                            <?php if($msg['role'] === 'user'): ?>
                                <!-- User Message -->
                                <div class="flex justify-end animate-slide-up">
                                    <div class="bg-indigo-600 text-white max-w-[85%] md:max-w-[75%] rounded-3xl rounded-tr-sm px-5 py-4 shadow-sm">
                                        <p class="whitespace-pre-wrap text-sm md:text-base font-medium"><?= htmlspecialchars($msg['content']) ?></p>
                                    </div>
                                </div>
                            <?php elseif($msg['role'] === 'assistant'): ?>
                                <!-- AI Message -->
                                <div class="flex justify-start gap-3 animate-slide-up">
                                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-500 to-senai-cyan flex items-center justify-center text-white shrink-0 mt-1 shadow-sm">
                                        <i class="ph-fill ph-robot text-sm"></i>
                                    </div>
                                    <div class="bg-white border border-slate-100 text-slate-700 max-w-[85%] md:max-w-[80%] rounded-3xl rounded-tl-sm px-6 py-5 shadow-sm prose prose-sm md:prose-base prose-indigo max-w-none ai-content-block">
                                        <!-- O JS vai processar o Markdown -->
                                        <textarea class="hidden raw-markdown"><?= htmlspecialchars($msg['content']) ?></textarea>
                                        <div class="rendered-markdown"></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Div invisível para ancorar o scroll no final -->
                    <div id="chat-bottom"></div>
                </div>

                <!-- Barra de Digitação Dinâmica -->
                <div class="p-4 bg-white/50 border-t border-slate-100 backdrop-blur-md">
                    <form id="chat-form" class="relative max-w-4xl mx-auto flex items-end gap-2 bg-white rounded-3xl border border-slate-200 p-2 shadow-sm focus-within:border-indigo-400 focus-within:ring-4 focus-within:ring-indigo-100 transition-all">
                        
                        <!-- Botão Anexar Arquivo (Futuro) -->
                        <button type="button" class="w-10 h-10 rounded-full flex items-center justify-center text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors shrink-0 mb-1" title="Anexar Documento (Em breve)">
                            <i class="ph-bold ph-paperclip text-lg"></i>
                        </button>
                        
                        <textarea id="chat-input" rows="1" placeholder="Pergunte qualquer coisa ao aiS..." class="flex-1 bg-transparent border-none focus:ring-0 resize-none py-3 text-slate-700 font-medium no-scrollbar max-h-32" style="min-height: 48px;"></textarea>
                        
                        <input type="hidden" id="session-id" value="<?= $session_id ? $session_id : '' ?>">
                        
                        <button type="submit" id="send-btn" class="w-10 h-10 rounded-full bg-indigo-600 flex items-center justify-center text-white hover:bg-indigo-700 hover:scale-105 transition-all shadow-md shrink-0 mb-1 disabled:opacity-50 disabled:hover:scale-100">
                            <i class="ph-bold ph-paper-plane-right text-lg"></i>
                        </button>
                    </form>
                    <p class="text-center text-[10px] text-slate-400 font-medium mt-3">A IA pode cometer erros. Considere verificar informações importantes baseadas no sistema.</p>
                </div>

            </div>
        </div>
    </main>
</div>

<style>
    /* Ajustes extras pro prose do Markdown */
    .prose p { margin-top: 0.5em; margin-bottom: 0.5em; }
    .prose pre { background-color: #1e293b; color: #f8fafc; border-radius: 0.75rem; padding: 1rem; }
    .prose code { color: #4f46e5; background: #e0e7ff; padding: 0.125rem 0.25rem; border-radius: 0.25rem; }
    .prose pre code { color: inherit; background: none; padding: 0; }
</style>

<script>
    const chatWindow = document.getElementById('chat-window');
    const chatForm = document.getElementById('chat-form');
    const chatInput = document.getElementById('chat-input');
    const sendBtn = document.getElementById('send-btn');
    const sessionIdInput = document.getElementById('session-id');

    // Auto-resize do Textarea
    chatInput.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
        if(this.value.trim() === '') {
            sendBtn.disabled = true;
        } else {
            sendBtn.disabled = false;
        }
    });

    // Enviar com Enter (sem Shift)
    chatInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if(this.value.trim() !== '') chatForm.dispatchEvent(new Event('submit'));
        }
    });

    function setPrompt(text) {
        chatInput.value = text;
        chatInput.dispatchEvent(new Event('input'));
        chatInput.focus();
    }

    // Scroll para o fim
    function scrollToBottom() {
        document.getElementById('chat-bottom').scrollIntoView({ behavior: 'smooth' });
    }

    // Processar Markdown nas mensagens carregadas do BD
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.ai-content-block').forEach(block => {
            const raw = block.querySelector('.raw-markdown').value;
            block.querySelector('.rendered-markdown').innerHTML = marked.parse(raw);
        });
        scrollToBottom();
        sendBtn.disabled = true;
    });

    // Lidar com o Submit via AJAX
    chatForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const message = chatInput.value.trim();
        if(!message) return;
        
        const sessionId = sessionIdInput.value;
        
        // Remove tela de boas vindas se existir
        const welcomeScreen = chatWindow.querySelector('.h-full.flex-col.items-center');
        if(welcomeScreen) welcomeScreen.remove();

        // 1. Adicionar mensagem do usuário na tela
        appendMessage('user', message);
        chatInput.value = '';
        chatInput.style.height = 'auto';
        sendBtn.disabled = true;
        scrollToBottom();

        // 2. Adicionar balão de "Digitando..." da IA
        const loadingId = 'loading-' + Date.now();
        appendLoading(loadingId);
        scrollToBottom();

        // 3. Fazer o Request pro Backend
        try {
            const formData = new FormData();
            formData.append('message', message);
            if(sessionId) formData.append('session_id', sessionId);

            const response = await fetch('ai_tutor_ajax.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            
            // Remove o balão de carregamento
            document.getElementById(loadingId).remove();

            if (data.status === 'success') {
                // Atualiza o Session ID caso seja um novo chat
                if(!sessionId && data.session_id) {
                    sessionIdInput.value = data.session_id;
                    // Opcionalmente podemos dar um replaceState na URL para o reload funcionar bem
                    window.history.replaceState({}, '', 'ai_tutor.php?session_id=' + data.session_id);
                }
                
                // Renderiza resposta da IA
                appendMessage('assistant', data.response);
                scrollToBottom();
            } else {
                appendMessage('system', '❌ Erro ao comunicar com a IA: ' + data.message);
                scrollToBottom();
            }

        } catch (error) {
            document.getElementById(loadingId).remove();
            appendMessage('system', '❌ Ocorreu um erro de rede. Tente novamente.');
            scrollToBottom();
        }
    });

    function appendMessage(role, content) {
        const div = document.createElement('div');
        div.className = 'animate-slide-up ' + (role === 'user' ? 'flex justify-end' : 'flex justify-start gap-3');
        
        if (role === 'user') {
            div.innerHTML = `
                <div class="bg-indigo-600 text-white max-w-[85%] md:max-w-[75%] rounded-3xl rounded-tr-sm px-5 py-4 shadow-sm">
                    <p class="whitespace-pre-wrap text-sm md:text-base font-medium">${escapeHtml(content)}</p>
                </div>
            `;
        } else if (role === 'assistant') {
            // Processa Markdown via JS no frontend
            const parsedContent = marked.parse(content);
            div.innerHTML = `
                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-500 to-senai-cyan flex items-center justify-center text-white shrink-0 mt-1 shadow-sm">
                    <i class="ph-fill ph-robot text-sm"></i>
                </div>
                <div class="bg-white border border-slate-100 text-slate-700 max-w-[85%] md:max-w-[80%] rounded-3xl rounded-tl-sm px-6 py-5 shadow-sm prose prose-sm md:prose-base prose-indigo max-w-none">
                    ${parsedContent}
                </div>
            `;
        } else {
            // Erros do sistema
            div.innerHTML = `
                <div class="bg-red-50 border border-red-100 text-red-600 max-w-full rounded-2xl px-5 py-3 text-sm font-bold shadow-sm mx-auto">
                    ${escapeHtml(content)}
                </div>
            `;
        }
        
        document.getElementById('chat-bottom').before(div);
    }

    function appendLoading(id) {
        const div = document.createElement('div');
        div.id = id;
        div.className = 'flex justify-start gap-3 animate-fade-in';
        div.innerHTML = `
            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-500 to-senai-cyan flex items-center justify-center text-white shrink-0 mt-1 shadow-sm">
                <i class="ph-fill ph-robot text-sm"></i>
            </div>
            <div class="bg-white border border-slate-100 rounded-3xl rounded-tl-sm px-6 py-5 shadow-sm flex items-center gap-2">
                <div class="w-2 h-2 bg-indigo-400 rounded-full animate-bounce"></div>
                <div class="w-2 h-2 bg-indigo-400 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
                <div class="w-2 h-2 bg-indigo-400 rounded-full animate-bounce" style="animation-delay: 0.4s"></div>
            </div>
        `;
        document.getElementById('chat-bottom').before(div);
    }

    // Util para prevenir XSS rápido
    function escapeHtml(unsafe) {
        return unsafe
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }
</script>

<?php include 'includes/footer.php'; ?>
