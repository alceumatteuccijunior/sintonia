<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    echo json_encode(['error' => 'Acesso negado.']);
    exit;
}

require_once 'config.php';

if (!defined('OPENAI_API_KEY') || OPENAI_API_KEY === '') {
    echo json_encode(['error' => 'Chave da API da OpenAI não configurada.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['student_name'])) {
    echo json_encode(['error' => 'Dados inválidos.']);
    exit;
}

// Prepare summary text for the prompt
$prompt = "Você é um Consultor Pedagógico Especialista em Aprendizagem. Analise a evolução (histórico) do aluno '{$data['student_name']}' da turma '{$data['class_name']}'.\n\n";

$prompt .= "1. EVOLUÇÃO GERAL (Por Sprint):\n";
foreach($data['sprints'] as $idx => $sprint) {
    $prompt .= "- Sprint " . ($idx+1) . " ({$sprint['name']}): " . $data['general'][$idx] . "% de acerto.\n";
}

$prompt .= "\n2. MÓDULOS DESTAQUE (Última avaliação):\n";
$prompt .= "- Melhor Módulo Atual: {$data['best_module']}\n";
$prompt .= "- Pior Módulo Atual: {$data['worst_module']}\n";

$prompt .= "\n3. CAPACIDADES TÉCNICAS (Última avaliação):\n";
$prompt .= "- Melhor Capacidade: {$data['best_capacity']}\n";
$prompt .= "- Capacidade de Maior Atenção: {$data['worst_capacity']}\n";

$prompt .= "\nEscreva um RESUMO EXECUTIVO (em no máximo 3 parágrafos curtos) focado em: 
1. Como está a curva de aprendizagem do aluno (ele evoluiu, estagnou ou piorou?). 
2. Quais são as competências mais fortes e mais fracas dele atualmente. 
3. Qual recomendação de estudo você daria a ele hoje.
Use uma linguagem profissional e direta para apresentação de slides.";

$url = 'https://api.openai.com/v1/chat/completions';
$postData = [
    'model' => 'gpt-3.5-turbo',
    'messages' => [
        [
            'role' => 'system',
            'content' => 'Você é um Assistente Pedagógico (Sintonia). Responda apenas com o texto do relatório executivo, sem introduções extras.'
        ],
        [
            'role' => 'user',
            'content' => $prompt
        ]
    ],
    'temperature' => 0.7,
    'max_tokens' => 500
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . OPENAI_API_KEY
]);

$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['error' => 'Erro na conexão com OpenAI: ' . $err]);
    exit;
}

$decoded = json_decode($response, true);
if (isset($decoded['error'])) {
    echo json_encode(['error' => 'Erro da API OpenAI: ' . $decoded['error']['message']]);
    exit;
}

$insight = $decoded['choices'][0]['message']['content'] ?? 'Nenhum insight gerado.';
echo json_encode(['insight' => $insight]);
