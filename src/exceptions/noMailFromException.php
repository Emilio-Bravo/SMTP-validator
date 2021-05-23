<?php

namespace Bravo;

use Bravo\ExceptionInterface;
use Exception;

class noMailFromException extends Exception implements ExceptionInterface
{
    public function __construct()
    {
        parent::__construct("MAIL FROM is not set)");
    }
    public function error()
    {
        return $this->getMessage();
    }
}