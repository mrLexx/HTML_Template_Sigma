<?php
/**
 * Implementation of Integrated Templates API with template 'compilation' added.
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This source file is subject to version 3.01 of the PHP license
 * that is available through the world-wide-web at the following URI:
 * http://www.php.net/license/3_01.txt If you did not receive a copy of
 * the PHP License and are unable to obtain it through the web, please
 * send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category  HTML
 * @package   HTML_Template_Sigma
 * @author    Ulf Wendel <ulf.wendel@phpdoc.de>
 * @author    Alexey Borzov <avb@php.net>
 * @copyright 2001-2007 The PHP Group
 * @license   http://www.php.net/license/3_01.txt PHP License 3.01
 * @link      http://pear.php.net/package/HTML_Template_Sigma
 */

use JustCommunication\Cache;

/**
 * PEAR and PEAR_Error classes (for error handling)
 */

/**#@+
 * Error codes
 * @see HTML_Template_Sigma::errorMessage()
 */
define('SIGMA_OK', 1);
define('SIGMA_ERROR', -1);
define('SIGMA_TPL_NOT_FOUND', -2);
define('SIGMA_BLOCK_NOT_FOUND', -3);
define('SIGMA_BLOCK_DUPLICATE', -4);
define('SIGMA_CACHE_ERROR', -5);
define('SIGMA_UNKNOWN_OPTION', -6);
define('SIGMA_PLACEHOLDER_NOT_FOUND', -10);
define('SIGMA_PLACEHOLDER_DUPLICATE', -11);
define('SIGMA_BLOCK_EXISTS', -12);
define('SIGMA_INVALID_CALLBACK', -13);
define('SIGMA_CALLBACK_SYNTAX_ERROR', -14);
/**#@-*/

/**
 * Implementation of Integrated Templates API with template 'compilation' added.
 *
 * The main new feature in Sigma is the template 'compilation'. Consider the
 * following: when loading a template file the engine has to parse it using
 * regular expressions to find all the blocks and variable placeholders. This
 * is a very "expensive" operation and is definitely an overkill to do on
 * every page request: templates seldom change on production websites. This is
 * where the cache kicks in: it saves an internal representation of the
 * template structure into a file and this file gets loaded instead of the
 * source one on subsequent requests (unless the source changes, of course).
 *
 * While HTML_Template_Sigma inherits PHPLib Template's template syntax, it has
 * an API which is easier to understand. When using HTML_Template_PHPLIB, you
 * have to explicitly name a source and a target the block gets parsed into.
 * This gives maximum flexibility but requires full knowledge of template
 * structure from the programmer.
 *
 * Integrated Template on the other hands manages block nesting and parsing
 * itself. The engine knows that inner1 is a child of block2, there's
 * no need to tell it about this:
 *
 * <pre>
 * + __global__ (hidden and automatically added)
 *     + block1
 *     + block2
 *         + inner1
 *         + inner2
 * </pre>
 *
 * To add content to block1 you simply type:
 * <code>$tpl->setCurrentBlock("block1");</code>
 * and repeat this as often as needed:
 * <code>
 *   $tpl->setVariable(...);
 *   $tpl->parseCurrentBlock();
 * </code>
 *
 * To add content to block2 you would type something like:
 * <code>
 * $tpl->setCurrentBlock("inner1");
 * $tpl->setVariable(...);
 * $tpl->parseCurrentBlock();
 *
 * $tpl->setVariable(...);
 * $tpl->parseCurrentBlock();
 *
 * $tpl->parse("block2");
 * </code>
 *
 * This will result in one repetition of block2 which contains two repetitions
 * of inner1. inner2 will be removed if $removeEmptyBlock is set to true (which
 * is the default).
 *
 * Usage:
 * <code>
 * $tpl = new HTML_Template_Sigma( [string filerootdir], [string cacherootdir] );
 *
 * // load a template or set it with setTemplate()
 * $tpl->loadTemplatefile( string filename [, boolean removeUnknownVariables, boolean removeEmptyBlocks] )
 *
 * // set "global" Variables meaning variables not beeing within a (inner) block
 * $tpl->setVariable( string variablename, mixed value );
 *
 * // like with the HTML_Template_PHPLIB there's a second way to use setVariable()
 * $tpl->setVariable( array ( string varname => mixed value ) );
 *
 * // Let's use any block, even a deeply nested one
 * $tpl->setCurrentBlock( string blockname );
 *
 * // repeat this as often as you need it.
 * $tpl->setVariable( array ( string varname => mixed value ) );
 * $tpl->parseCurrentBlock();
 *
 * // get the parsed template or print it: $tpl->show()
 * $html = $tpl->get();
 * </code>
 *
 * @category HTML
 * @package  HTML_Template_Sigma
 * @author   Ulf Wendel <ulf.wendel@phpdoc.de>
 * @author   Alexey Borzov <avb@php.net>
 * @license  http://www.php.net/license/3_01.txt PHP License 3.01
 * @version  Release: 1.3.0
 * @link     http://pear.php.net/package/HTML_Template_Sigma
 */
class HTML_Template_Sigma extends PEAR
{
    /**
     * First character of a variable placeholder ( _{_VARIABLE} ).
     * @var      string
     * @access   public
     * @see      $closingDelimiter, $blocknameRegExp, $variablenameRegExp
     */
    var $openingDelimiter = '{';

    /**
     * Last character of a variable placeholder ( {VARIABLE_}_ )
     * @var      string
     * @access   public
     * @see      $openingDelimiter, $blocknameRegExp, $variablenameRegExp
     */
    var $closingDelimiter = '}';

    /**
     * RegExp for matching the block names in the template.
     * Per default "sm" is used as the regexp modifier, "i" is missing.
     * That means a case sensitive search is done.
     * @var      string
     * @access   public
     * @see      $variablenameRegExp, $openingDelimiter, $closingDelimiter
     */
    var $blocknameRegExp = '[0-9A-Za-z_-]+';

    /**
     * RegExp matching a variable placeholder in the template.
     * Per default "sm" is used as the regexp modifier, "i" is missing.
     * That means a case sensitive search is done.
     * @var      string
     * @access   public
     * @see      $blocknameRegExp, $openingDelimiter, $closingDelimiter
     */
    var $variablenameRegExp = '[0-9A-Za-z._-]+';

    /**
     * RegExp used to find variable placeholder, filled by the constructor
     * @var      string    Looks somewhat like @(delimiter varname delimiter)@
     * @see      HTML_Template_Sigma()
     */
    var $variablesRegExp = '';

    /**
     * RegExp used to strip unused variable placeholders
     * @see      $variablesRegExp, HTML_Template_Sigma()
     */
    var $removeVariablesRegExp = '';

    /**
     * RegExp used to find blocks and their content, filled by the constructor
     * @var      string
     * @see      HTML_Template_Sigma()
     */
    var $blockRegExp = '';

    /**
     * Controls the handling of unknown variables, default is remove
     * @var      boolean
     * @access   public
     */
    var $removeUnknownVariables = true;

    /**
     * Controls the handling of empty blocks, default is remove
     * @var      boolean
     * @access   public
     */
    var $removeEmptyBlocks = true;

    /**
     * Name of the current block
     * @var      string
     */
    var $currentBlock = '__global__';

    /**
     * Template blocks and their content
     * @var      array
     * @see      _buildBlocks()
     * @access   private
     */
    var $_blocks = [];

    /**
     * Content of parsed blocks
     * @var      array
     * @see      get(), parse()
     * @access   private
     */
    var $_parsedBlocks = [];

    /**
     * Variable names that appear in the block
     * @var      array
     * @see      _buildBlockVariables()
     * @access   private
     */
    var $_blockVariables = [];

    /**
     * Inner blocks inside the block
     * @var      array
     * @see      _buildBlocks()
     * @access   private
     */
    var $_children = [];

    /**
     * List of blocks to preserve even if they are "empty"
     * @var      array
     * @see      touchBlock(), $removeEmptyBlocks
     * @access   private
     */
    var $_touchedBlocks = [];

    /**
     * List of blocks which should not be shown even if not "empty"
     * @var      array
     * @see      hideBlock(), $removeEmptyBlocks
     * @access   private
     */
    var $_hiddenBlocks = [];

    /**
     * Variables for substitution.
     *
     * Variables are kept in this array before the replacements are done.
     * This allows automatic removal of empty blocks.
     *
     * @var      array
     * @see      setVariable()
     * @access   private
     */
    var $_variables = [];

    /**
     * Global variables for substitution
     *
     * These are substituted into all blocks, are not cleared on
     * block parsing and do not trigger "non-empty" logic. I.e. if
     * only global variables are substituted into the block, it is
     * still considered "empty".
     *
     * @var      array
     * @see      setVariable(), setGlobalVariable()
     * @access   private
     */
    var $_globalVariables = [];

    /**
     * Root directory for "source" templates
     * @var    string
     * @see    HTML_Template_Sigma(), setRoot()
     */
    var $fileRoot = '';

    /**
     * Directory to store the "prepared" templates in
     * @var      string
     * @see      HTML_Template_Sigma(), setCacheRoot()
     * @access   private
     */
    var $_cacheRoot = null;

    /**
     * Flag indicating that the global block was parsed
     * @var    boolean
     */
    var $flagGlobalParsed = false;

    /**
     * Options to control some finer aspects of Sigma's work.
     *
     * @var      array
     * @access   private
     */
    var $_options = [
        'preserve_data' => false,
        'trim_on_save' => true,
        'charset' => 'iso-8859-1',
    ];

    /**
     * Function name prefix used when searching for function calls in the template
     * @var    string
     */
    var $functionPrefix = 'func_';

    /**
     * Function name RegExp
     * @var    string
     */
    var $functionnameRegExp = '[_a-zA-Z][A-Za-z_0-9]*';

    /**
     * RegExp used to grep function calls in the template (set by the constructor)
     * @var    string
     * @see    _buildFunctionlist(), HTML_Template_Sigma()
     */
    var $functionRegExp = '';

    /**
     * List of functions found in the template.
     * @var    array
     * @access private
     */
    var $_functions = [];

    /**
     * List of callback functions specified by the user
     * @var    array
     * @access private
     */
    var $_callback = [];

    /**
     * RegExp used to find file inclusion calls in the template
     * @var  string
     */
    var $includeRegExp = '#<!--\s+INCLUDE\s+(\S+)\s+-->#im';

    /**
     * RegExp used to find (and remove) comments in the template
     * @var  string
     */
    var $commentRegExp = '#<!--\s+COMMENT\s+-->.*?<!--\s+/COMMENT\s+-->#sm';

    /**
     * Files queued for inclusion
     * @var    array
     * @access private
     */
    var $_triggers = [];

    /**
     * Name of the block to use in _makeTrigger() (see bug #20068)
     * @var string
     * @access private
     */
    var $_triggerBlock = '__global__';

    /**
     * Object for cache systems
     * @var    object
     * @access private
     */
    var $cache = false;

    private $listJsFiles = [];

    private $jsReadyString = [];

    /**
     * Constructor: builds some complex regular expressions and optionally
     * sets the root directories.
     *
     * Make sure that you call this constructor if you derive your template
     * class from this one.
     *
     * @param string $root root directory for templates
     * @param string $cacheRoot directory to cache "prepared" templates in
     * @param Cache|System_SharedMemory_Memcache|boolean $cache
     *
     * @see   setRoot(), setCacheRoot()
     */
    public function __construct($root = '', $cacheRoot = '', $cache = false)
    {
        if (!is_array(@$GLOBALS['HTMLTemplateSigmajsReadyString'])) {
            $GLOBALS['HTMLTemplateSigmajsReadyString'] = [];

        }
        $this->jsReadyString = &$GLOBALS['HTMLTemplateSigmajsReadyString'];

        if (!is_array(@$GLOBALS['HTMLTemplateSigmaJsFiles'])) {
            $GLOBALS['HTMLTemplateSigmaJsFiles'] = [];

        }
        $this->listJsFiles = &$GLOBALS['HTMLTemplateSigmaJsFiles'];

        if (!isset($this->listJsFiles['primary'])) {
            $this->listJsFiles['primary'] = [];
        }
        if (!isset($this->listJsFiles['default'])) {
            $this->listJsFiles['default'] = [];
        }

        // the class is inherited from PEAR to be able to use $this->setErrorHandling()
        $this->PEAR();
        $this->variablesRegExp = '@' . $this->openingDelimiter . '(' . $this->variablenameRegExp . ')' .
            '(:(' . $this->functionnameRegExp . '))?' . $this->closingDelimiter . '@sm';
        $this->removeVariablesRegExp = '@' . $this->openingDelimiter . '\s*(' . $this->variablenameRegExp . ')\s*'
            . $this->closingDelimiter . '@sm';
        $this->blockRegExp = '@<!--\s+BEGIN\s+(' . $this->blocknameRegExp
            . ')\s+-->(.*)<!--\s+END\s+\1\s+-->@sm';
        $this->functionRegExp = '@' . $this->functionPrefix . '(' . $this->functionnameRegExp . ')\s*\(@sm';
        $this->setRoot($root);
        $this->setCacheRoot($cacheRoot);

        $this->setCallbackFunction('h', [&$this, '_htmlspecialchars']);
        $this->setCallbackFunction('e', [&$this, '_htmlentities']);
        $this->setCallbackFunction('u', 'urlencode');
        $this->setCallbackFunction('r', 'rawurlencode');
        $this->setCallbackFunction('j', [&$this, '_jsEscape']);

        $this->setCallbackFunction('cssCdnFile', [&$this, 'cssCdnFile']);
        $this->setCallbackFunction('jsCdnFile', [&$this, 'jsCdnFile']);
        $this->setCallbackFunction('cdnFile', [&$this, 'cdnFile']);
        $this->setCallbackFunction('jsAddFile', [&$this, 'jsAddFile']);
        $this->setCallbackFunction('jsGetListFiles', [&$this, 'jsGetListFiles']);
        $this->setCallbackFunction('jsAddReady', [&$this, 'jsAddReady']);
        $this->setCallbackFunction('jsGetReady', [&$this, 'jsGetReady']);
        $this->setCallbackFunction('strMobile', [&$this, 'strMobile']);

        if ($cache != false) {
            $this->setCache($cache);
        }
    }


    /**
     * Sets the file root for templates. The file root gets prefixed to all
     * filenames passed to the object.
     *
     * @param string $root directory name
     *
     * @return $this;
     * @see    HTML_Template_Sigma()
     * @access public
     */
    function setRoot($root)
    {
        if (('' != $root) && (DIRECTORY_SEPARATOR != substr($root, -1))) {
            $root .= DIRECTORY_SEPARATOR;
        }
        $this->fileRoot = $root;
        return $this;
    }


    /**
     * Sets the directory to cache "prepared" templates in, the directory should be writable for PHP.
     *
     * The "prepared" template contains an internal representation of template
     * structure: essentially a serialized array of $_blocks, $_blockVariables,
     * $_children and $_functions, may also contain $_triggers. This allows
     * to bypass expensive calls to _buildBlockVariables() and especially
     * _buildBlocks() when reading the "prepared" template instead of
     * the "source" one.
     *
     * The files in this cache do not have any TTL and are regenerated when the
     * source templates change.
     *
     * @param string $root directory name
     *
     * @return $this
     * @see    HTML_Template_Sigma(), _getCached(), _writeCache()
     * @access public
     */
    function setCacheRoot($root)
    {
        if (empty($root)) {
            $root = null;
        } elseif (DIRECTORY_SEPARATOR != substr($root, -1)) {
            $root .= DIRECTORY_SEPARATOR;
        }
        $this->_cacheRoot = $root;
        return $this;
    }

    /**
     * @access public
     * @param object Cache|SharedMemory
     * @return $this
     */
    function setCache($cache)
    {
        $this->cache = false;
        if ($cache != false && (strpos(get_class($cache), 'SharedMemory') !== false || strpos(get_class($cache), 'JustCommunication\Cache') !== false)) {
            $this->cache = $cache;
        }
        return $this;
    }


    /**
     * Sets the option for the template class
     *
     * Currently available options:
     * - preserve_data: If false (default), then substitute variables and
     *   remove empty placeholders in data passed through setVariable (see also
     *   PHP bugs #20199, #21951)
     * - trim_on_save: Whether to trim extra whitespace from template on cache
     *   save (defaults to true). Generally safe to leave this on, unless you
     *   have <<pre>><</pre>> in templates or want to preserve HTML indentantion
     * - charset: is used by builtin template callback 'h'/'e'. Defaults to 'iso-8859-1'
     *
     * @param string $option option name
     * @param mixed $value option value
     *
     * @access public
     * @return mixed SIGMA_OK on success, error object on failure
     */
    function setOption($option, $value)
    {
        if (isset($this->_options[$option])) {
            $this->_options[$option] = $value;
            return SIGMA_OK;
        }
        return $this->raiseError($this->errorMessage(SIGMA_UNKNOWN_OPTION, $option), SIGMA_UNKNOWN_OPTION);
    }


    /**
     * Returns a textual error message for an error code
     *
     * @param integer|PEAR_Error $code error code or another error object for code reuse
     * @param string $data additional data to insert into message
     *
     * @access public
     * @return string error message
     */
    function errorMessage($code, $data = null)
    {
        static $errorMessages;
        if (!isset($errorMessages)) {
            $errorMessages = [
                SIGMA_ERROR => 'unknown error',
                SIGMA_OK => '',
                SIGMA_TPL_NOT_FOUND => 'Cannot read the template file \'%s\'',
                SIGMA_BLOCK_NOT_FOUND => 'Cannot find block \'%s\'',
                SIGMA_BLOCK_DUPLICATE => 'The name of a block must be unique within a template. '
                    . 'Block \'%s\' found twice.',
                SIGMA_CACHE_ERROR => 'Cannot save template file \'%s\'',
                SIGMA_UNKNOWN_OPTION => 'Unknown option \'%s\'',
                SIGMA_PLACEHOLDER_NOT_FOUND => 'Variable placeholder \'%s\' not found',
                SIGMA_PLACEHOLDER_DUPLICATE => 'Placeholder \'%s\' should be unique, found in multiple blocks',
                SIGMA_BLOCK_EXISTS => 'Block \'%s\' already exists',
                SIGMA_INVALID_CALLBACK => 'Callback does not exist',
                SIGMA_CALLBACK_SYNTAX_ERROR => 'Cannot parse template function: %s',
            ];
        }

        if (is_a($code, 'PEAR_Error')) {
            $code = $code->getCode();
        }
        if (!isset($errorMessages[$code])) {
            return $errorMessages[SIGMA_ERROR];
        } else {
            return (null === $data) ? $errorMessages[$code] : sprintf($errorMessages[$code], $data);
        }
    }


    /**
     * Prints a block with all replacements done.
     *
     * @param string $block block name
     *
     * @access  public
     * @return  void
     * @see     get()
     */
    function show($block = '__global__')
    {
        print $this->get($block);
    }


    /**
     * Returns a block with all replacements done.
     *
     * @param string $block block name
     * @param bool $clear whether to clear parsed block contents
     *
     * @return string block with all replacements done
     * @throws PEAR_Error
     * @access public
     * @see    show()
     */
    function get($block = '__global__', $clear = false)
    {
        if (!isset($this->_blocks[$block])) {
            return $this->raiseError($this->errorMessage(SIGMA_BLOCK_NOT_FOUND, $block), SIGMA_BLOCK_NOT_FOUND);
        }
        if ('__global__' == $block && !$this->flagGlobalParsed) {
            $this->parse('__global__');
        }
        // return the parsed block, removing the unknown placeholders if needed
        if (!isset($this->_parsedBlocks[$block])) {
            return '';

        } else {
            $ret = $this->_parsedBlocks[$block];
            if ($clear) {
                unset($this->_parsedBlocks[$block]);
            }
            if ($this->removeUnknownVariables) {
                $ret = preg_replace($this->removeVariablesRegExp, '', $ret);
            }
            if ($this->_options['preserve_data']) {
                $ret = str_replace(
                    $this->openingDelimiter . '%preserved%' . $this->closingDelimiter, $this->openingDelimiter, $ret
                );
            }
            return $ret;
        }
    }


    /**
     * Parses the given block.
     *
     * @param string $block block name
     * @param bool $flagRecursion true if the function is called recursively (do not set this to true yourself!)
     * @param bool $fakeParse true if parsing a "hidden" block (do not set this to true yourself!)
     *
     * @return bool whether the block was "empty"
     * @access public
     * @throws PEAR_Error
     * @see    parseCurrentBlock()
     */
    function parse($block = '__global__', $flagRecursion = false, $fakeParse = false)
    {
        static $vars;

        if (!isset($this->_blocks[$block])) {
            return $this->raiseError($this->errorMessage(SIGMA_BLOCK_NOT_FOUND, $block), SIGMA_BLOCK_NOT_FOUND);
        }
        if ('__global__' == $block) {
            $this->flagGlobalParsed = true;
        }
        if (!isset($this->_parsedBlocks[$block])) {
            $this->_parsedBlocks[$block] = '';
        }
        $outer = $this->_blocks[$block];

        if (!$flagRecursion) {
            $vars = [];
        }
        // block is not empty if its local var is substituted
        $empty = true;
        foreach ($this->_blockVariables[$block] as $allowedvar => $v) {
            if (isset($this->_variables[$allowedvar])) {
                $vars[$this->openingDelimiter . $allowedvar . $this->closingDelimiter] = $this->_variables[$allowedvar];
                $empty = false;
                // vital for checking "empty/nonempty" status
                unset($this->_variables[$allowedvar]);
            }
        }

        // processing of the inner blocks
        if (isset($this->_children[$block])) {
            foreach ($this->_children[$block] as $innerblock => $v) {
                $placeholder = $this->openingDelimiter . '__' . $innerblock . '__' . $this->closingDelimiter;

                if (isset($this->_hiddenBlocks[$innerblock])) {
                    // don't bother actually parsing this inner block; but we _have_
                    // to go through its local vars to prevent problems on next iteration
                    $this->parse($innerblock, true, true);
                    unset($this->_hiddenBlocks[$innerblock]);
                    $outer = str_replace($placeholder, '', $outer);

                } else {
                    $this->parse($innerblock, true, $fakeParse);
                    // block is not empty if its inner block is not empty
                    if ('' != $this->_parsedBlocks[$innerblock]) {
                        $empty = false;
                    }

                    $outer = str_replace($placeholder, $this->_parsedBlocks[$innerblock], $outer);
                    $this->_parsedBlocks[$innerblock] = '';
                }
            }
        }

        // add "global" variables to the static array
        foreach ($this->_globalVariables as $allowedvar => $value) {
            if (isset($this->_blockVariables[$block][$allowedvar])) {
                $vars[$this->openingDelimiter . $allowedvar . $this->closingDelimiter] = $value;
            }
        }
        // if we are inside a hidden block, don't bother
        if (!$fakeParse) {
            if (0 != count($vars) && (!$flagRecursion || !empty($this->_functions[$block]))) {
                $varKeys = array_keys($vars);
                $varValues = $this->_options['preserve_data']
                    ? array_map([&$this, '_preserveOpeningDelimiter'], array_values($vars))
                    : array_values($vars);
            }

            // check whether the block is considered "empty" and append parsed content if not
            if (!$empty || '__global__' == $block
                || !$this->removeEmptyBlocks || isset($this->_touchedBlocks[$block])
            ) {
                // perform callbacks
                if (!empty($this->_functions[$block])) {
                    foreach ($this->_functions[$block] as $id => $data) {
                        $placeholder = $this->openingDelimiter . '__function_' . $id . '__' . $this->closingDelimiter;
                        // do not waste time calling function more than once
                        if (!isset($vars[$placeholder])) {
                            $args = [];
                            $preserveArgs = !empty($this->_callback[$data['name']]['preserveArgs']);
                            foreach ($data['args'] as $arg) {
                                $args[] = (empty($varKeys) || $preserveArgs)
                                    ? $arg
                                    : str_replace($varKeys, $varValues, $arg);
                            }
                            if (isset($this->_callback[$data['name']]['data'])) {
                                $res = call_user_func_array($this->_callback[$data['name']]['data'], $args);
                            } else {
                                $res = isset($args[0]) ? $args[0] : '';
                            }
                            $outer = str_replace($placeholder, $res, $outer);
                            // save the result to variable cache, it can be requested somewhere else
                            $vars[$placeholder] = $res;
                        }
                    }
                }
                // substitute variables only on non-recursive call, thus all
                // variables from all inner blocks get substituted
                if (!$flagRecursion && !empty($varKeys)) {
                    $outer = str_replace($varKeys, $varValues, $outer);
                }

                $this->_parsedBlocks[$block] .= $outer;
                if (isset($this->_touchedBlocks[$block])) {
                    unset($this->_touchedBlocks[$block]);
                }
            }
        }
        return $empty;
    }


    /**
     * Sets a variable value.
     *
     * The function can be used either like setVariable("varname", "value")
     * or with one array $variables["varname"] = "value" given setVariable($variables)
     *
     * If $value is an array ('key' => 'value', ...) then values from that array
     * will be assigned to template placeholders of the form {variable.key}, ...
     *
     * @param string|array $variable variable name or array ('varname' => 'value')
     * @param string|array $value variable value if $variable is not an array
     *
     * @access public
     * @return $this
     */
    function setVariable($variable, $value = '')
    {
        if (is_array($variable)) {
            $this->_variables = array_merge($this->_variables, $variable);
        } elseif (is_array($value)) {
            $this->_variables = array_merge(
                $this->_variables, $this->_flattenVariables($variable, $value)
            );
        } else {
            $this->_variables[$variable] = $value;
        }
        return $this;
    }


    /**
     * Sets a global variable value.
     *
     * @param string|array $variable variable name or array ('varname' => 'value')
     * @param string|array $value variable value if $variable is not an array
     *
     * @access public
     * @return $this
     * @see    setVariable()
     */
    function setGlobalVariable($variable, $value = '')
    {
        if (is_array($variable)) {
            $this->_globalVariables = array_merge($this->_globalVariables, $variable);
        } elseif (is_array($value)) {
            $this->_globalVariables = array_merge(
                $this->_globalVariables, $this->_flattenVariables($variable, $value)
            );
        } else {
            $this->_globalVariables[$variable] = $value;
        }
        return $this;
    }


    /**
     * Sets the name of the current block: the block where variables are added
     *
     * @param string $block block name
     *
     * @access public
     * @return mixed SIGMA_OK on success, error object on failure
     * @throws PEAR_Error
     */
    function setCurrentBlock($block = '__global__')
    {
        if (!isset($this->_blocks[$block])) {
            return $this->raiseError($this->errorMessage(SIGMA_BLOCK_NOT_FOUND, $block), SIGMA_BLOCK_NOT_FOUND);
        }
        $this->currentBlock = $block;
        return SIGMA_OK;
    }


    /**
     * Parses the current block
     *
     * @return bool whether the block was "empty"
     * @see    parse(), setCurrentBlock()
     * @access public
     */
    function parseCurrentBlock()
    {
        return $this->parse($this->currentBlock);
    }


    /**
     * Returns the current block name
     *
     * @return string block name
     * @access public
     */
    function getCurrentBlock()
    {
        return $this->currentBlock;
    }


    /**
     * Preserves the block even if empty blocks should be removed.
     *
     * Sometimes you have blocks that should be preserved although they are
     * empty (no placeholder replaced). Think of a shopping basket. If it's
     * empty you have to show a message to the user. If it's filled you have
     * to show the contents of the shopping basket. Now where to place the
     * message that the basket is empty? It's not a good idea to place it
     * in you application as customers tend to like unecessary minor text
     * changes. Having another template file for an empty basket means that
     * one fine day the filled and empty basket templates will have different
     * layouts.
     *
     * So blocks that do not contain any placeholders but only messages like
     * "Your shopping basked is empty" are intoduced. Now if there is no
     * replacement done in such a block the block will be recognized as "empty"
     * and by default ($removeEmptyBlocks = true) be stripped off. To avoid this
     * you can call touchBlock()
     *
     * @param string $block block name
     *
     * @access public
     * @return mixed SIGMA_OK on success, error object on failure
     * @throws PEAR_Error
     * @see    $removeEmptyBlocks, $_touchedBlocks
     */
    function touchBlock($block)
    {
        if (!isset($this->_blocks[$block])) {
            return $this->raiseError($this->errorMessage(SIGMA_BLOCK_NOT_FOUND, $block), SIGMA_BLOCK_NOT_FOUND);
        }
        if (isset($this->_hiddenBlocks[$block])) {
            unset($this->_hiddenBlocks[$block]);
        }
        $this->_touchedBlocks[$block] = true;
        return SIGMA_OK;
    }


    /**
     * Hides the block even if it is not "empty".
     *
     * Is somewhat an opposite to touchBlock().
     *
     * Consider a block (a 'edit' link for example) that should be visible to
     * registered/"special" users only, but its visibility is triggered by
     * some little 'id' field passed in a large array into setVariable(). You
     * can either carefully juggle your variables to prevent the block from
     * appearing (a fragile solution) or simply call hideBlock()
     *
     * @param string $block block name
     *
     * @access public
     * @return mixed SIGMA_OK on success, error object on failure
     * @throws PEAR_Error
     */
    function hideBlock($block)
    {
        if (!isset($this->_blocks[$block])) {
            return $this->raiseError($this->errorMessage(SIGMA_BLOCK_NOT_FOUND, $block), SIGMA_BLOCK_NOT_FOUND);
        }
        if (isset($this->_touchedBlocks[$block])) {
            unset($this->_touchedBlocks[$block]);
        }
        $this->_hiddenBlocks[$block] = true;
        return SIGMA_OK;
    }


    /**
     * Sets the template.
     *
     * You can either load a template file from disk with LoadTemplatefile() or set the
     * template manually using this function.
     *
     * @param string $template template content
     * @param boolean $removeUnknownVariables remove unknown/unused variables?
     * @param boolean $removeEmptyBlocks remove empty blocks?
     *
     * @access public
     * @return mixed SIGMA_OK on success, error object on failure
     * @see    loadTemplatefile()
     */
    function setTemplate($template, $removeUnknownVariables = true, $removeEmptyBlocks = true)
    {
        $this->_resetTemplate($removeUnknownVariables, $removeEmptyBlocks);
        $list = $this->_buildBlocks(
            '<!-- BEGIN __global__ -->' .
            preg_replace($this->commentRegExp, '', $template) .
            '<!-- END __global__ -->'
        );
        if (is_a($list, 'PEAR_Error')) {
            return $list;
        }
        return $this->_buildBlockVariables();
    }


    /**
     * Loads a template file.
     *
     * If caching is on, then it checks whether a "prepared" template exists.
     * If it does, it gets loaded instead of the original, if it does not, then
     * the original gets loaded and prepared and then the prepared version is saved.
     * addBlockfile() and replaceBlockfile() implement quite the same logic.
     *
     * @param string $filename filename
     * @param boolean $removeUnknownVariables remove unknown/unused variables?
     * @param boolean $removeEmptyBlocks remove empty blocks?
     *
     * @access public
     * @return mixed SIGMA_OK on success, error object on failure
     * @see    setTemplate(), $removeUnknownVariables, $removeEmptyBlocks
     */
    function loadTemplateFile($filename, $removeUnknownVariables = true, $removeEmptyBlocks = true)
    {
        $filename = $this->getFileMobileVersion($filename);

        if ($this->_isCached($filename)) {
            $this->_resetTemplate($removeUnknownVariables, $removeEmptyBlocks);
            return $this->_getCached($filename);
        }
        if (false === ($template = $this->_getFile($this->fileRoot . $filename))) {
            return $this->raiseError($this->errorMessage(SIGMA_TPL_NOT_FOUND, $filename), SIGMA_TPL_NOT_FOUND);
        }
        $this->_triggers = [];
        $this->_triggerBlock = '__global__';
        $template = preg_replace_callback($this->includeRegExp, [&$this, '_makeTrigger'], $template);
        if (SIGMA_OK !== ($res = $this->setTemplate($template, $removeUnknownVariables, $removeEmptyBlocks))) {
            return $res;
        } else {
            return $this->_writeCache($filename, '__global__');
        }
    }


    /**
     * Adds a block to the template changing a variable placeholder to a block placeholder.
     *
     * This means that a new block will be integrated into the template in
     * place of a variable placeholder. The variable placeholder will be
     * removed and the new block will behave in the same way as if it was
     * inside the original template.
     *
     * The block content must not start with <!-- BEGIN blockname --> and end with
     * <!-- END blockname -->, if it does the error will be thrown.
     *
     * @param string $placeholder name of the variable placeholder, the name must be unique within the template.
     * @param string $block name of the block to be added
     * @param string $template content of the block
     *
     * @access public
     * @return mixed SIGMA_OK on success, error object on failure
     * @throws PEAR_Error
     * @see    addBlockfile()
     */
    function addBlock($placeholder, $block, $template)
    {
        if (isset($this->_blocks[$block])) {
            return $this->raiseError($this->errorMessage(SIGMA_BLOCK_EXISTS, $block), SIGMA_BLOCK_EXISTS);
        }
        $parents = $this->_findParentBlocks($placeholder);
        if (0 == count($parents)) {
            return $this->raiseError(
                $this->errorMessage(SIGMA_PLACEHOLDER_NOT_FOUND, $placeholder), SIGMA_PLACEHOLDER_NOT_FOUND
            );

        } elseif (count($parents) > 1) {
            return $this->raiseError(
                $this->errorMessage(SIGMA_PLACEHOLDER_DUPLICATE, $placeholder), SIGMA_PLACEHOLDER_DUPLICATE
            );
        }

        $list = $this->_buildBlocks(
            "<!-- BEGIN $block -->" .
            preg_replace($this->commentRegExp, '', $template) .
            "<!-- END $block -->"
        );
        if (is_a($list, 'PEAR_Error')) {
            return $list;
        }
        $this->_replacePlaceholder($parents[0], $placeholder, $block);
        return $this->_buildBlockVariables($block);
    }


    /**
     * Adds a block taken from a file to the template, changing a variable placeholder
     * to a block placeholder.
     *
     * @param string $placeholder name of the variable placeholder
     * @param string $block name of the block to be added
     * @param string $filename template file that contains the block
     *
     * @access public
     * @return mixed SIGMA_OK on success, error object on failure
     * @throws PEAR_Error
     * @see    addBlock()
     */
    function addBlockfile($placeholder, $block, $filename)
    {
        $filename = $this->getFileMobileVersion($filename);

        if ($this->_isCached($filename)) {
            return $this->_getCached($filename, $block, $placeholder);
        }
        if (false === ($template = $this->_getFile($this->fileRoot . $filename))) {
            return $this->raiseError($this->errorMessage(SIGMA_TPL_NOT_FOUND, $filename), SIGMA_TPL_NOT_FOUND);
        }
        list($oldTriggerBlock, $this->_triggerBlock) = [$this->_triggerBlock, $block];
        $template = preg_replace_callback($this->includeRegExp, [&$this, '_makeTrigger'], $template);
        $this->_triggerBlock = $oldTriggerBlock;
        if (SIGMA_OK !== ($res = $this->addBlock($placeholder, $block, $template))) {
            return $res;
        } else {
            return $this->_writeCache($filename, $block);
        }
    }


    /**
     * Replaces an existing block with new content.
     *
     * This function will replace a block of the template and all blocks
     * contained in it and add a new block instead. This means you can
     * dynamically change your template.
     *
     * Sigma analyses the way you've nested blocks and knows which block
     * belongs into another block. This nesting information helps to make the
     * API short and simple. Replacing blocks does not only mean that Sigma
     * has to update the nesting information (relatively time consuming task)
     * but you have to make sure that you do not get confused due to the
     * template change yourself.
     *
     * @param string $block name of a block to replace
     * @param string $template new content
     * @param boolean $keepContent true if the parsed contents of the block should be kept
     *
     * @access public
     * @return mixed SIGMA_OK on success, error object on failure
     * @throws PEAR_Error
     * @see    replaceBlockfile(), addBlock()
     */
    function replaceBlock($block, $template, $keepContent = false)
    {
        if (!isset($this->_blocks[$block])) {
            return $this->raiseError($this->errorMessage(SIGMA_BLOCK_NOT_FOUND, $block), SIGMA_BLOCK_NOT_FOUND);
        }
        // should not throw a error as we already checked for block existance
        $this->_removeBlockData($block, $keepContent);

        $list = $this->_buildBlocks(
            "<!-- BEGIN $block -->" .
            preg_replace($this->commentRegExp, '', $template) .
            "<!-- END $block -->"
        );
        if (is_a($list, 'PEAR_Error')) {
            return $list;
        }
        // renew the variables list
        return $this->_buildBlockVariables($block);
    }


    /**
     * Replaces an existing block with new content from a file.
     *
     * @param string $block name of a block to replace
     * @param string $filename template file that contains the block
     * @param boolean $keepContent true if the parsed contents of the block should be kept
     *
     * @access public
     * @return mixed SIGMA_OK on success, error object on failure
     * @throws PEAR_Error
     * @see    replaceBlock(), addBlockfile()
     */
    function replaceBlockfile($block, $filename, $keepContent = false)
    {
        $filename = $this->getFileMobileVersion($filename);

        if ($this->_isCached($filename)) {
            $res = $this->_removeBlockData($block, $keepContent);
            if (is_a($res, 'PEAR_Error')) {
                return $res;
            } else {
                return $this->_getCached($filename, $block);
            }
        }
        if (false === ($template = $this->_getFile($this->fileRoot . $filename))) {
            return $this->raiseError($this->errorMessage(SIGMA_TPL_NOT_FOUND, $filename), SIGMA_TPL_NOT_FOUND);
        }
        list($oldTriggerBlock, $this->_triggerBlock) = [$this->_triggerBlock, $block];
        $template = preg_replace_callback($this->includeRegExp, [&$this, '_makeTrigger'], $template);
        $this->_triggerBlock = $oldTriggerBlock;
        if (SIGMA_OK !== ($res = $this->replaceBlock($block, $template, $keepContent))) {
            return $res;
        } else {
            return $this->_writeCache($filename, $block);
        }
    }


    /**
     * Checks if the block exists in the template
     *
     * @param string $block block name
     *
     * @access public
     * @return bool
     */
    function blockExists($block)
    {
        return isset($this->_blocks[$block]);
    }


    /**
     * Returns the name of the (first) block that contains the specified placeholder.
     *
     * @param string $placeholder Name of the placeholder you're searching
     * @param string $block Name of the block to scan. If left out (default) all blocks are scanned.
     *
     * @access public
     * @return string Name of the (first) block that contains the specified placeholder.
     *                If the placeholder was not found an empty string is returned.
     * @throws PEAR_Error
     */
    function placeholderExists($placeholder, $block = '')
    {
        if ('' != $block && !isset($this->_blocks[$block])) {
            return $this->raiseError($this->errorMessage(SIGMA_BLOCK_NOT_FOUND, $block), SIGMA_BLOCK_NOT_FOUND);
        }
        if ('' != $block) {
            // if we search in the specific block, we should just check the array
            return isset($this->_blockVariables[$block][$placeholder]) ? $block : '';
        } else {
            // _findParentBlocks returns an array, we need only the first element
            $parents = $this->_findParentBlocks($placeholder);
            return empty($parents) ? '' : $parents[0];
        }
    } // end func placeholderExists


    /**
     * Sets a callback function.
     *
     * Sigma templates can contain simple function calls. This means that the
     * author of the template can add a special placeholder to it:
     * <pre>
     * func_h1("embedded in h1")
     * </pre>
     * Sigma will parse the template for these placeholders and will allow
     * you to define a callback function for them. Callback will be called
     * automatically when the block containing such function call is parse()'d.
     *
     * Please note that arguments to these template functions can contain
     * variable placeholders: func_translate('Hello, {username}'), but not
     * blocks or other function calls.
     *
     * This should NOT be used to add logic (except some presentation one) to
     * the template. If you use a lot of such callbacks and implement business
     * logic through them, then you're reinventing the wheel. Consider using
     * XML/XSLT, native PHP or some other template engine.
     *
     * <code>
     * function h_one($arg) {
     *    return '<h1>' . $arg . '</h1>';
     * }
     * ...
     * $tpl = new HTML_Template_Sigma( ... );
     * ...
     * $tpl->setCallbackFunction('h1', 'h_one');
     * </code>
     *
     * template:
     * <pre>
     * func_h1('H1 Headline');
     * </pre>
     *
     * @param string $tplFunction Function name in the template
     * @param callable $callback A callback: anything that can be passed to call_user_func_array()
     * @param bool $preserveArgs If true, then no variable substitution in arguments
     *                               will take place before function call
     *
     * @access public
     * @return mixed SIGMA_OK on success, error object on failure
     * @throws PEAR_Error
     */
    function setCallbackFunction($tplFunction, $callback, $preserveArgs = false)
    {
        if (!is_callable($callback)) {
            return $this->raiseError($this->errorMessage(SIGMA_INVALID_CALLBACK), SIGMA_INVALID_CALLBACK);
        }
        $this->_callback[$tplFunction] = [
            'data' => $callback,
            'preserveArgs' => $preserveArgs,
        ];
        return SIGMA_OK;
    } // end func setCallbackFunction


    /**
     * Returns a list of blocks within a template.
     *
     * If $recursive is false, it returns just a 'flat' array of $parent's
     * direct subblocks. If $recursive is true, it builds a tree of template
     * blocks using $parent as root. Tree structure is compatible with
     * PEAR::Tree's Memory_Array driver.
     *
     * @param string $parent parent block name
     * @param bool $recursive whether to return a tree of child blocks (true) or a 'flat' array (false)
     *
     * @access public
     * @return array a list of child blocks
     * @throws PEAR_Error
     */
    function getBlockList($parent = '__global__', $recursive = false)
    {
        if (!isset($this->_blocks[$parent])) {
            return $this->raiseError($this->errorMessage(SIGMA_BLOCK_NOT_FOUND, $parent), SIGMA_BLOCK_NOT_FOUND);
        }
        if (!$recursive) {
            return isset($this->_children[$parent]) ? array_keys($this->_children[$parent]) : [];
        } else {
            $ret = ['name' => $parent];
            if (!empty($this->_children[$parent])) {
                $ret['children'] = [];
                foreach (array_keys($this->_children[$parent]) as $child) {
                    $ret['children'][] = $this->getBlockList($child, true);
                }
            }
            return $ret;
        }
    }


    /**
     * Returns a list of placeholders within a block.
     *
     * Only 'normal' placeholders are returned, not auto-created ones.
     *
     * @param string $block block name
     *
     * @access public
     * @return array a list of placeholders
     * @throws PEAR_Error
     */
    function getPlaceholderList($block = '__global__')
    {
        if (!isset($this->_blocks[$block])) {
            return $this->raiseError($this->errorMessage(SIGMA_BLOCK_NOT_FOUND, $block), SIGMA_BLOCK_NOT_FOUND);
        }
        $ret = [];
        foreach ($this->_blockVariables[$block] as $var => $v) {
            if ('__' != substr($var, 0, 2) || '__' != substr($var, -2)) {
                $ret[] = $var;
            }
        }
        return $ret;
    }


    /**
     * Clears the variables
     *
     * Global variables are not affected. The method is useful when you add
     * a lot of variables via setVariable() and are not sure whether all of
     * them appear in the block you parse(). If you clear the variables after
     * parse(), you don't risk them suddenly showing up in other blocks.
     *
     * @access public
     * @return void
     * @see    setVariable()
     */
    function clearVariables()
    {
        $this->_variables = [];
    }


    //------------------------------------------------------------
    //
    // Private methods follow
    //
    //------------------------------------------------------------

    /**
     * Builds the variable names for nested variables
     *
     * @param string $name variable name
     * @param array $array value array
     *
     * @access private
     * @return array array with 'name.key' keys
     */
    function _flattenVariables($name, $array)
    {
        $ret = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $ret = array_merge($ret, $this->_flattenVariables($name . '.' . $key, $value));
            } else {
                $ret[$name . '.' . $key] = $value;
            }
        }
        return $ret;
    }

    /**
     * Reads the file and returns its content
     *
     * @param string    filename
     * @param boolean   iscachedFile
     * @return   string    file content (or error object)
     * @access   private
     */
    function _getFile($filename, $cachedFile = false)
    {
        if (@$GLOBALS["HTMLTemplateSigmaStat"]["all"] == 0) {
            @$GLOBALS["HTMLTemplateSigmaStat"]["all"] = 1;
        } else {
            @$GLOBALS["HTMLTemplateSigmaStat"]["all"]++;
        }

        $useSharedMemory = false;
        $nameSharedMemory = @$GLOBALS["HTMLTemplateSigmaTplPrf"] . '_' . 'TemplateSigma_' . md5(realpath($filename));
        if ($cachedFile === true && $this->cache != false && method_exists($this->cache, 'get') && method_exists($this->cache, 'set') && method_exists($this->cache, 'rm')) {
            $useSharedMemory = true;
        }
        $memcache = false;
        if ($useSharedMemory && $memcache === false && !isset($_REQUEST['memcache_clear'])) {
            if (@$GLOBALS["HTMLTemplateSigmaStat"]["memcache"] == 0) {
                @$GLOBALS["HTMLTemplateSigmaStat"]["memcache"] = 1;
            } else {
                @$GLOBALS["HTMLTemplateSigmaStat"]["memcache"]++;
            }

            @$GLOBALS["HTMLTemplateSigmaStat"]["tpl"]["memcache"][] = $filename;
            if ($content = $this->cache->get($nameSharedMemory)) {
                $memcache = true;
            }
        }

        if ($memcache === false) {
            if (@$GLOBALS["HTMLTemplateSigmaStat"]["file"] == 0) {
                @$GLOBALS["HTMLTemplateSigmaStat"]["file"] = 1;
            } else {
                @$GLOBALS["HTMLTemplateSigmaStat"]["file"]++;
            }

            @$GLOBALS["HTMLTemplateSigmaStat"]["tpl"]["files"][] = $filename;
            if (false === ($content = @file_get_contents($filename))) {
                return false;
            }

        }

        if ($useSharedMemory && $memcache === false) {
            $this->cache->rm($nameSharedMemory);
            $this->cache->set($nameSharedMemory, $content, 10 * 60);
        }

        return $content;
    }


    /**
     * Recursively builds a list of all variables within a block.
     *
     * Also calls _buildFunctionlist() for each block it visits
     *
     * @param string $block block name
     *
     * @access private
     * @return mixed SIGMA_OK on success, error object on failure
     * @see    _buildFunctionlist()
     */
    function _buildBlockVariables($block = '__global__')
    {
        $this->_blockVariables[$block] = [];
        $this->_functions[$block] = [];
        preg_match_all($this->variablesRegExp, $this->_blocks[$block], $regs, PREG_SET_ORDER);
        foreach ($regs as $match) {
            $this->_blockVariables[$block][$match[1]] = true;
            if (!empty($match[3])) {
                $funcData = [
                    'name' => $match[3],
                    'args' => [$this->openingDelimiter . $match[1] . $this->closingDelimiter],
                ];
                $funcId = substr(md5(serialize($funcData)), 0, 10);

                // update block info
                $this->_blocks[$block] = str_replace(
                    $match[0],
                    $this->openingDelimiter . '__function_' . $funcId . '__' . $this->closingDelimiter,
                    $this->_blocks[$block]
                );
                $this->_blockVariables[$block]['__function_' . $funcId . '__'] = true;
                $this->_functions[$block][$funcId] = $funcData;
            }
        }
        if (SIGMA_OK != ($res = $this->_buildFunctionlist($block))) {
            return $res;
        }
        if (isset($this->_children[$block]) && is_array($this->_children[$block])) {
            foreach ($this->_children[$block] as $child => $v) {
                if (SIGMA_OK != ($res = $this->_buildBlockVariables($child))) {
                    return $res;
                }
            }
        }
        return SIGMA_OK;
    }


    /**
     * Recusively builds a list of all blocks within the template.
     *
     * @param string $string template to be scanned
     *
     * @access private
     * @return mixed array of block names on success or error object on failure
     * @throws PEAR_Error
     * @see    $_blocks
     */
    function _buildBlocks($string)
    {
        $blocks = [];
        if (preg_match_all($this->blockRegExp, $string, $regs, PREG_SET_ORDER)) {
            foreach ($regs as $match) {
                $blockname = $match[1];
                $blockcontent = $match[2];
                if (isset($this->_blocks[$blockname]) || isset($blocks[$blockname])) {
                    return $this->raiseError(
                        $this->errorMessage(SIGMA_BLOCK_DUPLICATE, $blockname), SIGMA_BLOCK_DUPLICATE
                    );
                }
                $this->_blocks[$blockname] = $blockcontent;
                $blocks[$blockname] = true;
                $inner = $this->_buildBlocks($blockcontent);
                if (is_a($inner, 'PEAR_Error')) {
                    return $inner;
                }
                foreach ($inner as $name => $v) {
                    $pattern = sprintf('@<!--\s+BEGIN\s+%s\s+-->(.*)<!--\s+END\s+%s\s+-->@sm', $name, $name);
                    $replacement = $this->openingDelimiter . '__' . $name . '__' . $this->closingDelimiter;
                    $this->_children[$blockname][$name] = true;
                    $this->_blocks[$blockname] = preg_replace(
                        $pattern, $replacement, $this->_blocks[$blockname]
                    );
                }
            }
        }
        return $blocks;
    }


    /**
     * Resets the object's properties, used before processing a new template
     *
     * @param boolean $removeUnknownVariables remove unknown/unused variables?
     * @param boolean $removeEmptyBlocks remove empty blocks?
     *
     * @access private
     * @return void
     * @see    setTemplate(), loadTemplateFile()
     */
    function _resetTemplate($removeUnknownVariables = true, $removeEmptyBlocks = true)
    {
        $this->removeUnknownVariables = $removeUnknownVariables;
        $this->removeEmptyBlocks = $removeEmptyBlocks;
        $this->currentBlock = '__global__';
        $this->_variables = [];
        $this->_blocks = [];
        $this->_children = [];
        $this->_parsedBlocks = [];
        $this->_touchedBlocks = [];
        $this->_functions = [];
        $this->flagGlobalParsed = false;
    } // _resetTemplate


    /**
     * Checks whether we have a "prepared" template cached.
     *
     * If we do not do caching, always returns false
     *
     * @param string $filename source filename
     *
     * @access private
     * @return bool yes/no
     * @see    loadTemplatefile(), addBlockfile(), replaceBlockfile()
     */
    function _isCached($filename)
    {
        if (null === $this->_cacheRoot) {
            return false;
        }
        $cachedName = $this->_cachedName($filename);
        $sourceName = $this->fileRoot . $filename;
        // if $sourceName does not exist, error will be thrown later
        return false !== ($sourceTime = @filemtime($sourceName)) && @filemtime($cachedName) === $sourceTime;
    } // _isCached


    /**
     * Loads a "prepared" template file
     *
     * @param string $filename filename
     * @param string $block block name
     * @param string $placeholder variable placeholder to replace by a block
     *
     * @access private
     * @return mixed SIGMA_OK on success, error object on failure
     * @see    loadTemplatefile(), addBlockfile(), replaceBlockfile()
     */
    function _getCached($filename, $block = '__global__', $placeholder = '')
    {
        // the same checks are done in addBlock()
        if (!empty($placeholder)) {
            if (isset($this->_blocks[$block])) {
                return $this->raiseError($this->errorMessage(SIGMA_BLOCK_EXISTS, $block), SIGMA_BLOCK_EXISTS);
            }
            $parents = $this->_findParentBlocks($placeholder);
            if (0 == count($parents)) {
                return $this->raiseError(
                    $this->errorMessage(SIGMA_PLACEHOLDER_NOT_FOUND, $placeholder), SIGMA_PLACEHOLDER_NOT_FOUND
                );

            } elseif (count($parents) > 1) {
                return $this->raiseError(
                    $this->errorMessage(SIGMA_PLACEHOLDER_DUPLICATE, $placeholder), SIGMA_PLACEHOLDER_DUPLICATE
                );
            }
        }
        if (false === ($content = $this->_getFile($this->_cachedName($filename), true))) {
            return $this->raiseError(
                $this->errorMessage(SIGMA_TPL_NOT_FOUND, $this->_cachedName($filename)), SIGMA_TPL_NOT_FOUND
            );
        }
        $cache = unserialize($content);
        if ('__global__' != $block) {
            $this->_blocks[$block] = $cache['blocks']['__global__'];
            $this->_blockVariables[$block] = $cache['variables']['__global__'];
            $this->_children[$block] = $cache['children']['__global__'];
            $this->_functions[$block] = $cache['functions']['__global__'];
            unset(
                $cache['blocks']['__global__'], $cache['variables']['__global__'],
                $cache['children']['__global__'], $cache['functions']['__global__']
            );
        }
        $this->_blocks = array_merge($this->_blocks, $cache['blocks']);
        $this->_blockVariables = array_merge($this->_blockVariables, $cache['variables']);
        $this->_children = array_merge($this->_children, $cache['children']);
        $this->_functions = array_merge($this->_functions, $cache['functions']);

        // the same thing gets done in addBlockfile()
        if (!empty($placeholder)) {
            $this->_replacePlaceholder($parents[0], $placeholder, $block);
        }
        // pull the triggers, if any
        if (isset($cache['triggers'])) {
            return $this->_pullTriggers($cache['triggers']);
        }
        return SIGMA_OK;
    } // _getCached


    /**
     * Returns a full name of a "prepared" template file
     *
     * @param string $filename source filename, relative to root directory
     *
     * @access private
     * @return string filename
     */
    function _cachedName($filename)
    {
        if (OS_WINDOWS) {
            $filename = str_replace(['/', '\\', ':'], ['__', '__', ''], $filename);
        } else {
            $filename = str_replace('/', '__', $filename);
        }
        return $this->_cacheRoot . $filename . '.it';
    } // _cachedName


    /**
     * Writes a prepared template file.
     *
     * Even if NO caching is going on, this method has a side effect: it calls
     * the _pullTriggers() method and thus loads all files added via <!-- INCLUDE -->
     *
     * @param string $filename source filename, relative to root directory
     * @param string $block name of the block to save into file
     *
     * @access private
     * @return mixed SIGMA_OK on success, error object on failure
     */
    function _writeCache($filename, $block)
    {
        // do not save anything if no cache dir, but do pull triggers
        if (null !== $this->_cacheRoot) {
            $cache = [
                'blocks' => [],
                'variables' => [],
                'children' => [],
                'functions' => [],
            ];
            $cachedName = $this->_cachedName($filename);
            $this->_buildCache($cache, $block);
            if ('__global__' != $block) {
                foreach (array_keys($cache) as $k) {
                    $cache[$k]['__global__'] = $cache[$k][$block];
                    unset($cache[$k][$block]);
                }
            }
            if (isset($this->_triggers[$block])) {
                $cache['triggers'] = $this->_triggers[$block];
            }
            $res = $this->_writeFileAtomically($cachedName, serialize($cache));
            if (is_a($res, 'PEAR_Error')) {
                return $res;
            }
            @touch($cachedName, @filemtime($this->fileRoot . $filename));
        }
        // now pull triggers
        if (isset($this->_triggers[$block])) {
            if (SIGMA_OK !== ($res = $this->_pullTriggers($this->_triggers[$block]))) {
                return $res;
            }
            unset($this->_triggers[$block]);
        }
        return SIGMA_OK;
    } // _writeCache

    /**
     * Atomically writes given content to a given file
     *
     * The method first creates a temporary file in the cache directory and
     * then renames it to the final name. This should prevent creating broken
     * cache files when there is no space left on device (bug #19220) or reading
     * incompletely saved files in another process / thread.
     *
     * The same idea is used in Twig, Symfony's Filesystem component, etc.
     *
     * @param string $fileName Name of the file to write
     * @param string $content Content to write
     *
     * @access private
     * @return mixed SIGMA_OK on success, error object on failure
     * @link http://pear.php.net/bugs/bug.php?id=19220
     */
    function _writeFileAtomically($fileName, $content)
    {
        global $SiteParam;
        $dirName = dirname($fileName);
        $tmpFile = tempnam($dirName, basename($fileName));
        if (!is_dir($dirName)) {
            $msg = "Sigma 40: not dir for [" . @$SiteParam['id_firm'] . "]";
            trigger_error($msg, E_USER_NOTICE);
        }

        if (function_exists('file_put_contents')) {
            if (false === @file_put_contents($tmpFile, $content)) {
                return $this->raiseError($this->errorMessage(SIGMA_CACHE_ERROR, $fileName), SIGMA_CACHE_ERROR);
            }

        } else {
            // Fall back to previous solution
            if (!($fh = @fopen($tmpFile, 'wb'))) {
                return $this->raiseError($this->errorMessage(SIGMA_CACHE_ERROR, $fileName), SIGMA_CACHE_ERROR);
            }
            if (!fwrite($fh, $content)) {
                return $this->raiseError($this->errorMessage(SIGMA_CACHE_ERROR, $fileName), SIGMA_CACHE_ERROR);
            }
            fclose($fh);
        }

        if (!OS_WINDOWS || version_compare(phpversion(), '5.2.6', '>=')) {
            if (@rename($tmpFile, $fileName)) {
                return SIGMA_OK;
            }

        } else {
            // rename() to an existing file will not work on Windows before PHP 5.2.6,
            // so we need to copy, which isn't that atomic, but better than writing directly to $fileName
            // https://bugs.php.net/bug.php?id=44805
            if (@copy($tmpFile, $fileName) && @unlink($tmpFile)) {
                return SIGMA_OK;
            }
        }

        return $this->raiseError($this->errorMessage(SIGMA_CACHE_ERROR, $fileName), SIGMA_CACHE_ERROR);
    }

    /**
     * Builds an array of template data to be saved in prepared template file
     *
     * @param array &$cache template data
     * @param string $block block to add to the array
     *
     * @access private
     * @return void
     */
    function _buildCache(&$cache, $block)
    {
        if (!$this->_options['trim_on_save']) {
            $cache['blocks'][$block] = $this->_blocks[$block];
        } else {
            $cache['blocks'][$block] = preg_replace(
                ['/^\\s+/m', '/\\s+$/m', '/(\\r?\\n)+/'],
                ['', '', "\n"],
                $this->_blocks[$block]
            );
        }
        $cache['variables'][$block] = $this->_blockVariables[$block];
        $cache['functions'][$block] = isset($this->_functions[$block]) ? $this->_functions[$block] : [];
        if (!isset($this->_children[$block])) {
            $cache['children'][$block] = [];
        } else {
            $cache['children'][$block] = $this->_children[$block];
            foreach (array_keys($this->_children[$block]) as $child) {
                $this->_buildCache($cache, $child);
            }
        }
    }


    /**
     * Recursively removes all data belonging to a block
     *
     * @param string $block block name
     * @param boolean $keepContent true if the parsed contents of the block should be kept
     *
     * @access private
     * @return mixed SIGMA_OK on success, error object on failure
     * @see    replaceBlock(), replaceBlockfile()
     */
    function _removeBlockData($block, $keepContent = false)
    {
        if (!isset($this->_blocks[$block])) {
            return $this->raiseError($this->errorMessage(SIGMA_BLOCK_NOT_FOUND, $block), SIGMA_BLOCK_NOT_FOUND);
        }
        if (!empty($this->_children[$block])) {
            foreach (array_keys($this->_children[$block]) as $child) {
                $this->_removeBlockData($child, false);
            }
            unset($this->_children[$block]);
        }
        unset($this->_blocks[$block]);
        unset($this->_blockVariables[$block]);
        unset($this->_hiddenBlocks[$block]);
        unset($this->_touchedBlocks[$block]);
        unset($this->_functions[$block]);
        if (!$keepContent) {
            unset($this->_parsedBlocks[$block]);
        }
        return SIGMA_OK;
    }


    /**
     * Returns the names of the blocks where the variable placeholder appears
     *
     * @param string $variable variable name
     *
     * @access private
     * @return array block names
     * @see    addBlock(), addBlockfile(), placeholderExists()
     */
    function _findParentBlocks($variable)
    {
        $parents = [];
        foreach ($this->_blockVariables as $blockname => $varnames) {
            if (!empty($varnames[$variable])) {
                $parents[] = $blockname;
            }
        }
        return $parents;
    }


    /**
     * Replaces a variable placeholder by a block placeholder.
     *
     * Of course, it also updates the necessary arrays
     *
     * @param string $parent name of the block containing the placeholder
     * @param string $placeholder variable name
     * @param string $block block name
     *
     * @access private
     * @return void
     */
    function _replacePlaceholder($parent, $placeholder, $block)
    {
        $this->_children[$parent][$block] = true;
        $this->_blockVariables[$parent]['__' . $block . '__'] = true;
        $this->_blocks[$parent] = str_replace(
            $this->openingDelimiter . $placeholder . $this->closingDelimiter,
            $this->openingDelimiter . '__' . $block . '__' . $this->closingDelimiter,
            $this->_blocks[$parent]
        );
        unset($this->_blockVariables[$parent][$placeholder]);
    }


    /**
     * Callback generating a placeholder to replace an <!-- INCLUDE filename --> statement
     *
     * @param array $matches Matches from preg_replace_callback() call
     *
     * @access private
     * @return string  a placeholder
     */
    function _makeTrigger($matches)
    {
        $name = 'trigger_' . substr(md5($matches[1] . ' ' . uniqid($this->_triggerBlock)), 0, 10);
        $this->_triggers[$this->_triggerBlock][$name] = $matches[1];
        return $this->openingDelimiter . $name . $this->closingDelimiter;
    }


    /**
     * Replaces the "trigger" placeholders by the matching file contents.
     *
     * @param array $triggers array ('trigger placeholder' => 'filename')
     *
     * @access private
     * @return mixed SIGMA_OK on success, error object on failure
     * @see _makeTrigger(), addBlockfile()
     */
    function _pullTriggers($triggers)
    {
        foreach ($triggers as $placeholder => $filename) {
            if (SIGMA_OK !== ($res = $this->addBlockfile($placeholder, $placeholder, $filename))) {
                return $res;
            }
            // we actually do not need the resultant block...
            $parents = $this->_findParentBlocks('__' . $placeholder . '__');
            // merge current block's children and variables with the parent's ones
            if (isset($this->_children[$placeholder])) {
                $this->_children[$parents[0]] = array_merge(
                    $this->_children[$parents[0]], $this->_children[$placeholder]
                );
            }
            $this->_blockVariables[$parents[0]] = array_merge(
                $this->_blockVariables[$parents[0]], $this->_blockVariables[$placeholder]
            );
            if (isset($this->_functions[$placeholder])) {
                $this->_functions[$parents[0]] = array_merge(
                    $this->_functions[$parents[0]], $this->_functions[$placeholder]
                );
            }
            // substitute the block's contents into parent's
            $this->_blocks[$parents[0]] = str_replace(
                $this->openingDelimiter . '__' . $placeholder . '__' . $this->closingDelimiter,
                $this->_blocks[$placeholder],
                $this->_blocks[$parents[0]]
            );
            // remove the stuff that is no more needed
            unset(
                $this->_blocks[$placeholder], $this->_blockVariables[$placeholder],
                $this->_children[$placeholder], $this->_functions[$placeholder],
                $this->_children[$parents[0]][$placeholder],
                $this->_blockVariables[$parents[0]]['__' . $placeholder . '__']
            );
        }
        return SIGMA_OK;
    }


    /**
     * Builds a list of functions in a block.
     *
     * @param string $block Block name
     *
     * @access private
     * @return mixed SIGMA_OK on success, error object on failure
     * @see    _buildBlockVariables()
     */
    function _buildFunctionlist($block)
    {
        $template = $this->_blocks[$block];
        $this->_blocks[$block] = '';

        while (preg_match($this->functionRegExp, $template, $regs)) {
            $this->_blocks[$block] .= substr($template, 0, strpos($template, $regs[0]));
            $template = substr($template, strpos($template, $regs[0]) + strlen($regs[0]));

            $state = 1;
            $arg = '';
            $quote = '';
            $funcData = [
                'name' => $regs[1],
                'args' => [],
            ];
            for ($i = 0, $len = strlen($template); $i < $len; $i++) {
                $char = $template[$i];
                switch ($state) {
                    case 0:
                    case -1:
                        break 2;

                    case 1:
                        if (')' == $char) {
                            $state = 0;
                        } elseif (',' == $char) {
                            $error = 'Unexpected \',\'';
                            $state = -1;
                        } elseif ('\'' == $char || '"' == $char) {
                            $quote = $char;
                            $state = 5;
                        } elseif (!ctype_space($char)) {
                            $arg .= $char;
                            $state = 3;
                        }
                        break;

                    case 2:
                        $arg = '';
                        if (',' == $char || ')' == $char) {
                            $error = 'Unexpected \'' . $char . '\'';
                            $state = -1;
                        } elseif ('\'' == $char || '"' == $char) {
                            $quote = $char;
                            $state = 5;
                        } elseif (!ctype_space($char)) {
                            $arg .= $char;
                            $state = 3;
                        }
                        break;

                    case 3:
                        if (')' == $char) {
                            $funcData['args'][] = rtrim($arg);
                            $state = 0;
                        } elseif (',' == $char) {
                            $funcData['args'][] = rtrim($arg);
                            $state = 2;
                        } elseif ('\'' == $char || '"' == $char) {
                            $quote = $char;
                            $arg .= $char;
                            $state = 4;
                        } else {
                            $arg .= $char;
                        }
                        break;

                    case 4:
                        $arg .= $char;
                        if ($quote == $char) {
                            $state = 3;
                        }
                        break;

                    case 5:
                        if ('\\' == $char) {
                            $state = 6;
                        } elseif ($quote == $char) {
                            $state = 7;
                        } else {
                            $arg .= $char;
                        }
                        break;

                    case 6:
                        $arg .= $char;
                        $state = 5;
                        break;

                    case 7:
                        if (')' == $char) {
                            $funcData['args'][] = $arg;
                            $state = 0;
                        } elseif (',' == $char) {
                            $funcData['args'][] = $arg;
                            $state = 2;
                        } elseif (!ctype_space($char)) {
                            $error = 'Unexpected \'' . $char . '\' (expected: \')\' or \',\')';
                            $state = -1;
                        }
                        break;
                } // switch
            } // for
            if (0 != $state) {
                return $this->raiseError(
                    $this->errorMessage(
                        SIGMA_CALLBACK_SYNTAX_ERROR,
                        (empty($error) ? 'Unexpected end of input' : $error)
                        . ' in ' . $regs[0] . substr($template, 0, $i)
                    ),
                    SIGMA_CALLBACK_SYNTAX_ERROR
                );

            } else {
                $funcId = 'f' . substr(md5(serialize($funcData)), 0, 10);
                $template = substr($template, $i);

                $this->_blocks[$block] .= $this->openingDelimiter . '__function_' . $funcId
                    . '__' . $this->closingDelimiter;
                $this->_blockVariables[$block]['__function_' . $funcId . '__'] = true;
                $this->_functions[$block][$funcId] = $funcData;
            }
        } // while
        $this->_blocks[$block] .= $template;
        return SIGMA_OK;
    } // end func _buildFunctionlist


    /**
     * Replaces an opening delimiter by a special string.
     *
     * Used to implement $_options['preserve_data'] logic
     *
     * @param string $str String possibly containing opening delimiters
     *
     * @access private
     * @return string
     */
    function _preserveOpeningDelimiter($str)
    {
        return (false === strpos($str, $this->openingDelimiter))
            ? $str
            : str_replace(
                $this->openingDelimiter,
                $this->openingDelimiter . '%preserved%' . $this->closingDelimiter, $str
            );
    }


    /**
     * Quotes the string so that it can be used in Javascript string constants
     *
     * @param string $value String to be used in JS
     *
     * @access private
     * @return string
     */
    function _jsEscape($value)
    {
        return strtr(
            $value,
            [
                "\r" => '\r', "'" => "\\x27", "\n" => '\n',
                '"' => '\\x22', "\t" => '\t', '\\' => '\\\\',
            ]
        );
    }


    /**
     * Wrapper around htmlspecialchars() needed to use the charset option
     *
     * @param string $value String with special characters
     *
     * @access private
     * @return string
     */
    function _htmlspecialchars($value)
    {
        return htmlspecialchars($value, ENT_COMPAT, $this->_options['charset']);
    }


    /**
     * Wrapper around htmlentities() needed to use the charset option
     *
     * @param string $value String with special characters
     *
     * @access private
     * @return string
     */
    function _htmlentities($value)
    {
        return htmlentities($value, ENT_COMPAT, $this->_options['charset']);
    }

    private function strMobile($full, $mobile)
    {
        if (@$GLOBALS["its_mobile"] === true) {
            return $mobile;
        } else {
            return $full;
        }

    }

    private function cssCdnFile($filenames, $verFilename = '')
    {
        $cssFiles = [];

        $typeVersion = '?';

        if (strpos($verFilename, '|') !== false) {
            $ar = explode('|', $verFilename);
            $verFilename = $ar[0];
            $typeVersion = $ar[1];
        }
        $proccCdn = true;


        list($files, $debug_files) = explode('|', $filenames, 2);

        if (null !== $debug_files && $GLOBALS["its_develop"]) {
            $files = explode(' ', $debug_files);
            $typeVersion = 'debug';
        } else {
            $files = [$files];
        }
        if ($GLOBALS["its_develop"]) {
            $proccCdn = false;
        }

        foreach ($files as $file) {


            switch ($typeVersion) {
                case 'v':
                    $dot = strrpos($file, '.');
                    $file = substr($file, 0, $dot) . '.v' . $this->_getFile($this->fileRoot . trim($verFilename)) . substr($file, $dot);
                    break;

                case 'debug':
                    $file = trim($file) . '?' . time();
                    break;

                case 'dev':
                    $dot = strrpos($file, '.');
                    $file = substr($file, 0, $dot) . '.dev' . substr($file, $dot);
                    $file = trim($file) . '?' . time();
                    break;

                case '?':
                default:
                    $file = trim($file) . ($verFilename != '' ? '?' . $this->_getFile($this->fileRoot . trim($verFilename)) : '');
                    break;

            }
            /** CDN mode */

            if ($proccCdn && array_key_exists('HTMLTemplateSigmaCdnParam', $GLOBALS)) {
                $cdnParams = $GLOBALS['HTMLTemplateSigmaCdnParam'];
                if ($cdnParams['css']['allow'] == true) {
                    foreach ($cdnParams['css']['from'] as $from => $to) {
                        if (substr($file, 0, strlen($from)) == $from) {
                            $file = $to . substr($file, strlen($from));
                            break;
                        }
                    }
                }
            }

            $cssFiles[] = '<link href="' . $file . '" type="text/css" rel="stylesheet" />';

        }

        return join("\r\n", $cssFiles);

    }

    private function jsCdnFile($filenames, $verFilename = '', $additional = '')
    {
        $jsFiles = [];

        $typeVersion = '?';

        if (strpos($verFilename, '|') !== false) {
            $ar = explode('|', $verFilename);
            $verFilename = $ar[0];
            $typeVersion = $ar[1];
        }
        $proccCdn = true;


        list($files, $debug_files) = explode('|', $filenames, 2);

        if (null !== $debug_files && $GLOBALS["its_develop"]) {
            $files = explode(' ', $debug_files);
            $typeVersion = 'debug';
        } else {
            $files = [$files];
        }
        if ($GLOBALS["its_develop"]) {
            $proccCdn = false;
        }

        foreach ($files as $file) {


            switch ($typeVersion) {
                case 'v':
                    $dot = strrpos($file, '.');
                    $file = substr($file, 0, $dot) . '.v' . $this->_getFile($this->fileRoot . trim($verFilename)) . substr($file, $dot);
                    break;

                case 'debug':
                    $file = trim($file) . '?' . time();
                    break;

                case 'dev':
                    $dot = strrpos($file, '.');
                    $file = substr($file, 0, $dot) . '.dev' . substr($file, $dot);
                    $file = trim($file) . '?' . time();
                    break;

                case '?':
                default:
                    $file = trim($file) . ($verFilename != '' ? '?' . $this->_getFile($this->fileRoot . trim($verFilename)) : '');
                    break;

            }
            /** CDN mode */

            if ($proccCdn && array_key_exists('HTMLTemplateSigmaCdnParam', $GLOBALS)) {
                $cdnParams = $GLOBALS['HTMLTemplateSigmaCdnParam'];
                if ($cdnParams['js']['allow'] == true) {
                    foreach ($cdnParams['js']['from'] as $from => $to) {
                        if (substr($file, 0, strlen($from)) == $from) {
                            $file = $to . substr($file, strlen($from));
                            break;
                        }
                    }
                }
            }

            $jsFiles[] = '<script ' . $additional . ' src="' . $file . '" type="text/javascript"></script>';

        }

        return join("\r\n", $jsFiles);

    }

    private function cdnFile($file)
    {
        $proccCdn = true;

        if ($GLOBALS["its_develop"]) {
            $proccCdn = false;
        }

        /** CDN mode */

        if ($proccCdn && array_key_exists('HTMLTemplateSigmaCdnParam', $GLOBALS)) {
            $cdnParams = $GLOBALS['HTMLTemplateSigmaCdnParam'];
            if (array_key_exists('files', $cdnParams)) {
                if ($cdnParams['files']['allow'] == true) {
                    foreach ($cdnParams['files']['from'] as $from => $to) {
                        if (substr($file, 0, strlen($from)) == $from) {
                            $file = $to . substr($file, strlen($from));
                            break;
                        }
                    }
                }
            }
        }

        return $file;

    }

    private function jsAddFile($filenames, $verFilename = '', $primary = false, $additional = '')
    {
        $typeVersion = '?';

        if (strpos($verFilename, '|') !== false) {
            $ar = explode('|', $verFilename);
            $verFilename = $ar[0];
            $typeVersion = $ar[1];
        }
        $proccCdn = true;


        list($files, $debug_files) = explode('|', $filenames, 2);

        if (null !== $debug_files && $GLOBALS["its_develop"]) {
            $files = explode(' ', $debug_files);
            $typeVersion = 'debug';
        } else {
            $files = [$files];
        }
        if ($GLOBALS["its_develop"]) {
            $proccCdn = false;
        }

        foreach ($files as $file) {


            switch ($typeVersion) {
                case 'v':
                    $dot = strrpos($file, '.');
                    $file = substr($file, 0, $dot) . '.v' . $this->_getFile($this->fileRoot . trim($verFilename)) . substr($file, $dot);
                    break;

                case 'debug':
                    $file = trim($file) . '?' . time();
                    break;

                case 'dev':
                    $dot = strrpos($file, '.');
                    $file = substr($file, 0, $dot) . '.dev' . substr($file, $dot);
                    $file = trim($file) . '?' . time();
                    break;

                case '?':
                default:
                    $file = trim($file) . ($verFilename != '' ? '?' . $this->_getFile($this->fileRoot . trim($verFilename)) : '');
                    break;

            }

            /** CDN mode */

            if ($proccCdn && array_key_exists('HTMLTemplateSigmaCdnParam', $GLOBALS)) {
                $cdnParams = $GLOBALS['HTMLTemplateSigmaCdnParam'];
                if ($cdnParams['js']['allow'] == true) {
                    foreach ($cdnParams['js']['from'] as $from => $to) {
                        if (strpos($file, $from) === 0) {
                            $file = str_replace($from, $to, $file);
                            break;
                        }
                    }
                }
            }

            if ($primary == true || $primary == 'true') {
                $this->listJsFiles['primary'][] = ['file' => $file, 'additional' => $additional];
            } else {
                $this->listJsFiles['default'][] = ['file' => $file, 'additional' => $additional];
            }
        }

        return '';

    }

    private function jsGetListFiles()
    {
        $return = [];
        $arSummary = [];
        $filesExist = [];
        if (count($this->listJsFiles['primary']) > 0) {
            foreach ($this->listJsFiles['primary'] as $v) {
                if (!in_array($v['file'], $filesExist)) {
                    $filesExist[] = $v['file'];
                    $arSummary[] = $v;
                }
            }
        }
        if (count($this->listJsFiles['default']) > 0) {
            foreach ($this->listJsFiles['default'] as $v) {
                if (!in_array($v['file'], $filesExist)) {
                    $filesExist[] = $v['file'];
                    $arSummary[] = $v;
                }
            }
        }

        if (count($arSummary) > 0) {
            foreach ($arSummary as $v) {
                $return[] = '<script ' . $v['additional'] . ' src="' . $v['file'] . '" type="text/javascript"></script>';
            }
        }

        return join("\r\n", $return);

    }

    private function jsAddReady($js, $fromFile = 0)
    {
        if ($fromFile == 1) {
            $js = $this->_getFile($this->fileRoot . trim($js));
        }
        $return = '';
        if (@$GLOBALS['HTMLTemplateSigmajsReadyMode'] == 'dev') {
            $return = "
<script type='text/javascript'>
	jQuery(document).ready(function($){
	" . str_replace('\"', '"', $js) . "
	});
</script>";

        } else {
            $this->jsReadyString[] = trim(str_replace("\t", '', $js));
        }

        return $return;

    }

    private function jsGetReady()
    {
        $return = [];
        if (count($this->jsReadyString) > 0) {
            $arSummary = array_unique($this->jsReadyString);
            foreach ($arSummary as $jsString) {
                $return = $arSummary;
            }
        }

        return join("\r\n", $return);
    }

    private function debug($var = '')
    {
        if (isset($_GET['debug'])) {
            echo '<pre>';
            var_dump($var);
            echo '</pre>';
        }

    }

    /**
     * @param string $filename
     * @return string
     */
    private function getFileMobileVersion($filename)
    {

        $filenameMobile = str_replace('.html', '.mobile.html', $filename);

        $nameSharedMemory = @$GLOBALS["HTMLTemplateSigmaTplPrf"] . '_' . 'TemplateSigmaExist_' . md5(realpath($filename));

        /* */
        if (@$GLOBALS["HTMLTemplateSigmaTplPrf"] != '' && $this->cache != false) {
            $tmp = $this->cache->get($nameSharedMemory);
            if ($tmp == 'yes') {
                $file_exists = true;

            } elseif ($tmp == 'no') {
                $file_exists = false;
            } else {
                $file_exists = file_exists($this->fileRoot . $filenameMobile);
                ($file_exists == true ? $tmp = 'yes' : $tmp = 'no');
                $this->cache->set($nameSharedMemory, $tmp, 10 * 60);

            }

        } else {
            $file_exists = file_exists($this->fileRoot . $filenameMobile);
        }
        //*/
        $file_exists = file_exists($this->fileRoot . $filenameMobile);

        if (@$GLOBALS["its_mobile"] === true && $file_exists) {
            $filename = $filenameMobile;

        };

        return $filename;
    }
}

