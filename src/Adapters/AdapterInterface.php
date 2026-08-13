<?php

namespace GantryMotion\USSDMonkey\Adapters;

interface AdapterInterface
{
    /**
     * Checks if the incoming request keys align with the gateway requirements.
     */
    public function validate(array $data): bool;
    /**
     * Standardizes the incoming data for the core engine.
     */
    public function parseRequest(array $data): array;
    /**
     * Formats the response (Plain text, JSON, or XML) as required by the gateway.
     */
    public function formatResponse(string $message, bool $isTerminal): mixed;
    /**
     * Returns the unique string identifier for session partitioning.
     */
    public function getName(): string;
    /**
     * Returns the MIME type of the value formatResponse() produces, so the
     * calling app can set it on its own response object.
     */
    public function getContentType(): string;
}
