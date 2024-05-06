<?php

/*
  FusionPBX
  Version: MPL 1.1

  The contents of this file are subject to the Mozilla Public License Version
  1.1 (the "License"); you may not use this file except in compliance with
  the License. You may obtain a copy of the License at
  http://www.mozilla.org/MPL/

  Software distributed under the License is distributed on an "AS IS" basis,
  WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
  for the specific language governing rights and limitations under the
  License.

  The Original Code is FusionPBX

  The Initial Developer of the Original Code is
  Mark J Crane <markjcrane@fusionpbx.com>
  Portions created by the Initial Developer are Copyright (C) 2008-2018
  the Initial Developer. All Rights Reserved.

  Contributor(s):
  Mark J Crane <markjcrane@fusionpbx.com>
  Tim Fry <tim.fry@hotmail.com>
 */

/**
 * Description of maintenance_service
 *
 * @author Tim Fry <tim.fry@hotmail.com>
 */
class maintenance_service extends service {

	const LOG_OK = 'ok';
	const LOG_ERROR = 'error';

	/**
	 * Database object
	 * @var database
	 */
	private $database;

	/**
	 * Settings object
	 * @var settings
	 */
	private $settings;

	/**
	 * List of purges to perform
	 * @var array
	 */
	private $maintenance_apps;

	/**
	 * Execution time for the maintenance to work at
	 * @var string|null
	 */
	private $execute_time;

	/**
	 * Maintenance work will only be performed if this is set to true
	 * @var bool
	 */
	private $enabled;

	/**
	 * Database object
	 * @var database
	 */
	private static $db = null;

	/**
	 * Array of logs to write to database_maintenance table
	 * @var array
	 */
	private static $logs = null;

	/**
	 * Integer to track the number of seconds to sleep between time match checking
	 * @var int
	 */
	private $check_interval;

	/**
	 * Tracks if the immediate flag (-i or --immediate) was used on startup
	 * @var bool True if the immediate flag was passed in on initial launch or false. Default is false.
	 */
	private static $execute_on_startup = false;

	/**
	 * Can extend the base cli options
	 * @param array $help_options
	 */
	#[\Override]
	protected static function set_cli_options() {
		//add a new command line option
		self::append_cli_option(cli_option::new()
			->short_option('i')
			->long_option('immediate')
			->description('Launch maintenance services immediately on startup')
			->functions(['set_immediate'])
		);
	}

	/**
	 * Show the version on the console when the -r or --version is used
	 * @return void
	 */
	#[\Override]
	protected static function display_version(): void {
		echo "Version " . self::VERSION . "\n";
	}

	protected static function set_immediate() {
		self::$execute_on_startup = true;
	}

	/**
	 * This is called whenever either a reload signal is received to the running instance or
	 * when the cli option -r or --reload is given to the service.
	 * This is also called when the maintainer_service is first created to connect to the database
	 * and reload the settings from the global default settings
	 * @return void
	 */
	#[\Override]
	protected function reload_settings(): void {
		//reload the config file
		self::$config->read();

		//re-connect the database just-in-case the config settings have changed
		$this->database->connect();

		//reload settings
		$this->settings->reload();

		//check if we are enabled to work or not
		$this->enabled = $this->settings->get('maintenance', 'enabled', 'false') === 'true' ? true : false;

		//returns an array of maintenance applications
		$this->maintenance_apps = $this->settings->get('maintenance', 'application', []);

		//time of day to execute in 24-hour time format
		$this->execute_time = $this->settings->get('maintenance', 'time_of_day', null);

		//sleep seconds between tests for matching the current time to the execute time
		$this->check_interval = intval($this->settings->get('maintenance', 'check_interval', 33));

		//check for starting service exactly on the time needed
		if (date('H:i') == $this->execute_time || self::$execute_on_startup) {
			$this->run_maintenance();
		}
	}

	/**
	 * Non-zero values indicate that the service failed to start
	 * @return void
	 */
	#[\Override]
	public function run(): int {

		//log the startup
		self::log('Starting up...', LOG_INFO);

		//load functions
		require_once dirname(__DIR__, 4) . '/resources/functions.php';

		//set the database to use the config file
		$this->database = new database(['config' => static::$config]);

		//save the database for logging but object and static properties can't the same name
		self::$db = $this->database;

		//initialize the logs for the workers
		self::$logs = [];

		//set the settings to use the connected database
		$this->settings = new settings(['database' => $this->database]);

		//reload the default settings
		$this->reload_settings();

		//get the default setting for this service
		if (!$this->enabled || empty($this->execute_time)) {
			$this->log('Service not enabled or time_of_day not set', LOG_ERR);
			$this->running = false;
			return 1;
		}

		//main loop
		while($this->running) {
			//wait until the time matches requested time of day
			do {
				$now = date('H:i');
				// check once a minute
				sleep($this->check_interval);
			} while ($this->execute_time <> $now && $this->running);
			$this->run_maintenance();
		}
		return 0;
	}

	/**
	 * Executes the maintenance for both database and filesystem objects using their respective helper methods
	 */
	protected function run_maintenance() {
		//execute all database maintainer classes
		$this->database_maintenance();
		//execute all filesystem maintainer classes
		$this->filesystem_maintenance();
		//write only once to database maintainance logs and flush the array
		self::log_flush();
	}

	/**
	 * Performs all database maintenance by calling each object that implements the database_maintenance interface
	 * @return void
	 */
	protected function database_maintenance(): void {
		foreach ($this->maintenance_apps as $class_name) {
			$worker = null;
			if (class_exists($class_name)) {
				$worker = new $class_name();
			}
			if ($worker instanceof database_maintenance) {
				$worker->database_maintenance($this->database, $this->settings);
			}
		}
	}

	/**
	 * Performs all file system maintenance by calling each object that implements the filesystem_maintenance interface
	 * @return void
	 */
	protected function filesystem_maintenance(): void {
		foreach ($this->maintenance_apps as $class_name) {
			$worker = null;
			if (class_exists($class_name)) {
				$worker = new $class_name();
			}
			if ($worker instanceof filesystem_maintenance) {
				$worker->filesystem_maintenance($this->database, $this->settings);
			}
		}
	}

	/**
	 * Write to the database
	 */
	private static function log_flush() {
		$array['maintenance_logs'] = self::$logs;
		self::$db->save($array, false);
		self::$logs = [];
	}

	////////////////////////////////////////////////////
	// Common functions used with maintainer services //
	////////////////////////////////////////////////////

	/**
	 * Finds the UUID of a maintenance setting searching in the default settings, domain settings, and user settings tables.
	 * This is primarily used for the dashboard display as there was a need to detect a setting even if it was disabled.
	 * <p>NOTE:<br>
	 * This will not deal with an array of returned values (such as maintenance_application) appropriately at this time as it has a limited scope of detecting a
	 * string value that is unique with the category and subcategory combination across the tables.</p>
	 * @param database $database Already connected database object
	 * @param string $category Main category
	 * @param string $subcategory Subcategory or name of the setting
	 * @param bool $status Used for internal use but could be used to find a setting that is currently disabled
	 * @return array Two-dimensional array assigned but using key/value pairs. The keys are:<br>
	 *   <ul>uuid: Primary UUID that would be chosen by the settings object
	 *   <ul>uuids: Array of all matching category and subcategory strings
	 *   <ul>table: Table name that the primary UUID was found
	 *   <ul>status: bool true/false
	 */
	public static function find_uuid(database $database, string $category, string $subcategory, bool $status = true): array {
		//first look for false setting then override with enabled setting
		if ($status) {
			$uuids = self::find_uuid($database, $category, $subcategory, false);
		} else {
			//set defaults to not found
			$uuids = [];
			$uuids['uuid'] = '';
			$uuids['uuids'] = [];
			$uuids['table'] = '';
			$uuids['status'] = false;
		}
		$status_string = ($status) ? 'true' : 'false';
		//
		// Get the settings for false first then override the 'false' setting with the setting that is set to 'true'
		//
		//get global setting
		$result = self::get_uuid($database, 'default', $category, $subcategory, $status_string);
		if (!empty($result)) {
			$uuids['uuid'] = $result[0];
			$uuids['count'] = count($result);
			if (count($result) > 1) {
				$uuids['uuids'] = $result;
			} else {
				$uuids['uuids'] = [];
			}
			$uuids['table'] = 'default';
			$uuids['status'] = $status;
		}
		//override default with domain setting
		$result = self::get_uuid($database, 'domain', $category, $subcategory, $status_string);
		if (!empty($result)) {
			if ($uuids['count'] > 0) {
				$uuids['count'] += count($result);
				if (count($uuids['uuids']) > 0) {
					array_merge($uuids['uuids'], $result);
				} else {
					$ids[] = $uuids['uuid'];
					$uuids['uuids'] = array_merge($result, $ids);
				}
			} else {
				$uuids['count'] = count($result);
				if (count($result) > 1) {
					$uuids['uuids'] = $result;
				} else {
					$uuids['uuids'] = [];
				}
			}
			$uuids['uuid'] = $result[0];
			$uuids['table'] = 'domain';
			$uuids['status'] = $status;
		}
		//override domain with user setting
		$result = self::get_uuid($database, 'user', $category, $subcategory, $status_string);
		if (!empty($result)) {
			$uuids['uuid'] = $result[0];
			$uuids['count'] = count($result);
			if (count($result) > 1) {
				$uuids['uuids'] = $result;
			} else {
				$uuids['uuids'] = [];
			}
			$uuids['table'] = 'user';
			$uuids['status'] = $status;
		}
		return $uuids;
	}

	/**
	 * Called by the find_uuid function to actually search database using prepared data structures
	 */
	private static function get_uuid(database $database, string $table, string $category, string $subcategory, string $status): array {
		$uuid = [];
		$sql = "select {$table}_setting_uuid from v_{$table}_settings s";
		$sql .= " where s.{$table}_setting_category = :category";
		$sql .= " and s.{$table}_setting_subcategory = :subcategory";
		$sql .= " and s.{$table}_setting_enabled = '$status'";

		//set search params
		$params = [];
		$params['category'] = $category;
		$params['subcategory'] = $subcategory;
		if ($table === 'domain' && !empty($_SESSION['domain_uuid']) && is_uuid($_SESSION['domain_uuid'])) {
			$sql .= " and s.domain_uuid = :domain_uuid";
			$params['domain_uuid'] = $_SESSION['domain_uuid'];
		}
		if ($table === 'user' && !empty($_SESSION['user_uuid']) && is_uuid($_SESSION['user_uuid'])) {
			$sql .= " and s.user_uuid = :user_uuid";
			$params['user_uuid'] = $_SESSION['user_uuid'];
		}
		$result = $database->select($sql, $params);
		if (!empty($result)) {
			if (is_array($result)) {
				$uuids = array_map(function ($value) use ($table) {
					if (is_array($value)) {
						return $value["{$table}_setting_uuid"];
					} else {
						return $value;
					}
				}, $result);
				$uuid = $uuids;
			} else {
				$uuid[] = $result;
			}
		}
		return $uuid;
	}

	/**
	 * Saves the logs in an array in order to write them all at once. This is to remove the number of times the database will try to
	 * be written to during the many worker processes to improve performance similar to an atomic commit.
	 * @param database_maintenance|filesystem_maintenance $worker
	 * @param string $message
	 */
	public static function log_write($worker, string $message, string $status = self::LOG_OK) {
		//protect against hijacking the log writer from a non maintenance worker
		if (self::$logs !== null && ($worker instanceof database_maintenance || $worker instanceof filesystem_maintenance)) {
			//automatic retrieval of class name based on PHP version
			if (version_compare(PHP_VERSION, "8.0.0", "<")) {
				$backtrace = debug_backtrace();
				$classname = !empty($backtrace[1]['class']) ? $backtrace[1]['class'] : 'object';
			} else {
				// PHP 8.0 and higher can extract the class from a dynamic name
				$classname = $worker::class;
			}
			$row_index = count(self::$logs);
			self::$logs[$row_index]['maintenance_log_uuid']   = uuid();
			self::$logs[$row_index]['maintenance_log_application'] = $classname;
			self::$logs[$row_index]['maintenance_log_epoch'] = time();
			self::$logs[$row_index]['maintenance_log_message'] = $message;
			self::$logs[$row_index]['maintenance_log_status'] = $status;
		}
	}

	/**
	 * Returns the number of seconds passed since the file was created
	 * @param string $file Full path and file name
	 * @return int number of seconds since the file was created
	 * @depends filectime
	 */
	public static function seconds_since_created(string $file): int {
		//check the file date and time
		return floor(time() - filectime($file));
	}

	/**
	 * Returns the number of minutes passed since the file was created
	 * @param string $file Full path and file name
	 * @return int number of minutes since the file was created
	 * @depends seconds_since_created
	 */
	public static function minutes_since_create(string $file): int {
		return floor(self::seconds_since_created($file) / 60);
	}

	/**
	 * Returns the number of hours passed since the file was created
	 * @param string $file Full path and file name
	 * @return int number of hours since the file was created
	 * @depends minutes_since_create
	 */
	public static function hours_since_created(string $file): int {
		return floor(self::minutes_since_create($file) / 60);
	}

	/**
	 * Returns the number of days passed since the file was created
	 * @param string $file Full path and file name
	 * @return int number of days since the file was created
	 * @depends hours_since_created
	 */
	public static function days_since_created(string $file): int {
		return floor(self::hours_since_created($file) / 24);
	}

	/**
	 * Returns the number of months passed since the file was created. Based on a month having 30 days.
	 * @param string $file Full path and file name
	 * @return int number of months since the file was created
	 * @depends days_since_created
	 */
	public static function months_since_created(string $file): int {
		return floor(self::days_since_created($file) / 30);
	}

	/**
	 * Returns the number of weeks passed since the file was created
	 * @param string $file Full path and file name
	 * @return int number of weeks since the file was created
	 * @depends days_since_created
	 */
	public static function weeks_since_created(string $file): int {
		return floor(self::days_since_created($file) / 7);
	}

	/**
	 * Returns the number of years passed since the file was created
	 * @param string $file Full path and file name
	 * @return int number of years since the file was created
	 * @depends weeks_since_created
	 */
	public static function years_since_created(string $file): int {
		return floor(self::weeks_since_created($file) / 52);
	}

}