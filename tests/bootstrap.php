<?php
declare(strict_types=1);

// Vendor autoloader lives at the project root (4 levels up from tests/)
require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

// Plugin classes under test
require_once dirname(__DIR__) . '/includes/class-retailer-api.php';
require_once dirname(__DIR__) . '/includes/class-affiliate-converter.php';
require_once dirname(__DIR__) . '/includes/class-restart-registry-controller.php';
