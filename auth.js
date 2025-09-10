$(document).ready(function() {

    // --- Funções Auxiliares para WebAuthn (Base64URL <-> ArrayBuffer) ---
    // O servidor envia dados em Base64URL, mas a API do navegador precisa de ArrayBuffers.
    function bufferDecode(value) {
        return Uint8Array.from(atob(value.replace(/_/g, '/').replace(/-/g, '+')), c => c.charCodeAt(0));
    }

    function bufferEncode(value) {
        return btoa(String.fromCharCode.apply(null, new Uint8Array(value)))
            .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }

    // --- Manipuladores de UI do Formulário de Autenticação ---
    $('#show-register').on('click', function(e) {
        e.preventDefault();
        $('#login-form').hide();
        $('#register-form').show();
    });

    $('#show-login').on('click', function(e) {
        e.preventDefault();
        $('#register-form').hide();
        $('#login-form').show();
    });

    $('#logout-btn').on('click', function() {
        logout();
    });

    // --- Processo de Registro ---
    $('#register-form').on('submit', async function(e) {
        e.preventDefault();
        const username = $('#register-username').val().trim();
        const errorEl = $('#register-error');
        errorEl.text('');

        if (!username) {
            errorEl.text('Por favor, insira um nome de usuário.');
            return;
        }

        try {
            // 1. Pedir o "desafio" de registro para o servidor
            const response = await fetch(`api.php?action=getRegisterChallenge&username=${username}`);
            const challenge = await response.json();

            if (challenge.error) {
                throw new Error(challenge.error);
            }

            // Decodificar os dados do servidor para o formato que a API WebAuthn precisa
            challenge.challenge = bufferDecode(challenge.challenge);
            challenge.user.id = bufferDecode(challenge.user.id);

            // 2. Chamar a API do navegador para criar a credencial (Passkey)
            // Isso irá abrir a janela de biometria/PIN do sistema operacional
            const credential = await navigator.credentials.create({
                publicKey: challenge
            });

            // 3. Enviar a credencial criada de volta para o servidor para ser salva
            const credentialForServer = {
                id: credential.id,
                rawId: bufferEncode(credential.rawId),
                type: credential.type,
                response: {
                    attestationObject: bufferEncode(credential.response.attestationObject),
                    clientDataJSON: bufferEncode(credential.response.clientDataJSON),
                },
            };

            const registerResponse = await fetch('api.php?action=registerUser', {
                method: 'POST',
                body: JSON.stringify({
                    username: username,
                    credential: credentialForServer
                }),
                headers: { 'Content-Type': 'application/json' }
            });

            const registerResult = await registerResponse.json();

            if (registerResult.success) {
                alert('Usuário registrado com sucesso! Agora você pode entrar.');
                $('#show-login').click(); // Volta para a tela de login
            } else {
                throw new Error(registerResult.error || 'Falha ao registrar.');
            }

        } catch (err) {
            console.error("Erro de registro:", err);
            errorEl.text(err.message);
        }
    });

    // --- Processo de Login ---
    $('#login-form').on('submit', async function(e) {
        e.preventDefault();
        const username = $('#login-username').val().trim();
        const errorEl = $('#login-error');
        errorEl.text('');

        if (!username) {
            errorEl.text('Por favor, insira um nome de usuário.');
            return;
        }

        try {
            // 1. Pedir o "desafio" de login para o servidor
            const response = await fetch(`api.php?action=getLoginChallenge&username=${username}`);
            const challenge = await response.json();

            if (challenge.error) {
                throw new Error(challenge.error);
            }
            
            // Decodificar os dados
            challenge.challenge = bufferDecode(challenge.challenge);
            challenge.allowCredentials.forEach(function(cred) {
                cred.id = bufferDecode(cred.id);
            });

            // 2. Chamar a API do navegador para obter a "assinatura" do desafio
            // Isso também abrirá a janela de biometria/PIN
            const credential = await navigator.credentials.get({
                publicKey: challenge
            });

            // 3. Enviar a resposta para o servidor verificar
            const credentialForServer = {
                id: credential.id,
                rawId: bufferEncode(credential.rawId),
                type: credential.type,
                response: {
                    authenticatorData: bufferEncode(credential.response.authenticatorData),
                    clientDataJSON: bufferEncode(credential.response.clientDataJSON),
                    signature: bufferEncode(credential.response.signature),
                    userHandle: bufferEncode(credential.response.userHandle),
                },
            };

            const loginResponse = await fetch('api.php?action=loginUser', {
                method: 'POST',
                body: JSON.stringify({
                    username: username,
                    credential: credentialForServer
                }),
                headers: { 'Content-Type': 'application/json' }
            });

            const loginResult = await loginResponse.json();

            if (loginResult.success) {
                // Se o login for bem-sucedido, inicia a aplicação principal
                window.startApp();
            } else {
                throw new Error(loginResult.error || 'Falha no login.');
            }

        } catch (err) {
            console.error("Erro de login:", err);
            errorEl.text(err.message);
        }
    });

    // --- Gerenciamento da Sessão ---
    window.checkLoginStatus = async function() {
        try {
            const response = await fetch('api.php?action=checkLogin');
            const result = await response.json();

            if (result.loggedIn) {
                console.log(`Usuário "${result.username}" já está logado.`);
                window.startApp(); // Inicia a aplicação principal
            } else {
                // Se não estiver logado, exibe a seção de autenticação
                $('#auth-section').show();
            }
        } catch (err) {
            console.error("Erro ao verificar status do login, mostrando formulário de login.", err);
            $('#auth-section').show();
        }
    }

    async function logout() {
        await fetch('api.php?action=logout');
        window.location.reload();
    }
});