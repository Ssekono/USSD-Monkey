### USSD Monkey

USSD Monkey is a small, gateway-agnostic engine for building multi-turn USSD applications from a single JSON menu file. It normalizes requests from different USSD gateways (Africa's Talking, UConnect, DMark, TrueAfrican, or your own) behind a common adapter interface, and uses Redis to track each caller's position in the menu between requests.

#### Installation

```bash
composer require gantry-motion/ussd-monkey
```

Requires a reachable Redis instance (via `predis/predis`).

#### Quick Start

```php
use GantryMotion\USSDMonkey\USSDMonkey;

class USSDController
{
    private $ussd;

    public function __construct()
    {
        $this->ussd = new USSDMonkey([
            'ussd_menu_file' => ROOTPATH . 'ussdMenu.json',
            'sanitization' => [
                'enabled' => true,
                'country_code' => '256',
                'local_number_length' => 9,
            ],
        ], $this); // $this is the handler: the object whose methods execute_func names call
    }

    public function index()
    {
        // Which gateways this endpoint accepts, checked in order
        $this->ussd->setAdapters(['africastalking', 'uconnect']);

        try {
            $response = $this->ussd->run($this->request->getPost(), 'default_menu');

            return $this->response
                ->setContentType($this->ussd->getContentType()) // adapter decides plain/JSON/XML
                ->setBody($response);
        } catch (\Exception $e) {
            log_message('error', '[USSD Error] ' . $e->getMessage());
            return $this->response->setStatusCode(400)->setBody('Service unavailable.');
        }
    }

    // Called when a menu node's "execute_func" matches this method name
    public function say_hello($path, $ussd)
    {
        $name = end($path);
        return "Hello $name";
    }
}
```

`USSDMonkey`'s constructor takes two arguments: your config array (merged over the library defaults) and a **handler instance** — typically your controller (`$this`) — whose public methods are invoked by name whenever the menu hits an `execute_func`.

`setAdapters()` activates one or more of the keys registered under `available_adapters` (see Configuration below) and tries them in order against each incoming request; the first one whose `validate()` matches wins. `run()` then parses the request, resolves the caller's position via Redis, and returns the rendered response body already formatted for that gateway.

#### The Handler Contract

Every `execute_func` referenced in your menu JSON must exist as a public method on the handler instance you pass to the constructor, with the signature:

```php
public function my_func(array $path, USSDMonkey $ussd)
{
    // $path is the breadcrumb of every input entered so far in this session,
    // e.g. ['customer_menu', '1', '3', '2']
    // $ussd->session_data has sessionId, phoneNumber, text, and adapter (the resolved adapter's getName())
}
```

The return value controls what happens next:

| Return value | Effect |
|---|---|
| `string` | Replaces the current menu node's `display` text and continues rendering normally |
| `false` | Re-renders the last screen prefixed with a generic "Invalid Input." notice |
| `$ussd->retry($msg)` | Re-renders the last screen prefixed with your own custom message instead of the generic one |
| `$ussd->terminate($msg)` | Ends the session with `$msg` as the final screen |

Every unimplemented `execute_func` referenced by your menu JSON will fatal with a PHP `\Error` (undefined method) rather than a catchable `\Exception` — make sure every `execute_func` in your menu has a matching handler method before going live.

#### `ussdMenu.json` Format

```json
{
    "default_menu": {
        "display": "1. Say Hello|2. Say Goodbye",
        "options": {
            "1": {
                "display": "Enter a name",
                "options": {
                    "display": "_EXECUTE_",
                    "execute_func": "say_hello"
                }
            },
            "2": {
                "display": "_EXECUTE_",
                "execute_func": "say_goodbye"
            }
        }
    }
}
```

- Each menu key is a valid `entryMenu` you can pass to `run()`.
- `options` maps a user's numeric input to the next node; a node with `options` but no matching key is treated as free-text capture (e.g. collecting a name or quantity).
- A node with `"display": "_EXECUTE_"` calls its `execute_func` on the handler instead of rendering static text.
- `menu_items_separator` (default `|`) splits a `display` string into lines; long menus are paginated automatically per `items_per_page`, with `nav_next`/`nav_prev` inputs (default `0`/`00`) moving between pages or back up the menu tree.

#### Configuration

Passed as the first constructor argument and merged recursively over the library defaults (`config/default.php`):

| Key | Default | Purpose |
|---|---|---|
| `ussd_menu_file` | `null` (required) | Absolute path to your `ussdMenu.json` |
| `available_adapters` | the 4 built-in adapters | Map of adapter key → class name; add your own or override a built-in one here |
| `session_ttl` | `180` | Seconds a session's Redis keys survive since the last request. Refreshed on every request while the session is active, so it only expires idle sessions |
| `items_per_page` | `6` | Lines per page before pagination kicks in |
| `menu_items_separator` | `\|` | Separator splitting a `display` string into lines |
| `nav_next` / `nav_prev` | `0` / `00` | Inputs reserved for next-page / previous-page-or-back navigation |
| `sanitization.enabled` | `true` | Whether incoming phone numbers are normalized |
| `sanitization.country_code` | `256` | Prepended to the sanitized local number. Assumes a single-country deployment — don't enable this if you accept international numbers |
| `sanitization.local_number_length` | `9` | Digits kept from the end of the raw number before prepending `country_code` |
| `redis` | `{scheme: tcp, host: 127.0.0.1, port: 6379}` | Passed straight through to `Predis\Client` |
| `chars_per_line` | `null` (disabled) | When set, wraps any single display line exceeding this width onto additional lines before pagination is applied |

#### Writing a Custom Adapter

Implement `GantryMotion\USSDMonkey\Adapters\AdapterInterface`:

```php
use GantryMotion\USSDMonkey\Adapters\AdapterInterface;

class MyGatewayAdapter implements AdapterInterface
{
    public function validate(array $data): bool
    {
        return !empty($data['session_id']) && !empty($data['phone_number']);
    }

    public function parseRequest(array $data): array
    {
        return [
            'sessionId'   => $data['session_id'],
            'phoneNumber' => $data['phone_number'],
            'text'        => $data['request_string'] ?? '',
        ];
    }

    public function formatResponse(string $message, bool $isTerminal): mixed
    {
        return json_encode(['response' => $message, 'end' => $isTerminal]);
    }

    public function getName(): string
    {
        return 'my_gateway'; // unique — used to partition Redis session keys per gateway
    }

    public function getContentType(): string
    {
        return 'application/json';
    }
}
```

Register it under `available_adapters` with a unique key and pass that key to `setAdapters()`. Don't call PHP's `header()` inside `formatResponse()` — return the content type from `getContentType()` instead and let the calling app set it on its own response object via `$ussd->getContentType()`. This keeps the package framework-agnostic and avoids header ordering issues with frameworks (like CodeIgniter) that manage their own response object.

#### Versioning Note

`AdapterInterface` requires `getContentType()` as of `2.0.0`. If you're upgrading from `1.x` with a custom adapter, add that method before updating — otherwise your adapter will fatal with a "must implement" error.
