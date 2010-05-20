<?php
/**
 * Cron Tasks Manager
 * PHP driven script that get's CRON's/tasks from Database specified, and executes them when they should
 * Also supports the following:
 * - Sending of log files if specified to the specified people
 * - Multi-day of the week selection options
 *
 * @author JA Clarke
 * @since 2009/01/02
 *
 * @version $Id$ version 2.2a
 * @modified 2010/12/12 JA Clarke
 * @description Added function to set the run state for status views on UI
 *
 * @version $Id$ version 2.2b
 * @modified 2009/12/23 JA Clarke
 * @description Added support for day-of-week selection
 *
 * @version $Id$ version 2.2c
 * @modified 2010/01/10 JA Clarke
 * @description Added support for multi-day CRON's
 *
 * @version $Id$ version 2.2d
 * @modified 2010/01/12 JA Clarke
 * @description Added log file sending support
 *
 * @version $Id$ version 2.2e
 * @modified 2010/01/21 JA Clarke
 * @description Added support for system reporter
 *
 * @version $Id$ version 3.0a
 * @modified 2010/02/16 JA Clarke
 * @description Added output handler for the logs to "colorize" it
 *
 * @version $Id$ version 3.0b
 * @modified 2010/02/19 JA Clarke
 * @description 
 * - Changed the way that the log fie is retrieved, so that it is working 100% and not 
 *   returning null index notices in the log
 * - Corrected the multi-time functions
 * 
 * @version $Id$ version 3.2a
 * @modified 2010/04/01 JA Clarke
 * @description
 * - Modified it so that ALL connections go out to the external DB
 *
 * @version $Id$ version 4.0a
 * @modified 2010/03/24 JA Clarke
 * @description 
 * - Integrated the script with daemon.cron_manager.php in order to make it a daemon based process as opposed to a CRON based system
 *
 * @versino $Id$ version 5.0a
 * @modified 2010/04/01 JA Clarke
 * @desciption
 * - Changed to use PDO for connections
 * - Changed queries to make use of stored procedures instead
 * - Consolidated a couple of queries (eg. cron list and times)
 * - Added extra functionality, such as :
 *	 (a) Bypassing some redundant functions (they have been commented out)
 *	 (b) General streamlining of the system
 *	 (c) Using locks for run-state checking as opposed to runstate variable
 *
 * @todo
 * - Add bi-weekly support
 * - Add multi-month support
 * - Add better integration for system reporter
 * - Setup replication to local DB for internal tasks ONLY
 *
 */

if( GenConfig::LIVE ) :
	include_once "/ts_systems/config/config.php";
	include_once "/ts_systems/systemreporter/agent/system.reporter.agent.php";
else :
	include_once "/www/bill/systems/config/config.php";
	include_once "/www/bill/systems/systemreporter/agent/system.reporter.agent.php";
endif;

/**
 * General config class
 */
class GenConfig
{
	## Debugging enabled??
	const DEBUG		=	false;
	## CronManager in Live Environment??
	const LIVE			=	false;
	## Location??
	const LOCATION	=	1;
	## API Key for System Reporter
	const API				=	"3fe29834";
}

/**
 * Database configuration class
 */
/* class DBConfig extends GenConfig
{
	## LIVE SERVER SETTINGS
	const DBL_SERVER		=	"78.46.17.13";
	const DBL_DBASE		=	"crondb";
	const DBL_USER			=	"cr0n1fy";
	const DBL_PASS			=	"n0rc";
	const DBL_PORT			=	"3306";
	
	## LIVE SERVER SETTINGS
	const DBD_SERVER		=	"78.46.17.13";
	const DBD_DBASE		=	"crondb";
	const DBD_USER			=	"cr0n1fy";
	const DBD_PASS			=	"n0rc";
	const DBD_PORT			=	"3306";
	
	## DEVELOPMENT SERVER SETTINGS
	# const DBD_SERVER		=	"localhost";
	# const DBD_DBASE		=	"cron";
	# const DBD_USER			=	"root";
	# const DBD_PASS			=	"root";
	# const DBD_PORT			=	"3306";
} */

/**
 * System Class
 */
class System
{
	const SYSTEM_NAME 		= "CronJob Manager";
	const SYSTEM_VERSION	= "5.0a";
	const SYSTEM_AUTHOR		= "John Clarke";
	const SYSTEM_URL			= "http://www.jc-interactive.co.za";
	const D_SYSTEM_PATH		= "/www/bill/systems/";
	const L_SYSTEM_PATH		= "/ts_systems/";
}

/**
 * System tasks class that extends from class System
 */
class SystemTasks extends System
{
	/**
	 * Used to print ther header part of the cron manager's output
	 */
	static public function printStart()
	{
		$display  = "\n%white%==================================================\n";
		$display .= "||\t%lightgreen%" . parent::SYSTEM_NAME . "%white%\tVersion: %green%" . parent::SYSTEM_VERSION . "%white%\t\t||\n";
		$display .= "==================================================\n";
		# $display .= "|\tAuthor: " . parent::SYSTEM_AUTHOR . "\t\t\t |\n";
		# $display .= "|\tVersion: " . parent::SYSTEM_VERSION . "\t\t\t\t |\n";
		# $display .= "|\tURL: " . parent::SYSTEM_URL . "\t |\n";
		# $display .= "==================================================%lightgray%\n";
		$display .= "System Call Time : %green%" . date("Y-M-d H:i:s") . "%lightgray%\n\n";
		
		return $display;
	}
	
	/**
	 * Used to print the footer section for the cron manager's output
	 */
	static public function printEnd()
	{
		$display  = "\n%white%==================================================\n";
		$display .= "System Run complete! :)\n";
		$display .= "System End Time : %green%" . date("Y-M-d H:i:s") . "%white%\n";
		$display .= "==================================================%lightgray%\n\n";
		
		return $display;
	}
}

/**
 * Database tasks class
 *
 */
class DBTasks
{
	## Private DB Link used within the class only
	private $link;
	
	/**
	 * Overriden constructor to initialize the connection to the database
	 * @param $server string The name of the server that DB connection needs to be created to
	 * @param $user string The username that needs to be used to authenticate on the MySQL server
	 * @param $passw string The password to be used for authentication
	 * @param $port integer The port number that the connection needs to be made to
	 * @param $db string The name of the database that the connection is being made to
	 */
	public function __construct($server, $user, $passw, $port, $db)
	{
		if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "%white%Connecting to MySQL Server %lightblue%$server%white%...\n" ); endif;
		// Version 5 Change
		// $this->link = mysql_connect( $server.":".$port, $user, $passw );
		$this -> link = new PDO( $server, $user, $passw );
		if( $this->link ) :
			if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "Connected! %lightgreen%:)%white%\n" ); endif;
			# if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "Selecting DB %lightblue%$db%white%...\n" ); endif;
			# mysql_select_db( $db, $this->link );
			# if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "DB Selected! %lightgreen%:)%lightgray%\n" ); endif;
		else :
			SR_Agent::Log( GenConfig::API, SystemReporter::MSG_ERROR, "Could not connect to database! :(" );
			if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "%lightred%Not Connected, terminating :(%lightgray%\n\n" ); exit(); endif;
		endif;
	}
	
	/**
	 * Function to retrieve the results gained from the passed query as a associative array
	 * @param $q string The query to be executed
	 * @return array
	 */
	public function getResults($q)
	{
		$return = array();
		if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "Incoming Query : \n--------------------\n%lightgreen%" . $q . "%lightgray%\n--------------------\n" ); endif;
		# $result = mysql_query( $q, $this->link );
		$stmt = $this -> link -> prepare( $q );
		$result = $stmt -> execute();
		if( !$result ) :
			OutputHandler::displayOutput( "%red%[ERROR]%lightgray% : Could not execute query on database! :(\nQuery: \n%lightred%$q%lightgray%\n" );
			SR_Agent::Log( GenConfig::API, SystemReporter::MSG_ERROR, "Could not execute query on database! :(\nQuery: \n$q\n" );
		else :
			# if( mysql_num_rows( $result ) > 0 ) :
				# if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "[%white%" . __FUNCTION__ . "%lightgray%] Query results count : %lightblue%" . mysql_num_rows( $result ) . "%lightgray%\n--------------------\n" ); endif;
				# while( $row = mysql_fetch_assoc($result) ) :
					# $return[] = $row;
				# endwhile;
			# endif;
			$return = $stmt -> fetchAll( PDO::FETCH_ASSOC );
		endif;
		return $return;
	}
	
	/**
	 * Function to retrieve the contents of the returning row
	 * @param $q string The query to be executed
	 * @return array
	 */
	public function getRow($q)
	{
		$return = "";
		if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "Incoming Query : \n--------------------\n%lightgreen%" . $q . "%lightgray%\n--------------------\n" ); endif;
		# $result = mysql_query( $q, $this->link );
		$stmt = $this -> link -> prepare( $q );
		$result = $stmt -> execute();
		if( !$result ) :
			OutputHandler::displayOutput( "%red%[ERROR]%lightgray% : Could not execute query on database! :(\nQuery: \n%lightred%$q%lightgray%\n" );
			SR_Agent::Log( GenConfig::API, SystemReporter::MSG_ERROR, "Could not execute query on database! :(\nQuery: \n$q\n" );
		else :
			# if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "[%white%" . __FUNCTION__ . "%lightgray%] Query results count : %lightblue%" . mysql_num_rows( $result ) . "%lightgray%\n--------------------\n" ); endif;
			 #if( mysql_num_rows( $result ) > 0 ) :
				# $line = mysql_fetch_array( $result );
				# $return = $line;
			# endif;
			$return = $stmt -> fetch( PDO::FETCH_ASSOC );
		endif;
		return $return;
	}
	
	/**
	 * Function to retrieve a single entry
	 * @param $q string The query to be executed
	 * @return array
	 */
	public function getEntry($q)
	{
		$return = null;
		if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "Incoming Query : \n--------------------\n%lightgreen%" . $q . "%lightgray%\n--------------------\n" ); endif;
		# $result = mysql_query( $q, $this->link );
		$stmt = $this -> link -> prepare( $q );
		$result = $stmt -> execute();
		if( !$result ) :
			OutputHandler::displayOutput( "%red%[ERROR]%lightgray% : Could not execute query on database! :(\nQuery: \n%lightred%$q%lightgray%\n" );
			SR_Agent::Log( GenConfig::API, SystemReporter::MSG_ERROR, "Could not execute query on database! :(\nQuery: \n$q\n" );
		else :
			# if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "[%white%" . __FUNCTION__ . "%lightgray%] Query results count : %lightblue%" . mysql_num_rows( $result ) . "%lightgray%\n--------------------\n" ); endif;
			# if( mysql_num_rows( $result ) > 0 ) :
				# $row = mysql_fetch_array($result);
				# $return = $row[0];
			# endif;
			$row = $stmt -> fetchColumn();
		endif;
		return $return;
	}
	
	/** 
	 * Function to execute queries on the DB without returning any data
	 * @param $q string The query to be executed
	 * @return integer
	 */
	public function runQuery($q)
	{
		if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "Incoming Query : \n--------------------\n%lightgreen%" . $q . "%lightgray%\n--------------------\n" ); endif;
		# $result = mysql_query( $q, $this->link );
		$stmt = $this -> link -> prepare( $q );
		$result = $stmt -> execute();
		if( !$result ) :
			OutputHandler::displayOutput( "%red%[ERROR]%lightgray% : Could not execute query on database! :(\nQuery: \n%lightred%$q%lightgray%\n" );
			SR_Agent::Log( GenConfig::API, SystemReporter::MSG_ERROR, "Could not execute query on database! :(\nQuery: \n$q\n" );
			return 1;
		else :
			# if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "[%white%" . __FUNCTION__ . "%lightgray%] Query results count : %lightblue%" . mysql_num_rows( $result ) . "%lightgray%\n--------------------\n" ); endif;
			return 0;
		endif;
	}
	
	/**
	 * Destructor function used to close the connection to the database
	 */	 
	public function __destruct()
	{
		if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "%lightgreen%Closing MySQL Connection...\n" ); endif;
		# mysql_close( $this->link );
		$this -> link = null;
		if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "%green%DB Connection Closed! :)%lightgray%\n" ); endif;
	}
}

/**
 * Cron manager class
 */
class CronManager extends SystemTasks
{
	/**
	 * Function used to init the events and get the enabled crons and from there process them
	 * @param $dbh object The object that links to the DB connection
	 */
	static public function DoRun($dbh)
	{
		OutputHandler::displayOutput( parent::printStart() );
		## Get Inactive Crons
		$disabled_crons = self::getCrons( 0, $dbh );
		## Get Active Crons
		$enabled_crons   = self::getCrons( 1, $dbh );
		## Get Running Crons
		$running_crons   = self::runningCrons( $dbh );
		
		OutputHandler::displayOutput( "CronJobs:\n%white%Active Tasks: " . count($enabled_crons) . "; %red%Disabled Tasks: " . count($disabled_crons) . "; %green%Running Tasks: " . $running_crons . "%lightgray%\n" );
		
		if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "Disabled Crons:\n%white%" ); var_dump($disabled_crons); OutputHandler::displayOutput( "%lightgray%\n--------------------\n" ); endif;
		if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "Enabled Crons:\n%white%" ); var_dump($enabled_crons); OutputHandler::displayOutput( "%lightgray%\n--------------------\n" ); endif;
		
		self::checkAndRun( $enabled_crons, $dbh );
		OutputHandler::displayOutput( parent::printEnd() );
	}
	
	/**
	 * Function to check the enabled crons and run the cron if it's scheduled time is the current time
	 * @param $crons array The array of crons for which the run needs to be done
	 * @param $dbh object The object that links to the DB connection
	 */
	static private function checkAndRun($crons, $dbh)
	{
		$run_time = date("Y-M-d H:i");
		## Check if a cron should run now
		foreach( $crons as $cronjob ) :
			# $cron_times = self::getCronJobTime($cronjob["cron_id"], $dbh);
			## Check the exclusions
			if( $cronjob["excl_id"] > 0 && date("Y-m-d") == $cronjob["excl_date"] ) : // self::checkExclusion( $cronjob["cron_id"], $dbh ) ) :
				OutputHandler::displayOutput( "CronJob [%white%" . $cronjob["cron_id"] . " : " . $cronjob["cron_name"] . "%lightgray%] is set for %lightblue%exclusion%lightgray%...\n" );
			else :
				# foreach( $cron_times as $ct ) :
					## Some checks :)
					# self::checkTimes( $ct, strtotime($run_time) );
					self::checkTimes( $cronjob, strtotime($run_time) );
					## Get the scheduled time for the cron in the format : 2009-11-28 11:11
					$scheduled_time = date("Y-M-d H:i", 
						# mktime($ct['cron_hour'], $ct['cron_min'], $ct['cron_sec'], $ct['cron_month'], $ct['cron_day'], $ct['cron_year']));
						mktime($cronjob['cron_hour'], $cronjob['cron_min'], $cronjob['cron_sec'], $cronjob['cron_month'], $cronjob['cron_day'], $cronjob['cron_year']));
					OutputHandler::displayOutput( "CronJob [%white%" . $cronjob["cron_id"] . " : " . $cronjob["cron_name"] . "%lightgray%] is set to run @ %green%" . $scheduled_time . "%lightgray%\n" );
					## Check if the current time matches that of the scheduled time
					 #if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "CronJob Weekday(s) : %green%" . $ct['cron_weekday'] . "%lightgray%\n--------------------\n" ); endif;
					if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "CronJob Weekday(s) : %green%" . $cronjob['cron_weekday'] . "%lightgray%\n--------------------\n" ); endif;
					# if( $scheduled_time == $run_time && in_array(date("N"), explode(",", $ct['cron_weekday']) ) ) :
					if( $scheduled_time == $run_time && in_array(date("N"), explode(",", $cronjob['cron_weekday']) ) ) :
						# Check to see if the cron is already running
						if( self::setRunState($cronjob["cron_id"],1,$dbh) ) : # $cronjob['run_state'] == 0 ) :
							$splitters = array( ">>", ">", "--logging" );
							$script = "";
							$log_file = "";
							foreach( $splitters as $splitter ) :
								if( GenConfig::DEBUG ) : OutputHandler::displayOutput("Split detection : %white%".(strpos($cronjob['cron_task'], $splitter))."%lightgray%\t%lightblue%$splitter%lightgray%\n"); endif;
								if( strpos($cronjob['cron_task'], $splitter) !== false && empty($log_file) ) :
									list($script, $log_file) = explode( $splitter, $cronjob['cron_task'] );
									# if( isset($split[1]) ) : $log_file = $split[1]; endif;
								endif;
							endforeach;
							# $split = explode(">>", $cronjob['cron_task']);
							# list($script, $log_file) = explode(">>", $cronjob['cron_task']);
							# if( isset($split[1]) ) : $log_file = $split[1]; endif;
							# if( empty($log_file) ) : 
							#	$split = explode(">", $cronjob['cron_task']);
							#	if( isset($split[1]) ) : $log_file = $split[1]; endif;
								# list($script, $log_file) = explode(">", $cronjob['cron_task']); 
							# endif;
							# if( empty($log_file) ) : 
							#	$split = explode("--logging", $cronjob['cron_task']);
							#	if( isset($split[1]) ) : $log_file = $split[1]; endif;
								# list($script, $log_file) = explode("--logging", $cronjob['cron_task']); 
							# endif;							
							OutputHandler::displayOutput( "Running CronJob [%white%" . $cronjob["cron_id"] . " : " . $cronjob["cron_name"] . "%lightgray%]...\n--------------------\n" );
							OutputHandler::displayOutput( "Logfile : %white%".(empty($log_file)?"N/A":$log_file)."%lightgray% \n" );
							# self::setRunState($cronjob["cron_id"],1,$dbh);
							$start = time();
							## Run the cron task
							$task_results = shell_exec($cronjob["cron_task"]);
							$stop = time();
							self::setRunState($cronjob["cron_id"],0,$dbh);
							## Insert cron log entry
							$log = self::logCronRun( $cronjob["cron_id"], $cronjob["cron_name"], $scheduled_time, ($stop - $start), $dbh );
							if( $log == 1 ) : OutputHandler::displayOutput( "%red%Log entry failed! :(%lightgray%\n--------------------\n" ); endif;
							## Send mail to relevant person
							if( $cronjob["gets_mailed"] == 1 && !empty($log_file) ) : # !GenConfig::LIVE && 
								# $email = EmailSystem::sendMail( $dbh, $cronjob["cron_id"], trim($log_file), $subject = "[CRON MANAGER] Task Completed - " . $cronjob["cron_name"] );
								$email = EmailSystem::sendMail( $dbh, $cronjob, trim($log_file), $subject = "[CRON MANAGER] Task Completed - " . $cronjob["cron_name"] );								
								OutputHandler::displayOutput( "Mail Messenger Response : %white%" . $email . "%lightgray%\n" );
							endif;
							SR_Agent::Log( GenConfig::API, SystemReporter::MSG_MESSAGE, "CronJob [" . $cronjob["cron_id"] . " : " . $cronjob["cron_name"] . "] Completed! :)\n" . $task_results );
							OutputHandler::displayOutput( "--------------------\nCronJob [%white%" . $cronjob["cron_id"] . " : " . $cronjob["cron_name"] . "%lightgray%] %green%Completed! :)%lightgray%\n" );
							unset($script);
							unset($log_file);
						else :
							OutputHandler::displayOutput( "%lightblue%Cron already running, skipping...%lightgray%\n" );
						endif;
					endif;
				# endforeach;
			endif;
		endforeach;
	}
	
	/**
	 * Function to check whether the current cron via its ID should be excluded from running
	 */
	/* static private function checkExclusion($id, $dbh)
	{
		$return = false;
		$q = "SELECT name, date FROM admin_cron_times_excl AS x " .
				"INNER JOIN admin_crons AS c " .
				  "ON c.excl_id = x.id " .
				"WHERE c.cron_id = $id AND x.status = 1;";
		$excl = $dbh->getRow($q);
		if( is_array( $excl ) ) :
			#if( date("Y-m-d") == $excl[1] ) :
			if( date("Y-m-d") == $excl["date"] ) :
				$return = true;
			endif;
		endif;
		return $return;
	}*/
	
	/**
	 * Function used to update the DB to show the current run status of the cron
	 * Used for the CRON Manager UI for updating status fields
	 * @param $cron array The cron for which the runstate needs to be set
	 * @param $state int If 0, then cron is inactive, if 1 it is active and running
	 * @param $dbh object The database object
	 */
	static private function setRunState($cron, $state = 0, $dbh)
	{
		$return = false;
		$q = 'UPDATE admin_crons SET run_state = ' . $state . ' WHERE cron_id = ' . $cron . ';';
		try
		{
			// Set / Unset a lock
			switch( $state ) :
				case 0	:	$dbh -> runQuery( "SELECT RELEASE_LOCK('cron_".$cron."');" );
								$result = $dbh -> runQuery( $q );
								$return = true;
								break;
				case 1	:	// First check if the lock is set
								$lock = $dbh -> getResults( "SELECT IS_USED_LOCK('cron_".$cron."') as locked;" );
								if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "[%white%DEBUG%lightgray%] : Does lock cron_{$cron} exist? \n" . print_r( $lock, true ) . "\n" ); endif;
								// The lock exists
								if( !empty($lock[0]["locked"]) && !is_null($lock[0]["locked"]) ) :
									$result = 0;
									$return = false;
								else :
									$dbh -> runQuery( "SELECT GET_LOCK('cron_".$cron."', 10);" );
									$result = $dbh -> runQuery( $q );
									$return = true;
								endif;
								break;
			endswitch;
		}
		catch( PDOException $e )
		{
			$result = 1;
			$return = false;
		}
		# Failed
		if( $result == 1 ) :
			if( GenConfig::DEBUG ) : OutputHandler::displayOutput( "%lightred%Could not update the run state of cronjob %red%$cron_id%lightred%! :(%lightgray%\n--------------------\n" ); endif;
			SR_Agent::Log( GenConfig::API, SystemReporter::MSG_ERROR, "Could not update the run state of cronjob $cron_id! :(" );
		endif;
		
		return $return;
	}
	
	/**
	 * Function used to convert crontab based times to real world times
	 *
	 * eg. *&#47;5 = Run every 5 minutes
	 */
	static private function checkTimes( &$cron, $run_time )
	{
		if( $cron['cron_day'] == 0 )   		: $cron['cron_day']   		= date("d"); endif;
		if( $cron['cron_month'] == 0 ) 		: $cron['cron_month'] 		= date("m"); endif;
		if( $cron['cron_year'] == 0 )  		: $cron['cron_year']  		= date("Y"); endif;
		if( $cron['cron_weekday'] == 0 )	: $cron['cron_weekday'] 	= date("N"); endif;
				
		if( strpos($cron['cron_min'], "*/" ) !== false ) :
			$minutes = explode("/", $cron['cron_min']);
			$cron['cron_min'] = (date("i", $run_time) % $minutes[1]) == 0 ? date("i", $run_time) : $minutes[1];
		elseif( $cron['cron_min'] == 0 ) :
			$cron['cron_min'] = date("i", $run_time);
		endif;
		
		if( strpos($cron['cron_hour'], "*/" ) !== false )  : 
			$hours = explode("/", $cron['cron_hour']);
			$cron['cron_hour'] = (date("H", $run_time) % $hours[1]) == 0 ? date("H", $run_time) : $hours[1];
		elseif( $cron['cron_hour'] == 0 ) :
			$cron['cron_hour'] = date("H", $run_time);
		endif;
	}

	/**
	 * Function to retrieve the stored crons according to their status
	 *
	 * Status codes:
	 * 0 - Inactive
	 * 1 - Active
	 */
	static private function getCrons($status, $dbh)
	{
		# $q = "SELECT * FROM admin_crons WHERE status = $status AND location_id = ".GenConfig::LOCATION.";";
		$q = "CALL get_crons_and_times(".GenConfig::LOCATION.", ".$status.");";
		$crons = $dbh->getResults( $q );
		return $crons;
	}
	
	/**
	 * Function to retrieve the number of currently running crons
	 */
	static private function runningCrons($dbh)
	{
		$q = "SELECT COUNT(*) FROM admin_crons WHERE run_state = 1 AND location_id = " . GenConfig::LOCATION . ";";
		$crons = $dbh->getEntry( $q );
		return is_null($crons)||empty($crons)?0:$crons;
	}
	
	/**
	 * Function to record a run to the DB log
	 */
	static private function logCronRun( $id, $name, $time, $exec_time, $dbh )
	{
		$q = "INSERT INTO admin_cron_log (cron_id, cron_name, run_time, exec_time) VALUES ('$id', '$name', NOW(), '$exec_time');";
		$result = $dbh -> runQuery( $q );
		return $result;
	}
	
	/**
	 * Function to get the run times of the specified cron job
	 */
	/* static private function getCronJobTime($cron_id, $dbh)
	{
		$q = "SELECT * FROM admin_cron_times WHERE cron_id = $cron_id AND status = 1;";
		$times = $dbh->getResults( $q );
		return $times;
	} */
}

/**
 * E-Mail System Class
 */
class EmailSystem
{
	/**
	 * Function used to send a mail to whoever is linked to the cron with the attached log file for the cron
	 */
	# static public function sendMail( $dbh, $id, $file, $subject = "[CRON MANAGER] Task Completed" )
	static public function sendMail( $dbh, $cron, $file, $subject = "[CRON MANAGER] Task Completed" )
	{
		require_once (GenConfig::LOCATION == 2 ? System::L_SYSTEM_PATH : System::D_SYSTEM_PATH) . "includes/emailSpool/class.emailSpool.php";
		$email = new EmailSpool();
		$email->fromName			= "TrafficSynergy CRON Manager";
		$email->fromAdd			= "Cron.Manager@TrafficSynergy.com";
		$email->toAddress			= $cron["email_address"]; # self::getToAddress($id, $dbh); # can be , seperated ; seperated or array
		$cc 					 			= self::getCCAddresses($cron["cron_id"], $dbh);
		$bcc 					 		= self::getBCCAddresses($cron["cron_id"], $dbh);
		if(!empty($cc)) : 
			$email->ccAddress	= $cc; # can be , seperated ; seperated or array
		endif; 
		if(!empty($bcc)) : 
			$email->bccAddress	= $bcc; # can be , seperated ; seperated or array
		endif; 
		$email->fileAttach			= array($file); # array of filenames
		# $email->htmlBody 		= file_get_contents($file); #html body - if you want to send an text email set only the textBody
		$email->textBody			= file_get_contents($file); #this can be the alt Body or text only body
		$email->priority				= 4; #1 is low 5 is high default 3
		$email->subject				= $subject;
		$email->program			= "CronManager";
		$email->key					= $cron["cron_id"]; # $id;    # (Optional) A key to identify this email in the context of the program [idnumber/transactionID]
		$email->ref					= "";   # (Optional) Ref to who send the email. Blank for auto email
		if($_id = $email->submitEmail()) : # returns an unique id that is a reference to the inserted email
			SR_Agent::Log( GenConfig::API, SystemReporter::MSG_MESSAGE, "Mail message sent, id #" . $_id );
			return "Message was sent successfully with ID : %lightblue%$_id%white% \n";
		else :
			SR_Agent::Log( GenConfig::API, SystemReporter::MSG_ERROR, "There was an error sending the message :".$email->error."\n" );
			return "There was an error sending the message : %red%".$email->error."%white%\n";			
		endif;
	}

	/**
	 * Function to get the To Address
	 */	
	static private function getToAddress($id, $dbh)
	{
		$q = "SELECT email_address FROM admin_crons_email_notifier WHERE cron_id = $id;";
		$to = $dbh->getEntry( $q );
		return $to;
	}
	
	/**
	 * Function to get the CC Address(es)
	 */
	static private function getCCAddresses($id, $dbh)
	{
		$q = "SELECT cc_addresses FROM admin_crons_email_notifier WHERE cron_id = $id;";
		$cc = $dbh->getEntry( $q );
		return (empty($cc)?"":explode(",",$cc));
	}
	
	/**
	 * Function to get the BCC Address(es)
	 */
	static private function getBCCAddresses($id, $dbh)
	{
		$q = "SELECT bcc_addresses FROM admin_crons_email_notifier WHERE cron_id = $id;";
		$bcc = $dbh->getEntry( $q );
		return (empty($bcc)?"":explode(",",$bcc));
	}
}

## Failsafe
if( !class_exists( "OutputHandler" ) ) :
	class OutputHandler
	{
		static public function displayOutput($output)
		{
			$o = $output;
			$colors = array(
				"%lightgray%" 		=> "\033[0;30m",
				"%darkgrey%"		=> "\033[1;30m",
				"%blue%"				=> "\033[0;34m",
				"%lightblue%"		=> "\033[1;34m",
				"%green%"			=> "\033[0;32m",
				"%lightgreen%"		=> "\033[1;32m",
				"%cyan%"			=> "\033[0;36m",
				"%lightcyan%"		=> "\033[1;36m",
				"%red%"				=> "\033[0;31m",
				"%lightred%"			=> "\033[1;31m",
				"%purple%"			=> "\033[0;35m",
				"%lightpurple%"		=> "\033[1;35m",
				"%brown%"			=> "\033[0;33m",
				"%yellow%"			=> "\033[1;33m",
				"%lightgray%"		=> "\033[0;37m",
				"%white%"			=> "\033[1;37m"
			);
			foreach( $colors as $key => $value ) :
				$o = str_replace( $key, $value, $o );
			endforeach;
			
			echo $o;
		}
	}
endif;

## Get the correct DB config and connection
/*if(GenConfig::LIVE) :
	$dbh = new DBTasks(DBConfig::DBL_SERVER, DBConfig::DBL_USER, DBConfig::DBL_PASS, DBConfig::DBL_PORT, DBConfig::DBL_DBASE);
else :
	$dbh = new DBTasks(DBConfig::DBD_SERVER, DBConfig::DBD_USER, DBConfig::DBD_PASS, DBConfig::DBD_PORT, DBConfig::DBD_DBASE);
endif;*/
if( class_exists( "CronManagerDB" ) ) :
	$dbh = new DBTasks( CronManagerDB::DSN, CronManagerDB::USER, CronManagerDB::PASS, CronManagerDB::DBPORT, CronManagerDB::DBDATABASE );

	## Start the application
	CronManager::DoRun( $dbh );
	SR_Agent::Log( GenConfig::API, SystemReporter::MSG_SUCCESS, "System run completed :)" );
else :
	OutputHandler::displayOutput( "%lightred%FATAL ERROR: Could not load CronManagerDB, quitting...&lightgrey%\n\n" );
endif;
exit(0);
?>
