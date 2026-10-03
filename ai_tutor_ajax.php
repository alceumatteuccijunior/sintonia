<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'teacher' && $_SESSION['user_role'] !== 'admin')) {
    echo json_encode(['status' => 'error', 'message' => 'Acesso negado.']);
    exit;
}

require_once 'config.php';

if (!defined('OPENAI_API_KEY') || OPENAI_API_KEY === '') {
    echo json_encode(['status' => 'error', 'message' => 'A chave da API da OpenAI (OPENAI_API_KEY) não está configurada no config.php.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$message = $_POST['message'] ?? '';
$session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;

if (empty($message)) {
    echo json_encode(['status' => 'error', 'message' => 'Mensagem vazia.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Se é um chat novo, cria a sessão
    if (!$session_id) {
        $title = mb_substr($message, 0, 40);
        if (mb_strlen($message) > 40) $title .= '...';
        
        $stmtSess = $pdo->prepare("INSERT INTO ai_chat_sessions (user_id, title) VALUES (?, ?)");
        $stmtSess->execute([$user_id, $title]);
        $session_id = $pdo->lastInsertId();
    } else {
        // Verifica se a sessão pertence ao usuário
        $stmtCheck = $pdo->prepare("SELECT id FROM ai_chat_sessions WHERE id = ? AND user_id = ?");
        $stmtCheck->execute([$session_id, $user_id]);
        if (!$stmtCheck->fetch()) {
            throw new Exception("Sessão inválida ou sem permissão.");
        }
        $pdo->prepare("UPDATE ai_chat_sessions SET updated_at = NOW() WHERE id = ?")->execute([$session_id]);
    }

    // Salva a mensagem do usuário no BD
    $stmtMsg = $pdo->prepare("INSERT INTO ai_chat_messages (session_id, role, content) VALUES (?, 'user', ?)");
    $stmtMsg->execute([$session_id, $message]);

    // Busca o histórico do chat da sessão
    $stmtHist = $pdo->prepare("SELECT role, content FROM ai_chat_messages WHERE session_id = ? ORDER BY id ASC");
    $stmtHist->execute([$session_id]);
    $history = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

    // Estatísticas básicas para o Prompt
    $userName = explode(' ', trim($_SESSION['user_name']))[0];
    
    // Buscar todas as turmas que este professor tem acesso (ou todas se for admin)
    if ($_SESSION['user_role'] === 'admin') {
        $stmtClasses = $pdo->query("SELECT id, name FROM classes");
    } else {
        // Professor (simplificado: turmas onde ele aplicou sprints ou está vinculado)
        $stmtClasses = $pdo->prepare("SELECT DISTINCT c.id, c.name FROM classes c JOIN sprints s ON c.id = s.class_id WHERE s.teacher_id = ?");
        $stmtClasses->execute([$user_id]);
    }
    $turmas = $stmtClasses->fetchAll(PDO::FETCH_ASSOC);
    
    $dados_turmas = [];
    foreach ($turmas as $t) {
        $class_id = $t['id'];
        
        // Conta alunos
        $stmtAlunos = $pdo->prepare("SELECT count(student_id) FROM class_students WHERE class_id = ?");
        $stmtAlunos->execute([$class_id]);
        $qtd_alunos = $stmtAlunos->fetchColumn();
        
        // Pega acurácia geral da turma calculando direto das respostas
        $stmtAcc = $pdo->prepare("
            SELECT 
                count(*) as total_respostas,
                sum(is_correct) as total_acertos
            FROM student_answers sa
            JOIN class_students cs ON sa.student_id = cs.student_id
            WHERE cs.class_id = ?
        ");
        $stmtAcc->execute([$class_id]);
        $accData = $stmtAcc->fetch();
        $accuracy = 0;
        if ($accData && $accData['total_respostas'] > 0) {
            $accuracy = round(($accData['total_acertos'] / $accData['total_respostas']) * 100, 1);
        }
        
        $dados_turmas[] = [
            'nome_da_turma' => $t['name'],
            'quantidade_alunos' => (int)$qtd_alunos,
            'taxa_acerto_geral_porcentagem' => $accuracy
        ];
    }
    
    $json_dados = json_encode($dados_turmas, JSON_UNESCAPED_UNICODE);

    // Prompt de Sistema Profundo e Restritivo
    $systemContent = "Você é o 'Tutor iaS', uma Inteligência Artificial Pedagógica conectada internamente à base de dados do sistema educacional iaS.
Você está conversando com o(a) gestor(a)/professor(a) {$userName}.

REGRA DE OURO E INVIOLÁVEL: 
Você NÃO pode inventar, alucinar ou supor dados. Você DEVE atuar ESTRITAMENTE em cima do seu banco de dados atual, que foi extraído agora e está formatado em JSON abaixo. 
Se o usuário perguntar sobre uma turma que não está no JSON, ou sobre um aluno específico cujo dado não está aqui, você DEVE dizer que não tem acesso a essa informação no momento.

DADOS REiaS E ATUiaS DO PROFESSOR:
{$json_dados}

Seu papel: Analisar as turmas acima. Se uma turma tiver taxa de acerto abaixo de 50%, considere-a em RISCO. Se estiver entre 50% e 69%, ATENÇÃO. Acima de 70%, ALTO DESEMPENHO.
Você pode sugerir dinâmicas, analisar o cenário dessas turmas e ajudar a planejar aulas. Use Markdown rigoroso para embelezar as respostas.";

    $apiMessages = [
        ['role' => 'system', 'content' => $systemContent]
    ];

    // Monta payload de mensagens pro ChatGPT
    foreach ($history as $h) {
        if ($h['role'] !== 'system') {
            $apiMessages[] = [
                'role' => $h['role'],
                'content' => $h['content']
            ];
        }
    }

    $pdo->commit(); // Commita as msgs do user antes da API para evitar travamento

    // Chamada à OpenAI
    $url = 'https://api.openai.com/v1/chat/completions';
    $data = [
        'model' => 'gpt-3.5-turbo',
        'messages' => $apiMessages,
        'temperature' => 0.7,
        'max_tokens' => 1200
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        throw new Exception('Erro de rede ao contatar a OpenAI: ' . $err);
    }

    $decoded = json_decode($response, true);
    if (isset($decoded['error'])) {
        throw new Exception('Erro da IA: ' . $decoded['error']['message']);
    }

    $ai_response = $decoded['choices'][0]['message']['content'] ?? 'Nenhuma resposta foi gerada.';

    // Salva a resposta da IA no banco
    $stmtAiMsg = $pdo->prepare("INSERT INTO ai_chat_messages (session_id, role, content) VALUES (?, 'assistant', ?)");
    $stmtAiMsg->execute([$session_id, $ai_response]);

    echo json_encode([
        'status' => 'success',
        'session_id' => $session_id,
        'response' => $ai_response
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
