<?php

namespace GantryMotion\USSDMonkey\Adapters;

class TrueAfricanAdapter implements AdapterInterface
{
    public function validate(array $data): bool
    {
        return !empty($data['session']) && !empty($data['msisdn']);
    }

    public function parseRequest(array $data): array
    {
        return [
            'sessionId'   => $data['session'],
            'phoneNumber' => $data['msisdn'],
            'text'        => $data['text'] ?? ''
        ];
    }

    public function formatResponse(string $msg, bool $term): string
    {
        $method = $term ? 'USSD.END' : 'USSD.CONT';

        $xml = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= '<methodCall>';
        $xml .= '<methodName>' . $method . '</methodName>';
        $xml .= '<params><param><value><struct>';
        $xml .= '<member><name>response</name><value><string>' . htmlspecialchars($msg) . '</string></value></member>';
        $xml .= '</struct></value></param></params>';
        $xml .= '</methodCall>';

        return $xml;
    }

    public function getName(): string
    {
        return 'trueafrican';
    }

    public function getContentType(): string
    {
        return 'text/xml';
    }
}
