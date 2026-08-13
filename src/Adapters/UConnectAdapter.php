<?php

namespace GantryMotion\USSDMonkey\Adapters;

class UConnectAdapter implements AdapterInterface
{
    public function validate(array $data): bool
    {
        // Strict check: must exist and not be empty
        return !empty($data['session_id']) && !empty($data['phone_number']);
    }

    public function parseRequest(array $data): array
    {
        return [
            'sessionId'   => $data['session_id'],
            'phoneNumber' => $data['phone_number'],
            'text'        => $data['request_string'] ?? ''
        ];
    }

    public function formatResponse(string $msg, bool $term): string
    {
        return json_encode([
            'response_string' => $msg,
            'action' => $term ? 'end' : 'request'
        ]);
    }

    public function getName(): string
    {
        return 'uconnect';
    }

    public function getContentType(): string
    {
        return 'application/json';
    }
}
