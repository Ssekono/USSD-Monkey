### USSD Monkey

The USSD Monkey package is designed to help developers quickly build USSD applications by defining their menu flow in a JSON file. This README.md file provides documentation on how to use the package effectively.

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
$ussd->push($params, $menu);
```

The `push` method takes two arguments: the parameters sent by your USSD gateway (such as session ID, service code, phone number, and request string) and the default menu to display.

#### Configuration

The package comes with default configuration settings, but you can override them as needed. Here are some of the key configuration options:

- `environment`: Set the environment to "production" or "development".
- `input_format`: Define the input format. Accepted value: "chained".
- `output_format`: Determine the output format. Accepted values: "conend" or "json".
- `enable_chained_input`: Enable or disable chained input if supported by the USSD gateway provider.
- `enable_back_and_forth_menu_nav`: Enable or disable back and forth menu navigation.
- `chars_per_line`: Specify the number of characters per line.
- `menu_items_separator`: Define the separator for menu items (| or ,).
- `nav_next`: Define the navigation string for moving to the next menu.
- `nav_prev`: Define the navigation string for moving to the previous menu.
- `sanitizePhoneNumber`: Enable or disable phone number sanitization.
- `error_title`: Set the title for error messages.
- `error_message`: Set the default error message.
- `disabled_func`: List any disabled functions from your project to be marked as suspended during USSD usage.
- `request_variables`: Map request variables to their corresponding names as sent by the USSD Gateway provider (session id, service code, phone number, request string).
- `redis`: Configuration settings for Redis, if used.

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
$config = [
    'environment' => 'development',
    'ussd_menu_file' => ROOTPATH . 'ussdMenu.json',
    'custom_class_namespace' => 'App\Controllers\USSD',
    // Override other configuration options as needed
];

$ussd = new USSDMonkey($config);
$ussd->push($params, $menu);
```

That's it! You're now ready to use the USSD Monkey package to build your USSD applications.
