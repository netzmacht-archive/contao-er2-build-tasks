<?php

/**
 * @package    cloud-connector
 * @author     David Molineus <david.molineus@netzmacht.de>
 * @copyright  2014 netzmacht creative David Molineus
 * @license    LGPL 3.0
 * @filesource
 *
 */

use Symfony\Component\Process\Process;

class InstallComposerTask extends \Task
{
	/**
	 * @var
	 */
	private $destDir;


	/**
	 * @param mixed $destDir
	 */
	public function setDestDir($destDir)
	{
		$this->destDir = $destDir;
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
		$project   = $this->getProject();
		$timeout   = $project->getProperty('timeout');
		$writethru = function($type, $buffer) {
			echo $buffer;
		};

		if (!file_exists($this->destDir . '/composer.phar')) {
			$process = new Process('curl -sS https://getcomposer.org/installer | php', $this->destDir);
			$process->setTimeout($timeout);
			$process->run($writethru);
			if (!$process->isSuccessful()) {
				throw new \BuildException($process->getErrorOutput());
			}
		}
	}

} 