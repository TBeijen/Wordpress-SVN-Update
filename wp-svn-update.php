<?php
/**
 * Wordpress SVN update
 *
 * Class that will find most recent version of wordpress and installed
 * plugins, switch to must recent versions and update using svn.
 */
class WordpressSvnUpdate
{
    /**
     * path given, might be relative from current dir.
     * @var string
     */
    protected $_targetRelative;

    /**
     * absolute path to wordpress dir
     * @var string
     */
    protected $_targetAbsolute;

    /**
     * If needing to skip confirmation
     * @var boolean
     */
    protected $_optForce = false;

    /**
     * Working dir when script was called
     * @var string
     */
    protected $_callingWd;

    /**
     * Will be set to true upon error;
     * @var boolean
     */
    protected $_hasError = false;

    /**
     *Current wordpress svn url
     * @var string
     */
    protected $_wpUrlCurrent;

    /**
     * Will parse options and run update
     */
    public function runCli()
    {
        $this->_parseOptions();
        
        // set working dir
        $this->_setWd();
        if ($this->_hasError) {
            $this->_exit();
        }

        // extract svn inf
        $this->_parseSvn();
        if ($this->_hasError) {
            $this->_exit();
        }
    }

    /**
     * Extracts command line arguments
     */
    protected function _parseOptions()
    {
        $argsToParse = $_SERVER['argv'];
        array_shift($argsToParse);

        // extract target path
        foreach($argsToParse as $arg) {
            if (strlen($arg) > 2 || $arg[0] != '-') {
                $this->_targetRelative = $arg;
                break;
            }
        }

        // determine absolute target
        if ($this->_targetRelative[0] == '/') {
            $this->_targetAbsolute = $this->_targetRelative;
        } else {
            $this->_targetAbsolute = realpath(getcwd() . '/' . $this->_targetRelative);
        }

        // extract options
        $opts = getopt('f');
        $this->_optForce = array_key_exists('f', $opts);
    }

    /**
     * Will change working dir to target wordpress location
     */
    protected function _setWd()
    {
        if (!$this->_targetAbsolute) {
            $this->_showError('Cannot change to directory: ' . $this->_targetRelative);
            return;
        }
        if (!chdir($this->_targetAbsolute)) {
            $this->_showError('Cannot change to directory: ' . $this->_targetAbsolute);
            return;
        }
    }

    /**
     * Shows error message
     * @param string $errorMsg
     */
    protected function _showError($errorMsg)
    {
        $this->_hasError = true;
        echo 'ERROR: ' . $errorMsg . PHP_EOL;
    }

    /**
     * Shows error message
     * @param string $errorMsg
     */
    protected function _exit()
    {
        echo '(exiting...)' . PHP_EOL;
        exit();
    }

    /**
     * Will extract current version of wordpress using svn info
     */
    protected function _parseSvn()
    {
        $cmd = 'svn info 1>&1';
        $svnOutput = shell_exec($cmd);
        $svnLines = explode("\n", $svnOutput);

        // extract current url
        foreach ($svnLines as $line) {
            if (strpos($line, 'URL: ') !== false) {
                $this->_wpUrlCurrent = substr($line, 5);
                break;
            }
        }

        // exit if not finding svn info
        if (!$this->_wpUrlCurrent) {
            $this->_showError('Cannot parse svn repository info');
            $this->_hasError = true;
        }
    }
}

// create instance and run
$wpSvnUpdate = new WordpressSvnUpdate();
$wpSvnUpdate->runCli();
