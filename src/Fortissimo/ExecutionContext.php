<?php
/**
 * @file
 *
 * The execution context.
 */
namespace Fortissimo;
/**
 * Tracks context information over the lifecycle of a request's execution.
 *
 * An execution context is passed from command to command during the course of
 * a request's execution. State information is inserted into the context by
 * various commands. Certain commands may also take data out of the context, though
 * this operation is not without its risks. Finally, objects may use information
 * found in the context, either to perform some operation (writing data to
 * the client) or to modify the context data.
 *
 * The idea of the context is to provide three things during the course of the
 * request(s):
 * - Shared access to data being generated.
 * - Common access to the logging system (see FortissimoLoggerManager).
 * - Common access to the datasources (See FortissimoDatasourceManager).
 * - Access to the underlying cache engine (so commands can cache their own data).
 *   See Fortissimo::CacheManager.
 * - Access to the request mapper. See FortissimoRequestMapper.
 *
 * Thus, every command can utilize the loggers and datasources defined for the
 * application, and commands can pass data throughout the lifecycle of the request.
 *
 * Note that when one request forwards to another request, the context may be
 * transferred along with it. Thus, sometimes a context will span multiple
 * defined requests (though this will always be in the handling of one
 * client serving operation -- i.e., it will only span one HTTP request, even if
 * multiple Fortissimo requests are fired.)
 *
 * @see Fortissimo
 */
class ExecutionContext implements IteratorAggregate {

  // Why do we create a class that is basically a thin wrapper around an array?
  // Three reasons:
  // 1. It gives us the ability to control access to the objects in the context.
  // 2. It gives us the ability to add validation and other features
  // 3. It eliminates the need to do overt pass-by-reference of a context array,
  //   which is likely to cause confusion with less experienced developers.
  // However, we do provide the to/from array methods to allow developers to make
  // use of the richer array library without our re-inventing the wheel.

  protected $data = NULL;
  protected $logger = NULL;
  protected $datasources = NULL;
  protected $cacheManager = NULL;
  protected $requestMapper = NULL;
  /** Command cache. */
  protected $cache = array();
  protected $caching = FALSE;

  /**
   * Create a new context.
   *
   * @param array $initialContext
   *  An associative array of context pairs.
   * @param FortissimoLoggerManager $logger
   *  The logger.
   * @param FortissimoDatasourceManager $datasources
   *  The manager for all datasources declared for this request.
   * @param FortissimoCacheManager $cacheManager
   *  The manager for all caches. Commands may use this to store or retrieve cached content.
   * @param FortissimoRequestMapper $requestMapper
   *  The request mapper used on this request. A request mapper should know how to construct
   *  a URL to the app.
   */
  public function __construct($initialContext = array(), \Fortissimo\LoggerManager $logger = NULL, \Fortissimo\DatasourceManager $datasources = NULL, \Fortissimo\CacheManager $cacheManager = NULL, \Fortissimo\RequestMapper $requestMapper = NULL) {
    if ($initialContext instanceof ExecutionContext) {
      $this->data = $initialContext->toArray();
    }
    else {
      $this->data = $initialContext;
    }

    // Store logger and datasources managers if they are set.
    if (isset($logger)) $this->logger = $logger;
    if (isset($datasources)) $this->datasources = $datasources;
    if (isset($cacheManager)) $this->cacheManager = $cacheManager;
    if (isset($requestMapper)) $this->requestMapper = $requestMapper;
  }

  /**
   * Log a message.
   * The context should always have a hook into a logger of some sort. This method
   * passes log messages to the underlying logger.
   *
   * @param mixed $msg
   *  The message to log. This can be a string or an Exception.
   * @param string $category
   *  A category. Typically, this is a string like 'error', 'warning', etc. But
   *  applications can customize their categories according to the underlying
   *  logger.
   * @see FortissimoLoggerManager Manages logging facilities.
   * @see FortissimoLogger Describes a logger.
   */
  public function log($msg, $category) {
    if (isset($this->logger)) {
      $this->logger->log($msg, $category);
    }
  }

  /**
   * Retrieve a named datasource.
   *
   * If no name is passed, this will try to retrieve the default datasource.
   *
   * @param string $name
   *  The name of the datasource to retrieve. If no name is given, the default
   *  datasource will be used.
   * @return FortissimoDatasource
   *  The requested datasource, or NULL if none is found.
   */
  public function datasource($name = NULL) {
    return $this->datasources->datasource($name);
  }

  /**
   * Convenience function for {@link datasource()}.
   */
  public function ds($name = NULL) {
    return $this->datasource($name);
  }

  /**
   * Check if the context has an item with the given name.
   *
   * @param string $name
   *  The name of the item to check for.
   */
  public function has($name) {
    return isset($this->data[$name]);
  }

  /**
   * Get the size of the context.
   *
   * @return int
   *  Number of items in the context.
   */
  public function size() {
    return count($this->data);
  }

  /**
   * Add a new name/value pair to the context.
   *
   * This will replace an existing entry with the same name. To check before
   * writing, use {@link has()}.
   *
   * @param string $name
   *  The name of the item to add.
   * @param mixed $value
   *  Some value to add. This can be a primitive, an object, or a resource. Note
   *  that storing resources is not serializable.
   */
  public function add($name, $value) {
    $this->data[$name] = $value;
  }
  //public function put($name, $value) {$this->add($name, $value);}

  /**
   * Add all values in the array.
   *
   * This will replace any existing entries with the same name.
   *
   * @param array $array
   *  Array of values to merge into the context.
   */
  public function addAll($array) {
    $this->data = $array + $this->data;
  }

  /**
   * Get a value by name.
   *
   * This fetches an item out of the context and returns a reference to it. A
   * reference is returned so that one can modify the value. But this introduces a risk: You
   * can accidentally modify the context value if you are not careful.
   *
   * If you are working with a non-object and you want to use it by reference, use the following
   * syntax:
   * @code
   * $foo =& $context->get('foo');
   * @endcode
   *
   * @return mixed
   *  A reference to the value in the context, or NULL if $name was not found.
   */
  public function &get($name) {
    $var = NULL;
    if (isset($this->data[$name])) {
      $var =  &$this->data[$name];
    }
    return $var;
  }

  /**
   * Remove an item from the context.
   *
   * @param string $name
   *  The thing to remove.
   */
  public function remove($name) {
    if (isset($this->data[$name])) unset($this->data[$name]);
  }

  /**
   * Convert the context to an array.
   *
   * @return array
   *  Associative array of name/value pairs.
   */
  public function toArray() {
    return $this->data;
  }

  /**
   * Replace the current context with the values in the given array.
   *
   * @param array $contextArray
   *  An array of new name/value pairs. The old context will be destroyed.
   */
  public function fromArray($contextArray) {
    $this->data = $contextArray;
  }

  /**
   * Get an iterator of the execution context.
   *
   * @return Iterator
   *  The iterator of each item in the execution context.
   */
  public function getIterator() {
    // Does this work?
    return new ArrayIterator($this->data);
  }

  /**
   * Expose the logger manager to commands.
   *
   * The logger manager is responsible for managing all of the underlying
   * loggers. This method provides access to the logger manager. For integrity
   * purposes, it is advised that loggers not be re-configured by commands.
   *
   * @return FortissimoLoggerManager
   *  The logger manager for the current server.
   */
  public function getLoggerManager() {
    return $this->logger;
  }

  /**
   * Get the datasource manager.
   *
   * The datasource manager is manages all of the datasources defined in
   * this Fortissimo instance (typically defined in commands.xml).
   *
   * Often, you will want to get datasources with the {@link datasource()} function
   * defined in this class. Sometimes, though, you may need more control over
   * the datasource. This method provides direct access to the manager, which
   * will give you a higher degree of control.
   *
   * @return FortissimoDatasourceManager
   *  An initialized datasource manager.
   */
  public function getDatasourceManager() {
    //throw new Exception('Not implemented.');
    return $this->datasourceManager;
  }

  /**
   * Get the FortissimoCacheManager for this request.
   *
   * Fortissimo provides facilities for providing any number of caches. All of the caches are
   * managed by a FortissimoCacheManager instance. This returns a handle to the manager, from
   * which tools can operate on caches.
   *
   * @return FortissimoCacheManager
   *  The current cache manager.
   */
  public function getCacheManager() {
    return $this->cacheManager;
  }

  /**
   * Get the FortissimoRequestMapper for this request.
   *
   * The Request Mapper maps requests to URLs and URLs to requests. It can be used
   * for constructing URLs to other parts of the app.
   */
  public function getRequestMapper() {
    return $this->requestMapper;
  }
}
