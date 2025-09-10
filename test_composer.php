<?php
// test_composer.php

// Habilita a exibição de todos os erros
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Teste de Autoloader do Composer</h1>";
echo "<p>Iniciando teste...</p>";

// Passo 1: Tenta incluir o autoloader
try {
    require __DIR__ . '/vendor/autoload.php';
    echo "<p style='color:green;'>SUCESSO: O arquivo 'vendor/autoload.php' foi incluído.</p>";
} catch (Throwable $e) {
    echo "<p style='color:red;'>FALHA CRÍTICA: Não foi possível incluir o 'vendor/autoload.php'. Erro: " . $e->getMessage() . "</p>";
    exit;
}

// Passo 2: Tenta verificar se uma classe específica da biblioteca existe
echo "<p>Verificando se a classe 'PublicKeyCredentialRpEntity' existe...</p>";

if (class_exists(\Webauthn\PublicKeyCredentialRpEntity::class)) {
    echo "<p style='color:green; font-weight:bold;'>TESTE BEM-SUCEDIDO!</p>";
    echo "<p>A classe 'PublicKeyCredentialRpEntity' foi encontrada com sucesso. Isso significa que o autoloader do Composer está funcionando corretamente.</p>";
} else {
    echo "<p style='color:red; font-weight:bold;'>TESTE FALHOU!</p>";
    echo "<p>A classe 'PublicKeyCredentialRpEntity' NÃO foi encontrada. Isso confirma que há um problema entre o PHP/Apache e os arquivos gerados pelo Composer.</p>";
}

echo "<hr>";
echo "<p>Versão do PHP em execução: " . phpversion() . "</p>";
?>