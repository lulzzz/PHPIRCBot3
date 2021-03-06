<?php
/*
 * @name		IRCBot
 * @description	A PHP class that can connect to IRC.
 * @author		xxOrpheus
 * @version		3.1
 * RELEASE NOTES:
 *	3.0 - This is currently in beta testing. Don't be alarmed if you encounter a bug, just report it!
 *	3.1 - OMG NO MORE TAB AFTER PHP TAG!!11
 *
 */

session_start();
chdir(dirname(__FILE__));
date_default_timezone_set('America/Vancouver');

class IRCBot {
	protected $IRC_SOCKET;
	protected $IRC_DATA;
	protected $IRC_DATA_ARGS;

	protected $IRC_ARGS = array();

	protected $IRCBOT_MODULES = array();

	protected $EVENT_HANDLERS;

	protected $LOGGING_ENABLED = false;
	protected $LOG = '';

	/* 
	 * @description	The contructor. Can be sent an array, which will fill the IRC arguments.
	 * @param		array $args IRC arguments. Channel, server, port etc all can be set here..
	 *
	 */
	public function IRCBot(array $args = array()) {
		if(count($args) > 0)
			$this->IRC_ARGS = array_merge($args, $this->IRC_ARGS);
		else {
			$default = array('IRC_PORT' => 6667, 'IRC_NICK' => 'PHPIRCBot', 'IRC_USER' => 'PHPIRCBot', 'OWNER' => 'PHPIRCBot');
			$this->IRC_ARGS = array_merge($default, $this->IRC_ARGS);
		}
	}

	/*
	 * @description	All work is done here. All handlers, modules etc will be executed here.
	 *
	 */
	public function start() {
		$this->IRC_SOCKET = @fsockopen($this->getArg('IRC_SERVER'), intval($this->getArg('IRC_PORT')), $SOCK_ERR_NUM, $SOCK_ERR_STR);
		if(!$this->IRC_SOCKET)
			Throw new Exception($SOCK_ERR_STR);
		$this->sendCommand('USER '.$this->getArg('IRC_USER').' 0 * ' . $this->getArg('IRC_USER'));
		$this->sendCommand('NICK '.$this->getArg('IRC_NICK'));
		while($this->IRC_SOCKET) {
			$this->IRC_DATA = fgets($this->IRC_SOCKET, 1024);
			$this->IRC_DATA_ARGS = $this->parseMessage($this->IRC_DATA);
			if($this->LOGGING_ENABLED) ob_start();
			$this->handleCommand($this->IRC_DATA_ARGS['command']);
			if($this->LOGGING_ENABLED) {
				$data = ob_get_clean();
				$this->LOG .= $data;
				echo $data;
			}
			if(substr($this->IRC_DATA_ARGS['trail'], 0, 1) == '!') {
				ob_start();
				$this->CURRENT_COMMAND = preg_replace('/(\s*)([^\s]*)(.*)/', '$2', $this->IRC_DATA_ARGS['trail']);
				$this->handleModule(substr($this->CURRENT_COMMAND, 1));
				$data = ob_get_clean();
				if($this->LOGGING_ENABLED)
					$this->LOG .= $data;
				if(!empty($data)) {
					$data = explode("\n", $data);
					foreach($data as $line) {
						if(strlen(trim($line)) == 0)
							continue;
						$line = str_replace("	", '  ', $line);
						$this->sendMessage($line);
					}
				}
			}
			if($this->LOGGING_ENABLED)
				$this->pushLog();
		}
	}

	/* 
	 * @description	Returns the result set. Mainly used by modules.
	 * @return		array
	 *
	 */

	public function getResultSet() {
		return $this->IRC_DATA_ARGS;
	}

	/*
	 * @description	Sends a command to the active server.
	 * @param		string $cmd The command to be sent.
	 *
	 */
	public function sendCommand($cmd) {
		if(!$this->IRC_SOCKET)
			Throw new Exception('No connection opened');
		fwrite($this->IRC_SOCKET, $cmd . "\r\n");
		fflush($this->IRC_SOCKET);

		return true;
	}
	public function sendMessage($msg) { $this->sendCommand('PRIVMSG ' . $this->IRC_DATA_ARGS['args'] . ' :' . $msg); }

	/*
	 * @description	Parse data sent by server.
	 * @param		string $str The data received by server.
	 * @return		array
	 *
	 */
	public function parseMessage($str) {
		preg_match('/^(?:[:@]([^\\s]+) )?([^\\s]+)(?: ((?:[^:\\s][^\\s]* ?)*))?(?: ?:(.*))?$/', $str, $args);
		if(isset($args[1]))
			if(strrpos($args[1], '!'))
				$username = substr($args[1], 0, strrpos($args[1], '!'));
			else
				$username = $args[1];
		else
			$username = '';
			
		return array('username' => $username, 'command' => isset($args[2]) ? $args[2] : '', 'trail' => isset($args[4]) ? trim($args[4]) : '', 'args' => isset($args[3]) ? $args[3] : '');
	}

	/*
	 * @description	Load modules in specified directory.
	 * @param		string $dir The directory to be searched for modules.
	 *
	 */
	public function loadModules($dir = null) {
		if(is_null($dir))
			$dir = dirname(__FILE__) . '\\modules';
		else
			if(!is_dir($dir))
				Throw new Exception($dir . ' is not a valid directory');
		$modules = glob($dir . '\\*.php');
		foreach($modules as $module) {
			$module_name = basename($module, '.php');
			if(!class_exists($module_name))
				require_once($module);
			else
				echo 'Module "'.$module_name.'"" already loaded!'.PHP_EOL;
			if(class_exists($module_name)) {
				$this->IRCBOT_MODULES[$module_name] = new $module_name($this);
			} else
				echo 'Module "'.$dir.'\\'.$module_name.'.php" could not be loaded! Class name must match that of the filename!' . PHP_EOL;
		}
	}

	/*
	 * @description	Get active modules.
	 * @return		Array of objects.
	 *
	 */
	public function getModules() {
		return $this->IRCBOT_MODULES;
	}

	/*
	 * @description	Handle command sent by server.
	 * @param		string $command The command
	 *
	 */
	public function handleCommand($command) {
		if($command == 'PING')
			$this->sendCommand('PONG ' . substr($this->IRC_DATA, 5));
		if(!isset($this->EVENT_HANDLERS[$command]) || $command == 'PING') {
			$ds = $this->getResultSet();
			foreach($ds as $s)
				if(empty($s))
					$empty = true;
				else
					$empty = false;
			if(isset($empty) && $empty === false)
				echo '['.date('h:i').'] <'.trim($ds['username']).':'.$ds['command'].'> ' . trim($ds['trail']) . PHP_EOL;
			return false;
		}
		foreach($this->EVENT_HANDLERS[$command] as $func)
			$func($this);
		foreach($this->IRCBOT_MODULES as $mod) {
			if(method_exists($mod, 'EVENT_' . $command)) {
				$command = 'EVENT_'.$command;
				$mod->$command($this, $this->getResultSet());
			}
		}
		return true;
	}

	/*
	 * @description Get the current command. (!thesethings)
	 * @return		string Current command
	 *
	 */
	public function getCurrentCommand() {
		return $this->CURRENT_COMMAND;
	}

	/*
	 * @description	Handle a module.
	 * @param		string $mod The module.
	 *
	 */
	public function handleModule($mod) {
		if(isset($this->IRCBOT_MODULES[$mod])) {
			$args = $this->IRC_DATA_ARGS['trail'];
			$args = substr($args, strlen($this->CURRENT_COMMAND) + 1);
			$mod = $this->IRCBOT_MODULES[$mod];
			$methods = array('pre_execute', 'execute', 'post_execute'); // the order of this array is very important!
			foreach($methods as $method)
				if(method_exists($mod, $method))
					$mod->$method($this, $args);
			return true;
		} else
			return false;
	}

	/*
	 * @description	Add a handler for a command.
	 * @param		string $command The command to handle.
	 * @param		closure $func The callback for the command.
	 * @return		int The ID of the handle.
	 *
	 */
	public function addHandler($command, closure $func) {
		if(!isset($this->EVENT_HANDLERS[$command]))
			$this->EVENT_HANDLERS[$command] = array();
		$this->EVENT_HANDLERS[$command][] = $func;

		return key(end($this->EVENT_HANDLERS));
	}

	/*
	 * @description	Remove a handler.
	 * @param		string $command The command
	 * @param		int $id The index of the handle. It is returned when you add a new handler.
	 *
	 */
	public function removeHandler($command, $id) {
		if(!isset($this->EVENT_HANDLERS[$command][$id]))
			return false;
		unset($this->EVENT_HANDLERS[$command][$id]);

		return true;
	}

	/*
	 * @description	Set an argument for the IRC Bot.
	 * @param		string $arg The argument we're setting
	 * @param		string $value and the value it's set to.
	 *
	 */
	public function setArg($arg, $value) {
		$this->IRC_ARGS[$arg] = $value;
		return $this->IRC_ARGS[$arg];
	}

	/*
	 * @description	Get an argument value.
	 * @return		mixed
	 *
	 */
	public function getArg($arg) {
		if(isset($this->IRC_ARGS[$arg]))
			return $this->IRC_ARGS[$arg];
		return null;
	}

	/*
	 * @description	Toggle logging. I recommend leaving it off.
	 *
	 */
	public function toggleLogging() {
		$this->LOGGING_ENABLED = !$this->LOGGING_ENABLED;
	}

	/*
	 * @description	Push current log data to log.
	 *
	 */
	public function pushLog() {
		$current_log = 'logs/'.date('d-M-Y').'--log.txt';
		if(!is_file($current_log))
			file_put_contents($current_log, '');
		$log = file_get_contents($current_log);
		file_put_contents($current_log, $log . $this->LOG);
		$this->LOG = '';
	}

	/*
	 * @description	Behaves like file_get_contents(...)
	 * @return		string The contents it retrieves.
	 *
	 */
	public function file_get_contents_2($url) {
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HEADER => true,
			CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; rv:16.0) Gecko/20100101 Firefox/16.0',
			CURLOPT_REFERER => $url
		));
		$res = curl_exec($ch);

		return $res;
	}
}
?>