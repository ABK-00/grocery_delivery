<?php

declare(strict_types=1);

require_once __DIR__ . '/AIPermissions.php';
require_once __DIR__ . '/AIPrompts.php';
require_once __DIR__ . '/AITools.php';
require_once __DIR__ . '/AIToolExecutor.php';
require_once __DIR__ . '/../../../includes/functions.php';

class AIService
{
    private PDO $pdo;
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    private const MAX_TOOL_ROUNDS = 6;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;

        $this->apiKey =
            $_ENV['OPENAI_API_KEY']
            ?? getenv('OPENAI_API_KEY')
            ?: '';

        $this->model =
            $_ENV['OPENAI_MODEL']
            ?? getenv('OPENAI_MODEL')
            ?: 'gpt-6-luna';

        $this->baseUrl = rtrim(
            $_ENV['OPENAI_BASE_URL']
            ?? getenv('OPENAI_BASE_URL')
            ?: 'https://api.openai.com/v1',
            '/'
        );
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function chat(
        int $userId,
        string $role,
        string $message,
        ?int $companyId = null,
        ?int $conversationId = null
    ): array {
        $message = trim($message);

        if ($message === '') {
            throw new InvalidArgumentException(
                'Message cannot be empty.'
            );
        }

        if (!$this->isConfigured()) {
            throw new RuntimeException(
                'AI service is currently unavailable because OPENAI_API_KEY is not configured.'
            );
        }

        $aiEnabled = platformSetting(
            $this->pdo,
            'ai.enabled',
            '0'
        );

        if (!in_array(
            strtolower((string) $aiEnabled),
            ['1', 'true', 'yes', 'on'],
            true
        )) {
            throw new RuntimeException(
                'AI service is currently disabled by platform settings.'
            );
        }

        $role = strtolower(trim($role));

        if ($conversationId !== null) {
            $this->assertConversationAccess(
                $conversationId,
                $userId,
                $role,
                $companyId
            );
        } else {
            $conversationId = $this->createConversation(
                $userId,
                $role,
                $companyId
            );
        }

        $this->saveMessage(
            $conversationId,
            'user',
            $userId,
            $message
        );

        $history = $this->getConversationHistory(
            $conversationId
        );

        $companyName = $this->getCompanyName(
            $companyId
        );

        $instructions = AIPrompts::forRole(
            $role,
            $companyName
        );

        $tools = AITools::definitions($role);

        $executor = new AIToolExecutor(
            $this->pdo,
            $userId,
            $role,
            $companyId
        );

        $response = $this->runToolLoop(
            instructions: $instructions,
            history: $history,
            tools: $tools,
            executor: $executor,
            conversationId: $conversationId
        );

        $assistantText = $this->extractText(
            $response
        );

        if ($assistantText === '') {
            $assistantText =
                'I could not generate a response. Please try again.';
        }

        $usage = $response['usage'] ?? [];

        $this->saveMessage(
            $conversationId,
            'assistant',
            null,
            $assistantText,
            $this->model,
            [
                'response_id' =>
                    $response['id'] ?? null,

                'usage' =>
                    $usage,

                'status' =>
                    $response['status'] ?? null
            ]
        );

        $this->touchConversation(
            $conversationId
        );

        return [
            'success' => true,

            'conversation_id' =>
                $conversationId,

            'message' =>
                $assistantText,

            'model' =>
                $response['model']
                ?? $this->model,

            'usage' => [
                'input_tokens' =>
                    $usage['input_tokens']
                    ?? null,

                'output_tokens' =>
                    $usage['output_tokens']
                    ?? null,

                'total_tokens' =>
                    $usage['total_tokens']
                    ?? null
            ]
        ];
    }

    private function runToolLoop(
        string $instructions,
        array $history,
        array $tools,
        AIToolExecutor $executor,
        int $conversationId
    ): array {
        $input = $history;

        for (
            $round = 1;
            $round <= self::MAX_TOOL_ROUNDS;
            $round++
        ) {
            $response = $this->requestOpenAI(
                $instructions,
                $input,
                $tools
            );

            $toolCalls = $this->getToolCalls(
                $response
            );

            if (empty($toolCalls)) {
                return $response;
            }

            /*
             * Responses API tool loops require the
             * previous response output items to be
             * preserved in the next input.
             */
            foreach ($response['output'] ?? [] as $item) {
                $input[] = $item;
            }

            foreach ($toolCalls as $call) {
                $callId =
                    (string)(
                        $call['call_id']
                        ?? ''
                    );

                $toolName =
                    (string)(
                        $call['name']
                        ?? ''
                    );

                $argumentsJson =
                    (string)(
                        $call['arguments']
                        ?? '{}'
                    );

                if (
                    $callId === ''
                    || $toolName === ''
                ) {
                    continue;
                }

                $arguments = json_decode(
                    $argumentsJson,
                    true
                );

                if (!is_array($arguments)) {
                    $arguments = [];
                }

                try {
                    $result = $executor->execute(
                        $toolName,
                        $arguments
                    );
                } catch (Throwable $e) {
                    $result = [
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }

                $this->saveToolMessage(
                    $conversationId,
                    $toolName,
                    $arguments,
                    $result
                );

                $input[] = [
                    'type' =>
                        'function_call_output',

                    'call_id' =>
                        $callId,

                    'output' =>
                        json_encode(
                            $result,
                            JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                        )
                ];
            }
        }

        throw new RuntimeException(
            'AI tool execution exceeded the maximum number of rounds.'
        );
    }

    private function requestOpenAI(
        string $instructions,
        array $input,
        array $tools
    ): array {
        $payload = [
            'model' => $this->model,
            'instructions' => $instructions,
            'input' => $input
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $ch = curl_init(
            $this->baseUrl . '/responses'
        );

        curl_setopt_array(
            $ch,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,

                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '
                        . $this->apiKey,

                    'Content-Type: application/json'
                ],

                CURLOPT_POSTFIELDS =>
                    json_encode(
                        $payload,
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                        | JSON_THROW_ON_ERROR
                    ),

                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60
            ]
        );

        $body = curl_exec($ch);

        if ($body === false) {
            $error = curl_error($ch);

            curl_close($ch);

            throw new RuntimeException(
                'AI connection failed: '
                . $error
            );
        }

        $status = curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        $decoded = json_decode(
            $body,
            true
        );

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Invalid response from OpenAI.'
            );
        }

        if (
            $status < 200
            || $status >= 300
        ) {
            $message =
                $decoded['error']['message']
                ?? 'OpenAI request failed.';

            throw new RuntimeException(
                $message
            );
        }

        return $decoded;
    }

    private function getToolCalls(
        array $response
    ): array {
        $calls = [];

        foreach (
            $response['output'] ?? []
            as $item
        ) {
            if (
                ($item['type'] ?? null)
                === 'function_call'
            ) {
                $calls[] = $item;
            }
        }

        return $calls;
    }

    private function extractText(
        array $response
    ): string {
        /*
         * Depending on client/API representation,
         * output_text may exist directly.
         */
        if (
            isset($response['output_text'])
            && is_string(
                $response['output_text']
            )
        ) {
            return trim(
                $response['output_text']
            );
        }

        $parts = [];

        foreach (
            $response['output'] ?? []
            as $output
        ) {
            if (
                ($output['type'] ?? null)
                !== 'message'
            ) {
                continue;
            }

            foreach (
                $output['content'] ?? []
                as $content
            ) {
                if (
                    ($content['type'] ?? null)
                    === 'output_text'
                    && isset($content['text'])
                ) {
                    $parts[] =
                        (string)$content['text'];
                }
            }
        }

        return trim(
            implode("\n", $parts)
        );
    }

    private function createConversation(
        int $userId,
        string $role,
        ?int $companyId
    ): int {
        $uuid = $this->uuid();

        $assistantType = match ($role) {
            'super_admin' =>
                'super_admin_assistant',

            'admin' =>
                'admin_assistant',

            'staff' =>
                'staff_assistant',

            'delivery_partner' =>
                'delivery_assistant',

            default =>
                'customer_service'
        };

        $stmt = $this->pdo->prepare("
            INSERT INTO ai_conversations (
                company_id,
                user_id,
                conversation_uuid,
                assistant_type,
                channel,
                status,
                started_at,
                last_message_at
            )
            VALUES (
                :company_id,
                :user_id,
                :conversation_uuid,
                :assistant_type,
                'web',
                'active',
                NOW(),
                NOW()
            )
        ");

        $stmt->execute([
            ':company_id' =>
                $companyId,

            ':user_id' =>
                $userId,

            ':conversation_uuid' =>
                $uuid,

            ':assistant_type' =>
                $assistantType
        ]);

        return (int)
            $this->pdo->lastInsertId();
    }

    private function assertConversationAccess(
        int $conversationId,
        int $userId,
        string $role,
        ?int $companyId
    ): void {
        $stmt = $this->pdo->prepare("
            SELECT
                id,
                user_id,
                company_id
            FROM ai_conversations
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $conversationId
        ]);

        $conversation =
            $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$conversation) {
            throw new RuntimeException(
                'AI conversation was not found.'
            );
        }

        /*
         * Every user, including super admins, can only
         * continue conversations they own. Explicit
         * support takeover should use a separate audited
         * workflow rather than bypassing ownership here.
         */
        if (
            (int)$conversation['user_id']
            !== $userId
        ) {
            throw new RuntimeException(
                'You cannot access this conversation.'
            );
        }

        /*
         * Company-bound users must remain inside
         * their company boundary.
         */
        if (
            in_array(
                $role,
                [
                    'admin',
                    'staff',
                    'delivery_partner'
                ],
                true
            )
        ) {
            if (
                (int)(
                    $conversation['company_id']
                    ?? 0
                )
                !== (int)$companyId
            ) {
                throw new RuntimeException(
                    'Conversation company access denied.'
                );
            }
        }
    }

    private function saveMessage(
        int $conversationId,
        string $senderType,
        ?int $senderId,
        string $message,
        ?string $model = null,
        ?array $metadata = null
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO ai_messages (
                conversation_id,
                sender_type,
                sender_id,
                message,
                message_type,
                model,
                metadata
            )
            VALUES (
                :conversation_id,
                :sender_type,
                :sender_id,
                :message,
                'text',
                :model,
                :metadata
            )
        ");

        $stmt->execute([
            ':conversation_id' =>
                $conversationId,

            ':sender_type' =>
                $senderType,

            ':sender_id' =>
                $senderId,

            ':message' =>
                $message,

            ':model' =>
                $model,

            ':metadata' =>
                $metadata !== null
                    ? json_encode(
                        $metadata,
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                    )
                    : null
        ]);
    }

    private function saveToolMessage(
        int $conversationId,
        string $toolName,
        array $arguments,
        array $result
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO ai_messages (
                conversation_id,
                sender_type,
                sender_id,
                message,
                message_type,
                tool_name,
                tool_payload,
                metadata
            )
            VALUES (
                :conversation_id,
                'tool',
                NULL,
                :message,
                'tool_result',
                :tool_name,
                :tool_payload,
                :metadata
            )
        ");

        $stmt->execute([
            ':conversation_id' =>
                $conversationId,

            ':message' =>
                json_encode(
                    $result,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                ),

            ':tool_name' =>
                $toolName,

            ':tool_payload' =>
                json_encode(
                    $arguments,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                ),

            ':metadata' =>
                json_encode([
                    'executed_at' =>
                        date(DATE_ATOM)
                ])
        ]);
    }

    private function getConversationHistory(
        int $conversationId
    ): array {
        $stmt = $this->pdo->prepare("
            SELECT
                sender_type,
                message
            FROM ai_messages
            WHERE conversation_id = :conversation_id
            AND sender_type IN (
                'user',
                'assistant'
            )
            ORDER BY id DESC
            LIMIT 30
        ");

        $stmt->execute([
            ':conversation_id' =>
                $conversationId
        ]);

        $rows = array_reverse(
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            )
        );

        $history = [];

        foreach ($rows as $row) {
            $history[] = [
                'role' =>
                    $row['sender_type']
                    === 'assistant'
                        ? 'assistant'
                        : 'user',

                'content' =>
                    (string)$row['message']
            ];
        }

        return $history;
    }

    private function touchConversation(
        int $conversationId
    ): void {
        $stmt = $this->pdo->prepare("
            UPDATE ai_conversations
            SET last_message_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $conversationId
        ]);
    }

    private function getCompanyName(
        ?int $companyId
    ): ?string {
        if (!$companyId) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT company_name
            FROM companies
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $companyId
        ]);

        $name = $stmt->fetchColumn();

        return $name !== false
            ? (string)$name
            : null;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);

        $data[6] =
            chr(
                (ord($data[6]) & 0x0f)
                | 0x40
            );

        $data[8] =
            chr(
                (ord($data[8]) & 0x3f)
                | 0x80
            );

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(
                bin2hex($data),
                4
            )
        );
    }
}




