<div class="card shadow-sm border-0 chat-card">
    <div class="card-body fs-6">

        {{-- Quick buttons --}}
        <div class="mb-3">
            <strong>{!! exmtrans('ai_assistant.help') !!}</strong>
        </div>
        <div class="d-flex justify-content-between gap-2" id="quick-buttons-container">
            <button class="btn btn-primary flex-fill" data-feature="custom_table">
                <i class="fa fa-table me-2"></i>{!! exmtrans('ai_assistant.feature.custom_table') !!}
            </button>
            <button class="btn btn-primary flex-fill" data-feature="workflow">
                <i class="fa fa-project-diagram me-2"></i>{!! exmtrans('ai_assistant.feature.workflow') !!}
            </button>
            <button class="btn btn-primary flex-fill" data-feature="schedule&notifications">
                <i class="fa fa-bell me-2"></i>{!! exmtrans('ai_assistant.feature.schedule_notifications') !!}
            </button>
        </div>

        {{-- Chat messages --}}
        <div id="chat-messages" class="chat-messages">
            <div id="chat-placeholder" class="text-muted">
                {!! exmtrans('ai_assistant.chat_box_placeholder') !!}
            </div>
        </div>

        {{-- Action buttons --}}
        <div id="action-buttons-container" class="chat-actions d-none border-top bg-light p-2">
            <div class="d-flex justify-content-end gap-2">
                <button class="btn btn-primary" data-action="edit"><i class="fa fa-pen me-1"></i>{!! exmtrans('ai_assistant.edit_button') !!}</button>
                <button class="btn btn-success" data-action="create"><i class="fa fa-check me-1"></i>{!! exmtrans('ai_assistant.create_button') !!}</button>
                <button class="btn btn-warning" data-action="cancel"><i class="fa fa-times me-1"></i>{!! exmtrans('ai_assistant.cancel_button') !!}</button>
            </div>
        </div>

        {{-- Input --}}
        <div class="chat-input p-2 border-top bg-white">
            <textarea id="chat-input" class="form-control mb-2" rows="1"
                      placeholder="{!! exmtrans('ai_assistant.input_placeholder') !!}"></textarea>
            <div class="d-flex justify-content-end">
                <button class="btn btn-primary" id="send-btn">
                    <i class="fa fa-paper-plane"></i> {!! exmtrans('ai_assistant.send_button') !!}
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    .chat-card {
        height: calc(100vh - 150px);
        display: flex;
        flex-direction: column;
    }

    .chat-card .card-body {
        display: flex;
        flex-direction: column;
        height: 100%;
        padding: 0 !important;
    }

    .chat-messages {
        flex-grow: 1;
        overflow-y: auto;
        background-color: #f9fafb;
        border-bottom: 1px solid #eee;
        padding: 1rem;
    }

    .chat-header,
    .chat-actions,
    .chat-input {
        flex-shrink: 0;
    }

    .chat-input textarea {
        resize: none;
        overflow: hidden;
        width: 100%;
    }

    .chat-loading {
        cursor: progress !important;
    }
    .chat-loading * {
        cursor: progress !important;
    }
</style>

<script>
    (function(){
        let currentConversationUuid = null;
        const chatMessages = document.getElementById('chat-messages');
        const textarea = document.getElementById('chat-input');
        const sendBtn = document.getElementById('send-btn');
        const actionButtonsContainer = document.getElementById('action-buttons-container');
        const quickButtons = document.querySelectorAll('.btn[data-feature]');
        const placeholderText = '{{ exmtrans('ai_assistant.chat_box_placeholder') }}';
        const notifyText = '{{ exmtrans('ai_assistant.notify') }}';
        const body = document.body;

        function clearChat() {
            chatMessages.innerHTML = `<div id="chat-placeholder" class="text-muted">${placeholderText}</div>`;
            toggleActionButtons(false);
            currentConversationUuid = null;
        }

        function autoResize(el) {
            el.style.height = 'auto';
            el.style.height = (el.scrollHeight) + 'px';
        }

        function appendMessage(text, role) {
            const wrapper = document.createElement('div');
            wrapper.className = `message d-flex mb-2 ${role === 'user' ? 'justify-content-end' : 'justify-content-start'}`;
            const bubble = document.createElement('div');
            bubble.className = 'p-2 rounded shadow-sm';
            bubble.style.cssText = `max-width:75%;white-space:pre-wrap; background:${role === 'user' ? '#0d6efd' : '#f0f2f5'}; color:${role === 'user' ? '#fff' : '#000'}`;
            bubble.innerText = text;
            wrapper.appendChild(bubble);

            const existingPlaceholder = document.getElementById('chat-placeholder');
            if (existingPlaceholder) existingPlaceholder.remove();

            chatMessages.appendChild(wrapper);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        /**
         * @param {boolean} show - data.showActionButtons
         */
        function toggleActionButtons(show) {
            if (show) {
                actionButtonsContainer.classList.remove('d-none');
                textarea.disabled = true;
                sendBtn.disabled = true;
            } else {
                actionButtonsContainer.classList.add('d-none');
                textarea.disabled = false;
                sendBtn.disabled = false;
            }
        }

        /**
         * @param {boolean} isLoading
         */
        function toggleLoading(isLoading) {
            if (isLoading) {
                body.classList.add('chat-loading');
                sendBtn.disabled = true;
                quickButtons.forEach(btn => btn.disabled = true);
                actionButtonsContainer.querySelectorAll('button').forEach(btn => btn.disabled = true);
            } else {
                body.classList.remove('chat-loading');
                if (actionButtonsContainer.classList.contains('d-none')) {
                    sendBtn.disabled = false;
                }
                quickButtons.forEach(btn => btn.disabled = false);
                if (!actionButtonsContainer.classList.contains('d-none')) {
                    actionButtonsContainer.querySelectorAll('button').forEach(btn => btn.disabled = false);
                }
            }
        }

        function handleStartConversation(featureType) {
            clearChat();
            toggleLoading(true);
            fetch('/assistant/start', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ feature_type: featureType }),
            })
                .then(response => response.json())
                .then(data => {
                    if (data.uuid) {
                        currentConversationUuid = data.uuid;
                        appendMessage(data.message, 'assistant');
                        if (data.showActionButtons) {
                            toggleActionButtons(true);
                        } else {
                            toggleActionButtons(false);
                        }
                    } else {
                        appendMessage('An error occurred while starting the conversation.', 'assistant');
                        toggleActionButtons(false);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    appendMessage('There was an error connecting to the server. Please try again.', 'assistant');
                    toggleActionButtons(false);
                })
                .finally(() => {
                    toggleLoading(false);
                });
        }

        function handleSendMessage() {
            const text = textarea.value.trim();
            if (!currentConversationUuid) {
                appendMessage(text, 'user');

                const notificationMessage = notifyText;
                appendMessage(notificationMessage, 'assistant');

                textarea.value = '';
                autoResize(textarea);

                return;
            }

            appendMessage(text, 'user');
            textarea.value = '';
            autoResize(textarea);
            toggleActionButtons(false);
            toggleLoading(true);

            fetch('/assistant/send-message', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ uuid: currentConversationUuid, message: text }),
            })
                .then(response => response.json())
                .then(data => {
                    if (data.uuid) {
                        currentConversationUuid = data.uuid;
                        appendMessage(data.message, 'assistant');
                        if (data.showActionButtons) {
                            toggleActionButtons(true);
                        } else {
                            toggleActionButtons(false);
                        }
                    } else {
                        appendMessage(data.message || 'Unable to send message.', 'assistant');
                        toggleActionButtons(false);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    appendMessage('There was an error sending the message. Please try again.', 'assistant');
                    toggleActionButtons(false);
                })
                .finally(() => {
                    toggleLoading(false);
                });
        }

        function handleAction(action) {
            if (!currentConversationUuid) return;

            toggleLoading(true);

            fetch('/assistant/action', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ uuid: currentConversationUuid, action: action }),
            })
                .then(response => response.json())
                .then(data => {
                    appendMessage(data.message, 'assistant');
                    if (action === 'cancel') {
                        clearChat();
                    } else {
                        toggleActionButtons(data.showActionButtons);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    appendMessage('An error occurred while performing the action. Please try again.', 'assistant');
                    toggleActionButtons(false);
                })
                .finally(() => {
                    toggleLoading(false);
                });
        }

        function initChat() {
            toggleLoading(false);

            quickButtons.forEach(button => {
                button.addEventListener('click', () => {
                    handleStartConversation(button.dataset.feature);
                });
            });

            sendBtn.addEventListener('click', handleSendMessage);
            textarea.addEventListener('keydown', function(e) {
                if (!textarea.disabled && (e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    handleSendMessage();
                }
            });
            textarea.addEventListener('input', () => autoResize(textarea));

            actionButtonsContainer.addEventListener('click', (e) => {
                const action = e.target.closest('button')?.dataset.action;
                if (action) {
                    handleAction(action);
                }
            });

            autoResize(textarea);
            clearChat();
        }

        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            initChat();
        } else {
            document.addEventListener('DOMContentLoaded', initChat);
        }
        document.addEventListener('turbolinks:load', initChat);
        document.addEventListener('turbo:load', initChat);
    })();
</script>
