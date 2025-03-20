<?php
namespace mracine\RouterOS\API\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Constraint\IsType;

use mracine\RouterOS\API\Parser;
use mracine\RouterOS\API\Exception\ParserException;

/**
 * @coversDefaultClass mracine\RouterOS\API\Parser
 */
class ParserTest extends TestCase
{
    public function test__construct()
    {
        $this->assertInstanceOf(Parser::class, new Parser());
    }

    /**
     * @covers ::parse
     * @covers ::<protected>
     */

    public function test_Empty()
    {
	$this->expectException(ParserException::class);
        $data = [];
        (new Parser())->Parse($data);
    }

    /**
     * @covers ::parse
     * @covers ::<protected>
     */

    public function test_firstNotAReply()
    {
	$this->expectException(ParserException::class);
        $data = [ 'Hello' ];
        (new Parser())->Parse($data);
    }

    /**
     * @covers ::parse
     * @covers ::<protected>
     */
    public function test_NotFinalRe()
    {
	$this->expectException(ParserException::class);
        $data = [
            '!re',
        ];
        (new Parser())->Parse($data);
    }

    /**
     * @covers ::parse
     * @covers ::<protected>
     */
    public function test_NotFinalTrap()
    {
	$this->expectException(ParserException::class);
        $data = [
            '!trap',
        ];
        (new Parser())->Parse($data);
    }

    /**
     * @covers ::parse
     * @covers ::<protected>
     */
    public function test_VerifyFinal()
    {
	$this->expectException(ParserException::class);
        $data = [
            '!fatal',
            '!done'
        ];
        (new Parser())->Parse($data);
    }


    /**
     * @covers ::parse
     * @covers ::<protected>
     * @dataProvider BlocksOrderOKProvider
     */
    public function test_BlocksOrderOK(array $dialog)
    {
        (new Parser())->Parse($dialog);
        $this->assertTrue(true);
    }

    public function BlocksOrderOKProvider()
    {
        return [
                [['!done']],
                [['!re', '!done']],
                [['!re', '!fatal']],
                [['!re', '!re', '!done']],
                [['!trap', '!done']],
                [['!re',   '!trap', '!done']],
                [['!fatal']],
                [['!re', '!fatal']],
                [['!re', '!re', '!fatal']],
                [['!trap', '!fatal']],
                [['!re',   '!trap', '!fatal']],
        ];
    }

    /**
     * @covers ::parse
     * @covers ::<protected>
     * @dataProvider BlocksOrderKOProvider
     */
    public function test_BlocksOrderKO(array $dialog)
    {
	$this->expectException(ParserException::class);
        (new Parser())->Parse($dialog);
    }

    public function BlocksOrderKOProvider()
    {
        return [
                [['!done', '!re']],
                [['!done', '!done']],
                [['!done', '!trap']],
                [['!done', '!fatal']],
                [['!fatal', '!re']],
                [['!fatal', '!done']],
                [['!fatal', '!trap']],
                [['!fatal', '!fatal']],
            ];
    }

    /**
     * @covers ::parse
     * @covers ::<protected>
     * @dataProvider OKAttributesProvider
     */
    public function test_OKAttributes(array $dialog)
    {
        $r = (new Parser())->Parse($dialog);
        // To be analysed !!!!
        $this->assertTrue(true);
    }

    public function OKAttributesProvider()
    {
        return [
                [['!done', '=key=value']],
                [['!done', '=key=']],
                [['!done', '=key']],
                [['!re', '=key=1', '!done', '=key']],
                [['!re', '=key=1', '!done', '=key=']],
                [['!re', '=key=1', '!done', '=key=value']],
                [['!re', '=key=1', '!done', '=key=value', '=key2=value']],
                [['!re', '=key=1', '=key2=2', '!done', '=key']],
                [['!re', '=key=1', '=key=1', '!done', '=key']], // Duplicate key with same value => OK
                [['!re', '=key', '=key', '!done', '=key']], // Duplicate key with same value => OK
                [['!re', '=.about=v1', '=.about=v2', '!done', '=key' ]], // Duplicate key .about with differents value => OK
                [['!fatal', 'WTF ?']],
        ];
    }

    /**
     * @covers ::parse
     * @covers ::<protected>
     * @dataProvider KOAttributesProvider
     */
    public function test_KOAttributes(array $dialog)
    {
	$this->expectException(ParserException::class);
        $r = (new Parser())->Parse($dialog);
    }

    public function KOAttributesProvider()
    {
        return [
                [['!done', 'WTF ?']],
                [['!done', 'toto=']],
                [['!done', '=']],
                [['!re', '=key=1', '!done', 'WTF ?']],
                [['!re', '=key=1', '!done', '.tag']],
                [['!re', '=key=1', '!done', '.pouet']],
                [['!re', 'WTF ?', '=key2=2', '!done', '=key']],
                [['!re', '=key=1', 'WTF ?', '!done', '=key']],
                [['!re', '=key=1', '=key=2', '!done', '=key']], // Duplicate key with different values => Error
            ];
    }
}
