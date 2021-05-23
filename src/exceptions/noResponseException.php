<?php

namespace Bravo;

use Bravo\ExceptionInterface;
use Exception;

class noResponseException extends Exception implements ExceptionInterface
{
    public function __construct()
    {
        parent::__construct("No response in recv)");
    }
    public function error()
    {
        return $this->getMessage();
    }
}
