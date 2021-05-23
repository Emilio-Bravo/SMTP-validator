<?php

namespace Bravo;

use Bravo\ExceptionInterface;
use Exception;

class noConnectionException extends Exception implements ExceptionInterface
{
    public function __construct($host)
    {
        parent::__construct("Cannot open a connection to remote host ($host)");
    }
    public function error()
    {
        return $this->getMessage();
    }
}
