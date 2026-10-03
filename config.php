<?php
// config.php
// Configurações e conexão com o banco de dados do sistema iaS
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('DB_HOST', 'localhost');      // Host do MySQL
define('DB_NAME', 'ia191379_iaS');    // Nome do banco de dados que será criado
define('DB_USER', 'ia191379_iaS');           // Usuário do MySQL (altere se necessário)
define('DB_PASS', 'bCUQV2S5dGcT96K4cg5C');               // Senha do MySQL (altere se necessário)
define('OPENAI_API_KEY', 'sua_chave_aqui');

// define('DB_HOST', 'localhost');      // Host do MySQL
// define('DB_NAME', 'castelob_sintonia2');    // Nome do banco de dados que será criado
// define('DB_USER', 'castelob_sintonia2');           // Usuário do MySQL (altere se necessário)
// define('DB_PASS', 'huAhbFrMDgNa56yhDvA8');               // Senha do MySQL (altere se necessário)
// define('OPENAI_API_KEY', 'sua_chave_aqui');



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
?>