<?php

namespace GantryMotion\USSDMonkey;

use Predis\Client;

class USSDMonkey
{
    private $sessionId;
    private $serviceCode;
    private $phoneNumber;
    private $requestString;

    private $ussdMenu;
    private $ussdConfig;
    private $customClassNamespace;

    private $redis;

    public function __construct(array $customConfig = [])
    {
        // Load default configurations
        $defaultConfig = include(__DIR__ . '/../config/default.php');
        // Validate custom configurations
        $this->validateConfig($customConfig);
        // Merge with valdated custom configurations
        $this->ussdConfig = array_merge($defaultConfig, $customConfig);

        // Load Menu
        if (isset($this->ussdConfig['ussd_menu_file'])) {
            $this->ussdMenu = $this->loadJsonMenu($this->ussdConfig['ussd_menu_file']);
        } else {
            throw new \InvalidArgumentException("USSD Menu file path is not defined");
        }

        // Get Custom Class where ussd methods are defined
        if (isset($this->ussdConfig['custom_class_namespace'])) {
            $this->customClassNamespace = $this->ussdConfig['custom_class_namespace'];
        } else {
            throw new \Exception("Custom Class Namespace is not defined");
        }

        // Set Redis
        $this->redis = new Client([
            'scheme' => $this->ussdConfig['redis']['scheme'],
            'host' => $this->ussdConfig['redis']['host'],
            'port' => $this->ussdConfig['redis']['port'],
            'password' => $this->ussdConfig['redis']['password'] ?? null,
        ]);
    }


    protected function loadJsonMenu(string $jsonMenuFile)
    {
        if (file_exists($jsonMenuFile)) {
            $userMenu = json_decode(file_get_contents($jsonMenuFile), true);
            if ($userMenu !== null) {
                return $userMenu;
            } else {
                throw new \RuntimeException("Invalid JSON format in the provided USSD Menu file: $jsonMenuFile");
            }
        } else {
            throw new \InvalidArgumentException("USSD Menu file not found: $jsonMenuFile");
        }
    }


    public function push(array $params, string $default_menu = 'default_menu')
    {
        try {
            $this->sessionId = $params[$this->ussdConfig['request_variables']['session_id']];
            $this->serviceCode = $params[$this->ussdConfig['request_variables']['service_code']];
            $this->phoneNumber = $params[$this->ussdConfig['request_variables']['phone_number']];
            $this->requestString = $params[$this->ussdConfig['request_variables']['request_string']];

            if ($this->ussdConfig['sanitizePhoneNumber']) {
                $this->phoneNumber = str_replace('+', '', $this->phoneNumber);
            }

            // Get pattern
            $pattern = $this->pattern_builder($this->requestString, $this->sessionId);

            $continue_session = true;
            // Get menu type
            if (empty($pattern)) {
                $user_menu = $default_menu;
                $menu_title = $this->ussdMenu[$user_menu]['menu_title'];
                $display = $this->ussdMenu[$user_menu]['display'];
                $response = $this->menu_builder($display, $menu_title);
            } else {
                // Use the built pattern to determin which menu option to navigate to
                $user_menu = $this->ussdMenu[$default_menu];

                // Navigate through the menu hierarchy
                foreach ($pattern as $ussd_option) {
                    if (isset($user_menu['options'][$ussd_option])) {
                        $user_menu = $user_menu['options'][$ussd_option];

                        if ($user_menu['options']['uses_same_method'] ??= false) {
                            $this->redis->set('ussd_session_same_endpoint_' . $this->sessionId, json_encode(['menu_option' => $ussd_option, 'method' => $user_menu['options']['execute_func']]));
                        }
                    } else {
                        // $user_menu = $user_menu['options'] ?? null;

                        if ($this->redis->get('ussd_session_same_endpoint_' . $this->sessionId)) {
                            $cached = json_decode($this->redis->get('ussd_session_same_endpoint_' . $this->sessionId), true);

                            $user_menu['options'][$cached['menu_option']]['display'] = "_EXECUTE_";
                            $user_menu['options'][$cached['menu_option']]['execute_func'] = $cached['method'];
                            $user_menu['options'][$cached['menu_option']]['uses_same_method'] = true;

                            $user_menu = $user_menu['options'][$cached['menu_option']];
                        } else {
                            $user_menu = $user_menu['options'] ?? null;
                        }
                    }
                }

                if ($user_menu) {
                    // Compile data to display
                    if (isset($user_menu['display'])) {
                        if ($user_menu['display'] == "_EXECUTE_") {
                            if (in_array($user_menu['execute_func'], $this->ussdConfig['disabled_func'])) {
                                $this->render("Service is not available at the moment.", false);
                            }

                            // Check if the method exists
                            if (method_exists($this->customClassNamespace, $user_menu['execute_func'])) {

                                // Append request parameters to pattern
                                $core = [];
                                $core[$this->ussdConfig['request_variables']['session_id']] = $this->sessionId;
                                $core[$this->ussdConfig['request_variables']['service_code']] = $this->serviceCode;
                                $core[$this->ussdConfig['request_variables']['phone_number']] = $this->phoneNumber;
                                $pattern[] = $core;

                                // If it exists, create an instance of the class
                                $object = $this->customClassNamespace;

                                if (!is_object($object)) {
                                    $object = new $this->customClassNamespace();
                                }
                                // Call the method dynamically
                                $display = call_user_func(array($object, $user_menu['execute_func']), $pattern);
                            } else {
                                // Handle if the method doesn't exist
                                $this->render("Service is not defined.", false);
                            }
                        } else {

                            $display = $user_menu['display'];
                        }
                    } else {
                        $display = 'Empty Response';
                    }

                    $menu_items_displayed = null;
                    if (isset($user_menu['items_displayed'])) {
                        $menu_items_displayed = $user_menu['items_displayed'];
                    }

                    // Generate final output for display
                    $menu_title = isset($user_menu['menu_title']) ? $user_menu['menu_title'] : NULL;
                    $response = $this->menu_builder($display, $menu_title, $menu_items_displayed);

                    // End session if menu options stop at function execution
                    if (!isset($user_menu['options'])) {
                        $continue_session = false;
                        // Delete session data
                        $this->redis->del('ussd_session_' . $this->sessionId);
                    }
                } else {
                    $continue_session = false;
                    $response = "Unknown Option";

                    // Delete session data
                    $this->redis->del('ussd_session_' . $this->sessionId);
                }
            }
            $this->render($response, $continue_session);
        } catch (\Exception $e) {
            // 1. Construct the log message
            $logMessage = sprintf(
                "[%s] USSD Error | Session: %s | Message: %s | File: %s:%d\n",
                date('Y-m-d H:i:s'),
                $this->sessionId,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );

            // 2. Log to custom file if path exists, otherwise fallback to system log
            $logPath = $this->ussdConfig['error_log_path'] ?? null;
            if ($logPath) {
                error_log($logMessage, 3, $logPath);
            } else {
                error_log($logMessage); // Default PHP error log
            }

            // 3. Cleanup Redis Session
            $this->redis->del('ussd_session_' . $this->sessionId);

            // 4. Handle Environment Display
            $env = $this->ussdConfig['environment'] ?? 'production';
            if ($env === 'production') {
                $response = $this->menu_builder(
                    $this->ussdConfig['error_message'],
                    $this->ussdConfig['error_title'],
                    null
                );
                $this->render($response, false);
            } elseif ($env === 'development') {
                header('Content-Type: text/plain');
                echo "Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString();
                exit;
            } else {
                echo "END Error: Unsupported environment (" . htmlspecialchars($env) . ")";
                exit;
            }
        }
    }



    public function pattern_builder(string $string, string $session_id)
    {
        $ussd_session = array();

        if ($cachedValue = $this->redis->get('ussd_session_' . $this->sessionId)) {
            $result = json_decode($cachedValue, true);
            if (!is_null($result)) {
                $this->redis->expire('ussd_session_' . $this->sessionId, 20); // expire in 20 seconds
                $ussd_session = $result;
            }
        }

        if (!isset($ussd_session['pattern'])) {
            $ussd_session['pattern'] = [];
        }

        if ($string !== '') {
            if ($this->ussdConfig['input_format'] == 'chained') {
                $exploded_string = explode('*', $string);
                $option = end($exploded_string);
            } else {
                $option = $string;
            }

            if ($option == $this->ussdConfig['nav_prev']) {
                array_pop($ussd_session['pattern']);
            } elseif ($option == $this->ussdConfig['nav_next']) {
                if (isset($ussd_session['nav'])) {
                    $ussd_session['nav']['current_page'] += 1;
                } else {
                    $ussd_session['nav'] = array('pages' => null, 'current_page' => 1, 'nav_direction' => 'next');
                }
            } else {
                // Remove dangling page navigation data
                if (isset($ussd_session['nav'])) {
                    unset($ussd_session['nav']);
                }
                $ussd_session['pattern'][] = $option;
            }
        }

        // Cache pattern to session data
        $this->redis->set('ussd_session_' . $session_id, json_encode($ussd_session));

        $this->redis->expire('ussd_session_' . $session_id, 20); // expire in 20 seconds
        return $ussd_session['pattern'];
    }

    public function menu_builder($display, $menu_title = null, $menu_items_displayed = null)
    {
        $separator = $this->ussdConfig['menu_items_separator'];
        $menu_items = explode($separator, $display);

        // 1. Fetch and decode session once (optimized)
        $sessionKey = 'ussd_session_' . $this->sessionId;
        $ussd_session = json_decode($this->redis->get($sessionKey) ?? '[]', true);
        if (!empty($ussd_session)) {
            $this->redis->expire($sessionKey, 20);
        }

        // 2. Handle Pagination (Next)
        if ($menu_items_displayed > 0 && count($menu_items) > $menu_items_displayed) {
            $menu_items = array_slice($menu_items, 0, $menu_items_displayed);
            $menu_items[] = $this->ussdConfig['nav_next'] . '. Next';

            if (isset($ussd_session['nav'])) {
                // Log/Debug navigation if needed
                error_log(json_encode($ussd_session['nav']));
            }
        }

        // 3. Handle Back Navigation
        if (!empty($ussd_session['pattern'])) {
            $menu_items[] = $this->ussdConfig['nav_prev'] . '. Back';
        }

        // 4. Build Response String
        $response = $menu_title ? $menu_title . PHP_EOL : '';
        $charLimit = $this->ussdConfig['chars_per_line'];

        foreach ($menu_items as $item) {
            $trimmed = trim($item);
            $line = $charLimit ? substr($trimmed, 0, $charLimit) : $trimmed;
            $response .= $line . PHP_EOL;
        }

        return rtrim($response); // Clean up trailing newline
    }

    /**
     * Outputs the USSD response to the user and terminates script execution.
     *
     * @param string $response The message to display to the user.
     * @param bool $continue Whether to continue the USSD session (true) or end it (false).
     * @return void This method will terminate script execution with exit.
     *
     * @note This method will terminate script execution using exit.
     */
    public function render(string $response, bool $continue = true)
    {
        // Remove nav back option and any leading/trailing whitespace or separator if continue is false
        if (!$continue) {
            $separator = preg_quote($this->ussdConfig['menu_items_separator'], '/');
            $backOption = preg_quote($this->ussdConfig['nav_prev'] . '. Back', '/');
            $pattern = "/(^|\s|{$separator}+){$backOption}(\s*|$)/m";
            $response = preg_replace($pattern, '', $response);
            $response = preg_replace("/\n{2,}/", "\n", $response); // Remove extra newlines
            $response = trim($response);
        }
        // Render response based on the specified adaptor or output format
        if (isset($this->ussdConfig['adaptor']) && !is_null($this->ussdConfig['adaptor'])) {
            $adaptor = $this->ussdConfig['adaptor'];
            if ($adaptor == 'AfricasTalking') {
                $response = $continue ? 'CON ' . $response : 'END ' . $response;
                header("Content-type: text/plain");
            } elseif ($adaptor == 'TrueAfrica') {
                $response_xml  = '<?xml version="1.0" encoding="utf-8"?>';
                $response_xml .= '<methodCall>';
                $response_xml .= '<methodName>USSD.' . ($continue ? 'CONT' : 'END') . '</methodName>';
                $response_xml .= '<params>';
                $response_xml .= '<param>';
                $response_xml .= '<value>';
                $response_xml .= '<struct>';
                if ($continue) {
                    $response_xml .= '<member>';
                    $response_xml .= '<name>response</name>';
                    $response_xml .= '<value><string>' . $response . '</string></value>';
                    $response_xml .= '</member>';
                }
                $response_xml .= '<member>';
                $response_xml .= '<name>session</name>';
                $response_xml .= '<value><string>' . $this->sessionId . '</string></value>';
                $response_xml .= '</member>';
                $response_xml .= '</struct>';
                $response_xml .= '</value>';
                $response_xml .= '</param>';
                $response_xml .= '</params>';
                $response_xml .= '</methodCall>';
                header("Content-type: text/xml");
            } elseif ($adaptor == 'DMark') {
                $render_response = [
                    'responseString' => urlencode($response),
                    'action' => $continue ? 'request' : 'end'
                ];
                $response = json_encode($render_response);
                header("Content-type: application/json");
            } elseif ($adaptor == 'UConnect') {
                $render_response = [
                    'response_string' => $response,
                    'action' => $continue ? 'request' : 'end'
                ];
                $response = json_encode($render_response);
                header("Content-type: application/json");
            } else {
                throw new \Exception("Unsupported adaptor (" . $adaptor . ")");
            }
        } else {
            if ($this->ussdConfig['output_format'] == "conend") {
                $response = $continue ? 'CON ' . $response : 'END ' . $response;
                header("Content-type: text/plain");
            } elseif ($this->ussdConfig['output_format'] == "json") {
                $render_response = [
                    'response_string' => $response,
                    'action' => $continue ? 'request' : 'end'
                ];
                $response = json_encode($render_response);
                header("Content-type: application/json");
            }
        }
        echo $response;
        exit;
    }

    public function validateConfig(array $customConfig)
    {
        if (isset($customConfig['environment'])) {
            if (!in_array($customConfig['environment'], ['development', 'production'])) {
                throw new \Exception("Invalid configuration value for 'environment'. Expected 'development' or 'production'.");
            }
        }

        if (isset($customConfig['output_format'])) {
            if (!in_array($customConfig['output_format'], ['conend', 'json'])) {
                throw new \Exception("Invalid configuration value for 'output_format'. Expected 'conend' or 'json'.");
            }
        }

        if (isset($customConfig['enable_chained_input'])) {
            if (!is_bool($customConfig['enable_chained_input'])) {
                throw new \Exception("Invalid configuration value for 'enable_chained_input'. Expected a boolean 'true' or 'false'.");
            }
        }

        if (isset($customConfig['enable_back_and_forth_menu_nav'])) {
            if (!is_bool($customConfig['enable_back_and_forth_menu_nav'])) {
                throw new \Exception("Invalid configuration value for 'enable_back_and_forth_menu_nav'. Expected a boolean 'true' or 'false'.");
            }
        }

        if (isset($customConfig['chars_per_line'])) {
            if (!preg_match('/^(\d+|NULL)$/', $customConfig['chars_per_line'])) {
                throw new \Exception("Invalid configuration value for 'chars_per_line'. Expected a boolean positive integer or 'NULL'.");
            }
        }

        if (isset($customConfig['menu_items_separator'])) {
            if (!in_array($customConfig['menu_items_separator'], ['|', ','])) {
                throw new \Exception("Invalid configuration value for 'menu_items_separator'. Expected '|' or ','.");
            }
        }

        if (isset($customConfig['sanitizePhoneNumber'])) {
            if (!is_bool($customConfig['sanitizePhoneNumber'])) {
                throw new \Exception("Invalid configuration value for 'sanitizePhoneNumber'. Expected a boolean 'true' or 'false'.");
            }
        }

        return true;
    }

    public function get_request_params($adaptor = null)
    {
        if (is_null($adaptor)) {
            $adaptor = $this->ussdConfig['adaptor'] ?? 'default';
        }

        if ($adaptor == 'default') {
            $params = $_POST;
            $params = [
                'sessionId' => $params[$this->ussdConfig['request_variables']['session_id']],
                'serviceCode' => $params[$this->ussdConfig['request_variables']['service_code']],
                'phoneNumber' => $params[$this->ussdConfig['request_variables']['phone_number']],
                'text' => $params[$this->ussdConfig['request_variables']['request_string']]
            ];
        } elseif ($adaptor == 'AfricasTalking') {
            $data = $_POST;
            $params = [
                'sessionId' => $data['sessionId'],
                'serviceCode' => $data['serviceCode'],
                'phoneNumber' => $data['phoneNumber'],
                'text' => $data['text']
            ];
        } elseif ($adaptor == 'TrueAfrica') {
            $xmlString = file_get_contents('php://input');
            $xml = simplexml_load_string($xmlString);

            // Navigate to <struct>
            $struct = $xml->params->param->value->struct;
            $params = [];

            // Extract values
            foreach ($struct->member as $member) {
                $name = (string)$member->name;
                $value = (string)$member->value->string;
                if ($name === 'msisdn') {
                    $params['phoneNumber'] = $value;
                } elseif ($name === 'shortcode') {
                    // Remove trailing '#' if present
                    $shortcode_clean = rtrim($value, '#');
                    $parts = explode('*', $shortcode_clean);

                    // Extract shortcode: first two segments, re-add leading '*'
                    if (isset($parts[1]) && isset($parts[2])) {
                        $params['serviceCode'] = '*' . $parts[1] . '*' . $parts[2];
                    } else {
                        $params['serviceCode'] = $shortcode_clean;
                    }

                    // Extract text: everything after the second '*'
                    $params['text'] = '';
                    if (count($parts) > 3) {
                        $params['text'] = implode('*', array_slice($parts, 3));
                    }
                } elseif ($name === 'session') {
                    $params['sessionId'] = $value;
                }
            }
        } elseif ($adaptor == 'DMark') {
            // $data = file_get_contents('php://input');
            // $params = json_decode($data, TRUE);
            // $data = $_POST;
            $data = $_GET;
            $params = [
                'sessionId' => $data['transactionID'],
                'serviceCode' => $data['ussdServiceCode'],
                'phoneNumber' => $data['msisdn'],
                'text' => $data['ussdRequestString']
            ];
        } elseif ($adaptor == 'UConnect') {
            $data = file_get_contents('php://input');
            $data = json_decode($data, TRUE);
            // $data = $_POST;
            $params = [
                'sessionId' => $data['session_id'],
                'serviceCode' => '*260#',
                'phoneNumber' => $data['phone_number'],
                'text' => $data['request_string']
            ];
        } else {
            throw new \Exception("Unsupported adaptor (" . $adaptor . ")");
        }
        return $params;
    }

    public function configInfo()
    {
        return $this->ussdConfig;
    }
}
