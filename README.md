PSR7 Streaming JSON Parser
=========================

This is a PSR-7 streaming parser for processing large JSON documents. 
Based on cool [php-streaming-json-parser](https://github.com/salsify/jsonstreamingparser). 
Original parser was improved to use PSR-7 StreamInterface. Also a lot of changes in naming and style. Feel free to address the original documentation because lots of core things are the same.


Usage
-----

To use the `JsonStreamingParser` you just have to implement the `\JsonStreamingParser\ListenerInterface`. You then pass your `Listener` into the parser. For example:

```php
$listener = new YourListener();
//$someStream is an object of class which implements PSR-7 StreamInterface. Example in src/Opensoft/Tests/Data/Stream.php
$parser = new \JsonStreamingParser\Parser($someStream, $listener); 
$parser->parse();

```

Your `Listener` will receive events from the streaming parser as it works.

You can see an example of correct Stream in src/Opensoft/Tests/Data/Stream.php. It was taken from zendframework/zend-diactoros.


License
-------

[MIT License](http://mit-license.org/) (c) Opensoft.
