<?php
/**
 * Wordpress SVN update
 *
 * Class that will find most recent version of wordpress and installed
 * plugins, switch to must recent versions and update using svn.
 *
 * TODO/ideas:
 *  - display feedback while collecting info
 *  - write log, or save backup file holding previous version, offering rollback
 *  - ...
 *
 * Copyright (c) 2011 Tibo Beijen
 *
 * Dual licensed under the MIT and GPL licenses:
 *   http://www.opensource.org/licenses/mit-license.php
 *   http://www.gnu.org/licenses/gpl.html
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
     * Array holding svnupdate info and the command to execute
     * @var array
     */
    protected $_updateInfoWp;

    /**
     * Array holding info of plugin externals that will possibly be updated
     * @var array
     */
    protected $_updateInfoPlugins;

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

        // collect update info
        $this->_configureWpSwitchCommand();
        $this->_configurePluginUpdates();

        // print info and execute
        $this->_exec();
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

    /**
     * Will determine command to execute for updating wordpress
     * and store the info in $this->_updateInfoWp
     */
    protected function _configureWpSwitchCommand()
    {
        $updateInfo = $this->_svnFindNewest($this->_wpUrlCurrent);
        $cmd = null;
        if ($updateInfo['isUpdate']) {
            $cmd = 'svn sw ' . $updateInfo['newUrl'];
        }
        $updateInfo['cmd'] = $cmd;

        $this->_updateInfoWp = $updateInfo;
    }


    /**
     * Will gather update info of plugin externals
     * and store the info in $this->_updateInfoPlugins
     */
    protected function _configurePluginUpdates()
    {
        $cmd = 'svn propget svn:externals wp-content/plugins';
        exec($cmd, $currentExternals);

        $this->_updateInfoPlugins = array();
        foreach ($currentExternals as $external) {
            if (trim($external) == '') {
                continue;
            }

            $pattern = '/(.+?)(\s+\-r\s*\d+)?\s+(.*)$/i';
            $matchCount = preg_match($pattern, $external, $matches);

            // extract name, url and optional revision from line
            $name = $matches[1];
            $revision = trim($matches[2]);
            $currentUrl = $matches[3];
            
            // fetch info about available externals
            $externalInfo = $this->_svnFindNewest($currentUrl, true);

            // construct line for new externals config
            $newExternalsLine = sprintf('%s%s %s', $name, $matches[2],
                $externalInfo['newUrl']);

            // add additional info to retrieved external info
            $externalInfo['revision'] = $revision;
            $externalInfo['newExternalsLine'] = $newExternalsLine;

            $this->_updateInfoPlugins[$name] = $externalInfo;
        }
    }

    /**
     * Execute gathered changes
     */
    protected function _exec()
    {
        $wpUpdate = false;
        echo 'Changes to be performed' . PHP_EOL;
        echo '=======================' . PHP_EOL;

        // Wordpress svn switch
        echo "Wordpress:" . PHP_EOL;

        if ($this->_updateInfoWp['isUpdate']) {
            printf("\t%s -> %s%s", $this->_updateInfoWp['currentVersion'],
                $this->_updateInfoWp['newVersion'], PHP_EOL);
            $wpUpdate = true;
        } else {
            echo "\t" . 'No change, current version = ' .
                $this->_updateInfoWp['currentVersion'] . ')' . PHP_EOL;
        }

        // Externals to be updated
        $newExternalsContent = '';
        $externalsUpdate = false;

        echo "Plugins (externals):" . PHP_EOL;
        foreach ($this->_updateInfoPlugins as $name => $info) {
            if ($info['isUpdate'] && !trim($info['revision'])) {
                printf("\t%s: %s -> %s%s", $name, $info['currentVersion'],
                    $info['newVersion'], PHP_EOL);
                $externalsUpdate = true;
            } else {
                printf("\t%s: No change, current version = %s %s%s",
                    $name, $info['currentVersion'], $info['revision'], PHP_EOL);
            }
            $newExternalsContent .= $info['newExternalsLine'] . PHP_EOL;
        }

        // ask for confirmation if no -f switch
        if (!$this->_optForce && ($wpUpdate || $externalsUpdate)) {
            echo PHP_EOL;

            while (true) {
                echo 'Type "y" to confirm' . PHP_EOL;
                $answer = $this->_askUserInput();
                if (strtolower($answer) == 'y') {
                    break;
                }
            }
        }

        // unless no externals
        if ($externalsUpdate) {
            // create temp. file holding new externals data
            $tmpFile = tempnam(sys_get_temp_dir(), 'wp-svn-update-');
            file_put_contents($tmpFile, $newExternalsContent);
            $cmd = 'svn propset svn:externals wp-content/plugins -F ' . $tmpFile;
            passthru($cmd);

            // remove temp. file
            unlink($tmpFile);
        }

        // exec wp switch command, or svn update to update externals
        if ($wpUpdate) {
            passthru($this->_updateInfoWp['cmd']);
        } elseif ($externalsUpdate) {
            $cmd = 'svn up';
            passthru($cmd);
        }

        $this->_exit();
    }

    /**
     * Parses currentUrl and returns array holding keys:
     *   currentUrl
     *   currentVersion
     *   newUrl
     *   newVersion
     *   isUpdate
     *   isParseError
     * @param string $currentUrl
     * @param boolean $isPlugin
     * @return array
     */
    protected function _svnFindNewest($currentUrl, $isPlugin = false)
    {
        $pluginPatternPart = ($isPlugin) ? '\/.+?' : '';

        // current url can be either trunk or tag
        $patternTrunk = '/http.+?' . $pluginPatternPart . '\/trunk\/?/';
        $matchCountTrunk = preg_match($patternTrunk, $currentUrl, $matchesTrunk);

        if ($matchCountTrunk) {
            $return = array(
                'currentUrl' => $currentUrl,
                'currentVersion' => 'trunk',
                'newUrl' => $currentUrl,
                'newVersion' => 'trunk',
                'isUpdate' => true,
                'isParseError' => false
            );
            return $return;
        }

        // determine current version (tag) and retrieve newest
        $patternTag = '/http.+?' . $pluginPatternPart . '\/tags\/(.+\/?)$/';
        $matchCountTag = preg_match($patternTag, $currentUrl, $matchesTag);
        
        if ($matchCountTag) {
            $baseUrl = str_replace($matchesTag[1], '', $matchesTag[0]);
            $cmd = 'svn ls ' . $baseUrl;
            $listOutput = shell_exec($cmd);
            $listLines = explode("\n", trim($listOutput));
            
            $currentVersion = trim($matchesTag[1], '/');
            $newVersion = trim(array_pop($listLines), '/');
            $newUrl = $baseUrl . $newVersion . '/';
            $isUpdate = (version_compare($newVersion, $currentVersion) == 1);

            $return = array(
                'currentUrl' => $currentUrl,
                'currentVersion' => $currentVersion,
                'newUrl' => $newUrl,
                'newVersion' => $newVersion,
                'isUpdate' => $isUpdate,
                'isParseError' => false
            );
            return $return;
        }

        // not finding trunk or tag -> error
        $this->_showError('Cannot determine newest version of current svn url: ' . $currentUrl);

        // return array stating error
        $return = array(
            'currentUrl' => $currentUrl,
            'currentVersion' => null,
            'newUrl' => $currentUrl,
            'newVersion' => null,
            'isUpdate' => false,
            'isParseError' => true
        );
        return $return;
    }

    /**
     * Waits for user input and returns entered value
     * @return string
     */
    protected function _askUserInput()
    {
        $in = fopen('php://stdin', 'r');
        $answer = trim(fgets($in));
        fclose($in);

        return $answer;
    }
}

// create instance and run
$wpSvnUpdate = new WordpressSvnUpdate();
$wpSvnUpdate->runCli();
