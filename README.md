PSR7 Streaming JSON Parser
=========================

This is a PSR-7 streaming parser for processing large JSON documents. 
Based on cool [php-streaming-json-parser](https://github.com/salsify/jsonstreamingparser). 
Original parser was improved to use PSR-7 StreamInterface and now it is a bundle for Symfony2. 
Also a lot of changes in naming and style. Feel free to address the original documentation because lots of core things are the same.


Usage
-----

To use the `JsonStreamingParser` you just have to implement the `Opensoft\JsonStreamingParserBundle\Listener\ListenerInterface`. 
Then you pass your listener into the parser. For example:

```php
$listener = new YourListener();
//$someStream is an object of class which implements PSR-7 StreamInterface 
//example in src/Opensoft/Tests/Data/Stream.php
$parser = new \JsonStreamingParserBundle\Parser($someStream, $listener); 
$parser->parse();

```

Your listener will receive events from the streaming parser as it works.

License
-------

[MIT License](http://mit-license.org/) (c) Opensoft.
