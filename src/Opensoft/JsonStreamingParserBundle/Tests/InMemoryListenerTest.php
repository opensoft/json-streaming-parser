<?php
namespace Opensoft\JsonStreamingParserBundle\Tests;

use Opensoft\JsonStreamingParserBundle\Listener\InMemoryListener;
use Opensoft\JsonStreamingParserBundle\Service\Parser;
use Opensoft\JsonStreamingParserBundle\Tests\Data\Stream;

class InMemoryListenerTest extends \PHPUnit_Framework_TestCase
{
    public function testGeoJson()
    {
        $testJson = dirname(__FILE__) . '/Data/geo.json';
        $this->assertParsesCorrectly($testJson);
    }

    public function testComplexJson()
    {
        $testJson = dirname(__FILE__) . '/Data/complex.json';
        $this->assertParsesCorrectly($testJson);
    }

    private function assertParsesCorrectly($testJson)
    {
        $listener = new InMemoryListener();
        $stream = new Stream(fopen($testJson, 'r'));

        try {
            $parser = new Parser();
            $parser->parse($stream, $listener);
            $stream->close();
        } catch (\Exception $e) {
            $stream->close();
            throw $e;
        }

        $actual = $listener->getJson();
        $expected = json_decode(file_get_contents($testJson), true);
        $this->assertSame($expected, $actual);
    }
}
