<?php

/**
 * Libraries
 *
 * The libraries of the server making the formats
 * (cwebp, avifenc, jpegoptim, optipng, oxipng,
 * pngquant): finds them, runs them without shell,
 * with a lower priority, several at once within a
 * number of threads, and reads their versions.
 * ImageEditor makes the WebP and AVIF files without
 * them.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Engines;

use Exception;
use Octopus\Log\Logger;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Libraries
{
	/**
	 * The libraries OIO runs, and no other program.
	 *
	 * @var string[]
	 */
	const LIBRARIES = ['jpegoptim', 'optipng', 'oxipng', 'pngquant', 'cwebp', 'avifenc'];

	/**
	 * VERSIONS_TRANSIENT caches the versions of the
	 * libraries, shown on the settings page.
	 */
	const VERSIONS_TRANSIENT = 'octopus_versions';

	/**
	 * VERSION_OPTIONS is the option printing the version
	 * of the libraries not using --version.
	 */
	const VERSION_OPTIONS = ['cwebp' => '-version'];

	/**
	 * The time a library may take on a file, in
	 * seconds: a few seconds on a big photo, more is a
	 * library stuck. Stopped, the file gets an error.
	 * The octopus_timeout filter sets it.
	 */
	const TIMEOUT = 120;

	/**
	 * The versions of the libraries, by name, with
	 * their path, once read in this request.
	 *
	 * @var array|null
	 */
	private static $versions = null;

	/**
	 * The paths of the programs, by name, once looked
	 * up in this request (see binary()).
	 *
	 * @var array
	 */
	private static $binaries = [];

	/**
	 * The threads the next command of run_all() may
	 * take, see threads().
	 *
	 * @var int
	 */
	private static $grant = 1;

	/**
	 * Executes a command without shell: each argument
	 * reaches the program as given, nothing is
	 * interpreted. Stopped after TIMEOUT, with the code
	 * 124 of the timeout command. Falls back on exec(),
	 * with escaped arguments and without time limit,
	 * where proc_open() is disabled. On Windows, exec()
	 * runs cmd.exe /s /c "command", and escapeshellarg()
	 * quotes each argument for it (and blanks out its
	 * % ! " characters).
	 *
	 * @param string[] $command The program, then its arguments.
	 * @return array The exit code and the output (stdout and stderr).
	 * @since 1.0.0
	 */
	public static function execute($command)
	{
		$runs = [self::start($command)];
		while (!isset($runs[0]['code'])) {
			self::poll($runs);
		}
		return [$runs[0]['code'], $runs[0]['output']];
	}

	/**
	 * Runs jobs at once within a number of threads, e.g.
	 * the WebP and AVIF versions of the files of an
	 * image: a library takes one thread, or a few, and
	 * one after the other most threads of the number
	 * would wait. A job is a generator yielding the
	 * commands it runs in turn, each ['command' =>
	 * string[], 'threads' => int], and getting back
	 * their exit code and output, as execute(): its PHP
	 * runs between them. The jobs start in their order
	 * while threads are free, a job under way first,
	 * and its command takes the threads given by
	 * threads(). A job throwing an exception stops,
	 * logged.
	 *
	 * @param \Generator[] $jobs
	 * @param int $threads
	 * @since 1.0.0
	 */
	public static function run_all($jobs, $threads)
	{
		// The jobs to start or go on with, with the result of their last command.
		$ready = [];
		foreach ($jobs as $job) {
			$ready[] = [$job, null];
		}
		$running = [];
		$used = 0;
		try {
			while ($ready || $running) {
				while ($ready && $used < $threads) {
					list($job, $result) = array_shift($ready);
					// The threads free, shared with the jobs waiting: the last ones get more.
					self::$grant = max(1, intdiv($threads - $used, count($ready) + 1));
					$step = self::step($job, $result);
					if ($step === null) {
						continue;
					}
					$run = self::start($step['command']);
					$run['job'] = $job;
					$run['threads'] = isset($step['threads']) ? max(1, (int)$step['threads']) : 1;
					$running[] = $run;
					$used += $run['threads'];
				}
				self::poll($running);
				// Every 200 ms at most: the request tells it is still at work, its locks refreshed (Lock::beat(), Background::beat()).
				do_action('octopus_working');
				foreach ($running as $key => $run) {
					if (isset($run['code'])) {
						$used -= $run['threads'];
						array_unshift($ready, [$run['job'], [$run['code'], $run['output']]]);
						unset($running[$key]);
					}
				}
			}
		} finally {
			// An error stopped the jobs: their libraries do not run on.
			foreach ($running as $run) {
				if (!isset($run['code'])) {
					proc_terminate($run['process'], 9);
					fclose($run['pipe']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The output pipe of the library, not a file.
					proc_close($run['process']);
				}
			}
			self::$grant = 1;
		}
	}

	/**
	 * Starts a job, or goes on with it with the result
	 * of its last command: its next command, or null
	 * once it is over.
	 *
	 * @param \Generator $job
	 * @param array|null $result
	 * @return array|null
	 * @since 1.0.0
	 */
	private static function step($job, $result)
	{
		try {
			$step = $result === null ? $job->current() : $job->send($result);
		} catch (Exception $e) {
			Logger::error($e->getMessage());
			return null;
		}
		return $job->valid() ? $step : null;
	}

	/**
	 * Starts a command, see execute(): its process, to
	 * poll(), or with its code and output at once
	 * through exec(), or when it cannot run.
	 *
	 * @param string[] $command
	 * @return array
	 * @since 1.0.0
	 */
	private static function start($command)
	{
		$command = array_map('strval', $command);
		if (function_exists('proc_open')) {
			$null = self::is_windows() ? 'NUL' : '/dev/null';
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, Generic.PHP.ForbiddenFunctions.Found -- Running the libraries (cwebp, avifenc...) is what OIO does: each argument apart, without shell.
			$process = @proc_open($command, [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
			if (is_resource($process)) {
				stream_set_blocking($pipes[1], false);
				$timeout = max(1, (int)apply_filters('octopus_timeout', self::TIMEOUT));
				return ['process' => $process, 'pipe' => $pipes[1], 'program' => $command[0], 'timeout' => $timeout, 'end' => microtime(true) + $timeout, 'output' => ''];
			}
		}
		if (function_exists('exec')) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Where proc_open() is disabled, each argument escaped.
			exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $code);
			return ['code' => $code, 'output' => implode("\n", $lines)];
		}
		return ['code' => 127, 'output' => 'cannot run ' . basename($command[0])];
	}

	/**
	 * Reads the output of the processes started, waiting
	 * until one writes, 200 ms at most. A process that
	 * ended, or took longer than TIMEOUT and is stopped,
	 * gets its exit code.
	 *
	 * @param array $runs See start().
	 * @since 1.0.0
	 */
	private static function poll(&$runs)
	{
		$read = [];
		foreach ($runs as $run) {
			if (!isset($run['code'])) {
				$read[] = $run['pipe'];
			}
		}
		if (!$read) {
			return;
		}
		$write = $except = null;
		@stream_select($read, $write, $except, 0, 200000);
		$written = false;
		foreach ($runs as $key => $run) {
			if (isset($run['code'])) {
				continue;
			}
			$chunk = (string)stream_get_contents($run['pipe']);
			$written = $written || $chunk !== '';
			$runs[$key]['output'] .= $chunk;
			$status = proc_get_status($run['process']);
			if (!$status['running']) {
				// Given once only: proc_close() would then return -1.
				$code = $status['exitcode'];
			} elseif (microtime(true) > $run['end']) {
				proc_terminate($run['process'], 9);
				$runs[$key]['output'] .= sprintf("\n%s took longer than %d s, stopped", basename($run['program']), $run['timeout']);
				$code = 124;
			} else {
				continue;
			}
			$runs[$key]['output'] .= (string)stream_get_contents($run['pipe']);
			fclose($run['pipe']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The output pipe of the library, not a file.
			proc_close($run['process']);
			$runs[$key]['code'] = $code;
		}
		if (!$written) {
			// On Windows, stream_select() does not wait on a pipe.
			usleep(10000);
		}
	}

	/**
	 * Returns the absolute path of a program, or null
	 * if it is not installed, once per request. The
	 * octopus_binary_path filter sets it by hand, e.g. for
	 * a library out of the folders looked up (see
	 * find_binary()), as what it finds: an absolute
	 * path, to a program if PHP sees it (open_basedir
	 * may hide it), never a .bat or .cmd script.
	 *
	 * @param string $name
	 * @return string|null
	 * @since 1.0.0
	 */
	public static function binary($name)
	{
		if (!array_key_exists($name, self::$binaries)) {
			$found = self::find_binary($name);
			$path = apply_filters('octopus_binary_path', $found, $name);
			// false turns the library off.
			if ($path !== $found && $path !== false && !self::acceptable($path)) {
				Logger::error(sprintf('octopus_binary_path: %s is not a program OIO runs, %s is turned off.', is_string($path) ? $path : gettype($path), $name));
				$path = false;
			}
			self::$binaries[$name] = is_string($path) && $path !== '' ? $path : null;
		}
		return self::$binaries[$name];
	}

	/**
	 * Looks a program up in the folders of the PATH,
	 * then in the usual folders of the libraries:
	 * PHP-FPM often passes an empty PATH. On Windows,
	 * with the extensions of PATHEXT (cwebp.exe),
	 * except .bat and .cmd: cmd.exe would read the
	 * arguments of these scripts. No shell runs, so
	 * this works where there is none (Windows), but
	 * where open_basedir hides the folders from PHP:
	 * the shell finds the program there, as it is
	 * not bound by it, with the name as its argument.
	 *
	 * @param string $name
	 * @return string|false
	 * @since 1.0.0
	 */
	private static function find_binary($name)
	{
		$folders = explode(PATH_SEPARATOR, (string)getenv('PATH'));
		$extensions = [''];
		if (self::is_windows()) {
			$pathext = getenv('PATHEXT') ?: '.COM;.EXE';
			$extensions = array_diff(array_map('strtolower', explode(';', $pathext)), ['', '.bat', '.cmd']);
		} else {
			$folders = array_merge($folders, ['/usr/bin', '/usr/local/bin', '/bin', '/opt/homebrew/bin', '/opt/local/bin']);
		}
		foreach (array_unique($folders) as $folder) {
			$folder = trim($folder, '"');
			// A relative folder, e.g. ".", would depend on the current folder. Not
			// path_is_absolute(): its realpath() warns of the folders out of open_basedir.
			if (!preg_match('#^([a-zA-Z]:)?[/\\\\]#', $folder)) {
				continue;
			}
			foreach ($extensions as $extension) {
				$path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . $name . $extension;
				if (self::is_program($path)) {
					return $path;
				}
			}
		}
		if (!self::is_windows() && ini_get('open_basedir')) {
			list($code, $output) = self::execute(['/bin/sh', '-c', 'command -v "$1"', 'sh', $name]);
			$path = trim($output);
			return $code === 0 && strpos($path, '/') === 0 ? $path : false;
		}
		return false;
	}

	/**
	 * Determines if a path set by hand can be run, as
	 * the ones found: absolute, a program unless PHP
	 * cannot see it, and on Windows not a .bat or .cmd
	 * script, whose arguments cmd.exe would read.
	 *
	 * @param mixed $path
	 * @return bool
	 * @since 1.0.0
	 */
	private static function acceptable($path)
	{
		if (!is_string($path) || !preg_match('#^([a-zA-Z]:)?[/\\\\]#', $path)) {
			return false;
		}
		if (self::is_windows() && preg_match('/\.(bat|cmd)$/i', $path)) {
			return false;
		}
		return !@file_exists($path) || self::is_program($path);
	}

	/**
	 * Determines if a path is a program PHP can run.
	 * On Windows, is_executable() checks its extension.
	 *
	 * @param string $path
	 * @return bool
	 * @since 1.0.0
	 */
	private static function is_program($path)
	{
		return $path !== '' && @is_file($path) && @is_executable($path);
	}

	/**
	 * Determines if the server runs Windows.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private static function is_windows()
	{
		return PHP_OS_FAMILY === 'Windows';
	}

	/**
	 * Returns the nice command lowering the priority
	 * of the libraries: in background jobs by default,
	 * the requests of the site go first. The octopus_nice
	 * filter sets the level, from 0 (normal) to 19
	 * (lowest), by context (see Optimiser::UPLOAD).
	 * Windows has no nice.
	 *
	 * @param string $context
	 * @return string[]
	 * @since 1.0.0
	 */
	public static function nice($context)
	{
		if (self::is_windows()) {
			return [];
		}
		$default = $context === 'background' ? 19 : 0;
		$level = (int)apply_filters('octopus_nice', $default, $context);
		$nice = $level > 0 ? self::binary('nice') : null;
		return $nice ? [$nice, '-n', (string)min(19, $level)] : [];
	}

	/**
	 * Determines if PHP can run programs: proc_open()
	 * or exec(), see execute(). Shared hosts often
	 * disable both, with disable_functions: the
	 * libraries are then of no use, even installed,
	 * and the overview says so.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public static function can_execute()
	{
		return function_exists('proc_open') || function_exists('exec');
	}


	/**
	 * Checks if a library is installed on the server,
	 * once per request (the status of the images asks
	 * it often). Only the supported libraries can be
	 * run, and none where PHP cannot run programs (see
	 * can_execute()).
	 *
	 * @param string $name
	 * @return bool
	 * @since 1.0.0
	 */
	public static function installed($name)
	{
		if (!in_array($name, self::LIBRARIES, true) || !self::can_execute()) {
			return false;
		}
		return self::binary($name) !== null;
	}

	/**
	 * Returns the version of a library (e.g. 1.5.0),
	 * or an empty string if it is not installed or does
	 * not tell it. The settings page shows it on every
	 * load, and the AVIF options follow it: it is
	 * cached for 12 hours, and read again when the
	 * library moves or its file changes, e.g. upgraded.
	 *
	 * @param string $name
	 * @return string
	 * @since 1.0.0
	 */
	public static function version($name)
	{
		if (!self::installed($name)) {
			return '';
		}
		$path = self::binary($name);
		if (self::$versions === null) {
			$cached = get_transient(self::VERSIONS_TRANSIENT);
			self::$versions = is_array($cached) ? $cached : [];
		}
		// A stat, where open_basedir allows it: 0 otherwise, the path alone tells.
		$modified = (int)@filemtime($path);
		$cached = isset(self::$versions[$name]) ? self::$versions[$name] : [];
		if (!isset($cached['path'], $cached['modified']) || $cached['path'] !== $path || $cached['modified'] !== $modified) {
			$option = array_key_exists($name, self::VERSION_OPTIONS) ? self::VERSION_OPTIONS[$name] : '--version';
			list(, $output) = self::execute([$path, $option]);
			// avifenc tells it on its Version: line, the others on their first line.
			$line = preg_match('/^Version:.*$/mi', $output, $matches) ? $matches[0] : strtok(ltrim($output), "\n");
			$version = preg_match('/\d+(\.\d+)+/', (string)$line, $matches) ? $matches[0] : '';
			self::$versions[$name] = ['path' => $path, 'modified' => $modified, 'version' => $version];
			set_transient(self::VERSIONS_TRANSIENT, self::$versions, 12 * HOUR_IN_SECONDS);
		}
		return self::$versions[$name]['version'];
	}

	/**
	 * Returns the threads the libraries take at most, all
	 * together, by context: 4 on upload, 2 in background
	 * jobs (the requests of the site go first), all of
	 * them in WP-CLI. The octopus_threads filter sets
	 * another number, or "all".
	 *
	 * @param string $context See Optimiser::UPLOAD.
	 * @return int
	 * @since 1.0.0
	 */
	public static function budget($context)
	{
		$defaults = ['upload' => 4, 'background' => 2, 'cli' => 'all'];
		$default = isset($defaults[$context]) ? $defaults[$context] : 4;
		$threads = apply_filters('octopus_threads', $default, $context);
		return $threads === 'all' ? self::cores() : max(1, (int)$threads);
	}

	/**
	 * Returns the threads the next command of run_all()
	 * may take: avifenc and oxipng run on them, the other
	 * libraries on one.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public static function threads()
	{
		return self::$grant;
	}

	/**
	 * Returns the number of processors of the server, 4
	 * if it cannot be read (e.g. /proc out of
	 * open_basedir).
	 *
	 * @return int
	 * @since 1.0.0
	 */
	private static function cores()
	{
		if (self::is_windows()) {
			$cores = (int)getenv('NUMBER_OF_PROCESSORS');
		} elseif (PHP_OS_FAMILY === 'Darwin') {
			list($code, $output) = self::execute(['/usr/sbin/sysctl', '-n', 'hw.ncpu']);
			$cores = $code === 0 ? (int)$output : 0;
		} else {
			$cpuinfo = (string)@file_get_contents('/proc/cpuinfo');
			$cores = preg_match_all('/^processor\s*:/m', $cpuinfo);
		}
		return $cores > 0 ? $cores : 4;
	}

	/**
	 * Forgets the versions of the libraries, read again
	 * next time, e.g. after an upgrade.
	 *
	 * @since 1.0.0
	 */
	public static function forget_versions()
	{
		self::$versions = null;
		delete_transient(self::VERSIONS_TRANSIENT);
	}
}
