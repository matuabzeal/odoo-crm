<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Client
{
    private array $connection;

    public function __construct(array $connection)
    {
        $this->connection = $connection;
    }

    public function test_connection(): array
    {
        $authentication = $this->authenticate();
        if (!$authentication['success']) {
            return $authentication;
        }

        return [
            'success' => true,
            'code' => 'CONNECTED',
            'message' => 'Connection successful.',
            'user_id' => (int) $authentication['user_id'],
        ];
    }

    public function discover_routing_options(): array
    {
        $authentication = $this->authenticate();
        if (!$authentication['success']) {
            return $authentication;
        }

        $resources = [
            'stages' => ['model' => 'crm.stage', 'limit' => 200],
            'mediums' => ['model' => 'utm.medium', 'limit' => 200],
            'tags' => ['model' => 'crm.tag', 'limit' => 500],
        ];

        $catalog = [];
        foreach ($resources as $key => $resource) {
            $result = $this->search_read_named_records(
                (int) $authentication['user_id'],
                (string) $resource['model'],
                (int) $resource['limit']
            );
            if (!$result['success']) {
                return [
                    'success' => false,
                    'code' => $result['code'] ?? 'ODOO_RPC_ERROR',
                    'message' => $result['message'] ?? 'Odoo routing discovery failed.',
                    'resource' => $key,
                ];
            }
            $catalog[$key] = $result['records'];
        }

        return [
            'success' => true,
            'code' => 'ROUTING_DISCOVERED',
            'message' => 'Odoo routing resources discovered.',
            'user_id' => (int) $authentication['user_id'],
            'stages' => $catalog['stages'],
            'mediums' => $catalog['mediums'],
            'tags' => $catalog['tags'],
        ];
    }

    public function create_lead(array $lead_fields): array
    {
        $authentication = $this->authenticate();
        if (!$authentication['success']) {
            return $authentication;
        }

        $result = $this->rpc_call('object', 'execute_kw', [
            $this->connection['database'],
            (int) $authentication['user_id'],
            $this->connection['api_key'],
            'crm.lead',
            'create',
            [$lead_fields],
        ]);

        if (!$result['success']) {
            return $result;
        }

        $lead_id = is_numeric($result['result']) ? (int) $result['result'] : 0;
        if ($lead_id <= 0) {
            return [
                'success' => false,
                'code' => 'LEAD_CREATION_FAILED',
                'message' => 'Odoo did not return a CRM record ID.',
            ];
        }

        return [
            'success' => true,
            'code' => 'LEAD_CREATED',
            'message' => 'CRM record created.',
            'user_id' => (int) $authentication['user_id'],
            'lead_id' => $lead_id,
        ];
    }

    public function find_partners_by_email(string $email): array
    {
        $email = strtolower(trim(sanitize_email($email)));
        if ($email === '') {
            return [
                'success' => false,
                'code' => 'PARTNER_EMAIL_REQUIRED',
                'message' => 'A valid donor email address is required for partner resolution.',
            ];
        }

        $authentication = $this->authenticate();
        if (!$authentication['success']) {
            return $authentication;
        }

        $result = $this->rpc_call('object', 'execute_kw', [
            $this->connection['database'],
            (int) $authentication['user_id'],
            $this->connection['api_key'],
            'res.partner',
            'search_read',
            [[['email', '=ilike', $email]]],
            [
                'fields' => ['id', 'name', 'email'],
                'limit' => 3,
                'order' => 'id asc',
            ],
        ]);

        if (!$result['success']) {
            return $result;
        }
        if (!is_array($result['result'])) {
            return [
                'success' => false,
                'code' => 'INVALID_RESPONSE',
                'message' => 'Odoo returned an invalid partner search response.',
            ];
        }

        $records = [];
        foreach ($result['result'] as $record) {
            if (!is_array($record)) {
                continue;
            }
            $record_email = strtolower(trim(sanitize_email((string) ($record['email'] ?? ''))));
            $id = absint($record['id'] ?? 0);
            if ($id > 0 && $record_email === $email) {
                $records[] = [
                    'id' => $id,
                    'name' => sanitize_text_field((string) ($record['name'] ?? '')),
                    'email' => $record_email,
                ];
            }
        }

        return [
            'success' => true,
            'code' => 'PARTNER_SEARCH_OK',
            'message' => 'Partner search completed.',
            'records' => $records,
        ];
    }

    public function create_partner(array $partner_fields): array
    {
        $authentication = $this->authenticate();
        if (!$authentication['success']) {
            return $authentication;
        }

        $result = $this->rpc_call('object', 'execute_kw', [
            $this->connection['database'],
            (int) $authentication['user_id'],
            $this->connection['api_key'],
            'res.partner',
            'create',
            [$partner_fields],
        ]);

        if (!$result['success']) {
            return $result;
        }

        $partner_id = is_numeric($result['result']) ? (int) $result['result'] : 0;
        if ($partner_id <= 0) {
            return [
                'success' => false,
                'code' => 'PARTNER_CREATION_FAILED',
                'message' => 'Odoo did not return a partner ID.',
            ];
        }

        return [
            'success' => true,
            'code' => 'PARTNER_CREATED',
            'message' => 'Odoo partner created.',
            'partner_id' => $partner_id,
        ];
    }

    private function search_read_named_records(int $user_id, string $model, int $limit): array
    {
        $result = $this->rpc_call('object', 'execute_kw', [
            $this->connection['database'],
            $user_id,
            $this->connection['api_key'],
            $model,
            'search_read',
            [[]],
            [
                'fields' => ['id', 'name'],
                'limit' => $limit,
                'order' => 'name asc',
            ],
        ]);

        if (!$result['success']) {
            return $result;
        }

        if (!is_array($result['result'])) {
            return [
                'success' => false,
                'code' => 'INVALID_RESPONSE',
                'message' => 'Odoo returned an invalid routing-resource response.',
            ];
        }

        $records = [];
        foreach ($result['result'] as $record) {
            if (!is_array($record)) {
                continue;
            }
            $id = isset($record['id']) ? absint($record['id']) : 0;
            $name = isset($record['name']) ? sanitize_text_field((string) $record['name']) : '';
            if ($id > 0 && $name !== '') {
                $records[] = ['id' => $id, 'name' => $name];
            }
        }

        return [
            'success' => true,
            'code' => 'SEARCH_READ_OK',
            'message' => 'Routing resources read successfully.',
            'records' => $records,
        ];
    }

    private function authenticate(): array
    {
        foreach (['url', 'database', 'username', 'api_key'] as $field) {
            if (!isset($this->connection[$field]) || trim((string) $this->connection[$field]) === '') {
                return [
                    'success' => false,
                    'code' => 'CONFIG_INCOMPLETE',
                    'message' => 'Connection settings are incomplete.',
                ];
            }
        }

        $result = $this->rpc_call('common', 'authenticate', [
            $this->connection['database'],
            $this->connection['username'],
            $this->connection['api_key'],
            [],
        ]);

        if (!$result['success']) {
            return $result;
        }

        $user_id = is_numeric($result['result']) ? (int) $result['result'] : 0;
        if ($user_id <= 0) {
            return [
                'success' => false,
                'code' => 'AUTHENTICATION_FAILED',
                'message' => 'Odoo rejected the configured credentials.',
            ];
        }

        return [
            'success' => true,
            'code' => 'AUTHENTICATED',
            'message' => 'Authentication successful.',
            'user_id' => $user_id,
        ];
    }

    private function rpc_call(string $service, string $method, array $args): array
    {
        $request_body = [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => $service,
                'method' => $method,
                'args' => $args,
            ],
            'id' => wp_generate_uuid4(),
        ];

        $response = wp_remote_post(
            untrailingslashit((string) $this->connection['url']) . '/jsonrpc',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode($request_body),
                'timeout' => 10,
            ]
        );

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'code' => 'CONNECTION_TIMEOUT',
                'message' => 'Unable to reach Odoo within the configured request boundary.',
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300) {
            return [
                'success' => false,
                'code' => 'INVALID_RESPONSE',
                'message' => 'Odoo returned HTTP status ' . $status . '.',
            ];
        }

        if (!is_array($body)) {
            return [
                'success' => false,
                'code' => 'INVALID_RESPONSE',
                'message' => 'Odoo returned an invalid JSON response.',
            ];
        }

        if (isset($body['error'])) {
            return $this->normalize_rpc_error($body['error']);
        }

        return [
            'success' => true,
            'code' => 'RPC_OK',
            'result' => $body['result'] ?? null,
        ];
    }

    private function normalize_rpc_error($error): array
    {
        $error = is_array($error) ? $error : [];
        $message = isset($error['message']) ? sanitize_text_field((string) $error['message']) : 'Odoo returned an RPC error.';
        $data_text = '';
        if (isset($error['data'])) {
            $encoded = wp_json_encode($error['data']);
            $data_text = is_string($encoded) ? $encoded : '';
        }
        $classification_text = strtolower($message . ' ' . $data_text);
        $code = str_contains($classification_text, 'invalid field') ? 'ODOO_FIELD_ERROR' : 'ODOO_RPC_ERROR';

        return [
            'success' => false,
            'code' => $code,
            'message' => $code === 'ODOO_FIELD_ERROR'
                ? 'Odoo rejected one or more CRM fields.'
                : $message,
        ];
    }

public function update_lead_lifecycle_context(int $lead_id, array $context): array
    {
        if ($lead_id <= 0) {
            return ['success' => false, 'code' => 'INVALID_LEAD_ID'];
        }

        $authentication = $this->authenticate();
        if (!$authentication['success']) {
            return $authentication;
        }

        $read_result = $this->rpc_call('object', 'execute_kw', [
            $this->connection['database'],
            (int) $authentication['user_id'],
            $this->connection['api_key'],
            'crm.lead',
            'read',
            [[$lead_id], ['description']],
        ]);
        if (!$read_result['success']) {
            return $read_result;
        }

        $rows = $read_result['result'] ?? [];
        $current_description = '';
        if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
            $current_description = isset($rows[0]['description']) && is_string($rows[0]['description']) ? $rows[0]['description'] : '';
        }

        $state = sanitize_text_field((string) ($context['state'] ?? ''));
        $event_identity = sanitize_text_field((string) ($context['event_identity'] ?? ''));
        $details = isset($context['details']) && is_array($context['details']) ? $context['details'] : [];
        $detail_parts = [];
        foreach ($details as $key => $value) {
            if (!is_scalar($value) || (string) $value === '') {
                continue;
            }
            $detail_parts[] = esc_html(sanitize_key((string) $key)) . ': ' . esc_html(sanitize_text_field((string) $value));
        }

        $lifecycle_line = '<p><strong>Payment lifecycle:</strong> ' . esc_html($state);
        if ($event_identity !== '') {
            $lifecycle_line .= ' <small>(' . esc_html($event_identity) . ')</small>';
        }
        if ($detail_parts) {
            $lifecycle_line .= '<br>' . implode(' | ', $detail_parts);
        }
        $lifecycle_line .= '</p>';
        $updated_description = rtrim($current_description) . "\n" . $lifecycle_line;

        $write_result = $this->rpc_call('object', 'execute_kw', [
            $this->connection['database'],
            (int) $authentication['user_id'],
            $this->connection['api_key'],
            'crm.lead',
            'write',
            [[$lead_id], ['description' => $updated_description]],
        ]);
        if (!$write_result['success']) {
            return $write_result;
        }
        if (empty($write_result['result'])) {
            return ['success' => false, 'code' => 'LEAD_LIFECYCLE_UPDATE_FAILED'];
        }
        return ['success' => true, 'code' => 'LEAD_LIFECYCLE_UPDATED', 'lead_id' => $lead_id];
    }
}
