<?php

namespace App\Exceptions;

use RuntimeException;

/** A reseller already has as many users as their limit allows. */
class ResellerLimitReached extends RuntimeException {}
