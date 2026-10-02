<?php
require_once 'config.php';

try {
    $pdo = getPDOConnection(true);

    // Cria a tabela caso não exista
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS module_capacities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            module_id INT,
            capacity_code VARCHAR(50) NOT NULL,
            description TEXT NOT NULL,
            FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
            UNIQUE KEY (module_id, capacity_code)
        ) ENGINE=InnoDB;
    ");

    $jsonFile = 'bancodequestoesinicial.json';
    if (!file_exists($jsonFile)) {
        die("Arquivo JSON não encontrado.");
    }

    $jsonContent = file_get_contents($jsonFile);
    $data = json_decode($jsonContent, true);
    
    $modulos = $data['prova']['modulos'] ?? [];
    $total_importados = 0;

    foreach ($modulos as $modulo) {
        $unidadeName = $modulo['nome'];
        
        $stmt = $pdo->prepare("SELECT id FROM modules WHERE name = ?");
        $stmt->execute([$unidadeName]);
        $module_db = $stmt->fetch();
        
        if ($module_db) {
            $moduleId = $module_db['id'];
            
            $capacidades = $modulo['capacidades_avaliadas'] ?? [];
            foreach ($capacidades as $cap) {
                $code = $cap['id'];
                $desc = $cap['descricao'];
                
                $stmtInsert = $pdo->prepare("
                    INSERT INTO module_capacities (module_id, capacity_code, description) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE description = VALUES(description)
                ");
                $stmtInsert->execute([$moduleId, $code, $desc]);
                $total_importados++;
            }
        }
    }

    echo "Sucesso! Total de $total_importados capacidades importadas e mapeadas.";

} catch (Exception $e) {
    echo "Erro: " . $e->getMessage();
}
