<?php

namespace Bravo;

use Bravo\noConnectionException;
use Exception;
use UnexpectedValueException;

/**
 * This class performs an SMTP connection
 * @author Emilio Bravo
 */

class SMTP
{

    /**
     * The port to use for the connection
     */

    private $port = 25;

    private $host;

    private $socket;

    private $connection_timeout = 10;

    private $send_noops = true;

    private $no_comm_is_valid = false;

    private $no_con_is_valid = false;

    private $results = [];

    private $greylisted_considered_valid = false;

    public $stream_context_args;

    private $smtp_states = [
        'helo' => false,
        'mail' => false,
        'rcpt' => false
    ];

    private $command_timeouts = [
        'ehlo' => 120,
        'helo' => 120,
        'tls'  => 180, // start tls
        'mail' => 300, // mail from
        'rcpt' => 300, // rcpt to,
        'rset' => 30,
        'quit' => 60,
        'noop' => 60
    ];

    private $debug = true;

    private $catchall_is_valied = true;

    private $catchall_test = false;

    const SMTP_CONNECT_SUCCESS = 220;
    const SMTP_QUIT_SUCCESS    = 221;
    const SMTP_GENERIC_SUCCESS = 250;
    const SMTP_USER_NOT_LOCAL  = 251;
    const SMTP_CANNOT_VRFY     = 252;
    const SMTP_SERVICE_UNAVAILABLE = 421;
    const SMTP_MAIL_ACTION_NOT_TAKEN = 450;
    const SMTP_MAIL_ACTION_ABORTED = 451;
    const SMTP_REQUESTED_ACTION_NOT_TAKEN = 452;
    const SMTP_SYNTAX_ERROR = 500;
    const SMTP_NOT_IMPLEMENTED = 502;
    const SMTP_BAD_SEQUENCE = 503;
    const SMTP_MBOX_UNAVAILABLE = 550;
    const SMTP_TRANSACTION_FAILED = 554;

    private $greylisted = [
        self::SMTP_MAIL_ACTION_NOT_TAKEN,
        self::SMTP_MAIL_ACTION_ABORTED,
        self::SMTP_REQUESTED_ACTION_NOT_TAKEN
    ];

    const CRLF = "\r\n";

    private $from_domain;

    private $from_user;

    private $domains = [];

    private $domains_info = [];

    /**
     * Performs the connection
     */

    public function __construct($emails = [], $sender)
    {
        if (!empty($emails)) $this->setEmails($emails);
        if (!is_null($sender)) $this->setSender($sender);
    }

    /**
     * Sets the email addresses to be used
     */

    public function __invoke($emails = [], $sender)
    {
        if (!empty($emails)) $this->setEmails($emails);
        if (!is_null($sender)) $this->setSender($sender);
    }

    /**
     * Disconnects from the server
     */

    public function __destruct()
    {
        $this->disconnect(true);
    }


    public function validate($emails = [], $sender = null)
    {
        $this->results = [];

        if (!empty($emails)) {
            $this->setEmails($emails);
        }
        if (!is_null($sender)) {
            $this->setSender($sender);
        }

        if (empty($this->domains)) {
            return $this->results;
        }

        $this->loop();

        return $this->getResults();
    }

    public function performSMTPDance($domain, array $users)
    {
        $this->throwIfNotConnected();
        try {
            $this->attemptMailCommands($domain, $users);
        } catch (UnexpectedValueException $e) {
            $this->setDomainResults($users, $domain, $this->no_comm_is_valid);
        } catch (timeOutException $e) {
            $this->setDomainResults($users, $domain, $this->no_comm_is_valid);
        }
    }
    public function attemptMailCommands($domain, array $users)
    {
        if (!$this->helo()) return;
        if (!$this->mail("$this->from_user@$this->from_domain")) {
            $this->setDomainResults($users, $domain, $this->no_comm_is_valid);
            return;
        }

        if (!$this->connected()) return;

        $is_catchall_domain = $this->accepts_any_recipient($domain);

        if ($is_catchall_domain) {
            if (!$this->catchall_is_valied) {
                $this->setDomainResults($users, $domain, $this->catchall_is_valied);
                return;
            }
        }

        $this->noop();

        array_map(fn ($user) => $this->results["$user@$domain"] = $this->rcpt("$user@$domain"), $users);

        return $this->results;
    }

    public function loop()
    {
        foreach ($this->domains as $domain => $users) {
            $mxs = $this->buildMxs($domain);
            $this->debug("MX records ($domain)" . print_r($mxs, true));
            $this->domains_info[$domain] = [];
            $this->domains_info[$domain]['users'] = $users;
            $this->domains_info[$domain]['mxs'] = $mxs;
            $this->setDomainResults($users, $domain, $this->no_con_is_valid);
            $this->attemptMultipleConnections($mxs);
            $this->performSMTPDance($domain, $users);
        }
    }
    /**
     * If the SMTP sender supports the SMTP extensions defined in RFC 1651, 
     * you can substitute the HELO command for EHLO.
     * An SMTP receiver that does not support the extensions 
     * will respond with a "500 Syntax error, command unrecognized" message. 
     * The SMTP sender should try again with HELO, or if it cannot relay 
     * the message without extensions, send a QUIT message.
     */

    public function ehlo()
    {
        try {
            //Modern service
            $this->send("EHLO $this->from_domain");
            $this->expect(self::SMTP_GENERIC_SUCCESS, $this->command_timeouts['ehlo']);
        } catch (UnexpectedValueException $e) {
            //Legacy service
            $this->send("HELO $this->from_domain");
            $this->expect(self::SMTP_GENERIC_SUCCESS, $this->command_timeouts['helo']);
        }
    }
    public function helo()
    {
        if ($this->smtp_states['helo']) return null;

        try {
            $this->expect(self::SMTP_CONNECT_SUCCESS, $this->command_timeouts['helo']);
            $this->ehlo();
            $this->smtp_states['helo'] = true;
            $result = true;
        } catch (UnexpectedValueException $e) {
            $result = false;
            $this->debug("Unexpected response after connecting {$e->getMessage()}");
            $this->disconnect(false);
        }
        return $result;
    }
    public function mail($from)
    {
        if (!$this->smtp_states['helo']) {
            //No helo Exception
        }
        $this->send("MAIL FROM:<$from>");
        try {
            $this->expect(self::SMTP_GENERIC_SUCCESS, $this->command_timeouts['mail']);
            $this->setSMTPStates(['mail' => true, 'rcpt' => false]);
            $result = true;
        } catch (UnexpectedValueException $e) {
            $result = false;
            $this->debug("Unexpected response to MAIL FROM \n {$e->getMessage()}");
            $this->disconnect(false);
        }
        return $result;
    }
    public function rcpt($to)
    {
        if (!$this->smtp_states['mail']) throw new noMailFromException; // In case that MAIL FROM is not set
        $valid = false;
        $expected_codes = [
            self::SMTP_GENERIC_SUCCESS,
            self::SMTP_USER_NOT_LOCAL
        ];
        if ($this->greylisted_considered_valid) $expected_codes = array_merge($expected_codes, $this->greylisted);
        try {
            $this->send("RCPT TO:<$to>");
            //Handle response
            try {
                $this->expect($expected_codes, $this->command_timeouts['rcpt']);
                $this->smtp_states['rcpt'] = true;
                $valid = true;
            } catch (UnexpectedValueException $e) {
                $this->debug("Unexpected response to RCPT TO: {$e->getMessage()}");
            }
        } catch (Exception $e) {
            $this->debug("Sending RCPT TO failed: {$e->getMessage()}");
        }
        return $valid;
    }
    public function rset()
    {
        $this->send('RSET');
        $expected_codes = [
            self::SMTP_GENERIC_SUCCESS,
            self::SMTP_CONNECT_SUCCESS,
            self::SMTP_NOT_IMPLEMENTED,
            //Hotmail
            self::SMTP_TRANSACTION_FAILED
        ];
        $this->expect($expected_codes, $this->command_timeouts['rset'], true);
        $this->setSMTPStates([
            'mail' => false,
            'rcpt' => false
        ]);
    }
    public function quit()
    {
        if ($this->smtp_states['helo']) {
            $expected_codes = [
                self::SMTP_GENERIC_SUCCESS,
                self::SMTP_QUIT_SUCCESS
            ];
            $this->send('QUIT');
            $this->expect($expected_codes, $this->command_timeouts['quit'], true);
        }
    }
    public function noop()
    {
        if ($this->send_noops) {
            $this->send('NOOP');
            $expected_codes = [
                self::SMTP_GENERIC_SUCCESS,
                self::SMTP_CONNECT_SUCCESS,
                self::SMTP_BAD_SEQUENCE,
                self::SMTP_SYNTAX_ERROR,
                self::SMTP_NOT_IMPLEMENTED
            ];
            $this->expect($expected_codes, $this->command_timeouts['noop'], true);
        }
    }
    public function recv($timeout)
    {
        $this->throwIfNotConnected();
        if (!is_null($timeout)) stream_set_timeout($this->socket, $timeout);
        //Get response
        $response = fgets($this->socket, 1024);
        $this->debug("<<<recv: $response");
        //Evaluate proccess and response
        smtpResponseHandler::handleResponseTimeout($this->socket);
        smtpResponseHandler::handleResponse($response);

        return $response;
    }

    public function connect($host)
    {
        $this->host = "$host:$this->port";
        $this->debug('Connecting to the server...');
        $this->socket = @stream_socket_client(
            $this->host,
            $errno,
            $errstr,
            $this->connection_timeout,
            STREAM_CLIENT_CONNECT
        );
        $this->throwIfNotConnected();
        $this->debug('Connected to the server');
    }

    public function attemptConnection($host)
    {
        try {
            $this->connect($host);
        } catch (noConnectionException $e) {
            $this->debug($e);
        }
    }

    public function throwIfNotConnected()
    {
        if (!is_resource($this->socket)) throw new noConnectionException($this->host);
    }

    public function debug($str)
    {
        if ($this->debug) {
            if (PHP_SAPI !== 'cli') $str = '<br><pre>' . htmlspecialchars($str) . '</pre>';
        }
        echo PHP_EOL . $str;
    }

    public function send($cmd)
    {
        $this->throwIfNotConnected();
        $result = fwrite($this->socket, $cmd);
        smtpResponseHandler::handleOnSentResponse($result, $this->host);
        return $result;
    }

    public function expect($codes, $timeout, $empty_response_allowed = false)
    {
        if (!is_array($codes)) $codes = (array) $codes;
        $code = null;
        try {
            $response = $this->recv($timeout);
            $complete_response = $response;
            while (preg_match('/^[0-9]+-/', $response)) {
                $response = $this->recv($timeout);
                $complete_response .= $response;
            }
            sscanf($response, '%d%s', $code, $text);
            if ($code == self::SMTP_SERVICE_UNAVAILABLE || ($empty_response_allowed === false && (null === $code || !in_array($code, $codes)))) throw new UnexpectedValueException;
        } catch (noResponseException $e) {
            $this->debug("No response in expect():{$e->getMessage()}");
        }
        return $complete_response;
    }
    public function setSMTPStates(array $keys_and_values)
    {
        array_map(fn ($key, $value) => $this->smtp_states[$key] = $value, array_keys($keys_and_values), $keys_and_values);
    }
    public function connected()
    {
        return is_resource($this->socket);
    }
    public function splitEmail($email)
    {
        $parts = explode('@', $email);
        $domain = array_pop($parts);
        $user = implode('@', $parts);
        return [$user, $domain];
    }
    protected function buildMxs($domain)
    {
        $mxs = [];
        list($hosts, $weights) = $this->mxQuery($domain);
        array_map(fn ($key, $host) => $mxs[$host] = $weights[$key], $hosts);
        asort($mxs);
        $mxs[$domain] = 0;
        return $mxs;
    }
    public function setEmails(array $emails)
    {
        if (!is_array($emails)) $emails = (array) $emails;
        foreach ($emails as $email) {
            list($user, $domain) = $this->splitEmail($email);
            if (!isset($this->domains[$domain])) {
                $this->domains[$domain] = [];
            }
            $this->domains[$domain][] = $user;
        }
    }
    public function attemptMultipleConnections(array $mxs)
    {
        foreach ($mxs as $host => $_weight) {
            try {
                $this->connect($host);
                if ($this->connected()) {
                    break;
                }
            } catch (NoConnectionException $e) {
                $this->debug('Unable to connect. Exception caught: ' . $e->getMessage());
            }
        }
    }
    public function setSender($email)
    {
        $parts = $this->splitEmail($email);
        $this->from_user = $parts[0];
        $this->from_domain = $parts[1];
    }
    public function setDomainResults(array $users, $domain, $value)
    {
        array_map(fn ($user) => $this->results["$user@$domain"] = $value, $users);
    }
    public function getResults($include_domain_info = true)
    {
        if ($include_domain_info) {
            $this->results['domains'] = $this->domains_info;
        } else {
            unset($this->results['domains']);
        }
        return $this->results;
    }
    public function accepts_any_recipient($domain)
    {
        if (!$this->catchall_test) return false;
        $test = 'catch-all-test-' . time();
        $accepted = $this->rcpt("$test@$domain");
        if ($accepted) {
            $this->domains_info[$domain]['catchall'] = true;
            return true;
        }

        $this->noop();
        if (!$this->connected()) $this->debug("Disconnected after trying a non-existing recipient on $domain");
        return false;
    }
    public function disconnect($quit_smtp = true)
    {
        if ($quit_smtp) $this->quit();
        if ($this->connected()) {
            $this->debug("Closing conenction to $this->host");
            fclose($this->socket);
            $this->host = null;
            $this->setSMTPStates([
                'helo' => false,
                'mail' => false,
                'rcpt' => false
            ]);
        }
    }
    public function mxQuery($domain)
    {
        $hosts  = [];
        $weight = [];
        getmxrr($domain, $hosts, $weight);
        return [$hosts, $weight];
    }
}
