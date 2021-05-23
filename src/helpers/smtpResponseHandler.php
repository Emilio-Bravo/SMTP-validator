<?php

namespace Bravo;

use Bravo\timeOutException;
use Bravo\sendFailedException;
use Bravo\noResponseException;

class smtpResponseHandler
{
    public static function handleResponseTimeout($socket)
    {
        $response_info = stream_get_meta_data($socket);
        if (!empty($response_info['timed_out'])) throw new timeOutException;
    }

    public static function handleResponse($response)
    {
        if ($response === false) throw new noResponseException;
    }

    public static function handleOnSentResponse($response, $host)
    {
        if ($response === false) throw new sendFailedException($host);
    }
}
