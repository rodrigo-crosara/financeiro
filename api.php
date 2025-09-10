<?php

// api.php - Versão Limpa e Final

// --- PRÉ-REQUISITOS E INICIALIZAÇÃO ---
// Habilita a exibição de todos os erros para depuração.
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Inclui o autoloader do Composer.
require __DIR__ . '/vendor/autoload.php';

// Inicia a sessão. Deve ser chamado antes de qualquer output.
session_start();

// Define o cabeçalho da resposta como JSON.
header("Content-Type: application/json");

// Importa as classes necessárias da biblioteca WebAuthn
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\Server;
use Webauthn\AuthenticationConverter\StandardAuthenticationConverter;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Cose\Algorithm\Manager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;

// --- CONFIGURAÇÃO DO BANCO DE DADOS (PDO) ---
$host = 'localhost';
$db = 'financas_pessoais';
$user = 'root';
$pass = '';
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
    exit;
}

// --- CONFIGURAÇÃO DO SERVIDOR WEBAUTHN ---

class PDOCredentialSourceRepository implements \Webauthn\PublicKeyCredentialSourceRepository {
    private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?\Webauthn\PublicKeyCredentialSource {
        $stmt = $this->pdo->prepare('SELECT user_handle, public_key_pem as credentialPublicKey, credential_id_base64url as publicKeyCredentialId, sign_count as counter, "public-key" as type FROM user_authenticators WHERE credential_id_base64url = ?');
        $stmt->execute([base64_encode($publicKeyCredentialId)]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$data) return null;
        $data['aaguid'] = '00000000-0000-0000-0000-000000000000';
        $data['transports'] = [];
        return \Webauthn\PublicKeyCredentialSource::createFromArray($data);
    }

    public function findAllForUserEntity(\Webauthn\PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array {
        $stmt = $this->pdo->prepare('SELECT credential_id_base64url FROM user_authenticators a JOIN users u ON a.user_id = u.id WHERE u.user_handle = ?');
        $stmt->execute([$publicKeyCredentialUserEntity->getId()]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_filter(array_map(function($row) {
            $decodedId = base64_decode($row['credential_id_base64url']);
            if ($decodedId === false) { return null; }
            return ['id' => $decodedId, 'type' => 'public-key'];
        }, $results));
    }

    public function saveCredentialSource(\Webauthn\PublicKeyCredentialSource $publicKeyCredentialSource): void {
        $data = $publicKeyCredentialSource->jsonSerialize();
        $stmt = $this->pdo->prepare('UPDATE user_authenticators SET sign_count = ? WHERE credential_id_base64url = ?');
        $stmt->execute([$data['counter'], base64_encode($data['publicKeyCredentialId'])]);
    }
}

$rpEntity = new PublicKeyCredentialRpEntity('Meu Painel Financeiro', 'localhost');
$credentialSourceRepository = new PDOCredentialSourceRepository($pdo);
$attestationStatementSupportManager = new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]);
$algorithmManager = new Manager([new ES256(), new RS256()]);

$server = new Server($rpEntity, $credentialSourceRepository, new StandardAuthenticationConverter());
$server->setAlgorithmManager($algorithmManager);
$server->setAttestationStatementSupportManager($attestationStatementSupportManager);

// --- LÓGICA DA API ---
$action = $_REQUEST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

function isLoggedIn() { return isset($_SESSION['user_id']); }
function requireLogin() { if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error' => 'Autenticação necessária.']); exit; } }

switch ($action) {
    case 'getRegisterChallenge':
        $username = $_GET['username'] ?? '';
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) { echo json_encode(['error' => 'Nome de usuário já existe.']); exit; }
        
        $userHandle = bin2hex(random_bytes(32));
        $userEntity = new PublicKeyCredentialUserEntity($username, $userHandle, $username);
        $options = $server->generatePublicKeyCredentialCreationOptions($userEntity, new AuthenticatorSelectionCriteria());
        $_SESSION['challenge'] = $options->getChallenge();
        $_SESSION['userEntity'] = $userEntity;
        echo json_encode($options);
        break;

    case 'registerUser':
        try {
            $userEntity = $_SESSION['userEntity'];
            $attestationResponse = new AuthenticatorAttestationResponse($input['credential']);
            $credentialSource = $server->loadAndCheckAttestationResponse($attestationResponse, $_SESSION['challenge']);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO users (user_handle, username, display_name) VALUES (?, ?, ?)");
            $stmt->execute([$userEntity->getId(), $userEntity->getName(), $userEntity->getDisplayName()]);
            $userId = $pdo->lastInsertId();

            $data = $credentialSource->jsonSerialize();
            $stmt = $pdo->prepare("INSERT INTO user_authenticators (user_id, credential_id_base64url, public_key_pem, sign_count) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, base64_encode($data['publicKeyCredentialId']), $data['credentialPublicKey'], $data['counter']]);
            $pdo->commit();
            
            unset($_SESSION['challenge'], $_SESSION['userEntity']);
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'getLoginChallenge':
        $username = $_GET['username'] ?? '';
        $stmt = $pdo->prepare("SELECT user_handle FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $userHandle = $stmt->fetchColumn();
        if (!$userHandle) { echo json_encode(['error' => 'Usuário não encontrado.']); exit; }

        $userEntity = new PublicKeyCredentialUserEntity($username, $userHandle, $username);
        $allowedCredentials = $credentialSourceRepository->findAllForUserEntity($userEntity);
        $options = $server->generatePublicKeyCredentialRequestOptions('discouraged', $allowedCredentials);
        $_SESSION['challenge'] = $options->getChallenge();
        $_SESSION['username_for_login'] = $username;
        echo json_encode($options);
        break;

    case 'loginUser':
        try {
            $username = $_SESSION['username_for_login'];
            $assertionResponse = new AuthenticatorAssertionResponse($input['credential']);
            $credentialSource = $server->loadAndCheckAssertionResponse($assertionResponse, $_SESSION['challenge']);
            
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $userId = $stmt->fetchColumn();

            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $username;
            unset($_SESSION['challenge'], $_SESSION['username_for_login']);

            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    case 'checkLogin':
        if (isLoggedIn() && isset($_SESSION['username'])) {
            echo json_encode(['loggedIn' => true, 'username' => $_SESSION['username']]);
        } else {
            echo json_encode(['loggedIn' => false]);
        }
        break;

    case 'logout':
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    case 'loadAllData':
        requireLogin();
        $userId = $_SESSION['user_id'];
        
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
        break;
        
    case 'processSyncQueue':
        requireLogin();
        $userId = $_SESSION['user_id'];
        $actions = $input['actions'] ?? [];
        $processed_ids = [];

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
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Ação não encontrada.']);
        break;
}
?>