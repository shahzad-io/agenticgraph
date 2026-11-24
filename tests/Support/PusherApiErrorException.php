<?php

namespace Pusher;

// The Pusher SDK is optional and not installed for tests. Its HTTP error: getCode() is the status, 0 for a network error.
if (! class_exists(ApiErrorException::class)) {
    class ApiErrorException extends \Exception {}
}
