<?php
/*
 * This file is part of mracine/php-routeros-api.
 *
 * (c) Matthieu Racine <matthieu.racine@gmail.com>
 * Issued from collaboration with https://github.com/EvilFreelancer/routeros-api-php
 * Best regards Paul
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace mracine\RouterOS\API;

use mracine\RouterOS\API\Exception\ClientException;
use mracine\RouterOS\API\Exception\ParserException;


/**
 * class Parser
 *
 * Parse SENTENCE received from RouterOS and re-arange result in an array
 * Validate results and throw exceptions if response is not well formated
 * Parser is implemented using "finite state machine" technic
 *
 * ex :
 * !re
 * =prop1=value1
 * =prop2=value2
 * !re
 * =prop1=value3
 * =prop2=value4
 * !done
 *
 * Will return :
 * [
 *   '!re' =>
 *       [
 *           0 => ['prop1'=>'value1', 'prop2'=>'value2'], 
 *           1 => ['prop1'=>'value3', 'prop2'=>'value4'],
 *       ],
 *   '!done' => 
 *       [],
 *  ] 
 *        ['prop1'=>'value3', 'prop2'=>'value4'] 
 * ['!done']
 *
 * ex (on login legacy):
 ! !done
 * =ret=hashvalue
 *
 * Will return :
 * [
 *   '!done' =>
 *      ['ret' => 'hashvalue'],
 * ]
 */
class Parser
{
    const PARSER_STARTING = 'PARSER_STARTING';
    const PARSER_RE       = '!re';
    const PARSER_TRAP     = '!trap';
    const PARSER_DONE     = '!done';
    const PARSER_FATAL    = '!fatal';
    const PARSER_FINAL    = 'PARSER_FINAL';

    /**
     * @var string $state the actual state of the parser. 
     */
    protected $state;

    /**
     * @var array $parsedResult The resulting array
     */
    protected $parsedResult;

    /**
     * @var string[] $buffer Used to store response content between state switches
     */
    protected $buffer;

    /**
     * Parse a RouterOS API SENTENCE sent by Router
     *
     * SENTENCE have to be decoded from RouterOS API Protocol. It is received in an array, each line must contains a WORD
     *
     * @param string[] $raw The array containing the SENTENCE to parse
     * @throws mracine\RouterOS\API\Exception\ParserException on parse Error
     * @return string[] An array representing the SENTENCE
     */
    public function parse(array $raw)
    {
        // Reset Parser
        $this->state = self::PARSER_STARTING;
        $this->parsedResult = [];
        $this->buffer = [];

        // read lines
        foreach($raw as $word) {
            switch($word) {
                // Command word, switch to new state
                case self::PARSER_RE:
                case self::PARSER_TRAP:
                case self::PARSER_DONE:
                case self::PARSER_FATAL:
                    // Entering new state
                    $this->newState($word);
                    break;
                // Attribute word in a "block", add it to the buffer
                default:
                    $this->feedBuffer($word);
                    break;
            }
        }

        // ALl WORDs have been read, finalization
        switch($this->state) {
            // Always starting ? We never received a single word...
            case self::PARSER_STARTING:
                throw new ParserException("Empty response from router");
                break;
            // Always un !re or !trap block ? We should have received a !done or !fatal
            case self::PARSER_RE:
            case self::PARSER_TRAP:
                throw new ParserException("End of data without !done or !fatal");
                break;
            // OK final block
            case self::PARSER_DONE:
            case self::PARSER_FATAL:
                $this->newState(self::PARSER_FINAL);
                break;
            // Whow, if this append, there is a bug in the parser 
            default:
                // This must not append !
                // @codeCoverageIgnoreStart
                throw new ParserException("Internal parser error");
                break;
                // @codeCoverageIgnoreEnd
        }
        return $this->parsedResult;
    }

    /**
     * Set Parser to a new state
     *
     * We received a RouterOS API Reply WORD (!re, !done....)
     * The buffer contains the entire block concerning this Reply WORD, so store it in a new array index 
     * 
     * @param string $newState The new state to switch to, and also the WORD received
     * @throws mracine\RouterOS\API\Exception\ParserException if received a non final state after !done or !fatal
     */
    protected function newState(string $newState)
    {
        switch($this->state) {
            // Always OK, this state is only to launch the machine
            case self::PARSER_STARTING:
                break;
            // OK, theses states allow to conniue with a new "block"
            case self::PARSER_RE:
            case self::PARSER_TRAP:
                    // Multiple !re or !trap block possibles, so have to store an new array for each 
                    $this->parsedResult[$this->state][] = $this->buffer;
                break;
            // Final blocks the only alowed new state is FINAL, wich is not a block, just a marker used in the algorithm
            case self::PARSER_DONE:
            case self::PARSER_FATAL:
                if (self::PARSER_FINAL == $newState) {
                    // only one block for !done or fatal, store the buffer
                    $this->parsedResult[$this->state] = $this->buffer;
                }
                else {
                    throw new ParserException(sprintf("%s and %s are final states, new state %s received", self::PARSER_DONE, self::PARSER_FATAL, $newState));
                }
                break;
        }
        // Switch to new state and clean the buffer for a new block
        $this->state = $newState;
        $this->buffer = [];
    }

    /**
     * Add a WORD to the block buffer
     *
     * Verify that the state and the format of the WORD are OK
     *
     * @param string $word The WORD received
     * @throws mracine\RouterOS\API\Exception\ParserException if the state deoes not allow to receive a WORD or the WORD is missformated (not an attribute format)
     */
    protected function feedBuffer(string $word)
    {
        switch($this->state)
        {
            // Fake state, we should have receive a Reply WORD (!re, !done....)
            case self::PARSER_STARTING:
                throw new ParserException("Not a valid response word received at start");
                break;
            // We are in a block hat receive attributes
            case self::PARSER_RE:
            case self::PARSER_DONE:
            case self::PARSER_TRAP:
                // format must be an attribute ('=name=value' or '=name=')
                if (substr($word, 0, 1) == '=' ) {
                    $t = explode('=', $word, 3);
                    if ($t[0] !== '' || $t[1] === '') {
                        throw new ParserException(sprintf("Invalid attribute %s", $word));
                    }
		            // =name=
		            if(2 == count($t)) {
                        $t[2] = "";
                    }
                    // .about attribute can appears multiple times
                    if ('.about' == $t[1]) {
                        $this->buffer['.about'][] = $t[2];
                    } else {
                        // Duplicates attributes must contain the same value
                        if (array_key_exists($t[1], $this->buffer) && ($this->buffer[$t[1]] != $t[2])) {
                                throw new ParserException(sprintf("Duplicate attribute key %s", $t[1]));
                        }
    
                        $this->buffer[$t[1]] = $t[2];                        
                    }
                }
                // Special case: .tag attribute
                elseif (substr($word, 0, 1) == '.') {
                    if (substr_compare($word, '.tag', 0, strlen('.tag')) === 0) {
                        throw new ParserException("Tagged responses are not (yet) supported");
                    }
                    throw new ParserException(sprintf("Unknow attribute format %s", $word));
                }
                else {
                    throw new ParserException(sprintf("Unknow attribute format %s", $word));
                }
                break;
            // !fatal reply does not use =name=value format
            case self::PARSER_FATAL:
                $this->buffer[] = $word;
                break;
        }
    }
}
