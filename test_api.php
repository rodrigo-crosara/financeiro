<?php
// test_api.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Teste de Construção Incremental da API</h1>";

// --- NÍVEL 1: O Autoloader (Sabemos que funciona) ---
require __DIR__ . '/vendor/autoload.php';
echo "<p style='color:green;'>NÍVEL 1 SUCESSO: Autoloader incluído.</p>";


// --- NÍVEL 2: As Declarações 'use' ---
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialSourceRepository;
use Webauthn\PublicKeyCredentialUserEntity;
echo "<p style='color:green;'>NÍVEL 2 SUCESSO: Declarações 'use' processadas.</p>";


// --- NÍVEL 3: A Declaração da Classe ---
class PDOCredentialSourceRepository implements PublicKeyCredentialSourceRepository {
    // A classe está vazia por enquanto, apenas para testar a declaração.
    public function findOneByCredentialId(string $id): ?PublicKeyCredentialSource { return null; }
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $user): array { return []; }
    public function saveCredentialSource(PublicKeyCredentialSource $source): void {}
}
echo "<p style='color:green;'>NÍVEL 3 SUCESSO: A classe foi declarada corretamente.</p>";


?>