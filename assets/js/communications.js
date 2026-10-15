(() => {
  const app = document.getElementById('communicationsApp');
  if (!app) return;

  const base = app.dataset.baseUrl || '';
  const portal = app.dataset.portal || '';
  const me = Number(app.dataset.userId || 0);
  const csrf = app.dataset.csrf || '';

  let threadId = Number(app.dataset.threadId || 0);
  let targetId = Number(app.dataset.targetId || 0);
  let targetName = '';
  let targetRole = '';
  let lastMessageId = 0;

  let callId = Number(app.dataset.callId || 0);
  let peer = null;
  let localStream = null;
  let lastSignalId = 0;
  let callMode = '';
  let connectedAt = 0;
  let timerHandle = null;

  const q = (id) => document.getElementById(id);
  const threadList = q('threadList');
  const contactList = q('contactList');
  const messagesPane = q('messagesPane');
  const chatEmpty = q('chatEmpty');
  const chatActive = q('chatActive');
  const chatName = q('chatName');
  const chatRole = q('chatRole');
  const chatAvatar = q('chatAvatar');
  const messageForm = q('messageForm');
  const messageInput = q('messageInput');
  const sendMessage = q('sendMessage');
  const startCall = q('startCall');
  const contactSearch = q('contactSearch');

  const callOverlay = q('callOverlay');
  const callName = q('callName');
  const callAvatar = q('callAvatar');
  const callStatus = q('callStatus');
  const callTimer = q('callTimer');
  const acceptCall = q('acceptCall');
  const endCall = q('endCall');
  const muteCall = q('muteCall');
  const remoteAudio = q('remoteAudio');

  const roleLabel = (role) => ({
    super_admin: 'Super Admin',
    admin: 'Company Admin',
    staff: 'Staff',
    delivery_partner: 'Delivery Partner',
    customer: 'Customer'
  }[role] || 'User');

  const initials = (name) =>
    String(name || '?').trim().charAt(0).toUpperCase() || '?';

  const apiUrl = (action, extra = {}) => {
    const url = new URL(base + '/api/communications.php', location.origin);
    url.searchParams.set('portal', portal);
    url.searchParams.set('action', action);
    Object.entries(extra).forEach(([k, v]) => {
      if (v !== null && v !== undefined && v !== '') {
        url.searchParams.set(k, String(v));
      }
    });
    return url;
  };

  const get = async (action, extra = {}) => {
    const response = await fetch(apiUrl(action, extra), {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.success) {
      throw new Error(data?.message || 'Request failed.');
    }
    return data;
  };

  const post = async (action, body = {}) => {
    const response = await fetch(apiUrl(action), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf
      },
      body: JSON.stringify(body)
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.success) {
      throw new Error(data?.message || 'Request failed.');
    }
    return data;
  };

  const formatTime = (value) => {
    const date = new Date(String(value || '').replace(' ', 'T'));
    return Number.isNaN(date.getTime())
      ? ''
      : date.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
  };

  const appendMessage = (row) => {
    const id = Number(row.id || 0);
    if (id && messagesPane.querySelector('[data-message-id="'+id+'"]')) return;

    const wrap = document.createElement('div');
    wrap.className = 'chat-message ' + (Number(row.sender_id) === me ? 'mine' : 'theirs');
    if (id) wrap.dataset.messageId = String(id);

    const bubble = document.createElement('div');
    bubble.className = 'chat-bubble';
    bubble.textContent = row.message || '';

    const time = document.createElement('small');
    time.className = 'chat-time';
    time.textContent = formatTime(row.created_at);

    wrap.append(bubble, time);
    messagesPane.appendChild(wrap);
    lastMessageId = Math.max(lastMessageId, id);
  };

  const setHeader = (id, name, role) => {
    targetId = Number(id);
    targetName = name || 'Conversation';
    targetRole = role || '';

    chatName.textContent = targetName;
    chatRole.textContent = roleLabel(targetRole);
    chatAvatar.textContent = initials(targetName);

    chatEmpty.classList.add('d-none');
    chatActive.classList.remove('d-none');
    messageInput.disabled = false;
    sendMessage.disabled = false;
    startCall.disabled = false;

    document.querySelectorAll('[data-contact-id]').forEach((node) => {
      node.classList.toggle('active', Number(node.dataset.contactId) === targetId);
    });
  };

  const loadMessages = async (reset = false) => {
    if (!threadId) return;
    if (reset) {
      messagesPane.innerHTML = '';
      lastMessageId = 0;
    }
    const data = await get('messages', {
      thread_id: threadId,
      after_id: lastMessageId
    });
    data.messages.forEach(appendMessage);
    if (data.messages.length) {
      messagesPane.scrollTop = messagesPane.scrollHeight;
    }
  };

  const openContact = async (id, name, role) => {
    const data = await post('thread', {target_id: Number(id)});
    threadId = Number(data.thread_id || 0);
    setHeader(id, name, role);

    const url = new URL(location.href);
    url.searchParams.set('portal', portal);
    url.searchParams.set('with', String(id));
    url.searchParams.delete('call');
    history.replaceState({}, '', url);

    await loadMessages(true);
    await loadThreads();
  };

  const loadThreads = async () => {
    try {
      const data = await get('threads');
      threadList.innerHTML = '';

      data.threads.forEach((t) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'thread-row';
        if (Number(t.other_user_id) === targetId) btn.classList.add('active');

        const avatar = document.createElement('span');
        avatar.className = 'contact-avatar';
        avatar.textContent = initials(t.other_name);

        const copy = document.createElement('span');
        copy.className = 'contact-copy';
        copy.innerHTML = '<strong></strong><small></small>';
        copy.querySelector('strong').textContent = t.other_name || 'User';
        copy.querySelector('small').textContent = t.last_message || roleLabel(t.other_role);

        const meta = document.createElement('span');
        meta.className = 'thread-meta';
        const unread = Number(t.unread_count || 0);
        if (unread) {
          const badge = document.createElement('span');
          badge.className = 'unread-badge';
          badge.textContent = unread > 99 ? '99+' : String(unread);
          meta.appendChild(badge);
        }

        btn.append(avatar, copy, meta);
        btn.addEventListener('click', () => {
          openContact(t.other_user_id, t.other_name, t.other_role)
            .catch((e) => alert(e.message));
        });
        threadList.appendChild(btn);
      });
    } catch (e) {
      console.error(e);
    }
  };

  contactList?.querySelectorAll('[data-contact-id]').forEach((btn) => {
    btn.addEventListener('click', () => {
      openContact(
        btn.dataset.contactId,
        btn.dataset.contactName,
        btn.dataset.contactRole
      ).catch((e) => alert(e.message));
    });
  });

  contactSearch?.addEventListener('input', () => {
    const term = contactSearch.value.trim().toLowerCase();
    contactList.querySelectorAll('[data-contact-id]').forEach((btn) => {
      const text = (btn.dataset.contactName + ' ' + btn.dataset.contactRole).toLowerCase();
      btn.hidden = term && !text.includes(term);
    });
  });

  q('refreshThreads')?.addEventListener('click', loadThreads);

  messageForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const message = messageInput.value.trim();
    if (!message || !threadId) return;

    sendMessage.disabled = true;
    try {
      await post('send', {thread_id: threadId, message});
      messageInput.value = '';
      await loadMessages();
      await loadThreads();
    } catch (e) {
      alert(e.message);
    } finally {
      sendMessage.disabled = false;
      messageInput.focus();
    }
  });

  messageInput?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      messageForm.requestSubmit();
    }
  });

  const localAudio = async () => {
    if (localStream) return localStream;
    if (!navigator.mediaDevices?.getUserMedia) {
      throw new Error('Microphone access requires localhost or HTTPS.');
    }
    localStream = await navigator.mediaDevices.getUserMedia({
      audio: true,
      video: false
    });
    return localStream;
  };

  const makePeer = async () => {
    if (peer) peer.close();

    peer = new RTCPeerConnection({
      iceServers: [{urls:'stun:stun.l.google.com:19302'}]
    });

    const stream = await localAudio();
    stream.getTracks().forEach((track) => peer.addTrack(track, stream));

    peer.ontrack = (event) => {
      if (event.streams?.[0]) {
        remoteAudio.srcObject = event.streams[0];
        remoteAudio.play().catch(() => {});
      }
    };

    peer.onicecandidate = (event) => {
      if (event.candidate && callId) {
        post('signal', {
          call_id: callId,
          signal_type: 'ice',
          payload: event.candidate.toJSON()
        }).catch(console.error);
      }
    };

    return peer;
  };

  const showCall = (name, status, incoming = false) => {
    callName.textContent = name || 'Somame user';
    callAvatar.textContent = initials(name);
    callStatus.textContent = status;
    callTimer.textContent = '00:00';
    acceptCall.classList.toggle('d-none', !incoming);
    muteCall.classList.add('d-none');
    callOverlay.classList.add('show');
  };

  const startTimer = () => {
    connectedAt = Date.now();
    clearInterval(timerHandle);
    timerHandle = setInterval(() => {
      const sec = Math.floor((Date.now() - connectedAt) / 1000);
      callTimer.textContent =
        String(Math.floor(sec / 60)).padStart(2,'0')
        + ':'
        + String(sec % 60).padStart(2,'0');
    }, 1000);
  };

  const cleanupCall = () => {
    clearInterval(timerHandle);
    timerHandle = null;
    connectedAt = 0;
    if (peer) peer.close();
    peer = null;
    if (localStream) localStream.getTracks().forEach((t) => t.stop());
    localStream = null;
    remoteAudio.srcObject = null;
    callId = 0;
    lastSignalId = 0;
    callMode = '';
    callOverlay.classList.remove('show');
  };

  const processSignals = async () => {
    if (!callId) return;

    const data = await get('signals', {
      call_id: callId,
      after_id: lastSignalId
    });

    for (const signal of data.signals) {
      lastSignalId = Math.max(lastSignalId, Number(signal.id || 0));

      if (signal.signal_type === 'offer') {
        const p = peer || await makePeer();
        await p.setRemoteDescription(signal.payload);
        const answer = await p.createAnswer();
        await p.setLocalDescription(answer);
        await post('signal', {
          call_id: callId,
          signal_type: 'answer',
          payload: answer
        });
        callStatus.textContent = 'Connected';
        muteCall.classList.remove('d-none');
        if (!connectedAt) startTimer();
      }

      if (signal.signal_type === 'answer' && peer) {
        await peer.setRemoteDescription(signal.payload);
        callStatus.textContent = 'Connected';
        muteCall.classList.remove('d-none');
        if (!connectedAt) startTimer();
      }

      if (signal.signal_type === 'ice' && peer) {
        try {
          await peer.addIceCandidate(signal.payload);
        } catch (e) {
          console.warn(e);
        }
      }

      if (signal.signal_type === 'hangup') {
        cleanupCall();
        return;
      }
    }

    if (['rejected','ended','missed','cancelled'].includes(data.call_status)) {
      cleanupCall();
    }
  };

  startCall?.addEventListener('click', async () => {
    if (!threadId || !targetId || callId) return;

    try {
      await localAudio();
      const data = await post('start_call', {thread_id: threadId});
      callId = Number(data.call_id || 0);
      callMode = 'outgoing';
      lastSignalId = 0;
      showCall(targetName, 'Calling…', false);

      const p = await makePeer();
      const offer = await p.createOffer();
      await p.setLocalDescription(offer);
      await post('signal', {
        call_id: callId,
        signal_type: 'offer',
        payload: offer
      });
    } catch (e) {
      cleanupCall();
      alert(e.message);
    }
  });

  const checkIncoming = async () => {
    if (callId) return;
    try {
      const data = await get('incoming_call');
      if (!data.call) return;

      callId = Number(data.call.id || 0);
      callMode = 'incoming';
      lastSignalId = 0;
      showCall(data.call.caller_name, 'Incoming call', true);

      const contact = contactList?.querySelector(
        '[data-contact-id="' + Number(data.call.caller_id) + '"]'
      );
      if (contact && Number(data.call.caller_id) !== targetId) {
        await openContact(
          data.call.caller_id,
          contact.dataset.contactName,
          contact.dataset.contactRole
        );
      }
    } catch (e) {
      console.error(e);
    }
  };

  acceptCall?.addEventListener('click', async () => {
    if (!callId) return;
    try {
      await localAudio();
      await post('call_action', {call_id: callId, status:'accepted'});
      await makePeer();
      acceptCall.classList.add('d-none');
      muteCall.classList.remove('d-none');
      callStatus.textContent = 'Connecting…';
      await processSignals();
    } catch (e) {
      alert(e.message);
      cleanupCall();
    }
  });

  endCall?.addEventListener('click', async () => {
    if (!callId) return cleanupCall();

    const status =
      callMode === 'incoming' && !connectedAt
        ? 'rejected'
        : callMode === 'outgoing' && !connectedAt
          ? 'cancelled'
          : 'ended';

    try {
      await post('call_action', {call_id: callId, status});
    } catch (e) {
      console.error(e);
    }
    cleanupCall();
  });

  muteCall?.addEventListener('click', () => {
    if (!localStream) return;
    const tracks = localStream.getAudioTracks();
    const enabled = tracks.some((t) => t.enabled);
    tracks.forEach((t) => t.enabled = !enabled);
    muteCall.innerHTML = enabled
      ? '<i class="bi bi-mic-mute-fill"></i>'
      : '<i class="bi bi-mic-fill"></i>';
  });

  if (targetId) {
    const contact = contactList?.querySelector(
      '[data-contact-id="' + targetId + '"]'
    );
    if (contact) {
      setHeader(
        targetId,
        contact.dataset.contactName,
        contact.dataset.contactRole
      );
    }
  }

  if (threadId) loadMessages(true).catch(console.error);
  loadThreads();

  setInterval(async () => {
    try {
      if (threadId) await loadMessages();
      await loadThreads();
      if (callId) await processSignals();
      else await checkIncoming();
    } catch (e) {
      console.error(e);
    }
  }, 2200);
})();