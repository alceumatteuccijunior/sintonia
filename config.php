<?php
// config.php
// Configurações e conexão com o banco de dados do sistema iaS
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Fuso horário padrão do Brasil (Horário de Brasília)
date_default_timezone_set('America/Sao_Paulo');

define('DB_HOST', 'localhost');      // Host do MySQL
define('DB_NAME', 'ia191379_ais');   // Nome do banco de dados
define('DB_USER', 'ia191379_ais');   // Usuário do MySQL
define('DB_PASS', 'bCUQV2S5dGcT96K4cg5C'); // Senha do MySQL

// 1. Carrega chave local se existir (config.local.php não é sobrescrito pelo Git/cPanel)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

/**
 * Função para retornar a conexão PDO
 * @param bool $connectToDb Se falso, conecta apenas ao MySQL (usado para criar o banco na primeira vez)
 */
function getPDOConnection($connectToDb = true)
{
    $dsn = "mysql:host=" . DB_HOST . ";charset=utf8mb4";

    if ($connectToDb) {
        $dsn .= ";dbname=" . DB_NAME;
    }

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        try {
            $pdo->exec("SET time_zone = '-03:00'");
        } catch (Exception $tzEx) {}
        return $pdo;
    } catch (PDOException $e) {
        // Em produção, não exiba detalhes do erro na tela.
        die("Falha na conexão com o Banco de Dados: " . $e->getMessage());
    }
}

// Cria uma variável $pdo para uso nos scripts (tenta conectar ao DB diretamente)
// Caso o banco não exista ainda (1049), ele ignora para não dar erro fatal antes do setup
try {
    $pdo = getPDOConnection(true);
} catch (Exception $e) {
    // Banco possivelmente não existe, o setup cuidará disso
    $pdo = null;
}

// 2. Se OPENAI_API_KEY ainda não foi definida via config.local.php, busca na tabela system_settings do banco
if (!defined('OPENAI_API_KEY')) {
    $dbOpenAiKey = '';
    if ($pdo) {
        try {
            $stmtKey = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'openai_api_key' LIMIT 1");
            if ($stmtKey) {
                $val = trim((string)$stmtKey->fetchColumn());
                if ($val !== '' && $val !== 'sua_chave_aqui') {
                    $dbOpenAiKey = $val;
                }
            }
        } catch (Exception $e) {
            // Tabela system_settings ainda não criada; será criada automaticamente quando configurada
        }
    }
    define('OPENAI_API_KEY', $dbOpenAiKey);
}
?>
