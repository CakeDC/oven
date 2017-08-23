<?php
/**
 * Copyright 2017, Cake Development Corporation (https://www.cakedc.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2017, Cake Development Corporation (https://www.cakedc.com)
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

session_start() ;

if (!ini_get('safe_mode')) {
    set_time_limit(600);
}

ini_set('memory_limit', '512M');

class Oven {

    public $installDir;
    public $currentDir;
    public $composerHomeDir;
    public $composerFilename;
    public $composerPath;
    public $appDir = 'app';
    public $versions = [
        '~3.5.0' => '~3.5.0',
        '~3.4.0' => '~3.4.0',
    ];

    const DATASOURCE_REGEX = "/(\'Datasources'\s\=\>\s\[\n\s*\'default\'\s\=\>\s\[\n\X*\'__FIELD__\'\s\=\>\s\').*(\'\,)(?=\X*\'test\'\s\=\>\s)/";
    const REQUIREMENTS_DELAY = 500000;
    const DIR_MODE = 0777;
    const VERSIONS_SESSION_KEY = 'cached_versions';
    const MIXER_PACKAGE = 'CakeDC/Mixer';
    const MIXER_VERSION = '@stable';

    public function __construct()
    {
        $this->currentDir = __DIR__ . DIRECTORY_SEPARATOR;

        if (isset($_POST['dir'])) {
            $this->appDir = $_POST['dir'];
        }

        $this->composerHomeDir = $this->currentDir . '.composer';
        $this->composerFilename = 'composer.phar';
        if (!$this->composerPath = $this->_getComposerPathFromQuery()) {
            $this->composerPath = $this->currentDir . $this->composerFilename;
        }

        $this->installDir = $this->currentDir . $this->appDir;

        $this->versions = $this->_getAvailableVersions();
    }

    public function run()
    {
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
            $action = '_run' . ucfirst($_POST['action']);
            if (method_exists($this, $action)) {
                header('Content-Type: application/json');
                $result = $this->$action() + ['success' => 1];
                echo json_encode($result);

                exit(0);
            }
        }
    }

    public function getComposerSystemPath()
    {
        $paths = explode(':', getenv('PATH'));
        foreach ($paths as $path) {
            $composerPath = $path . DIRECTORY_SEPARATOR . $this->composerFilename;
            if (is_readable($composerPath)) {
                return $composerPath;
            }

            $composerBinaryBath = $path . DIRECTORY_SEPARATOR . pathinfo($this->composerFilename, PATHINFO_FILENAME);
            if (is_executable($composerBinaryBath)) {
                return $composerBinaryBath;
            }
        }

        return false;
    }

    protected function _getAvailableVersions()
    {
        if (isset($_SESSION[self::VERSIONS_SESSION_KEY ]) && is_array($_SESSION[self::VERSIONS_SESSION_KEY ])) {
            return $_SESSION[self::VERSIONS_SESSION_KEY ];
        }

        if (!$package = json_decode(file_get_contents('https://packagist.org/packages/cakephp/cakephp.json'), true)) {
            return $this->versions;
        }

        if (!isset($package['package']['versions'])) {
            return $this->versions;
        }

        $tags = array_keys($package['package']['versions']);

        $versions = [];
        $branches = ['4.0.', '3.5.', '3.4.'];
        foreach ($branches as $branch) {
            if ($version = $this->_getLatestVersion($tags, $branch)) {
                $versions['~' . $version] = $version;
            }
        }

        return $_SESSION[self::VERSIONS_SESSION_KEY ] = $versions;
    }

    protected function _getLatestVersion($versions, $branch)
    {
        foreach ($versions as $version) {
            if (strpos($version, $branch) === 0) {
                return $version;
            }
        }

        return false;
    }

    protected  function _getComposerVersion()
    {
        return $this->_runComposer([
            '--version' => true,
        ]);
    }

    protected  function _getComposerPathFromQuery()
    {
        $var = 'composerPath';
        if (!isset($_POST[$var]) || empty($_POST[$var])) {
            return false;
        }

        if (!is_readable($path = $_POST[$var])) {
            throw new Exception("Composer installation not found at {$path}");
        }

        if (substr($path, -5) != '.phar' && !is_executable($path)) {
            throw new Exception("Composer binary is not executable");
        }

        return $path;
    }

    protected function _updateDatasourceConfig($path, $field, $value)
    {
        $config = file_get_contents($path);
        $config = preg_replace(str_replace('__FIELD__', $field, Oven::DATASOURCE_REGEX), '${1}' . $value . '${2}', $config);

        return file_put_contents($path, $config);
    }

    protected function _enablePlugin($path, $plugin, $bootstrap = true, $routes = false, $debugOnly = false)
    {
        $config = file_get_contents($path);

        $bootstrap = $bootstrap
            ? 'true'
            : 'false';

        $routes = $routes
            ? 'true'
            : 'false';

        $line = "Plugin::load('{$plugin}', ['bootstrap' => {$bootstrap}, 'routes' => $routes]);";

        if ($debugOnly) {
            $line = "if (Configure::read('debug')) {\n    {$line}\n}";
        }

        $config .= "\n\n{$line}";

        return file_put_contents($path, $config);
    }

    protected function _runCheckPhp()
    {
        usleep(self::REQUIREMENTS_DELAY);
        if (!version_compare(PHP_VERSION, '5.5.9', '>=')) {
            throw new Exception('Your version of PHP is too low. You need PHP 5.5.9 or higher (detected ' . PHP_VERSION . ').');
        }

        return ['message' => 'Your version of PHP is 5.5.9 or higher (detected ' . PHP_VERSION . ').'];
    }

    protected function _runCheckMbString()
    {
        usleep(self::REQUIREMENTS_DELAY);
        if (!extension_loaded('mbstring')) {
            throw new Exception('Your version of PHP does NOT have the mbstring extension loaded.');
        }

        return ['message' => 'Your version of PHP has the mbstring extension loaded.'];
    }

    protected function _runCheckOpenSSL()
    {
        usleep(self::REQUIREMENTS_DELAY);
        if (extension_loaded('openssl')) {
            return ['message' => 'Your version of PHP has the openssl extension loaded.'];
        } elseif (extension_loaded('mcrypt')) {
            return ['message' => 'Your version of PHP has the mcrypt extension loaded.'];
        }

        throw new Exception('Your version of PHP does NOT have the openssl or mcrypt extension loaded.');
    }

    protected function _runCheckIntl()
    {
        usleep(self::REQUIREMENTS_DELAY);
        if (!extension_loaded('intl')) {
            throw new Exception('Your version of PHP does NOT have the intl extension loaded.');
        }

        return ['message' => 'Your version of PHP has the intl extension loaded.'];
    }

    protected function _runCheckPath()
    {
        usleep(self::REQUIREMENTS_DELAY);

        $this->_checkPath($this->installDir);

        return ['message' => $this->installDir . ' directory is writable'];
    }

    protected function _checkPath($path)
    {
        if (file_exists($path)) {
            if (!is_dir($path)) {
                throw new Exception("{$path} is not a directory");
            }

            if (!is_writable($path)) {
                throw new Exception("{$path} directory is NOT writable");
            }
        } elseif (!is_writable(dirname($path))) {
            throw new Exception(dirname($path) . ' directory is NOT writable');
        }
    }

    protected function _runFinalise()
    {
        $this->_checkPath($this->installDir);
        $this->_restoreScripts();

        $log = $this->_runComposer([
            'command' => 'dump-autoload',
            '--no-interaction' => true,
            '--working-dir' => $this->installDir,
        ]);

        $log .= "\n";

        $log .= $this->_runComposer([
            'command' => 'run-script',
            'script' => 'post-install-cmd',
            '--no-interaction' => true,
            '--working-dir' => $this->installDir,
        ]);

        $configPath = $this->installDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        foreach (['host', 'username', 'password', 'database'] as $field) {
            if (isset($_POST[$field]) && !empty($_POST[$field])) {
                $this->_updateDatasourceConfig($configPath, $field, $_POST[$field]);
            }
        }

        if (isset($_POST['installMixer']) && $_POST['installMixer']) {
            $bootstrapPath = $this->installDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bootstrap.php';
            $this->_enablePlugin($bootstrapPath, self::MIXER_PACKAGE, true, true, true);
        }

        $message = 'Finalised!';

        return compact('log', 'message');
    }

    protected function _runInstallComposer()
    {
        $result = [];
        if (!is_readable($this->composerPath) || !($version = $this->_getComposerVersion())) {
            $result['log'] = $this->_installComposer($this->currentDir, $this->composerFilename);
            $version = $this->_getComposerVersion();
        } else {
            usleep(self::REQUIREMENTS_DELAY);
            $result['log'] = $version;
        }

        if (strpos($version, 'Composer') === false && strpos($version, 'version') === false) {
            throw new Exception('Invalid composer installation');
        }

        $result['message'] = $version;

        return $result;
    }

    protected function _runCreateProject()
    {
        if ($this->_isCakeInstalled($this->installDir)) {
            throw new Exception('CakePHP app already installed');
        }

        $this->_checkPath($this->installDir);

        if (file_exists($this->installDir) && $this->installDir != $this->currentDir && !$this->_isDirEmpty($this->installDir)) {
            throw new Exception("{$this->installDir} is not empty");
        }
        if (!file_exists($this->installDir) && !mkdir($this->installDir, self::DIR_MODE, true)) {
            throw new Exception("Could NOT create {$this->installDir} directory");
        }

        if (strpos(realpath($this->installDir), realpath($this->currentDir)) !== 0) {
            throw new Exception('Invalid app dir ' . $this->installDir);
        }

        if (!isset($_POST['version']) || !isset($this->versions[$_POST['version']])) {
            throw new Exception('Invalid CakePHP version. Available versions: ' . implode(', ', $this->versions));
        }
        $cakeVersion = $_POST['version'];

        $log = $this->_createProject($this->installDir, $cakeVersion, false);

        $dir = $this->appDir;

        if (strpos($log, 'Created project in ' . $this->installDir) === false) {
            throw new Exception('Error while creating project');
        }

        $this->_backupComposerJson();
        $this->_clearDependencies();
        $dependencies = $this->_getDependencies();

        $composerPath = (string)$this->_getComposerPathFromQuery();

        $steps = [];
        foreach ($dependencies as $req => $packages) {
            foreach ($packages as $package => $version) {
                if ($package == 'cakephp/cakephp') {
                    $version = $cakeVersion;
                }

                $action = 'installPackage';
                $dev = ($req == 'require-dev') ? 1 : 0;
                $steps[] = [
                    'title' => $package == 'php' ? "Requiring platform {$package}:{$version}..." : "Installing {$package}:{$version}...",
                    'data' => compact('action', 'package', 'version', 'dev', 'dir', 'composerPath')
                ];
            }
        }

        if (isset($_POST['installMixer']) && $_POST['installMixer']) {
            $package = strtolower(self::MIXER_PACKAGE);
            $version = self::MIXER_VERSION;
            $action = 'installPackage';
            $dev = 1;

            $steps[] = [
                'title' => "Installing {$package}:{$version}...",
                'data' => compact('action', 'package', 'version', 'dev', 'dir', 'composerPath')
            ];
        }

        return [
            'message' => 'CakePHP project created',
            'log' => $log,
            'steps' => $steps
        ];
    }

    protected function _runInstallPackage()
    {
        $package = $_POST['package'];
        $version = $_POST['version'];
        $dev = (isset($_POST['dev']) && $_POST['dev']);

        $this->_checkPath($this->installDir);

        return [
            'message' => "{$package}:{$version} installed",
            'log' => $this->_installPackage($package, $version, $dev, $this->installDir)
        ];
    }

    protected function _installComposer($dir, $filename)
    {
        putenv("COMPOSER_HOME={$this->composerHomeDir}");
        putenv("OSTYPE=OS400");

        $composerSetupFilename = 'composer-setup.php';
        copy('https://getcomposer.org/installer', $composerSetupFilename);

        $expectedSignature = trim(file_get_contents('https://composer.github.io/installer.sig'));
        if (!hash_file('SHA384', $composerSetupFilename) === $expectedSignature) {
            unlink($composerSetupFilename);
            throw new Exception('Composer Installer corrupt');
        }

        // Modify composer setup script not to exit
        $composerSetup = file_get_contents('https://getcomposer.org/installer');
        $composerSetup = str_replace('exit(0);', 'return;', $composerSetup);
        $composerSetup = str_replace('exit(1);', 'throw new Exception("Error setting up composer");', $composerSetup);
        $composerSetup = str_replace('exit($ok ? 0 : 1);', 'if ($ok) return; else throw new Exception("Error setting up composer");', $composerSetup);
        file_put_contents($composerSetupFilename, $composerSetup);

        ob_start();
        ini_set('register_argc_argv', 0);
        $argv = [
            "--install-dir={$dir}",
            "--filename={$filename}",
        ];
        require($composerSetupFilename);
        $result = ob_get_clean();

        unlink($composerSetupFilename);

        if (strpos($result, 'successfully installed to: ' . $dir . $filename) === false) {
            throw new Exception('Error while installing composer');
        }

        return $result;
    }

    protected function _isCakeInstalled($dir)
    {
        return file_exists($dir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'cakephp' . DIRECTORY_SEPARATOR . 'cakephp' . DIRECTORY_SEPARATOR . 'VERSION.txt');
    }

    protected function _runComposer($input)
    {
        putenv("OSTYPE=OS400");
        if (!getenv('COMPOSER_HOME')) {
            putenv("COMPOSER_HOME={$this->composerHomeDir}");
        }

        if (substr($this->composerPath, -5) == '.phar') {
            require_once "phar://{$this->composerPath}/src/bootstrap.php";

            $input = new \Symfony\Component\Console\Input\ArrayInput($input);
            $output = new \Symfony\Component\Console\Output\BufferedOutput();

            $application = new \Composer\Console\Application();
            $application->setAutoExit(false);
            $application->run($input, $output);

            return $output->fetch();
        } else {
            $command = $this->_buildComposerCommand($this->composerPath, $input);

            ob_start();
            passthru($command);

            return ob_get_clean();
        }
    }

    protected function _buildComposerCommand($path, $input)
    {
        $command = [escapeshellcmd($path)];

        foreach ($input as $k => $v) {
            if (substr($k, 0, 2) == '--') {
                $command[] = escapeshellcmd($k . ($v === true ? '' : "={$v}"));
            } elseif (is_array($v)) {
                $command[] = escapeshellcmd(implode(' ', $v));
            } else {
                $command[] = escapeshellcmd($v);
            }
        }

        return implode(' ', $command);
    }

    protected function _createProject($dir, $cakeVersion = false, $install = false)
    {
        $tmpDir = false;
        if (!$this->_isDirEmpty($dir)) {
            $tmpDir = __DIR__ . DIRECTORY_SEPARATOR . uniqid();
        }

        $package = 'cakephp/app';
        if ($cakeVersion) {
            $appVersion = explode('.', $cakeVersion);
            array_pop($appVersion);
            $appVersion[] = 0;
            $appVersion = implode('.', $appVersion);
            $package .= ":{$appVersion}";
        }

        $input = [
            'command' => 'create-project',
            '--no-interaction' => true,
            '--prefer-dist' => true,
            'package' => $package,
            'directory' => $tmpDir ? $tmpDir : $dir,
        ];

        if (!$install) {
            $input += [
                '--no-install' => true,
                '--no-scripts' => true,
            ];
        }

        $output = $this->_runComposer($input);

        if ($tmpDir) {
            $this->_moveDir($tmpDir, $dir);
        }

        return $output;
    }

    protected function _isDirEmpty($dir)
    {
        if (!is_readable($dir)) {
            throw new Exception("{$dir} is NOT readable");
        }

        $handle = opendir($dir);
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                return false;
            }
        }

        return true;
    }

    /**
     * A Recursive directory move.
     *
     * @param string $src The fully qualified source directory to copy
     * @param string $dest The fully qualified destination directory to copy to
     * @throws InvalidArgumentException
     * @throws ErrorException
     * @return boolean                    Returns TRUE on success, throws an error otherwise.
     */
    protected function _moveDir($src, $dest)
    {
        if (!is_dir($src)) {
            throw new InvalidArgumentException('The source passed in does not appear to be a valid directory: [' . $src . ']', 1);
        }

        if (!is_dir($dest) && !mkdir($dest, self::DIR_MODE, true)) {
            throw new InvalidArgumentException('The destination does not exist, and I can not create it: [' . $dest . ']', 2);
        }

        $emptiedDirs = array();

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $relativePath = str_replace($src, '', $f->getRealPath());
            $destination = $dest . $relativePath;

            if ($f->isFile()) {
                $path_parts = pathinfo($destination);

                if (!is_dir($path_parts['dirname']) && !mkdir($path_parts['dirname'], self::DIR_MODE, true)) {
                    throw new ErrorException("Failed to create the destination directory: [{$path_parts['dirname']}]", 5);
                }

                if (!rename($f->getRealPath(), $destination)) {
                    throw new ErrorException("Failed to rename file [{$f->getRealPath()}] to [$destination]", 6);
                }
            } elseif ($f->isDir()) {
                if (!is_dir($destination) && !mkdir($destination, self::DIR_MODE, true)) {
                    throw new ErrorException("Failed to create the destination directory: [$destination]", 7);
                }

                array_push($emptiedDirs, $f->getRealPath());
            }
        }

        foreach ($emptiedDirs as $emptyDir) {
            if (realpath($emptyDir) == realpath($src)) {
                continue;
            }

            if (!is_readable($emptyDir)) {
                throw new ErrorException("The source directory {$emptyDir} is NOT readable", 9);
            }

            if (!rmdir($emptyDir)) {
                throw new ErrorException("Failed to delete the source director {$emptyDir}", 10);
            }
        }

        // Finally, delete the base of the source directory we just recursed through
        if (!rmdir($src)) {
            throw new ErrorException("Failed to delete the base source directory: {$src}", 11);
        }

        return true;
    }

    protected function _installPackage($package, $version, $dev, $dir)
    {
        $allowedPackages = $this->_getDependencies();
        $allowedPackages = $allowedPackages['require'] + $allowedPackages['require-dev'] + [strtolower(self::MIXER_PACKAGE) => self::MIXER_VERSION];

        if (!array_key_exists($package, $allowedPackages)) {
            throw new Exception("{$package} package is not allowed");
        }

        $input = [
            'command' => 'require',
            '--prefer-dist' => true,
            '--no-interaction' => true,
            '--working-dir' => $dir,
            '--no-progress' => true,
            'packages' => [$package . ($version ? ':' . $version : '')],
        ];

        if ($dev) {
            $input['--dev'] = true;
        }

        $output = $this->_runComposer($input);

        if (strpos($output, 'Generating autoload files') === false) {
            throw new Exception("Error installing package {$package}");
        }

        return $output;
    }

    protected function _clearDependencies()
    {
        $this->_backupComposerJson();
        $json = $this->_openComposerJson();
        unset($json['require']);
        unset($json['require-dev']);
        unset($json['scripts']['post-install-cmd']);
        unset($json['scripts']['post-create-project-cmd']);
        $this->_saveComposerJson($json);
    }

    protected function _backupComposerJson()
    {
        return copy($this->installDir . DIRECTORY_SEPARATOR . 'composer.json', $this->installDir . DIRECTORY_SEPARATOR . 'composer.json.bak');
    }

    protected function _openComposerJson($composerFile = null)
    {
        if (!$composerFile) {
            $composerFile = 'composer.json';
        }
        $composerFile = $this->installDir . DIRECTORY_SEPARATOR . $composerFile;

        return json_decode(file_get_contents($composerFile), true);
    }

    protected function _saveComposerJson($json)
    {
        $composerFile = $this->installDir . DIRECTORY_SEPARATOR . 'composer.json';
        file_put_contents($composerFile, json_encode($json, JSON_PRETTY_PRINT));
    }

    protected function _restoreScripts()
    {
        $composerFileBackup = $this->_openComposerJson('composer.json.bak');
        $composerFile = $this->_openComposerJson();

        $composerFile['scripts'] = $composerFileBackup['scripts'];
        $this->_saveComposerJson($composerFile);

        unlink($this->installDir . DIRECTORY_SEPARATOR . 'composer.json.bak');
    }

    protected function _getDependencies()
    {
        $composerFile = json_decode(file_get_contents($this->installDir . DIRECTORY_SEPARATOR . 'composer.json.bak'), true);

        unset($composerFile['require']['php']);

        return [
            'require' => $composerFile['require'],
            'require-dev' => $composerFile['require-dev'],
        ];
    }

    protected function _runCheckDatabaseConnection()
    {
        if (!$this->_checkDriverEnabled()) {
            throw new Exception('Mysql driver is not available');
        }

        if (!isset($_POST['host']) || empty($_POST['host'])) {
            throw new Exception('Missing database host');
        }
        if (!isset($_POST['database']) || empty($_POST['database'])) {
            throw new Exception('Missing database name');
        }
        if (!isset($_POST['username']) || empty($_POST['username'])) {
            throw new Exception('Missing database username');
        }

        $host = filter_input(INPUT_POST, 'host', FILTER_SANITIZE_SPECIAL_CHARS);
        $database = filter_input(INPUT_POST, 'database', FILTER_SANITIZE_SPECIAL_CHARS);
        $userName = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_SPECIAL_CHARS);
        $password = '';
        if (isset($_POST['password'])) {
            $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_SPECIAL_CHARS);
        }

        $dsn = "mysql:dbname={$database};host={$host}";
        try {
            $connection = new PDO($dsn, $userName, $password);
        } catch (PDOException $e) {
            throw $e;
        }

        return ['message' => 'Successfully connected to the database.'];
    }

    /**
     * Check the database driver is available
     *
     * @param string $driver driver name
     * @return bool
     */
    protected function _checkDriverEnabled($driver = 'mysql')
    {
        return in_array($driver, PDO::getAvailableDrivers());
    }
}

try {
    $oven = new Oven();
    $oven->run();
} catch (Exception $e) {
    echo json_encode([
        'success' => 0,
        'message' => htmlentities($e->getMessage())
    ]);

    exit(0);
}

$svgs = [
    'knob' => 'data:image/svg+xml;base64,PHN2ZyB2ZXJzaW9uPSIxLjEiIGlkPSJMYXllcl8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCINCiAgICAgICAgICAgICAgICAgd2lkdGg9IjE2NS43MjNweCIgaGVpZ2h0PSIxNjUuNzI3cHgiIHZpZXdCb3g9IjAgMCAxNjUuNzIzIDE2NS43MjciIGVuYWJsZS1iYWNrZ3JvdW5kPSJuZXcgMCAwIDE2NS43MjMgMTY1LjcyNyINCiAgICAgICAgICAgICAgICAgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+DQogICAgICAgICAgICA8cGF0aCBmaWxsPSIjRDMzRDQ0IiBkPSJNMTM1LjUzNSwzMC4xODJjLTI5LjA5Mi0yOS4wOS03Ni4yNjQtMjkuMDktMTA1LjM0OCwwYy0yOS4xLDI5LjA5Mi0yOS4xLDc2LjI2NSwwLDEwNS4zNjUNCiAgICAgICAgICAgICAgICBjMjkuMDgzLDI5LjA5LDc2LjI1NSwyOS4wOSwxMDUuMzQ4LTAuMDExQzE2NC42MzQsMTA2LjQ0NiwxNjQuNjM0LDU5LjI3NCwxMzUuNTM1LDMwLjE4MnogTTExMy4zMjYsMTEzLjMzMQ0KICAgICAgICAgICAgICAgIGMtMTYuODI5LDE2LjgyNS00NC4xMTEsMTYuODE3LTYwLjkzLDBjLTEzLjA1MS0xMy4wNTMtMTUuOTM0LTMyLjM2OS04Ljc0Ny00OC4yMzhsLTUuNjItMjcuMDYxbDI3LjA2Niw1LjYyMQ0KICAgICAgICAgICAgICAgIGMxNS44NzItNy4xODgsMzUuMTc3LTQuMzA1LDQ4LjIyOSw4Ljc0N0MxMzAuMTU2LDY5LjIxOSwxMzAuMTU2LDk2LjUwMSwxMTMuMzI2LDExMy4zMzF6Ii8+DQogICAgICAgICAgICA8L3N2Zz4=',
    'logo' => 'data:image/svg+xml;base64,PHN2ZyB2ZXJzaW9uPSIxLjEiIGlkPSJMYXllcl8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCINCndpZHRoPSIyNDMuOTMzcHgiIGhlaWdodD0iMzIuNTEycHgiIHZpZXdCb3g9IjAgMCAyNDMuOTMzIDMyLjUxMiIgZW5hYmxlLWJhY2tncm91bmQ9Im5ldyAwIDAgMjQzLjkzMyAzMi41MTIiDQp4bWw6c3BhY2U9InByZXNlcnZlIj4NCjxnPg0KPGc+DQo8cGF0aCBmaWxsPSIjRkZGRkZGIiBkPSJNMTQwLjUwMywxNC45MzF2MC45MzloMi4xMDV2MC44ODNoLTIuMTA1djEuNzA3aC0wLjk3MXYtNC40MWgzLjM1N3YwLjg4SDE0MC41MDN6Ii8+DQo8cGF0aCBmaWxsPSIjRkZGRkZGIiBkPSJNMTQ1LjgxMiwxOC41MzZjLTEuMzYxLDAtMi4zMzgtMS4wMTYtMi4zMzgtMi4yNjl2LTAuMDEyYzAtMS4yNTMsMC45OS0yLjI4MSwyLjM1Mi0yLjI4MQ0KYzEuMzU5LDAsMi4zMzYsMS4wMTQsMi4zMzYsMi4yNjh2MC4wMTRDMTQ4LjE2MiwxNy41MDksMTQ3LjE3MywxOC41MzYsMTQ1LjgxMiwxOC41MzZ6IE0xNDcuMTQ2LDE2LjI1NQ0KYzAtMC43NTUtMC41NTMtMS4zODctMS4zMzQtMS4zODdjLTAuNzgzLDAtMS4zMjQsMC42MTctMS4zMjQsMS4zNzN2MC4wMTRjMCwwLjc1NiwwLjU1NSwxLjM4NSwxLjMzOCwxLjM4NQ0KYzAuNzc5LDAsMS4zMi0wLjYxNSwxLjMyLTEuMzczVjE2LjI1NXoiLz4NCjxwYXRoIGZpbGw9IiNGRkZGRkYiIGQ9Ik0xNTEuNzA3LDE4LjQ2bC0wLjk0NS0xLjQxMmgtMC43NjR2MS40MTJoLTAuOTY5di00LjQxaDIuMDE2YzEuMDM5LDAsMS42NjIsMC41NDYsMS42NjIsMS40NTV2MC4wMTINCmMwLDAuNzEzLTAuMzgzLDEuMTYtMC45NDMsMS4zN2wxLjA3OCwxLjU3NEgxNTEuNzA3eiBNMTUxLjcyNCwxNS41NTZjMC0wLjQxNi0wLjI4OS0wLjYzMS0wLjc2Mi0wLjYzMWgtMC45NjV2MS4yNjhoMC45ODQNCmMwLjQ3MywwLDAuNzQyLTAuMjUyLDAuNzQyLTAuNjI1VjE1LjU1NnoiLz4NCjxwYXRoIGZpbGw9IiNGRkZGRkYiIGQ9Ik0xNTMuNDgsMTYuMDkxdi0xLjAwOGgxLjAyMXYxLjAwOEgxNTMuNDh6IE0xNTMuNDgsMTguNDZ2LTEuMDA4aDEuMDIxdjEuMDA4SDE1My40OHoiLz4NCjwvZz4NCjxnPg0KPGc+DQo8cGF0aCBmaWxsPSIjRkZGRkZGIiBkPSJNMTcxLjY4MywxNC41OTdMMTgwLDE2LjY2NWMxLjQ0OS0wLjU2NiwyLjMxOC0xLjI4NywyLjMxOC0yLjA2OHYtMy4zMjJjMC0xLjgzLTQuNzY0LTMuMzE4LTEwLjYzNS0zLjMxOA0KYy01Ljg3NSwwLTEwLjYzNywxLjQ4OC0xMC42MzcsMy4zMTh2My4zMjJjMCwxLjgzNCw0Ljc2MiwzLjMyMSwxMC42MzcsMy4zMjFWMTQuNTk3eiIvPg0KPGc+DQo8cGF0aCBmaWxsPSIjRkZGRkZGIiBkPSJNMTgwLDE5Ljk4NWwtOC4zMTYtMi4wNjh2My4zMkwxODAsMjMuMzA0YzEuNDQ5LTAuNTY2LDIuMzE4LTEuMjg1LDIuMzE4LTIuMDY2YzAtMC43MDUsMC0yLjYxNywwLTMuMzINCkMxODIuMzE4LDE4LjY5OCwxODEuNDQ5LDE5LjQxNywxODAsMTkuOTg1eiIvPg0KPHBhdGggZmlsbD0iI0ZGRkZGRiIgZD0iTTE2MS4wNDYsMTcuOTE3YzAsMC43MDMsMCwyLjYxNSwwLDMuMzJjMCwxLjgzMiw0Ljc2MiwzLjMxOCwxMC42MzcsMy4zMTh2LTMuMzE4DQpDMTY1LjgwOCwyMS4yMzcsMTYxLjA0NiwxOS43NSwxNjEuMDQ2LDE3LjkxN3oiLz4NCjwvZz4NCjwvZz4NCjxnPg0KPHBhdGggZmlsbD0iI0ZGRkZGRiIgZD0iTTE5MS40NDcsMjAuOTMzYy0yLjU5OCwwLTQuNTI1LTIuMDA0LTQuNTI1LTQuNTM4di0wLjAyN2MwLTIuNTA4LDEuODg5LTQuNTYyLDQuNi00LjU2Mg0KYzEuNjY0LDAsMi42NTgsMC41NTUsMy40NzksMS4zNjFsLTEuMjM2LDEuNDI0Yy0wLjY3OC0wLjYxOS0xLjM3My0wLjk5NC0yLjI1NC0wLjk5NGMtMS40ODgsMC0yLjU2MSwxLjIzNC0yLjU2MSwyLjc0NnYwLjAyNQ0KYzAsMS41MTQsMS4wNDcsMi43NzUsMi41NjEsMi43NzVjMS4wMDgsMCwxLjYyNS0wLjQwNCwyLjMxOC0xLjAzNWwxLjIzNiwxLjI0OEMxOTQuMTU2LDIwLjMyNywxOTMuMTQ4LDIwLjkzMywxOTEuNDQ3LDIwLjkzM3oiDQovPg0KPHBhdGggZmlsbD0iI0ZGRkZGRiIgZD0iTTIwMC4zNjksMjAuNzh2LTAuNzNjLTAuNDY1LDAuNTE4LTEuMTA3LDAuODU3LTIuMDQxLDAuODU3Yy0xLjI3MywwLTIuMzItMC43My0yLjMyLTIuMDY2di0wLjAyNQ0KYzAtMS40NzUsMS4xMjEtMi4xNTcsMi43MjEtMi4xNTdjMC42ODQsMCwxLjE3NiwwLjExMywxLjY1NCwwLjI3N3YtMC4xMTNjMC0wLjc5NS0wLjQ5Mi0xLjIzNi0xLjQ1MS0xLjIzNg0KYy0wLjczLDAtMS4yNDgsMC4xMzktMS44NjMsMC4zNjVsLTAuNDgtMS40NjFjMC43NDItMC4zMjgsMS40NzUtMC41NDMsMi42MjMtMC41NDNjMi4wOTIsMCwzLjAxMiwxLjA4NCwzLjAxMiwyLjkxNHYzLjkxOA0KSDIwMC4zNjl6IE0yMDAuNDEsMTguMDU4Yy0wLjMzLTAuMTUtMC43NTgtMC4yNTItMS4yMjctMC4yNTJjLTAuODE2LDAtMS4zMjIsMC4zMjktMS4zMjIsMC45MzR2MC4wMjUNCmMwLDAuNTE0LDAuNDI4LDAuODE4LDEuMDQ3LDAuODE4YzAuODk1LDAsMS41MDItMC40OSwxLjUwMi0xLjE4NlYxOC4wNTh6Ii8+DQo8cGF0aCBmaWxsPSIjRkZGRkZGIiBkPSJNMjA4LjIyNCwyMC43OGwtMS43NjQtMi43NmwtMC42NzIsMC43MDV2Mi4wNTVoLTEuOTE2di05LjIwM2gxLjkxNnY0LjkwN2wyLjI0Ni0yLjQ1OWgyLjI5M2wtMi41NywyLjY2DQpsMi42Niw0LjA5NkgyMDguMjI0eiIvPg0KPHBhdGggZmlsbD0iI0ZGRkZGRiIgZD0iTTIxNy4yOTYsMTguMDcxaC00LjcwMWMwLjE4OSwwLjg3MSwwLjc5MywxLjMyNCwxLjY1LDEuMzI0YzAuNjQ1LDAsMS4xMDktMC4yMDEsMS42MzktMC42OTNsMS4wOTgsMC45NjkNCmMtMC42MzEsMC43ODMtMS41MzksMS4yNjItMi43NjIsMS4yNjJjLTIuMDI3LDAtMy41MjktMS40MjQtMy41MjktMy40OTN2LTAuMDI1YzAtMS45MywxLjM3My0zLjUxOCwzLjM0Mi0zLjUxOA0KYzIuMjU2LDAsMy4yODksMS43NTQsMy4yODksMy42N3YwLjAyM0MyMTcuMzIyLDE3Ljc4LDIxNy4zMTIsMTcuODk2LDIxNy4yOTYsMTguMDcxeiBNMjE0LjAzMywxNS40MzcNCmMtMC43OTUsMC0xLjMxMiwwLjU2Ni0xLjQ2NSwxLjQzNmgyLjg4OUMyMTUuMzQzLDE2LjAxNywyMTQuODM5LDE1LjQzNywyMTQuMDMzLDE1LjQzN3oiLz4NCjxwYXRoIGZpbGw9IiNGRkZGRkYiIGQ9Ik0yMjIuMzY1LDE4LjEzNGgtMS40NzV2Mi42NDZoLTEuOTQzdi04LjgyNGgzLjYwNWMyLjEwNSwwLDMuMzc5LDEuMjQ4LDMuMzc5LDMuMDUzdjAuMDI0DQpDMjI1LjkzMSwxNy4wNzUsMjI0LjM0MywxOC4xMzQsMjIyLjM2NSwxOC4xMzR6IE0yMjMuOTY2LDE1LjA0NmMwLTAuODcxLTAuNjA1LTEuMzM2LTEuNTc2LTEuMzM2aC0xLjV2Mi42OTdoMS41MzcNCmMwLjk2OSwwLDEuNTM5LTAuNTgsMS41MzktMS4zMzZWMTUuMDQ2eiIvPg0KPHBhdGggZmlsbD0iI0ZGRkZGRiIgZD0iTTIzMi44OSwyMC43OHYtMy41NDFoLTMuNTh2My41NDFoLTEuOTQxdi04LjgyNGgxLjk0MXYzLjQ5MmgzLjU4di0zLjQ5MmgxLjkzOXY4LjgyNEgyMzIuODl6Ii8+DQo8cGF0aCBmaWxsPSIjRkZGRkZGIiBkPSJNMjQwLjM2NywxOC4xMzRoLTEuNDc3djIuNjQ2aC0xLjk0MXYtOC44MjRoMy42MDVjMi4xMDUsMCwzLjM3OSwxLjI0OCwzLjM3OSwzLjA1M3YwLjAyNA0KQzI0My45MzMsMTcuMDc1LDI0Mi4zNDUsMTguMTM0LDI0MC4zNjcsMTguMTM0eiBNMjQxLjk2NiwxNS4wNDZjMC0wLjg3MS0wLjYwNS0xLjMzNi0xLjU3Ni0xLjMzNmgtMS41djIuNjk3aDEuNTM3DQpjMC45NzEsMCwxLjUzOS0wLjU4LDEuNTM5LTEuMzM2VjE1LjA0NnoiLz4NCjwvZz4NCjwvZz4NCjxnPg0KPHBhdGggZmlsbD0iI0ZGRkZGRiIgZD0iTTUzLjIwNiwzMi4xOTZoLTYuMTA3TDM0LjM4OSwwLjUzOGg3LjYzNWw4LjIxOSwyMi4xMzlsOC4yMTUtMjIuMTM5aDcuNDU1TDUzLjIwNiwzMi4xOTZ6Ii8+DQo8cGF0aCBmaWxsPSIjRkZGRkZGIiBkPSJNNzAuNzYyLDMxLjk3MlYwLjUzOGgyMy43MTJWNi42OUg3Ny42MzN2Ni4zNzdoMTQuODE4djYuMTUySDc3LjYzM3Y2LjZoMTcuMDY1djYuMTUySDcwLjc2MnoiLz4NCjxwYXRoIGZpbGw9IiNGRkZGRkYiIGQ9Ik0xMjIuOSwzMS45NzJsLTE1LjIyNS0xOS45ODJ2MTkuOTgyaC02LjgyNlYwLjUzOGg2LjM3N2wxNC43MywxOS4zNTVWMC41MzhoNi44MjR2MzEuNDM0SDEyMi45eiIvPg0KPHBhdGggZmlsbD0iI0ZGRkZGRiIgZD0iTTI3Ljc0OSw0Ljc2MWMtNi4zNDgtNi4zNDgtMTYuNjQxLTYuMzQ4LTIyLjk4NiwwYy02LjM1LDYuMzQ4LTYuMzUsMTYuNjQxLDAsMjIuOTkNCmM2LjM0Niw2LjM0OCwxNi42MzksNi4zNDgsMjIuOTg2LTAuMDAxQzM0LjA5OCwyMS40MDEsMzQuMDk4LDExLjEwOCwyNy43NDksNC43NjF6IE0yMi45MDMsMjIuOTA0DQpjLTMuNjcyLDMuNjcxLTkuNjI1LDMuNjY5LTEzLjI5NSwwQzYuNzYsMjAuMDU2LDYuMTMxLDE1Ljg0MSw3LjcsMTIuMzc4TDYuNDczLDYuNDc0TDEyLjM3OSw3LjcNCmMzLjQ2My0xLjU2Nyw3LjY3Ni0wLjkzOSwxMC41MjMsMS45MDlDMjYuNTc1LDEzLjI3OCwyNi41NzUsMTkuMjMxLDIyLjkwMywyMi45MDR6Ii8+DQo8L2c+DQo8L2c+DQo8L3N2Zz4=',
    'go-to-your-app' => 'data:image/svg+xml;base64,PHN2ZyB2ZXJzaW9uPSIxLjEiIGlkPSJMYXllcl8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciDQogICAgICAgICAgICAgICAgICAgICAgICAgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHg9IjBweCIgeT0iMHB4Ig0KICAgICAgICAgICAgICAgICAgICAgICAgIHdpZHRoPSIzMi42MzdweCIgaGVpZ2h0PSIyNS40NjhweCIgdmlld0JveD0iMy45NzQgMy4xMDIgMzIuNjM3IDI1LjQ2OCINCiAgICAgICAgICAgICAgICAgICAgICAgICBlbmFibGUtYmFja2dyb3VuZD0ibmV3IDMuOTc0IDMuMTAyIDMyLjYzNyAyNS40NjgiDQogICAgICAgICAgICAgICAgICAgICAgICAgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+DQogICAgICAgICAgICA8Zz4NCiAgICAgICAgICAgICAgICA8cGF0aCBmaWxsPSIjRkZGRkZGIiBkPSJNMjAuMjk0LDEzLjI5MmwxMi43NTgsMy4xNzJjMi4yMjUtMC44NjksMy41NTktMS45NzYsMy41NTktMy4xNzJWOC4xOTRjMC0yLjgwOC03LjMwOS01LjA5Mi0xNi4zMTYtNS4wOTINCiAgICAgICAgICAgICAgICAgICAgYy05LjAxNCwwLTE2LjMyLDIuMjg0LTE2LjMyLDUuMDkydjUuMDk5YzAsMi44MTIsNy4zMDcsNS4wOTIsMTYuMzIsNS4wOTJWMTMuMjkyeiIvPg0KICAgICAgICAgICAgICAgIDxwYXRoIGZpbGw9IiNGRkZGRkYiIGQ9Ik0zMy4wNTIsMjEuNTU4bC0xMi43NTgtMy4xNzR2NS4wOTVsMTIuNzU4LDMuMTdjMi4yMjUtMC44NjksMy41NTktMS45NzIsMy41NTktMy4xNw0KICAgICAgICAgICAgICAgICAgICBjMC0xLjA4MiwwLTQuMDE2LDAtNS4wOTVDMzYuNjExLDE5LjU4MywzNS4yNzcsMjAuNjg2LDMzLjA1MiwyMS41NTh6Ii8+DQogICAgICAgICAgICAgICAgPHBhdGggZmlsbD0iI0ZGRkZGRiIgZD0iTTMuOTc0LDE4LjM4NGMwLDEuMDc5LDAsNC4wMTMsMCw1LjA5NWMwLDIuODExLDcuMzA3LDUuMDkxLDE2LjMyLDUuMDkxdi01LjA5MQ0KICAgICAgICAgICAgICAgICAgICBDMTEuMjgxLDIzLjQ3OSwzLjk3NCwyMS4xOTYsMy45NzQsMTguMzg0eiIvPg0KICAgICAgICAgICAgPC9nPg0KICAgICAgICAgICAgPC9zdmc+',
    'go-to-mixer' => 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA0Ni45MyAzMi4wMSI+PGRlZnM+PHN0eWxlPi5jbHMtMXtmaWxsOiNmZmY7fTwvc3R5bGU+PC9kZWZzPjx0aXRsZT5BcnRib2FyZCA4NzwvdGl0bGU+PGcgaWQ9IkxheWVyXzEiIGRhdGEtbmFtZT0iTGF5ZXIgMSI+PHBhdGggY2xhc3M9ImNscy0xIiBkPSJNNDAsMzJoN2MwLTE3LjY1LTEwLjUzLTMyLTIzLjQ3LTMyUzAsMTQuMzYsMCwzMkg3YzAtOS40NSwzLjY3LTE4LjE2LDguOTEtMjIuNThDMTMuMzYsMTcuNDgsMTMuMTcsMjguNzcsMTMuMTcsMzJoN2MwLTEyLDEuODgtMjEuMTgsMy4zMy0yNC4yOUMyNC45MiwxMC44MiwyNi44LDIwLDI2LjgsMzJoN2MwLTMuMjQtLjE5LTE0LjUzLTIuNzEtMjIuNThDMzYuMywxMy44NSw0MCwyMi41Niw0MCwzMloiLz48L2c+PC9zdmc+',
    'progress-1' => 'data:image/svg+xml;base64,PHN2ZyB2ZXJzaW9uPSIxLjEiIGlkPSJMYXllcl8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCINCndpZHRoPSIyMDQuODk1cHgiIGhlaWdodD0iMjU5LjQ3OXB4IiB2aWV3Qm94PSIwIDAgMjA0Ljg5NSAyNTkuNDc5IiBlbmFibGUtYmFja2dyb3VuZD0ibmV3IDAgMCAyMDQuODk1IDI1OS40NzkiDQp4bWw6c3BhY2U9InByZXNlcnZlIj4NCjxwYXRoIGZpbGw9IiNEMzNENDQiIGQ9Ik0xMDIuNDQzLDE2Mi42NzNjMTMuMDg0LDAsMjUuMzc0LDAuODU0LDM2LjgzOCwyLjU2YzExLjQ3NywxLjcwOSwyMS41MSw0LjAyNCwzMC4xMjEsNy4wMDMNCmM4LjU5OCwyLjk1NCwxNS4zNzEsNi4zNDUsMjAuMjk1LDEwLjE5OWM0LjkzNCwzLjg3LDcuMzk2LDguMDI2LDcuMzk2LDEyLjUxNXYzMi4yNTNjMCw0LjQ4OC0yLjQ2Myw4LjY2Ni03LjM5NiwxMi41MTYNCmMtNC45MjQsMy44NTQtMTEuNjk3LDcuMjY5LTIwLjI5NSwxMC4yMjNjLTguNjExLDIuOTU1LTE4LjY0Niw1LjI5My0zMC4xMjEsNi45NzljLTExLjQ2NSwxLjcwOS0yMy43NTQsMi41Ni0zNi44MzgsMi41Ng0KYy0xMy4wODYsMC0yNS4zNi0wLjg1MS0zNi44MjktMi41NmMtMTEuNDc2LTEuNjg3LTIxLjUyMS00LjAyMy0zMC4xMTgtNi45NzljLTguNjAyLTIuOTU0LTE1LjM3NC02LjM2OC0yMC4yOTgtMTAuMjIzDQpjLTQuOTM0LTMuODUtNy4zOTYtOC4wMjYtNy4zOTYtMTIuNTE2di0zMi4yNTNjMC00LjQ4NywyLjQ2My04LjY0NSw3LjM5Ni0xMi41MTVjNC45MjQtMy44NTQsMTEuNjk2LTcuMjQ1LDIwLjI5OC0xMC4xOTkNCmM4LjU5OC0yLjk3OSwxOC42NDQtNS4yOTQsMzAuMTE4LTcuMDAzQzc3LjA4MiwxNjMuNTI3LDg5LjM1NywxNjIuNjczLDEwMi40NDMsMTYyLjY3M3ogTTM3LjEwMywxNzcuMjAzDQpjLTcuNTI4LDIuNTE0LTEzLjQzNiw1LjMzOC0xNy43NDcsOC40NjhjLTQuMywzLjEzMS02LjQ0NSw2LjIzNy02LjQ0NSw5LjI3N3MyLjE0Niw2LjE5MSw2LjQ0NSw5LjQwOA0KYzQuMzEyLDMuMjIsMTAuMjE5LDYuMTA1LDE3Ljc0Nyw4LjYwNGM4Ljc4NSwzLjA2MiwxOC43ODQsNS4zMzcsMjkuOTg3LDYuODY2YzExLjE5MywxLjUxMiwyMi45ODcsMi4yNzUsMzUuMzUzLDIuMjc1DQpjMTIuMzc0LDAsMjQuMTU5LTAuNzY1LDM1LjM2Mi0yLjI3NWMxMS4yMDMtMS41MjksMjEuMTkxLTMuODA1LDI5Ljk3OS02Ljg2NmM3LjUzNy0yLjQ5NywxMy40NDctNS4zODQsMTcuNzQ2LTguNjA0DQpjNC4zMTEtMy4yMTcsNi40NTUtNi4zNjgsNi40NTUtOS40MDhzLTIuMTQ2LTYuMTQ2LTYuNDU1LTkuMjc3Yy00LjI5OS0zLjEzLTEwLjIwOS01Ljk1NC0xNy43NDYtOC40NjgNCmMtOC43ODctMy4wNDQtMTguNzc1LTUuMzQyLTI5Ljk3OS02Ljg1Yy0xMS4yMDMtMS41MzMtMjIuOTg4LTIuMjk5LTM1LjM2Mi0yLjI5OWMtMTIuMzY0LDAtMjQuMTU4LDAuNzY2LTM1LjM1MywyLjI5OQ0KQzU1Ljg4NywxNzEuODYxLDQ1Ljg4OCwxNzQuMTU5LDM3LjEwMywxNzcuMjAzeiIvPg0KPC9zdmc+',
    'progress-2' => 'data:image/svg+xml;base64,PHN2ZyB2ZXJzaW9uPSIxLjEiIGlkPSJMYXllcl8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCINCndpZHRoPSIyMDQuODk1cHgiIGhlaWdodD0iMjU5LjQ3OXB4IiB2aWV3Qm94PSIwIDAgMjA0Ljg5NSAyNTkuNDc5IiBlbmFibGUtYmFja2dyb3VuZD0ibmV3IDAgMCAyMDQuODk1IDI1OS40NzkiDQp4bWw6c3BhY2U9InByZXNlcnZlIj4NCjxwYXRoIGZpbGw9IiNEMzNENDQiIGQ9Ik0xNzIuODksMTU3LjMxNGM2LjYzMSwyLjE0LDEyLjAxNCw0LjQ2MSwxNi4xMzcsNi45NzljNC4xMjcsMi41MTQsNy4zNTQsNS4wMSw5LjY4Niw3LjUyOA0KYzIuMzE4LDIuNTE0LDMuOTM4LDQuOTQsNC44MzQsNy4yNjJjMC44OTgsMi4zMjEsMS4zNDgsNC40ODgsMS4zNDgsNi40NTh2MjAuOTYxYzAsMi42OTQtMC4zNjEsNC43NDktMS4wNzIsNi4xOTINCmMtMS4wODQsMy40MTMtMy4zMjYsNS4xLTYuNzI5LDUuMXY5LjQwOGMwLDQuNDg4LTIuNDYxLDguNjY2LTcuMzk2LDEyLjUxNmMtNC45MjQsMy44NTQtMTEuNjk1LDcuMjY5LTIwLjI5NSwxMC4yMjMNCmMtOC42MTEsMi45NTMtMTguNjQzLDUuMjkzLTMwLjEyMSw2Ljk3OWMtMTEuNDY3LDEuNzA5LTIzLjc1MiwyLjU2LTM2LjgzOSwyLjU2Yy0xMy4wODQsMC0yNS40MDMtMC44NTEtMzYuOTcxLTIuNTYNCmMtMTEuNTYzLTEuNjg3LTIxLjY0LTQuMDI1LTMwLjI1MS02Ljk3OWMtOC41OTktMi45NTQtMTUuMzcxLTYuMzY4LTIwLjI5Ny0xMC4yMjNjLTQuOTMzLTMuODUtNy4zOTUtOC4wMjYtNy4zOTUtMTIuNTE2di05LjQwOA0KYy0zLjA1NCwwLTUuMjg0LTEuNjg3LTYuNzE5LTUuMUMwLjI3NSwyMTEuMDc1LDAsMjA5LjAyLDAsMjA2LjUwMnYtMjAuOTYxYzAtMS45NywwLjQ0OS00LjEzNywxLjM0Ni02LjQ1OA0KYzAuODk3LTIuMzIsMi41MDctNC43NDgsNC44MzUtNy4yNjJjMi4zMzEtMi41Miw1LjU1OS01LjAxNiw5LjY4Ni03LjUyOGM0LjEyNC0yLjUxOSw5LjQ5Ni00Ljg0LDE2LjEzOC02Ljk3OQ0KYzkuNDk1LTMuMjQyLDIwLjI1LTUuNjg5LDMyLjI2Ni03LjM5OGMxMi4wMDItMS43MDgsMjQuNzM3LTIuNTU5LDM4LjE3Mi0yLjU1OWMxMy40NDcsMCwyNi4xNzEsMC44NTEsMzguMTg1LDIuNTU5DQpDMTUyLjYzOCwxNTEuNjI1LDE2My4zOTIsMTU0LjA3MiwxNzIuODksMTU3LjMxNHogTTE5OS41MSwxODUuNTQyYzAtNC40ODgtMi41NTEtOC43NTYtNy42NTgtMTIuNzgNCmMtNS4xMDctNC4wMjMtMTIuMDE0LTcuNTcyLTIwLjY5OS0xMC42MTJjLTguNjk5LTMuMDQtMTguOTYzLTUuNDI5LTMwLjc4OS03LjEzNGMtMTEuODM4LTEuNzA4LTI0LjQ3Ny0yLjU2Mi0zNy45MjItMi41NjINCmMtMTMuNDM1LDAtMjYuMDcsMC44NTQtMzcuOTA5LDIuNTYyYy0xMS44MjcsMS43MDUtMjIuMTM2LDQuMDk0LTMwLjkxOSw3LjEzNGMtOC43ODYsMy4wNC0xNS43MzQsNi41ODktMjAuODQ2LDEwLjYxMg0KYy01LjEwOCw0LjAyNC03LjY1OCw4LjI5Mi03LjY1OCwxMi43OHYyMC45NjFjMCw0LjEzOCwwLjgxLDYuMTkyLDIuNDIsNi4xOTJjMC41MzUsMCwwLjg5Ni0wLjMwNywxLjA3MS0wLjkzOQ0KYzAuMTg2LTAuNjM0LDAuNDkzLTEuMzU3LDAuOTQtMi4xNDZjMC40NDktMC44MTEsMS4wODQtMS41MzQsMS44ODItMi4xNjhjMC44MTItMC42MTEsMi4wMjMtMC45MzksMy42MzEtMC45MzkNCmMxLjYyMSwwLDIuODI0LDEuMDc1LDMuNjM1LDMuMjM3YzAuODEsMi4xNDYsMS42MDcsNC41MjgsMi40MTcsNy4xMTRjMC44MSwyLjYwNCwxLjcwOCw0Ljk4NiwyLjY5LDcuMTM0DQpjMC45ODUsMi4xNDUsMi40NjMsMy4yMTUsNC40MzUsMy4yMTVjMS43OTMsMCwzLjMxMi0wLjQ4LDQuNTcxLTEuNDY2YzEuMjU3LTAuOTg1LDIuNDE4LTIuMDU3LDMuNTAyLTMuMjM4DQpjMS4wNy0xLjE2LDIuMjMxLTIuMjMsMy40ODktMy4yMTVjMS4yNTktMC45ODUsMi44NjctMS40ODksNC44NDktMS40ODljMS45NywwLDMuNDg4LDEuMDk0LDQuNTYyLDMuMjM4DQpjMS4wODQsMi4xNDUsMi4xMTIsNC41NzMsMy4wOTcsNy4yNjNjMC45ODQsMi42NzEsMi4xMDIsNS4xLDMuMzU5LDcuMjQ1YzEuMjU4LDIuMTY3LDIuOTY0LDMuMjM3LDUuMTA3LDMuMjM3DQpjMi4xNTYsMCwzLjgwOC0wLjcyMSw0Ljk3OS0yLjE0NmMxLjE2LTEuNDQ0LDIuMjQyLTIuOTczLDMuMjI3LTQuNTc0YzAuOTg2LTEuNjE3LDIuMTA0LTMuMTQ2LDMuMzU5LTQuNTcyDQpjMS4yNDgtMS40NDMsMy4xMzEtMi4xNjMsNS42NDYtMi4xNjNjMi4zMjksMCw0LjM0NCwxLjA0OCw2LjA1MSwzLjEwNmMxLjcwNywyLjA1NywzLjM1OCw0LjI4NSw0Ljk3OSw2LjcyDQpjMS42MDYsMi40MjksMy40NDcsNC42NTgsNS41MDIsNi43MTVjMi4wNjgsMi4wNTksNC43MTksMy4wODQsNy45MzMsMy4wODRjMy4yMjgsMCw1Ljg3Ni0xLjAyNSw3LjkzMy0zLjA4NA0KYzIuMDY2LTIuMDU3LDMuOTA2LTQuMjg2LDUuNTE0LTYuNzE1YzEuNjE5LTIuNDM1LDMuMjMtNC42NjMsNC44NS02LjcyYzEuNjA1LTIuMDYsMy42NjYtMy4xMDYsNi4xODItMy4xMDYNCmMyLjMzLDAsNC4xNjgsMC43Miw1LjUxNCwyLjE2M2MxLjM0OCwxLjQyNiwyLjQ2MSwyLjk1NSwzLjM1OSw0LjU3MmMwLjg5NSwxLjYwMywxLjkyOCwzLjEzLDMuMDk2LDQuNTc0DQpjMS4xNiwxLjQyNSwyLjgyNCwyLjE0Niw0Ljk2NywyLjE0NmMyLjE1NiwwLDMuODUyLTEuMDcsNS4xMDktMy4yMzdjMS4yNTgtMi4xNDYsMi4zNzUtNC41NzQsMy4zNjEtNy4yNDUNCmMwLjk4LTIuNjg5LDIuMDU1LTUuMTE4LDMuMjI3LTcuMjYzYzEuMTcyLTIuMTQ2LDIuNzMyLTMuMjM4LDQuNzAzLTMuMjM4czMuNTksMC41MDQsNC44NDgsMS40ODkNCmMxLjI0OCwwLjk4MywyLjQxOCwyLjA1NSwzLjQ5LDMuMjE1YzEuMDcyLDEuMTgzLDIuMTk5LDIuMjUzLDMuMzU5LDMuMjM4YzEuMTcsMC45ODQsMi43MzIsMS40NjYsNC43MDMsMS40NjYNCmMxLjc5NSwwLDMuMjI5LTEuMDcsNC4zMTEtMy4yMTVjMS4wNzItMi4xNDYsMS45NzEtNC41MjksMi42ODItNy4xMzRjMC43MjUtMi41ODYsMS41MjEtNC45NywyLjQzLTcuMTE0DQpjMC44ODctMi4xNjIsMi4xNDUtMy4yMzcsMy43NjYtMy4yMzdjMS42MDcsMCwyLjgyMiwwLjMyOCwzLjYxOSwwLjkzOWMwLjgwOSwwLjYzNCwxLjM5MSwxLjM1NywxLjc1MiwyLjE2OA0KYzAuMzU5LDAuNzg3LDAuNjc2LDEuNTEyLDAuOTM5LDIuMTQ2YzAuMjczLDAuNjM0LDAuNTgsMC45MzksMC45NDEsMC45MzljMS42MTksMCwyLjQxOC0yLjA1NiwyLjQxOC02LjE5MnYtMjAuOTYxSDE5OS41MXoNCk0xOTAuNjM2LDE4MS4yMjljMS43OTUsMCwyLjY5MSwwLjg5OCwyLjY5MSwyLjY5MmMwLDMuOTM2LTEuNzkzLDcuNjU5LTUuMzcxLDExLjE1N2MtMy41ODgsMy41MDQtOC43ODcsNi41ODgtMTUuNjA0LDkuMjc3DQpjLTYuNDU1LDIuNTE5LTEzLjc1Miw0LjM5Ny0yMS45MTQsNS42NDdjLTguMTUsMS4yNDYtMTYuODA1LDEuODgtMjUuOTQxLDEuODhjLTEuNzk1LDAtMi42OTEtMC44MS0yLjY5MS0yLjQyOQ0KYzAtMS43NzEsMC44OTYtMi42NzEsMi42OTEtMi42NzFjOS40OTYsMCwxOC4xODYtMC42NzUsMjYuMDg0LTIuMDMyYzcuODc1LTEuMzM2LDE0LjYwNS0zLjA4NiwyMC4xNjYtNS4yMjkNCmM1LjU0NS0yLjE2OCw5Ljg1NS00LjYxOCwxMi44OTYtNy4zOTdjMy4wNTMtMi43NzksNC41NzQtNS41MTQsNC41NzQtOC4yMDNDMTg4LjIxOSwxODIuMTI5LDE4OS4wMjcsMTgxLjIyOSwxOTAuNjM2LDE4MS4yMjl6Ii8+DQo8L3N2Zz4NCg==',
    'progress-3' => 'data:image/svg+xml;base64,PHN2ZyB2ZXJzaW9uPSIxLjEiIGlkPSJMYXllcl8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCINCndpZHRoPSIyMDQuODk1cHgiIGhlaWdodD0iMjU5LjQ3OXB4IiB2aWV3Qm94PSIwIDAgMjA0Ljg5NSAyNTkuNDc5IiBlbmFibGUtYmFja2dyb3VuZD0ibmV3IDAgMCAyMDQuODk1IDI1OS40NzkiDQp4bWw6c3BhY2U9InByZXNlcnZlIj4NCjxwYXRoIGZpbGw9IiNEMzNENDQiIGQ9Ik0xODcuNjg1LDE2My43NDNjNi44MTgsMy43ODcsMTEuMzgxLDcuNTk2LDEzLjcwOSwxMS40NDVjMi4zMzIsMy44NSwzLjUwMiw3LjMwOSwzLjUwMiwxMC4zNTN2MjAuOTYxDQpjMCwyLjY5My0wLjM1OSw0Ljc0OS0xLjA3Miw2LjE5MmMtMS4wODIsMy40MTMtMy4zMjYsNS4xLTYuNzI5LDUuMXY5LjQwOGMwLDQuNDg4LTIuNDYxLDguNjY1LTcuMzk2LDEyLjUxNQ0KYy00LjkyNiwzLjg1NC0xMS42OTUsNy4yNy0yMC4yOTksMTAuMjIzYy04LjYwOSwyLjk1NS0xOC42NDMsNS4yOTMtMzAuMTE5LDYuOTc5Yy0xMS40NjcsMS43MDktMjMuNzUyLDIuNTYtMzYuODM5LDIuNTYNCmMtMTMuMDg0LDAtMjUuNDA0LTAuODUxLTM2Ljk2OS0yLjU2Yy0xMS41NjYtMS42ODgtMjEuNjQyLTQuMDI0LTMwLjI1Mi02Ljk3OWMtOC42MDEtMi45NTMtMTUuMzczLTYuMzY3LTIwLjI5Ny0xMC4yMjMNCmMtNC45MzUtMy44NS03LjM5Ni04LjAyNS03LjM5Ni0xMi41MTV2LTkuNDA4Yy0zLjA1MywwLTUuMjg1LTEuNjg3LTYuNzE5LTUuMWMtMC41MzUtMS42MTktMC44MTItMy42NzQtMC44MTItNi4xOTJ2LTIwLjk2MQ0KYzAtMy4wNDQsMS4xMTctNi41MDMsMy4zNTktMTAuMzUzYzIuMjQ0LTMuODUxLDYuODU5LTcuNjU4LDEzLjg1Mi0xMS40NDV2LTE5LjM0MmMwLTQuMzEyLDIuMjQ0LTguMzM4LDYuNzItMTIuMTAzDQpjNC40ODUtMy43NjQsMTAuNTgtNy4wOTIsMTguMjgzLTkuOTU3YzcuNzEzLTIuODYyLDE2LjcxOS01LjA5OSwyNy4wMjQtNi43MThjMTAuMzA1LTEuNjIsMjEuMzc3LTIuNDI5LDMzLjIwMy0yLjQyOQ0KYzExLjgzOSwwLDIyLjkwMiwwLjgwOSwzMy4yMTgsMi40MjljMTAuMzA5LDEuNjE5LDE5LjMxMiwzLjg1NCwyNy4wMTYsNi43MThjNy43MTMsMi44NjUsMTMuODA5LDYuMTkzLDE4LjI5NSw5Ljk1Nw0KYzQuNDczLDMuNzY1LDYuNzE1LDcuNzg5LDYuNzE1LDEyLjEwM3YxOS4zNDJIMTg3LjY4NXogTTE5OS41MTMsMTg1LjU0MWMwLTUuNTU5LTMuOTM5LTEwLjg1NS0xMS44MjgtMTUuODY2djUuOTA5DQpjMCw0LjMxLTIuMjQyLDguMzM4LTYuNzE1LDEyLjA5OGMtNC40ODYsMy43NjUtMTAuNTgyLDcuMDQ5LTE4LjI5NSw5LjgyNmMtNy43MDMsMi43NzktMTYuNzA3LDQuOTctMjcuMDE2LDYuNTg4DQpjLTEwLjMxNCwxLjU5OC0yMS4zNzksMi40MDYtMzMuMjE4LDIuNDA2Yy0xMS44MjYsMC0yMi44OTctMC44MS0zMy4yMDMtMi40MDZjLTEwLjMwNy0xLjYxOC0xOS4zMTItMy44MDktMjcuMDI0LTYuNTg4DQpjLTcuNzAzLTIuNzc3LTEzLjc5Ny02LjA2Mi0xOC4yODMtOS44MjZjLTQuNDc2LTMuNzYtNi43MTktNy43ODgtNi43MTktMTIuMDk4di01LjkwOWMtOC4wNjIsNS4wMTEtMTIuMTAxLDEwLjMwOS0xMi4xMDEsMTUuODY2DQp2MjAuOTYxYzAsNC4xMzcsMC44MTIsNi4xOTIsMi40MTgsNi4xOTJjMC41MzcsMCwwLjg5Ni0wLjMwOCwxLjA3Mi0wLjkzOWMwLjE4OC0wLjYzNSwwLjQ5Mi0xLjM1NywwLjkzOS0yLjE0Ng0KYzAuNDQ4LTAuODExLDEuMDg0LTEuNTM0LDEuODgzLTIuMTY4YzAuODA5LTAuNjEyLDIuMDI0LTAuOTM5LDMuNjMzLTAuOTM5YzEuNjE5LDAsMi44MjIsMS4wNzUsMy42MzEsMy4yMzcNCmMwLjgxMiwyLjE0NiwxLjYxMSw0LjUyOCwyLjQyLDcuMTE1YzAuODEyLDIuNjAzLDEuNzA3LDQuOTg1LDIuNjkxLDcuMTMyYzAuOTgzLDIuMTQ1LDIuNDYxLDMuMjE2LDQuNDMsMy4yMTYNCmMxLjc5NSwwLDMuMzE4LTAuNDgxLDQuNTc0LTEuNDY3YzEuMjU4LTAuOTg0LDIuNDE4LTIuMDU2LDMuNS0zLjIzN2MxLjA3MS0xLjE2LDIuMjMxLTIuMjMsMy40OTItMy4yMTYNCmMxLjI1OC0wLjk4NCwyLjg2NC0xLjQ4OCw0Ljg0Ni0xLjQ4OGMxLjk3MSwwLDMuNDksMS4wOTMsNC41NjIsMy4yMzhjMS4wODQsMi4xNDUsMi4xMTEsNC41NzIsMy4wOTcsNy4yNjMNCmMwLjk4MywyLjY3MSwyLjEwMiw1LjEsMy4zNTgsNy4yNDRjMS4yNjEsMi4xNjgsMi45NjcsMy4yMzgsNS4xMDksMy4yMzhjMi4xNTYsMCwzLjgwOS0wLjcyMSw0Ljk3OS0yLjE0Ng0KYzEuMTYtMS40NDIsMi4yNDQtMi45NzMsMy4yMjktNC41NzRjMC45ODQtMS42MTcsMi4xLTMuMTQ2LDMuMzU5LTQuNTcxYzEuMjQ1LTEuNDQzLDMuMTI3LTIuMTYzLDUuNjQ1LTIuMTYzDQpjMi4zMzIsMCw0LjM0NCwxLjA0OCw2LjA1MSwzLjEwNWMxLjcwNywyLjA1NywzLjM1Nyw0LjI4Niw0Ljk3OCw2LjcyYzEuNjA4LDIuNDMsMy40NDcsNC42NTksNS41MDYsNi43MTUNCmMyLjA2NSwyLjA2LDQuNzE1LDMuMDg0LDcuOTMsMy4wODRjMy4yMjksMCw1Ljg3NS0xLjAyNCw3LjkzNC0zLjA4NGMyLjA2OC0yLjA1NiwzLjkwNi00LjI4NSw1LjUxNi02LjcxNQ0KYzEuNjE5LTIuNDM0LDMuMjI3LTQuNjYzLDQuODQ2LTYuNzJjMS42MDctMi4wNTksMy42NjYtMy4xMDUsNi4xODQtMy4xMDVjMi4zMzIsMCw0LjE2OCwwLjcyLDUuNTE2LDIuMTYzDQpjMS4zNDQsMS40MjYsMi40NjEsMi45NTQsMy4zNTksNC41NzFjMC44OTUsMS42MDMsMS45MjQsMy4xMzIsMy4wOTYsNC41NzRjMS4xNTgsMS40MjYsMi44MjIsMi4xNDYsNC45NjcsMi4xNDYNCmMyLjE1NCwwLDMuODUtMS4wNyw1LjEwNy0zLjIzOGMxLjI2Mi0yLjE0NiwyLjM3NS00LjU3MywzLjM1OS03LjI0NGMwLjk4NC0yLjY4OSwyLjA1OS01LjExOCwzLjIyNy03LjI2Mw0KYzEuMTc0LTIuMTQ2LDIuNzM4LTMuMjM4LDQuNzA3LTMuMjM4czMuNTksMC41MDQsNC44NDYsMS40ODhjMS4yNDgsMC45ODMsMi40MiwyLjA1NiwzLjQ5LDMuMjE2DQpjMS4wNzIsMS4xODMsMi4xOTksMi4yNTMsMy4zNTcsMy4yMzdjMS4xNzQsMC45ODQsMi43MzYsMS40NjcsNC43MDUsMS40NjdjMS43OTUsMCwzLjIyOS0xLjA3MSw0LjMxMi0zLjIxNg0KYzEuMDcyLTIuMTQ2LDEuOTY5LTQuNTI5LDIuNjgtNy4xMzJjMC43MjMtMi41ODcsMS41MjEtNC45NzEsMi40MjgtNy4xMTVjMC44ODktMi4xNjIsMi4xNDYtMy4yMzcsMy43NjYtMy4yMzcNCmMxLjYwNSwwLDIuODIyLDAuMzI3LDMuNjIxLDAuOTM5YzAuODExLDAuNjM0LDEuMzg5LDEuMzU3LDEuNzUyLDIuMTY4YzAuMzU5LDAuNzg3LDAuNjc4LDEuNTExLDAuOTM4LDIuMTQ2DQpjMC4yNzMsMC42MzMsMC41OCwwLjkzOSwwLjk0MSwwLjkzOWMxLjYxOSwwLDIuNDE4LTIuMDU3LDIuNDE4LTYuMTkyVjE4NS41NDFMMTk5LjUxMywxODUuNTQxeiBNNDMuODMzLDEyNy4xNzcNCmMtNi44MTYsMi41MjEtMTIuMTAzLDUuMjU0LTE1Ljg2Myw4LjIwOWMtMy43NjQsMi45NTQtNS42NDYsNS45NzMtNS42NDYsOS4wMTdjMCwyLjg2NCwxLjg4Myw1Ljc3Myw1LjY0Niw4LjcyOQ0KYzMuNzYyLDIuOTU1LDkuMDQ3LDUuNjg5LDE1Ljg2Myw4LjIwOGM3Ljg4NywzLjA0LDE2LjgwNSw1LjI5MywyNi43NSw2LjcxOGM5Ljk1NiwxLjQ0NCwyMC41NjksMi4xNDYsMzEuODU4LDIuMTQ2DQpjMTEuMTE3LDAsMjEuNjk3LTAuNzAxLDMxLjczLTIuMTQ2YzEwLjA0NS0xLjQyNSwxOC45MTgtMy41ODgsMjYuNjIxLTYuNDU3YzYuODE0LTIuNDksMTIuMTQ1LTUuMjcxLDE2LjAwOC04LjMzOA0KYzMuODUyLTMuMDQsNS43NzMtNS45OTQsNS43NzMtOC44NThjMC0zLjA0NC0xLjkyNC02LjA2Mi01Ljc3My05LjAxN2MtMy44NjMtMi45NTUtOS4xOTEtNS42ODgtMTYuMDA4LTguMjA5DQpjLTcuNzAzLTIuODYyLTE2LjU3Ni01LjA1My0yNi42MjEtNi41ODNjLTEwLjAzMS0xLjUxMS0yMC42MTEtMi4yNzMtMzEuNzMtMi4yNzNjLTExLjI4OSwwLTIxLjkwMSwwLjc2NC0zMS44NTgsMi4yNzMNCkM2MC42MzgsMTIyLjEyNCw1MS43MiwxMjQuMzEzLDQzLjgzMywxMjcuMTc3eiIvPg0KPC9zdmc+DQo=',
    'progress-4' => 'data:image/svg+xml;base64,PHN2ZyB2ZXJzaW9uPSIxLjEiIGlkPSJMYXllcl8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCINCgkgd2lkdGg9IjIwNC44OTVweCIgaGVpZ2h0PSIyNTkuNDc5cHgiIHZpZXdCb3g9IjAgMCAyMDQuODk1IDI1OS40NzkiIGVuYWJsZS1iYWNrZ3JvdW5kPSJuZXcgMCAwIDIwNC44OTUgMjU5LjQ3OSINCgkgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+DQo8cGF0aCBmaWxsPSIjRDMzRDQ0IiBkPSJNMTkwLjkxLDE2NS42MjhjNS41NjEsMy41ODgsOS4yNzcsNy4wODgsMTEuMTYsMTAuNTAxYzEuODg1LDMuMzkyLDIuODI0LDYuNTQyLDIuODI0LDkuNDEydjIwLjk2MQ0KCWMwLDIuNjkzLTAuMzYxLDQuNzQ5LTEuMDcyLDYuMTkyYy0xLjA4NCwzLjQxMy0zLjMyNiw1LjEtNi43MjksNS4xdjkuNDA4YzAsNC40ODgtMi40NjEsOC42NjUtNy4zOTYsMTIuNTE1DQoJYy00LjkyNiwzLjg1NC0xMS42OTUsNy4yNy0yMC4yOTUsMTAuMjIzYy04LjYxMywyLjk1NS0xOC42NDUsNS4yOTQtMzAuMTIzLDYuOTc5Yy0xMS40NjUsMS43MDktMjMuNzUyLDIuNTYtMzYuODM3LDIuNTYNCgljLTEzLjA4NiwwLTI1LjQwNi0wLjg1MS0zNi45NzEtMi41NmMtMTEuNTYyLTEuNjg3LTIxLjY0Mi00LjAyNC0zMC4yNS02Ljk3OWMtOC42MDMtMi45NTMtMTUuMzc1LTYuMzY3LTIwLjI5Ny0xMC4yMjMNCgljLTQuOTM3LTMuODUtNy4zOTYtOC4wMjUtNy4zOTYtMTIuNTE1di05LjQwOGMtMy4wNTEsMC01LjI4NS0xLjY4Ny02LjcxNy01LjFjLTAuNTM3LTEuNjE5LTAuODEyLTMuNjc0LTAuODEyLTYuMTkydi0yMC45NjENCgljMC0yLjg3LDAuODk4LTYuMDIxLDIuNjkzLTkuNDEyYzEuNzgzLTMuNDEzLDUuNDU5LTYuOTEzLDExLjAxOS0xMC41MDFjLTEuMjQ4LTAuNzAxLTIuMzMyLTIuMTQ2LTMuMjMtNC4yOQ0KCWMtMC41MzMtMS4yNDYtMC43OTctMy4yMzgtMC43OTctNS45MDl2LTIwLjE3NGMwLTUuNTU5LDIuNTA0LTEwLjY1OCw3LjUyNy0xNS4zMTZjNS4wMS00LjY2MywxMi4xODgtOC43OTYsMjEuNTEtMTIuMzg2DQoJYzguNjAyLTMuMDQ1LDE4LjM3MS01LjQwNCwyOS4zMTItNy4xMTNjMTAuOTMxLTEuNzA0LDIyLjQwNi0yLjU2LDM0LjQwOC0yLjU2YzEyLjAxMiwwLDIzLjQ5MywwLjg1NCwzNC40MjIsMi41Ng0KCWMxMC45MywxLjcwOSwyMC43MTEsNC4wNjgsMjkuMzA5LDcuMTEzYzkuMzI0LDMuNTg5LDE2LjQ4OCw3LjcyMywyMS41MTIsMTIuMzg2YzUuMDIxLDQuNjU4LDcuNTI3LDkuNzU5LDcuNTI3LDE1LjMxNnYyMC4xNzQNCgljMCwyLjY3MS0wLjI2Miw0LjY2My0wLjgxMSw1LjkwOUMxOTMuNTA2LDE2My40ODIsMTkyLjM0NywxNjQuOTI3LDE5MC45MSwxNjUuNjI4eiBNMTk5LjUxMywxODUuNTQxDQoJYzAtNS41NTktMy45MzktMTAuODU1LTExLjgyOC0xNS44NjZ2NS45MDljMCw0LjMxLTIuMjQ0LDguMzM4LTYuNzE5LDEyLjA5OGMtNC40ODQsMy43NjUtMTAuNTc4LDcuMDQ5LTE4LjI5Myw5LjgyNg0KCWMtNy43MDEsMi43NzktMTYuNzA3LDQuOTctMjcuMDE0LDYuNTg4Yy0xMC4zMTYsMS41OTgtMjEuMzc5LDIuNDA2LTMzLjIxOCwyLjQwNmMtMTEuODI2LDAtMjIuODk3LTAuODEtMzMuMjA1LTIuNDA2DQoJYy0xMC4zMDktMS42MTgtMTkuMzExLTMuODA5LTI3LjAyNC02LjU4OGMtNy43MDEtMi43NzctMTMuNzk3LTYuMDYyLTE4LjI4My05LjgyNmMtNC40NzYtMy43Ni02LjcxNy03Ljc4OC02LjcxNy0xMi4wOTh2LTUuOTA5DQoJQzkuMTUsMTc0LjY4NSw1LjExLDE3OS45ODMsNS4xMSwxODUuNTQxdjIwLjk2MWMwLDQuMTM3LDAuODEsNi4xOTIsMi40MTgsNi4xOTJjMC41MzcsMCwwLjg5Ni0wLjMwOCwxLjA3Mi0wLjkzOQ0KCWMwLjE4Ni0wLjYzNSwwLjQ5Mi0xLjM1NywwLjk0MS0yLjE0NmMwLjQ0Ni0wLjgxMSwxLjA4MS0xLjUzNCwxLjg4My0yLjE2OGMwLjgwOS0wLjYxMiwyLjAyMi0wLjkzOSwzLjYzMS0wLjkzOQ0KCWMxLjYxOSwwLDIuODI0LDEuMDc1LDMuNjMzLDMuMjM3YzAuODEyLDIuMTQ2LDEuNjA5LDQuNTI4LDIuNDE4LDcuMTE1YzAuODEyLDIuNjA0LDEuNzA3LDQuOTg1LDIuNjkxLDcuMTMyDQoJYzAuOTgzLDIuMTQ1LDIuNDYzLDMuMjE2LDQuNDMyLDMuMjE2YzEuNzk1LDAsMy4zMTQtMC40ODEsNC41NzQtMS40NjdjMS4yNTgtMC45ODQsMi40MTgtMi4wNTYsMy41LTMuMjM3DQoJYzEuMDc0LTEuMTYsMi4yMjktMi4yMywzLjQ5LTMuMjE2YzEuMjU4LTAuOTg0LDIuODY2LTEuNDg4LDQuODQ2LTEuNDg4YzEuOTcxLDAsMy40OTIsMS4wOTMsNC41NjMsMy4yMzgNCgljMS4wODIsMi4xNDUsMi4xMTEsNC41NzMsMy4wOTcsNy4yNjNjMC45ODMsMi42NzEsMi4xMDIsNS4xLDMuMzU2LDcuMjQ0YzEuMjYxLDIuMTY4LDIuOTY3LDMuMjM4LDUuMTExLDMuMjM4DQoJYzIuMTU0LDAsMy44MDctMC43MjEsNC45NzktMi4xNDZjMS4xNTgtMS40NDIsMi4yNDItMi45NzMsMy4yMjgtNC41NzRjMC45ODQtMS42MTcsMi4xLTMuMTQ2LDMuMzU5LTQuNTcxDQoJYzEuMjQ3LTEuNDQzLDMuMTMxLTIuMTYzLDUuNjQ2LTIuMTYzYzIuMzMsMCw0LjM0MiwxLjA0OCw2LjA1MSwzLjEwNWMxLjcwNywyLjA1NywzLjM1Nyw0LjI4Niw0Ljk3OCw2LjcyMQ0KCWMxLjYwOCwyLjQyOSwzLjQ0NCw0LjY1OCw1LjUwNCw2LjcxNGMyLjA2NywyLjA2LDQuNzE1LDMuMDg0LDcuOTMyLDMuMDg0YzMuMjI4LDAsNS44NzYtMS4wMjQsNy45MzUtMy4wODQNCgljMi4wNjQtMi4wNTYsMy45MDQtNC4yODUsNS41MTQtNi43MTRjMS42MTktMi40MzUsMy4yMjctNC42NjQsNC44NDYtNi43MjFjMS42MDktMi4wNTksMy42NjgtMy4xMDUsNi4xODQtMy4xMDUNCgljMi4zMjgsMCw0LjE2OCwwLjcyLDUuNTE0LDIuMTYzYzEuMzQ2LDEuNDI2LDIuNDYxLDIuOTU0LDMuMzU5LDQuNTcxYzAuODk2LDEuNjAzLDEuOTI0LDMuMTMyLDMuMDk2LDQuNTc0DQoJYzEuMTYsMS40MjYsMi44MjIsMi4xNDYsNC45NjcsMi4xNDZjMi4xNTYsMCwzLjg1NC0xLjA3LDUuMTA5LTMuMjM4YzEuMjU4LTIuMTQ2LDIuMzczLTQuNTczLDMuMzU5LTcuMjQ0DQoJYzAuOTg0LTIuNjg4LDIuMDU3LTUuMTE4LDMuMjI5LTcuMjYzYzEuMTY4LTIuMTQ2LDIuNzM0LTMuMjM4LDQuNzAzLTMuMjM4YzEuOTcxLDAsMy41OSwwLjUwNCw0Ljg0OCwxLjQ4OA0KCWMxLjI0NiwwLjk4MywyLjQxOCwyLjA1NiwzLjQ5LDMuMjE2YzEuMDcyLDEuMTgzLDIuMTk5LDIuMjUzLDMuMzU5LDMuMjM3YzEuMTcsMC45ODQsMi43MzQsMS40NjcsNC43MDMsMS40NjcNCgljMS43OTUsMCwzLjIyNy0xLjA3MSw0LjMxMS0zLjIxNmMxLjA3Mi0yLjE0NiwxLjk3MS00LjUyOCwyLjY4Mi03LjEzMmMwLjcyMy0yLjU4NywxLjUyMS00Ljk3MSwyLjQzLTcuMTE1DQoJYzAuODg5LTIuMTYyLDIuMTQ1LTMuMjM3LDMuNzY0LTMuMjM3YzEuNjA5LDAsMi44MjQsMC4zMjcsMy42MjMsMC45MzljMC44MDksMC42MzQsMS4zODksMS4zNTcsMS43NSwyLjE2OA0KCWMwLjM2MSwwLjc4NywwLjY3OCwxLjUxMSwwLjk0MSwyLjE0NmMwLjI3LDAuNjMzLDAuNTgsMC45MzksMC45MzgsMC45MzljMS42MTksMCwyLjQyLTIuMDU3LDIuNDItNi4xOTJWMTg1LjU0MUwxOTkuNTEzLDE4NS41NDF6DQoJIE0xNC43OTIsMTU1LjQyOGMwLDMuOTM5LDAuODAxLDUuOTA5LDIuNDIsNS45MDljMC4zNTgsMCwwLjYyMy0wLjMwNiwwLjgxMS0wLjkzOWMwLjE3NC0wLjYzNSwwLjQzNi0xLjI5LDAuNzk3LTIuMDE2DQoJYzAuMzYzLTAuNzI0LDAuODk4LTEuMzk3LDEuNjE5LTIuMDE1YzAuNzExLTAuNjM0LDEuNzgzLTAuOTM5LDMuMjI5LTAuOTM5YzEuNDM0LDAsMi41NDksMS4wMjksMy4zNTYsMy4wODQNCgljMC44MTIsMi4wNjIsMS41MjMsNC4zOTgsMi4xNDYsNi45OGMwLjYzMywyLjYwOCwxLjQzNCw0Ljk0NiwyLjQyOCw3LjAwNmMwLjk4NCwyLjA1NiwyLjI4NywzLjA4NSwzLjg5NiwzLjA4NQ0KCWMxLjc5NSwwLDMuMjI4LTAuNDc5LDQuMy0xLjQ2NmMxLjA4NC0wLjk4NCwyLjA1OS0yLjAzNywyLjk2Ny0zLjEwN2MwLjg4NS0xLjA3MywxLjkxMi0yLjEwMSwzLjA4NC0zLjA4NQ0KCWMxLjE3LTAuOTg0LDIuNjQ2LTEuNDg3LDQuNDQtMS40ODdjMS43ODMsMCwzLjIyOSwxLjA0OCw0LjMwMiwzLjEwNmMxLjA2OSwyLjA1NSwyLjA1Nyw0LjM3NiwyLjk1Miw2Ljk3OQ0KCWMwLjg5NiwyLjYwMywxLjg4NCw0Ljk0NSwyLjk2OCw3LjAwMmMxLjA3MSwyLjA1NSwyLjU5MiwzLjA4NCw0LjU2MSwzLjA4NGMxLjk3MSwwLDMuNDU5LTAuNjc5LDQuNDQzLTIuMDE1DQoJYzAuOTgxLTEuMzU0LDEuOTI2LTIuODE5LDIuODIxLTQuNDM4YzAuODk2LTEuNjIsMS45MjUtMy4wODUsMy4wOTctNC40NDNjMS4xNi0xLjMzNiwyLjgyMi0yLjAxNSw0Ljk2Ny0yLjAxNQ0KCWMyLjE1NiwwLDMuOTM5LDAuOTg0LDUuMzgzLDIuOTU1YzEuNDM1LDEuOTkxLDIuOTEsNC4xMzcsNC40MzUsNi40NTNjMS41MiwyLjM0MywzLjE4NCw0LjQ4Nyw0Ljk3Nyw2LjQ1OA0KCWMxLjc4MywxLjk2OSw0LjIxMywyLjk1NCw3LjI1NCwyLjk1NGMyLjg2NywwLDUuMjUtMC45ODUsNy4xMzItMi45NTRjMS44ODUtMS45NzEsMy41NzgtNC4xMTUsNS4xMTEtNi40NTgNCgljMS41MjEtMi4zMTYsMi45OTgtNC40NjIsNC40My02LjQ1M2MxLjQzOC0xLjk3MSwzLjIzLTIuOTU1LDUuMzg1LTIuOTU1YzIuMTQ1LDAsMy44MDksMC42NzksNC45NjcsMi4wMTUNCgljMS4xNzIsMS4zNTgsMi4xOTcsMi44MjMsMy4wOTYsNC40NDNjMC44OTgsMS42MTgsMS44NCwzLjA4NSwyLjgyNCw0LjQzOGMwLjk4NCwxLjMzNiwyLjQ2MywyLjAxNSw0LjQzOSwyLjAxNQ0KCWMxLjk3MywwLDMuNDkyLTEuMDI5LDQuNTYyLTMuMDg0YzEuMDg0LTIuMDU3LDIuMDU3LTQuMzk4LDIuOTY3LTcuMDAyYzAuODg1LTIuNjA0LDEuODgxLTQuOTI1LDIuOTUzLTYuOTc5DQoJYzEuMDcyLTIuMDYsMi41MDYtMy4xMDYsNC4yOTktMy4xMDZjMS43OTUsMCwzLjIzLDAuNTAzLDQuMzAxLDEuNDg3YzEuMDg0LDAuOTg0LDIuMTEzLDIuMDEyLDMuMSwzLjA4NQ0KCWMwLjk4LDEuMDcsMi4wMTIsMi4xMjMsMy4wOTYsMy4xMDdjMS4wNywwLjk4NSwyLjQxOCwxLjQ2Niw0LjAyNSwxLjQ2NmMxLjc5NSwwLDMuMTQxLTEuMDI5LDQuMDM3LTMuMDg1DQoJYzAuODk2LTIuMDYsMS42OTUtNC4zOTYsMi40MTYtNy4wMDZjMC43MTMtMi41ODIsMS40MzYtNC45MiwyLjE1Ni02Ljk4YzAuNzExLTIuMDU1LDEuNzgzLTMuMDg0LDMuMjI5LTMuMDg0DQoJYzEuNDM0LDAsMi41MDYsMC4zMDcsMy4yMjcsMC45MzljMC43MTMsMC42MTYsMS4yOTMsMS4yOTEsMS43NCwyLjAxNWMwLjQ1MSwwLjcyNiwwLjc2OCwxLjM4MSwwLjk0MywyLjAxNg0KCWMwLjE3MiwwLjYzNSwwLjQ0NSwwLjkzOSwwLjgwOSwwLjkzOWMxLjQzNCwwLDIuMTU0LTEuOTcsMi4xNTQtNS45MDl2LTIwLjE3NGMwLTQuNDg4LTIuMjg3LTguNjQzLTYuODU5LTEyLjQ5OA0KCWMtNC41NzQtMy44NzItMTAuNzk5LTcuMjE4LTE4LjY4OC0xMC4wODZjLTcuODkxLTIuODY1LTE3LjE2Ni01LjE2My0yNy44MzQtNi44NzJjLTEwLjY2Ni0xLjY4Mi0yMi4wMDYtMi41MzctMzQuMDE3LTIuNTM3DQoJYy0xMi4xODgsMC0yMy41NjUsMC44NTUtMzQuMTQ3LDIuNTM3Yy0xMC41NzgsMS43MDktMTkuODQ3LDQuMDA3LTI3LjgyLDYuODcyYy03Ljk4OCwyLjg2OC0xNC4yNTgsNi4yMTQtMTguODMyLDEwLjA4Ng0KCWMtNC41NjIsMy44NTUtNi44NSw4LjAxMS02Ljg1LDEyLjQ5OHYyMC4xNzRIMTQuNzkyeiBNMTE1LjA5MiwxNjIuNjczYy0xLjc5NSwwLTIuNjkxLTAuODk3LTIuNjkxLTIuNjkyDQoJYzAtMS43NzIsMC44OTYtMi42NjcsMi42OTEtMi42NjdjOS40OTYsMCwxOC4xODQtMC42OCwyNi4wNzItMi4wMzdjNy44ODctMS4zMzUsMTQuNjE1LTMuMDg1LDIwLjE3Ni01LjIyOQ0KCWM1LjU0Ny0yLjE2OCw5Ljg0Ni00LjYxOSwxMi44OTgtNy4zOTRjMy4wNTMtMi43NzgsNC41Ny01LjUxOSw0LjU3LTguMjA3YzAtMS43OTQsMC44MTItMi42OTQsMi40Mi0yLjY5NA0KCWMxLjc5NSwwLDIuNjkxLDAuOSwyLjY5MSwyLjY5NGMwLDQuMTMzLTEuNzk1LDcuOTQtNS4zODMsMTEuNDIzYy0zLjU3OCwzLjQ5OC04Ljc3Nyw2LjQ5OC0xNS41OTIsOS4wMTYNCgljLTYuNDU1LDIuNTE2LTEzLjc2NCw0LjQzOS0yMS45MTQsNS43NzRDMTMyLjg2OSwxNjIuMDE3LDEyNC4yMjYsMTYyLjY3MywxMTUuMDkyLDE2Mi42NzN6Ii8+DQo8L3N2Zz4=',
    'progress-5' => 'data:image/svg+xml;base64,PHN2ZyB2ZXJzaW9uPSIxLjEiIGlkPSJMYXllcl8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCINCgkgd2lkdGg9IjIwNC44OTVweCIgaGVpZ2h0PSIyNTkuNDc5cHgiIHZpZXdCb3g9IjAgMCAyMDQuODk1IDI1OS40NzkiIGVuYWJsZS1iYWNrZ3JvdW5kPSJuZXcgMCAwIDIwNC44OTUgMjU5LjQ3OSINCgkgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+DQo8cGF0aCBmaWxsPSIjRDMzRDQ0IiBkPSJNMTkwLjkxMiwxNjUuNjI4YzUuNTU3LDMuNTg5LDkuMjc5LDcuMDg4LDExLjE2LDEwLjUwMWMxLjg4MywzLjM5MiwyLjgyMiw2LjU0MywyLjgyMiw5LjQxMnYyMC45NjENCgljMCwyLjY5NC0wLjM2MSw0Ljc0OS0xLjA3Miw2LjE5MmMtMS4wODQsMy40MTQtMy4zMjYsNS4xMDEtNi43Myw1LjEwMXY5LjQwN2MwLDQuNDg4LTIuNDYxLDguNjY2LTcuMzk1LDEyLjUxNg0KCWMtNC45MjIsMy44NTQtMTEuNjk1LDcuMjY5LTIwLjI5NSwxMC4yMjNjLTguNjA5LDIuOTU1LTE4LjY0NSw1LjI5My0zMC4xMjMsNi45NzljLTExLjQ2NSwxLjcwOS0yMy43NSwyLjU2LTM2LjgzOCwyLjU2DQoJYy0xMy4wODIsMC0yNS40MDItMC44NTEtMzYuOTctMi41NmMtMTEuNTYzLTEuNjg3LTIxLjY0MS00LjAyMy0zMC4yNTItNi45NzljLTguNTk4LTIuOTU0LTE1LjM3MS02LjM2OC0yMC4yOTUtMTAuMjIzDQoJYy00LjkzNC0zLjg1LTcuMzk2LTguMDI2LTcuMzk2LTEyLjUxNnYtOS40MDdjLTMuMDU0LDAtNS4yODMtMS42ODctNi43Mi01LjEwMUMwLjI3NSwyMTEuMDc1LDAsMjA5LjAyLDAsMjA2LjUwMnYtMjAuOTYxDQoJYzAtMi44NjksMC44OTYtNi4wMjEsMi42OTEtOS40MTJjMS43ODMtMy40MTMsNS40NTktNi45MTIsMTEuMDE4LTEwLjUwMWMtMS4yNDgtMC43MDEtMi4zMy0yLjE0NS0zLjIyNy00LjI4OQ0KCWMtMC41MzctMS4yNDYtMC44MDItMy4yMzctMC44MDItNS45MXYtMjAuMTc0YzAtOC4yNTIsNS4yODctMTUuNDI5LDE1Ljg2NS0yMS41MDlWOTYuNTIyYzAtNC4xMTEsMi4wMTUtNy45NjYsNi4wNTEtMTEuNTU1DQoJYzQuMDI1LTMuNTg5LDkuNTQxLTYuNzE5LDE2LjUzMS05LjQwNmM2Ljk5LTIuNjk0LDE1LjE0NC00Ljc5NiwyNC40NzgtNi4zMjRjOS4zMTEtMS41MTEsMTkuMjY4LTIuMjc1LDI5LjgzNC0yLjI3NQ0KCWMxMC41OCwwLDIwLjUyNSwwLjc2NywyOS44NDcsMi4yNzVjOS4zMjQsMS41MjgsMTcuNDQxLDMuNjMsMjQuMzQ2LDYuMzI0YzYuODkzLDIuNjg4LDEyLjM2Myw1LjgxNywxNi40LDkuNDA2DQoJYzQuMDI1LDMuNTg5LDYuMDQ5LDcuNDQyLDYuMDQ5LDExLjU1NXYxNy4yMjRjMTAuNzU4LDYuMDgsMTYuMTI5LDEzLjI1NywxNi4xMjksMjEuNTA5djIwLjE3NGMwLDIuNjczLTAuMjY0LDQuNjY0LTAuODA5LDUuOTENCglDMTkzLjUwNiwxNjMuNDgzLDE5Mi4zNDUsMTY0LjkyNywxOTAuOTEyLDE2NS42Mjh6IE0xOTkuNTExLDE4NS41NDFjMC01LjU1OS0zLjkzOS0xMC44NTQtMTEuODI2LTE1Ljg2NnY1LjkxDQoJYzAsNC4zMS0yLjI0Miw4LjMzNy02LjcyMSwxMi4wOTdjLTQuNDg0LDMuNzY2LTEwLjU3OCw3LjA0OS0xOC4yOTEsOS44MjZjLTcuNzAzLDIuNzgtMTYuNzA3LDQuOTcxLTI3LjAxNCw2LjU4OQ0KCWMtMTAuMzE4LDEuNTk3LTIxLjM3OSwyLjQwNS0zMy4yMTksMi40MDVjLTExLjgyNiwwLTIyLjg5OC0wLjgxLTMzLjIwNS0yLjQwNWMtMTAuMzA2LTEuNjE4LTE5LjMxMi0zLjgwOS0yNy4wMjMtNi41ODkNCgljLTcuNzAzLTIuNzc3LTEzLjc5Ny02LjA2Mi0xOC4yODItOS44MjZjLTQuNDc2LTMuNzYtNi43Mi03Ljc4Ny02LjcyLTEyLjA5N3YtNS45MWMtOC4wNjIsNS4wMTItMTIuMTAyLDEwLjMwOS0xMi4xMDIsMTUuODY2DQoJdjIwLjk2MWMwLDQuMTM4LDAuODEyLDYuMTkyLDIuNDIsNi4xOTJjMC41MzUsMCwwLjg5Ny0wLjMwNywxLjA3Mi0wLjkzOWMwLjE4Ni0wLjYzNCwwLjQ5MS0xLjM1NywwLjk0LTIuMTQ2DQoJYzAuNDQ5LTAuODExLDEuMDgyLTEuNTMzLDEuODgxLTIuMTY3YzAuODEyLTAuNjEyLDIuMDIzLTAuOTQsMy42MzYtMC45NGMxLjYxNiwwLDIuODE5LDEuMDc1LDMuNjMxLDMuMjM4DQoJYzAuODA5LDIuMTQ2LDEuNjA2LDQuNTI3LDIuNDE4LDcuMTE0YzAuODA5LDIuNjA0LDEuNzA1LDQuOTg2LDIuNjksNy4xMzNjMC45ODQsMi4xNDUsMi40NjEsMy4yMTUsNC40MzMsMy4yMTUNCgljMS43OTMsMCwzLjMxMi0wLjQ4LDQuNTcxLTEuNDY2YzEuMjYxLTAuOTg2LDIuNDE4LTIuMDU2LDMuNTAyLTMuMjM4YzEuMDcyLTEuMTU5LDIuMjMyLTIuMjI5LDMuNDktMy4yMTVzMi44NjUtMS40ODgsNC44NDktMS40ODgNCgljMS45NjksMCwzLjQ4NywxLjA5Myw0LjU2MSwzLjIzN2MxLjA4NCwyLjE0NiwyLjExMyw0LjU3MywzLjA5OSw3LjI2NGMwLjk4MywyLjY3MSwyLjEsNS4xLDMuMzU4LDcuMjQ0DQoJYzEuMjU2LDIuMTY4LDIuOTYzLDMuMjM3LDUuMTA3LDMuMjM3YzIuMTU3LDAsMy44MDktMC43MjEsNC45NzktMi4xNDZjMS4xNi0xLjQ0MiwyLjI0NC0yLjk3MywzLjIyOC00LjU3Mw0KCWMwLjk4NS0xLjYxOCwyLjEwNC0zLjE0NywzLjM1OC00LjU3M2MxLjI0OC0xLjQ0MywzLjEzMi0yLjE2Miw1LjY0Ni0yLjE2MmMyLjMzLDAsNC4zNDQsMS4wNDcsNi4wNTEsMy4xMDUNCgljMS43MDcsMi4wNTcsMy4zNTksNC4yODYsNC45NzksNi43MmMxLjYwNiwyLjQyOSwzLjQ0Niw0LjY1OCw1LjUwMiw2LjcxNWMyLjA2NywyLjA1OSw0LjcxNywzLjA4NCw3LjkzMiwzLjA4NA0KCWMzLjIzLDAsNS44NzYtMS4wMjUsNy45MzMtMy4wODRjMi4wNy0yLjA1NywzLjkwNi00LjI4Niw1LjUxNi02LjcxNWMxLjYxOS0yLjQzNCwzLjIyOS00LjY2Myw0Ljg0OC02LjcyDQoJYzEuNjA3LTIuMDYsMy42NjYtMy4xMDUsNi4xODItMy4xMDVjMi4zMywwLDQuMTcsMC43MTksNS41MTQsMi4xNjJjMS4zNDYsMS40MjYsMi40NjMsMi45NTUsMy4zNTksNC41NzMNCgljMC44OTYsMS42MDIsMS45MjgsMy4xMzEsMy4wOTYsNC41NzNjMS4xNiwxLjQyNSwyLjgyNCwyLjE0Niw0Ljk2NywyLjE0NmMyLjE1OCwwLDMuODUyLTEuMDY5LDUuMTExLTMuMjM3DQoJYzEuMjU4LTIuMTQ2LDIuMzc1LTQuNTczLDMuMzU5LTcuMjQ0YzAuOTgyLTIuNjg5LDIuMDU1LTUuMTE4LDMuMjI3LTcuMjY0czIuNzM0LTMuMjM3LDQuNzAzLTMuMjM3YzEuOTcxLDAsMy41OSwwLjUwMyw0Ljg1LDEuNDg4DQoJYzEuMjQ4LDAuOTg0LDIuNDE2LDIuMDU2LDMuNDg4LDMuMjE1YzEuMDcyLDEuMTg0LDIuMTk5LDIuMjUzLDMuMzU5LDMuMjM4YzEuMTcyLDAuOTg0LDIuNzM0LDEuNDY2LDQuNzA1LDEuNDY2DQoJYzEuNzkzLDAsMy4yMjktMS4wNyw0LjMwOS0zLjIxNWMxLjA3NC0yLjE0NiwxLjk3MS00LjUyOSwyLjY4NC03LjEzM2MwLjcyMy0yLjU4NywxLjUyLTQuOTcsMi40MjgtNy4xMTQNCgljMC44ODctMi4xNjMsMi4xNDYtMy4yMzgsMy43NjgtMy4yMzhjMS42MDUsMCwyLjgyLDAuMzI4LDMuNjE3LDAuOTRjMC44MTIsMC42MzQsMS4zOTMsMS4zNTYsMS43NTIsMi4xNjcNCgljMC4zNTksMC43ODcsMC42NzgsMS41MTIsMC45MzksMi4xNDZjMC4yNzUsMC42MzQsMC41OCwwLjkzOSwwLjk0MSwwLjkzOWMxLjYyMSwwLDIuNDE4LTIuMDU2LDIuNDE4LTYuMTkydi0yMC45NjFIMTk5LjUxMXoNCgkgTTE0Ljc5NCwxNTUuNDI4YzAsMy45MzksMC43OTksNS45MSwyLjQxNiw1LjkxYzAuMzYzLDAsMC42MjMtMC4zMDcsMC44MTItMC45MzljMC4xNzYtMC42MzYsMC40MzgtMS4yOTEsMC43OTktMi4wMTYNCglzMC44OTYtMS4zOTcsMS42MjEtMi4wMTVjMC43MTEtMC42MzQsMS43ODMtMC45NCwzLjIyNy0wLjk0YzEuNDMzLDAsMi41NDksMS4wMywzLjM1OSwzLjA4NWMwLjgwOSwyLjA2MSwxLjUyLDQuMzk4LDIuMTQzLDYuOTc5DQoJYzAuNjM3LDIuNjA3LDEuNDM1LDQuOTQ3LDIuNDMxLDcuMDA3YzAuOTg1LDIuMDU2LDIuMjg2LDMuMDg1LDMuODk1LDMuMDg1YzEuNzk1LDAsMy4yMjgtMC40OCw0LjMwMS0xLjQ2Ng0KCWMxLjA4Mi0wLjk4NSwyLjA1OC0yLjAzNywyLjk2Ni0zLjEwN2MwLjg4Ny0xLjA3NCwxLjkxMy0yLjEwMSwzLjA4Ni0zLjA4NWMxLjE3LTAuOTg0LDIuNjQ3LTEuNDg3LDQuNDQtMS40ODcNCgljMS43ODMsMCwzLjIyOSwxLjA0Nyw0LjMwMiwzLjEwNmMxLjA3MSwyLjA1NiwyLjA1Nyw0LjM3NSwyLjk1Miw2Ljk3OWMwLjg5OCwyLjYwNCwxLjg4NCw0Ljk0NSwyLjk2Niw3LjAwMQ0KCWMxLjA3MSwyLjA1NSwyLjU5MiwzLjA4NSw0LjU2MiwzLjA4NWMxLjk2OSwwLDMuNDU3LTAuNjgsNC40NDEtMi4wMTZjMC45ODUtMS4zNTQsMS45MjYtMi44MTgsMi44MjEtNC40MzgNCgljMC44OTgtMS42MTksMS45MjktMy4wODUsMy4wOTktNC40NDNjMS4xNi0xLjMzNSwyLjgyMy0yLjAxNSw0Ljk2Ny0yLjAxNWMyLjE1NiwwLDMuOTM4LDAuOTg1LDUuMzgzLDIuOTU1DQoJYzEuNDM1LDEuOTkyLDIuOTEyLDQuMTM4LDQuNDMzLDYuNDUzYzEuNTIsMi4zNDQsMy4xODQsNC40ODcsNC45NzksNi40NThjMS43ODMsMS45Nyw0LjIxMSwyLjk1NCw3LjI1MiwyLjk1NA0KCWMyLjg2OSwwLDUuMjUzLTAuOTg0LDcuMTM2LTIuOTU0YzEuODgxLTEuOTcxLDMuNTc2LTQuMTE0LDUuMTA3LTYuNDU4YzEuNTItMi4zMTUsMi45OTgtNC40NjEsNC40MzItNi40NTMNCgljMS40MzYtMS45NywzLjIyOS0yLjk1NSw1LjM4NS0yLjk1NWMyLjE0NSwwLDMuODA3LDAuNjgsNC45NjcsMi4wMTVjMS4xNywxLjM1OCwyLjE5OSwyLjgyNCwzLjA5OCw0LjQ0Mw0KCWMwLjg5NiwxLjYxOSwxLjgzNiwzLjA4NSwyLjgyMiw0LjQzOGMwLjk4NCwxLjMzNiwyLjQ2MSwyLjAxNiw0LjQ0MSwyLjAxNmMxLjk3MSwwLDMuNDg4LTEuMDMsNC41NjEtMy4wODUNCgljMS4wODQtMi4wNTYsMi4wNTktNC4zOTcsMi45NjUtNy4wMDFjMC44ODctMi42MDQsMS44ODEtNC45MjYsMi45NTMtNi45NzljMS4wNzQtMi4wNjEsMi41MDgtMy4xMDYsNC4zMDMtMy4xMDYNCglzMy4yMjksMC41MDMsNC4yOTksMS40ODdjMS4wODQsMC45ODQsMi4xMTEsMi4wMTEsMy4wOTgsMy4wODVjMC45ODQsMS4wNywyLjAxNCwyLjEyMiwzLjA5OCwzLjEwNw0KCWMxLjA3MiwwLjk4NCwyLjQxNiwxLjQ2Niw0LjAyNSwxLjQ2NmMxLjc5NSwwLDMuMTM5LTEuMDI5LDQuMDM3LTMuMDg1YzAuODk1LTIuMDYsMS42OTMtNC4zOTgsMi40MTYtNy4wMDcNCgljMC43MTMtMi41ODEsMS40MzYtNC45MiwyLjE1Ni02Ljk3OWMwLjcwOS0yLjA1NSwxLjc4My0zLjA4NSwzLjIyNy0zLjA4NWMxLjQzNiwwLDIuNTA4LDAuMzA4LDMuMjI5LDAuOTQNCgljMC43MTEsMC42MTYsMS4yOTEsMS4yOSwxLjczOCwyLjAxNWMwLjQ0OSwwLjcyNSwwLjc2NiwxLjM4LDAuOTQxLDIuMDE2YzAuMTc2LDAuNjM0LDAuNDQ3LDAuOTM5LDAuODExLDAuOTM5DQoJYzEuNDMyLDAsMi4xNTYtMS45NzEsMi4xNTYtNS45MXYtMjAuMTc0YzAtNS43MzItMy41OS0xMC44NTQtMTAuNzU4LTE1LjMxNXY2LjE3YzAsNC4xMzctMi4wMjMsNy45NC02LjA0OSwxMS40MjINCgljLTQuMDM3LDMuNDk5LTkuNTEsNi41ODgtMTYuNCw5LjI3NmMtNi45MDQsMi42OTQtMTUuMDIxLDQuODE2LTI0LjM0Niw2LjMyM2MtOS4zMjIsMS41MzMtMTkuMjY4LDIuMjk4LTI5Ljg0OCwyLjI5OA0KCWMtMTAuNTY1LDAtMjAuNTIyLTAuNzY1LTI5LjgzNC0yLjI5OGMtOS4zMzQtMS41MDctMTcuNDg1LTMuNjI5LTI0LjQ3Ny02LjMyM2MtNi45OS0yLjY4OC0xMi41MDYtNS43NzctMTYuNTMxLTkuMjc2DQoJYy00LjAzNy0zLjQ4LTYuMDUxLTcuMjg1LTYuMDUxLTExLjQyMnYtNi4xN2MtMy40MTIsMi4xNDUtNi4wNTIsNC41MjctNy45MzMsNy4xMDhjLTEuODgzLDIuNjA0LTIuODIxLDUuMzM5LTIuODIxLDguMjA3djIwLjE3NA0KCUgxNC43OTR6IE01MC4wMTQsODAuMzk1Yy02LjA5NSwyLjM0My0xMC44LDQuOTQ1LTE0LjExMyw3LjgxMWMtMy4zMTQsMi44Ny00Ljk3OSw1LjY0OC00Ljk3OSw4LjMxNg0KCWMwLDIuNjkyLDEuNjY0LDUuNDI4LDQuOTc5LDguMjA3YzMuMzEzLDIuNzc3LDguMDIsNS4zMzcsMTQuMTEzLDcuNjU3YzYuOTg5LDIuNjkzLDE0Ljk2Nyw0Ljc3MSwyMy45Myw2LjE5Mg0KCWM4Ljk1OSwxLjQ0MywxOC40NjcsMi4xNDUsMjguNDk4LDIuMTQ1YzEwLjA0NCwwLDE5LjU0Mi0wLjcsMjguNTE1LTIuMTQ1YzguOTU5LTEuNDIxLDE2LjkzOC0zLjQ5OSwyMy45MjYtNi4xOTINCgljNS45MS0yLjMyLDEwLjU3LTQuODgsMTMuOTg0LTcuNjU3YzMuNC0yLjc3OSw1LjEwOS01LjUxNSw1LjEwOS04LjIwN2MwLTIuNjY4LTEuNzA5LTUuNDQ2LTUuMTA5LTguMzE2DQoJYy0zLjQxNC0yLjg2NC04LjA3NC01LjQ2OC0xMy45ODQtNy44MTFjLTYuOTg4LTIuNjg5LTE0Ljk2Ny00Ljc1LTIzLjkyNi02LjE3MWMtOC45NzMtMS40NDItMTguNDcxLTIuMTY4LTI4LjUxNS0yLjE2OA0KCWMtMTAuMDMxLDAtMTkuNTM5LDAuNzI2LTI4LjQ5OCwyLjE2OEM2NC45ODEsNzUuNjQ1LDU3LjAwMyw3Ny43MDYsNTAuMDE0LDgwLjM5NXoiLz4NCjwvc3ZnPg==',
    'progress-6' => 'data:image/svg+xml;base64,PHN2ZyB2ZXJzaW9uPSIxLjEiIGlkPSJMYXllcl8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCINCgkgd2lkdGg9IjIwNC44OTVweCIgaGVpZ2h0PSIyNTkuNDc5cHgiIHZpZXdCb3g9IjAgMCAyMDQuODk1IDI1OS40NzkiIGVuYWJsZS1iYWNrZ3JvdW5kPSJuZXcgMCAwIDIwNC44OTUgMjU5LjQ3OSINCgkgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+DQo8cGF0aCBmaWxsPSIjRDMzRDQ0IiBkPSJNMTkwLjkxNCwxNjUuNjI4YzUuNTYsMy41ODksOS4yNzcsNy4wODgsMTEuMTYsMTAuNTAxYzEuODgxLDMuMzkyLDIuODIsNi41NDMsMi44Miw5LjQxMnYyMC45NjENCgljMCwyLjY5NC0wLjM1OSw0Ljc0OS0xLjA3LDYuMTkyYy0xLjA4NCwzLjQxNC0zLjMyNiw1LjEwMS02LjcyOSw1LjEwMXY5LjQwN2MwLDQuNDg4LTIuNDYzLDguNjY2LTcuMzk2LDEyLjUxNg0KCWMtNC45MjQsMy44NTQtMTEuNjk1LDcuMjY5LTIwLjI5NywxMC4yMjNjLTguNjA5LDIuOTU1LTE4LjY0NCw1LjI5My0zMC4xMTksNi45NzljLTExLjQ2NywxLjcwOS0yMy43NTQsMi41Ni0zNi44NCwyLjU2DQoJYy0xMy4wODQsMC0yNS40MDQtMC44NTEtMzYuOTY5LTIuNTZjLTExLjU2Ni0xLjY4Ny0yMS42NDQtNC4wMjMtMzAuMjU1LTYuOTc5Yy04LjU5OC0yLjk1NC0xNS4zNy02LjM2OC0yMC4yOTUtMTAuMjIzDQoJYy00LjkzNC0zLjg1LTcuMzk1LTguMDI2LTcuMzk1LTEyLjUxNnYtOS40MDdjLTMuMDU1LDAtNS4yODUtMS42ODctNi43MTktNS4xMDFDMC4yNzQsMjExLjA3NSwwLDIwOS4wMiwwLDIwNi41MDJ2LTIwLjk2MQ0KCWMwLTIuODY5LDAuODk2LTYuMDIxLDIuNjkxLTkuNDEyYzEuNzgyLTMuNDEzLDUuNDU5LTYuOTEyLDExLjAxOC0xMC41MDFjLTEuMjQ0LTAuNzAxLTIuMzI4LTIuMTQ1LTMuMjI3LTQuMjg5DQoJYy0wLjUzNy0xLjI0Ni0wLjc5OC0zLjIzNy0wLjc5OC01Ljkxdi0yMC4xNzRjMC03LjAwMiwzLjkzNy0xMy4yNjIsMTEuODI2LTE4LjgxOWMtMC4zNi0wLjM3My0wLjc2OC0wLjg1NC0xLjIxNy0xLjQ4OA0KCWMtMC40NDctMC42MTEtMC44NC0xLjM3Ni0xLjIwMy0yLjI3NWMtMC4zNTgtMS43OTUtMC41MzUtMy42NzQtMC41MzUtNS42NDRWODcuOTI0YzAtNS4xODUsMi4yMzItMTAuMDg3LDYuNzE4LTE0LjYzNw0KCWM0LjQ3Ny00LjU3NCwxMS4wMjgtOC40OTEsMTkuNjMxLTExLjcwN2M3LjY5OC0zLjA0NCwxNi40ODctNS4zNDIsMjYuMzU0LTYuODU0YzkuODU5LTEuNTI3LDIwLjI1Mi0yLjI5MywzMS4xODMtMi4yOTMNCgljMTAuNzU0LDAsMjEuMDYyLDAuNzY2LDMwLjkzLDIuMjkzYzkuODU3LDEuNTEzLDE4LjcyOSwzLjgxMSwyNi42MjEsNi44NTRjOC41OTgsMy4yMTYsMTUuMTQzLDcuMTMzLDE5LjYyNywxMS43MDcNCgljNC40NzUsNC41NSw2LjcxOSw5LjQ1Miw2LjcxOSwxNC42Mzd2MTkuMTA0YzAsMi41MTMtMC4yNjIsNC4zOTYtMC44MDksNS42NDRjLTAuNTM3LDEuNzk1LTEuMzM2LDMuMDQ1LTIuNDIsMy43NjUNCgljOC4wNzMsNS41NTksMTIuMTAyLDExLjgxNywxMi4xMDIsMTguODE5djIwLjE3NGMwLDIuNjczLTAuMjYyLDQuNjY0LTAuODA5LDUuOTFDMTkzLjUwOCwxNjMuNDgzLDE5Mi4zNDYsMTY0LjkyNywxOTAuOTE0LDE2NS42Mjh6DQoJIE0xOTkuNTE0LDE4NS41NDFjMC01LjU1OS0zLjkzOC0xMC44NTQtMTEuODI4LTE1Ljg2NnY1LjkxYzAsNC4zMS0yLjI0Miw4LjMzNy02LjcxOCwxMi4wOTcNCgljLTQuNDg1LDMuNzY2LTEwLjU4Miw3LjA0OS0xOC4yOTMsOS44MjZjLTcuNzAyLDIuNzgtMTYuNzA3LDQuOTcxLTI3LjAxNiw2LjU4OWMtMTAuMzEzLDEuNTk3LTIxLjM3OSwyLjQwNS0zMy4yMTcsMi40MDUNCgljLTExLjgyOCwwLTIyLjg5OC0wLjgxLTMzLjIwNy0yLjQwNWMtMTAuMzA2LTEuNjE4LTE5LjMxMi0zLjgwOS0yNy4wMjEtNi41ODljLTcuNzAzLTIuNzc3LTEzLjc5Ny02LjA2Mi0xOC4yODItOS44MjYNCgljLTQuNDc2LTMuNzYtNi43Mi03Ljc4Ny02LjcyLTEyLjA5N3YtNS45MWMtOC4wNjMsNS4wMTItMTIuMSwxMC4zMDktMTIuMSwxNS44NjZ2MjAuOTYxYzAsNC4xMzgsMC44MDksNi4xOTIsMi40MTgsNi4xOTINCgljMC41MzMsMCwwLjg5Ni0wLjMwNywxLjA3Mi0wLjkzOWMwLjE4Ni0wLjYzNCwwLjQ5MS0xLjM1NywwLjkzOC0yLjE0NmMwLjQ0OS0wLjgxMSwxLjA4NC0xLjUzNCwxLjg4MS0yLjE2Nw0KCWMwLjgxMi0wLjYxMiwyLjAyNS0wLjk0LDMuNjM1LTAuOTRjMS42MTksMCwyLjgyMiwxLjA3NSwzLjYzMiwzLjIzOGMwLjgxMSwyLjE0NiwxLjYwNiw0LjUyNywyLjQyLDcuMTE0DQoJYzAuODA5LDIuNjA0LDEuNzA3LDQuOTg2LDIuNjksNy4xMzNjMC45ODIsMi4xNDUsMi40NTksMy4yMTUsNC40MzEsMy4yMTVjMS43OTMsMCwzLjMxNS0wLjQ4LDQuNTczLTEuNDY2DQoJYzEuMjU5LTAuOTg2LDIuNDE2LTIuMDU2LDMuNS0zLjIzOGMxLjA3Mi0xLjE1OSwyLjIzMi0yLjIyOSwzLjQ5LTMuMjE1czIuODY3LTEuNDg4LDQuODQ5LTEuNDg4YzEuOTY5LDAsMy40ODksMS4wOTMsNC41NjIsMy4yMzcNCgljMS4wODQsMi4xNDYsMi4xMTEsNC41NzMsMy4wOTYsNy4yNjRjMC45ODQsMi42NzEsMi4xMDEsNS4xLDMuMzU5LDcuMjQ0YzEuMjU2LDIuMTY4LDIuOTY3LDMuMjM3LDUuMTA5LDMuMjM3DQoJYzIuMTU1LDAsMy44MDktMC43MjEsNC45NzctMi4xNDZjMS4xNjItMS40NDIsMi4yNDYtMi45NzMsMy4yMy00LjU3M2MwLjk4My0xLjYxOCwyLjEtMy4xNDcsMy4zNTYtNC41NzMNCgljMS4yNDgtMS40NDMsMy4xMjktMi4xNjIsNS42NDYtMi4xNjJjMi4zMywwLDQuMzQ0LDEuMDQ3LDYuMDQ5LDMuMTA1YzEuNzA3LDIuMDU3LDMuMzU4LDQuMjg2LDQuOTc5LDYuNzINCgljMS42MDcsMi40MjksMy40NDcsNC42NTgsNS41MDcsNi43MTVjMi4wNjMsMi4wNTksNC43MTUsMy4wODQsNy45MywzLjA4NGMzLjIyOSwwLDUuODc1LTEuMDI1LDcuOTM0LTMuMDg0DQoJYzIuMDY4LTIuMDU3LDMuOTA0LTQuMjg2LDUuNTE1LTYuNzE1YzEuNjE4LTIuNDM0LDMuMjI3LTQuNjYzLDQuODQ4LTYuNzJjMS42MDctMi4wNiwzLjY2NC0zLjEwNSw2LjE4LTMuMTA1DQoJYzIuMzMyLDAsNC4xNzEsMC43MTksNS41MTcsMi4xNjJjMS4zNDYsMS40MjYsMi40NjEsMi45NTUsMy4zNTYsNC41NzNjMC44OTYsMS42MDIsMS45MjksMy4xMzEsMy4xMDEsNC41NzMNCgljMS4xNTgsMS40MjUsMi44MTksMi4xNDYsNC45NjcsMi4xNDZjMi4xNTQsMCwzLjg1LTEuMDY5LDUuMTA3LTMuMjM3YzEuMjYtMi4xNDYsMi4zNzUtNC41NzMsMy4zNTgtNy4yNDQNCgljMC45ODYtMi42ODksMi4wNi01LjExOCwzLjIyOC03LjI2NGMxLjE3Mi0yLjE0NiwyLjczNS0zLjIzNyw0LjcwNy0zLjIzN2MxLjk2NywwLDMuNTg4LDAuNTAzLDQuODQ2LDEuNDg4DQoJYzEuMjQ4LDAuOTg0LDIuNDE4LDIuMDU2LDMuNDg4LDMuMjE1YzEuMDc0LDEuMTg0LDIuMjAxLDIuMjUzLDMuMzYsMy4yMzhjMS4xNzEsMC45ODQsMi43MzYsMS40NjYsNC43MDMsMS40NjYNCgljMS43OTUsMCwzLjIyOS0xLjA3LDQuMzEyLTMuMjE1YzEuMDY5LTIuMTQ2LDEuOTY3LTQuNTI5LDIuNjgyLTcuMTMzYzAuNzIxLTIuNTg3LDEuNTIxLTQuOTcsMi40MjgtNy4xMTQNCgljMC44ODYtMi4xNjMsMi4xNDYtMy4yMzgsMy43NjUtMy4yMzhjMS42MDYsMCwyLjgyMiwwLjMyOCwzLjYyMSwwLjk0YzAuODA5LDAuNjMzLDEuMzg5LDEuMzU2LDEuNzUsMi4xNjcNCgljMC4zNjIsMC43ODcsMC42OCwxLjUxMiwwLjkzOCwyLjE0NmMwLjI3NSwwLjYzNCwwLjU4MiwwLjkzOSwwLjk0MywwLjkzOWMxLjYxOSwwLDIuNDE4LTIuMDU2LDIuNDE4LTYuMTkyTDE5OS41MTQsMTg1LjU0MQ0KCUwxOTkuNTE0LDE4NS41NDF6IE0xNC43OTUsMTU1LjQyOGMwLDMuOTM5LDAuNzk5LDUuOTEsMi40MTgsNS45MWMwLjM2MSwwLDAuNjI1LTAuMzA3LDAuODEtMC45MzkNCgljMC4xNzYtMC42MzYsMC40MzgtMS4yOTEsMC44MDEtMi4wMTZjMC4zNTktMC43MjUsMC44OTYtMS4zOTcsMS42MTktMi4wMTVjMC43MTEtMC42MzQsMS43ODMtMC45NCwzLjIyNy0wLjk0DQoJYzEuNDM3LDAsMi41NTEsMS4wMywzLjM1OSwzLjA4NWMwLjgwOSwyLjA2MSwxLjUyLDQuMzk4LDIuMTQ1LDYuOTc5YzAuNjM2LDIuNjA3LDEuNDM1LDQuOTQ3LDIuNDMxLDcuMDA3DQoJYzAuOTgzLDIuMDU2LDIuMjg3LDMuMDg1LDMuODk1LDMuMDg1YzEuNzkzLDAsMy4yMjktMC40OCw0LjI5OS0xLjQ2NmMxLjA4NC0wLjk4NSwyLjA1OC0yLjAzNywyLjk2Ni0zLjEwNw0KCWMwLjg4Ny0xLjA3NCwxLjkxNi0yLjEwMSwzLjA4Ni0zLjA4NXMyLjY0Ny0xLjQ4Nyw0LjQ0Mi0xLjQ4N2MxLjc4MywwLDMuMjI4LDEuMDQ3LDQuMjk5LDMuMTA2DQoJYzEuMDcyLDIuMDU2LDIuMDU4LDQuMzc1LDIuOTU1LDYuOTc5YzAuODk2LDIuNjAzLDEuODgxLDQuOTQ1LDIuOTYzLDcuMDAxYzEuMDcyLDIuMDU1LDIuNTk3LDMuMDg1LDQuNTY0LDMuMDg1DQoJYzEuOTY3LDAsMy40NTUtMC42OCw0LjQ0LTIuMDE2YzAuOTg0LTEuMzU0LDEuOTI1LTIuODE4LDIuODIyLTQuNDM4YzAuODk2LTEuNjE5LDEuOTI2LTMuMDg1LDMuMDk5LTQuNDQzDQoJYzEuMTU3LTEuMzM1LDIuODIxLTIuMDE1LDQuOTY3LTIuMDE1YzIuMTU0LDAsMy45MzgsMC45ODUsNS4zODMsMi45NTVjMS40MzUsMS45OTIsMi45MSw0LjEzOCw0LjQzMSw2LjQ1Mw0KCWMxLjUyMiwyLjM0MywzLjE4NCw0LjQ4Nyw0Ljk3OSw2LjQ1OGMxLjc4MywxLjk3LDQuMjE1LDIuOTU0LDcuMjU0LDIuOTU0YzIuODY3LDAsNS4yNTItMC45ODQsNy4xMzUtMi45NTQNCgljMS44ODEtMS45NzEsMy41NzgtNC4xMTUsNS4xMDktNi40NThjMS41MjEtMi4zMTUsMi45OTYtNC40NjEsNC40My02LjQ1M2MxLjQzNS0xLjk3LDMuMjI5LTIuOTU1LDUuMzg2LTIuOTU1DQoJYzIuMTQ1LDAsMy44MDcsMC42OCw0Ljk2NywyLjAxNWMxLjE3MiwxLjM1OCwyLjE5OSwyLjgyNCwzLjA5Niw0LjQ0M2MwLjg5OCwxLjYxOSwxLjg0LDMuMDg1LDIuODIyLDQuNDM4DQoJYzAuOTgzLDEuMzM2LDIuNDYzLDIuMDE2LDQuNDQyLDIuMDE2YzEuOTcsMCwzLjQ5LTEuMDMsNC41NjItMy4wODVjMS4wODQtMi4wNTYsMi4wNTctNC4zOTgsMi45NjUtNy4wMDENCgljMC44ODctMi42MDQsMS44ODMtNC45MjYsMi45NTMtNi45NzljMS4wNzItMi4wNjEsMi41MDgtMy4xMDYsNC4zMDEtMy4xMDZjMS43OTUsMCwzLjIyOCwwLjUwMyw0LjI5OSwxLjQ4Nw0KCWMxLjA4NCwwLjk4NCwyLjExMSwyLjAxMSwzLjA5OSwzLjA4NWMwLjk4MywxLjA3LDIuMDEyLDIuMTIyLDMuMDk2LDMuMTA3YzEuMDcyLDAuOTg0LDIuNDE4LDEuNDY2LDQuMDI3LDEuNDY2DQoJYzEuNzkzLDAsMy4xMzktMS4wMjksNC4wMzQtMy4wODVjMC44OTYtMi4wNiwxLjY5NS00LjM5OCwyLjQyMS03LjAwN2MwLjcxMS0yLjU4MSwxLjQzNC00LjkyLDIuMTUzLTYuOTc5DQoJYzAuNzExLTIuMDU1LDEuNzgzLTMuMDg1LDMuMjI5LTMuMDg1YzEuNDM0LDAsMi41MDYsMC4zMDgsMy4yMjcsMC45NGMwLjcxMiwwLjYxNiwxLjI5MSwxLjI5LDEuNzQsMi4wMTUNCgljMC40NDcsMC43MjUsMC43NjcsMS4zOCwwLjk0MSwyLjAxNmMwLjE3NCwwLjYzNCwwLjQ0OCwwLjkzOSwwLjgwOSwwLjkzOWMxLjQzNSwwLDIuMTU2LTEuOTcxLDIuMTU2LTUuOTF2LTIwLjE3NA0KCWMwLTUuNzMyLTMuNTg4LTEwLjg1NC0xMC43NTQtMTUuMzE1djYuMTdjMCw0LjEzNy0yLjAyNyw3Ljk0LTYuMDU0LDExLjQyMmMtNC4wMzYsMy40OTktOS41MDgsNi41ODgtMTYuMzk5LDkuMjc2DQoJYy02LjkwMiwyLjY5NC0xNS4wMjEsNC44MTYtMjQuMzQ0LDYuMzIzYy05LjMyLDEuNTMzLTE5LjI2NywyLjI5OC0yOS44NDksMi4yOThjLTEwLjU2NywwLTIwLjUyNC0wLjc2NS0yOS44MzYtMi4yOTgNCgljLTkuMzMyLTEuNTA3LTE3LjQ4NC0zLjYyOS0yNC40NzUtNi4zMjNjLTYuOTkyLTIuNjg4LTEyLjUwNC01Ljc3Ny0xNi41MzEtOS4yNzZjLTQuMDM5LTMuNDgtNi4wNTEtNy4yODUtNi4wNTEtMTEuNDIydi02LjE3DQoJYy0zLjQxNCwyLjE0NS02LjA1MSw0LjUyNy03LjkzNSw3LjEwOGMtMS44ODMsMi42MDQtMi44MjEsNS4zMzktMi44MjEsOC4yMDd2MjAuMTc0SDE0Ljc5NXogTTIzLjY2OCwxMDcuMDI3DQoJYzAsMy43NjQsMC42MjUsNS42NDQsMS44ODQsNS42NDRjMC4zNSwwLDAuNjIzLTAuMzA3LDAuNzk3LTAuOTM4YzAuMTg4LTAuNjM2LDAuNDUxLTEuMjQ2LDAuODExLTEuODgxDQoJYzAuMzYxLTAuNjQsMC44NTQtMS4yNSwxLjQ3OC0xLjg4NWMwLjYzNS0wLjYzNCwxLjY2My0wLjkzOCwzLjA5Ni0wLjkzOGMxLjI1LDAsMi4yNDQsMC45ODMsMi45NTUsMi45NTQNCgljMC43MjMsMS45NywxLjM0OCw0LjE1NCwxLjg4Myw2LjU4NGMwLjUzNSwyLjQzMywxLjIxNSw0LjYxOSwyLjAyMiw2LjU4OGMwLjc5OCwxLjk3LDIuMDE1LDIuOTU1LDMuNjIzLDIuOTU1DQoJYzEuNjE3LDAsMi44NjUtMC40MzgsMy43NjUtMS4zMzZjMC44OTYtMC44OTYsMS43OTMtMS45MjYsMi42OS0zLjEwN2MwLjg5Ni0xLjE2LDEuODg0LTIuMTg2LDIuOTY0LTMuMDg1DQoJYzEuMDcxLTAuODk1LDIuNDItMS4zMzYsNC4wMjYtMS4zMzZjMS42MTksMCwyLjg2NywxLjAzLDMuNzY1LDMuMDg1YzAuODk3LDIuMDYxLDEuNzUsNC4zMTMsMi41NjEsNi43MTkNCgljMC43OTksMi40MywxLjY5Nyw0LjYxOCwyLjY4OSw2LjU4OGMwLjk3NSwxLjk3MiwyLjM2MiwyLjk1NSw0LjE1NywyLjk1NXMzLjE0Mi0wLjYxNSw0LjAzOS0xLjg4NQ0KCWMwLjg5Ni0xLjI0NSwxLjc0OC0yLjY4OCwyLjU2Mi00LjI4NWMwLjc5OS0xLjYyNCwxLjY5NC0zLjA0NSwyLjY4LTQuMzEyYzAuOTg2LTEuMjQ2LDIuNDYxLTEuODg2LDQuNDQxLTEuODg2DQoJYzEuOTY5LDAsMy41NzgsMC45NDQsNC44MzYsMi44MjRjMS4yNTgsMS44ODQsMi41NjIsMy45MzksMy45MDYsNi4xOTJjMS4zMzYsMi4yMzQsMi44NTQsNC4yOSw0LjU2Miw2LjE3DQoJYzEuNzA3LDEuODg1LDMuOTA2LDIuODI0LDYuNTg2LDIuODI0YzIuNjkxLDAsNC44OTItMC45MzksNi41OTktMi44MjRjMS42OTYtMS44OCwzLjIxNy0zLjkzNSw0LjU2My02LjE3DQoJYzEuMzQ0LTIuMjUzLDIuNjQ2LTQuMzEsMy45MDQtNi4xOTJjMS4yNDYtMS44OCwyLjg2NS0yLjgyNCw0LjgzNi0yLjgyNGMxLjk2OSwwLDMuNDQ3LDAuNjQsNC40NDEsMS44ODYNCgljMC45NzUsMS4yNjgsMS44ODMsMi42ODgsMi42ODIsNC4zMTJjMC44MDksMS41OTgsMS42NjQsMy4wNCwyLjU2MSw0LjI4NWMwLjg5NiwxLjI3LDIuMjMsMS44ODUsNC4wMjUsMS44ODUNCglzMy4xODQtMC45ODMsNC4xNjgtMi45NTVjMC45ODYtMS45NywxLjg4My00LjE1OCwyLjY5MS02LjU4OGMwLjgxMS0yLjQwNCwxLjY1MS00LjY1OCwyLjU2Mi02LjcxOQ0KCWMwLjg4Ni0yLjA1NSwyLjE0NC0zLjA4NSwzLjc2NS0zLjA4NWMxLjYwNiwwLDIuOTEsMC40NDEsMy44OTUsMS4zMzZjMC45ODQsMC44OTksMS45MjQsMS45MjUsMi44MjIsMy4wODUNCgljMC44OTYsMS4xODQsMS43OTMsMi4yMTMsMi42OTEsMy4xMDdjMC44OTYsMC44OTgsMi4xNDUsMS4zMzYsMy43NjQsMS4zMzZjMS42MDcsMCwyLjgyMi0wLjk4NSwzLjYzMy0yLjk1NQ0KCWMwLjc5OS0xLjk2OSwxLjUyMS00LjE1NSwyLjE0My02LjU4OGMwLjYzNi0yLjQzLDEuMzA0LTQuNjE0LDIuMDI1LTYuNTg0YzAuNzExLTEuOTcxLDEuNjk3LTIuOTU0LDIuOTUzLTIuOTU0DQoJYzEuMjQ4LDAsMi4xODgsMC4zMDYsMi44MjQsMC45MzhjMC42MjMsMC42MzUsMS4xMTUsMS4yNDUsMS40NzcsMS44ODVjMC4zNjEsMC42MzUsMC42MjMsMS4yNDUsMC44MTIsMS44ODENCgljMC4xNzQsMC42MzMsMC40NDYsMC45MzgsMC44MTEsMC45MzhjMS40MzMsMCwyLjE0NC0xLjg4LDIuMTQ0LTUuNjQ0Vjg3LjkyM2MwLTQuMjg5LTIuMDU4LTguMjkyLTYuMTgzLTExLjk3MQ0KCWMtNC4xMjUtMy42NTItOS43Ny02Ljg0OS0xNi45MzYtOS41MzhjLTcuMTc5LTIuNjk0LTE1LjUxNy00LjgzNS0yNS4wMTUtNi40NThjLTkuNDk0LTEuNTk2LTE5LjcxNS0yLjQwNi0zMC42NTUtMi40MDYNCgljLTEwLjkzMSwwLTIxLjE5MywwLjgxMi0zMC43NzUsMi40MDZjLTkuNTk4LDEuNjIzLTE3LjkzMiwzLjc2NC0yNS4wMTQsNi40NThjLTcuMDc4LDIuNjg5LTEyLjY4MSw1Ljg4Ni0xNi44MDYsOS41MzgNCgljLTQuMTI1LDMuNjc5LTYuMTgyLDcuNjgyLTYuMTgyLDExLjk3MUwyMy42NjgsMTA3LjAyN0wyMy42NjgsMTA3LjAyN3ogTTEwNy4yOTEsMTE0LjU1NWMtMS42MTksMC0yLjQxOC0wLjg5OS0yLjQxOC0yLjY5Mw0KCWMwLTEuNjE5LDAuNzk5LTIuNDI5LDIuNDE4LTIuNDI5YzkuNDk2LDAsMTguMTk1LTAuNjU2LDI2LjA4Mi0yLjAxNmM3Ljg4MS0xLjMzMSwxNC42MDctMy4wODUsMjAuMTY2LTUuMjI2DQoJYzUuNTYtMi4xNjcsOS44NTctNC42MTgsMTIuOTEtNy4zOTdjMy4wNDEtMi43NzksNC41NjItNS41MTQsNC41NjItOC4yMDdjMC0xLjc5NCwwLjg5OC0yLjY4OCwyLjY5My0yLjY4OA0KCWMxLjYxNywwLDIuNDE2LDAuODk2LDIuNDE2LDIuNjg4YzAsMy45MzktMS43OTUsNy42NTgtNS4zNzEsMTEuMTYxYy0zLjU5LDMuNDk5LTguNzg1LDYuNTg0LTE1LjYwNCw5LjI3OA0KCWMtNi40NDMsMi41MTMtMTMuNzUyLDQuMzk2LTIxLjkxNCw1LjY0NEMxMjUuMDgyLDExMy45MjEsMTE2LjQyNiwxMTQuNTU1LDEwNy4yOTEsMTE0LjU1NXoiLz4NCjwvc3ZnPg==',
    'progress-7' => 'data:image/svg+xml;base64,PHN2ZyB2ZXJzaW9uPSIxLjEiIGlkPSJMYXllcl8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCINCgkgd2lkdGg9IjIwNC44OTVweCIgaGVpZ2h0PSIyNTkuNDc5cHgiIHZpZXdCb3g9IjAgMCAyMDQuODk1IDI1OS40NzkiIGVuYWJsZS1iYWNrZ3JvdW5kPSJuZXcgMCAwIDIwNC44OTUgMjU5LjQ3OSINCgkgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+DQo8cGF0aCBmaWxsPSIjRDMzRDQ0IiBkPSJNMTkwLjkxMiwxNjUuNjI4YzUuNTU3LDMuNTg5LDkuMjc2LDcuMDg4LDExLjE1NywxMC41MDFjMS44ODQsMy4zOTIsMi44MjQsNi41NDMsMi44MjQsOS40MTJ2MjAuOTYxDQoJYzAsMi42OTQtMC4zNjEsNC43NDktMS4wNzIsNi4xOTJjLTEuMDg0LDMuNDE0LTMuMzI2LDUuMS02LjcyOSw1LjF2OS40MDhjMCw0LjQ4OC0yLjQ2MSw4LjY2Ni03LjM5NiwxMi41MTYNCgljLTQuOTI0LDMuODU0LTExLjY5Niw3LjI2OS0yMC4yOTUsMTAuMjIzYy04LjYxMywyLjk1NC0xOC42NDYsNS4yOTMtMzAuMTIzLDYuOTc5Yy0xMS40NjUsMS43MDktMjMuNzUyLDIuNTYtMzYuODM4LDIuNTYNCglzLTI1LjQwNC0wLjg1MS0zNi45NzEtMi41NmMtMTEuNTYyLTEuNjg3LTIxLjY0Mi00LjAyNC0zMC4yNS02Ljk3OWMtOC42MDMtMi45NTQtMTUuMzc1LTYuMzY4LTIwLjI5NS0xMC4yMjMNCgljLTQuOTM4LTMuODUtNy4zOTgtOC4wMjYtNy4zOTgtMTIuNTE2di05LjQwOGMtMy4wNTEsMC01LjI4My0xLjY4Ni02LjcxNy01LjFDMC4yNzIsMjExLjA3NSwwLDIwOS4wMiwwLDIwNi41MDJ2LTIwLjk2MQ0KCWMwLTIuODY5LDAuODk2LTYuMDIxLDIuNjkxLTkuNDEyYzEuNzgzLTMuNDEzLDUuNDU5LTYuOTEyLDExLjAxOC0xMC41MDFjLTEuMjQ4LTAuNzAxLTIuMzMyLTIuMTQ1LTMuMjI5LTQuMjg5DQoJYy0wLjUzNS0xLjI0Ny0wLjc5OS0zLjIzNy0wLjc5OS01Ljkxdi0yMC4xNzRjMC03LjAwMiwzLjkzOC0xMy4yNjIsMTEuODI4LTE4LjgxOWMtMC4zNjMtMC4zNzMtMC43NjktMC44NTQtMS4yMTUtMS40ODgNCgljLTAuNDUxLTAuNjExLTAuODQ1LTEuMzc2LTEuMjAzLTIuMjc1Yy0wLjM2MS0xLjc5NS0wLjUzNy0zLjY3NC0wLjUzNy01LjY0NVY4Ny45MjNjMC04LjA1NCw0LjkyNC0xNC45NjYsMTQuNzgxLTIwLjY5OVY1MS4zNTgNCgljMC0zLjkzOSwxLjc5NS03LjYxMyw1LjM4My0xMS4wMjZjMy41NzgtMy4zOTIsOC41MTQtNi4zNDYsMTQuNzkzLTguODY0YzYuMjctMi41MTQsMTMuNjIzLTQuNDgyLDIyLjA0NS01LjkzMg0KCWM4LjQyNi0xLjQyMSwxNy4zODctMi4xNDEsMjYuODgzLTIuMTQxYzkuNTA4LDAsMTguNDY5LDAuNzIsMjYuODk2LDIuMTQxYzguNDI0LDEuNDQ4LDE1Ljc3NiwzLjQxOCwyMi4wNDUsNS45MzINCgljNi4yODEsMi41MiwxMS4yMDMsNS40NzQsMTQuNzkxLDguODY0YzMuNTksMy40MTMsNS4zNzMsNy4wODcsNS4zNzMsMTEuMDI2djE1Ljg2NWM5Ljg1OCw1LjczMywxNC43OTMsMTIuNjQ2LDE0Ljc5MywyMC42OTl2MTkuMTA0DQoJYzAsMi41MTQtMC4yNjUsNC4zOTctMC44MTIsNS42NDVjLTAuNTM1LDEuNzk1LTEuMzM0LDMuMDQ1LTIuNDE2LDMuNzY1YzguMDc0LDUuNTU5LDEyLjEwMSwxMS44MTcsMTIuMTAxLDE4LjgxOXYyMC4xNzQNCgljMCwyLjY3My0wLjI2Myw0LjY2My0wLjgxMiw1LjkxQzE5My41MDQsMTYzLjQ4MywxOTIuMzQ0LDE2NC45MjcsMTkwLjkxMiwxNjUuNjI4eiBNMTk5LjUxMSwxODUuNTQxDQoJYzAtNS41NTktMy45MzktMTAuODU0LTExLjgyOC0xNS44NjZ2NS45MWMwLDQuMzEtMi4yNDQsOC4zMzctNi43MTksMTIuMDk3Yy00LjQ4NCwzLjc2Ni0xMC41NzgsNy4wNDktMTguMjkzLDkuODI2DQoJYy03LjcwMSwyLjc4LTE2LjcwNyw0Ljk2OS0yNy4wMTQsNi41ODljLTEwLjMxNiwxLjU5Ny0yMS4zNzcsMi40MDUtMzMuMjE4LDIuNDA1Yy0xMS44MjYsMC0yMi44OTktMC44MS0zMy4yMDUtMi40MDUNCgljLTEwLjMwNy0xLjYyLTE5LjMxMS0zLjgwOS0yNy4wMjQtNi41ODljLTcuNzAxLTIuNzc3LTEzLjc5Ny02LjA2Mi0xOC4yODMtOS44MjZjLTQuNDc1LTMuNzYtNi43MTctNy43ODctNi43MTctMTIuMDk3di01LjkxDQoJYy04LjA2Miw1LjAxMi0xMi4xMDMsMTAuMzA5LTEyLjEwMywxNS44NjZ2MjAuOTYxYzAsNC4xMzgsMC44MTIsNi4xOTIsMi40MTgsNi4xOTJjMC41MzcsMCwwLjg5Ni0wLjMwNywxLjA3Mi0wLjkzOQ0KCWMwLjE4OC0wLjYzNSwwLjQ5Mi0xLjM1NywwLjk0My0yLjE0NmMwLjQ0NC0wLjgxMSwxLjA3OS0xLjUzNCwxLjg4MS0yLjE2N2MwLjgwOS0wLjYxMiwyLjAyMi0wLjk0LDMuNjMxLTAuOTQNCgljMS42MTksMCwyLjgyMiwxLjA3NSwzLjYzNSwzLjIzN2MwLjgxLDIuMTQ2LDEuNjA3LDQuNTI4LDIuNDE2LDcuMTE1YzAuODEyLDIuNjA0LDEuNzA3LDQuOTg1LDIuNjkxLDcuMTMzDQoJYzAuOTg1LDIuMTQ1LDIuNDYzLDMuMjE1LDQuNDM0LDMuMjE1YzEuNzkzLDAsMy4zMTItMC40OCw0LjU3LTEuNDY2YzEuMjYtMC45ODUsMi40Mi0yLjA1NywzLjUwNC0zLjIzOA0KCWMxLjA2OS0xLjE1OSwyLjIyOS0yLjIyOSwzLjQ5LTMuMjE2YzEuMjU2LTAuOTg0LDIuODY0LTEuNDg4LDQuODQ0LTEuNDg4YzEuOTcxLDAsMy40OTIsMS4wOTQsNC41NjIsMy4yMzgNCgljMS4wODQsMi4xNDUsMi4xMTMsNC41NzMsMy4wOTksNy4yNjNjMC45ODMsMi42NzEsMi4xMDIsNS4xMDEsMy4zNTgsNy4yNDVjMS4yNTksMi4xNjcsMi45NjUsMy4yMzcsNS4xMDksMy4yMzcNCgljMi4xNTQsMCwzLjgwOS0wLjcyMSw0Ljk3OS0yLjE0NmMxLjE1OC0xLjQ0MywyLjI0Mi0yLjk3MywzLjIyOC00LjU3M2MwLjk4NC0xLjYxOCwyLjEtMy4xNDcsMy4zNTktNC41NzMNCgljMS4yNDgtMS40NDMsMy4xMjktMi4xNjMsNS42NDYtMi4xNjNjMi4zMywwLDQuMzQyLDEuMDQ4LDYuMDQ5LDMuMTA2YzEuNzA5LDIuMDU3LDMuMzU5LDQuMjg2LDQuOTc5LDYuNzINCgljMS42MDgsMi40MjksMy40NDQsNC42NTgsNS41MDQsNi43MTVjMi4wNjcsMi4wNTksNC43MTUsMy4wODQsNy45MzIsMy4wODRjMy4yMjksMCw1Ljg3Ny0xLjAyNSw3LjkzMy0zLjA4NA0KCWMyLjA2Ny0yLjA1NywzLjkwNy00LjI4Niw1LjUxNi02LjcxNWMxLjYxOS0yLjQzNCwzLjIyNy00LjY2Myw0Ljg0Ni02LjcyYzEuNjA5LTIuMDYsMy42NjYtMy4xMDYsNi4xODUtMy4xMDYNCgljMi4zMjksMCw0LjE3LDAuNzIsNS41MTQsMi4xNjNjMS4zNDgsMS40MjYsMi40NjMsMi45NTUsMy4zNTksNC41NzNjMC44OTYsMS42MDIsMS45MjQsMy4xMywzLjA5Niw0LjU3Mw0KCWMxLjE2LDEuNDI1LDIuODIyLDIuMTQ2LDQuOTY3LDIuMTQ2YzIuMTU2LDAsMy44NTMtMS4wNyw1LjEwOS0zLjIzN2MxLjI2LTIuMTQ2LDIuMzc1LTQuNTc0LDMuMzU4LTcuMjQ1DQoJYzAuOTg0LTIuNjg4LDIuMDU4LTUuMTE4LDMuMjI5LTcuMjYzYzEuMTctMi4xNDYsMi43MzMtMy4yMzgsNC43MDMtMy4yMzhjMS45NzEsMCwzLjU5LDAuNTA0LDQuODQ2LDEuNDg4DQoJYzEuMjQ4LDAuOTg1LDIuNDIsMi4wNTcsMy40OTIsMy4yMTZjMS4wNzEsMS4xODMsMi4xOTgsMi4yNTMsMy4zNTYsMy4yMzhjMS4xNzIsMC45ODQsMi43MzYsMS40NjYsNC43MDcsMS40NjYNCgljMS43OTMsMCwzLjIyOC0xLjA3LDQuMzEtMy4yMTVjMS4wNzMtMi4xNDYsMS45NzEtNC41MjksMi42ODItNy4xMzNjMC43MjEtMi41ODcsMS41MjEtNC45NzEsMi40MjgtNy4xMTUNCgljMC44ODgtMi4xNjIsMi4xNDYtMy4yMzcsMy43NjUtMy4yMzdjMS42MDgsMCwyLjgyNCwwLjMyOCwzLjYyMywwLjk0YzAuODExLDAuNjMzLDEuMzkxLDEuMzU2LDEuNzUsMi4xNjcNCgljMC4zNiwwLjc4NywwLjY3OCwxLjUxMSwwLjk0LDIuMTQ2YzAuMjcxLDAuNjM0LDAuNTgsMC45MzksMC45MzksMC45MzljMS42MTksMCwyLjQyLTIuMDU2LDIuNDItNi4xOTJMMTk5LjUxMSwxODUuNTQxDQoJTDE5OS41MTEsMTg1LjU0MXogTTE0Ljc5MywxNTUuNDI4YzAsMy45MzksMC43OTcsNS45MSwyLjQxOCw1LjkxYzAuMzYsMCwwLjYyMy0wLjMwNywwLjgxMS0wLjkzOQ0KCWMwLjE3NC0wLjYzNiwwLjQzNy0xLjI5MSwwLjc5Ny0yLjAxNmMwLjM2My0wLjcyNSwwLjg5OC0xLjM5NywxLjYxOS0yLjAxNWMwLjcxMS0wLjYzNSwxLjc4My0wLjk0LDMuMjMtMC45NA0KCWMxLjQzMiwwLDIuNTQ3LDEuMDMsMy4zNTYsMy4wODRjMC44MSwyLjA2MiwxLjUyMSw0LjM5OCwyLjE0Niw2Ljk4YzAuNjMzLDIuNjA4LDEuNDMyLDQuOTQ3LDIuNDI4LDcuMDA2DQoJYzAuOTg0LDIuMDU3LDIuMjg3LDMuMDg2LDMuODk2LDMuMDg2YzEuNzk1LDAsMy4yMjktMC40OCw0LjMwMy0xLjQ2NmMxLjA4LTAuOTg1LDIuMDU3LTIuMDM4LDIuOTYzLTMuMTA4DQoJYzAuODg3LTEuMDczLDEuOTE2LTIuMTAxLDMuMDg2LTMuMDg0YzEuMTctMC45ODUsMi42NDYtMS40ODcsNC40NDEtMS40ODdjMS43ODIsMCwzLjIyOSwxLjA0Nyw0LjI5OSwzLjEwNQ0KCWMxLjA3NCwyLjA1NywyLjA1OSw0LjM3NiwyLjk1NSw2Ljk3OWMwLjg5NywyLjYwNCwxLjg4Myw0Ljk0NSwyLjk2Nyw3LjAwMmMxLjA3MiwyLjA1NSwyLjU5MiwzLjA4NSw0LjU2MiwzLjA4NQ0KCXMzLjQ1Ny0wLjY4LDQuNDM5LTIuMDE2YzAuOTg0LTEuMzU0LDEuOTI4LTIuODE4LDIuODI0LTQuNDM4YzAuODk3LTEuNjE5LDEuOTI2LTMuMDg1LDMuMDk4LTQuNDQzDQoJYzEuMTYtMS4zMzUsMi44Mi0yLjAxNSw0Ljk2Ny0yLjAxNWMyLjE1NiwwLDMuOTM5LDAuOTg1LDUuMzg0LDIuOTU1YzEuNDMyLDEuOTkyLDIuOTA3LDQuMTM3LDQuNDMyLDYuNDUzDQoJYzEuNTIxLDIuMzQzLDMuMTg0LDQuNDg3LDQuOTc5LDYuNDU4YzEuNzgyLDEuOTcsNC4yMTEsMi45NTQsNy4yNTIsMi45NTRjMi44NjgsMCw1LjI1Mi0wLjk4NCw3LjEzMy0yLjk1NA0KCWMxLjg4My0xLjk3MSwzLjU3OC00LjExNSw1LjExLTYuNDU4YzEuNTIxLTIuMzE2LDIuOTk4LTQuNDYxLDQuNDMxLTYuNDUzYzEuNDM2LTEuOTcsMy4yMjktMi45NTUsNS4zODMtMi45NTUNCgljMi4xNDYsMCwzLjgwOSwwLjY4LDQuOTY3LDIuMDE1YzEuMTcyLDEuMzU4LDIuMTk5LDIuODI0LDMuMDk5LDQuNDQzYzAuODk2LDEuNjE5LDEuODM4LDMuMDg1LDIuODIzLDQuNDM4DQoJYzAuOTg0LDEuMzM2LDIuNDYxLDIuMDE2LDQuNDM5LDIuMDE2YzEuOTcxLDAsMy40OTItMS4wMyw0LjU2Mi0zLjA4NWMxLjA4NC0yLjA1NywyLjA2LTQuMzk4LDIuOTY4LTcuMDAyDQoJYzAuODg1LTIuNjA0LDEuODgxLTQuOTI1LDIuOTUzLTYuOTc5YzEuMDcxLTIuMDYsMi41MDYtMy4xMDUsNC4zMDEtMy4xMDVjMS43OTMsMCwzLjIyOSwwLjUwMiw0LjI5OSwxLjQ4Nw0KCWMxLjA4NCwwLjk4MywyLjExMSwyLjAxMSwzLjA5NiwzLjA4NGMwLjk4NCwxLjA3LDIuMDE2LDIuMTIzLDMuMDk2LDMuMTA4YzEuMDcyLDAuOTg0LDIuNDIsMS40NjYsNC4wMjcsMS40NjYNCgljMS43OTUsMCwzLjE0My0xLjAyOSw0LjAzOS0zLjA4NmMwLjg5Ni0yLjA1OSwxLjY5NS00LjM5NywyLjQxNi03LjAwNmMwLjcxMS0yLjU4MiwxLjQzNC00LjkyLDIuMTU4LTYuOTgNCgljMC43MDktMi4wNTQsMS43OC0zLjA4NCwzLjIyNS0zLjA4NGMxLjQzNCwwLDIuNTA2LDAuMzA3LDMuMjI5LDAuOTRjMC43MTIsMC42MTYsMS4yOTMsMS4yOSwxLjc0LDIuMDE1DQoJYzAuNDQ5LDAuNzI1LDAuNzY3LDEuMzgsMC45NDEsMi4wMTZjMC4xNzIsMC42MzQsMC40NDcsMC45MzksMC44MDksMC45MzljMS40MzUsMCwyLjE1NC0xLjk3MSwyLjE1NC01Ljkxdi0yMC4xNzQNCgljMC01LjczMy0zLjU4OC0xMC44NTQtMTAuNzU0LTE1LjMxNXY2LjE2OWMwLDQuMTM4LTIuMDIzLDcuOTQxLTYuMDUxLDExLjQyM2MtNC4wMzcsMy40OTktOS41MDksNi41ODgtMTYuNCw5LjI3Ng0KCWMtNi45MDIsMi42OTMtMTUuMDIzLDQuODE2LTI0LjM0NCw2LjMyMmMtOS4zMjIsMS41MzQtMTkuMjY5LDIuMjk5LTI5Ljg0OSwyLjI5OWMtMTAuNTY3LDAtMjAuNTI0LTAuNzY1LTI5LjgzOC0yLjI5OQ0KCWMtOS4zMy0xLjUwNi0xNy40NzktMy42MjktMjQuNDczLTYuMzIyYy02Ljk5Mi0yLjY4OC0xMi41MDYtNS43NzctMTYuNTMzLTkuMjc2Yy00LjAzNS0zLjQ4LTYuMDUxLTcuMjg1LTYuMDUxLTExLjQyM3YtNi4xNjkNCgljLTMuNDEyLDIuMTQ1LTYuMDQ5LDQuNTI2LTcuOTMxLDcuMTA4Yy0xLjg4MywyLjYwNC0yLjgyMyw1LjMzOC0yLjgyMyw4LjIwN0wxNC43OTMsMTU1LjQyOEwxNC43OTMsMTU1LjQyOHogTTIzLjY2OCwxMDcuMDI2DQoJYzAsMy43NjQsMC42MjMsNS42NDUsMS44NzksNS42NDVjMC4zNTIsMCwwLjYyNS0wLjMwNywwLjgwMS0wLjkzOGMwLjE4NC0wLjYzNiwwLjQ0Ny0xLjI0NiwwLjgwOS0xLjg4MQ0KCWMwLjM2MS0wLjY0LDAuODU0LTEuMjUsMS40NzktMS44ODZjMC42MzMtMC42MzMsMS42NjItMC45MzgsMy4wOTYtMC45MzhjMS4yNDYsMCwyLjI0MiwwLjk4MywyLjk1MywyLjk1NQ0KCWMwLjcyNCwxLjk3LDEuMzQ2LDQuMTU0LDEuODgxLDYuNTg0YzAuNTM5LDIuNDMzLDEuMjE1LDQuNjE5LDIuMDI3LDYuNTg4YzAuNzk3LDEuOTcsMi4wMTIsMi45NTQsMy42MTksMi45NTQNCgljMS42MTksMCwyLjg2Ni0wLjQzOCwzLjc2NC0xLjMzNWMwLjg5OC0wLjg5NiwxLjc5NS0xLjkyNiwyLjY5MS0zLjEwN2MwLjg5Ny0xLjE2LDEuODgzLTIuMTg2LDIuOTY3LTMuMDg1DQoJYzEuMDctMC44OTYsMi40MTYtMS4zMzYsNC4wMjUtMS4zMzZjMS42MTYsMCwyLjg2NCwxLjAzLDMuNzY0LDMuMDg1YzAuODk2LDIuMDYsMS43NTIsNC4zMTIsMi41NjIsNi43MTkNCgljMC43OTksMi40MywxLjY5NCw0LjYxOCwyLjY5LDYuNTg4YzAuOTc0LDEuOTcxLDIuMzYzLDIuOTU1LDQuMTU4LDIuOTU1YzEuNzkzLDAsMy4xMzktMC42MTUsNC4wMzUtMS44ODUNCgljMC44OTctMS4yNDYsMS43NTItMi42ODgsMi41NjItNC4yODVjMC43OTctMS42MjQsMS42OTUtMy4wNDUsMi42ODEtNC4zMTJjMC45ODMtMS4yNDUsMi40NjEtMS44ODUsNC40NDItMS44ODUNCgljMS45NjcsMCwzLjU3NiwwLjk0NCw0LjgzNCwyLjgyNGMxLjI2LDEuODg1LDIuNTYyLDMuOTM5LDMuOTA0LDYuMTkyYzEuMzM2LDIuMjM1LDIuODU3LDQuMjksNC41NjIsNi4xNw0KCWMxLjcwOSwxLjg4NCwzLjkwNywyLjgyNCw2LjU4OCwyLjgyNGMyLjY5MiwwLDQuODkzLTAuOTQsNi41OTgtMi44MjRjMS42OTUtMS44OCwzLjIxNy0zLjkzNSw0LjU2Mi02LjE3DQoJYzEuMzQ2LTIuMjUzLDIuNjQ2LTQuMzEsMy45MDUtNi4xOTJjMS4yNDgtMS44OCwyLjg2Ny0yLjgyNCw0LjgzNi0yLjgyNGMxLjk3MiwwLDMuNDQ1LDAuNjQsNC40NDEsMS44ODUNCgljMC45NzMsMS4yNjksMS44ODMsMi42ODgsMi42OCw0LjMxMmMwLjgxMiwxLjU5OCwxLjY2NCwzLjAzOSwyLjU2Miw0LjI4NWMwLjg5NiwxLjI3LDIuMjMxLDEuODg1LDQuMDI0LDEuODg1DQoJYzEuNzk2LDAsMy4xODctMC45ODQsNC4xNjgtMi45NTVjMC45ODQtMS45NywxLjg4Mi00LjE1OCwyLjY5MS02LjU4OGMwLjgxMi0yLjQwNSwxLjY1Mi00LjY1OSwyLjU2Mi02LjcxOQ0KCWMwLjg4Ny0yLjA1NSwyLjE0NS0zLjA4NSwzLjc2NC0zLjA4NWMxLjYwNywwLDIuOTEsMC40NCwzLjg5NiwxLjMzNmMwLjk4NSwwLjg5OSwxLjkyOCwxLjkyNSwyLjgyMywzLjA4NQ0KCWMwLjg5OCwxLjE4NCwxLjc5NSwyLjIxMywyLjY5MSwzLjEwN2MwLjg5NywwLjg5NywyLjE0MywxLjMzNSwzLjc2MiwxLjMzNWMxLjYxMSwwLDIuODI0LTAuOTg0LDMuNjM1LTIuOTU0DQoJYzAuOC0xLjk2OSwxLjUyMS00LjE1NSwyLjE0Ni02LjU4OGMwLjYzNS0yLjQzLDEuMzAzLTQuNjE0LDIuMDIyLTYuNTg0YzAuNzEyLTEuOTcyLDEuNjk1LTIuOTU1LDIuOTU1LTIuOTU1DQoJYzEuMjQ2LDAsMi4xODgsMC4zMDcsMi44MjIsMC45MzhjMC42MjMsMC42MzYsMS4xMTUsMS4yNDYsMS40NzgsMS44ODZjMC4zNTgsMC42MzUsMC42MjMsMS4yNDUsMC44MDksMS44ODENCgljMC4xNzYsMC42MzMsMC40NDcsMC45MzgsMC44MTEsMC45MzhjMS40MzMsMCwyLjE0Ni0xLjg4MSwyLjE0Ni01LjY0NVY4Ny45MjJjMC01LjAxLTMuMjI5LTkuOTM0LTkuNjg1LTE0Ljc5MXY2LjE5Mw0KCWMwLDMuNzY0LTEuNzgyLDcuMzUyLTUuMzczLDEwLjc2NmMtMy41ODgsMy4zOTItOC41MSw2LjM0Ny0xNC43OTEsOC44NTljLTYuMjY4LDIuNTE5LTEzLjYyLDQuNDg4LTIyLjA0NSw1LjkwOQ0KCWMtOC40MjYsMS40NDItMTcuMzg3LDIuMTY3LTI2Ljg5NSwyLjE2N2MtOS40OTYsMC0xOC40NTctMC43MjUtMjYuODgzLTIuMTY3Yy04LjQyMi0xLjQyMS0xNS43NzUtMy4zOTItMjIuMDQ1LTUuOTA5DQoJYy02LjI3OS0yLjUxNC0xMS4yMTYtNS40NjktMTQuNzkzLTguODU5Yy0zLjU4OS0zLjQxNC01LjM4NC03LjAwMi01LjM4NC0xMC43NjZ2LTYuMTkzYy02LjQ0Miw0Ljg1Ny05LjY3LDkuNzgxLTkuNjcsMTQuNzkxDQoJTDIzLjY2OCwxMDcuMDI2TDIzLjY2OCwxMDcuMDI2eiBNNTUuMzkzLDM2LjMwMmMtNS4yMDksMi4xNDYtOS4zMTksNC41NzMtMTIuMzczLDcuMjY5Yy0zLjA1MywyLjY4OC00LjU3NCw1LjI3My00LjU3NCw3Ljc4OA0KCWMwLDIuNTE5LDEuNTIxLDUuMDc3LDQuNTc0LDcuNjU3YzMuMDUzLDIuNjA0LDcuMTY0LDQuOTkyLDEyLjM3Myw3LjEzNGM2LjI3MSwyLjUxOSwxMy40MzgsNC40NDIsMjEuNTEyLDUuNzc3DQoJYzguMDYyLDEuMzU5LDE2LjU3NiwyLjAxNiwyNS41MzUsMi4wMTZjOC45NzQsMCwxNy40NDEtMC42NTUsMjUuNDE2LTIuMDE2YzcuOTc4LTEuMzM1LDE1LjEwMS0zLjI2LDIxLjM3OS01Ljc3Nw0KCWM1LjM3My0yLjE0Miw5LjU0MS00LjUyOCwxMi40OTYtNy4xMzRjMi45NjMtMi41OCw0LjQzOS01LjE0LDQuNDM5LTcuNjU3YzAtMi41MTUtMS40NzgtNS4xMDEtNC40MzktNy43ODgNCgljLTIuOTU1LTIuNjk0LTcuMTIzLTUuMTIzLTEyLjQ5Ni03LjI2OWMtNi4yNzgtMi41MTUtMTMuNDAxLTQuNDM4LTIxLjM3OS01Ljc3NGMtNy45NzUtMS4zNTctMTYuNDQyLTIuMDE1LTI1LjQxNi0yLjAxNQ0KCWMtOC45NTksMC0xNy40NzMsMC42NTYtMjUuNTM1LDIuMDE1QzY4LjgzMiwzMS44NjIsNjEuNjY0LDMzLjc4Nyw1NS4zOTMsMzYuMzAyeiIvPg0KPC9zdmc+',
    'progress-8' => 'data:image/svg+xml;base64,PHN2ZyB2ZXJzaW9uPSIxLjEiIGlkPSJMYXllcl8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCINCgkgd2lkdGg9IjIwNC44OTVweCIgaGVpZ2h0PSIyNTkuNDc5cHgiIHZpZXdCb3g9IjAgMCAyMDQuODk1IDI1OS40NzkiIGVuYWJsZS1iYWNrZ3JvdW5kPSJuZXcgMCAwIDIwNC44OTUgMjU5LjQ3OSINCgkgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+DQo8cGF0aCBmaWxsPSIjRDMzRDQ0IiBkPSJNMTkwLjkxMiwxNjUuNjI4YzUuNTU5LDMuNTg4LDkuMjc4LDcuMDg4LDExLjE2MSwxMC41MDFjMS44NzksMy4zOTIsMi44MjEsNi41NDIsMi44MjEsOS40MTJ2MjAuOTYxDQoJYzAsMi42OTQtMC4zNjIsNC43NDktMS4wNzEsNi4xOTJjLTEuMDg0LDMuNDEzLTMuMzI1LDUuMS02LjcyOSw1LjF2OS40MDhjMCw0LjQ4OC0yLjQ2Myw4LjY2Ni03LjM5OCwxMi41MTYNCgljLTQuOTIyLDMuODU0LTExLjY5Myw3LjI2OS0yMC4yOTUsMTAuMjIyYy04LjYwOCwyLjk1NS0xOC42NDMsNS4yOTMtMzAuMTIxLDYuOTc5Yy0xMS40NjUsMS43MDktMjMuNzUyLDIuNTYtMzYuODM4LDIuNTYNCglzLTI1LjQwMy0wLjg1MS0zNi45NzEtMi41NmMtMTEuNTYzLTEuNjg4LTIxLjY0MS00LjAyNC0zMC4yNTItNi45NzljLTguNTk4LTIuOTUzLTE1LjM3MS02LjM2Ny0yMC4yOTUtMTAuMjIyDQoJYy00LjkzNC0zLjg1LTcuMzk2LTguMDI2LTcuMzk2LTEyLjUxNnYtOS40MDhjLTMuMDU1LDAtNS4yODQtMS42ODctNi43MTktNS4xQzAuMjc2LDIxMS4wNzUsMCwyMDkuMDIsMCwyMDYuNTAydi0yMC45NjENCgljMC0yLjg3LDAuODk2LTYuMDIxLDIuNjktOS40MTJjMS43ODMtMy40MTMsNS40NTktNi45MTMsMTEuMDItMTAuNTAxYy0xLjI0OS0wLjcwMS0yLjMzMi0yLjE0NS0zLjIyOS00LjI5DQoJYy0wLjUzNS0xLjI0Ni0wLjc5OS0zLjIzNi0wLjc5OS01LjkwOXYtMjAuMTc0YzAtNy4wMDIsMy45MzktMTMuMjYyLDExLjgyNy0xOC44MTljLTAuMzU4LTAuMzczLTAuNzY1LTAuODU0LTEuMjE0LTEuNDg4DQoJYy0wLjQ0OC0wLjYxMi0wLjg0My0xLjM3Ni0xLjIwMy0yLjI3NWMtMC4zNjEtMS43OTUtMC41MzctMy42NzQtMC41MzctNS42NDVWODcuOTIzYzAtNi42MjksMy40OTEtMTIuNjI4LDEwLjQ4Mi0xOC4wMTENCgljLTAuODk2LTAuODk2LTEuNjA4LTEuOTctMi4xNDYtMy4yMTZjLTAuMzU4LTEuNzk0LTAuNTQ3LTMuNjc0LTAuNTQ3LTUuNjY2VjQzLjAxOWMwLTUuMDEyLDIuMDctOS42Nyw2LjE5My0xMy45ODENCgljNC4xMTUtNC4yOSwxMC4wMzItNy45NjUsMTcuNzQ2LTExLjAyNmM2Ljk5Mi0yLjg2OCwxNC45NjgtNS4wNTYsMjMuOTI4LTYuNTg5YzguOTYxLTEuNTExLDE4LjM3MS0yLjI3NCwyOC4yMjktMi4yNzQNCgljOS44NTcsMCwxOS4yMzMsMC43NjUsMjguMTA2LDIuMjc0YzguODc1LDEuNTMzLDE2LjgwNSwzLjcyMSwyMy43OTksNi41ODljNy44NzUsMy4wNjIsMTMuODgyLDYuNzM2LDE4LjAwOSwxMS4wMjYNCgljNC4xMjMsNC4zMTIsNi4xODIsOC45NzEsNi4xODIsMTMuOTgxVjYxLjAzYzAsMi41MTQtMC4yNjQsNC4zOTctMC43OTksNS42NjZjLTAuMTg4LDEuMDc1LTAuODEyLDIuMTQ1LTEuODgyLDMuMjE2DQoJYzYuOTg5LDUuNTU5LDEwLjQ3OSwxMS41NTcsMTAuNDc5LDE4LjAxMXYxOS4xMDRjMCwyLjUxNC0wLjI2MSw0LjM5Ny0wLjgxLDUuNjQ1Yy0wLjUzNSwxLjc5NC0xLjMzNCwzLjA0NS0yLjQxOCwzLjc2NQ0KCWM4LjA3Myw1LjU1OSwxMi4xMDMsMTEuODE3LDEyLjEwMywxOC44MTl2MjAuMTc0YzAsMi42NzMtMC4yNjMsNC42NjMtMC44MSw1LjkwOUMxOTMuNTA2LDE2My40ODMsMTkyLjM0NSwxNjQuOTI3LDE5MC45MTIsMTY1LjYyOHoNCgkgTTE5OS41MTEsMTg1LjU0MWMwLTUuNTU5LTMuOTM4LTEwLjg1NS0xMS44MjctMTUuODY2djUuOTFjMCw0LjMxLTIuMjQzLDguMzM2LTYuNzE5LDEyLjA5NmMtNC40ODYsMy43NjctMTAuNTgsNy4wNS0xOC4yOTIsOS44MjcNCgljLTcuNzAyLDIuNzc5LTE2LjcwOSw0Ljk3LTI3LjAxNCw2LjU4OGMtMTAuMzE5LDEuNTk4LTIxLjM3OSwyLjQwNi0zMy4yMTgsMi40MDZjLTExLjgyNywwLTIyLjg5OC0wLjgxLTMzLjIwNy0yLjQwNg0KCWMtMTAuMzA2LTEuNjE4LTE5LjMxMi0zLjgwOS0yNy4wMjQtNi41ODhjLTcuNzAxLTIuNzc3LTEzLjc5NC02LjA2Mi0xOC4yOC05LjgyN2MtNC40NzctMy43Ni02LjcxOS03Ljc4Ni02LjcxOS0xMi4wOTZ2LTUuOTENCglDOS4xNDksMTc0LjY4NSw1LjExLDE3OS45ODMsNS4xMSwxODUuNTQxdjIwLjk2MWMwLDQuMTM4LDAuODA5LDYuMTkyLDIuNDE5LDYuMTkyYzAuNTM0LDAsMC44OTYtMC4zMDgsMS4wNzItMC45MzkNCgljMC4xODUtMC42MzUsMC40OTItMS4zNTcsMC45MzktMi4xNDZjMC40NDktMC44MTEsMS4wODQtMS41MzQsMS44ODItMi4xNjhjMC44MTEtMC42MTIsMi4wMjQtMC45MzksMy42MzItMC45MzkNCgljMS42MjEsMCwyLjgyNCwxLjA3NSwzLjYzMywzLjIzN2MwLjgxMiwyLjE0NiwxLjYwOCw0LjUyOCwyLjQxOCw3LjExNGMwLjgxLDIuNjA0LDEuNzA3LDQuOTg2LDIuNjkxLDcuMTM0DQoJYzAuOTg0LDIuMTQ1LDIuNDYyLDMuMjE1LDQuNDMyLDMuMjE1YzEuNzk0LDAsMy4zMTQtMC40OCw0LjU3My0xLjQ2N2MxLjI1Ny0wLjk4NCwyLjQxNy0yLjA1NiwzLjUwMS0zLjIzNw0KCWMxLjA3Mi0xLjE2LDIuMjMyLTIuMjMxLDMuNDktMy4yMTZjMS4yNTktMC45ODQsMi44NjctMS40ODgsNC44NDgtMS40ODhjMS45NywwLDMuNDksMS4wOTQsNC41NjIsMy4yMzgNCgljMS4wODIsMi4xNDUsMi4xMSw0LjU3MiwzLjA5Niw3LjI2M2MwLjk4NCwyLjY3MSwyLjEwMSw1LjEsMy4zNTksNy4yNDVjMS4yNTcsMi4xNjcsMi45NjQsMy4yMzYsNS4xMDgsMy4yMzYNCgljMi4xNTYsMCwzLjgwOC0wLjcyLDQuOTc5LTIuMTQ2YzEuMTU5LTEuNDQyLDIuMjM5LTIuOTczLDMuMjI3LTQuNTc0YzAuOTg0LTEuNjE2LDIuMTAzLTMuMTQ2LDMuMzU4LTQuNTcxDQoJYzEuMjQ5LTEuNDQzLDMuMTMtMi4xNjMsNS42NDYtMi4xNjNjMi4zMjgsMCw0LjM0NCwxLjA0OCw2LjA1MSwzLjEwNWMxLjcwNiwyLjA1OCwzLjM1OCw0LjI4Niw0Ljk3OCw2LjcyDQoJYzEuNjA4LDIuNDMsMy40NDcsNC42NTksNS41MDIsNi43MTVjMi4wNjgsMi4wNiw0LjcxNiwzLjA4NCw3LjkzNSwzLjA4NGMzLjIyNywwLDUuODc1LTEuMDI0LDcuOTMzLTMuMDg0DQoJYzIuMDY2LTIuMDU2LDMuOTA2LTQuMjg1LDUuNTEzLTYuNzE1YzEuNjIxLTIuNDM0LDMuMjI5LTQuNjYyLDQuODUtNi43MmMxLjYwNy0yLjA1OSwzLjY2NS0zLjEwNSw2LjE4MS0zLjEwNQ0KCWMyLjMzLDAsNC4xNywwLjcyLDUuNTE1LDIuMTYzYzEuMzQ4LDEuNDI2LDIuNDYzLDIuOTU1LDMuMzU4LDQuNTcxYzAuODk3LDEuNjAzLDEuOTI4LDMuMTMyLDMuMDk3LDQuNTc0DQoJYzEuMTYxLDEuNDI2LDIuODI0LDIuMTQ2LDQuOTY3LDIuMTQ2YzIuMTU3LDAsMy44NTQtMS4wNjksNS4xMS0zLjIzNmMxLjI1OS0yLjE0NiwyLjM3NS00LjU3NCwzLjM1Ny03LjI0NQ0KCWMwLjk4My0yLjY4OSwyLjA1OC01LjExOCwzLjIyOS03LjI2M2MxLjE3MS0yLjE0NiwyLjczNC0zLjIzOCw0LjcwNC0zLjIzOGMxLjk2OSwwLDMuNTg4LDAuNTA0LDQuODQ4LDEuNDg4DQoJYzEuMjQ4LDAuOTgzLDIuNDE4LDIuMDU2LDMuNDg5LDMuMjE2YzEuMDczLDEuMTgzLDIuMTk5LDIuMjUzLDMuMzU5LDMuMjM3YzEuMTY5LDAuOTg1LDIuNzMyLDEuNDY3LDQuNzA0LDEuNDY3DQoJYzEuNzk1LDAsMy4yMjktMS4wNyw0LjMxMS0zLjIxNWMxLjA3Mi0yLjE0NiwxLjk3MS00LjUyOSwyLjY4LTcuMTM0YzAuNzI2LTIuNTg2LDEuNTIzLTQuOTcsMi40MzMtNy4xMTQNCgljMC44ODUtMi4xNjIsMi4xNDMtMy4yMzcsMy43NjQtMy4yMzdjMS42MDYsMCwyLjgyMiwwLjMyNywzLjYyMSwwLjkzOWMwLjgxLDAuNjM0LDEuMzg5LDEuMzU3LDEuNzUxLDIuMTY4DQoJYzAuMzU5LDAuNzg3LDAuNjc3LDEuNTExLDAuOTM5LDIuMTQ2YzAuMjczLDAuNjMzLDAuNTgsMC45MzksMC45NDEsMC45MzljMS42MTksMCwyLjQxNy0yLjA1NiwyLjQxNy02LjE5MlYxODUuNTQxTDE5OS41MTEsMTg1LjU0MQ0KCXogTTE0Ljc5NCwxNTUuNDI4YzAsMy45MzksMC43OTksNS45MDksMi40MTgsNS45MDljMC4zNjEsMCwwLjYyMy0wLjMwNiwwLjgwOS0wLjkzOWMwLjE3Ny0wLjYzNSwwLjQzOS0xLjI5LDAuNzk5LTIuMDE1DQoJYzAuMzYxLTAuNzI1LDAuODk3LTEuMzk4LDEuNjE5LTIuMDE2YzAuNzEzLTAuNjM0LDEuNzg1LTAuOTM5LDMuMjI5LTAuOTM5YzEuNDMzLDAsMi41NTEsMS4wMjksMy4zNTgsMy4wODQNCgljMC44MTEsMi4wNjIsMS41MjEsNC4zOTgsMi4xNDQsNi45OGMwLjYzNywyLjYwOCwxLjQzNiw0Ljk0NywyLjQzMSw3LjAwNmMwLjk4NSwyLjA1NiwyLjI4NywzLjA4NiwzLjg5NiwzLjA4Ng0KCWMxLjc5NCwwLDMuMjI3LTAuNDgxLDQuMjk5LTEuNDY3YzEuMDg0LTAuOTg0LDIuMDU4LTIuMDM3LDIuOTY1LTMuMTA3YzAuODg3LTEuMDczLDEuOTE0LTIuMTAxLDMuMDg2LTMuMDg0DQoJYzEuMTcxLTAuOTg1LDIuNjQ2LTEuNDg4LDQuNDQxLTEuNDg4YzEuNzg1LDAsMy4yMjksMS4wNDgsNC4zMDEsMy4xMDZjMS4wNzIsMi4wNTcsMi4wNTgsNC4zNzYsMi45NTUsNi45NzkNCgljMC44OTYsMi42MDQsMS44NzksNC45NDUsMi45NjMsNy4wMDJjMS4wNzIsMi4wNTUsMi41OTMsMy4wODQsNC41NjIsMy4wODRjMS45NjksMCwzLjQ1Ny0wLjY3OSw0LjQ0Mi0yLjAxNQ0KCWMwLjk4NC0xLjM1NCwxLjkyNC0yLjgxOSwyLjgyMS00LjQzOGMwLjg5Ni0xLjYxOSwxLjkyNy0zLjA4NCwzLjA5Ny00LjQ0MmMxLjE2LTEuMzM2LDIuODI0LTIuMDE1LDQuOTY4LTIuMDE1DQoJYzIuMTU1LDAsMy45MzgsMC45ODMsNS4zODMsMi45NTVjMS40MzYsMS45OTIsMi45MTIsNC4xMzcsNC40MzIsNi40NTNjMS41MiwyLjM0MywzLjE4Niw0LjQ4Nyw0Ljk3OSw2LjQ1OA0KCWMxLjc4MywxLjk3LDQuMjExLDIuOTUzLDcuMjU0LDIuOTUzYzIuODY3LDAsNS4yNTQtMC45ODMsNy4xMzYtMi45NTNjMS44ODItMS45NzEsMy41NzctNC4xMTUsNS4xMDgtNi40NTgNCgljMS41MjEtMi4zMTYsMi45OTYtNC40NjEsNC40MzItNi40NTNjMS40MzMtMS45NzIsMy4yMjgtMi45NTUsNS4zODQtMi45NTVjMi4xNDMsMCwzLjgwNywwLjY3OSw0Ljk2NywyLjAxNQ0KCWMxLjE2OSwxLjM1OCwyLjE5OSwyLjgyMywzLjA5Niw0LjQ0MmMwLjg5NywxLjYxOSwxLjgzOCwzLjA4NiwyLjgyMiw0LjQzOGMwLjk4MywxLjMzNiwyLjQ2MywyLjAxNSw0LjQ0MywyLjAxNQ0KCWMxLjk3LDAsMy40ODktMS4wMjksNC41NjItMy4wODRjMS4wODEtMi4wNTcsMi4wNTctNC4zOTgsMi45NjMtNy4wMDJjMC44ODktMi42MDQsMS44ODMtNC45MjUsMi45NTUtNi45NzkNCgljMS4wNzItMi4wNiwyLjUwNC0zLjEwNiw0LjI5OS0zLjEwNmMxLjc5NiwwLDMuMjI5LDAuNTAzLDQuMzAzLDEuNDg4YzEuMDgxLDAuOTgzLDIuMTA5LDIuMDExLDMuMDk2LDMuMDg0DQoJYzAuOTg1LDEuMDcsMi4wMTMsMi4xMjMsMy4wOTcsMy4xMDdjMS4wNzIsMC45ODMsMi40MTgsMS40NjcsNC4wMjQsMS40NjdjMS43OTUsMCwzLjE0Mi0xLjAzLDQuMDM4LTMuMDg2DQoJYzAuODk2LTIuMDU5LDEuNjk0LTQuMzk3LDIuNDE4LTcuMDA2YzAuNzEzLTIuNTgyLDEuNDM1LTQuOTIsMi4xNTUtNi45OGMwLjcxMS0yLjA1NSwxLjc4My0zLjA4NCwzLjIyOC0zLjA4NA0KCWMxLjQzNSwwLDIuNTA4LDAuMzA3LDMuMjI5LDAuOTM5YzAuNzExLDAuNjE2LDEuMjkxLDEuMjkxLDEuNzM3LDIuMDE2YzAuNDQ5LDAuNzI1LDAuNzY5LDEuMzgsMC45NDIsMi4wMTUNCgljMC4xNzUsMC42MzUsMC40NDcsMC45MzksMC44MSwwLjkzOWMxLjQzMywwLDIuMTU2LTEuOTcsMi4xNTYtNS45MDl2LTIwLjE3NGMwLTUuNzMzLTMuNTg4LTEwLjg1NC0xMC43NTgtMTUuMzE1djYuMTY5DQoJYzAsNC4xMzgtMi4wMjMsNy45NDEtNi4wNDgsMTEuNDIzYy00LjAzOSwzLjQ5OC05LjUxLDYuNTg4LTE2LjQsOS4yNzZjLTYuOTA1LDIuNjkzLTE1LjAyMSw0LjgxNi0yNC4zNDUsNi4zMjINCgljLTkuMzIyLDEuNTM0LTE5LjI2OSwyLjI5OS0yOS44NDksMi4yOTljLTEwLjU2NywwLTIwLjUyMy0wLjc2NS0yOS44MzYtMi4yOTljLTkuMzM0LTEuNTA2LTE3LjQ4My0zLjYyOS0yNC40NzUtNi4zMjINCgljLTYuOTkyLTIuNjg4LTEyLjUwOC01Ljc3OC0xNi41MzItOS4yNzZjLTQuMDM4LTMuNDgtNi4wNS03LjI4NS02LjA1LTExLjQyM3YtNi4xNjljLTMuNDE0LDIuMTQ1LTYuMDUxLDQuNTI2LTcuOTM0LDcuMTA4DQoJYy0xLjg4MSwyLjYwNC0yLjgyMiw1LjMzOC0yLjgyMiw4LjIwN3YyMC4xNzRIMTQuNzk0eiBNMjMuNjY4LDEwNy4wMjZjMCwzLjc2NCwwLjYyMyw1LjY0NSwxLjg4Myw1LjY0NQ0KCWMwLjM0OSwwLDAuNjIyLTAuMzA3LDAuNzk3LTAuOTM4YzAuMTg4LTAuNjM2LDAuNDQ3LTEuMjQ3LDAuODEyLTEuODgxYzAuMzYxLTAuNjQsMC44NTQtMS4yNSwxLjQ3Ny0xLjg4Ng0KCWMwLjYzNi0wLjYzMywxLjY2NC0wLjkzOCwzLjA5Ny0wLjkzOGMxLjI0OSwwLDIuMjQzLDAuOTgzLDIuOTU0LDIuOTU1YzAuNzI0LDEuOTY5LDEuMzQ3LDQuMTUzLDEuODg0LDYuNTg0DQoJYzAuNTM1LDIuNDMyLDEuMjE0LDQuNjE5LDIuMDIyLDYuNTg4YzAuNzk5LDEuOTcsMi4wMTIsMi45NTQsMy42MiwyLjk1NGMxLjYyLDAsMi44NjYtMC40MzgsMy43NjUtMS4zMzUNCgljMC44OTYtMC44OTYsMS43OTUtMS45MjcsMi42OS0zLjEwN2MwLjg5Ny0xLjE2LDEuODgyLTIuMTg2LDIuOTY2LTMuMDg2YzEuMDcyLTAuODk1LDIuNDE4LTEuMzM1LDQuMDI3LTEuMzM1DQoJYzEuNjE5LDAsMi44NjUsMS4wMjksMy43NjIsMy4wODVjMC44OTcsMi4wNiwxLjc1Miw0LjMxMiwyLjU2Miw2LjcxOWMwLjc5OCwyLjQzLDEuNjk0LDQuNjE3LDIuNjksNi41ODgNCgljMC45NzUsMS45NzEsMi4zNjIsMi45NTUsNC4xNTcsMi45NTVjMS43OTQsMCwzLjE0MS0wLjYxNyw0LjAzOS0xLjg4NWMwLjg5Ni0xLjI0NiwxLjc0OC0yLjY4OCwyLjU1OS00LjI4NQ0KCWMwLjc5OS0xLjYyNCwxLjY5NS0zLjA0NSwyLjY4LTQuMzEyYzAuOTg1LTEuMjQ1LDIuNDYzLTEuODg1LDQuNDQzLTEuODg1YzEuOTcxLDAsMy41NzgsMC45NDQsNC44MzcsMi44MjQNCgljMS4yNTcsMS44ODUsMi41NiwzLjkzOSwzLjkwNSw2LjE5MmMxLjMzNCwyLjIzNSwyLjg1NSw0LjI5LDQuNTYyLDYuMTY5YzEuNzA3LDEuODg1LDMuOTA1LDIuODI1LDYuNTg2LDIuODI1DQoJYzIuNjksMCw0Ljg5MS0wLjk0LDYuNTk4LTIuODI1YzEuNjk1LTEuODc5LDMuMjE3LTMuOTM0LDQuNTYyLTYuMTY5YzEuMzQ3LTIuMjUzLDIuNjQ4LTQuMzEsMy45MDUtNi4xOTINCgljMS4yNDctMS44OCwyLjg2Ny0yLjgyNCw0LjgzNy0yLjgyNGMxLjk2OSwwLDMuNDQ3LDAuNjQsNC40NDIsMS44ODVjMC45NzQsMS4yNjksMS44NzksMi42ODgsMi42ODEsNC4zMTINCgljMC44MDksMS41OTcsMS42NjIsMy4wMzksMi41NTksNC4yODVjMC44OTcsMS4yNjgsMi4yMzIsMS44ODUsNC4wMjcsMS44ODVzMy4xODUtMC45ODQsNC4xNjktMi45NTVzMS44ODItNC4xNTgsMi42ODktNi41ODgNCgljMC44MTItMi40MDUsMS42NTMtNC42NTksMi41NjEtNi43MTljMC44ODktMi4wNTYsMi4xNDYtMy4wODUsMy43NjUtMy4wODVjMS42MDksMCwyLjkxMSwwLjQ0LDMuODk2LDEuMzM1DQoJYzAuOTgxLDAuOSwxLjkyNSwxLjkyNiwyLjgyMSwzLjA4NmMwLjg5NiwxLjE4MiwxLjc5MywyLjIxMywyLjY5MSwzLjEwN2MwLjg5NiwwLjg5NywyLjE0NSwxLjMzNSwzLjc2NCwxLjMzNQ0KCWMxLjYwNywwLDIuODIxLTAuOTg0LDMuNjMxLTIuOTU0YzAuOC0xLjk2OSwxLjUyMi00LjE1NiwyLjE0Ni02LjU4OGMwLjYzNC0yLjQzMSwxLjMwMi00LjYxNSwyLjAyMy02LjU4NA0KCWMwLjcxMS0xLjk3MiwxLjY5NS0yLjk1NSwyLjk1My0yLjk1NWMxLjI0OCwwLDIuMTg4LDAuMzA3LDIuODIyLDAuOTM4YzAuNjI1LDAuNjM2LDEuMTE3LDEuMjQ2LDEuNDc5LDEuODg2DQoJYzAuMzYxLDAuNjM0LDAuNjI1LDEuMjQ1LDAuODEsMS44ODFjMC4xNzYsMC42MzMsMC40NDksMC45MzgsMC44MSwwLjkzOGMxLjQzNiwwLDIuMTQ2LTEuODgxLDIuMTQ2LTUuNjQ1Vjg3LjkyMg0KCWMwLTUuMDEtMy4yMjgtOS45MzQtOS42ODUtMTQuNzkxdjYuMTkzYzAsMy43NjQtMS43ODEsNy4zNTItNS4zNzEsMTAuNzY1Yy0zLjU4OCwzLjM5Mi04LjUxMyw2LjM0OC0xNC43OTMsOC44Ng0KCWMtNi4yNywyLjUxOC0xMy42MjEsNC40ODgtMjIuMDQ3LDUuOTA4Yy04LjQyMywxLjQ0Mi0xNy4zODYsMi4xNjgtMjYuODkzLDIuMTY4Yy05LjQ5OCwwLTE4LjQ1OC0wLjcyNi0yNi44ODEtMi4xNjgNCgljLTguNDI3LTEuNDItMTUuNzc3LTMuMzkyLTIyLjA0OC01LjkwOGMtNi4yOC0yLjUxNC0xMS4yMTctNS40Ny0xNC43OTMtOC44NmMtMy41ODktMy40MTMtNS4zODMtNy4wMDEtNS4zODMtMTAuNzY1di02LjE5Mw0KCWMtNi40NDQsNC44NTctOS42NzIsOS43ODEtOS42NzIsMTQuNzkxdjE5LjEwNEgyMy42Njh6IE0zMS40NTcsNjEuMDNjMCwzLjU4OCwwLjYzNyw1LjM4MywxLjg4Myw1LjM4Mw0KCWMwLjM2MSwwLDAuNjM0LTAuMjY2LDAuODEtMC44MDljMC4xNzYtMC41MjYsMC40MDMtMS4xNjEsMC42OC0xLjg4N2MwLjI2NC0wLjY5NSwwLjcxLTEuMzMsMS4zMzUtMS44NzkNCgljMC42MzQtMC41MjUsMS40ODUtMC44MSwyLjU2LTAuODFjMS4wNzIsMCwxLjkyOCwwLjkzOSwyLjU0OSwyLjgyM2MwLjYzNiwxLjg4MSwxLjI2MSw0LjAwMywxLjg4NCw2LjMyMg0KCWMwLjYzNCwyLjMzOSwxLjMwMSw0LjQ0NCwyLjAyMiw2LjMyNGMwLjcxMSwxLjg3OSwxLjc4MywyLjgyNCwzLjIyOSwyLjgyNGMxLjI0NiwwLDIuMzE3LTAuNDQxLDMuMjI3LTEuMzM2DQoJYzAuODg4LTAuOSwxLjc0LTEuODQsMi41NS0yLjgyNGMwLjgxMS0wLjk4NSwxLjY1MS0xLjkyNiwyLjU2MS0yLjgyYzAuODg1LTAuODk4LDIuMDU3LTEuMzU2LDMuNDkyLTEuMzU2DQoJYzEuNDMyLDAsMi41OTIsMC45MzgsMy41MDEsMi44MjNjMC44ODYsMS44OCwxLjY5NCwzLjkzNiwyLjQxNyw2LjE5MmMwLjcxMSwyLjIyOSwxLjQ3Nyw0LjI4NSwyLjI4Nyw2LjE2OQ0KCWMwLjc5NywxLjg4MSwyLjAxMiwyLjgyLDMuNjIxLDIuODJjMS42MTgsMCwyLjgyMS0wLjU2NiwzLjYzMy0xLjcyOWMwLjgwOS0xLjE4MiwxLjU2My0yLjQ3MywyLjI4NS0zLjkxNg0KCWMwLjcxMS0xLjQyMiwxLjUyMS0yLjcxMiwyLjQxOC0zLjg5NWMwLjg5Ni0xLjE2LDIuMjQzLTEuNzUsNC4wMzktMS43NWMxLjc4MSwwLDMuMjcxLDAuODUxLDQuNDMyLDIuNTYNCgljMS4xNjksMS43MDQsMi4zNzEsMy42MzQsMy42MzEsNS43NzhjMS4yNTksMi4xNjMsMi41OTIsNC4wODgsNC4wMzYsNS43NzRjMS40MzUsMS43MDksMy40MDMsMi41NTksNS45MDksMi41NTkNCgljMi4zMywwLDQuMjU2LTAuODUsNS43ODctMi41NTljMS41MjEtMS42ODgsMi44NjYtMy42MTEsNC4wMjYtNS43NzRjMS4xNy0yLjE0NiwyLjMzLTQuMDc0LDMuNTAyLTUuNzc4DQoJYzEuMTYtMS43MDksMi42NDYtMi41Niw0LjQzMS0yLjU2YzEuNzkzLDAsMy4xODQsMC41OSw0LjE2OCwxLjc1YzAuOTgzLDEuMTgzLDEuODQsMi40NzMsMi41NjEsMy44OTUNCgljMC43MTEsMS40NDMsMS40NzksMi43MzQsMi4yODcsMy45MTZjMC43OTksMS4xNjEsMi4wMTQsMS43MjksMy42MzIsMS43MjljMS42MSwwLDIuODI0LTAuOTM5LDMuNjIyLTIuODINCgljMC44MTItMS44ODQsMS41NzYtMy45MzgsMi4yODctNi4xNjljMC43MS0yLjI1OCwxLjUyMS00LjMxMiwyLjQxNy02LjE5MmMwLjg5Ny0xLjg4NSwyLjA2OC0yLjgyMywzLjUwMS0yLjgyMw0KCWMxLjQzNiwwLDIuNTk2LDAuNDU4LDMuNDkyLDEuMzU2YzAuODk2LDAuODk2LDEuNzA3LDEuODM1LDIuNDI5LDIuODJjMC43MSwwLjk4NCwxLjUyMSwxLjkyNCwyLjQxNywyLjgyNA0KCWMwLjg5NiwwLjg5NSwyLjA1OSwxLjMzNiwzLjQ5LDEuMzM2YzEuNDM0LDAsMi41MDYtMC45NDUsMy4yMjktMi44MjRjMC43MTEtMS44OCwxLjM5LTMuOTg1LDIuMDEzLTYuMzI0DQoJYzAuNjM1LTIuMzE5LDEuMjYtNC40NDEsMS44ODMtNi4zMjJjMC42MzUtMS44ODQsMS40ODgtMi44MjMsMi41NjItMi44MjNjMS4wNzIsMCwxLjkyNiwwLjI4MywyLjU0NywwLjgxDQoJYzAuNjM3LDAuNTQ5LDEuMDg0LDEuMTg0LDEuMzQ4LDEuODc5YzAuMjczLDAuNzI2LDAuNDkyLDEuMzU5LDAuNjgsMS44ODdjMC4xNzQsMC41NDMsMC40MzcsMC44MDksMC43OTcsMC44MDkNCgljMS4wODYsMCwxLjYxOS0xLjc5NSwxLjYxOS01LjM4M1Y0My4wMTljMC0zLjkzOS0xLjgzOS03LjY1OS01LjUxNC0xMS4xNTdjLTMuNjc2LTMuNTA0LTguNzQxLTYuNDk4LTE1LjE4Ni04Ljk5NA0KCWMtNi40NTUtMi41Mi0xMy45ODQtNC41MjgtMjIuNTk1LTYuMDYyYy04LjYwMi0xLjUxMi0xNy43NDgtMi4yNzUtMjcuNDMtMi4yNzVjLTkuODU2LDAtMTkuMDgxLDAuNzY1LTI3LjY5LDIuMjc1DQoJYy04LjYwMSwxLjUzMy0xNi4xMjksMy41NDMtMjIuNTg0LDYuMDYyYy02LjQ1MywyLjQ5Ni0xMS41Miw1LjQ5LTE1LjE5NSw4Ljk5NGMtMy42NzYsMy40OTgtNS41MTYsNy4yMTgtNS41MTYsMTEuMTU3VjYxLjAzSDMxLjQ1Nw0KCXogTTEwMi40NDIsNzEuMjQ4Yy0xLjc5NSwwLTIuNjgtMC44NzMtMi42OC0yLjY2N2MwLTEuNzk1LDAuODg1LTIuNjkzLDIuNjgtMi42OTNjOS41MDgsMCwxOC4xOTUtMC42OCwyNi4wODQtMi4wMzMNCgljNy44OS0xLjMzNSwxNC42MDUtMy4wODksMjAuMTc3LTUuMjI5YzUuNTQ3LTIuMTQ2LDkuODQ2LTQuNjE5LDEyLjg5OC03LjM5N2MzLjA1Mi0yLjc3OSw0LjU3My01LjUxNSw0LjU3My04LjIwNw0KCWMwLTEuNjE5LDAuODExLTIuNDA2LDIuNDE3LTIuNDA2YzEuNzk0LDAsMi42OTIsMC43ODcsMi42OTIsMi40MDZjMCw0LjEzOC0xLjc5NSw3Ljk0NC01LjM4NiwxMS40MjINCgljLTMuNTg4LDMuNTA0LTguNzgyLDYuNDk5LTE1LjU5LDkuMDE4Yy02LjQ1NywyLjUxOS0xMy43NjQsNC40NDItMjEuOTE0LDUuNzc4QzEyMC4yMzMsNzAuNTkxLDExMS41ODksNzEuMjQ4LDEwMi40NDIsNzEuMjQ4eiIvPg0KPC9zdmc+',
    'progress-9' => 'data:image/svg+xml;base64,PHN2ZyB2ZXJzaW9uPSIxLjEiIGlkPSJMYXllcl8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCINCgkgd2lkdGg9IjIwNC44OTVweCIgaGVpZ2h0PSIyNTkuNDc5cHgiIHZpZXdCb3g9IjAgMCAyMDQuODk1IDI1OS40NzkiIGVuYWJsZS1iYWNrZ3JvdW5kPSJuZXcgMCAwIDIwNC44OTUgMjU5LjQ3OSINCgkgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+DQo8cGF0aCBmaWxsPSIjRDMzRDQ0IiBkPSJNMTkwLjkxMywxNjUuNjI4YzUuNTYsMy41ODksOS4yNzcsNy4wODgsMTEuMTU5LDEwLjUwMmMxLjg4MywzLjM5MSwyLjgyMiw2LjU0MiwyLjgyMiw5LjQxMnYyMC45NjENCgljMCwyLjY5My0wLjM2LDQuNzQ4LTEuMDcyLDYuMTkxYy0xLjA4MiwzLjQxNC0zLjMyNSw1LjEtNi43MjgsNS4xdjkuNDA4YzAsNC40ODgtMi40NjMsOC42NjYtNy4zOTYsMTIuNTE2DQoJYy00LjkyNCwzLjg1NC0xMS42OTUsNy4yNjktMjAuMjk3LDEwLjIyM2MtOC42MDksMi45NTQtMTguNjQ0LDUuMjkzLTMwLjExOSw2Ljk3OWMtMTEuNDY3LDEuNzA5LTIzLjc1NCwyLjU2LTM2Ljg0LDIuNTYNCgljLTEzLjA4NCwwLTI1LjQwNC0wLjg1MS0zNi45NjktMi41NmMtMTEuNTY2LTEuNjg3LTIxLjY0My00LjAyNC0zMC4yNTItNi45NzljLTguNjAxLTIuOTU0LTE1LjM3My02LjM2OC0yMC4yOTgtMTAuMjIzDQoJYy00LjkzNC0zLjg1LTcuMzk2LTguMDI2LTcuMzk2LTEyLjUxNnYtOS40MDhjLTMuMDUzLDAtNS4yODMtMS42ODYtNi43MTctNS4xQzAuMjc1LDIxMS4wNzUsMCwyMDkuMDE5LDAsMjA2LjUwM3YtMjAuOTYxDQoJYzAtMi44NywwLjg5Ni02LjAyMSwyLjY5LTkuNDEyYzEuNzgzLTMuNDE0LDUuNDYxLTYuOTEzLDExLjAyLTEwLjUwMmMtMS4yNDctMC43MDEtMi4zMjktMi4xNDUtMy4yMjctNC4yODkNCgljLTAuNTM3LTEuMjQ2LTAuOC0zLjIzOC0wLjgtNS45MXYtMjAuMTc0YzAtNy4wMDIsMy45MzgtMTMuMjYyLDExLjgyOC0xOC44MTljLTAuMzYyLTAuMzczLTAuNzY3LTAuODU0LTEuMjE2LTEuNDg4DQoJYy0wLjQ0OS0wLjYxMS0wLjg0Mi0xLjM3NS0xLjIwNC0yLjI3NWMtMC4zNi0xLjc5NS0wLjUzNi0zLjY3NC0wLjUzNi01LjY0NFY4Ny45MjRjMC02LjYyOSwzLjQ5LTEyLjYyNywxMC40OC0xOC4wMQ0KCWMtMC44OTYtMC44OTYtMS42MDctMS45NzEtMi4xNDQtMy4yMTdjLTAuMzYxLTEuNzkzLTAuNTQ5LTMuNjc0LTAuNTQ5LTUuNjY2di0xOC4wMWMwLTUuMDEyLDIuMDY5LTkuNjcsNi4xOTItMTMuOTgyDQoJYzQuMTE1LTQuMjg5LDEwLjAzMy03Ljk2NCwxNy43NDgtMTEuMDI1YzYuNjMyLTIuNjkzLDE0LjE1Ni00Ljc1LDIyLjU4Mi02LjE3NmM4LjQyNi0xLjQ0MiwxNy4yMTEtMi4zMzgsMjYuMzU3LTIuNjg4DQoJYy0wLjcyMy0xLjI0NS0xLjUzMS0yLjM4NC0yLjQyOS0zLjM2N2MtMC44OTctMC45ODUtMi4wNTktMS42NDMtMy40OTMtMi4wMTdjLTAuODk2LTAuMzUtMS4zNDQtMC45ODMtMS4zNDQtMS44ODMNCgljMC0wLjcyMSwwLjI3My0xLjIwMSwwLjgxMS0xLjQ2N2MwLjUzMy0wLjI4MywwLjk4NC0wLjQxNCwxLjM0NC0wLjQxNGMxLjQzNiwwLDMuMDg4LDEuMDMsNC45NywzLjA4Ng0KCWMxLjg4LDIuMDc3LDMuMjcsNC4wOTIsNC4xNjcsNi4wNjJjOS42ODUsMC4xNzYsMTguOTE5LDAuOTg1LDI3LjcwNCwyLjQwNWM4Ljc3MiwxLjQ0NCwxNi41NzUsMy41OTEsMjMuMzkxLDYuNDU5DQoJYzcuODc3LDMuMDYyLDEzLjg4Niw2LjczNiwxOC4wMTEsMTEuMDI1YzQuMTI1LDQuMzEyLDYuMTgxLDguOTcyLDYuMTgxLDEzLjk4MnYxOC4wMWMwLDIuNTE0LTAuMjYxLDQuMzk3LTAuNzk4LDUuNjY2DQoJYy0wLjE4NywxLjA3Ni0wLjgxMiwyLjE0Ni0xLjg4MywzLjIxN2M2Ljk5Myw1LjU1OSwxMC40ODIsMTEuNTU3LDEwLjQ4MiwxOC4wMXYxOS4xMDRjMCwyLjUxMy0wLjI2NCw0LjM5Ni0wLjgxMSw1LjY0NA0KCWMtMC41MzcsMS43OTUtMS4zMzYsMy4wNDUtMi40MiwzLjc2NWM4LjA3NSw1LjU1OSwxMi4xMDQsMTEuODE3LDEyLjEwNCwxOC44MTl2MjAuMTc0YzAsMi42NzMtMC4yNjQsNC42NjQtMC44MTEsNS45MQ0KCUMxOTMuNTA3LDE2My40ODMsMTkyLjM0NiwxNjQuOTI3LDE5MC45MTMsMTY1LjYyOHogTTE5OS41MSwxODUuNTQyYzAtNS41Ni0zLjkzNi0xMC44NTYtMTEuODI1LTE1Ljg2N3Y1LjkxDQoJYzAsNC4zMS0yLjI0Miw4LjMzNy02LjcxOCwxMi4wOTdjLTQuNDg0LDMuNzY2LTEwLjU4Miw3LjA0OS0xOC4yOTMsOS44MjdjLTcuNzA1LDIuNzc5LTE2LjcwNyw0Ljk3LTI3LjAxNSw2LjU4OA0KCWMtMTAuMzE2LDEuNTk3LTIxLjM3OSwyLjQwNi0zMy4yMTgsMi40MDZjLTExLjgyOCwwLTIyLjg5OC0wLjgxMS0zMy4yMDQtMi40MDZjLTEwLjMwOC0xLjYxOC0xOS4zMTItMy44MDktMjcuMDI0LTYuNTg4DQoJYy03LjcwNS0yLjc3OC0xMy43OTctNi4wNjItMTguMjgyLTkuODI3Yy00LjQ3Ni0zLjc2LTYuNzItNy43ODgtNi43Mi0xMi4wOTd2LTUuOTFjLTguMDYyLDUuMDExLTEyLjEsMTAuMzA5LTEyLjEsMTUuODY3djIwLjk2MQ0KCWMwLDQuMTM3LDAuODExLDYuMTkxLDIuNDE2LDYuMTkxYzAuNTM2LDAsMC44OTgtMC4zMDcsMS4wNzMtMC45MzljMC4xODgtMC42MzQsMC40OTMtMS4zNTYsMC45MzktMi4xNDYNCgljMC40NDktMC44MTEsMS4wODQtMS41MzQsMS44ODItMi4xNjdjMC44MTEtMC42MTIsMi4wMjMtMC45MzksMy42MzUtMC45MzljMS42MTksMCwyLjgyMiwxLjA3NCwzLjYzMSwzLjIzNw0KCWMwLjgxMSwyLjE0NiwxLjYwOSw0LjUyNywyLjQxOSw3LjExM2MwLjgxLDIuNjA0LDEuNzA4LDQuOTg3LDIuNjkyLDcuMTM0YzAuOTgzLDIuMTQ1LDIuNDU5LDMuMjE1LDQuNDMsMy4yMTUNCgljMS43OTQsMCwzLjMxNS0wLjQ4LDQuNTcyLTEuNDY2YzEuMjYtMC45ODUsMi40Mi0yLjA1NiwzLjUwMi0zLjIzOGMxLjA3MS0xLjE1OSwyLjIzMS0yLjIzLDMuNDg4LTMuMjE1DQoJYzEuMjYtMC45ODUsMi44NjgtMS40ODksNC44NS0xLjQ4OWMxLjk3LDAsMy40OSwxLjA5NCw0LjU2MiwzLjIzOGMxLjA4NCwyLjE0NiwyLjExLDQuNTcyLDMuMDk3LDcuMjY0DQoJYzAuOTgzLDIuNjcxLDIuMTAxLDUuMDk5LDMuMzU4LDcuMjQ0YzEuMjYsMi4xNjgsMi45NjYsMy4yMzcsNS4xMDgsMy4yMzdjMi4xNTYsMCwzLjgxLTAuNzIxLDQuOTc4LTIuMTQ2DQoJYzEuMTYxLTEuNDQzLDIuMjQ1LTIuOTczLDMuMjI5LTQuNTc0YzAuOTgzLTEuNjE4LDIuMTAxLTMuMTQ2LDMuMzU5LTQuNTcyYzEuMjQ2LTEuNDQzLDMuMTI3LTIuMTYyLDUuNjQ0LTIuMTYyDQoJYzIuMzMyLDAsNC4zNDQsMS4wNDcsNi4wNTIsMy4xMDVjMS43MDYsMi4wNTcsMy4zNTcsNC4yODYsNC45NzksNi43MmMxLjYwNiwyLjQyOSwzLjQ0Niw0LjY1OCw1LjUwNCw2LjcxNQ0KCWMyLjA2NSwyLjA1OSw0LjcxNywzLjA4NCw3LjkzMiwzLjA4NGMzLjIyOSwwLDUuODc1LTEuMDI1LDcuOTMzLTMuMDg0YzIuMDY5LTIuMDU3LDMuOTA2LTQuMjg2LDUuNTE4LTYuNzE1DQoJYzEuNjE4LTIuNDM0LDMuMjI2LTQuNjYzLDQuODQ2LTYuNzJjMS42MDYtMi4wNiwzLjY2NC0zLjEwNSw2LjE4LTMuMTA1YzIuMzMyLDAsNC4xNywwLjcxOSw1LjUxOCwyLjE2Mg0KCWMxLjM0NSwxLjQyNiwyLjQ2MiwyLjk1NCwzLjM1Nyw0LjU3MmMwLjg5OCwxLjYwMywxLjkyNywzLjEzMSwzLjA5OSw0LjU3NGMxLjE1NSwxLjQyNSwyLjgxOSwyLjE0Niw0Ljk2NywyLjE0Ng0KCWMyLjE1MiwwLDMuODUtMS4wNjksNS4xMDctMy4yMzdjMS4yNi0yLjE0NiwyLjM3NC00LjU3MywzLjM1OC03LjI0NGMwLjk4NS0yLjY5LDIuMDU5LTUuMTE4LDMuMjI3LTcuMjY0DQoJYzEuMTczLTIuMTQ2LDIuNzM3LTMuMjM4LDQuNzA3LTMuMjM4YzEuOTY4LDAsMy41ODgsMC41MDQsNC44NDcsMS40ODljMS4yNDgsMC45ODMsMi40MiwyLjA1NiwzLjQ4OCwzLjIxNQ0KCWMxLjA3MiwxLjE4NCwyLjE5OCwyLjI1MywzLjM1OSwzLjIzOGMxLjE3MiwwLjk4NCwyLjczNiwxLjQ2Niw0LjcwNiwxLjQ2NmMxLjc5NSwwLDMuMjI3LTEuMDcsNC4zMTEtMy4yMTUNCgljMS4wNzItMi4xNDYsMS45Ny00LjUyOSwyLjY4MS03LjEzNGMwLjcyMy0yLjU4NiwxLjUyMS00Ljk2OSwyLjQzLTcuMTEzYzAuODg1LTIuMTYzLDIuMTQ1LTMuMjM3LDMuNzY0LTMuMjM3DQoJYzEuNjA3LDAsMi44MjIsMC4zMjcsMy42MTksMC45MzljMC44MTIsMC42MzMsMS4zOTEsMS4zNTYsMS43NTMsMi4xNjdjMC4zNjIsMC43ODcsMC42NzksMS41MTIsMC45MzgsMi4xNDYNCgljMC4yNzQsMC42MzQsMC41ODEsMC45MzksMC45NDIsMC45MzljMS42MTgsMCwyLjQxNi0yLjA1NiwyLjQxNi02LjE5MUwxOTkuNTEsMTg1LjU0MkwxOTkuNTEsMTg1LjU0MnogTTE0Ljc5NCwxNTUuNDI4DQoJYzAsMy45MzksMC44LDUuOTEsMi40MTgsNS45MWMwLjM2MSwwLDAuNjI1LTAuMzA3LDAuODEtMC45MzljMC4xNzYtMC42MzUsMC40MzgtMS4yOTEsMC44MDEtMi4wMTYNCgljMC4zNTktMC43MjUsMC44OTctMS4zOTcsMS42MTktMi4wMTVjMC43MTEtMC42MzQsMS43ODMtMC45NCwzLjIyOC0wLjk0YzEuNDM0LDAsMi41NTEsMS4wMywzLjM1OCwzLjA4NQ0KCWMwLjgxLDIuMDYxLDEuNTIxLDQuMzk4LDIuMTQ2LDYuOTc5YzAuNjM1LDIuNjA4LDEuNDM0LDQuOTQ3LDIuNDI5LDcuMDA3YzAuOTg1LDIuMDU2LDIuMjg3LDMuMDg1LDMuODk2LDMuMDg1DQoJYzEuNzkzLDAsMy4yMjktMC40OCw0LjI5OS0xLjQ2NmMxLjA4NC0wLjk4NSwyLjA1OC0yLjAzNywyLjk2Ni0zLjEwN2MwLjg4Ny0xLjA3NCwxLjkxNi0yLjEwMSwzLjA4Ni0zLjA4NQ0KCWMxLjE3Mi0wLjk4NCwyLjY0Ny0xLjQ4Nyw0LjQ0Mi0xLjQ4N2MxLjc4MiwwLDMuMjI4LDEuMDQ3LDQuMywzLjEwNmMxLjA3MSwyLjA1NiwyLjA1Nyw0LjM3NSwyLjk1NCw2Ljk3OQ0KCWMwLjg5NiwyLjYwMywxLjg4Miw0Ljk0NSwyLjk2Niw3LjAwMWMxLjA3LDIuMDU1LDIuNTkzLDMuMDg1LDQuNTYyLDMuMDg1YzEuOTY4LDAsMy40NTctMC42OCw0LjQ0MS0yLjAxNg0KCWMwLjk4NC0xLjM1NCwxLjkyNy0yLjgxOCwyLjgyNC00LjQzOGMwLjg5Ni0xLjYxOSwxLjkyNC0zLjA4NSwzLjA5Ni00LjQ0M2MxLjE1OC0xLjMzNSwyLjgyMi0yLjAxNSw0Ljk2Ny0yLjAxNQ0KCWMyLjE1NCwwLDMuOTM4LDAuOTg1LDUuMzgzLDIuOTU1YzEuNDMzLDEuOTkyLDIuOTEsNC4xMzgsNC40MzEsNi40NTNjMS41MjIsMi4zNDQsMy4xODQsNC40ODcsNC45NzksNi40NTkNCgljMS43ODMsMS45NjksNC4yMTMsMi45NTMsNy4yNTQsMi45NTNjMi44NjcsMCw1LjI1Mi0wLjk4NCw3LjEzNi0yLjk1M2MxLjg4Mi0xLjk3MiwzLjU3Ni00LjExNSw1LjEwNi02LjQ1OQ0KCWMxLjUyMi0yLjMxNSwzLjAwMS00LjQ2MSw0LjQzMy02LjQ1M2MxLjQzNC0xLjk3LDMuMjI5LTIuOTU1LDUuMzg1LTIuOTU1YzIuMTQ1LDAsMy44MDcsMC42OCw0Ljk2NywyLjAxNQ0KCWMxLjE3MiwxLjM1OCwyLjE5OSwyLjgyNCwzLjA5Niw0LjQ0M2MwLjg5OCwxLjYxOSwxLjg0LDMuMDg1LDIuODIyLDQuNDM4YzAuOTg0LDEuMzM2LDIuNDYyLDIuMDE2LDQuNDQzLDIuMDE2DQoJYzEuOTcsMCwzLjQ5LTEuMDMsNC41NjItMy4wODVjMS4wODItMi4wNTYsMi4wNTgtNC4zOTgsMi45NjYtNy4wMDFjMC44ODctMi42MDQsMS44ODMtNC45MjYsMi45NTUtNi45NzkNCgljMS4wNjktMi4wNjEsMi41MDQtMy4xMDYsNC4yOTktMy4xMDZzMy4yMjcsMC41MDMsNC4yOTksMS40ODdjMS4wODQsMC45ODQsMi4xMTEsMi4wMTEsMy4wOTgsMy4wODUNCgljMC45ODQsMS4wNywyLjAxMiwyLjEyMiwzLjA5NiwzLjEwN2MxLjA3MiwwLjk4NCwyLjQyLDEuNDY2LDQuMDI3LDEuNDY2YzEuNzk1LDAsMy4xMzktMS4wMjksNC4wMzYtMy4wODUNCgljMC44OTYtMi4wNiwxLjY5Ny00LjM5NiwyLjQxOS03LjAwN2MwLjcxLTIuNTgxLDEuNDMyLTQuOTIsMi4xNTQtNi45NzljMC43MTMtMi4wNTUsMS43ODEtMy4wODUsMy4yMjktMy4wODUNCgljMS40MzIsMCwyLjUwNSwwLjMwOCwzLjIyOCwwLjk0YzAuNzExLDAuNjE2LDEuMjkxLDEuMjksMS43NDEsMi4wMTVjMC40NDUsMC43MjUsMC43NjUsMS4zODEsMC45MzksMi4wMTYNCgljMC4xNzUsMC42MzQsMC40NDgsMC45MzksMC44MDksMC45MzljMS40MzYsMCwyLjE1Ni0xLjk3MSwyLjE1Ni01Ljkxdi0yMC4xNzRjMC01LjczMi0zLjU4OS0xMC44NTQtMTAuNzU2LTE1LjMxNXY2LjE3DQoJYzAsNC4xMzctMi4wMjEsNy45NC02LjA1LDExLjQyMmMtNC4wMzgsMy40OTktOS41MTEsNi41ODgtMTYuNDAxLDkuMjc2Yy02LjkwMiwyLjY5My0xNS4wMjEsNC44MTYtMjQuMzQ0LDYuMzIzDQoJYy05LjMyLDEuNTM0LTE5LjI2OCwyLjI5OC0yOS44NDgsMi4yOThjLTEwLjU2OCwwLTIwLjUyNC0wLjc2NC0yOS44MzYtMi4yOThjLTkuMzMyLTEuNTA3LTE3LjQ4Mi0zLjYzLTI0LjQ3Ni02LjMyMw0KCWMtNi45OTEtMi42ODgtMTIuNTA1LTUuNzc3LTE2LjUzMi05LjI3NmMtNC4wMzctMy40OC02LjA1LTcuMjg1LTYuMDUtMTEuNDIydi02LjE3Yy0zLjQxNCwyLjE0NS02LjA1MSw0LjUyNy03LjkzNCw3LjEwOA0KCWMtMS44ODIsMi42MDQtMi44MjIsNS4zMzgtMi44MjIsOC4yMDd2MjAuMTc0SDE0Ljc5NHogTTIzLjY2NywxMDcuMDI3YzAsMy43NjMsMC42MjUsNS42NDQsMS44ODMsNS42NDQNCgljMC4zNTEsMCwwLjYyMy0wLjMwNywwLjc5OC0wLjkzOGMwLjE4OC0wLjYzNiwwLjQ1LTEuMjQ2LDAuODExLTEuODgxYzAuMzYxLTAuNjQsMC44NTQtMS4yNSwxLjQ3OC0xLjg4NQ0KCWMwLjYzNy0wLjYzNCwxLjY2NC0wLjkzOCwzLjA5Ni0wLjkzOGMxLjI0OCwwLDIuMjQ0LDAuOTgzLDIuOTU1LDIuOTU0YzAuNzIzLDEuOTcsMS4zNDgsNC4xNTQsMS44ODIsNi41ODQNCgljMC41MzYsMi40MzMsMS4yMTQsNC42MTksMi4wMjQsNi41ODhjMC43OTgsMS45NzEsMi4wMTUsMi45NTUsMy42MjIsMi45NTVjMS42MTgsMCwyLjg2Ni0wLjQzOCwzLjc2NS0xLjMzNg0KCWMwLjg5Ni0wLjg5NiwxLjc5My0xLjkyNiwyLjY5MS0zLjEwN2MwLjg5Ni0xLjE2LDEuODgxLTIuMTg2LDIuOTYzLTMuMDg1YzEuMDcxLTAuODk1LDIuNDItMS4zMzUsNC4wMjYtMS4zMzUNCgljMS42MTksMCwyLjg2NywxLjAyOSwzLjc2NSwzLjA4NGMwLjg5NiwyLjA2MiwxLjc1LDQuMzEzLDIuNTU5LDYuNzE5YzAuODAxLDIuNDMxLDEuNjk4LDQuNjE4LDIuNjkxLDYuNTg4DQoJYzAuOTc3LDEuOTcyLDIuMzYyLDIuOTU1LDQuMTU3LDIuOTU1YzEuNzk0LDAsMy4xNDItMC42MTUsNC4wMzktMS44ODVjMC44OTYtMS4yNDUsMS43NTEtMi42ODgsMi41NjItNC4yODUNCgljMC43OTktMS42MjMsMS42OTUtMy4wNDUsMi42OC00LjMxMmMwLjk4NC0xLjI0NiwyLjQ2My0xLjg4Niw0LjQ0MS0xLjg4NmMxLjk3LDAsMy41NzYsMC45NDQsNC44MzYsMi44MjQNCgljMS4yNiwxLjg4NSwyLjU2MiwzLjkzOSwzLjkwNSw2LjE5MmMxLjMzNiwyLjIzNSwyLjg1NSw0LjI5LDQuNTYyLDYuMTdjMS43MDcsMS44ODUsMy45MDUsMi44MjUsNi41ODYsMi44MjUNCgljMi42OSwwLDQuODkxLTAuOTQsNi41OTgtMi44MjVjMS42OTctMS44OCwzLjIxNy0zLjkzNSw0LjU2My02LjE3YzEuMzQ1LTIuMjUzLDIuNjQ2LTQuMzEsMy45MDYtNi4xOTINCgljMS4yNDQtMS44OCwyLjg2NS0yLjgyNCw0LjgzNC0yLjgyNHMzLjQ0NSwwLjY0LDQuNDQyLDEuODg2YzAuOTc0LDEuMjY4LDEuODgyLDIuNjg4LDIuNjgxLDQuMzEyDQoJYzAuODA5LDEuNTk4LDEuNjY0LDMuMDQsMi41NjEsNC4yODVjMC44OTcsMS4yNywyLjIzLDEuODg1LDQuMDI1LDEuODg1YzEuNzk0LDAsMy4xODQtMC45ODMsNC4xNjktMi45NTUNCgljMC45ODMtMS45NywxLjg4Mi00LjE1NywyLjY5LTYuNTg4YzAuODExLTIuNDA0LDEuNjUyLTQuNjU3LDIuNTYxLTYuNzE5YzAuODg3LTIuMDU1LDIuMTQ2LTMuMDg0LDMuNzY2LTMuMDg0DQoJYzEuNjA3LDAsMi45MDksMC40NCwzLjg5NiwxLjMzNWMwLjk4MywwLjg5OSwxLjkyNCwxLjkyNSwyLjgyMSwzLjA4NWMwLjg5NiwxLjE4NCwxLjc5NCwyLjIxMywyLjY5MSwzLjEwNw0KCWMwLjg5NiwwLjg5OCwyLjE0NiwxLjMzNiwzLjc2NCwxLjMzNmMxLjYwNywwLDIuODI0LTAuOTg0LDMuNjM1LTIuOTU1YzAuNzk4LTEuOTY5LDEuNTIxLTQuMTU1LDIuMTQzLTYuNTg4DQoJYzAuNjM3LTIuNDMsMS4zMDMtNC42MTQsMi4wMjYtNi41ODRjMC43MTEtMS45NzEsMS42OTItMi45NTQsMi45NTItMi45NTRjMS4yNDYsMCwyLjE4NywwLjMwNiwyLjgyMiwwLjkzOA0KCWMwLjYyMywwLjYzNSwxLjExNSwxLjI0NSwxLjQ3OSwxLjg4NWMwLjM1OSwwLjYzNSwwLjYyMywxLjI0NSwwLjgxLDEuODgxYzAuMTc3LDAuNjMzLDAuNDQ3LDAuOTM4LDAuODEsMC45MzgNCgljMS40MzQsMCwyLjE0NS0xLjg4MSwyLjE0NS01LjY0NFY4Ny45MjNjMC01LjAwOS0zLjIyNy05LjkzNC05LjY4My0xNC43OXY2LjE5MmMwLDMuNzY0LTEuNzgzLDcuMzUzLTUuMzcyLDEwLjc2Ng0KCWMtMy41OSwzLjM5Mi04LjUxNCw2LjM0Ny0xNC43OTMsOC44NTljLTYuMjcxLDIuNTE5LTEzLjYyMSw0LjQ4OC0yMi4wNDUsNS45MDljLTguNDI2LDEuNDQyLTE3LjM4NSwyLjE2OC0yNi44OTYsMi4xNjgNCgljLTkuNDk1LDAtMTguNDU2LTAuNzI2LTI2Ljg4Mi0yLjE2OGMtOC40MjUtMS40MjEtMTUuNzc3LTMuMzkyLTIyLjA0Ni01LjkwOWMtNi4yODEtMi41MTUtMTEuMjE0LTUuNDY5LTE0Ljc5MS04Ljg1OQ0KCWMtMy41ODktMy40MTMtNS4zODQtNy4wMDItNS4zODQtMTAuNzY2di02LjE5MmMtNi40NDQsNC44NTYtOS42NzQsOS43ODEtOS42NzQsMTQuNzlWMTA3LjAyN0wyMy42NjcsMTA3LjAyN3ogTTMxLjQ1OCw2MS4wMw0KCWMwLDMuNTg5LDAuNjM1LDUuMzg0LDEuODgzLDUuMzg0YzAuMzU5LDAsMC42MzQtMC4yNjYsMC44MS0wLjgxYzAuMTc2LTAuNTI2LDAuNDA0LTEuMTYsMC42NzctMS44ODYNCgljMC4yNjQtMC42OTYsMC43MTUtMS4zMywxLjMzNi0xLjg3OWMwLjYzNS0wLjUyNiwxLjQ4OS0wLjgxMSwyLjU2Mi0wLjgxMWMxLjA2OCwwLDEuOTI0LDAuOTQsMi41NDcsMi44MjQNCgljMC42MzcsMS44ODEsMS4yNiw0LjAwMywxLjg4Myw2LjMyMmMwLjYzNywyLjMzOSwxLjMwMSw0LjQ0NCwyLjAyMyw2LjMyM2MwLjcxMywxLjg3OSwxLjc4NSwyLjgyNCwzLjIyOSwyLjgyNA0KCWMxLjI0OCwwLDIuMzE4LTAuNDQxLDMuMjI4LTEuMzM2YzAuODg3LTAuODk5LDEuNzM5LTEuODQsMi41NS0yLjgyNHMxLjY1My0xLjkyNSwyLjU2Mi0yLjgxOWMwLjg4NS0wLjg5OCwyLjA1Ni0xLjM1NywzLjQ4OS0xLjM1Nw0KCWMxLjQzMywwLDIuNTkzLDAuOTM4LDMuNSwyLjgyNGMwLjg4OCwxLjg4LDEuNjk2LDMuOTM2LDIuNDE4LDYuMTkyYzAuNzEzLDIuMjI5LDEuNDc4LDQuMjg1LDIuMjg2LDYuMTY5DQoJYzAuODAxLDEuODgsMi4wMTUsMi44MTksMy42MjIsMi44MTljMS42MTksMCwyLjgyMi0wLjU2NiwzLjYzMy0xLjcyOGMwLjgxLTEuMTgzLDEuNTYzLTIuNDczLDIuMjg1LTMuOTE2DQoJYzAuNzEzLTEuNDIyLDEuNTIyLTIuNzEzLDIuNDItMy44OTVjMC44OTctMS4xNiwyLjI0Mi0xLjc1LDQuMDM2LTEuNzVjMS43ODMsMCwzLjI3MSwwLjg1LDQuNDMzLDIuNTU5DQoJYzEuMTczLDEuNzA1LDIuMzc1LDMuNjM1LDMuNjMyLDUuNzc5YzEuMjYsMi4xNjIsMi41OTQsNC4wODgsNC4wMzksNS43NzNjMS40MzIsMS43MDksMy4zOTksMi41Niw1LjkwNSwyLjU2DQoJYzIuMzMyLDAsNC4yNTctMC44NTEsNS43ODctMi41NmMxLjUyMy0xLjY4NywyLjg2Ny0zLjYxMSw0LjAyNy01Ljc3M2MxLjE3Mi0yLjE0NiwyLjMzLTQuMDc0LDMuNTAxLTUuNzc5DQoJYzEuMTU5LTEuNzA5LDIuNjQ4LTIuNTU5LDQuNDM0LTIuNTU5YzEuNzkzLDAsMy4xODMsMC41OSw0LjE2OCwxLjc1YzAuOTgzLDEuMTgyLDEuODM4LDIuNDczLDIuNTU5LDMuODk1DQoJYzAuNzEzLDEuNDQzLDEuNDc5LDIuNzMzLDIuMjg3LDMuOTE2YzAuNzk5LDEuMTYsMi4wMTYsMS43MjgsMy42MzQsMS43MjhjMS42MDgsMCwyLjgyMi0wLjkzOSwzLjYyLTIuODE5DQoJYzAuODEtMS44ODQsMS41NzYtMy45MzgsMi4yODctNi4xNjljMC43MTMtMi4yNTgsMS41MjItNC4zMTIsMi40MTktNi4xOTJjMC44OTctMS44ODYsMi4wNjYtMi44MjQsMy41MDEtMi44MjQNCgljMS40MzIsMCwyLjU5NCwwLjQ1OSwzLjQ5LDEuMzU3YzAuODk2LDAuODk2LDEuNzA2LDEuODM1LDIuNDI4LDIuODE5YzAuNzExLDAuOTg0LDEuNTIzLDEuOTI1LDIuNDIsMi44MjQNCgljMC44OTYsMC44OTUsMi4wNTUsMS4zMzYsMy40OSwxLjMzNmMxLjQzMiwwLDIuNTA0LTAuOTQ1LDMuMjI3LTIuODI0YzAuNzExLTEuODc5LDEuMzktMy45ODQsMi4wMTMtNi4zMjMNCgljMC42MzctMi4zMTksMS4yNi00LjQ0MSwxLjg4My02LjMyMmMwLjYzNi0xLjg4NCwxLjQ4OC0yLjgyNCwyLjU2Mi0yLjgyNGMxLjA3MiwwLDEuOTI0LDAuMjgzLDIuNTUsMC44MTENCgljMC42MzQsMC41NDksMS4wODEsMS4xODMsMS4zNDUsMS44NzljMC4yNzMsMC43MjYsMC40OTIsMS4zNTgsMC42NzksMS44ODZjMC4xNzcsMC41NDQsMC40MzgsMC44MSwwLjgsMC44MQ0KCWMxLjA4MiwwLDEuNjE4LTEuNzk1LDEuNjE4LTUuMzg0VjQzLjAyYzAtMy43NjYtMS43MDctNy4zNTQtNS4xMS0xMC43NDRjLTMuNC0zLjQxMy04LjExOC02LjM2Ny0xNC4xMTItOC44ODENCgljLTYuMDA3LTIuNDk2LTEzLjA0MS00LjU3NC0yMS4xMDQtNi4xNzZjLTguMDc0LTEuNjItMTYuNzcyLTIuNTE1LTI2LjA4My0yLjY4OGMxLjk2OCwwLjg5NSwzLjUzMSwyLjE4OCw0LjcwNCwzLjg5NQ0KCWMxLjE2MSwxLjcwOSwxLjczOSwzLjYzNSwxLjczOSw1Ljc3OGMwLDMuMDQxLTEuMDI5LDUuNTk5LTMuMDg3LDcuNjU5Yy0yLjA2NCwyLjA1NS00LjYxNiwzLjEwNi03LjY2OSwzLjEwNg0KCWMtMy4wNDEsMC01LjYwMy0xLjA1My03LjY1OC0zLjEwNmMtMi4wNjUtMi4wNjItMy4wOTYtNC42MTgtMy4wOTYtNy42NTljMC00LjY2MywyLjA2OC03Ljg3OSw2LjE5LTkuNjczDQoJYy05LjMxOSwwLjE3NS0xOC4wMiwxLjA2OC0yNi4wODIsMi42ODhjLTguMDYzLDEuNjAyLTE1LjExLDMuNjgtMjEuMTA0LDYuMTc2Yy02LjAxOSwyLjUxNC0xMC43MjMsNS40NjgtMTQuMTI2LDguODgxDQoJYy0zLjQsMy4zOTEtNS4xMDcsNi45NzktNS4xMDcsMTAuNzQ0djE4LjAxSDMxLjQ1OHogTTEwMi40NDMsNzEuMjQ5Yy0xLjc5NSwwLTIuNjgtMC44NzMtMi42OC0yLjY2OA0KCWMwLTEuNzk0LDAuODg1LTIuNjkzLDIuNjgtMi42OTNjOS41MSwwLDE4LjE5NS0wLjY3OSwyNi4wODUtMi4wMzJjNy44ODgtMS4zMzUsMTQuNjA1LTMuMDksMjAuMTc1LTUuMjI5DQoJYzUuNTQ4LTIuMTQ2LDkuODQ5LTQuNjE5LDEyLjg5OS03LjM5N2MzLjA1My0yLjc3OSw0LjU3Mi01LjUxNSw0LjU3Mi04LjIwN2MwLTEuNjE5LDAuODExLTIuNDA2LDIuNDE4LTIuNDA2DQoJYzEuNzkzLDAsMi42OTEsMC43ODcsMi42OTEsMi40MDZjMCw0LjEzNy0xLjc5NSw3Ljk0NC01LjM4NCwxMS40MjJjLTMuNTg5LDMuNTA0LTguNzg0LDYuNDk5LTE1LjU4OSw5LjAxOA0KCWMtNi40NTUsMi41MTktMTMuNzY3LDQuNDQyLTIxLjkxNiw1Ljc3N0MxMjAuMjM0LDcwLjU5MiwxMTEuNTg5LDcxLjI0OSwxMDIuNDQzLDcxLjI0OXogTTEwNi43NTUsMTguNTU1DQoJYy0xLjQ0NCwwLTIuMTU1LDAuNzI2LTIuMTU1LDIuMTQ2YzAsMS4yNjksMC43MTEsMS44ODQsMi4xNTUsMS44ODRjMS40MzQsMCwyLjE0NC0wLjYxNSwyLjE0NC0xLjg4NA0KCUMxMDguODk4LDE5LjI4LDEwOC4xODgsMTguNTU1LDEwNi43NTUsMTguNTU1eiIvPg0KPC9zdmc+',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Oven</title>

    <!-- Bootstrap -->
    <link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">

    <!-- HTML5 shim and Respond.js for IE8 support of HTML5 elements and media queries -->
    <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
    <!--[if lt IE 9]>
    <script src="//oss.maxcdn.com/html5shiv/3.7.3/html5shiv.min.js"></script>
    <script src="//oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->

    <!-- Fonts -->
    <link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/font-awesome/4.6.3/css/font-awesome.min.css">
    <link href="//fonts.googleapis.com/css?family=Raleway:700,500i|Roboto:300,400,700|Roboto+Mono" rel="stylesheet">
    <style type="text/css">
        body {
            color: #404041;
        }

        ul {
            list-style: none;
        }

        a:hover {
            text-decoration: none;
            -webkit-transition: all 0.5s;
            -moz-transition: all 0.5s;
            -ms-transition: all 0.5s;
            -o-transition: all 0.5s;
            transition: all 0.5s;
        }

        a {
            -webkit-transition: all 0.5s;
            -moz-transition: all 0.5s;
            -ms-transition: all 0.5s;
            -o-transition: all 0.5s;
            transition: all 0.5s;
        }

        .logo {
            text-align: center;
            height: 60px;
            background-color: #d33c44;
            padding-top: 14px;
            padding-left: 0px;
            padding-right: 0px;
        }

        h1 {
            height: 60px;
            font-family: 'Raleway', sans-serif;
            font-style: italic;
            font-size: 18px;
            font-weight: 500;
            padding-top: 20px;
            color: #DEDED5;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            background-color: #404041;
            text-align: center;
            line-height: 19px;
            margin: 0;
        }

        pre {
            white-space: pre-wrap; /* Since CSS 2.1 */
            white-space: -moz-pre-wrap; /* Mozilla, since 1999 */
            white-space: -o-pre-wrap; /* Opera 7 */
            word-wrap: break-word; /* Internet Explorer 5.5+ */
            height: 200px;
            background-color: #404041;
            color: #DEDED5;
            font-family: 'Roboto Mono', monospace;
            font-size: 11px;
        }

        p {
            font-family: 'Roboto', sans-serif;
            font-size: 14px;
            margin: 0px;
            line-height: 20px;
        }

        h2 {
            font-family: 'Roboto', sans-serif;
            font-size: 27px;
            line-height: 43px;
            font-weight: 300;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        a {
            color: #0071BC;
        }

        .header {
            margin-bottom: 30px;
        }

        .text-success {
            color: #88C671;
        }

        .text-danger {
            color: #d33c44;
        }

        .congrats {
            color: #d33c44;
            font-family: 'Raleway', sans-serif;
            font-style: italic;
            font-size: 18px;
            font-weight: 500;
            padding-top: 20px;
        }

        .path {
            font-family: 'Roboto Mono', monospace;
            font-size: 10px;
        }

        .path::before {
            color: #DEDED5;
            content: "\f07c";
            display: inline-block;
            font-family: FontAwesome;
            width: 1.3em;
            font-size: 11px;
        }

        .cake-button {
            position: absolute;
            left: 50%;
            margin-top: 135px;
            margin-left: -108px;
            text-align: center;
            z-index: 2;
        }

        .cake-button span {
            font-size: 15px;
            font-family: "Raleway", sans-serif;
            font-weight: 700;
            letter-spacing: 0.05em;
            color: #DEDED5;
            background-color: #404041;
            border: 0;
            padding: 21px 20px 17px 20px;
            border-radius: 0 12px 12px 0;
            display: inline-block;
            vertical-align: top;
            height: 60px;
            margin: 0 0 0 -4px;
            box-shadow: 0px 7px 0px #353535;
        }

        .cake-button:hover {
            -webkit-filter: brightness(80%);
            filter: brightness(80%);
            transition: all .2s ease-in-out;
        }

        .cake-button:active span, .cake-button:active img {
            box-shadow: none;
            margin-top: 7px !important;
            margin-bottom: -7px !important;
        }

        .cake-button img {
            background-color: #d33c43;
            line-height: 30px;
            padding: 16px 16px 13px 17px;
            border-radius: 12px 0px 0px 12px;
            display: inline-block;
            vertical-align: top;
            height: 60px;
            box-shadow: 0px 7px 0px #AF333C;
        }

        .mixer-button {
            position: absolute;
            left: 50%;
            margin-top: 235px;
            margin-left: -72px;
            text-align: center;
            z-index: 2;
        }

        .mixer-button span {
            font-size: 12px;
            font-family: "Raleway", sans-serif;
            font-weight: 700;
            letter-spacing: 0.05em;
            color: #DEDED5;
            background-color: #404041;
            border: 0;
            padding: 11px 10px 4px 10px;
            border-radius: 0 6px 6px 0;
            display: inline-block;
            vertical-align: top;
            height: 36px;
            margin: 0 0 0 -4px;
            box-shadow: 0px 5px 0px #353535;
        }

        .mixer-button:hover {
            -webkit-filter: brightness(80%);
            filter: brightness(80%);
            transition: all .2s ease-in-out;
        }

        .mixer-button:active span, .mixer-button:active img {
            box-shadow: none;
            margin-top: 5px !important;
            margin-bottom: -5px !important;
        }

        .mixer-button img {
            background-color: #d33c43;
            line-height: 36px;
            padding: 11px 10px 11px 10px;
            border-radius: 6px 0px 0px 6px;
            display: inline-block;
            vertical-align: top;
            height: 36px;
            box-shadow: 0px 5px 0px #AF333C;
        }

        .starting {
            font-family: "Raleway", sans-serif;
            font-size: 18px;
            font-weight: 700;
            text-align: center;
        }

        #start img {
            transition: all 0.75s 0.25s;
            transform: rotate(0);
        }

        #start.rotate img {
            transform: rotate(90deg);
        }

        .starting a {
            color: #404041;
            line-height: 1.8em;
        }

        .starting a:hover {
            opacity: 0.5;
        }

        .checklist {
            list-style-type: none;
            padding-left: 28px;
        }

        .checklist i.fa {
            margin-right: 3px;
        }

        .checklist span.text-danger {
            font-weight: bold;
        }

        #progress {
            position: relative;
            text-align: center;
            padding-top: 35px;
            padding-bottom: 35px;
        }
        #progress.finished img {
            opacity: 0.5;
        }
        #progress img { display: none; }
        #progress.progress-1 img.p1 { display: inline; }
        #progress.progress-2 img.p2 { display: inline; }
        #progress.progress-3 img.p3 { display: inline; }
        #progress.progress-4 img.p4 { display: inline; }
        #progress.progress-5 img.p5 { display: inline; }
        #progress.progress-6 img.p6 { display: inline; }
        #progress.progress-7 img.p7 { display: inline; }
        #progress.progress-8 img.p8 { display: inline; }
        #progress.progress-9 img.p9 { display: inline; transition: all .3s ease .3s; }

        #composer_path {
            width: 175px;
            display: inline-block;
        }

        .btn {
            border-radius: 0;
            background: #d33c44;
            border-color: #a01f26;
        }
        .btn-primary:hover, .btn-primary:focus, .btn-primary:active  {
            background: #c82e36 !important;
            border-color: #8c1b21 !important;;
        }

        .input-group-addon {
            border-radius: 0;
            border-color: #deded5;
        }

        .form-control {
            border-color: #deded5;
            border-radius: 0;
            -webkit-box-shadow: none;
            -moz-box-shadow: none;
            box-shadow: none;
        }

        .radio {
            margin-top: 3px;
            margin-bottom: 3px;
        }

        .form-group {
            margin-bottom: 5px;
        }

        fieldset {
            margin-bottom: 10px;
        }

        legend {
            margin-bottom: 5px;
            padding-bottom: 5px;
            border-color: #deded5;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .form-group label, .radio label {
            margin-bottom: 3px;
            font-size: 12px;
            text-transform: uppercase;
        }

        legend label {
            margin: 0;
        }

        .radio label {
            text-transform: none;
        }

        input[type=radio] {
            margin-top: 2px;
        }
        input[type=checkbox] {
            margin-top: 4px;
        }

        @media (min-width: 1200px) {
            .container {
                width: 970px;
            }
        }

        @media (min-width: 768px) {
            body {
                margin-top: 40px;
                margin-bottom: 40px;
            }
            .header {
                padding-left: 30px;
                padding-right: 30px;
            }
            .hide-me {
                display: none;
            }
            .cake-button {
                margin-left: -159px;
            }
            .starting {
                margin-top: 120px;
            }
        }

        @media (max-width: 767px) {
            h1 {
                font-size: 14px;
                padding: 12px;
                line-height: 18px;
                height: auto;
            }

            .cake-button span {
                padding: 15px 20px 17px 20px;
                line-height: 17px;
            }
        }

    </style>
</head>
<body>

<div class="container header">
    <div class="row">
        <div class="col-md-4 logo">
            <img src="<?php echo $svgs['logo'] ?>"/>
        </div>
        <div class="col-md-8" style="padding-left:0; padding-right:0"><h1>Welcome to Oven. The easiest way to install CakePHP.</h1></div>
    </div>
</div>

<div class="container">

    <div id="splash">
        <form action="" class="row" id="config-form">
            <div class="col-lg-6">
                <fieldset>
                    <legend style="border: none; margin: 0"><label for="app_dir">APP INSTALL DIR</label></legend>
                    <div class="form-group">
                        <div class="input-group">
                            <div class="input-group-addon" id="current_dir"><?php echo $oven->currentDir; ?></div>
                            <input type="text" class="form-control" id="app_dir" name="app_dir" value="<?php echo $oven->appDir; ?>" />
                        </div>
                    </div>
                </fieldset>
                <fieldset>
                    <legend style="border: none; margin: 0"><label for="version">CAKEPHP VERSION</label></legend>
                    <div class="form-group">
                        <div class="input-group">
                            <select class="form-control" name="version" id="version">
                            <?php foreach ($oven->versions as $k => $v): ?>
                                <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                            <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="checkbox">
                        <label for="install_mixer">
                            <input type="checkbox" name="install_mixer" id="install_mixer"> Install <a href="https://github.com/cakedc/mixer" target="_blank">Mixer plugin</a>
                        </label>
                    </div>
                </fieldset>
                <fieldset>
                    <legend>Composer</legend>
                    <?php $composerPath = $oven->getComposerSystemPath(); ?>
                    <div class="radio">
                        <label for="install_composer1">
                            <input type="radio" name="install_composer" id="install_composer1" value="1" <?php if (!$composerPath) echo 'checked'; ?>>
                            Install Composer to current dir
                        </label>
                    </div>
                    <div class="radio">
                        <label for="install_composer0">
                            <input type="radio" name="install_composer" id="install_composer0" value="0" required <?php if ($composerPath) echo 'checked'; ?>>
                            Use existing composer installation. Path:
                        </label>
                        <input type="text" class="form-control" id="composer_path" name="composer_path" value="<?php echo $composerPath; ?>" required />
                    </div>
                </fieldset>
                <fieldset>
                    <legend>Database configuration<button type="button" id="test-database-button" class="btn btn-primary btn-xs pull-right">Test connection</button></legend>
                    <div class="row">
                        <div class="col-xs-12">
                            <div id="database-message"></div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-xs-6">
                            <div class="form-group">
                                <label for="host">Host</label>
                                <input type="text" class="form-control" id="host" name="host" tabindex="1" />
                            </div>
                            <div class="form-group">
                                <label for="username">Username</label>
                                <input type="text" class="form-control" id="username" name="username" tabindex="3" />
                            </div>
                        </div>
                        <div class="col-xs-6">
                            <div class="form-group">
                                <label for="database">Database</label>
                                <input type="text" class="form-control" id="database" name="database" tabindex="2" />
                            </div>
                            <div class="form-group">
                                <label for="password">Password</label>
                                <input type="password" class="form-control" id="password" name="password" tabindex="4" />
                            </div>
                        </div>
                    </div>
                </fieldset>
            </div>
            <div class="col-lg-6">
                <p class="starting"><a href="javascript:;" id="start"><img src="<?php echo $svgs['knob'] ?>" /><br />Click to install CakePHP</a></p>
            </div>
        </form>
    </div>

    <div id="installation" class="row" style="display:none">
        <div class="col-md-6">

            <h2>1. Requirements</h2>
            <ul class="checklist requirements-list list"></ul>

            <div id="composer-list-wrapper" style="display: none">
                <h2>2. Composer</h2>
                <ul class="checklist composer-list list"></ul>
            </div>

            <div id="cake-list-wrapper" style="display: none">
                <h2>3. CakePHP Setup</h2>
                <ul class="checklist cake-list list"></ul>
            </div>

            <div class="on-finish" style="padding-left: 28px; display: none">
                <p class="congrats">Congratulations!</p>
                <p>You just baked your cake :D</p>
            </div>
        </div>

        <div class="col-md-6">
            <a href="<?php echo $oven->appDir ?>" class="cake-button on-finish" id="go_to_your_app" target="_blank" style="display: none">
                <img src="<?php echo $svgs['go-to-your-app'] ?>" />
                <span>GO TO YOUR <br class="hide-me">CAKEPHP APP</span>
            </a>
            <a href="<?php echo $oven->appDir ?>/mixer" class="mixer-button on-finish" id="go_to_mixer" target="_blank" style="display: none">
                <img src="<?php echo $svgs['go-to-mixer'] ?>" />
                <span>GO TO MIXER</span>
            </a>

            <div id="progress" class="progress-1">
                <img src="<?php echo $svgs['progress-1'] ?>" class="p1" />
                <img src="<?php echo $svgs['progress-2'] ?>" class="p2" />
                <img src="<?php echo $svgs['progress-3'] ?>" class="p3" />
                <img src="<?php echo $svgs['progress-4'] ?>" class="p4" />
                <img src="<?php echo $svgs['progress-5'] ?>" class="p5" />
                <img src="<?php echo $svgs['progress-6'] ?>" class="p6" />
                <img src="<?php echo $svgs['progress-7'] ?>" class="p7" />
                <img src="<?php echo $svgs['progress-8'] ?>" class="p8" />
                <img src="<?php echo $svgs['progress-9'] ?>" class="p9" />
            </div>
            <p class="path" id="install_dir"><?php echo $oven->installDir ?></p>
            <pre id="log"></pre>

        </div>

    </div>
</div>

<script src="//ajax.googleapis.com/ajax/libs/jquery/2.1.4/jquery.min.js"></script>
<script src="//maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous"></script>
<script>
    // AjaxQ jQuery Plugin
    // Copyright (c) 2012 Foliotek Inc.
    // MIT License
    // https://github.com/Foliotek/ajaxq
    // Uses CommonJS, AMD or browser globals to create a jQuery plugin.
    !function(a){"function"==typeof define&&define.amd?define(["jquery"],a):"object"==typeof module&&module.exports?module.exports=a(require("jquery")):a(jQuery)}(function(a){var b={},c={};a.ajaxq=function(d,e){function j(a){if(b[d])b[d].push(a);else{b[d]=[];var e=a();c[d]=e}}function k(){if(b[d]){var a=b[d].shift();if(a){var e=a();c[d]=e}else delete b[d],delete c[d]}}if("undefined"==typeof e)throw"AjaxQ: queue name is not provided";var f=a.Deferred(),g=f.promise();g.success=g.done,g.error=g.fail,g.complete=g.always;var h="function"==typeof e,i=h?null:a.extend(!0,{},e);return j(function(){var b=a.ajax.apply(window,[h?e():i]);return b.done(function(){f.resolve.apply(this,arguments)}),b.fail(function(){f.reject.apply(this,arguments)}),b.always(k),b}),g},a.each(["getq","postq"],function(b,c){a[c]=function(b,d,e,f,g){return a.isFunction(e)&&(g=g||f,f=e,e=void 0),a.ajaxq(b,{type:"postq"===c?"post":"get",url:d,data:e,success:f,dataType:g})}});var d=function(a){return b.hasOwnProperty(a)&&b[a].length>0||c.hasOwnProperty(a)},e=function(){for(var a in b)if(d(a))return!0;return!1};a.ajaxq.isRunning=function(a){return a?d(a):e()},a.ajaxq.getActiveRequest=function(a){if(!a)throw"AjaxQ: queue name is required";return c[a]},a.ajaxq.abort=function(d){if(!d)throw"AjaxQ: queue name is required";var e=a.ajaxq.getActiveRequest(d);delete b[d],delete c[d],e&&e.abort()},a.ajaxq.clear=function(a){if(a)b[a]&&(b[a]=[]);else for(var c in b)b.hasOwnProperty(c)&&(b[c]=[])}});
</script>
<script>

    $(function() {
        $('#start').on('click', function(e) {
            e.preventDefault();

            $('#config-form').submit();

            return false;
        });

        $('#config-form').on('submit', function(e) {
            e.preventDefault();

            $('#start').addClass('rotate');
            setTimeout(function(){
                $('#splash').hide();
                $('#installation').show();

                var $requirementsList = $('.requirements-list');
                var $composerList = $('.composer-list');
                var $cakeList = $('.cake-list');
                var appDir = $('#app_dir').val();
                var currentDir = $('#current_dir').text();

                $('#go_to_your_app').attr('href', appDir);
                $('#go_to_mixer').attr('href', appDir + '/mixer');
                $('#install_dir').text(currentDir + appDir);

                runRequirementsSteps($requirementsList, $composerList, $cakeList, appDir);
            }, 1000);

            return false;
        });

        $('input[name="install_composer"]', '#config-form').on('change', function() {
            $('#composer_path').attr('disabled', $('input:checked[name="install_composer"]').val() == 1 ? 'disabled' : false);
        }).filter(':checked').triggerHandler('change');

        $('#host, #database, #username').on('change', function() {
            var host = $('#host');
            var database = $('#database');
            var username = $('#username');

            if ((host.val() === '') || (database.val() === '') || (username.val() === '')) {
                $('#test-database-button').prop('disabled', true);
            } else {
                $('#test-database-button').prop('disabled', false);
            }
        });
        $('#host, #database, #username').change();

        $('#test-database-button').on('click', function(e) {
            checkDatabaseConnection();
        });
    });

    function runRequirementsSteps($list, $composerList, $cakeList, dir) {
        runSteps(
            'requirements',
            [
                {
                    title: 'Checking path...',
                    data: { action: 'checkPath', dir: dir }
                },
                {
                    title: 'Checking PHP version...',
                    data: { action: 'checkPhp' }
                },
                {
                    title: 'Checking mbstring extension...',
                    data: { action: 'checkMbString' }
                },
                {
                    title: 'Checking openssl/mcrypt extension...',
                    data: { action: 'checkOpenSSL' }
                },
                {
                    title: 'Checking intl extension...',
                    data: { action: 'checkIntl' },
                    success: function(response) {
                        $('#progress').attr('class', '').addClass('progress-2');
                        runComposerSteps($composerList, $cakeList, dir);
                    }
                }
            ],
            $list
        );
    }

    function runComposerSteps($list, $cakeList, dir) {
        $('#composer-list-wrapper').show();

        var title = 'Installing composer...';
        var data = { action: 'installComposer' };

        var composerPath = null;
        if ($('input:checked[name="install_composer"]').val() == 0) {
            composerPath = $('#composer_path').val();
            title = 'Checking composer installation...';
            data.composerPath = composerPath;
        }

        runSteps(
            'composer',
            [
                {
                    title: title,
                    data: data,
                    success: function(response) {
                        $('#progress').attr('class', '').addClass('progress-3');
                        runCakeSteps($cakeList, dir, composerPath);
                    }
                }
            ],
            $list
        );
    }

    function runCakeSteps($list, dir, composerPath) {
        $('#cake-list-wrapper').show();
        runSteps(
            'cake',
            [
                {
                    title: 'Creating CakePHP project...',
                    data: {
                        action: 'createProject',
                        dir: dir,
                        composerPath: composerPath,
                        version: $('select[name="version"]').val(),
                        stability: $('select[name="stability"]').val(),
                        installMixer: $('input[name="install_mixer"]').is(':checked') ? 1 : 0
                    },
                    success: function(response) {
                        var steps = response.steps;
                        steps.push({
                            title: 'Finalising...',
                            data: {
                                action: 'finalise',
                                dir: dir,
                                composerPath: composerPath,
                                host: $('input[name="host"]').val(),
                                username: $('input[name="username"]').val(),
                                password: $('input[name="password"]').val(),
                                database: $('input[name="database"]').val(),
                                installMixer: $('input[name="install_mixer"]').is(':checked') ? 1 : 0
                            }
                        });
                        runSteps('deps', response.steps, $list);
                    },
                    failure: function(response) {
                        if (response.message && response.message.indexOf('installed')) {
                            $('li:last i', $list).removeClass('fa-times fa-fw text-danger').addClass('fa-check fa-fw text-success');
                            $('li:last span', $list).removeClass('text-danger');
                            $('#progress').attr('class', '').addClass('progress-9');
                            setTimeout(function(){
                                if (!$('input[name="install_mixer"]').is(':checked')) {
                                    $('#go_to_mixer').removeClass('on-finish');
                                }
                                $('.on-finish').fadeIn('slow');
                                $('#progress').addClass('finished');
                            }, 2000);
                        }
                    }
                }
            ],
            $list
        );
    }

    function runSteps(queue, steps, $list) {
        steps.forEach(function (step, index) {
            var $listItem = $('<li></li>');
            var $icon = $('<i class="fa fa-fw fa-spin fa-spinner"></i>');
            var $message = $('<span>' + step.title + '</span>');

            $.ajaxq(queue, {
                url: 'oven.php',
                dataType: 'json',
                cache: false,
                method: 'post',
                data: step.data,
                beforeSend: function (jqXHR, settings) {
                    $listItem
                        .append($icon)
                        .append($message)
                        .appendTo($list);

                    if (step.beforeSend) {
                        step.beforeSend(jqXHR, settings);
                    }
                },
                complete: function (response) {
                    if (response.status === 200 && response.responseJSON && response.responseJSON.success === 1) {
                        $icon
                            .removeClass('fa-spin fa-fw fa-spinner')
                            .addClass('fa-check fa-fw text-success');

                        $message.text(response.responseJSON.message);

                        if (step.success) {
                            step.success(response.responseJSON);
                        }

                        if (queue == 'deps') {
                            var $progress = $('#progress');

                            var p = Math.round((6 / steps.length) * (index + 1));
                            p = Math.min(p + 3, 9);
                            $progress.attr('class', '').addClass('progress-' + p);

                            if ((index + 1) === steps.length) {
                                // Finished
                                $progress.attr('class', '').addClass('progress-9');
                                setTimeout(function(){
                                    if (!$('input[name="install_mixer"]').is(':checked')) {
                                        $('#go_to_mixer').removeClass('on-finish');
                                    }
                                    $('.on-finish').fadeIn('slow');
                                    $('#progress').addClass('finished');
                                }, 2000);
                            }
                        }
                    } else {
                        $icon
                            .removeClass('fa-spin fa-fw fa-spinner')
                            .addClass('fa-times fa-fw text-danger');

                        $message.text(response.responseJSON.message ? response.responseJSON.message : 'Unknown error').addClass('text-danger');

                        $.ajaxq.abort(queue);

                        if (step.failure) {
                            step.failure(response.responseJSON);
                        }
                    }

                    if (response.responseJSON) {
                        var log;
                        if (response.responseJSON.log) {
                            log = response.responseJSON.log
                        } else if (response.responseJSON.message) {
                            log = response.responseJSON.message;
                        }

                        if (log != null) {
                            var $log = $('#log');
                            $log.append(step.title + "\n" + log + "\n").scrollTop($log[0].scrollHeight);
                        }
                    }
                }
            });
        });
    }

    function checkDatabaseConnection() {
        $.ajax({
            url: 'oven.php',
            dataType: 'json',
            cache: false,
            method: 'post',
            data: {
                action: 'checkDatabaseConnection',
                host: $('#host').val(),
                database: $('#database').val(),
                username: $('#username').val(),
                password: $('#password').val(),
            },
            complete: function (response) {
                if (response.status === 200 && response.responseJSON.success) {
                    $('#database-message').empty();
                    $('#database-message').html('<div class="alert alert-success" role="alert">' + response.responseJSON.message + '</div>');
                } else {
                    $('#database-message').empty();
                    $('#database-message').html('<div class="alert alert-danger" role="alert">' + response.responseJSON.message + '</div>');
                }
            }
        });
    }
</script>

</body>
</html>
