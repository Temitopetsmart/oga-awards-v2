<?php
declare(strict_types=1);

namespace App;

/** The payment provider could not be reached or answered badly. Never shown raw to visitors. */
final class GatewayException extends \RuntimeException
{
}