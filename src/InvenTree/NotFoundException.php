<?php

declare(strict_types=1);

namespace InvenTreeSync\InvenTree;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Exception class for 404 Not Found errors when communicating with the InvenTree API.
class NotFoundException extends ClientException {
}
