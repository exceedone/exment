<div class="card shadow-sm border-0 chat-card">
    <div class="card-body fs-6">

        {{-- Quick buttons --}}
        <div class="mb-3">
            <strong>{!! exmtrans('ai_assistant.help') !!}</strong>
        </div>
        <div class="d-flex justify-content-between gap-2" id="quick-buttons-container">
            <button class="btn btn-primary flex-fill" data-feature="custom_table">
                <i class="fa fa-table me-2"></i> {!! exmtrans('ai_assistant.feature.custom_table') !!}
            </button>
            <button class="btn btn-primary flex-fill" data-feature="workflow">
                <i class="fa fa-project-diagram me-2"></i> {!! exmtrans('ai_assistant.feature.workflow') !!}
            </button>
            <button class="btn btn-primary flex-fill" data-feature="calendar">
                <i class="fa fa-bell me-2"></i> {!! exmtrans('ai_assistant.feature.schedule_notifications') !!}
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

        {{-- Calendar target selector --}}
        <div id="calendar-target-summary" class="calendar-target-summary d-none border-top bg-white p-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="text-muted" id="calendar-target-summary-text"></div>
                <button class="btn btn-default btn-sm" id="calendar-target-open-btn" type="button">
                    <i class="fa fa-users me-1"></i>{!! exmtrans('ai_assistant.calendar_targets.select_button') !!}
                </button>
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

<div id="calendar-target-modal" class="calendar-target-modal d-none" role="dialog" aria-modal="true" aria-labelledby="calendar-target-modal-title">
    <div class="calendar-target-dialog">
        <div class="calendar-target-header">
            <h5 id="calendar-target-modal-title">{!! exmtrans('ai_assistant.calendar_targets.title') !!}</h5>
            <button type="button" class="btn btn-default btn-sm" id="calendar-target-close-btn" aria-label="{!! exmtrans('ai_assistant.calendar_targets.close') !!}">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div class="calendar-target-toolbar">
            <input type="search" class="form-control" id="calendar-target-search" placeholder="{!! exmtrans('ai_assistant.calendar_targets.search_placeholder') !!}">
            <div class="calendar-target-toolbar-actions">
                <button type="button" class="btn btn-default btn-sm" id="calendar-target-select-visible-btn">{!! exmtrans('ai_assistant.calendar_targets.select_visible') !!}</button>
                <button type="button" class="btn btn-default btn-sm" id="calendar-target-clear-btn">{!! exmtrans('ai_assistant.calendar_targets.clear') !!}</button>
            </div>
        </div>
        <div class="calendar-target-list" id="calendar-target-users-list"></div>
        <div class="calendar-target-footer">
            <div class="text-muted" id="calendar-target-selected-count"></div>
            <div class="calendar-target-footer-actions">
                <button type="button" class="btn btn-default" id="calendar-target-cancel-btn">{!! exmtrans('ai_assistant.calendar_targets.cancel') !!}</button>
                <button type="button" class="btn btn-primary" id="calendar-target-apply-btn">{!! exmtrans('ai_assistant.calendar_targets.apply') !!}</button>
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
        overflow-y: auto;
        width: 100%;
        max-height: 120px;
        line-height: 1.5;
    }

    .chat-loading {
        cursor: progress !important;
    }
    .chat-loading * {
        cursor: progress !important;
    }

    .calendar-target-summary {
        flex-shrink: 0;
    }

    .calendar-target-modal {
        align-items: center;
        background: rgba(15, 23, 42, 0.45);
        bottom: 0;
        display: flex;
        justify-content: center;
        left: 0;
        padding: 1rem;
        position: fixed;
        right: 0;
        top: 0;
        z-index: 1050;
    }

    .calendar-target-modal.d-none {
        display: none !important;
    }

    .calendar-target-dialog {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.22);
        display: flex;
        flex-direction: column;
        max-height: min(720px, calc(100vh - 2rem));
        max-width: 760px;
        overflow: hidden;
        width: 100%;
    }

    .calendar-target-header,
    .calendar-target-footer,
    .calendar-target-toolbar {
        align-items: center;
        display: flex;
        gap: 0.75rem;
        padding: 0.75rem 1rem;
    }

    .calendar-target-header,
    .calendar-target-footer {
        justify-content: space-between;
    }

    .calendar-target-header {
        border-bottom: 1px solid #e5e7eb;
    }

    .calendar-target-header h5 {
        font-size: 1.4rem;
        font-weight: 600;
        color: #111827;
        line-height: 1.4;
        margin: 0;
    }

    .calendar-target-toolbar {
        border-bottom: 1px solid #e5e7eb;
        flex-wrap: wrap;
    }

    .calendar-target-toolbar .form-control {
        flex: 1 1 260px;
    }

    .calendar-target-toolbar-actions,
    .calendar-target-footer-actions {
        display: flex;
        gap: 0.5rem;
    }

    .calendar-target-list {
        flex: 1 1 auto;
        overflow-y: auto;
        padding: 0.5rem 0;
    }

    .calendar-target-group {
        border-bottom: 1px solid #eef2f7;
    }

    .calendar-target-group-header {
        align-items: center;
        background: #f8fafc;
        display: flex;
        gap: 0.75rem;
        min-height: 46px;
        padding: 0.6rem 1rem;
    }

    .calendar-target-item {
        align-items: center;
        display: flex;
        gap: 0.75rem;
        min-height: 54px;
        padding: 0.55rem 1rem;
    }

    .calendar-target-group-title {
        color: #111827;
        font-weight: 700;
        line-height: 1.35;
    }

    .calendar-target-item {
        position: relative;
        align-items: center;
        display: flex;
        gap: 0.75rem;
        min-height: 54px;
        padding: 0.55rem 1rem 0.55rem 3.5rem;
        border-bottom: 1px solid #f8fafc;
    }

    .calendar-target-item:last-child {
        border-bottom: none;
    }

    .calendar-target-item:hover {
        background: #f1f5f9;
    }

    .calendar-target-item::before {
        content: "";
        position: absolute;
        left: 1.5rem;
        top: 0;
        bottom: 0;
        width: 1px;
        background-color: #cbd5e1;
    }

    .calendar-target-item::after {
        content: "";
        position: absolute;
        left: 1.5rem;
        top: 50%;
        width: 1rem;
        height: 1px;
        background-color: #cbd5e1;
    }

    .calendar-target-item:last-child::before {
        bottom: 50%;
    }

    .calendar-target-item input {
        flex: 0 0 auto;
    }

    .calendar-target-name {
        color: #111827;
        font-weight: 600;
        line-height: 1.35;
    }

    .calendar-target-meta {
        color: #6b7280;
        font-size: 0.85rem;
        line-height: 1.35;
    }

    .calendar-target-empty {
        color: #6b7280;
        padding: 1rem;
        text-align: center;
    }

    .calendar-target-footer {
        border-top: 1px solid #e5e7eb;
        flex-wrap: wrap;
    }
</style>

<script>
    (function(){
        const trans = {
            placeholder: @json(exmtrans('ai_assistant.chat_box_placeholder')),
            notify: @json(exmtrans('ai_assistant.notify')),
            errorStart: @json(exmtrans('ai_assistant.error_start')),
            errorConnection: @json(exmtrans('ai_assistant.error_connection')),
            errorSendFail: @json(exmtrans('ai_assistant.error_send_fail')),
            errorSendException: @json(exmtrans('ai_assistant.error_send_exception')),
            errorAction: @json(exmtrans('ai_assistant.error_action')),
            calendarNoTargets: @json(exmtrans('ai_assistant.calendar_targets.no_targets')),
            calendarSelectedSummary: @json(exmtrans('ai_assistant.calendar_targets.selected_summary')),
            calendarSelectedCount: @json(exmtrans('ai_assistant.calendar_targets.selected_count')),
            calendarOrganizationUserCount: @json(exmtrans('ai_assistant.calendar_targets.organization_user_count')),
            calendarNoItems: @json(exmtrans('ai_assistant.calendar_targets.no_items')),
        };

        let currentConversationUuid = null;
        let featureType = null;
        let calendarTargetOptions = { groups: [] };
        let selectedCalendarTargets = { users: [] };
        let calendarTargetSnapshot = null;
        let initialized = false;
        const chatMessages = document.getElementById('chat-messages');
        const textarea = document.getElementById('chat-input');
        const sendBtn = document.getElementById('send-btn');
        const actionButtonsContainer = document.getElementById('action-buttons-container');
        const quickButtons = document.querySelectorAll('.btn[data-feature]');
        const calendarTargetSummary = document.getElementById('calendar-target-summary');
        const calendarTargetSummaryText = document.getElementById('calendar-target-summary-text');
        const calendarTargetOpenBtn = document.getElementById('calendar-target-open-btn');
        const calendarTargetModal = document.getElementById('calendar-target-modal');
        const calendarTargetCloseBtn = document.getElementById('calendar-target-close-btn');
        const calendarTargetCancelBtn = document.getElementById('calendar-target-cancel-btn');
        const calendarTargetApplyBtn = document.getElementById('calendar-target-apply-btn');
        const calendarTargetSearch = document.getElementById('calendar-target-search');
        const calendarTargetSelectVisibleBtn = document.getElementById('calendar-target-select-visible-btn');
        const calendarTargetClearBtn = document.getElementById('calendar-target-clear-btn');
        const calendarTargetUsersList = document.getElementById('calendar-target-users-list');
        const calendarTargetSelectedCount = document.getElementById('calendar-target-selected-count');
        const body = document.body;

        function clearChat() {
            chatMessages.innerHTML = `<div id="chat-placeholder" class="text-muted">${trans.placeholder}</div>`;
            toggleActionButtons(false);
            currentConversationUuid = null;
            hideCalendarTargetSummary();
            selectedCalendarTargets = { users: [] };
        }

        function autoResize(el) {
            const maxHeight = 120;

            el.style.height = 'auto';

            if (el.scrollHeight <= maxHeight) {
                el.style.height = el.scrollHeight + 'px';
                el.style.overflowY = 'hidden';
            } else {
                el.style.height = maxHeight + 'px';
                el.style.overflowY = 'auto';
            }
        }

        function appendMessage(text, role, confirmUrl = null) {
            const wrapper = document.createElement('div');
            wrapper.className = `message d-flex mb-2 ${role === 'user' ? 'justify-content-end' : 'justify-content-start'}`;
            const bubble = document.createElement('div');
            bubble.className = 'p-2 rounded shadow-sm';
            bubble.style.cssText = `max-width:75%;white-space:pre-wrap; background:${role === 'user' ? '#0d6efd' : '#f0f2f5'}; color:${role === 'user' ? '#fff' : '#000'}`;
            bubble.innerText = text;
            wrapper.appendChild(bubble);

            if (confirmUrl) {
                const actionsWrapper = document.createElement('div');
                actionsWrapper.className = 'mt-1';

                const link = document.createElement('a');
                link.href = confirmUrl;
                link.target = '_blank';
                link.rel = 'noopener';
                link.className = 'text-primary text-decoration-underline';
                link.innerText = confirmUrl;

                actionsWrapper.appendChild(link);
                wrapper.appendChild(actionsWrapper);
            }

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

        function showCalendarTargetSummary() {
            calendarTargetSummary.classList.remove('d-none');
            updateCalendarTargetSummary();
        }

        function hideCalendarTargetSummary() {
            calendarTargetSummary.classList.add('d-none');
        }

        function updateCalendarTargetSummary() {
            const userCount = selectedCalendarTargets.users.length;

            if (userCount === 0) {
                calendarTargetSummaryText.innerText = trans.calendarNoTargets;
            } else {
                calendarTargetSummaryText.innerText = trans.calendarSelectedSummary
                    .replace(':users', userCount);
            }

            calendarTargetSelectedCount.innerText = trans.calendarSelectedCount
                .replace(':users', userCount);
        }

        function setCalendarTargetOptions(options) {
            calendarTargetOptions = {
                groups: Array.isArray(options?.groups) ? options.groups : [],
            };
            selectedCalendarTargets = { users: [] };
            calendarTargetSearch.value = '';
            renderCalendarTargetModal();
            updateCalendarTargetSummary();
        }

        function openCalendarTargetModal() {
            calendarTargetSnapshot = {
                users: selectedCalendarTargets.users.slice(),
            };
            calendarTargetModal.classList.remove('d-none');
            renderCalendarTargetModal();
            calendarTargetSearch.focus();
        }

        function closeCalendarTargetModal() {
            calendarTargetSnapshot = null;
            calendarTargetModal.classList.add('d-none');
        }

        function dismissCalendarTargetModal() {
            if (calendarTargetSnapshot) {
                selectedCalendarTargets = {
                    users: calendarTargetSnapshot.users.slice(),
                };
            }

            calendarTargetSnapshot = null;
            renderCalendarTargetModal();
            updateCalendarTargetSummary();
            calendarTargetModal.classList.add('d-none');
        }

        function getCalendarTargetSearchTerm() {
            return calendarTargetSearch.value.trim().toLowerCase();
        }

        function calendarTargetUserMatchesSearch(user, organizationName, term) {
            if (!term) return true;

            return [
                user.user_name || '',
                user.email || '',
                organizationName || '',
            ].join(' ').toLowerCase().includes(term);
        }

        function renderCalendarTargetModal() {
            const selectedIds = new Set(selectedCalendarTargets.users.map(String));
            const term = getCalendarTargetSearchTerm();
            const visibleGroups = calendarTargetOptions.groups
                .map(group => {
                    return {
                        ...group,
                        allUsers: group.users || [],
                        users: (group.users || []).filter(user => calendarTargetUserMatchesSearch(user, group.organization_name, term)),
                    };
                })
                .filter(group => group.users.length > 0);

            calendarTargetUsersList.innerHTML = '';

            if (visibleGroups.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'calendar-target-empty';
                empty.innerText = trans.calendarNoItems;
                calendarTargetUsersList.appendChild(empty);
                updateCalendarTargetSummary();
                return;
            }

            visibleGroups.forEach(group => {
                const groupWrapper = document.createElement('div');
                groupWrapper.className = 'calendar-target-group';

                const groupUserIds = group.allUsers.map(user => String(user.id));
                const selectedGroupUserCount = groupUserIds.filter(id => selectedIds.has(id)).length;

                const header = document.createElement('label');
                header.className = 'calendar-target-group-header';

                const headerCheckbox = document.createElement('input');
                headerCheckbox.type = 'checkbox';
                headerCheckbox.checked = selectedGroupUserCount === groupUserIds.length;
                headerCheckbox.indeterminate = selectedGroupUserCount > 0 && selectedGroupUserCount < groupUserIds.length;
                headerCheckbox.dataset.calendarTargetGroupCheckbox = '1';
                headerCheckbox.dataset.calendarTargetUserIds = groupUserIds.join(',');

                const headerText = document.createElement('div');
                const headerName = document.createElement('div');
                headerName.className = 'calendar-target-group-title';
                headerName.innerText = group.organization_name || '';

                const headerMeta = document.createElement('div');
                headerMeta.className = 'calendar-target-meta';
                headerMeta.innerText = trans.calendarOrganizationUserCount.replace(':count', group.allUsers.length);

                headerText.appendChild(headerName);
                headerText.appendChild(headerMeta);
                header.appendChild(headerCheckbox);
                header.appendChild(headerText);
                groupWrapper.appendChild(header);

                group.users.forEach(user => {
                    const label = document.createElement('label');
                    label.className = 'calendar-target-item';
                    label.dataset.calendarTargetId = user.id;

                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.checked = selectedIds.has(String(user.id));
                    checkbox.dataset.calendarTargetCheckbox = '1';
                    checkbox.value = user.id;

                    const text = document.createElement('div');
                    const name = document.createElement('div');
                    name.className = 'calendar-target-name';
                    name.innerText = user.user_name || '';

                    text.appendChild(name);

                    if (user.email) {
                        const email = document.createElement('div');
                        email.className = 'calendar-target-meta';
                        email.innerText = user.email;
                        text.appendChild(email);
                    }

                    label.appendChild(checkbox);
                    label.appendChild(text);
                    groupWrapper.appendChild(label);
                });

                calendarTargetUsersList.appendChild(groupWrapper);
            });

            updateCalendarTargetSummary();
        }

        function toggleCalendarTargetSelection(id, selected) {
            const currentSelection = new Set(selectedCalendarTargets.users.map(String));

            if (selected) {
                currentSelection.add(String(id));
            } else {
                currentSelection.delete(String(id));
            }

            selectedCalendarTargets.users = Array.from(currentSelection).map(Number);
            updateCalendarTargetSummary();
        }

        function toggleCalendarTargetGroupSelection(ids, selected) {
            const currentSelection = new Set(selectedCalendarTargets.users.map(String));

            ids.forEach(id => {
                if (selected) {
                    currentSelection.add(String(id));
                } else {
                    currentSelection.delete(String(id));
                }
            });

            selectedCalendarTargets.users = Array.from(currentSelection).map(Number);
            renderCalendarTargetModal();
        }

        function selectVisibleCalendarTargets() {
            const term = getCalendarTargetSearchTerm();
            const selectedIds = new Set(selectedCalendarTargets.users.map(String));

            calendarTargetOptions.groups.forEach(group => {
                (group.users || [])
                    .filter(user => calendarTargetUserMatchesSearch(user, group.organization_name, term))
                    .forEach(user => selectedIds.add(String(user.id)));
            });

            selectedCalendarTargets.users = Array.from(selectedIds).map(Number);
            renderCalendarTargetModal();
        }

        function clearCalendarTargets() {
            selectedCalendarTargets.users = [];
            renderCalendarTargetModal();
        }

        function getParticipantEmailsForRequest() {
            const selectedIds = new Set(selectedCalendarTargets.users.map(String));
            const emails = [];

            calendarTargetOptions.groups.forEach(group => {
                (group.users || []).forEach(user => {
                    if (selectedIds.has(String(user.id)) && user.email) {
                        emails.push(user.email);
                    }
                });
            });

            return Array.from(new Set(emails));
        }

        function handleStartConversation(featureType) {
            clearChat();
            toggleLoading(true);
            console.log('handleStartConversation called', featureType);
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
                        if (featureType === 'calendar') {
                            setCalendarTargetOptions(data.calendarTargets || {});
                            showCalendarTargetSummary();
                            openCalendarTargetModal();
                        }
                        if (data.showActionButtons) {
                            toggleActionButtons(true);
                        } else {
                            toggleActionButtons(false);
                        }
                    } else {
                        appendMessage(trans.errorStart, 'assistant');
                        toggleActionButtons(false);
                    }
                })
                .catch(error => {
                    appendMessage(trans.errorConnection, 'assistant');
                    toggleActionButtons(false);
                })
                .finally(() => {
                    toggleLoading(false);
                });
        }

        function handleSendMessage() {
            const text = textarea.value.trim();
            if (!text) return;

            if (!currentConversationUuid) {
                appendMessage(text, 'user');

                appendMessage(trans.notify, 'assistant');

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
                body: JSON.stringify({
                    uuid: currentConversationUuid,
                    message: text,
                    feature_type: featureType,
                }),
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
                        appendMessage(data.message || trans.errorSendFail, 'assistant');
                        toggleActionButtons(false);
                    }
                })
                .catch(error => {
                    appendMessage(trans.errorSendException, 'assistant');
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
                body: JSON.stringify({
                    uuid: currentConversationUuid,
                    action: action,
                    feature_type: featureType,
                    participant_emails: featureType === 'calendar' && action === 'create'
                        ? getParticipantEmailsForRequest()
                        : undefined,
                }),
            })
                .then(response => response.json())
                .then(data => {
                    appendMessage(data.message, 'assistant', data.confirmEditUrl);
                    if (action === 'cancel') {
                        clearChat();
                        featureType = null;
                    }
                    else if (action === 'create') {
                        toggleActionButtons(data.showActionButtons);
                        // Workflow request_actions
                        if (!data.showActionButtons) {
                            featureType = null;
                            currentConversationUuid = null;
                            appendMessage(trans.notify, 'assistant');
                        }
                    } else {
                        toggleActionButtons(data.showActionButtons);
                    }
                })
                .catch(error => {
                    appendMessage(trans.errorAction, 'assistant');
                    toggleActionButtons(false);
                })
                .finally(() => {
                    toggleLoading(false);
                });
        }

        function initChat() {
            if (initialized) return;
            initialized = true;
            toggleLoading(false);

            quickButtons.forEach(button => {
                button.addEventListener('click', () => {
                    featureType = button.dataset.feature;
                    handleStartConversation(featureType);
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

            calendarTargetOpenBtn.addEventListener('click', openCalendarTargetModal);
            calendarTargetCloseBtn.addEventListener('click', dismissCalendarTargetModal);
            calendarTargetCancelBtn.addEventListener('click', dismissCalendarTargetModal);
            calendarTargetApplyBtn.addEventListener('click', () => {
                updateCalendarTargetSummary();
                closeCalendarTargetModal();
            });
            calendarTargetModal.addEventListener('click', (e) => {
                if (e.target === calendarTargetModal) {
                    dismissCalendarTargetModal();
                }
            });
            calendarTargetSearch.addEventListener('input', renderCalendarTargetModal);
            calendarTargetSelectVisibleBtn.addEventListener('click', selectVisibleCalendarTargets);
            calendarTargetClearBtn.addEventListener('click', clearCalendarTargets);
            calendarTargetUsersList.addEventListener('change', (e) => {
                const groupCheckbox = e.target.closest('[data-calendar-target-group-checkbox]');
                if (groupCheckbox) {
                    const ids = groupCheckbox.dataset.calendarTargetUserIds.split(',').filter(Boolean);
                    toggleCalendarTargetGroupSelection(ids, groupCheckbox.checked);
                    return;
                }

                const checkbox = e.target.closest('[data-calendar-target-checkbox]');
                if (checkbox) {
                    toggleCalendarTargetSelection(checkbox.value, checkbox.checked);
                    renderCalendarTargetModal();
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
