{* AioChat - Ventana de chat para el cliente *}

<div id="aiochat-widget" class="aiochat-{$aiochat_position|escape:'html':'UTF-8'}">
    <!-- Botón flotante -->
    <div id="aiochat-bubble" onclick="aiochatToggle()">
        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="white">
            <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
        </svg>
        <span id="aiochat-unread" style="display:none;">1</span>
    </div>

    <!-- Ventana del chat -->
    <div id="aiochat-box" style="display:none;">
        <!-- Header -->
        <div id="aiochat-header">
            <div id="aiochat-header-info">
                <div id="aiochat-avatar">🤖</div>
                <div>
                    <div id="aiochat-title">{$aiochat_bot_name|escape:'html':'UTF-8'}</div>
                    <div id="aiochat-status-text">En línea</div>
                </div>
            </div>
            <div id="aiochat-header-actions">
                <button onclick="aiochatRequestHuman()" title="Hablar con una persona">👤</button>
                <button onclick="aiochatClearChat()" title="Limpiar conversación">🗑</button>
                <button onclick="aiochatToggle()" title="Cerrar">✕</button>
            </div>
        </div>

        <!-- Mensajes -->
        <div id="aiochat-messages">
            <div class="aiochat-msg aiochat-bot">
                <div class="aiochat-bubble">{$aiochat_welcome_msg|escape:'html':'UTF-8'}</div>
            </div>
        </div>

        <!-- Panel de contacto humano (oculto por defecto) -->
        <div id="aiochat-human-panel" style="display:none;">
            <div id="aiochat-human-title">¿Cómo prefieres contactarnos?</div>
            <div id="aiochat-human-options">
                {if $aiochat_whatsapp}
                <a href="https://wa.me/{$aiochat_whatsapp|escape:'html':'UTF-8'}" target="_blank" class="aiochat-contact-btn aiochat-wa">
                    💬 WhatsApp
                </a>
                {/if}
                {if $aiochat_phone}
                <a href="tel:{$aiochat_phone|escape:'html':'UTF-8'}" class="aiochat-contact-btn aiochat-phone">
                    📞 Llamar
                </a>
                {/if}
                {if $aiochat_agent_online}
                <button onclick="aiochatStartLive()" class="aiochat-contact-btn aiochat-live">
                    💬 Chat en vivo
                </button>
                {/if}
                {if $aiochat_email_enabled}
                <button onclick="aiochatShowEmail()" class="aiochat-contact-btn aiochat-email">
                    ✉️ Enviar mensaje
                </button>
                {/if}
            </div>
            <button onclick="aiochatHideHuman()" class="aiochat-back-btn">← Volver al chat</button>
        </div>

        <!-- Panel de email (oculto por defecto) -->
        <div id="aiochat-email-panel" style="display:none;">
            <div id="aiochat-human-title">Envíanos un mensaje</div>
            <input type="text" id="aiochat-email-name" placeholder="Tu nombre" />
            <input type="email" id="aiochat-email-addr" placeholder="Tu email" />
            <textarea id="aiochat-email-msg" placeholder="Tu mensaje..." rows="4"></textarea>
            <button onclick="aiochatSendEmail()" class="aiochat-send-email-btn">Enviar</button>
            <button onclick="aiochatShowHuman()" class="aiochat-back-btn">← Volver</button>
        </div>

        <!-- Panel de chat en vivo -->
        <div id="aiochat-live-panel" style="display:none;">
            <div id="aiochat-live-messages"></div>
            <div id="aiochat-live-waiting" style="display:none;">
                <div class="aiochat-typing">Un agente se conectará en breve...</div>
            </div>
        </div>

        <!-- Input -->
        <div id="aiochat-input-area">
            <textarea id="aiochat-input" placeholder="Escribe tu mensaje..." rows="1" onkeydown="aiochatKeydown(event)"></textarea>
            <button id="aiochat-send-btn" onclick="aiochatSend()">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="white">
                    <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                </svg>
            </button>
        </div>

        <div id="aiochat-footer">Powered by IA · <a href="#" onclick="aiochatRequestHuman();return false;">Hablar con una persona</a></div>
    </div>
</div>

<script>
var AIOCHAT = {
    chatUrl: '{$aiochat_chat_url|escape:'javascript':'UTF-8'}',
    liveUrl: '{$aiochat_live_url|escape:'javascript':'UTF-8'}',
    sessionId: null,
    history: [],
    isLive: false,
    livePolling: null,
    lastMessageTime: null,
    customerName: '{$aiochat_customer_name|escape:'javascript':'UTF-8'}',
    customerEmail: '{$aiochat_customer_email|escape:'javascript':'UTF-8'}',
    customerId: {$aiochat_customer_id|intval},
    agentOnline: {if $aiochat_agent_online}true{else}false{/if},
    welcomeMsg: '{$aiochat_welcome_msg|escape:'javascript':'UTF-8'}',
    isTyping: false,
    opened: false
};
{literal}
// Generar/restaurar session ID y limpiar si el cliente cambió (login/logout)
(function() {
    var storedCid = localStorage.getItem('aiochat_customer_id');
    var currentCid = String(AIOCHAT.customerId);
    // Si el customer_id cambió (login, logout o cambio de cuenta) → sesión nueva
    if (storedCid !== null && storedCid !== currentCid) {
        localStorage.removeItem('aiochat_session');
        var oldSid = localStorage.getItem('aiochat_session_prev');
        if (oldSid) {
            localStorage.removeItem('aiochat_history_' + oldSid);
            localStorage.removeItem('aiochat_msgs_' + oldSid);
        }
    }
    localStorage.setItem('aiochat_customer_id', currentCid);
})();

AIOCHAT.sessionId = localStorage.getItem('aiochat_session');
if (!AIOCHAT.sessionId) {
    AIOCHAT.sessionId = 'ac_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
    localStorage.setItem('aiochat_session', AIOCHAT.sessionId);
}

// Restaurar historial y mensajes desde localStorage
(function() {
    var savedHistory = localStorage.getItem('aiochat_history_' + AIOCHAT.sessionId);
    var savedMsgs    = localStorage.getItem('aiochat_msgs_' + AIOCHAT.sessionId);
    if (savedHistory) {
        try { AIOCHAT.history = JSON.parse(savedHistory); } catch(e) {}
    }
    if (savedMsgs) {
        try {
            var msgs = JSON.parse(savedMsgs);
            msgs.forEach(function(m) { aiochatAddMessage(m.t, m.s); });
        } catch(e) {}
    }
})();

function aiochatPersist() {
    localStorage.setItem('aiochat_history_' + AIOCHAT.sessionId, JSON.stringify(AIOCHAT.history));
    // Guardar texto original (markdown crudo de AIOCHAT.history), no el innerHTML ya renderizado.
    // Si se guardara innerHTML y luego aiochatFormatText lo volviera a procesar,
    // los <a> y <strong> se escaparían y se verían como código HTML en pantalla.
    var msgs = AIOCHAT.history.slice(-30).map(function(h) {
        return {t: h.content, s: h.role === 'user' ? 'customer' : 'bot'};
    });
    localStorage.setItem('aiochat_msgs_' + AIOCHAT.sessionId, JSON.stringify(msgs));
}

function aiochatClearChat() {
    localStorage.removeItem('aiochat_history_' + AIOCHAT.sessionId);
    localStorage.removeItem('aiochat_msgs_' + AIOCHAT.sessionId);
    AIOCHAT.history = [];
    AIOCHAT.sessionId = 'ac_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
    localStorage.setItem('aiochat_session', AIOCHAT.sessionId);
    var msgs = document.getElementById('aiochat-messages');
    msgs.innerHTML = '';
    // Restaurar mensaje de bienvenida
    var div = document.createElement('div');
    div.className = 'aiochat-msg aiochat-bot';
    var bubble = document.createElement('div');
    bubble.className = 'aiochat-bubble';
    bubble.textContent = AIOCHAT.welcomeMsg;
    div.appendChild(bubble);
    msgs.appendChild(div);
}

function aiochatToggle() {
    var box = document.getElementById('aiochat-box');
    var bubble = document.getElementById('aiochat-bubble');
    if (box.style.display === 'none' || box.style.display === '') {
        box.style.display = 'flex';
        bubble.style.display = 'none';
        document.getElementById('aiochat-unread').style.display = 'none';
        AIOCHAT.opened = true;
        document.getElementById('aiochat-input').focus();
    } else {
        box.style.display = 'none';
        bubble.style.display = 'flex';
    }
}

function aiochatKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        aiochatSend();
    }
}

function aiochatSend() {
    var input = document.getElementById('aiochat-input');
    var message = input.value.trim();
    if (!message || AIOCHAT.isTyping) return;

    input.value = '';
    input.style.height = 'auto';

    aiochatAddMessage(message, 'customer');
    AIOCHAT.history.push({role: 'user', content: message});
    aiochatPersist();

    if (AIOCHAT.isLive) {
        aiochatSendLiveMessage(message);
        return;
    }

    aiochatShowTyping();
    AIOCHAT.isTyping = true;

    fetch(AIOCHAT.chatUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action: 'send',
            message: message,
            session_id: AIOCHAT.sessionId,
            customer_name: AIOCHAT.customerName,
            customer_email: AIOCHAT.customerEmail,
            history: JSON.stringify(AIOCHAT.history.slice(-10))
        })
    })
    .then(r => r.json())
    .then(data => {
        aiochatHideTyping();
        AIOCHAT.isTyping = false;

        if (data.error) {
            aiochatAddMessage('[ERROR] ' + data.error, 'bot');
            return;
        }

        aiochatAddMessage(data.response, 'bot');
        AIOCHAT.history.push({role: 'bot', content: data.response});
        aiochatPersist();

        if (data.human_requested) {
            setTimeout(aiochatShowHuman, 800);
        }
    })
    .catch(() => {
        aiochatHideTyping();
        AIOCHAT.isTyping = false;
        aiochatAddMessage('Error de conexión. Por favor inténtalo de nuevo.', 'bot');
    });
}

function aiochatFormatText(text) {
    // Escapar HTML para evitar XSS
    var safe = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    // Markdown links: [Nombre del producto](URL) → botón clicable
    safe = safe.replace(
        /\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g,
        '<a href="$2" target="_blank" rel="noopener noreferrer" class="aiochat-product-link">$1</a>'
    );
    // URLs sueltas que hayan podido quedar
    safe = safe.replace(
        /(?<!['">=])(https?:\/\/\S+)/g,
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>'
    );
    // Negrita **texto**
    safe = safe.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    // Saltos de línea
    safe = safe.replace(/\n/g, '<br>');
    return safe;
}

function aiochatAddMessage(text, sender) {
    var msgs = document.getElementById('aiochat-messages');
    var div = document.createElement('div');
    div.className = 'aiochat-msg aiochat-' + sender;
    var bubble = document.createElement('div');
    bubble.className = 'aiochat-bubble';
    bubble.innerHTML = aiochatFormatText(text);
    div.appendChild(bubble);
    msgs.appendChild(div);
    msgs.scrollTop = msgs.scrollHeight;

    if (!AIOCHAT.opened && sender === 'bot') {
        document.getElementById('aiochat-unread').style.display = 'flex';
    }
}

function aiochatShowTyping() {
    var msgs = document.getElementById('aiochat-messages');
    var div = document.createElement('div');
    div.id = 'aiochat-typing-indicator';
    div.className = 'aiochat-msg aiochat-bot';
    div.innerHTML = '<div class="aiochat-bubble aiochat-typing"><span></span><span></span><span></span></div>';
    msgs.appendChild(div);
    msgs.scrollTop = msgs.scrollHeight;
}

function aiochatHideTyping() {
    var el = document.getElementById('aiochat-typing-indicator');
    if (el) el.remove();
}

function aiochatRequestHuman() {
    fetch(AIOCHAT.chatUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action: 'request_human', session_id: AIOCHAT.sessionId})
    });
    aiochatShowHuman();
}

function aiochatShowHuman() {
    document.getElementById('aiochat-human-panel').style.display = 'flex';
    document.getElementById('aiochat-messages').style.display = 'none';
    document.getElementById('aiochat-input-area').style.display = 'none';
    document.getElementById('aiochat-email-panel').style.display = 'none';
    document.getElementById('aiochat-live-panel').style.display = 'none';
}

function aiochatHideHuman() {
    document.getElementById('aiochat-human-panel').style.display = 'none';
    document.getElementById('aiochat-messages').style.display = 'flex';
    document.getElementById('aiochat-input-area').style.display = 'flex';
}

function aiochatShowEmail() {
    document.getElementById('aiochat-human-panel').style.display = 'none';
    document.getElementById('aiochat-email-panel').style.display = 'flex';
}

function aiochatSendEmail() {
    var name  = document.getElementById('aiochat-email-name').value.trim();
    var email = document.getElementById('aiochat-email-addr').value.trim();
    var msg   = document.getElementById('aiochat-email-msg').value.trim();

    if (!name || !email || !msg) {
        alert('Por favor rellena todos los campos.');
        return;
    }

    fetch(AIOCHAT.chatUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action: 'send_email', name: name, email: email, message: msg})
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            document.getElementById('aiochat-email-panel').style.display = 'none';
            document.getElementById('aiochat-messages').style.display = 'flex';
            document.getElementById('aiochat-input-area').style.display = 'flex';
            aiochatAddMessage('¡Mensaje enviado! Te responderemos lo antes posible.', 'bot');
        }
    });
}

function aiochatStartLive() {
    document.getElementById('aiochat-human-panel').style.display = 'none';
    document.getElementById('aiochat-live-panel').style.display = 'flex';
    document.getElementById('aiochat-live-waiting').style.display = 'flex';
    document.getElementById('aiochat-input-area').style.display = 'flex';
    AIOCHAT.isLive = true;
    AIOCHAT.lastMessageTime = null;
    aiochatStartLivePolling();
}

function aiochatSendLiveMessage(message) {
    fetch(AIOCHAT.liveUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action: 'send', session_id: AIOCHAT.sessionId, message: message})
    });
}

function aiochatStartLivePolling() {
    AIOCHAT.livePolling = setInterval(function() {
        var params = new URLSearchParams({
            action: 'poll',
            session_id: AIOCHAT.sessionId
        });
        if (AIOCHAT.lastMessageTime) params.append('since', AIOCHAT.lastMessageTime);

        fetch(AIOCHAT.liveUrl + '?' + params.toString())
        .then(r => r.json())
        .then(data => {
            if (data.messages && data.messages.length > 0) {
                document.getElementById('aiochat-live-waiting').style.display = 'none';
                data.messages.forEach(function(m) {
                    var liveMessages = document.getElementById('aiochat-live-messages');
                    var div = document.createElement('div');
                    div.className = 'aiochat-msg aiochat-bot';
                    div.innerHTML = '<div class="aiochat-bubble">' + m.message + '</div>';
                    liveMessages.appendChild(div);
                    liveMessages.scrollTop = liveMessages.scrollHeight;
                    AIOCHAT.lastMessageTime = m.date_add;
                });
            }
            if (data.status === 'closed') {
                clearInterval(AIOCHAT.livePolling);
                AIOCHAT.isLive = false;
                document.getElementById('aiochat-live-panel').style.display = 'none';
                document.getElementById('aiochat-messages').style.display = 'flex';
                document.getElementById('aiochat-input-area').style.display = 'flex';
                aiochatAddMessage('El agente ha cerrado la conversación. Si necesitas más ayuda pulsa en el chat.', 'bot');
                aiochatClearSession();
            }
        });
    }, 3000);
}
{/literal}
</script>
