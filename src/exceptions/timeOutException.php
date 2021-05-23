<?php

namespace Bravo;

use Bravo\ExceptionInterface;
use Exception;

class timeOutException extends Exception implements ExceptionInterface
{
    public function __construct()
    {
        parent::__construct("Time out in recv");
    }
    public function error()
    {
        return $this->getMessage();
    }
}
