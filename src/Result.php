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

/**
 * class Result
 * 
 * Parsed Result of a request
 *
 * @since   0.1.0
 */

class Result
{
    protected $result;

    public function __construct(array $raw)
    {
        $parser = new Parser();
        $this->result = $parser->parse($raw);
    }

    public function hasTrap()
    {
        return array_key_exists(Parser::PARSER_TRAP, $this->result);
    }

    public function getTrap()
    {
        if (!$this->hasTrap()) {
            throw new ClientException('Result does not contain trap response');
        }
        return $this->result[Parser::PARSER_TRAP];
    }

    public function trap(string $key, int $idx=0)
    {
        $trap =  $this->getTrap();
        return $trap[$idx][$key];
    }

    public function hasDone()
    {
        return array_key_exists(Parser::PARSER_DONE, $this->result);
    }

    public function getDone()
    {
        if (!$this->hasDone()) {
            throw new ClientException('Result does not contain done response');
        }

        return $this->result[Parser::PARSER_DONE];
    }

    public function countDone()
    {
        return count($this->getDone());
    }

    public function done(string $key)
    {
        $done = $this->getDone();
        if (!array_key_exists($key, $done)) {
            throw new ClientException(sprintf('The key %s does not exists in done result', $key));
        }
        return $done[$key];
    }

    public function hasFatal()
    {
        return array_key_exists(Parser::PARSER_FATAL, $this->result);
    }

    public function getFatal()
    {
        if (!$this->hasFatal()) {
            throw new ClientException('Result does not contain fatal response');
        }
        return $this->result[Parser::PARSER_FATAL];
    }

    public function fatal()
    {
        return implode(' ', $this->getFatal());
    }
}
