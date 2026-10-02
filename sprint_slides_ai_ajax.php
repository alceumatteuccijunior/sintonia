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
if (!$data || !isset($data['sprint_name'])) {
    echo json_encode(['error' => 'Dados inválidos.']);
    exit;
}

$prompt = "Você é um Consultor Pedagógico Especialista. Analise os seguintes dados de uma prova (Sprint) chamada '{$data['sprint_name']}' da turma '{$data['class_name']}'.\n\n";
$prompt .= "1. SAÚDE DA TURMA:\n- Média de acertos: {$data['avg_score']}\n- Alunos avaliados: {$data['total_students']}\n- Questões na prova: {$data['total_questions']}\n\n";
$prompt .= "2. PIORES CAPACIDADES AVALIADAS:\n";
foreach($data['worst_capacities'] as $c) {
    $prompt .= "- {$c['capacity_code']} ({$c['capacity_desc']}): ".round($c['rate'], 1)."% de acerto\n";
}
$prompt .= "\n3. MELHORES CAPACIDADES:\n";
foreach($data['best_capacities'] as $c) {
    $prompt .= "- {$c['capacity_code']} ({$c['capacity_desc']}): ".round($c['rate'], 1)."% de acerto\n";
}

$prompt .= "\nEscreva um RESUMO EXECUTIVO (em no máximo 3 parágrafos curtos) focado em: O que observar com esses dados? Quais pontos fortes? Quais fragilidades devem ser atacadas imediatamente pelo professor? Use uma linguagem profissional e direta para apresentação de slides.";

$url = 'https://api.openai.com/v1/chat/completions';
$postData = [
    'model' => 'gpt-3.5-turbo',
    'messages' => [
        [
            'role' => 'system',
            'content' => 'Você é um Assistente Pedagógico (aiS). Responda apenas com o texto do relatório executivo, sem introduções extras.'
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
