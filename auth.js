// auth.js - Versão Final e Corrigida

$(document).ready(function() {

    // --- Funções Auxiliares para WebAuthn (Base64URL <-> ArrayBuffer) ---
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
            let options = await response.json();

            if (options.error) {
                throw new Error(options.error);
            }

            // Decodificar os dados do servidor para o formato que a API WebAuthn precisa
            options.challenge = bufferDecode(options.challenge);
            options.user.id = bufferDecode(options.user.id);
            if (options.excludeCredentials) {
                options.excludeCredentials.forEach(cred => {
                    cred.id = bufferDecode(cred.id);
                });
            }

            // 2. Chamar a API do navegador para criar a credencial (Passkey)
            const credential = await navigator.credentials.create({
                publicKey: options
            });

            // 3. Enviar a credencial criada de volta para o servidor para ser salva (FORMATO CORRIGIDO)
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
                    credential: credentialForServer
                }),
                headers: { 'Content-Type': 'application/json' }
            });

            const registerResult = await registerResponse.json();

            if (registerResult.success) {
                alert('Usuário registrado com sucesso! Agora você pode entrar.');
                $('#login-username').val(username);
                $('#show-login').click();
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
            let options = await response.json();

            if (options.error) {
                throw new Error(options.error);
            }
            
            // Decodificar os dados
            options.challenge = bufferDecode(options.challenge);
            if (options.allowCredentials) {
                options.allowCredentials.forEach(function(cred) {
                    cred.id = bufferDecode(cred.id);
                });
            }

            // 2. Chamar a API do navegador para obter a "assinatura" do desafio
            const credential = await navigator.credentials.get({
                publicKey: options
            });

            // 3. Enviar a resposta para o servidor verificar (FORMATO CORRIGIDO)
            const credentialForServer = {
                id: credential.id,
                rawId: bufferEncode(credential.rawId),
                type: credential.type,
                response: {
                    authenticatorData: bufferEncode(credential.response.authenticatorData),
                    clientDataJSON: bufferEncode(credential.response.clientDataJSON),
                    signature: bufferEncode(credential.response.signature),
                    userHandle: credential.response.userHandle ? bufferEncode(credential.response.userHandle) : null,
                },
            };

            const loginResponse = await fetch('api.php?action=loginUser', {
                method: 'POST',
                body: JSON.stringify({
                    credential: credentialForServer
                }),
                headers: { 'Content-Type': 'application/json' }
            });

            const loginResult = await loginResponse.json();

            if (loginResult.success) {
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

            if (result.error) { throw new Error(result.error); }

            if (result.loggedIn) {
                console.log(`Usuário "${result.username}" já está logado.`);
                window.startApp();
            } else {
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