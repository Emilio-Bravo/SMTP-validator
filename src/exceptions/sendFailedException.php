<?php

namespace Bravo;

use Bravo\ExceptionInterface;
use Exception;

class sendFailedException extends Exception implements ExceptionInterface
{
    public function __construct($host)
    {
        parent::__construct("Send failed on $host");
    }
    public function error()
    {
        return $this->getMessage();
    }
}
