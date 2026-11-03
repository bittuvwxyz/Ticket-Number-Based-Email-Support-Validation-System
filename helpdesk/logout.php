<?php
/**
 * Logout Endpoint
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpdesk-private/config/bootstrap.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/AuthService.php';

AuthService::logout();
AuthService::startSecureSession();
$_SESSION['flash_success'] = "You have been logged out securely.";

header("Location: " . config('app.url') . "/login.php");
exit;
