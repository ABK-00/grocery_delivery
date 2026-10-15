(() => {
    const root = document.getElementById('somameAi');
    if (!root) return;

    const launcher = document.getElementById('somameAiLauncher');
    const panel = document.getElementById('somameAiPanel');
    const closeBtn = document.getElementById('somameAiClose');
    const newChatBtn = document.getElementById('somameAiNewChat');
    const form = document.getElementById('somameAiForm');
    const input = document.getElementById('somameAiInput');
    const send = document.getElementById('somameAiSend');
    const messages = document.getElementById('somameAiMessages');

    const endpoint = root.dataset.endpoint;
    const role = root.dataset.role || 'customer';
    const storageKey = 'somame_ai_conversation_' + role;

    let conversationId = sessionStorage.getItem(storageKey);
    let busy = false;

    const scrollBottom = () => {
        messages.scrollTop = messages.scrollHeight;
    };

    const addMessage = (text, type = 'assistant') => {
        const row = document.createElement('div');
        row.className = 'somame-ai-message ' + type;

        const bubble = document.createElement('div');
        bubble.className = 'somame-ai-bubble';
        bubble.textContent = text;

        row.appendChild(bubble);
        messages.appendChild(row);
        scrollBottom();
        return row;
    };

    const addTyping = () => {
        const row = document.createElement('div');
        row.className = 'somame-ai-message assistant';
        row.innerHTML =
            '<div class="somame-ai-bubble">' +
            '<span class="somame-ai-typing">' +
            '<span></span><span></span><span></span>' +
            '</span></div>';

        messages.appendChild(row);
        scrollBottom();
        return row;
    };

    const setOpen = (open) => {
        panel.classList.toggle('open', open);
        panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (open) {
            setTimeout(() => input.focus(), 50);
            scrollBottom();
        }
    };

    const setBusy = (value) => {
        busy = value;
        send.disabled = value;
        input.disabled = value;
    };

    launcher.addEventListener('click', () => {
        setOpen(!panel.classList.contains('open'));
    });

    closeBtn.addEventListener('click', () => setOpen(false));

    newChatBtn.addEventListener('click', () => {
        conversationId = null;
        sessionStorage.removeItem(storageKey);
        messages.innerHTML = '';
        addMessage(
            'New conversation started. How can I help?',
            'assistant'
        );
        input.focus();
    });

    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 110) + 'px';
    });

    input.addEventListener('keydown', (event) => {
        if (
            event.key === 'Enter'
            && !event.shiftKey
        ) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (busy) return;

        const message = input.value.trim();
        if (!message) return;

        addMessage(message, 'user');
        input.value = '';
        input.style.height = 'auto';
        setBusy(true);

        const typing = addTyping();

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    message,
                    conversation_id: conversationId
                        ? Number(conversationId)
                        : null
                })
            });

            const data = await response.json().catch(() => null);
            typing.remove();

            if (
                !response.ok
                || !data
                || data.success !== true
            ) {
                addMessage(
                    data?.message
                        || 'The assistant is unavailable right now.',
                    'error'
                );
                return;
            }

            if (data.conversation_id) {
                conversationId = String(data.conversation_id);
                sessionStorage.setItem(
                    storageKey,
                    conversationId
                );
            }

            addMessage(
                data.message || 'No response received.',
                'assistant'
            );
        } catch (error) {
            typing.remove();
            addMessage(
                'Unable to reach the assistant. Please try again.',
                'error'
            );
        } finally {
            setBusy(false);
            input.focus();
        }
    });
})();
