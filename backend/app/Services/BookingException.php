<?php

namespace App\Services;

use RuntimeException;

/** A booking rule was violated; the message is safe to show to the user. */
class BookingException extends RuntimeException {}
