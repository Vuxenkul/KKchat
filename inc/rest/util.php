<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kkchat_rest_namespace')) {
    function kkchat_rest_namespace(): string
    {
        return 'kkchat/v1';
    }
}

if (!function_exists('kkchat_close_session_if_open')) {
    function kkchat_close_session_if_open(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
    }
}
