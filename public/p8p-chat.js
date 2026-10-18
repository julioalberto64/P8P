(function () {
    function initP8PChat() {
        // Configuración del Widget
        const P8P_CONFIG = {
            apiUrl: window.P8P_API_URL || 'http://localhost:8000',
            workflowId: window.P8P_WORKFLOW_ID || '',
            title: 'Asistente P8P'
        };

        const sessionId = 'session_' + Math.random().toString(36).substring(2, 9);

        // Inyectar Estilos CSS
        const style = document.createElement('style');
        style.innerHTML = `
      #p8p-chat-button { position: fixed; bottom: 20px; right: 20px; background: #4f46e5; color: white; border: none; border-radius: 50%; width: 50px; height: 50px; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.3); z-index: 9999; font-size: 20px; }
      #p8p-chat-box { position: fixed; bottom: 80px; right: 20px; width: 340px; height: 450px; background: #0f172a; border: 1px solid #334155; border-radius: 12px; display: none; flex-direction: column; box-shadow: 0 10px 25px rgba(0,0,0,0.5); z-index: 9999; font-family: sans-serif; overflow: hidden; }
      #p8p-chat-header { background: #1e293b; padding: 12px; color: #f8fafc; font-weight: bold; font-size: 14px; border-bottom: 1px solid #334155; display: flex; justify-content: space-between; }
      #p8p-chat-messages { flex: 1; padding: 12px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; font-size: 13px; }
      .p8p-msg { max-width: 80%; padding: 8px 12px; border-radius: 8px; line-height: 1.4; word-break: break-word; }
      .p8p-user { align-self: flex-end; background: #4f46e5; color: white; }
      .p8p-bot { align-self: flex-start; background: #334155; color: #f8fafc; }
      #p8p-chat-input-area { padding: 8px; border-top: 1px solid #334155; display: flex; gap: 6px; background: #0f172a; }
      #p8p-chat-input { flex: 1; background: #1e293b; border: 1px solid #334155; color: white; padding: 8px; border-radius: 6px; outline: none; font-size: 12px; }
      #p8p-chat-send { background: #4f46e5; border: none; color: white; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: bold; }
    `;
        document.head.appendChild(style);

        // Crear HTML del Chat
        const chatBtn = document.createElement('button');
        chatBtn.id = 'p8p-chat-button';
        chatBtn.innerHTML = '💬';

        const chatBox = document.createElement('div');
        chatBox.id = 'p8p-chat-box';
        chatBox.innerHTML = `
      <div id="p8p-chat-header">
        <span>${P8P_CONFIG.title}</span>
        <span style="cursor:pointer;" id="p8p-chat-close">✕</span>
      </div>
      <div id="p8p-chat-messages">
        <div class="p8p-msg p8p-bot">¡Hola! ¿En qué puedo ayudarte hoy?</div>
      </div>
      <div id="p8p-chat-input-area">
        <input type="text" id="p8p-chat-input" placeholder="Escribe un mensaje..." />
        <button id="p8p-chat-send">Enviar</button>
      </div>
    `;

        document.body.appendChild(chatBtn);
        document.body.appendChild(chatBox);

        // Eventos de apertura/cierre
        chatBtn.onclick = () => chatBox.style.display = chatBox.style.display === 'flex' ? 'none' : 'flex';
        document.getElementById('p8p-chat-close').onclick = () => chatBox.style.display = 'none';

        // Función de Envío y Polling Asíncrono
        async function sendMessage() {
            const input = document.getElementById('p8p-chat-input');
            const text = input.value.trim();
            if (!text) return;

            appendMessage(text, 'p8p-user');
            input.value = '';

            const loadingMsg = appendMessage('Pensando...', 'p8p-bot');

            try {
                // 1. Encolar la ejecución en P8P
                const res = await fetch(`${P8P_CONFIG.apiUrl}/async_run.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        workflow_id: P8P_CONFIG.workflowId,
                        session_id: sessionId,
                        user_message: text
                    })
                });

                const data = await res.json();
                if (!data.job_id) throw new Error('No se generó el Job ID');

                // 2. Consultar el estado (Polling cada 1.5s)
                const pollInterval = setInterval(async () => {
                    const statusRes = await fetch(`${P8P_CONFIG.apiUrl}/async_run.php?job_id=${data.job_id}`);
                    const statusData = await statusRes.json();

                    if (statusData.job && statusData.job.status === 'completed') {
                        clearInterval(pollInterval);

                        // Extraer la última respuesta de la IA del historial
                        const result = statusData.job.result;
                        let botResponse = 'Respuesta recibida.';

                        if (result && result.final_context) {
                            for (const key in result.final_context) {
                                if (result.final_context[key].output_text) {
                                    botResponse = result.final_context[key].output_text;
                                }
                            }
                        }

                        loadingMsg.textContent = botResponse;
                    } else if (statusData.job && statusData.job.status === 'failed') {
                        clearInterval(pollInterval);
                        loadingMsg.textContent = 'Ocurrió un error al procesar tu solicitud.';
                    }
                }, 1500);

            } catch (err) {
                loadingMsg.textContent = 'Error de conexión con el servidor P8P.';
            }
        }

        function appendMessage(text, className) {
            const msgDiv = document.createElement('div');
            msgDiv.className = `p8p-msg ${className}`;
            msgDiv.textContent = text;
            const container = document.getElementById('p8p-chat-messages');
            container.appendChild(msgDiv);
            container.scrollTop = container.scrollHeight;
            return msgDiv;
        }

        document.getElementById('p8p-chat-send').onclick = sendMessage;
        document.getElementById('p8p-chat-input').onkeypress = (e) => { if (e.key === 'Enter') sendMessage(); };
    }

    // Esperar a que el DOM esté listo antes de insertar elementos
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initP8PChat);
    } else {
        initP8PChat();
    }
})();
