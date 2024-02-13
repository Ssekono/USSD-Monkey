<?php

namespace GantryMotion\USSDMonkey;

use Predis\Client;

class USSDMonkey
{
    public $sessionId;
    public $serviceCode;
    public $phoneNumber;
    public $requestString;

    protected $ussdConfig;
    protected $ussdMenu;

    protected $redis;

    public function __construct(array $config = [])
    {
        // Load configuration
        $defaultConfig = include(__DIR__ . '/../config/default.php');
        $this->ussdConfig = array_merge($defaultConfig, $config);

        // Load Menu
        if (isset($this->ussdConfig['ussd_menu_file'])) {
            $this->loadJsonConfig($this->ussdConfig['ussd_menu_file']);
        }

        // Set Redis
        $this->redis = new Client([
            'scheme' => $this->ussdConfig['redis']['scheme'],
            'host' => $this->ussdConfig['redis']['host'],
            'port' => $this->ussdConfig['redis']['port'],
            'password' => $this->ussdConfig['redis']['password'] ?? null,
        ]);
    }


    protected function loadJsonConfig(string $jsonConfigFile)
    {
        if (file_exists($jsonConfigFile)) {
            $userConfig = json_decode(file_get_contents($jsonConfigFile), true);
            if ($userConfig !== null) {
                $this->ussdConfig = $userConfig;
            } else {
                throw new \RuntimeException("Invalid JSON format in the provided USSD Menu file: $jsonConfigFile");
            }
        } else {
            throw new \InvalidArgumentException("USSD Menu file not found: $jsonConfigFile");
        }
    }


    public function connect(array $params)
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
            $default_menu = $this->userMenuSelector($this->phoneNumber);
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
                    } else {
                        $user_menu = $user_menu['options'];
                    }
                }

                // Compile data to display
                if ($user_menu['display'] == "_EXECUTE_") {
                    if (in_array($user_menu['execute_func'], $this->ussdConfig['disabled_func'])) {
                        $this->render("Service is not available.", false);
                    }
                    $display = call_user_func(array($this, $user_menu['execute_func']), $pattern);
                } else {
                    $display = $user_menu['display'];
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
                    $this->cache('delete', 'ussd_session_' . $this->sessionId);
                }
            }
            $this->render($response, $continue_session);
        } catch (\Exception $e) {
            // Delete session data
            $this->cache('delete', 'ussd_session_' . $this->sessionId);

            $menu_title = $this->ussdConfig['error_title'];
            $display = $this->ussdConfig['error_message'];
            $menu_items_displayed = null;
            $response = $this->menu_builder($display, $menu_title, $menu_items_displayed);
            $continue_session = false;
            $this->render($response, $continue_session);
        }
    }



    public function pattern_builder(string $string, string $session_id)
    {
        $ussd_session = array();
        // Fetch cached session data
        if ($result = $this->cache('get', 'ussd_session_' . $session_id)) {
            $ussd_session = $result;
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
        $this->cache('set', 'ussd_session_' . $session_id, $ussd_session);
        return $ussd_session['pattern'];
    }

    public function menu_builder($display, $menu_title = null, $menu_items_displayed = null)
    {
        $separator = $this->ussdConfig['menu_items_separator'];
        $menu_items = explode($separator, $display);

        // Limit number of displayed menu items
        if (!is_null($menu_items_displayed) && $menu_items_displayed > 0) {

            if ($ussd_session = $this->cache('get', 'ussd_session_' . $this->sessionId)) {
                if (isset($ussd_session['nav'])) {
                    print_r($ussd_session['nav']);
                }
            }

            $sliced_menu_items = array_slice($menu_items, 0, $menu_items_displayed);
            // Append navigation menu options to menu
            if (count($menu_items) > $menu_items_displayed) {
                $sliced_menu_items[] = $this->ussdConfig['nav_next'] . '. Next';
            }
            $menu_items = $sliced_menu_items;
        }

        // Add a back navigation menu item
        if ($ussd_session = $this->cache('get', 'ussd_session_' . $this->sessionId)) {
            if (isset($ussd_session['pattern']) && !empty($ussd_session['pattern'])) {
                $menu_items[] = $this->ussdConfig['nav_prev'] . '. Back';
            }
        }

        $response = is_null($menu_title) ? '' : $menu_title . PHP_EOL;
        foreach ($menu_items as $menu_item) {
            if (is_null($this->ussdConfig['chars_per_line'])) {
                $response .= trim($menu_item) . PHP_EOL;
            } else {
                $response .= trim(substr($menu_item, 0, $this->ussdConfig['chars_per_line'])) . PHP_EOL;
            }
        }
        return $response;
    }

    public function render(string $response, bool $continue = true)
    {
        // Remove nav back option if continue is false
        if (!$continue) {
            $response = str_replace($this->ussdConfig['nav_prev'] . '. Back', '', $response);
        }

        if ($this->ussdConfig['output_format'] == "conend") {
            if ($continue) {
                $response = 'CON ' . $response;
            } else {
                $response = 'END ' . $response;
            }
        } elseif ($this->ussdConfig['output_format'] == "json") {
            $render_response = array(
                'response_string' => $response,
                'action' => $continue == true ? 'request' : 'end'
            );
            $response = json_encode($render_response);
        }
        // Send response back to user
        header("Content-type: text/plain");
        echo $response;
        exit;
    }


    public function cache(string $action, string $key, $value = null)
    {
        if ($action == 'set') {
            return $this->redis->set($key, $value);
        } elseif ($action == 'delete') {
            return $this->redis->delete($key);
        } else {
            return $this->redis->get($key);
        }
    }
}
