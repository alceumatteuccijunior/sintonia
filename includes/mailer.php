<?php
// includes/mailer.php

function send_system_email($to, $subject, $message) {
    // Cabeçalhos para enviar HTML com a função mail() nativa
    $headers  = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    
    // Remetente (Opcional: Ajuste para o domínio do seu servidor)
    $headers .= "From: iaS Plataforma <no-reply@seu-servidor.com>" . "\r\n";
    
    // Template HTML básico
    $html_body = "
    <html>
    <body style='font-family: Arial, sans-serif; background-color: #f8fafc; padding: 20px; color: #334155;'>
        <div style='max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 12px; border-top: 6px solid #4f46e5; box-shadow: 0 4px 6px rgba(0,0,0,0.05);'>
            <h2 style='color: #1e293b; margin-top: 0;'>Sistema iaS</h2>
            <div style='font-size: 16px; line-height: 1.6;'>
                $message
            </div>
            <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 30px 0;'>
            <p style='color: #94a3b8; font-size: 12px; text-align: center; margin-bottom: 0;'>
                Este é um e-mail automático do iaS.<br>Por favor, não responda.
            </p>
        </div>
    </body>
    </html>
    ";
    
    // Usa a função mail nativa do PHP
    return mail($to, $subject, $html_body, $headers);
}
?>
