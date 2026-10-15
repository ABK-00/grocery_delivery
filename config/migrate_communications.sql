-- Somame in-system communications
-- Direct messaging + browser audio call signaling.
USE grocery_delivery;

CREATE TABLE IF NOT EXISTS communication_threads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_one_id INT UNSIGNED NOT NULL,
    user_two_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED DEFAULT NULL,
    created_by INT UNSIGNED NOT NULL,
    last_message_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_communication_pair (
        user_one_id,
        user_two_id
    ),

    INDEX idx_communication_user_one (
        user_one_id,
        updated_at
    ),

    INDEX idx_communication_user_two (
        user_two_id,
        updated_at
    ),

    INDEX idx_communication_company (
        company_id,
        updated_at
    ),

    CONSTRAINT fk_communication_user_one
        FOREIGN KEY (user_one_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_communication_user_two
        FOREIGN KEY (user_two_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_communication_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_communication_company
        FOREIGN KEY (company_id)
        REFERENCES companies(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS communication_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id BIGINT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    read_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_communication_messages_thread (
        thread_id,
        id
    ),

    INDEX idx_communication_messages_unread (
        thread_id,
        read_at,
        id
    ),

    CONSTRAINT fk_communication_message_thread
        FOREIGN KEY (thread_id)
        REFERENCES communication_threads(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_communication_message_sender
        FOREIGN KEY (sender_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS communication_calls (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id BIGINT UNSIGNED NOT NULL,
    caller_id INT UNSIGNED NOT NULL,
    callee_id INT UNSIGNED NOT NULL,

    status ENUM(
        'ringing',
        'accepted',
        'rejected',
        'ended',
        'missed',
        'cancelled'
    ) NOT NULL DEFAULT 'ringing',

    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    answered_at DATETIME DEFAULT NULL,
    ended_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_communication_calls_callee (
        callee_id,
        status,
        id
    ),

    INDEX idx_communication_calls_caller (
        caller_id,
        status,
        id
    ),

    INDEX idx_communication_calls_thread (
        thread_id,
        id
    ),

    CONSTRAINT fk_communication_call_thread
        FOREIGN KEY (thread_id)
        REFERENCES communication_threads(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_communication_call_caller
        FOREIGN KEY (caller_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_communication_call_callee
        FOREIGN KEY (callee_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS communication_call_signals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id BIGINT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    recipient_id INT UNSIGNED NOT NULL,

    signal_type ENUM(
        'offer',
        'answer',
        'ice',
        'hangup'
    ) NOT NULL,

    payload JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_communication_signals_recipient (
        recipient_id,
        call_id,
        id
    ),

    CONSTRAINT fk_communication_signal_call
        FOREIGN KEY (call_id)
        REFERENCES communication_calls(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_communication_signal_sender
        FOREIGN KEY (sender_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_communication_signal_recipient
        FOREIGN KEY (recipient_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
