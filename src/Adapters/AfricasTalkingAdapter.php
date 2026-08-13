<?php

namespace GantryMotion\USSDMonkey\Adapters;

class AfricasTalkingAdapter implements AdapterInterface
{
    public function validate(array $data): bool
    {
        // Enforce non-empty and non-null
        return !empty($data['sessionId']) && !empty($data['phoneNumber']);
    }

    public function parseRequest(array $data): array
    {
        return [
            'sessionId'   => $data['sessionId'],
            'phoneNumber' => $data['phoneNumber'],
            'text'        => $data['text'] ?? ''
        ];
    }

    public function formatResponse(string $msg, bool $term): string
    {
        return ($term ? "END " : "CON ") . $msg;
    }

    public function getName(): string
    {
        return 'at';
    }

    public function getContentType(): string
    {
        return 'text/plain';
    }
}
