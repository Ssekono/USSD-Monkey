<?php

namespace GantryMotion\USSDMonkey;

use Predis\Client;

class USSDMonkey
{
    private $redis;
    private $ussdMenu;
    private $ussdConfig;
    private $activeAdapters = [];
    private $resolvedAdapter = null;
    private $handler;
    private $redisKey = null;

    /** @var object Standardized session info */
    public $session_data;

    public function __construct(array $config, $handlerInstance)
    {
        // Load the library defaults
        $defaultConfig = include(__DIR__ . '/../config/default.php');
        $this->ussdConfig = array_replace_recursive($defaultConfig, $config);

        $this->ussdMenu = json_decode(file_get_contents($this->ussdConfig['ussd_menu_file']), true);
        $this->redis = new Client($this->ussdConfig['redis']);
        $this->handler = $handlerInstance;
    }

    public function setAdapters(array $adapterKeys)
    {
        foreach ($adapterKeys as $key) {
            $class = $this->ussdConfig['available_adapters'][$key] ?? null;
            if ($class && class_exists($class)) {
                $this->activeAdapters[] = new $class();
            }
        }
        return $this;
    }

    public function run(array $requestData, string $entryMenu)
    {
        foreach ($this->activeAdapters as $adapter) {
            if ($adapter->validate($requestData)) {
                $this->resolvedAdapter = $adapter;
                break;
            }
        }

        if (!$this->resolvedAdapter) {
            throw new \Exception("Gatekeeper: Unrecognized request format.");
        }

        $params = $this->resolvedAdapter->parseRequest($requestData);

        // Clean implementation of standardized data
        $this->session_data = (object) [
            'sessionId'   => $params['sessionId'],
            'phoneNumber' => $this->sanitize($params['phoneNumber']),
            'text'        => $params['text'] ?? '',
            'adapter'     => $this->resolvedAdapter->getName()
        ];

        $this->redisKey = $this->session_data->adapter . "_" . $this->session_data->sessionId;
        return $this->process($this->session_data->text, $entryMenu, $this->redisKey);
    }

    /**
     * Framework-agnostic phone sanitization based on config
     */
    private function sanitize($phone)
    {
        $config = $this->ussdConfig['sanitization'];
        if (!$config['enabled']) {
            return $phone;
        }

        // Remove any non-numeric characters
        $purePhone = preg_replace('/[^0-9]/', '', $phone);

        // Extract the last X digits based on config and prepend country code
        $localLength = $config['local_number_length'];
        $countryCode = $config['country_code'];

        return $countryCode . substr($purePhone, -$localLength);
    }

    private function process($text, $menuKey, $redisKey)
    {
        $inputs = array_filter(explode('*', $text));
        $currentLayer = $this->ussdMenu[$menuKey] ?? null;
        if (!$currentLayer) throw new \Exception("Menu key '$menuKey' not found.");

        $pathValues = [$menuKey];
        $pageOffset = (int) ($this->redis->get("page_$redisKey") ?? 0);

        foreach ($inputs as $val) {
            if ($val === $this->ussdConfig['nav_next']) {
                $pageOffset++;
                continue;
            }
            if ($val === $this->ussdConfig['nav_prev']) {
                if ($pageOffset > 0) $pageOffset--;
                else array_pop($pathValues);
                continue;
            }

            $pathValues[] = $val;
            if (isset($currentLayer['options'][$val])) {
                $currentLayer = $currentLayer['options'][$val];
                $pageOffset = 0;
            } elseif (isset($currentLayer['options'])) {
                $currentLayer = $currentLayer['options'];
            }

            if (($currentLayer['display'] ?? '') === '_EXECUTE_') {
                $method = $currentLayer['execute_func'];
                $result = $this->handler->$method($pathValues, $this);
                if ($result === false) return $this->retry();
                if (is_string($result)) $currentLayer['display'] = $result;
            }
        }

        $this->redis->setex("page_$redisKey", $this->ussdConfig['session_ttl'], $pageOffset);
        return $this->render($currentLayer, $pageOffset, $redisKey);
    }

    private function render($layer, $pageOffset, $redisKey)
    {
        // 1. Prepare the basic display
        $display = str_replace($this->ussdConfig['menu_items_separator'], PHP_EOL, $layer['display'] ?? '');
        $lines = explode(PHP_EOL, $display);

        // Wrap any line exceeding the device's character-per-line budget
        if (!empty($this->ussdConfig['chars_per_line'])) {
            $wrapped = [];
            foreach ($lines as $line) {
                $wrapped = array_merge($wrapped, explode(PHP_EOL, wordwrap($line, $this->ussdConfig['chars_per_line'], PHP_EOL, true)));
            }
            $lines = $wrapped;
        }

        $chunks = array_chunk($lines, $this->ussdConfig['items_per_page']);
        $msg = implode(PHP_EOL, $chunks[$pageOffset] ?? $chunks[0] ?? ["Error"]);

        // 2. Fetch the current path depth from the process loop
        $pathDepth = count(array_filter(explode('*', $this->session_data->text)));

        // 3. Handle Pagination Navigation (Next/Prev Page)
        if (count($chunks) > 1) {
            if ($pageOffset < count($chunks) - 1) {
                $msg .= PHP_EOL . $this->ussdConfig['nav_next'] . ". Next";
            }
            if ($pageOffset > 0) {
                $msg .= PHP_EOL . $this->ussdConfig['nav_prev'] . ". Prev Page";
            }
        }

        // 4. Handle Menu Hierarchy (Back to Previous Menu)
        if ($pathDepth > 0 && $pageOffset === 0) {
            $msg .= PHP_EOL . $this->ussdConfig['nav_prev'] . ". Back";
        }

        $this->redis->setex("last_msg:$redisKey", $this->ussdConfig['session_ttl'], $msg);
        return $this->resolvedAdapter->formatResponse($msg, !isset($layer['options']));
    }

    /**
     * The MIME type the resolved adapter's response was formatted as.
     * Call after run() so the caller can set it on its own response object.
     */
    public function getContentType(): string
    {
        $this->assertResolved();
        return $this->resolvedAdapter->getContentType();
    }

    public function terminate(?string $msg = null)
    {
        $this->assertResolved();
        return $this->resolvedAdapter->formatResponse($msg ?? "Thank you.", true);
    }

    public function retry(?string $msg = null)
    {
        $this->assertResolved();
        $last = $this->redis->get("last_msg:{$this->redisKey}");
        return $this->resolvedAdapter->formatResponse(($msg ?? "Invalid Input.") . PHP_EOL . $last, false);
    }

    private function assertResolved(): void
    {
        if (!$this->resolvedAdapter) {
            throw new \Exception("USSDMonkey: No adapter resolved yet. Call run() first.");
        }
    }
}
