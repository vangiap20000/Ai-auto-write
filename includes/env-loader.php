<?php
if (!defined('ABSPATH')) exit;

function ai_aw_load_env() {
    $env_file = plugin_dir_path(dirname(__FILE__)) . '.env';
    $env_vars = [];

    if (file_exists($env_file)) {
        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
                $env_vars[$name] = $value;
            }
        }
    }
    return $env_vars;
}

ai_aw_load_env();

function ai_aw_get_config($option_name, $env_key, $default_value = '') {
    $env_value = getenv($env_key);
    $final_default = ($env_value !== false && $env_value !== '') ? $env_value : $default_value;

    return get_option($option_name, $final_default);
}
