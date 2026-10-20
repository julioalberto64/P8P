(function () {
    const scriptTag = document.currentScript;
    const baseUrl = scriptTag ? scriptTag.src.replace('/p8p-chat.js', '') : 'http://localhost:8000';
    const workflowId = scriptTag ? scriptTag.getAttribute('data-workflow-id') : null;

    if (!workflowId) {
        console.error('P8P Chat Error: Falta el atributo data-workflow-id en el tag <script>');
        return;
    }

    const sessionId = 'session_' + Math.random().toString(36).substring(2, 9);

    // Inyectar Estilos CSS
    const style = document.createElement('style');
    style.innerHTML = `
        #p8p-chat-widget { position: fixed; bottom: 20px; right: 20px; z-index: 999999; font-family: system-ui, -apple-system, sans-serif; }
        #p8p-chat-button { width: 56px; height: 56px; border-radius: 50%; background: #4f46e5; color: white; border: none; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; transition: transform 0.2s; }
        #p8p-chat-button:hover { transform: scale(1.05); }
        #p8p-chat-window { display: none; width: 360px; height: 500px; background: #0f172a; border: 1px solid #1e293b; border-radius: 16px; box-shadow: 0 8px 32px rgba(0,0,0,0.5); flex-direction: column; overflow: hidden; margin-bottom: 12px; }
        #p8p-chat-header { background: #1e293b; color: #f8fafc; padding: 14px; font-weight: 600; font-size: 14px; display: flex; justify-content: space-between; align-items: center; }
        #p8p-chat-messages { flex: 1; padding: 14px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; }
        .p8p-msg { max-width: 80%; padding: 10px 14px; border-radius: 12px; font-size: 13px; line-height: 1.4; word-break: break-word; }
        .p8p-msg-user { background: #4f46e5; color: white; align-self: flex-end; border-bottom-right-radius: 2px; }
        .p8p-msg-bot { background: #1e293b; color: #e2e8f0; align-self: flex-start; border-bottom-left-radius: 2px; border: 1px solid #334155; }
        #p8p-chat-input-container { padding: 12px; border-top: 1px solid #1e293b; display: flex; gap: 8px; background: #0f172a; }
        #p8p-chat-input { flex: 1; background: #1e293b; border: 1px solid #334155; color: #f8fafc; padding: 10px 12px; border-radius: 8px; font-size: 13px; outline: none; }
        #p8p-chat-send { background: #4f46e5; color: white; border: none; padding: 0 14px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 13px; }
    `;
    document.head.appendChild(style);

    // Contenedor HTML del Widget
    const widget = document.createElement('div');
    widget.id = 'p8p-chat-widget';
    widget.innerHTML = `
        <div id="p8p-chat-window">
            <div id="p8p-chat-header">
                <span>Asistente P8P</span>
                <span id="p8p-chat-close" style="cursor:pointer; font-size: 16px;">✕</span>
            </div>
            <div id="p8p-chat-messages">
                <div class="p8p-msg p8p-msg-bot">¡Hola! ¿En qué puedo ayudarte hoy?</div>
            </div>
            <div id="p8p-chat-input-container">
                <input type="text" id="p8p-chat-input" placeholder="Escribe tu mensaje..." />
                <button id="p8p-chat-send">Enviar</button>
            </div>
        </div>
        <button id="p8p-chat-button">💬</button>
    `;
    document.body.appendChild(widget);

    // Elementos DOM
    const chatWindow = document.getElementById('p8p-chat-window');
    const chatButton = document.getElementById('p8p-chat-button');
    const chatClose = document.getElementById('p8p-chat-close');
    const chatInput = document.getElementById('p8p-chat-input');
    const chatSend = document.getElementById('p8p-chat-send');
    const chatMessages = document.getElementById('p8p-chat-messages');

    // Manejadores de Eventos
    chatButton.onclick = () => {
        chatWindow.style.display = chatWindow.style.display === 'flex' ? 'none' : 'flex';
    };
    chatClose.onclick = () => chatWindow.style.display = 'none';

    const addMessage = (text, type) => {
        const msgDiv = document.createElement('div');
        msgDiv.className = `p8p-msg p8p-msg-${type}`;
        msgDiv.innerText = text;
        chatMessages.appendChild(msgDiv);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    };

    const sendMessage = async () => {
        const text = chatInput.value.trim();
        if (!text) return;

        addMessage(text, 'user');
        chatInput.value = '';

        try {
            const res = await fetch(`${baseUrl}/async_run.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    workflow_id: workflowId,
                    session_id: sessionId,
                    message: text
                })
            });

            const data = await res.json();
            if (data.success) {
                addMessage('Mensaje recibido. Procesando respuesta...', 'bot');
            } else {
                addMessage('Error: ' + (data.error || 'No se pudo enviar el mensaje'), 'bot');
            }
        } catch (err) {
            addMessage('Error de conexión con el servidor', 'bot');
        }
    };

    chatSend.onclick = sendMessage;
    chatInput.onkeypress = (e) => { if (e.key === 'Enter') sendMessage(); };
})();
