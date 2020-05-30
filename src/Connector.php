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

use mracine\RouterOS\API\Parser;
use mracine\RouterOS\API\Exception\ClientException;
use mracine\RouterOS\API\Exception\ProtocolException;
use mracine\RouterOS\API\Exception\LoginFailedException;

use mracine\Streams\Stream;
/**
 * class Connector
 * 
 * Implement middle level dialog with RouterOS API
 *
 * @since   0.1.0
 */

class Connector
{
    const STATE_DISCONNECTED  = 0;
    const STATE_WAITING_WRITE = 1;
    const STATE_WRITING       = 2;
    const STATE_WAITING_READ  = 3;
    const STATE_READING       = 4;
    /**
     * @var StreamInterface $stream The socket stream used to communicate with the router
     */
    protected $stream;

    protected $state;

    /**
     * Constructor
     *
     * @param Stream $stream
     */

    public function __construct(Stream $stream)
    {
        $this->stream = $stream;
        $this->setState(self::STATE_WAITING_WRITE);
    }

    public function isConnected()
    {
        return !is_null($this->stream);
    }

    public function getState()
    {
        return $this->state;
    }

    protected function setState(int $state)
    {
        $this->state = $state;
    } 

    /**
     * Writes a WORD to the stream
     *
     * @param string $word 
     * @param bool $finalize if true writes an empty WORD after the $word to finalize the SENTENCE
     * @return int number of bytes written (expect finalize byte)  
     */ 
    public function writeWord(string $word, bool $finalize = false)
    {
        if (!in_array($this->getState(), [self::STATE_WAITING_WRITE, self::STATE_WRITING])) {
            if(self::STATE_DISCONNECTED==$this->getState()) {
                throw new ClientException("Cannot write, stream disconnected");
            }
            throw new ClientException("Cannot write to stream, have to read all data first");
        }

        if(is_null($this->stream)) {
            throw new ClientException("Cannot write to closed stream");
        }

        $encodedLength = WordLengthCoDec::encodeLength(strlen($word));
        $this->setState(self::STATE_WRITING);
        $length = $this->stream->write($encodedLength.$word);
        if ($finalize) {
            $this->writeEnd();
        }
        if ('' == $word) {
            $this->setState(self::STATE_WAITING_READ);
        }
        return $length;
    }

    /**
     * Writes an empty WORD to the stream, terminate a SENTENCE
     */ 
    public function writeEnd()
    {
        $this->writeWord('');
    }

    /**
     * Reads a WORD from the stream
     *
     * @return string The WORD content, an empty string signal end of SENTENCE
     */ 
    protected function readWord() : string
    {
        if(is_null($this->stream)) {
            throw new ClientException("Cannot read from closed stream");
        }
        // Get length of next word
        $length = WordLengthCoDec::decodeLength($this->stream);
        if (0 == $length) {
            return '';
        }
        $w = $this->stream->read($length);
        return $w;
    }

    /**
     * Reads a SENTENCE from the stream
     *
     * @param bool $parseReply if true the SENTENCE is parsed in a Result Object
     * @return array|Result
     */
    public function getSentence(bool $parseReply=true)
    {
        if (!in_array($this->getState(), [self::STATE_WAITING_READ, self::STATE_READING])) {
            if(self::STATE_DISCONNECTED==$this->getState()) {
                throw new ClientException("Cannot read, stream disconnected");
            }
            throw new ClientException("Cannot read from stream, have to finalize command first");
        }
        $this->setState(self::STATE_READING);
        $reply = [];
        $lastBlock = false;

        // read WORDs from stream in loop
        // stop if we read an end of SENTENCE and in a final "block" (DONE or FATAL)
	$word = $this->readWord();
        // Bug with certain versions of API ?
	// It seems that some versions returns empty words in loop
	// after login is sent (old versions).
	$emptyCount = 0;
        while((!$lastBlock) || ('' !== $word)) {
            if (in_array($word, [Parser::PARSER_DONE, Parser::PARSER_FATAL])) {
                $lastBlock = true;
            }
            if ($word !== '') {
                $reply[] = $word;
            }

	    $newWord = $this->readWord();
	    // Bug contournement
	    // After 10 empty lines (arbitrary value) stop communication, there is a problem...
            if (''==$word && ''==$newWord) {
                $emptyCount++;
                if ($emptyCount>=10) {
                    throw new ProtocolException('Too many consecutive empty words in sentence');
                }
            } else {
                $emptyCount = 0;
            }
            $word = $newWord;
        }
        $this->setState(self::STATE_WAITING_WRITE);

        $result = null;
        if($parseReply) {
            $result = new Result($reply);
            // On !fatal response, RouterOS closes connection, so we close the stream
            if ($result->hasFatal()) {
                $this->close();
            }
        }
        else {
            $result = $reply;
            // On !fatal response, RouterOS closes connection, so we close the stream
            if (in_array(Parser::PARSER_FATAL, $result)) {
                $this->close();
            }
        }
        return $result;
    }

    public function legacyLogin(string $login, string $password)
    {
        $this->writeWord('/login', true);
        $r = $this->getSentence();
        
        $challenge = $r->done('ret');
        // Encrypt password with chalenge
        $secret = '00' . md5(chr(0) . $password . pack('H*', $challenge));

        $this->writeWord('/login');
        $this->writeWord('=name=' . $login);
        $this->writeWord('=response='.$secret, true);
        $r = $this->getSentence();

        if ($r->hasTrap()) {
            throw new LoginFailedException($r->trap('message'));
        }

        if ($r->countDone() == 0) {
            // Login OK
            return;                
        }

        throw new ProtocolException("Error Processing Request");
    }

    public function nonLegacyLogin(string $login, string $password, bool $fallbackLegacyLogin=true)
    {
        $this->writeWord('/login');
        $this->writeWord('=name='.$login);
        $this->writeWord('=password='.$password, true);
        $r = $this->getSentence();

        if ($r->hasTrap()) {
            throw new LoginFailedException($r->trap('message'));
        }

        if ($r->countDone() == 0) {
            // Login OK
            return;                
        }

        if ($fallbackLegacyLogin) {
            // Even non legacy routers have implements legacy login, so try it
            $challenge = $r->done('ret');
            $this->legacyLogin($login, $password);
            return;
        }
        throw new ClientException(sprintf('Legacy RouterOS version does not accept non legacy login method'));
    }

    public function quit()
    {
        $this->writeWord('/quit', true);
        return $this->getSentence()->fatal();
    }

    protected function close()
    {
        if(!is_null($this->stream)) {
            $this->stream->close();
            $this->stream = null;
        }
        $this->setState(self::STATE_DISCONNECTED);
    }
}
