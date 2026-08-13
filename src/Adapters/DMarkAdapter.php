<?php

namespace GantryMotion\USSDMonkey\Adapters;

class DMarkAdapter implements AdapterInterface
{
    public function validate(array $data): bool
    {
        return !empty($data['transactionID']) && !empty($data['msisdn']);
    }

    public function parseRequest(array $data): array
    {
        return [
            'sessionId'   => $data['transactionID'],
            'phoneNumber' => $data['msisdn'],
            'text'        => $data['ussdRequestString'] ?? ''
        ];
    }

    public function formatResponse(string $msg, bool $term): string
    {
        return json_encode([
            'responseString' => urlencode($msg),
            'action' => $term ? 'end' : 'request'
        ]);
    }

    public function getName(): string
    {
        return 'dmark';
    }

    public function getContentType(): string
    {
        return 'application/json';
    }
}
