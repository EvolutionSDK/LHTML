<?php

namespace Bundles\LHTML;
use Exception;
use e;

class Scope {
	
	public $owner;
	
	private $source_as = false;
	private $source_data = false;
	private $source_pointer = false;
	private $source_count = false;
	private $deferred_sources = array();
	
	public $timers = array();

	public static function hookExists($name) {
		$hooks = e::configure('lhtml')->hook;
		return isset($hooks[$name]);
	}

	public static function getHook($name) {
		$hooks = e::configure('lhtml')->hook;
		if(isset($hooks[$name])) return $hooks[$name];
		return false;
	}

	public function sourceData() {
		return $this->source_data;
	}

	public function addDeferredSource($name, $value) {
		$this->deferred_sources[$name] = $value;
	}

	public function absorb(Scope $scope) {
		foreach($scope->sourceData() as $var => $value)
			$this->source_data[$var] = $value;
	}
	
	public function __construct($owner = false) {
		$this->timers['scope->map'] = 0;
		$this->timers['scope->get'] = 0;
		
		/**
		 * Set the parent scope
		 */
		$this->owner = $owner;
		
		/**
		 * Prepare URL Hook
		 */
		$url = explode('/', $_SERVER['REQUEST_URI']);
		$url = array_filter($url, function($val) {
			if(strlen($val) > 0 || is_array($val) || is_object($val))
				return true;
		});
		$url['last'] = end($url);
		$url['first'] = reset($url);
		$url['current'] = $_SERVER['REQUEST_URI'];
		$url['referer'] = $_SERVER['HTTP_REFERER'];

		/**
		 * Bind URL hook
		 */
		e::configure('lhtml')->activeAddKey('hook', ':url', &$url);
	}
	
	public function get($var_map) {
		$source = false;
		$tt = microtime(true);
		$deferred = false;

		// Check for deferred sources
		if(isset($this->deferred_sources[$var_map])) {
			$var_map = $this->deferred_sources[$var_map];
			$deferred = true;
		}
		
		// strip special char for embedded JS vars
		if(is_string($var_map) AND strpos($var_map, '%') === 0) $var_map = substr($var_map, 1);
		
		$allmap = is_string($var_map) ? $this->parse($var_map) : $var_map;
		$filters = $allmap['filters'];
		$map = $allmap['vars'];

		// Allmap calculates deferred variables (don't know why)
		if($deferred) {
			$source = implode('.', $map);

			/**
			 * Perform Filters
			 */
			if(is_array($filters)) foreach($filters as $filter) {
				if(!is_array($filter)) $source = e::filters($filter, $source);
				else $source = e::filters($filter['func'], $source, $filter['args']);
			}

			return $source;
		}
		
		$flag_first = false;

		if(is_array($map[0]) && isset($map[0]['func']) && strpos($map[0]['func'],':')===0 && self::hookExists($map[0]['func'])) {

			$hook = self::getHook($map[0]['func']);

			if(is_callable($hook)) {
				$source = call_user_func_array($hook, $map[0]['args']);
				$flag_first=1;
			}
			else {
				$func = $map[0]['func'];
				throw new Exception("LHTML hook `$func` is not callable");
			}

		}

		else if(is_string($map[0]) && strpos($map[0],':')===0 && self::hookExists($map[0])) {

			$hook = self::getHook($map[0]);

			if(is_callable($hook)) {
				$source = $hook();
				$flag_first=1;
			}
			else {
				$source = $hook;
				$flag_first=1;
			}
			
		}
		
		if(!$flag_first) {

			/**
			 * Check if traversable
			 */
			$traversable = isset($this->source_data[$map[0]]) && $this->source_data[$map[0]] instanceof \Traversable;

			/**
			 * Return literal string
			 */
			if(is_string($map[0]) && strpos($map[0],"'") === 0) return trim($map[0],"'");

			/**
			 * Return literal number
			 */
			else if(is_string($map[0]) && is_numeric($map[0])) return $map[0];
			
			/**
			 * Pass on traversable object (i.e. allow loopable source when not in a loop)
			 * @author Nate Ferrero
			 */
			else if($this->source_pointer === false && is_string($map[0]) && $traversable) {
				$source = $this->source_data[$map[0]];
				$flag_first = 1;
			}

			/**
			 * Return Traversable Object
			 */
			else if($this->source_pointer !== false && is_string($map[0]) && $traversable) {
				$i=0; foreach($this->source_data[$map[0]] as $source) {
					if($i === $this->source_pointer) break;
					unset($source);
					$i++;
				}
				
				if(isset($source)) $flag_first = 1;
			}
			
			/**
			 * Return Array
			 */
			else if($this->source_pointer !== false && is_string($map[0]) && isset($this->source_data[$map[0]]) && is_array($this->source_data[$map[0]])) {
				$i=0; foreach($this->source_data[$map[0]] as $source) {
					if($i === $this->source_pointer) break;
					unset($source);
					$i++;
				}
				
				if(isset($source)) $flag_first = 1;
			}
			
			/**
			 * Return Object
			 */
			else if(is_string($map[0]) && isset($this->source_data[$map[0]]) && !($this->source_data[$map[0]] instanceof \Traversable)) {
				$tmp = $this->source_data[$map[0]];
				
				if(is_array($source) && $this->source_pointer !== false) {
					$i=0; foreach($tmp as $source) {
						if($i === $this->source_pointer) break;
						unset($source);
						$i++;
					}
				} else $source = $tmp;
				
				if(isset($source)) $flag_first = 1;
			}
			
			else if(is_string($map[0]) && !isset($this->source_data[$map[0]])) {
				if(is_object($this->owner)) {
					$parent = $this->owner->parent();
					if(is_object($parent)) {
						$data = $parent->_data();
						if($data instanceof Scope)
							return $data->get($var_map);
					}
				}
			}
			//else throw new \Exception("IXML Scope no function was called when calling {$var_map}");
		}
		foreach($map as $i=>$var) {
			if($map[0] == ':get' && $map[1] == 'test') echo(' | i:'.$i.' | flag: '.$flag_first);
			if($flag_first && $i < $flag_first) continue;
			if(!isset($source) || (!$source && !is_array($source))) break;
			
			if(is_array($var) && is_object($source)) {
				if(method_exists($source, $var['func'])) $source = call_user_func_array(array($source, $var['func']), $var['args']);
				else if(method_exists($source, '__call')) $source = call_user_func_array(array($source, $var['func']), $var['args']);
 			} 

			else if(is_object($source)) {
				try {
					if(isset($source->$var)) $source = $source->$var;
					else if(!is_null($var) && method_exists($source, $var)) $source = $source->$var();
					else if(!is_null($var) && method_exists($source, '__call')) $source = $source->$var();
				}
				catch(Exception $e) {
					if(!isset($e->severity) || $e->severity < 3)
						throw $e;
					else e\Trace_Exception($e);
				}
			}
			
			else if(is_array($source)) {
				if($this->source_pointer !== false && $map[0] == $this->source_as && !$iterated) {
					$iterated = true;
					$source = $source[$map[1]];
				}
				else if(isset($source[$var]))
					$source = $source[$var];
				else {
					$source = false;
				}
			}
			else $source = false;
		}
		
		/**
		 * Perform Filters
		 */
		if(is_array($filters)) foreach($filters as $filter) {
			if(!is_array($filter)) $source = e::filters($filter, $source);
			else $source = e::filters($filter['func'], $source, $filter['args']);
		}
		
		$this->timers['scope->get'] += microtime(true) - $tt;
		
		return $source;
	}
	
	public function parse($var) {
		$tt = microtime(true);
		
		$extract_vars = $this->extract_vars($var);
		if(!empty($extract_vars)) foreach($extract_vars as $rv) {
			$val = (string) $this->get($rv);
			$var = str_replace('{'.$rv.'}', $val, $var);
		}
		
		$extract_subvars = $this->extract_subvars($var);
		if(!empty($extract_subvars)) foreach($extract_subvars as $rv) {
			$val = (string) $this->get($rv);
			$var = str_replace('['.$rv.']', $val, $var);
		}
		
		if(strpos($var, ' ? ') !== false) {
			list($cond, $result) = explode(' ? ', $var);
			$else = false;
			
			if(strpos($result, ' : ') !== false) list($result, $else) = explode(' : ', $result);
			
			if(strpos($cond, ' + ') !== false) {
				list($cond1, $cond2) = explode(' + ', $cond);
				$var = $cond1 + $cond2;
			}
			else if(strpos($cond, ' - ') !== false) {
				list($cond1, $cond2) = explode(' - ', $cond);
				$var = $cond1 - $cond2;
			}
			else if(strpos($cond, ' / ') !== false) {
				list($cond1, $cond2) = explode(' / ', $cond);
				$var = $cond1 / $cond2;
			}
			else if(strpos($cond, ' * ') !== false) {
				list($cond1, $cond2) = explode(' * ', $cond);
				$var = $cond1 * $cond2;
			}
			else if(strpos($cond, ' == ') !== false) {
				list($cond, $compare) = explode(' == ', $cond);
				$val = $this->get($cond);
				$cval = $this->get($compare);
				
				if($val == $cval) $var = $result;
				else $var = $else;
			}
			else if(strpos($cond, ' != ') !== false) {
				list($cond, $compare) = explode(' != ', $cond);
				$val = $this->get($cond);
				$cval = $this->get($cond);
				
				if($val != $cval) $var = $result;
				else $var = $else;
			}
			else if(strpos($cond, ' () ') !== false) {
				list($cond, $compare) = explode(' () ', $cond);
				$val = $this->get($cond);
				$cval = explode(',', $this->get($cond));
				$retval = false;
				foreach($cval as $tmp) if($val == trim($tmp)) $retval = true;
				if($retval) $var = $result;
				else $var = $else;
			}
			else if(strpos($cond, ' () ') !== false) {
				list($cond, $compare) = explode(' () ', $cond);
				$val = $this->get($cond);
				$cval = explode(',', $this->get($cond));
				$retval = true;
				foreach($cval as $tmp) if($val == trim($tmp)) $retval = false;
				if($retval) $var = $result;
				else $var = $else;
			}
			else {
				$val = $this->get($cond);
				$val = is_string($val) ? trim($val) : $val;
				if($val) $var = $result;
				else $var = $else;
			}
		}
		
		$ef = $this->extract_funcs($var);
		if(is_array($ef)) foreach($ef as $k=>$f) {
			$ef[$k]['key'] = '%F'.$k;
			$var = str_replace($f['string'], '%F'.$k, $var);
		}
		
		if(strpos($var, '|') !== false) {
			$a = explode('|', $var);
			$var = (strlen($a[0]) > 0 ? $a[0] :false);
			$filters = array_slice($a, 1);
		}
		else $filters = array();
		
		$vars = explode('.', $var);
		foreach($vars as &$v) {
			if(substr($v, 0, 2) == '%F') $v = $ef[substr($v, 2)];
		}
		
		if(is_array($filters)) foreach($filters as &$filter) {
			if(substr($filter, 0, 2) == '%F') $filter = $ef[substr($filter, 2)];
		}
		
		$this->timers['scope->map'] += microtime(true) - $tt;
		
		return array('vars' => $vars, 'filters' => $filters);
	}
	
	/**
	 * Get parsed variable
	 */
	public function __get($v) {
		return $this->get($v);
	}
	
	/**
	 * Load a literal variable into the scope
	 */
	public function __set($var, $value) {
		$this->source_data[$var] = $value;
	}
	
	/**
	 * Load source into the scope
	 */
	public function source($source, $as = false) {
		/**
		 * Set the source as
		 */
		if(!$as) $as = 'i';
		$this->source_as = $as;
				
		/**
		 * Load the source into the scope
		 */
		$this->source_data[$this->source_as] = $source;
		
		/**
		 * If string or non traversable object
		 */
		if(!(is_array($source) || $source instanceof \Traversable))
			$this->source_count = 1;
		
		/**
		 * Else count the iterations
		 */
		else $this->source_count = count($source);
		
		/**
		 * Reset the pointer
		 */
		$this->source_pointer = false;
	}
	
	/**
	 * Reset Iterations
	 */
	public function reset() {
		$this->source_pointer = 0;
		
		return $this;
	}
	
	/**
	 * Next Source
	 */
	public function next() {
		if($this->source_pointer < $this->source_count)
			$this->source_pointer++;
		
		return $this;
	}
	
	/**
	 * Is still in a safe zone
	 */
	public function iteratable() {
		if($this->source_pointer >= 0 && $this->source_pointer < $this->source_count)
			return true;
		else
			return false;
	}
	
	/**
	 * Back One Source
	 */
	public function back() {
		if($this->source_pointer !== 0)
			$this->source_pointer--;
		
		return $this;
	}
	
	/**
	 * Count the Sources
	 */
	public function count() {
		return $this->source_count;
	}
	
	/**
	 * Extract all variables Below Here
	 */
	private function extract_vars($content) {
		
		if(strpos($content, '{') === false) return array();
		// parse out the variables
		preg_match_all(
			"/{([\w:|.\,\(\)\/\-\% \[\]\?'=]+?)}/", //regex
			$content, // source
			$matches_vars, // variable to export results to
			PREG_SET_ORDER // settings
		);
		
		foreach((array)$matches_vars as $var) {
			$vars[] = $var[1];
		}
		
		return $vars;
		
	}
	
	private function extract_subvars($content) {
		
		if(strpos($content, '[') === false) return array();
		// parse out the variables
		preg_match_all(
			"/\[([\w:|.\,\(\)\/\-\% \[\]\?'=]+?)\]/", //regex
			$content, // source
			$matches_vars, // variable to export results to
			PREG_SET_ORDER // settings
		);
		
		foreach((array)$matches_vars as $var) {
			$vars[] = $var[1];
		}
		
		return $vars;
		
	}
	
	private function extract_funcs($content) {
		if(strpos($content, '(') === false) return array();
		// parse out the variables
		preg_match_all(
			"/([\w\:]+?)\(([\w:|.\,=@\(\)\/\-\%& ]*?)\)/", //regex
			$content, // source
			$matches_vars, // variable to export results to
			PREG_SET_ORDER // settings
		);
		
		foreach((array)$matches_vars as $var) {
			$vars[] = array('func' => $var[1], 'string' => $var[0], 
				'args' => ($var[2] == '' ? array() : explode(',', $var[2]))
			);
		}
		
		return $vars;
	}
	
	/**
	 * Print Info
	 * @author Nate Ferrero
	 */
	public function printInfo() {
		echo "<ul>";
		foreach ($this->source_data as $var => $val) {
			echo "<li>$var: $val</li>";
		}
		echo "</ul>";
	}
}