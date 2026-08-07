<?php
/**
 * Ai - Main AI Chat Controller
 * Handles the Tier 1 admin chat interface.
 */
namespace Ai\Controllers;

use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("CceClient.php");
include_once("ServerScriptHelper.php");
use I18n;
use BxPage;
use CceClient;
use ServerScriptHelper;

class Ai extends BaseController
{
    private function getAiServiceAuthKey(): string
    {
        $CI = get_instance();
        $System = $CI->getSystem();
        $ai_config = $CI->cceClient->get($System['OID'], "AI");
        if (!is_array($ai_config)) {
            return '';
        }

        return trim((string)($ai_config['service_api_key'] ?? ''));
    }

    public function index()
    {
        $CI = get_instance();

        if (!$CI->getAllowed('serverAdministrator')) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();
        $user = $BX_SESSION['loginUser'];

        // CSRF Token für AJAX-Request vorbereiten
        $csrfTokenName = csrf_token();
        $csrfHash = csrf_hash();

        // User context for privileged tools
        $ai_username = $BX_SESSION['loginName'] ?? '';
        $ai_session_id = $BX_SESSION['sessionId'] ?? '';

        // Prepare Page:
        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-ai", "/ai/chat");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        $errors = $BxPage->getErrors();

        // Check if AI service is enabled
        $AI_config = $CI->cceClient->get($System['OID'], "AI");
        if (empty($AI_config['enabled'])) {
            $errors[] = ErrorMessage($i18n->get("[[base-ai.must_enable_service]]"));
        }

        // Build page
        $page_module = 'base_sysmanage';
        $BxPage->setVerticalMenu('base_programsPersonal');
        $BxPage->setVerticalMenuChild('base_admin_ai');
        $defaultPage = 'pageID';
        $page_body = array();

        $block = $factory->getPagedBlock("ai", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        // Show 'Settings' Button:
        $SettingsURL = '/ai/settings';
        $buttonContainerButtons[] = $factory->getButton($SettingsURL, '[[base-ai.settings_title]]', "DEMO-OVERRIDE");
        $buttonContainer = $factory->getButtonContainer("", $buttonContainerButtons);

        // Out with the Button-Container:
        $block->addFormField(
            $buttonContainer,
            $factory->getLabel("userList"),
            $defaultPage
        );

        // Chat container
        $chat_html = '';

        // Errors
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $chat_html .= '<div class="msg-box error">' . $error . '</div>';
            }
        }

        // Chat messages area
$chat_html .= '<div id="ai-chat-container">
    <div id="ai-chat-toolbar">
        <button id="ai-chat-toggle-size" type="button" class="ai-toolbar-btn" title="Toggle fullscreen" aria-label="Toggle fullscreen">⤢</button>
    </div>
    <div id="ai-chat-messages">
        <div class="ai-message system">' . $i18n->get('[[base-ai.chat_welcome]]') . '</div>
    </div>
    <div id="ai-chat-input-area">
        <textarea id="ai-chat-input" rows="3" placeholder="' . $i18n->get('[[base-ai.chat_placeholder]]') . '"></textarea>
        <button id="ai-chat-send" type="button">' . $i18n->get('[[base-ai.chat_send]]') . '</button>
    </div>
</div>

<input type="hidden" id="ai-session-id" value="' . uniqid('ai_', true) . '" />
<input type="hidden" id="ai-chat-url" value="/ai/chat/send" />
<input type="hidden" id="csrf-token" name="' . $csrfTokenName . '" value="' . $csrfHash . '" />
<input type="hidden" id="ai-username" value="' . htmlspecialchars($ai_username) . '" />
<input type="hidden" id="ai-user-session-id" value="' . htmlspecialchars($ai_session_id) . '" />

<style>
#ai-chat-container {
    position: relative;
    display: flex;
    flex-direction: column;
    height: 550px;
    border: 1px solid #ccc;
    border-radius: 4px;
    background: #fff;
}
#ai-chat-container.ai-fullscreen {
    position: fixed;
    inset: 12px;
    z-index: 99999;
    width: auto;
    height: auto;
    max-width: none;
    max-height: none;
    box-shadow: 0 24px 80px rgba(0, 0, 0, 0.35);
}
#ai-chat-toolbar {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    padding: 6px 8px 0 8px;
    background: transparent;
}
.ai-toolbar-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: 1px solid #cfd8dc;
    border-radius: 4px;
    background: #fff;
    color: #374151;
    cursor: pointer;
    font-size: 15px;
    line-height: 1;
    padding: 0;
}
.ai-toolbar-btn:hover {
    background: #f3f4f6;
}
#ai-chat-container.ai-fullscreen .ai-toolbar-btn {
    background: #fff;
}
body.ai-chat-locked {
    overflow: hidden;
}
#ai-chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
    background: #f9f9f9;
}
.ai-message {
    position: relative;
    margin-bottom: 10px;
    padding: 10px 38px 10px 12px;
    border-radius: 6px;
    max-width: 80%;
    word-wrap: break-word;
    white-space: pre-wrap;
    line-height: 1.45;
}
.ai-message-content {
    white-space: pre-wrap;
}
.ai-message pre.ai-code-block {
    margin: 8px 0 0 0;
    padding: 10px 12px;
    background: #111827;
    color: #f9fafb;
    border-radius: 6px;
    overflow-x: auto;
    white-space: pre;
    font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
    font-size: 13px;
    line-height: 1.5;
}
.ai-copy-btn {
    position: absolute;
    right: 8px;
    top: 8px;
    border: 0;
    background: transparent;
    color: #68707a;
    cursor: pointer;
    padding: 2px 4px;
    font-size: 15px;
    line-height: 1;
}
.ai-copy-btn:hover {
    color: #1f2937;
}
.ai-copy-btn:focus {
    outline: 2px solid #60a5fa;
    outline-offset: 2px;
}
.ai-message.user {
    background: #d4edff;
    margin-left: auto;
    border: 1px solid #b8daff;
}
.ai-message.assistant {
    background: #e8f5e9;
    margin-right: auto;
    border: 1px solid #c8e6c9;
}
.ai-message.error {
    background: #ffebee;
    margin-right: auto;
    border: 1px solid #ffcdd2;
    color: #c62828;
}
.ai-message.system {
    background: #fff3e0;
    margin: 0 auto;
    border: 1px solid #ffe0b2;
    text-align: center;
    font-style: italic;
    max-width: 80%;
}
#ai-chat-input-area {
    display: flex;
    padding: 8px;
    border-top: 1px solid #ccc;
    background: #fff;
}
#ai-chat-input {
    flex: 1;
    padding: 8px;
    border: 1px solid #ccc;
    border-radius: 4px;
    resize: none;
    font-family: inherit;
    font-size: 14px;
}
#ai-chat-send {
    margin-left: 8px;
    padding: 8px 20px;
    background: #4285f4;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}
#ai-chat-send:hover {
    background: #3367d6;
}
#ai-chat-send:disabled {
    background: #ccc;
    cursor: not-allowed;
}
.ai-typing {
    color: #888;
    font-style: italic;
    padding: 8px 12px;
}
</style>

<script type="text/javascript">
(function() {
    var messagesEl = document.getElementById("ai-chat-messages");
    var inputEl = document.getElementById("ai-chat-input");
    var sendBtn = document.getElementById("ai-chat-send");
    var chatContainer = document.getElementById("ai-chat-container");
    var toggleSizeBtn = document.getElementById("ai-chat-toggle-size");
    var sessionSeed = document.getElementById("ai-session-id").value;
    var chatUrl = document.getElementById("ai-chat-url").value;
    var fullscreen = false;
    var userSessionId = document.getElementById("ai-user-session-id").value || "default";
    var sessionStorageKey = "base-ai-chat-session-id:" + userSessionId;

    function getChatSessionId() {
        try {
            var saved = window.localStorage.getItem(sessionStorageKey);
            if (saved) {
                return saved;
            }
            var generated = (window.crypto && window.crypto.randomUUID)
                ? "ai_" + window.crypto.randomUUID()
                : (sessionSeed || "ai_") + Math.random().toString(36).slice(2) + Date.now().toString(36);
            window.localStorage.setItem(sessionStorageKey, generated);
            return generated;
        } catch (e) {
            return sessionSeed;
        }
    }

    var sessionId = getChatSessionId();
    document.getElementById("ai-session-id").value = sessionId;

    function addMessage(text, type) {
        var div = document.createElement("div");
        div.className = "ai-message " + type;

        if (type !== "system") {
            var copyBtn = document.createElement("button");
            copyBtn.type = "button";
            copyBtn.className = "ai-copy-btn";
            copyBtn.title = "Copy message";
            copyBtn.setAttribute("aria-label", "Copy message");
            copyBtn.textContent = "⧉";
            copyBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                ev.stopPropagation();
                var copyText = div.getAttribute("data-copy-text") || text || "";
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(copyText).catch(function() {});
                } else {
                    var ta = document.createElement("textarea");
                    ta.value = copyText;
                    ta.style.position = "fixed";
                    ta.style.left = "-9999px";
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand("copy"); } catch (e) {}
                    document.body.removeChild(ta);
                }
            });
            div.appendChild(copyBtn);
        }

        var content = document.createElement("div");
        content.className = "ai-message-content";
        div.appendChild(content);
        renderMessageContent(content, text);
        div.setAttribute("data-copy-text", text);
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        return div;
    }

    function setFullscreen(enabled) {
        fullscreen = !!enabled;
        chatContainer.classList.toggle("ai-fullscreen", fullscreen);
        document.body.classList.toggle("ai-chat-locked", fullscreen);
        toggleSizeBtn.textContent = fullscreen ? "⤓" : "⤢";
        toggleSizeBtn.title = fullscreen ? "Exit fullscreen" : "' . $i18n->get('[[palette.icon_maximize]]') . '";
        toggleSizeBtn.setAttribute("aria-label", fullscreen ? "' . $i18n->get('[[base-ai.exit_fullscreen]]') . '" : "' . $i18n->get('[[palette.icon_maximize]]') . '");
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function renderMessageContent(container, text) {
        container.innerHTML = "";
        var raw = String(text || "");
        var fence = /```([\s\S]*?)```/g;
        var lastIndex = 0;
        var match;

        while ((match = fence.exec(raw)) !== null) {
            if (match.index > lastIndex) {
                var span = document.createElement("span");
                span.textContent = raw.slice(lastIndex, match.index);
                container.appendChild(span);
            }

            var pre = document.createElement("pre");
            pre.className = "ai-code-block";
            var code = document.createElement("code");
            code.textContent = match[1].replace(/^\n+/, "").replace(/\n+$/, "");
            pre.appendChild(code);
            container.appendChild(pre);

            lastIndex = fence.lastIndex;
        }

        if (lastIndex < raw.length) {
            var tail = document.createElement("span");
            tail.textContent = raw.slice(lastIndex);
            container.appendChild(tail);
        }
    }

    function addTyping() {
        var div = document.createElement("div");
        div.className = "ai-typing";
        div.id = "ai-typing";
        div.textContent = "' . $i18n->get('[[base-ai.chat_typing]]') . '";
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function removeTyping() {
        var el = document.getElementById("ai-typing");
        if (el) { el.parentNode.removeChild(el); }
    }

    function sendMessage() {
        var message = inputEl.value.trim();
        if (!message) return;

        addMessage(message, "user");
        inputEl.value = "";
        sendBtn.disabled = true;
        addTyping();

        var xhr = new XMLHttpRequest();
        xhr.open("POST", chatUrl, true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

        var lastIndex = 0;
        var responseText = "";
        var assistantMsgEl = null;
        var accumulatedMessage = "";

        xhr.onprogress = function() {
            var newData = xhr.responseText.substring(lastIndex);
            lastIndex = xhr.responseText.length;
            responseText = xhr.responseText;

            var lines = newData.split("\n");
            for (var i = 0; i < lines.length; i++) {
                var line = lines[i].trim();
                if (line.startsWith("data: ")) {
                    var payload = line.substring(6).trim();
                    if (payload === "[DONE]") continue;
                    try {
                        var json = JSON.parse(payload);
                        if (json.message) {
                            removeTyping();
                            accumulatedMessage += json.message;
                            if (!assistantMsgEl) {
                                assistantMsgEl = addMessage(accumulatedMessage, "assistant");
                            } else {
                                assistantMsgEl.setAttribute("data-copy-text", accumulatedMessage);
                                var contentNode = assistantMsgEl.querySelector(".ai-message-content");
                                if (contentNode) {
                                    renderMessageContent(contentNode, accumulatedMessage);
                                }
                            }
                            messagesEl.scrollTop = messagesEl.scrollHeight;
                        }
                        if (json.error) {
                            removeTyping();
                            addMessage(json.error, "error");
                        }
                    } catch(e) {}
                }
            }
        };

        xhr.onloadend = function() {
            removeTyping();
            sendBtn.disabled = false;
            inputEl.focus();
            if (!responseText) {
                addMessage("' . $i18n->get('[[base-ai.chat_no_response]]') . '", "error");
            }
        };

        xhr.onerror = function() {
            removeTyping();
            sendBtn.disabled = false;
            addMessage("' . $i18n->get('[[base-ai.chat_connection_error]]') . '", "error");
        };

        var csrfTokenName = document.getElementById(\'csrf-token\').name;
        var csrfHash = document.getElementById(\'csrf-token\').value;
        var username = document.getElementById(\'ai-username\').value;
        var userSessionId = document.getElementById(\'ai-user-session-id\').value;
        var params = \'message=\' + encodeURIComponent(message) + \'&session_id=\' + encodeURIComponent(sessionId);
        params += \'&\' + encodeURIComponent(csrfTokenName) + \'=\' + encodeURIComponent(csrfHash);
        params += \'&username=\' + encodeURIComponent(username) + \'&user_session_id=\' + encodeURIComponent(userSessionId);

        xhr.send(params);
    }

    sendBtn.addEventListener("click", sendMessage);
    toggleSizeBtn.addEventListener("click", function() {
        setFullscreen(!fullscreen);
    });
    inputEl.addEventListener("keydown", function(e) {
        if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
})();
</script>';

        $xff = $factory->getRawHTML("chat_html", $chat_html);
        $block->addFormField(
            $xff,
            $factory->getLabel("chat_html"),
            $defaultPage
        );

        $page_body[] = $block->toHtml();

        return $BxPage->render($page_module, $page_body);
    }

    public function send()
    {
        $CI = get_instance();

        if (!$CI->getAllowed('serverAdministrator')) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        $message = $this->request->getPost('message');
        $session_id = $this->request->getPost('session_id');
        $ai_username = $this->request->getPost('username') ?? $this->request->getPost('ai_username') ?? '';
        $ai_user_session_id = $this->request->getPost('user_session_id') ?? $this->request->getPost('ai_user_session_id') ?? '';

        if (!$message) {
            return $this->response->setJSON(['error' => 'No message provided'])->setStatusCode(400);
        }

        if (!$session_id) {
            $session_id = uniqid('ai_', true);
        }

        $run_as = 'blueonyx_ai';
        $serviceAuthKey = $this->getAiServiceAuthKey();
        if ($serviceAuthKey === '') {
            return $this->response->setJSON(['error' => 'AI service auth key missing'])->setStatusCode(503);
        }

        // Stream response from Python service
        $service_url = 'http://127.0.0.1:1972/chat';
        $payload = json_encode([
            'message' => $message,
            'session_id' => $session_id,
            'run_as' => $run_as,
            'ai_username' => $ai_username,
            'ai_user_session_id' => $ai_user_session_id,
        ]);

        // Use curl for streaming POST
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $service_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: text/event-stream',
                'X-BlueOnyx-AI-Auth: ' . $serviceAuthKey,
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_WRITEFUNCTION => function($ch, $data) {
                echo $data;
                ob_flush();
                flush();
                return strlen($data);
            },
        ]);

        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            echo "event: error\ndata: " . json_encode(['message' => "Connection to AI service failed: $error"]) . "\n\n";
        }
        curl_close($ch);
    }
}

/*
Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
All Rights Reserved.

1. Redistributions of source code must retain the above copyright 
   notice, this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright 
   notice, this list of conditions and the following disclaimer in 
   the documentation and/or other materials provided with the 
   distribution.

3. Neither the name of the copyright holder nor the names of its 
   contributors may be used to endorse or promote products derived 
   from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 
"AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT 
LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS 
FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, 
INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; 
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER 
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT 
LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN 
ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE 
POSSIBILITY OF SUCH DAMAGE.

You acknowledge that this software is not designed or intended for 
use in the design, construction, operation or maintenance of any 
nuclear facility.

*/
?>
