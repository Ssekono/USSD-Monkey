### USSD Monkey

The USSD Monkey package is designed to help developers quickly build USSD applications by defining their menu flow in a JSON file. This README.md file provides documentation on how to use the package effectively. This package uses Redis to temporarily store session information.

#### Installation

To install the USSD Monkey package, you can use Composer:

```bash
composer require gantry-motion/ussd-monkey
```

#### Usage

After installing the package, you need to configure it by providing the path to your USSD menu JSON file and specifying a custom class namespace if needed. Here's an example configuration:

```php
$config = [
    'environment' => 'development',
    'ussd_menu_file' => ROOTPATH . 'ussdMenu.json',
    'custom_class_namespace' => 'App\Controllers\USSD'
];
```

Then, instantiate the USSD Monkey class and call the `push` method to render the content of your application menu:

```php
$ussd = new USSDMonkey($config);
$params = $ussd->get_request_params();
$ussd->push($params, $menu);
```

The `get_request_params` method accepts an optional USSD gateway name (e.g., `AfricasTalking`, `TrueAfrica`, `DMark`, or `UConnect`). This argument automatically adapts the request parameters for the specified gateway. If you omit this argument, the method will use the gateway configured in the `adaptor` attribute. If no adaptor is set, it will fall back to the default `request_variables`.

The `push` method takes two arguments: the parameters sent by your USSD gateway (such as session ID, service code, phone number, and request string) and the default menu to display.


#### Configuration

The package comes with default configuration settings, but you can override them as needed. Here are some of the key configuration options:

- `environment`: Set the environment to "production" or "development".
- `input_format`: Define the input format. Accepted value: "chained".
- `output_format`: Determine the output format. Accepted values: "conend" or "json".
- `enable_chained_input`: Enable or disable chained input if supported by the USSD gateway provider. Accepted values: "true" or "false".
- `enable_back_and_forth_menu_nav`: Enable or disable back and forth menu navigation. Accepted values: "true" or "false".
- `chars_per_line`: Specify the number of characters per line.
- `menu_items_separator`: Define the separator for menu items (| or ,).
- `nav_next`: Define the navigation string for moving to the next menu.
- `nav_prev`: Define the navigation string for moving to the previous menu.
- `sanitizePhoneNumber`: Enable or disable phone number sanitization. Accepted values: "true" or "false".
- `error_title`: Set the title for error messages.
- `error_message`: Set the default error message.
- `disabled_func`: List any disabled functions from your project to be marked as suspended during USSD usage.
- `adaptor`: Easily adapt your application's requests and responses to selected USSD gateways. Accepted value: "AfricasTalking", "TrueAfrica", "DMark" or "UConnect".
- `request_variables`: Map request variables to their corresponding names as sent by the USSD Gateway provider (session id, service code, phone number, request string).
- `redis`: Configuration settings for Redis.

You can override these settings by providing a custom configuration array when instantiating the USSD Monkey class.

To view the configuration that USSD Monkey will use, you can utilize the `configInfo()` function provided by the package. Here's how you can use it:

```php
$ussd = new USSDMonkey($config);
$current_config = $ussd->configInfo();
echo json_encode($current_config);
```

This code will output a JSON representation of the current configuration settings that USSD Monkey will use for your USSD application. You can then inspect these settings to ensure they align with your requirements.

#### Example

Here's an example of how you might instantiate the USSD Monkey class with custom configuration:

```php
use GantryMotion\USSDMonkey\USSDMonkey;

$config = [
    'ussd_menu_file' => 'path/to/ussdMenu.json',
    'custom_class_namespace' => 'App\Controllers\USSD',
    // Override other configuration options as needed
];

$ussd = new USSDMonkey($config);
$params = $ussd->get_request_params();
$ussd->push($params, 'default_menu');
```

Below is an example of the `ussdMenu.json` file structure:

```json
{
    "default_menu": {
        "menu_title": "USSD Monkey",
        "display": "1. Say Hello |2. Say Goodbye |3. Say Good Night",
        "options": {
            "1": {
                "display": "Enter a name",
                "options": {
                    "display": "_EXECUTE_",
                    "execute_func": "say_hello"
                }
            },
            "2": {
                "display": "Enter a name",
                "options": {
                    "display": "_EXECUTE_",
                    "execute_func": "get_people_titles",
                    "options": {
                        "display": "_EXECUTE_",
                        "execute_func": "say_goodbye"
                    }
                }
            },
            "3": {
                "display": "Enter a name",
                "options": {
                    "display": "1. Wish sweet dream |2. Let bedbugs not bite",
                    "options": {
                        "1": {
                            "display": "_EXECUTE_",
                            "execute_func": "say_goodnight_sweet_dreams"
                        },
                        "2": {
                            "display": "_EXECUTE_",
                            "execute_func": "say_goodnight_bedbugs_dont_bite"
                        }
                    }
                }
            }
        }
    }
}
```

This JSON structure defines the menu options for your USSD application. Each menu item has a display label and, optionally, an associated action to execute (`_EXECUTE_`). The structure supports nested options for creating multi-level menus. Ensure your `ussdMenu.json` file adheres to this format to work correctly with USSD Monkey.

Below is an example of the `USSD` PHP class containing custom methods for your USSD application:

```php
namespace App\Controllers;

class USSD
{
    public function say_hello($data)
    {
        $name = $data[1];
        $display = "Hello " . $name;
        return $display;
    }

    public function get_people_titles($data)
    {
        $titles = ["1" => "Mr", "2" => "Mrs", "3" => "Ms", "4" => "Dr"];

        $title_list = [];
        foreach ($titles as $key => $value) {
            $title_list[] = $key . ' ' . $value;
        }
        $display = "Select Title" . PHP_EOL;
        $display .= implode('|', $title_list);
        return $display;
    }

    public function say_goodbye($data)
    {
        $name = $data[1];
        $index = $data[2];

        $titles = ["1" => "Mr", "2" => "Mrs", "3" => "Ms", "4" => "Dr"];

        $display = "Goodbye " . $titles[$index] . " " . $name;
        return $display;
    }

    public function say_goodnight_sweet_dreams($data)
    {
        $name = $data[1];
        $display = "Good night " . $name . ", and sweet dreams";
        return $display;
    }

    public function say_goodnight_bedbugs_dont_bite($data)
    {
        $name = $data[1];
        $display = "Good night " . $name . ", and don't let the bedbugs bite";
        return $display;
    }
}
```

This `USSD` class contains methods that correspond to the actions defined in your `ussdMenu.json` file. These methods process the data received from the USSD menu and return appropriate responses. Ensure that the namespace and class name match the ones specified in your configuration.


That's it! You're now ready to use the USSD Monkey package to build your USSD applications.
