<?php
namespace Opensoft\JsonStreamingParser\Tests;

use Opensoft\JsonStreamingParser\InMemoryListener;
use Opensoft\JsonStreamingParser\Parser;

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
            $parser = new Parser($stream, $listener);
            $parser->parse();
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
