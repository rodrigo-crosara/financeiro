<?php
// api.php - A Versão Correta (com exit;)

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Carrega o autoloader do Composer.
require __DIR__ . '/vendor/autoload.php';

// Inicia a sessão ANTES de qualquer outro output.
session_start();

// Define o cabeçalho da resposta como JSON.
header("Content-Type: application/json");

// --- CONFIGURAÇÃO DO BANCO DE DADOS (PDO) ---
$host = 'localhost';
$db = 'financas_pessoais';
$user = 'seust8890'; // Seu usuário do banco de dados
$pass = 'SUA_SENHA_AQUI'; // <<< MUDE AQUI PARA SUA SENHA REAL
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Falha na conexão com o banco de dados: ' . $e->getMessage()]);
    exit; // Pare aqui em caso de falha de conexão
}

// --- LÓGICA DA API ---
$action = $_REQUEST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

switch ($action) {
    case 'getRegisterChallenge':
    case 'registerUser':
    case 'getLoginChallenge':
    case 'loginUser':
        // A lógica de autenticação é encapsulada para garantir que as classes só sejam carregadas quando necessário.
        import_auth_classes();
        $rpEntity = new \Webauthn\PublicKeyCredentialRpEntity('Painel Financeiro', 'seustyle.net');
        $repository = new PDORepository($pdo);
        $attestationStatementSupportManager = new \Webauthn\AttestationStatement\AttestationStatementSupportManager([new \Webauthn\AttestationStatement\NoneAttestationStatementSupport()]);
        $authenticatorAttestationResponseValidator = new \Webauthn\AuthenticatorAttestationResponseValidator($attestationStatementSupportManager, $repository);
        $authenticatorAssertionResponseValidator = new \Webauthn\AuthenticatorAssertionResponseValidator($repository);
        handle_auth_action($action, $pdo, $input, $rpEntity, $repository, $authenticatorAttestationResponseValidator, $authenticatorAssertionResponseValidator);
        break;

    case 'checkLogin':
        if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
            echo json_encode(['loggedIn' => true, 'username' => $_SESSION['username']]);
        } else {
            echo json_encode(['loggedIn' => false]);
        }
        exit; // << A CORREÇÃO CRÍTICA ESTÁ AQUI

    case 'logout':
        session_destroy();
        echo json_encode(['success' => true]);
        exit; // << BOA PRÁTICA ADICIONADA AQUI

    case 'loadAllData':
        if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'Autenticação necessária.']); exit; }
        load_all_data($pdo, $_SESSION['user_id']);
        break;
        
    case 'processSyncQueue':
        if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'Autenticação necessária.']); exit; }
        process_sync_queue($pdo, $_SESSION['user_id'], $input);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Ação não encontrada.']);
        exit; // << BOA PRÁTICA ADICIONADA AQUI
}

// --- FUNÇÕES AUXILIARES ---

function import_auth_classes() {
    class_exists(\Webauthn\PublicKeyCredentialLoader::class);
    class_exists(\Webauthn\CredentialSourceRepository::class);
}

class PDORepository implements \Webauthn\PublicKeyCredentialLoader, \Webauthn\CredentialSourceRepository {
    private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?\Webauthn\PublicKeyCredentialSource {
        $stmt = $this->pdo->prepare('SELECT * FROM user_authenticators WHERE credential_id_base64url = ?');
        $stmt->execute([base64_encode($publicKeyCredentialId)]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? \Webauthn\PublicKeyCredentialSource::createFromArray($data) : null;
    }

    public function findAllForUserEntity(\Webauthn\PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array {
        $stmt = $this->pdo->prepare('SELECT * FROM user_authenticators a JOIN users u ON a.user_id = u.id WHERE u.user_handle = ?');
        $stmt->execute([$publicKeyCredentialUserEntity->getId()]);
        return array_map(fn($data) => \Webauthn\PublicKeyCredentialSource::createFromArray($data), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function saveCredentialSource(\Webauthn\PublicKeyCredentialSource $publicKeyCredentialSource): void {
        $data = $publicKeyCredentialSource->jsonSerialize();
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE user_handle = ?');
        $stmt->execute([$data['userHandle']]);
        $userId = $stmt->fetchColumn();
        if (!$userId) { throw new Exception("Usuário não encontrado para salvar a credencial."); }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM user_authenticators WHERE credential_id_base64url = ?");
        $stmt->execute([base64_encode($data['publicKeyCredentialId'])]);
        if ($stmt->fetchColumn() > 0) {
            $stmt = $this->pdo->prepare('UPDATE user_authenticators SET sign_count = ? WHERE credential_id_base64url = ?');
            $stmt->execute([$data['counter'], base64_encode($data['publicKeyCredentialId'])]);
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO user_authenticators (user_id, credential_id_base64url, public_key_pem, sign_count, transports) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$userId, base64_encode($data['publicKeyCredentialId']), $data['credentialPublicKey'], $data['counter'], json_encode($data['transports'])]);
        }
    }
}

function handle_auth_action($action, $pdo, $input, $rpEntity, $repository, $attestationValidator, $assertionValidator) {
    switch ($action) {
        case 'getRegisterChallenge':
            $username = $_GET['username'] ?? '';
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) { echo json_encode(['error' => 'Nome de usuário já existe.']); exit; }
            
            $userHandle = bin2hex(random_bytes(32));
            $userEntity = new \Webauthn\PublicKeyCredentialUserEntity($username, $userHandle, $username);
            $options = \Webauthn\PublicKeyCredentialCreationOptions::create($rpEntity, $userEntity, random_bytes(32));
            $options->setAttestation(\Webauthn\PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE);
            $_SESSION['challenge'] = $options->getChallenge();
            $_SESSION['userEntity'] = $userEntity;
            echo json_encode($options);
            exit;

        case 'registerUser':
            try {
                $userEntity = $_SESSION['userEntity'];
                $attestationResponse = \Webauthn\AuthenticatorAttestationResponse::create($input['credential']);
                $publicKeyCredentialSource = $attestationValidator->check($attestationResponse, $_SESSION['challenge'], $rpEntity->getId());
                
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO users (user_handle, username, display_name) VALUES (?, ?, ?)");
                $stmt->execute([$userEntity->getId(), $userEntity->getName(), $userEntity->getDisplayName()]);
                
                $repository->saveCredentialSource($publicKeyCredentialSource);
                $pdo->commit();
                
                unset($_SESSION['challenge'], $_SESSION['userEntity']);
                echo json_encode(['success' => true]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                echo json_encode(['error' => 'Register Error: ' . $e->getMessage()]);
            }
            exit;

        case 'getLoginChallenge':
            $username = $_GET['username'] ?? '';
            $stmt = $pdo->prepare("SELECT user_handle FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $userHandle = $stmt->fetchColumn();
            if (!$userHandle) { echo json_encode(['error' => 'Usuário não encontrado.']); exit; }
            
            $userEntity = new \Webauthn\PublicKeyCredentialUserEntity($username, $userHandle, $username);
            $allowedCredentials = $repository->findAllForUserEntity($userEntity);
            $descriptors = array_map(fn(\Webauthn\PublicKeyCredentialSource $cred) => $cred->getPublicKeyCredentialDescriptor(), $allowedCredentials);

            $options = \Webauthn\PublicKeyCredentialRequestOptions::create(random_bytes(32));
            $options->setRpId($rpEntity->getId());
            $options->setAllowedCredentials($descriptors);
            $_SESSION['challenge'] = $options->getChallenge();
            $_SESSION['username_for_login'] = $username;
            echo json_encode($options);
            exit;

        case 'loginUser':
            try {
                $username = $_SESSION['username_for_login'];
                $assertionResponse = \Webauthn\AuthenticatorAssertionResponse::create($input['credential']);
                $publicKeyCredentialSource = $assertionValidator->check($assertionResponse, $_SESSION['challenge'], $rpEntity->getId());
                
                $repository->saveCredentialSource($publicKeyCredentialSource);

                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $userId = $stmt->fetchColumn();
                
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $username;
                unset($_SESSION['challenge'], $_SESSION['username_for_login']);

                echo json_encode(['success' => true]);
            } catch (Throwable $e) {
                echo json_encode(['error' => 'Login Error: ' . $e->getMessage()]);
            }
            exit;
    }
}

function load_all_data($pdo, $userId) {
    try {
        $stmt_bills = $pdo->prepare("SELECT * FROM bills WHERE user_id = ?");
        $stmt_bills->execute([$userId]);
        $stmt_earnings = $pdo->prepare("SELECT * FROM earnings WHERE user_id = ?");
        $stmt_earnings->execute([$userId]);
        $stmt_purposes = $pdo->prepare("SELECT * FROM purposes WHERE user_id = ?");
        $stmt_purposes->execute([$userId]);
        $stmt_history = $pdo->prepare("SELECT * FROM financial_history WHERE user_id = ? ORDER BY closedAt ASC");
        $stmt_history->execute([$userId]);
        $stmt_bill_templates = $pdo->prepare("SELECT * FROM bill_templates WHERE user_id = ?");
        $stmt_bill_templates->execute([$userId]);
        $stmt_earning_templates = $pdo->prepare("SELECT * FROM earning_templates WHERE user_id = ?");
        $stmt_earning_templates->execute([$userId]);
        $history = $stmt_history->fetchAll();
        foreach ($history as $key => $item) {
            $history[$key]['data'] = json_decode($item['data']);
        }
        echo json_encode([
            'bills' => $stmt_bills->fetchAll(),
            'earnings' => $stmt_earnings->fetchAll(),
            'purposes' => $stmt_purposes->fetchAll(),
            'billTemplates' => $stmt_bill_templates->fetchAll(),
            'earningTemplates' => $stmt_earning_templates->fetchAll(),
            'history' => $history,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao carregar dados: ' . $e->getMessage()]);
    }
    exit;
}

function process_sync_queue($pdo, $userId, $input) {
    $actions = $input['actions'] ?? [];
    $processed_ids = [];
    if (empty($actions)) {
        echo json_encode(['success' => true, 'processed_ids' => []]);
        exit;
    }
    $pdo->beginTransaction();
    try {
        foreach ($actions as $actionItem) {
            $payload = $actionItem['payload'];
            switch ($actionItem['type']) {
                case 'ADD_BILL':
                    $stmt = $pdo->prepare("INSERT INTO bills (user_id, client_id, description, amount, dueDate, category, isPaid) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$userId, $payload['id'], $payload['description'], $payload['amount'], $payload['dueDate'], $payload['category'], $payload['isPaid'] ? 1 : 0]);
                    break;
                case 'UPDATE_BILL':
                     $stmt = $pdo->prepare("UPDATE bills SET description=?, amount=?, dueDate=?, category=? WHERE user_id=? AND client_id=?");
                     $stmt->execute([$payload['description'], $payload['amount'], $payload['dueDate'], $payload['category'], $userId, $payload['id']]);
                    break;
                case 'UPDATE_BILL_STATUS':
                     $stmt = $pdo->prepare("UPDATE bills SET isPaid=? WHERE user_id=? AND client_id=?");
                     $stmt->execute([$payload['isPaid'] ? 1 : 0, $userId, $payload['id']]);
                    break;
                case 'DELETE_BILL':
                    $stmt = $pdo->prepare("DELETE FROM bills WHERE user_id=? AND client_id=?");
                    $stmt->execute([$userId, $payload['id']]);
                    break;
            }
            $processed_ids[] = $actionItem['id'];
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'processed_ids' => $processed_ids]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro no SyncQueue: ' . $e->getMessage()]);
    }
    exit;
}
?>