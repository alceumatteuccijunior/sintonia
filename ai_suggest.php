<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    echo json_encode(['error' => 'Acesso negado.']);
    exit;
}

require_once 'config.php';

$capacity = $_GET['capacity'] ?? '';
if (!$capacity || $capacity === 'N/A') {
    echo json_encode(['error' => 'Capacidade inválida ou sem dados.']);
    exit;
}

if (!defined('OPENAI_API_KEY') || OPENAI_API_KEY === '') {
    echo json_encode(['error' => 'Chave da API da OpenAI não configurada.']);
    exit;
}

// Chamar a API da OpenAI via cURL
$url = 'https://api.openai.com/v1/chat/completions';
$data = [
    'model' => 'gpt-3.5-turbo', // Usando 3.5 turbo para rapidez/custo, ou pode ser gpt-4
    'messages' => [
        [
            'role' => 'system',
            'content' => 'Você é um Assistente Pedagógico Especialista (Sintonia). Sua função é ajudar professores do SENAI a criar planos de aula corretivos curtos, diretos e gamificados. Responda usando Markdown estruturado.'
        ],
        [
            'role' => 'user',
            'content' => "Minha turma teve um desempenho muito ruim na capacidade '$capacity'. Escreva um breve plano de aula corretivo (duração: 2 horas) com atividades práticas para melhorar essa deficiência."
        ]
    ],
    'temperature' => 0.7,
    'max_tokens' => 800
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
    echo json_encode(['error' => 'Erro na conexão com OpenAI: ' . $err]);
    exit;
}

$decoded = json_decode($response, true);
if (isset($decoded['error'])) {
    echo json_encode(['error' => 'Erro da API OpenAI: ' . $decoded['error']['message']]);
    exit;
}

$suggestion = $decoded['choices'][0]['message']['content'] ?? 'Nenhuma sugestão retornada.';

echo json_encode(['suggestion' => $suggestion]);
