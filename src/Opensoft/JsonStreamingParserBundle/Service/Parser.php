<?php
namespace Opensoft\JsonStreamingParserBundle\Service;

use Opensoft\JsonStreamingParserBundle\Exception\ParsingException;
use Opensoft\JsonStreamingParserBundle\Listener\ListenerInterface;
use Psr\Http\Message\StreamInterface;

class Parser
{
    const STATE_START_DOCUMENT = 0;
    const STATE_DONE = -1;
    const STATE_IN_ARRAY = 1;
    const STATE_IN_OBJECT = 2;
    const STATE_END_KEY = 3;
    const STATE_AFTER_KEY = 4;
    const STATE_IN_STRING = 5;
    const STATE_START_ESCAPE = 6;
    const STATE_UNICODE = 7;
    const STATE_IN_NUMBER = 8;
    const STATE_IN_TRUE = 9;
    const STATE_IN_FALSE = 10;
    const STATE_IN_NULL = 11;
    const STATE_AFTER_VALUE = 12;
    const STATE_UNICODE_SURROGATE = 13;

    const STACK_OBJECT = 0;
    const STACK_ARRAY = 1;
    const STACK_KEY = 2;
    const STACK_STRING = 3;

    const BUFFER_SIZE = 8192;

    private $state = self::STATE_START_DOCUMENT;
    private $stack = array();

    /**
     * @var StreamInterface
     */
    private $stream;
    /**
     * @var ListenerInterface
     */
    private $listener;

    private $buffer = '';
    private $unicodeBuffer = array();
    private $unicodeHighSurrogate = -1;
    private $unicodeEscapeBuffer = '';

    private $lineNumber;
    private $charNumber;

    /**
     * Launches parsing of given stream
     *
     * @param StreamInterface $stream
     * @param ListenerInterface $listener
     */
    public function parse(StreamInterface $stream, ListenerInterface $listener)
    {
        $this->stream = $stream;
        $this->listener = $listener;

        $this->lineNumber = 1;
        $this->charNumber = 1;

        $eof = false;

        while (!$this->stream->eof() && !$eof) {
            $pos = $this->stream->tell();
            $line = $this->stream->read(self::BUFFER_SIZE);
            $ended = (bool)($this->stream->tell() - strlen($line) - $pos);
            // if we're still at the same place after stream_get_line, we're done
            $eof = $this->stream->tell() == $pos;

            $byteLen = strlen($line);
            for ($i = 0; $i < $byteLen; $i++) {
                $this->consumeChar($line[$i]);
                $this->charNumber++;
            }

            if ($ended) {
                $this->lineNumber++;
                $this->charNumber = 1;
            }
        }
    }

    /**
     * @param string $char
     * @throws ParsingException
     */
    private function consumeChar($char)
    {
        // valid whitespace characters in JSON (from RFC4627 for JSON) include:
        // space, horizontal tab, line feed or new line, and carriage return.
        // thanks: http://stackoverflow.com/questions/16042274/definition-of-whitespace-in-json
        if (($char === " " || $char === "\t" || $char === "\n" || $char === "\r") &&
            !($this->state === self::STATE_IN_STRING ||
                $this->state === self::STATE_UNICODE ||
                $this->state === self::STATE_START_ESCAPE ||
                $this->state === self::STATE_IN_NUMBER ||
                $this->state === self::STATE_START_DOCUMENT)
        ) {
            return;
        }

        switch ($this->state) {
            case self::STATE_IN_STRING:
                if ($char === '"') {
                    $this->endString();
                } elseif ($char === '\\') {
                    $this->state = self::STATE_START_ESCAPE;
                } elseif (($char < "\x1f") || ($char === "\x7f")) {
                    $this->throwException("Unescaped control character encountered: " . $char);
                } else {
                    $this->buffer .= $char;
                }
                break;

            case self::STATE_IN_ARRAY:
                if ($char === ']') {
                    $this->endArray();
                } else {
                    $this->startValue($char);
                }
                break;

            case self::STATE_IN_OBJECT:
                if ($char === '}') {
                    $this->endObject();
                } elseif ($char === '"') {
                    $this->startKey();
                } else {
                    $this->throwException("Start of string expected for object key. Instead got: " . $char);
                }
                break;

            case self::STATE_END_KEY:
                if ($char !== ':') {
                    $this->throwException("Expected ':' after key.");
                }
                $this->state = self::STATE_AFTER_KEY;
                break;

            case self::STATE_AFTER_KEY:
                $this->startValue($char);
                break;

            case self::STATE_START_ESCAPE:
                $this->processEscapeCharacter($char);
                break;

            case self::STATE_UNICODE:
                $this->processUnicodeCharacter($char);
                break;

            case self::STATE_UNICODE_SURROGATE:
                $this->unicodeEscapeBuffer .= $char;
                if (mb_strlen($this->unicodeEscapeBuffer) == 2) {
                    $this->endUnicodeSurrogateInterstitial();
                }
                break;

            case self::STATE_AFTER_VALUE:
                $within = end($this->stack);
                if ($within === self::STACK_OBJECT) {
                    if ($char === '}') {
                        $this->endObject();
                    } elseif ($char === ',') {
                        $this->state = self::STATE_IN_OBJECT;
                    } else {
                        $this->throwException("Expected ',' or '}' while parsing object. Got: " . $char);
                    }
                } elseif ($within === self::STACK_ARRAY) {
                    if ($char === ']') {
                        $this->endArray();
                    } elseif ($char === ',') {
                        $this->state = self::STATE_IN_ARRAY;
                    } else {
                        $this->throwException("Expected ',' or ']' while parsing array. Got: " . $char);
                    }
                } else {
                    $this->throwException("Finished a literal, but unclear what state to move to. Last state: " . $within);
                }
                break;

            case self::STATE_IN_NUMBER:
                if (ctype_digit($char)) {
                    $this->buffer .= $char;
                } elseif ($char === '.') {
                    if (strpos($this->buffer, '.') !== false) {
                        $this->throwException("Cannot have multiple decimal points in a number.");
                    } elseif (stripos($this->buffer, 'e') !== false) {
                        $this->throwException("Cannot have a decimal point in an exponent.");
                    }
                    $this->buffer .= $char;
                } elseif ($char === 'e' || $char === 'E') {
                    if (stripos($this->buffer, 'e') !== false) {
                        $this->throwException("Cannot have multiple exponents in a number.");
                    }
                    $this->buffer .= $char;
                } elseif ($char === '+' || $char === '-') {
                    $last = mb_substr($this->buffer, -1);
                    if (!($last === 'e' || $last === 'E')) {
                        $this->throwException("Can only have '+' or '-' after the 'e' or 'E' in a number.");
                    }
                    $this->buffer .= $char;
                } else {
                    $this->endNumber();
                    // we have consumed one beyond the end of the number
                    $this->consumeChar($char);
                }
                break;

            case self::STATE_IN_TRUE:
                $this->buffer .= $char;
                if (mb_strlen($this->buffer) === 4) {
                    $this->endTrue();
                }
                break;

            case self::STATE_IN_FALSE:
                $this->buffer .= $char;
                if (mb_strlen($this->buffer) === 5) {
                    $this->endFalse();
                }
                break;

            case self::STATE_IN_NULL:
                $this->buffer .= $char;
                if (mb_strlen($this->buffer) === 4) {
                    $this->endNull();
                }
                break;

            case self::STATE_START_DOCUMENT:
                $this->listener->startDocument();
                if ($char === '[') {
                    $this->startArray();
                } elseif ($char === '{') {
                    $this->startObject();
                } else {
                    $this->throwException("Document must start with object or array.");
                }
                break;

            case self::STATE_DONE:
                $this->throwException("Expected end of document.");
                break;

            default:
                $this->throwException("Internal error. Reached an unknown state: " . $this->state);
        }
    }

    /**
     * @throws ParsingException
     */
    private function endString()
    {
        $popped = array_pop($this->stack);
        if ($popped === self::STACK_KEY) {
            $this->listener->key($this->buffer);
            $this->state = self::STATE_END_KEY;
        } elseif ($popped === self::STACK_STRING) {
            $this->listener->value($this->buffer);
            $this->state = self::STATE_AFTER_VALUE;
        } else {
            $this->throwException("Unexpected end of string.");
        }
        $this->buffer = '';
    }

    /**
     * Thanks: http://stackoverflow.com/questions/1805802/php-convert-unicode-codepoint-to-utf-8
     * @throws ParsingException
     */
    private function endArray()
    {
        $popped = array_pop($this->stack);
        if ($popped !== self::STACK_ARRAY) {
            $this->throwException("Unexpected end of array encountered.");
        }
        $this->listener->endArray();
        $this->state = self::STATE_AFTER_VALUE;

        if (empty($this->stack)) {
            $this->endDocument();
        }
    }

    /**
     * Called in the end of the document
     */
    private function endDocument()
    {
        $this->listener->endDocument();
        $this->state = self::STATE_DONE;
    }

    /**
     * @param $char
     * @throws ParsingException
     */
    private function startValue($char)
    {
        if ($char === '[') {
            $this->startArray();
        } elseif ($char === '{') {
            $this->startObject();
        } elseif ($char === '"') {
            $this->startString();
        } elseif ($this->isDigit($char)) {
            $this->startNumber($char);
        } elseif ($char === 't') {
            $this->state = self::STATE_IN_TRUE;
            $this->buffer .= $char;
        } elseif ($char === 'f') {
            $this->state = self::STATE_IN_FALSE;
            $this->buffer .= $char;
        } elseif ($char === 'n') {
            $this->state = self::STATE_IN_NULL;
            $this->buffer .= $char;
        } else {
            $this->throwException("Unexpected character for value: " . $char);
        }
    }

    private function startArray()
    {
        $this->listener->startArray();
        $this->state = self::STATE_IN_ARRAY;
        $this->stack[] = self::STACK_ARRAY;
    }

    private function startObject()
    {
        $this->listener->startObject();
        $this->state = self::STATE_IN_OBJECT;
        $this->stack[] = self::STACK_OBJECT;
    }

    private function startString()
    {
        $this->stack[] = self::STACK_STRING;
        $this->state = self::STATE_IN_STRING;
    }

    private function isDigit($c)
    {
        // Only concerned with the first character in a number.
        return ctype_digit($c) || $c === '-';
    }

    private function startNumber($c)
    {
        $this->state = self::STATE_IN_NUMBER;
        $this->buffer .= $c;
    }

    private function endObject()
    {
        $popped = array_pop($this->stack);
        if ($popped !== self::STACK_OBJECT) {
            $this->throwException("Unexpected end of object encountered.");
        }
        $this->listener->endObject();
        $this->state = self::STATE_AFTER_VALUE;

        if (empty($this->stack)) {
            $this->endDocument();
        }
    }

    private function startKey()
    {
        $this->stack[] = self::STACK_KEY;
        $this->state = self::STATE_IN_STRING;
    }

    /**
     * @param $char
     * @throws ParsingException
     */
    private function processEscapeCharacter($char)
    {
        if ($char === '"') {
            $this->buffer .= '"';
        } elseif ($char === '\\') {
            $this->buffer .= '\\';
        } elseif ($char === '/') {
            $this->buffer .= '/';
        } elseif ($char === 'b') {
            $this->buffer .= "\x08";
        } elseif ($char === 'f') {
            $this->buffer .= "\f";
        } elseif ($char === 'n') {
            $this->buffer .= "\n";
        } elseif ($char === 'r') {
            $this->buffer .= "\r";
        } elseif ($char === 't') {
            $this->buffer .= "\t";
        } elseif ($char === 'u') {
            $this->state = self::STATE_UNICODE;
        } else {
            $this->throwException("Expected escaped character after backslash. Got: " . $char);
        }

        if ($this->state !== self::STATE_UNICODE) {
            $this->state = self::STATE_IN_STRING;
        }
    }

    /**
     * @param $char
     * @throws ParsingException
     */
    private function processUnicodeCharacter($char)
    {
        if (!ctype_xdigit($char)) {
            $this->throwException("Expected hex character for escaped Unicode character. Unicode parsed: " . implode($this->unicodeBuffer) . " and got: " . $char);
        }
        $this->unicodeBuffer[] = $char;
        if (count($this->unicodeBuffer) === 4) {
            $codepoint = hexdec(implode($this->unicodeBuffer));

            if ($codepoint >= 0xD800 && $codepoint < 0xDC00) {
                $this->unicodeHighSurrogate = $codepoint;
                $this->unicodeBuffer = array();
                $this->state = self::STATE_UNICODE_SURROGATE;
            } elseif ($codepoint >= 0xDC00 && $codepoint <= 0xDFFF) {
                if ($this->unicodeHighSurrogate === -1) {
                    $this->throwException("Missing high surrogate for Unicode low surrogate.");
                }
                $combinedCodepoint = (($this->unicodeHighSurrogate - 0xD800) * 0x400) + ($codepoint - 0xDC00) + 0x10000;

                $this->endUnicodeCharacter($combinedCodepoint);
            } else if ($this->unicodeHighSurrogate != -1) {
                $this->throwException("Invalid low surrogate following Unicode high surrogate.");
            } else {
                $this->endUnicodeCharacter($codepoint);
            }
        }
    }

    private function endUnicodeCharacter($codepoint)
    {
        $this->buffer .= $this->convertCodepointToCharacter($codepoint);
        $this->unicodeBuffer = array();
        $this->unicodeHighSurrogate = -1;
        $this->state = self::STATE_IN_STRING;
    }

    private function convertCodepointToCharacter($num)
    {
        if ($num <= 0x7F) return chr($num);
        if ($num <= 0x7FF) return chr(($num >> 6) + 192) . chr(($num & 63) + 128);
        if ($num <= 0xFFFF) return chr(($num >> 12) + 224) . chr((($num >> 6) & 63) + 128) . chr(($num & 63) + 128);
        if ($num <= 0x1FFFFF) return chr(($num >> 18) + 240) . chr((($num >> 12) & 63) + 128) . chr((($num >> 6) & 63) + 128) . chr(($num & 63) + 128);
        return '';
    }

    private function endUnicodeSurrogateInterstitial()
    {
        $unicodeEscape = $this->unicodeEscapeBuffer;
        if ($unicodeEscape != '\\u') {
            $this->throwException("Expected '\\u' following a Unicode high surrogate. Got: " . $unicodeEscape);
        }
        $this->unicodeEscapeBuffer = '';
        $this->state = self::STATE_UNICODE;
    }

    private function endNumber()
    {
        $num = $this->buffer;

        // thanks to #andig for the fix for big integers
        if (ctype_digit($num) && ((float)$num === (float)((int)$num))) {
            // natural number PHP_INT_MIN < $num < PHP_INT_MAX
            $num = (int)$num;
        } else {
            // real number or natural number outside PHP_INT_MIN ... PHP_INT_MAX
            $num = (float)$num;
        }

        $this->listener->value($num);

        $this->buffer = '';
        $this->state = self::STATE_AFTER_VALUE;
    }

    private function endTrue()
    {
        $true = $this->buffer;
        if ($true === 'true') {
            $this->listener->value(true);
        } else {
            $this->throwException("Expected 'true'. Got: " . $true);
        }
        $this->buffer = '';
        $this->state = self::STATE_AFTER_VALUE;
    }

    private function endFalse()
    {
        $false = $this->buffer;
        if ($false === 'false') {
            $this->listener->value(false);
        } else {
            $this->throwException("Expected 'false'. Got: " . $false);
        }
        $this->buffer = '';
        $this->state = self::STATE_AFTER_VALUE;
    }

    private function endNull()
    {
        $null = $this->buffer;
        if ($null === 'null') {
            $this->listener->value(null);
        } else {
            $this->throwException("Expected 'null'. Got: " . $null);
        }
        $this->buffer = '';
        $this->state = self::STATE_AFTER_VALUE;
    }

    /**
     * @param string $message
     * @throws ParsingException
     */
    private function throwException($message)
    {
        throw new ParsingException(
            $this->lineNumber,
            $this->charNumber,
            $message
        );
    }
}
