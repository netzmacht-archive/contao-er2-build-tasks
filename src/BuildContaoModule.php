<?php

/**
 * @package    cloud-connector
 * @author     David Molineus <david.molineus@netzmacht.de>
 * @copyright  2014 netzmacht creative David Molineus
 * @license    LGPL 3.0
 * @filesource
 *
 */


use Composer\Autoload\ClassMapGenerator;
use Composer\Config;
use Composer\IO\NullIO;
use Composer\Package\CompletePackage;
use Composer\Repository\VcsRepository;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class BuildContaoModule extends \Task
{
	/**
	 * @var string
	 */
	private $repositoryDir;

	/**
	 * @var string
	 */
	private $packageDir;

	/**
	 * @var array
	 */
	private $config;

	/**
	 * @var string
	 */
	private $configFile;

	/**
	 * @var array
	 */
	private $runonce = array();

	/**
	 * @var array
	 */
	private $symlinks = array();
	
	/**
	 * @var
	 */
	private $modulePath;

	/**
	 * @var
	 */
	private $repository;

	/**
	 * @var array
	 */
	private $dependencies = array();

	/**
	 * @var
	 */
	private $composerDir;


	/**
	 * @param string $baseDir
	 */
	public function setRepositoryDir($baseDir)
	{
		$this->repositoryDir = $baseDir;
	}

	/**
	 * @param string $packageDir
	 */
	public function setPackageDir($packageDir)
	{
		$this->packageDir = $packageDir;
	}


	/**
	 * @param string $configFile
	 */
	public function setConfigFile($configFile)
	{
		$this->configFile = $configFile;
	}


	/**
	 * @param mixed $repository
	 */
	public function setRepository($repository)
	{
		$this->repository = $repository;
	}

	/**
	 * @param mixed $composerDir
	 */
	public function setComposerDir($composerDir)
	{
		$this->composerDir = $composerDir;
	}


	/**
	 *  Called by the project to let the task do it's work. This method may be
	 *  called more than once, if the task is invoked more than once. For
	 *  example, if target1 and target2 both depend on target3, then running
	 *  <em>phing target1 target2</em> will run all tasks in target3 twice.
	 *
	 *  Should throw a BuildException if someting goes wrong with the build
	 *
	 *  This is here. Must be overloaded by real tasks.
	 */
	public function main()
	{
		$this->loadConfig();
		$this->validateProject();
		$this->cleanConfig();
		$this->installDependencies();
		$this->createAutoload();
		$this->finish();
	}


	/**
	 * @throws \RuntimeException
	 */
	private function validateProject()
	{
		$this->log('Validate project', \Project::MSG_INFO);

		if (!array_key_exists('type', $this->config) || $this->config['type'] != 'contao-module') {
			throw new \BuildException('Project ' . $this->config['name'] . ' does not seems to be a contao module.');
		}
	}

	/**
	 *
	 */
	private function loadConfig()
	{
		$this->config = json_decode(file_get_contents($this->repositoryDir . '/composer.json'), true);

		if($this->configFile) {
			$config = json_decode(file_get_contents($this->configFile), true);
			$this->config = array_merge($this->config, $config);
		}
	}

	/**
	 *
	 */
	private function cleanConfig()
	{
		$this->modulePath = false;

		if (array_key_exists('extra', $this->config) &&
			array_key_exists('contao', $this->config['extra'])) {
			if (array_key_exists('symlinks', $this->config['extra']['contao'])) {
				$this->symlinks = array_merge($this->symlinks, $this->config['extra']['contao']['symlinks']);
			}
			if (array_key_exists('sources', $this->config['extra']['contao'])) {
				$this->symlinks = array_merge($this->symlinks, $this->config['extra']['contao']['sources']);
			}
			if (array_key_exists('runonce', $this->config['extra']['contao'])) {
				$this->runonce = $this->config['extra']['contao']['runonce'];
			}
		}
		foreach ($this->symlinks as $target) {
			if (preg_match('#^system/modules/[^/]+#', $target)) {
				$this->modulePath = $target;
				break;
			}
		}
		if (!$this->modulePath) {
			$this->modulePath = 'system/modules/' . preg_replace('#^.*/(.*)$#', '$1', $this->config['name']);
			$this->log('  * <comment>Module path not found, guessing the module path from name: ' . $this->modulePath . '</comment>');
		}

		if (!array_key_exists('replace', $this->config)) {
			$this->config['replace'] = array();
		}

		if (array_key_exists('require', $this->config)) {
			$this->log('  - Remove unneeded dependencies');
			foreach ($this->config['require'] as $package => $version) {
				if (
					$package == 'contao' ||
					$package == 'contao/core' ||
					$package == 'contao-community-alliance/composer' ||
					$package == 'contao-community-alliance/composer-installer' ||
					$package == 'contao-community-alliance/composer-plugin' ||
					$package == 'contao-community-alliance/composer-plugin' ||
					preg_match('~^contao-legacy/~', $package) ||
					in_array($this->getPackageType($package, $version, $this->repositoryDir, $this->config), array('legacy-contao-module', 'contao-module'))
				) {
					$this->config['replace'][$package] = '*';
					$this->dependencies[$package] = $version;
					unset($this->config['require'][$package]);
				}
			}
			if (empty($this->config['require'])) {
				unset($this->config['require']);
			}
		}


		if (file_exists($this->repositoryDir . '/composer.lock')) {
			$lock = json_decode(file_get_contents($this->repositoryDir . '/composer.lock'), true);

			if (isset($lock['packages'])) {
				foreach ($lock['packages'] as $index => $package) {
					if (
						$package['name'] == 'contao/core' ||
						$package['name'] == 'contao-community-alliance/composer' ||
						$package['name'] == 'contao-community-alliance/composer-installer' ||
						$package['name'] == 'contao-community-alliance/composer-plugin' ||
						in_array($package['type'], array('legacy-contao-module', 'contao-module'))
					) {
						$this->config['replace'][$package['name']] = '*';
						unset($lock['packages'][$index]);
					}
				}

				$lock['packages'] = array_values($lock['packages']);
			}

			file_put_contents($this->repositoryDir . '/composer.lock', json_encode($lock));
		}

		file_put_contents($this->repositoryDir . '/composer.json', json_encode($this->config, JSON_PRETTY_PRINT));
	}


	/**
	 * @param $package
	 * @param $version
	 * @param $root
	 * @param array $config
	 * @return bool|string
	 * @throws \RuntimeException
	 */
	protected function getPackageType($package, $version, $root, array $config)
	{
		$type = $this->getPackageTypeFromRepositories($package, $version, $root, $config);

		if($type) {
			return $type;
		}

		$process = new Process('php ' . escapeshellarg($this->composerDir . '/composer.phar') . ' show ' . escapeshellarg($package) . ' ' . escapeshellarg($version));
		$process->setTimeout($this->getProject()->getProperty('timeout'));
		$process->run();
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getErrorOutput());
		}
		$details = $process->getOutput();
		foreach (explode("\n", $details) as $line) {
			$parts = explode(':', $line);
			$parts = array_map('trim', $parts);
			if ($parts[0] == 'type') {
				return $parts[1];
			}
		}
		return 'library';
	}

	protected function getPackageTypeFromRepositories($packageName, $version, $root)
	{
		if (!isset($this->config['repositories']) || empty($this->config['repositories'])) {
			return false;
		}

		$tempHome = tempnam(sys_get_temp_dir(), 'compser_');
		unlink($tempHome);
		mkdir($tempHome);

		$inputOutput	= new NullIO();
		$composerConfig = new Config();
		$composerConfig->merge(array(
			'config' => array(
				'home' => $tempHome
			),
		));

		foreach ($this->config['repositories'] as $repoConfig) {
			if ($repoConfig['type'] == 'vcs') {
				$repository = new VcsRepository($repoConfig, $inputOutput, $composerConfig);
				$packages   = $repository->getPackages();

				foreach($packages as $package) {
					/** @var $package CompletePackage */
					if ($package->getName() == $packageName) {
						return $package->getType();
					}
				}
			}
		}

		return false;
	}


	private function installDependencies()
	{
		$root           = $this->getProject()->getBasedir();
		$writethru      = function($type, $buffer) {
			$this->log($buffer);
		};

		$this->log('Install dependencies');
		$process = new Process('php ' . escapeshellarg($this->composerDir . '/composer.phar') . ' install --no-dev', $this->repositoryDir);
		$process->setTimeout($this->getProject()->getProperty('timeout'));
		$process->run($writethru);
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getErrorOutput());
		}

		$fs = new Filesystem();

		$this->log('Copy files into package');
		$fs->mkdir($this->packageDir . '/' . $this->modulePath . '/config');
		foreach ($this->symlinks as $source => $target) {
			$this->copy($this->repositoryDir . '/' . $source, $this->packageDir . '/' . $target, $fs);
		}
		foreach (array_values($this->runonce) as $index => $file) {
			$fs->copy($this->repositoryDir . '/' . $file, $this->packageDir . '/' . $this->modulePath . '/config/runonce_' . $index . '.php');
		}
		if (count($this->runonce)) {
			$class = 'runonce_' . md5(uniqid('', true));
			file_put_contents(
				$this->packageDir . '/' . $this->modulePath . '/config/runonce.php',
				<<<EOF
<?php

class $class extends System
{
	public function __construct()
	{
		parent::__construct();
	}

	public function run()
	{
		for (\$i=0; file_exists(__DIR__ . '/runonce_' . \$i . '.php'); \$i++) {
			try {
				require_once(__DIR__ . '/runonce_' . \$i . '.php');
			}
			catch (\Exception \$e) {
				// first trigger an error to write this into the log file
				trigger_error(
					\$e->getMessage() . "\n" . \$e->getTraceAsString(),
					E_USER_ERROR
				);
				// now log into the system log
				\$this->log(
					\$e->getMessage() . "\n" . \$e->getTraceAsString(),
					'RunonceExecutor run()',
					'ERROR'
				);
			}
		}
	}
}

\$executor = new $class();
\$executor->run();

EOF
			);
		}
	}

	protected function copy($source, $target, Filesystem $fs)
	{
		if (is_dir($source)) {
			$fs->mkdir($target);
			$iterator = new \FilesystemIterator($source, \FilesystemIterator::CURRENT_AS_PATHNAME);
			foreach ($iterator as $item) {
				$this->copy($item, $target . '/' . basename($item), $fs);
			}
		}
		else {
			$fs->copy($source, $target);
		}
	}

	private function createAutoload()
	{
		$classmapGenerator = new ClassMapGenerator();
		$classmap = array();

		$fs = new Filesystem();

		$fs->mkdir($this->repositoryDir . '/' . $this->modulePath . '/classes');
		if (array_key_exists('autoload', $this->config)) {
			if (array_key_exists('psr-0', $this->config['autoload'])) {
				foreach ($this->config['autoload']['psr-0'] as $sources) {
					foreach ((array) $sources as $source) {
						$classmap = array_merge($classmap, $classmapGenerator->createMap($this->repositoryDir . '/' . $source));
						$this->copy($this->repositoryDir . '/' . $source, $this->packageDir . '/' . $this->modulePath . '/classes/' . $source, $fs);
					}
				}
			}
			if (array_key_exists('psr-4', $this->config['autoload'])) {
				foreach ($this->config['autoload']['psr-4'] as $sources) {
					foreach ((array) $sources as $source) {
						$classmap = array_merge($classmap, $classmapGenerator->createMap($this->repositoryDir . '/' . $source));
						$this->copy($this->repositoryDir . '/' . $source, $this->packageDir . '/' . $this->modulePath . '/classes/' . $source, $fs);
					}
				}
			}
			if (array_key_exists('classmap', $this->config['autoload'])) {
				foreach ($this->config['autoload']['classmap'] as $source) {
					$classmap = array_merge($classmap, $classmapGenerator->createMap($this->repositoryDir . '/' . $source));
					$this->copy($this->repositoryDir . '/' . $source, $this->packageDir . '/' . $this->modulePath . '/classes/' . $source, $fs);
				}
			}
		}
		$this->copy($this->repositoryDir . '/vendor', $this->packageDir . '/' . $this->modulePath . '/classes/vendor', $fs);


		if (file_exists($this->packageDir . '/' . $this->modulePath . '/config/autoload.php')) {
			$autoload = file_get_contents($this->packageDir . '/' . $this->modulePath . '/config/autoload.php');
			$autoload = preg_replace('#\?>\s*$#', '', $autoload);
		}
		else {
			$autoload = <<<EOF
<?php

EOF
			;
		}

		$autoload .= <<<EOF

require_once(TL_ROOT . '/$this->modulePath/classes/vendor/autoload.php');

EOF
		;
		file_put_contents(
			$this->packageDir . '/' . $this->modulePath . '/config/autoload.php',
			$autoload
		);


		if (file_exists($this->packageDir . '/' . $this->modulePath . '/config/config.php')) {
			$this->config = file_get_contents($this->packageDir . '/' . $this->modulePath . '/config/config.php');
			$this->config = preg_replace('#\?>\s*$#', '', $this->config);
		}
		else {
			$this->config = <<<EOF
<?php

EOF
			;
		}

		$classmapClasses = array();
		foreach ($classmap as $className => $path) {
			$classmapClasses[] = $className;
		}
		$classmapClasses = array_map(
			function($className) {
				return var_export($className, true);
			},
			$classmapClasses
		);
		$classmapClasses = implode(',', $classmapClasses);

		$this->config .= <<<EOF


if (version_compare(VERSION, '3', '<')) {
	spl_autoload_unregister('__autoload');
	require_once(TL_ROOT . '/$this->modulePath/classes/vendor/autoload.php');
	spl_autoload_register('__autoload');

	\$classes = array($classmapClasses);
	\$cache = FileCache::getInstance('classes');
	foreach (\$classes as \$class) {
		if (!\$cache->\$class) {
			\$cache->\$class = true;
		}
	}
}

EOF
		;
		file_put_contents(
			$this->packageDir . '/' . $this->modulePath . '/config/config.php',
			$this->config
		);
	}


	private function finish()
	{
		$timeout = $this->getProject()->getProperty('timeout');
		$process = new Process('git describe --all HEAD', $this->repositoryDir);
		$process->setTimeout($timeout);
		$process->run();
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getErrorOutput());
		}
		$describe = trim($process->getOutput());

		$process = new Process('git rev-parse HEAD', $this->repositoryDir);
		$process->setTimeout($timeout);
		$process->run();
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getErrorOutput());
		}
		$commit = trim($process->getOutput());

		$process = new Process('git log -1 --format=format:%cD HEAD', $this->repositoryDir);
		$process->setTimeout($timeout);
		$process->run();
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getErrorOutput());
		}
		$datetime = trim($process->getOutput());

		$this->log('  - Write release file');
		file_put_contents(
			$this->packageDir . '/' . $this->modulePath . '/RELEASE',
			<<<EOF
url: {$this->getProject()->getProperty('')}
head: $describe
commit: $commit
datetime: $datetime
EOF
		);

		if (count($this->dependencies)) {
			$this->log('  - Remember to define the dependencies');
			foreach ($this->dependencies as $package => $version) {
				$this->log('  * ' . $package . ' ' . $version . '', Project::MSG_DEBUG);
			}
		}
	}

} 