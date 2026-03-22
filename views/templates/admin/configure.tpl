{* AioChat - Panel de configuración backoffice *}

<div class="panel aiochat-admin">
    <div class="panel-heading">
        <i class="icon-comments"></i> AI Chat Assistant — Configuración
    </div>

    {* Formulario principal *}
    <form method="post" enctype="multipart/form-data">
        <div class="form-group">
            <label class="control-label col-lg-3">API Key de Anthropic</label>
            <div class="col-lg-9">
                <input type="text" name="AIOCHAT_API_KEY" class="form-control" value="{$aiochat_api_key|escape:'html':'UTF-8'}" placeholder="sk-ant-..." />
                <p class="help-block">Obtén tu API Key en <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a></p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">Nombre del bot</label>
            <div class="col-lg-9">
                <input type="text" name="AIOCHAT_BOT_NAME" class="form-control" value="{$aiochat_bot_name|escape:'html':'UTF-8'}" placeholder="Asistente" />
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">Mensaje de bienvenida</label>
            <div class="col-lg-9">
                <input type="text" name="AIOCHAT_WELCOME_MSG" class="form-control" value="{$aiochat_welcome_msg|escape:'html':'UTF-8'}" placeholder="¡Hola! ¿En qué puedo ayudarte?" />
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">WhatsApp (número internacional)</label>
            <div class="col-lg-9">
                <input type="text" name="AIOCHAT_WHATSAPP" class="form-control" value="{$aiochat_whatsapp|escape:'html':'UTF-8'}" placeholder="34600000000" />
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">Teléfono de contacto</label>
            <div class="col-lg-9">
                <input type="text" name="AIOCHAT_PHONE" class="form-control" value="{$aiochat_phone|escape:'html':'UTF-8'}" placeholder="+34 600 000 000" />
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">Email de contacto</label>
            <div class="col-lg-9">
                <input type="email" name="AIOCHAT_EMAIL" class="form-control" value="{$aiochat_email|escape:'html':'UTF-8'}" placeholder="info@tutienda.com" />
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">Canal YouTube (URL)</label>
            <div class="col-lg-9">
                <input type="text" name="AIOCHAT_YOUTUBE" class="form-control" value="{$aiochat_youtube|escape:'html':'UTF-8'}" placeholder="https://www.youtube.com/@tucanal" />
                <p class="help-block">Se extraerán los subtítulos de los vídeos para dar contexto al chatbot.</p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">Agente disponible (chat en vivo)</label>
            <div class="col-lg-9">
                <select name="AIOCHAT_AGENT_ONLINE" class="form-control" style="width:auto;">
                    <option value="1" {if $aiochat_agent_online}selected{/if}>Sí — estoy disponible</option>
                    <option value="0" {if !$aiochat_agent_online}selected{/if}>No — fuera de línea</option>
                </select>
                <p class="help-block">Cuando estés disponible, el cliente podrá iniciar un chat en vivo contigo.</p>
            </div>
        </div>

        <div class="panel-footer">
            <button type="submit" name="submit_aiochat" class="btn btn-primary">
                <i class="process-icon-save"></i> Guardar configuración
            </button>
        </div>
    </form>

    {* Subida de documentos *}
    <div class="panel-heading" style="margin-top:20px;">
        <i class="icon-file"></i> Documentos de contexto (PDF / TXT)
    </div>
    <form method="post" enctype="multipart/form-data">
        <div class="form-group">
            <label class="control-label col-lg-3">Subir documento</label>
            <div class="col-lg-9">
                <input type="file" name="aiochat_pdf" accept=".pdf,.txt" class="form-control" />
                <p class="help-block">Sube catálogos, fichas de producto, manuales o cualquier información extra para el bot.</p>
            </div>
        </div>
        <div class="panel-footer">
            <button type="submit" name="submit_aiochat_pdf" class="btn btn-default">
                <i class="process-icon-upload"></i> Subir documento
            </button>
        </div>
    </form>

    {* Lista de documentos subidos *}
    {if $aiochat_documents}
    <table class="table" style="margin-top:10px;">
        <thead>
            <tr>
                <th>Documento</th>
                <th>Fecha</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            {foreach $aiochat_documents as $doc}
            <tr>
                <td>{$doc.original_name|escape:'html':'UTF-8'}</td>
                <td>{$doc.date_add}</td>
                <td>
                    <a href="{$aiochat_config_url|escape:'html':'UTF-8'}&delete_doc={$doc.id_document}" 
                       onclick="return confirm('¿Eliminar este documento?')" 
                       class="btn btn-danger btn-xs">
                        <i class="icon-trash"></i> Eliminar
                    </a>
                </td>
            </tr>
            {/foreach}
        </tbody>
    </table>
    {/if}

    {* Panel de chat en vivo para el agente *}
    <div class="panel-heading" style="margin-top:20px;">
        <i class="icon-comments-alt"></i> Chat en vivo — Conversaciones
        <span id="aiochat-pending-badge" class="badge" style="margin-left:8px;background:#ef4444;">0</span>
    </div>

    <div id="aiochat-agent-panel" style="display:flex;gap:16px;min-height:300px;">
        <!-- Lista conversaciones -->
        <div id="aiochat-conv-list" style="width:220px;border-right:1px solid #e2e8f0;padding-right:12px;overflow-y:auto;">
            <div style="color:#94a3b8;font-size:13px;text-align:center;margin-top:20px;">Cargando...</div>
        </div>

        <!-- Área de mensajes del agente -->
        <div id="aiochat-agent-chat" style="flex:1;display:flex;flex-direction:column;">
            <div id="aiochat-agent-messages" style="flex:1;overflow-y:auto;padding:8px;background:#f8fafc;border-radius:8px;min-height:200px;margin-bottom:8px;">
                <div style="color:#94a3b8;font-size:13px;text-align:center;margin-top:40px;">Selecciona una conversación</div>
            </div>
            <div style="display:flex;gap:8px;" id="aiochat-agent-input-area" style="display:none;">
                <input type="text" id="aiochat-agent-input" class="form-control" placeholder="Escribe tu respuesta..." />
                <button onclick="agentSend()" class="btn btn-primary">Enviar</button>
                <button onclick="agentClose()" class="btn btn-danger">Cerrar chat</button>
            </div>
        </div>
    </div>

    {* Historial de conversaciones *}
    <div class="panel-heading" style="margin-top:20px;">
        <i class="icon-history"></i> Historial de conversaciones
    </div>
    {if $aiochat_conversations}
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Cliente</th>
                <th>Email</th>
                <th>Estado</th>
                <th>Mensajes</th>
                <th>Última actividad</th>
            </tr>
        </thead>
        <tbody>
            {foreach $aiochat_conversations as $conv}
            <tr>
                <td>#{$conv.id_conversation}</td>
                <td>{$conv.customer_name|default:'Anónimo'|escape:'html':'UTF-8'}</td>
                <td>{$conv.customer_email|default:'-'|escape:'html':'UTF-8'}</td>
                <td>
                    <span class="badge" style="background:{if $conv.status=='bot'}#2563eb{elseif $conv.status=='waiting'}#f59e0b{elseif $conv.status=='live'}#10b981{else}#94a3b8{/if}">
                        {$conv.status}
                    </span>
                </td>
                <td>{$conv.total_messages}</td>
                <td>{$conv.date_upd}</td>
            </tr>
            {/foreach}
        </tbody>
    </table>
    {else}
    <p style="color:#94a3b8;padding:16px;">Aún no hay conversaciones.</p>
    {/if}
</div>

<script>
var AIOCHAT_AGENT_LIVE_URL = '{$aiochat_module_url|escape:'javascript':'UTF-8'}';
var AIOCHAT_AGENT_API_KEY  = '{$aiochat_api_key|escape:'javascript':'UTF-8'}';
{literal}
var AIOCHAT_AGENT = {
    liveUrl: AIOCHAT_AGENT_LIVE_URL + '../aiochat/index.php?fc=module&module=aiochat&controller=livechat',
    token: null,
    currentConv: null,
    lastTime: null,
    polling: null
};

AIOCHAT_AGENT.token = btoa(AIOCHAT_AGENT_API_KEY + 'agent').replace(/=/g,'').substr(0,32);


function agentPollConversations() {
    fetch(AIOCHAT_AGENT.liveUrl + '&action=agent_poll&agent_token=' + encodeURIComponent(AIOCHAT_AGENT.token))
    .then(r => r.json())
    .then(data => {
        if (!data.conversations) return;
        var list = document.getElementById('aiochat-conv-list');
        var pending = data.conversations.filter(c => c.status === 'waiting').length;
        document.getElementById('aiochat-pending-badge').textContent = pending;

        if (data.conversations.length === 0) {
            list.innerHTML = '<div style="color:#94a3b8;font-size:13px;text-align:center;margin-top:20px;">Sin conversaciones activas</div>';
            return;
        }

        list.innerHTML = data.conversations.map(function(c) {
            var bg = AIOCHAT_AGENT.currentConv == c.id_conversation ? '#dbeafe' : 'white';
            var statusColor = c.status === 'waiting' ? '#f59e0b' : '#10b981';
            return '<div onclick="agentSelectConv(' + c.id_conversation + ')" style="padding:10px;margin-bottom:6px;border-radius:8px;cursor:pointer;background:' + bg + ';border:1px solid #e2e8f0;">' +
                '<div style="font-weight:600;font-size:13px;">' + (c.customer_name || 'Anónimo') + '</div>' +
                '<div style="font-size:11px;color:' + statusColor + ';">' + c.status + '</div>' +
                '<div style="font-size:11px;color:#94a3b8;margin-top:3px;">' + (c.last_message || '').substr(0, 40) + '</div>' +
                '</div>';
        }).join('');
    });
}

function agentSelectConv(idConv) {
    AIOCHAT_AGENT.currentConv = idConv;
    AIOCHAT_AGENT.lastTime = null;
    document.getElementById('aiochat-agent-messages').innerHTML = '';
    document.getElementById('aiochat-agent-input-area').style.display = 'flex';

    if (AIOCHAT_AGENT.polling) clearInterval(AIOCHAT_AGENT.polling);
    AIOCHAT_AGENT.polling = setInterval(agentPollMessages, 2000);
    agentPollMessages();
    agentPollConversations();
}

function agentPollMessages() {
    if (!AIOCHAT_AGENT.currentConv) return;
    var url = AIOCHAT_AGENT.liveUrl + '&action=agent_poll&id_conversation=' + AIOCHAT_AGENT.currentConv + '&agent_token=' + encodeURIComponent(AIOCHAT_AGENT.token);
    if (AIOCHAT_AGENT.lastTime) url += '&since=' + encodeURIComponent(AIOCHAT_AGENT.lastTime);

    fetch(url)
    .then(r => r.json())
    .then(data => {
        if (!data.messages || data.messages.length === 0) return;
        var msgs = document.getElementById('aiochat-agent-messages');
        data.messages.forEach(function(m) {
            var div = document.createElement('div');
            div.style.cssText = 'margin-bottom:8px;display:flex;' + (m.sender === 'agent' ? 'flex-direction:row-reverse;' : '');
            var bubble = document.createElement('div');
            bubble.style.cssText = 'padding:8px 12px;border-radius:10px;max-width:75%;font-size:13px;' +
                (m.sender === 'agent' ? 'background:#2563eb;color:white;' : m.sender === 'customer' ? 'background:white;border:1px solid #e2e8f0;' : 'background:#f1f5f9;color:#64748b;font-style:italic;');
            bubble.textContent = m.message;
            div.appendChild(bubble);
            msgs.appendChild(div);
            AIOCHAT_AGENT.lastTime = m.date_add;
        });
        msgs.scrollTop = msgs.scrollHeight;
    });
}

function agentSend() {
    var input = document.getElementById('aiochat-agent-input');
    var message = input.value.trim();
    if (!message || !AIOCHAT_AGENT.currentConv) return;
    input.value = '';

    fetch(AIOCHAT_AGENT.liveUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action: 'agent_send',
            id_conversation: AIOCHAT_AGENT.currentConv,
            message: message,
            agent_token: AIOCHAT_AGENT.token
        })
    });
}

function agentClose() {
    if (!AIOCHAT_AGENT.currentConv) return;
    if (!confirm('¿Cerrar esta conversación?')) return;

    fetch(AIOCHAT_AGENT.liveUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action: 'agent_close',
            id_conversation: AIOCHAT_AGENT.currentConv,
            agent_token: AIOCHAT_AGENT.token
        })
    }).then(() => {
        AIOCHAT_AGENT.currentConv = null;
        document.getElementById('aiochat-agent-messages').innerHTML = '<div style="color:#94a3b8;font-size:13px;text-align:center;margin-top:40px;">Conversación cerrada</div>';
        document.getElementById('aiochat-agent-input-area').style.display = 'none';
        agentPollConversations();
    });
}

// Iniciar polling de conversaciones cada 5 segundos
agentPollConversations();
setInterval(agentPollConversations, 5000);
{/literal}
</script>
