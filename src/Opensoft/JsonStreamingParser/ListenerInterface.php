<?php
namespace Opensoft\JsonStreamingParser;

interface ListenerInterface
{
  /**
   * @return mixed
   */
  public function startDocument();

  /**
   * @return mixed
   */
  public function endDocument();

  /**
   * @return mixed
   */
  public function startObject();

  /**
   * @return mixed
   */
  public function endObject();

  /**
   * @return mixed
   */
  public function startArray();

  /**
   * @return mixed
   */
  public function endArray();

  /**
   * Key will always be a string
   * @param $key
   * @return mixed
   */
  public function key($key);

  /**
   * Note that value may be a string, integer, boolean, etc.
   * @param $value
   * @return mixed
   */
  public function value($value);

  /**
   * @param $whitespace
   * @return mixed
   */
  public function whitespace($whitespace);
}
