<?php
	$FreeSWITCHserver = '127.0.0.1';		// event_socket.xml param: listen-ip
	$FreeSWITCHport = 8021;					// event_socket.xml param: listen-port
	$FreeSWITCHpassword = file_get_contents('.password');	// event_socket.xml param: password
	$SofiaProfiles = array('internal');		// write empty array if not used: array();
	$FreeTDMspans = array('pri');			// write empty array if not used: array();
	$disableXML = false;					// You can disable retrieve XML data if error found

	$t = time();

function clearFlush() {
//	echo(str_repeat(' ', 2048));
	if (ob_get_length()) {
		@ob_flush();
		@flush();
		@ob_end_flush();
	}
}
function sendError($err) {
	print('<script type="text/javascript">window.parent.comet.handleResponse(\' ');
	echo '{"ERROR": "'.$err.'"}';
	//echo '{"ERROR": "'.$err.'"}'."\r\n\r\n";
	print(' \');</script>');
	clearFlush();
	exit;
}
function sendErrorXML() {
	$s = '';
	foreach (libxml_get_errors() as $error) {
		$s .= str_replace("\n", ' ', $error->message).' Line: '.$error->line.', column: '.$error->column.' ';
	}
	libxml_clear_errors();
	sendError($s.' Please debug or disable XML!');
	exit;
}

// getVar definition
if (!defined('PHP_VERSION_ID')) {
	$version = explode('.', PHP_VERSION);
	define('PHP_VERSION_ID', ($version[0] * 10000 + $version[1] * 100 + $version[2]));
	if (PHP_VERSION_ID < 50200) {
		function getVar($varname) {
			if (array_key_exists($varname, $_GET)) {
				return trim($_GET[$varname]);
			} else {
				return '';
			}
		}
	} else {
		function getVar($varname) {
			// PHP ver >= 5.2
			return filter_input(INPUT_GET, $varname, FILTER_SANITIZE_STRING);
		}
	}
} else {
	function getVar($varname) {
		return filter_input(INPUT_GET, $varname, FILTER_SANITIZE_STRING);
	}
}
// For old versions PHP
if ( !function_exists('json_decode') ){
function json_decode($json, $assoc=false)
{
    $comment = false;
    $out = '$x=';
 
    for ($i=0; $i<strlen($json); $i++)
    {
        if (!$comment)
        {
            if (($json[$i] == '{') || ($json[$i] == '[')) $out .= ' array(';
            else if (($json[$i] == '}') || ($json[$i] == ']'))   $out .= ')';
            else if ($json[$i] == ':')    $out .= '=>';
            else                         $out .= $json[$i];         
        }
        else $out .= $json[$i];
        if ($json[$i] == '"' && $json[($i-1)]!="\\") $comment = !$comment;
    }
    eval($out . ';');
    return $x;
}
}
if ( !function_exists('json_encode') ){
function json_encode( $data ) {           
    if( is_array($data) || is_object($data) ) {
        $islist = is_array($data) && ( empty($data) || array_keys($data) === range(0,count($data)-1) );
       
        if( $islist ) {
            $json = '[' . implode(',', array_map('json_encode', $data) ) . ']';
        } else {
            $items = Array();
            foreach( $data as $key => $value ) {
                $items[] = json_encode("$key") . ':' . json_encode($value);
            }
            $json = '{' . implode(',', $items) . '}';
        }
    } elseif( is_string($data) ) {
        # Escape non-printable or Non-ASCII characters.
        # I also put the \\ character first, as suggested in comments on the 'addclashes' page.
        $string = '"' . addcslashes($data, "\\\"\n\r\t/" . chr(8) . chr(12)) . '"';
        $json    = '';
        $len    = strlen($string);
        # Convert UTF-8 to Hexadecimal Codepoints.
        for( $i = 0; $i < $len; $i++ ) {
            $char = $string[$i];
            $c1 = ord($char);
            # Single byte;
            if( $c1 <128 ) {
                $json .= ($c1 > 31) ? $char : sprintf("\\u%04x", $c1);
                continue;
            }
            # Double byte
            $c2 = ord($string[++$i]);
            if ( ($c1 & 32) === 0 ) {
                $json .= sprintf("\\u%04x", ($c1 - 192) * 64 + $c2 - 128);
                continue;
            }
            # Triple
            $c3 = ord($string[++$i]);
            if( ($c1 & 16) === 0 ) {
                $json .= sprintf("\\u%04x", (($c1 - 224) <<12) + (($c2 - 128) << 6) + ($c3 - 128));
                continue;
            }
            # Quadruple
            $c4 = ord($string[++$i]);
            if( ($c1 & 8 ) === 0 ) {
                $u = (($c1 & 15) << 2) + (($c2>>4) & 3) - 1;
                $w1 = (54<<10) + ($u<<6) + (($c2 & 15) << 2) + (($c3>>4) & 3);
                $w2 = (55<<10) + (($c3 & 15)<<6) + ($c4-128);
                $json .= sprintf("\\u%04x\\u%04x", $w1, $w2);
            }
        }
    } else {
        # int, floats, bools, null
        $json = strtolower(var_export( $data, true ));
    }
    return $json;
}
}

class socketReader {
	protected $socket;

	public function __construct() {
		$this->socket = socket_create (AF_INET, SOCK_STREAM, 0);
		if ($this->socket < 0) sendError('socket_create() failed: reason: '.socket_strerror ($this->socket));
	}
	public function connect($server, $port) {
		return socket_connect($this->socket, $server, $port);
	}
	public function disconnect() {
		return socket_close($this->socket);
	}
	public function sRead($cnt) {
		if (empty($cnt)) return false;
		$c = 0;
		$res = '';
		do {
			$s = socket_read($this->socket, 1400, PHP_NORMAL_READ);
			if ($s === false) break;
			$res .= $s;
			$c = $c + strlen($s);
		} while ($c < $cnt);
		return $res;
	}
	public function pRead() {
		$res = '';
		do {
			$s = socket_read($this->socket, 1400, PHP_NORMAL_READ);
			if (($s === false) | ($s == "\n")) break;
			$res .= $s;
		} while (true);
		return $res;
	}
	public function sendData($data) {
		socket_write($this->socket, $data, strlen ($data));
	}
	public function getContentLen($v) {
		$p = strpos($v, 'Content-Length:');
		if ($p === false) return false;
		$n = strpos($v, "\n", $p+15);
		if ($n === false) return false;
		return trim(substr($v, $p+15, $n-$p-15));
	}
}

function findID($arr, $id) {
	if (count($arr) == 0) return false;
	for ($i=0; $i<count($arr); $i++) {
		if ($arr[$i]['id'] == $id) return $i;
	}
	return false;
}
class fsData {
	const cPart1NotFound = -2;
	const cPart2NotFound = 2;
	const cField1NotFound = -1;
	const cField2NotFound = 1;
	const cFieldNotEqual = 0;
	const cExtOperation = 'fsoperation';

	public $data	= array();
	public $extdata	= array();
	protected $socket;
	
	public function __construct($socket) {
		$this->socket = $socket; 
	}
	public function getData($command) {
		$this->socket->sendData($command);
		$out = $this->socket->pRead();
		$c = $this->socket->getContentLen($out);
		if ($c === false) return '';
		return $this->socket->sRead($c);
	}

	public function loadData($param = null) {}
	public function fieldProcessing($field, $value) {
		return $value;
	}
	public function compare($data1, $data2) {
		foreach ($data1 as $part => $dvalue) {
			if (array_key_exists($part, $data2)) {
				foreach ($dvalue as $key => $value) {
					if (array_key_exists($key, $data2[$part])) {
						// Field exist - compare two fields
						if (is_array($value)) {
							$x = array_diff($value, $data2[$part][$key]);
							if (count($x) > 0) $this->onCompare(fsData::cFieldNotEqual, $part, $key, $data2[$part][$key]);
						} else {
							if ($value != $data2[$part][$key]) {
								$this->onCompare(fsData::cFieldNotEqual, $part, $key, $data2[$part][$key]);
							}
						}
					} else {
						// Field not exist in data2[part]
						$this->onCompare(fsData::cField1NotFound, $part, $key, $value);
					}
				}
			} else {
				// Part not found in data2
				$this->onCompare(fsData::cPart1NotFound, $part, null, $dvalue);
			}
		}
		foreach ($data2 as $part => $dvalue) {
			if (array_key_exists($part, $data1)) {
				foreach ($dvalue as $key => $value) {
					if (!array_key_exists($key, $data1[$part])) {
						// Field not exist in data1[part]
						$this->onCompare(fsData::cField2NotFound, $part, $key, $value);
					}
				}
			} else {
				// Part not found in data1
				$this->onCompare(fsData::cPart2NotFound, $part, null, $dvalue);
			}
		}
	}
	public function onCompare($cmp, $part, $field, $newvalue) {
		if (!array_key_exists($part, $this->extdata)) $this->extdata[$part] = array();
		if (!array_key_exists($part, $this->data)) $this->data[$part] = array();
		switch ($cmp) {
			case fsData::cPart1NotFound :
						unset($this->data[$part]);
						$this->extdata[$part][fsData::cExtOperation] = 'part_del';
						break;
			case fsData::cPart2NotFound :
						$this->data[$part] = $newvalue;
						$this->extdata[$part] = $newvalue;
						$this->extdata[$part][fsData::cExtOperation] = 'part_add';
						break;
			case fsData::cField1NotFound :
						unset($this->data[$part][$field]);
						$this->extdata[$part][$field] = $newvalue;
						if (is_array($newvalue)) {
								$this->extdata[$part][$field][fsData::cExtOperation] = 'field_del';
							} else {
								$this->extdata[$part][fsData::cExtOperation] = 'field_del';
						}
						break;
			case fsData::cField2NotFound :
						$this->data[$part][$field] = $newvalue;
						$this->extdata[$part][$field] = $newvalue;
						if (is_array($newvalue)) {
								$this->extdata[$part][$field][fsData::cExtOperation] = 'field_add';
							} else {
								$this->extdata[$part][fsData::cExtOperation] = 'field_add';
						}
						break;
			case fsData::cFieldNotEqual :
						$this->data[$part][$field] = $newvalue;
						$this->extdata[$part][$field] = $newvalue;
						if (is_array($newvalue)) {
								$this->extdata[$part][$field][fsData::cExtOperation] = 'field_upd';
							} else {
								$this->extdata[$part][fsData::cExtOperation] = 'field_upd';
						}
						break;
		}
	}
}

class fsSofia extends fsData {
	protected $isFirstCall = true;

	public function loadData($param = null) {
		$out = $this->getData("api sofia status profile ".$param." reg\n\n");
		// Fill array: d[user_id][fields from sofia status]
		$d = array();
		$n = strpos($out, "Registrations:\n");
		if ($n === false) return $d;
		$x = strpos($out, "=====\n", $n);
		$y = strpos($out, "Total items returned:", $x+7);
		$z = explode("\n\n", substr($out, $x+6, $y-$x-7));
		if ($this->isFirstCall) $d['fields'] = array();
		for ($i=0; $i<count($z); $i++) {
			$n = strpos($z[$i], 'User:');
			$y = strpos($z[$i], "\n", $n+5);
			$usr = trim(substr($z[$i], $n+6, $y-$n-5));
			$x = explode("\n", $z[$i]);
			for ($j=0; $j<count($x); $j++) {
				$n = strpos($x[$j], ":");
				if ($n === false) continue;
				$y = substr($x[$j], 0, $n);
				if ($this->isFirstCall) $d['fields'][count($d['fields'])] = $y;
				$d[$usr][$y] = $this->fieldProcessing($y, trim(substr($x[$j], $n+1, strlen($x[$j])-$n)));
			}
			$this->isFirstCall = false;
		}
		return $d;
	}
	public function loadDataStatus($param = null) {
		$fieldNames = array('name', 'type', 'data', 'state', 'count');

		$out = $this->getData("api sofia status".(is_null($param) ? '' : ' profile '.$param.' reg')."\n\n");
		// Fill array: d[n][fields from sofia status]
		$d = array();
		$n = strpos($out, "=====\n");
		if ($n === false) return $d;
		$x = strpos($out, "=====", $n+6);
		// Variant only for all sofia status (without profile name)
		$z = explode("\n", substr($out, $n+6, $x-$n-7));
		for ($i=0; $i<count($z); $i++) {
			$s = trim(preg_replace('/\s\s+/', ' ', $z[$i])); // multiple spaces
			$x = explode(' ', str_replace("\t", ' ', $s));
			$n = '';
			for ($j=0; $j<count($x); $j++) {
				if ($j == 0) {
					$n = $x[0];
					$d[$n] = array();
				}
				$d[$n][$fieldNames[$j]] = $x[$j];
			}
			if (count($d[$n]) < 5) {
				$d[$n][$fieldNames[4]] = 0;
			} else {
				$s = $d[$n][$fieldNames[4]];
				$d[$n][$fieldNames[4]] = intval(str_replace('(', '', str_replace(')', '', $s)));
			}
		}
		if ($this->isFirstCall) $this->isFirstCall = false;
		return $d;
	}
	/*
	 * Special processing (delete EXPSECS parameter)
	 */
	public function fieldProcessing($field, $value) {
		if ($field == 'Contact') {
			$s = str_replace('"', '', $value);
			$n = strpos($s, '<');
			if ($n !== false) $s = substr($s, 0, $n);
			return $s;
			//return str_replace('<', '&lt;', str_replace('>', '&gt;', $s));
		}
		if ($field != 'Status') return $value;
		$n = strpos($value, 'EXPSECS');
		if ($n === false) {
			return $value;
		} else {
			return substr($value, 0, $n-1);
		}
	}
}
class fsSofiaXML extends fsSofia {
	public function loadDataStatus($param = null) {
		$out = $this->getData("api sofia xmlstatus\n\n");
		$xml = simplexml_load_string(str_replace('encoding="ISO-8859-1"', 'encoding="utf-8"', $out), 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);
		if (!$xml) sendErrorXML();
		$d = array();
		$prevp = ''; // parent profile name, for construct profile::gateway
		foreach ($xml->children() as $child) { //level: profile gateway alias
			$x = false;
			$type = $child->getName();
			foreach ($child->children() as $item) { // next level: name, type, data, state
				$s = $item->getName();
				if ($s == 'name') {
					$x = strval($item);
					if ($type == 'gateway') $x = $prevp.'::'.$x; 
					$d[$x] = array();
					if ($type == 'profile') $prevp = $x;
				}
				if ($s == 'state') {
/*					$c = explode(' ', $item);
					$d[$x]['state'] = $c[0];
					$d[$x]['count'] = (count($c) > 1 ? intval(str_replace('(', '', str_replace(')', '', $c[1]))) : 0);*/
					$c = explode('(', $item);
					$d[$x]['state'] = $c[0];
					$d[$x]['count'] = (count($c) > 1 ? intval(str_replace(')', '', $c[1])) : 0);
				} else {
					$d[$x][$s] = strval($item);
				}
			}
		}
		if ($this->isFirstCall) $this->isFirstCall = false;
		return $d;
	}
	public function loadData($param = null) {
		$out = $this->getData("api sofia xmlstatus profile ".$param." reg\n\n");
		$xml = simplexml_load_string(str_replace('encoding="ISO-8859-1"', 'encoding="utf-8"', $out), 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);
		if (!$xml) sendErrorXML();
		$d = array();
		if ($this->isFirstCall) {
			$child2 = $xml->children();
			$child = $child2->children();
			$i = 0;
			$d['fields'] = array();
			foreach ($child->children() as $item) {
				$d['fields'][$i] = $item->getName();
				$i++;
			}
		}
		foreach ($xml->children() as $child) { //level: profile-info, registrations
			foreach ($child->children() as $regs) { // level: registration
				$x = false;
				foreach ($regs->children() as $item) { // next level: fields
					$s = $item->getName();
					if ($s == 'call-id') {
						$x = strval($item);
						$d[$x] = array();
					}
					$d[$x][$s] = $this->fieldProcessing($s, strval($item));
				}
			}
		}
		if ($this->isFirstCall) $this->isFirstCall = false;
		return $d;
	}
	/*
	 * Special processing (delete exp parameter)
	 */
	public function fieldProcessing($field, $value) {
		if ($field == 'contact') {
			$s = str_replace('"', '', $value);
			$n = strpos($s, '<');
			if ($n !== false) $s = substr($s, 0, $n);
			return $s;
			//return str_replace('<', '&lt;', str_replace('>', '&gt;', $s));
		}
		if ($field != 'status') return $value;
		$n = strpos($value, 'exp');
		if ($n === false) {
			return $value;
		} else {
			return substr($value, 0, $n-1);
		}
	}
}

class fsConf extends fsData {
	public $filter = '';

	public function loadData($param = null) {
		$out = $this->getData("api conference list\n\n");
		// Fill array: d[conference id][confNNN][name, number, status]
		$d = array();
		if (stripos($out, 'No active conferences') !== false) return $d;
		$x = explode("\n", $out);
		for ($i=0; $i<count($x); $i++) {
			if (stripos($x[$i], 'Conference ') !== false) {
				$y = explode(' ', $x[$i]);
				$conf = $y[1]; // Conference id
				if ((!empty($this->filter)) & ($conf != $this->filter)) continue;
				$d[$conf] = array();
			} else {
				$y = explode(";", $x[$i]);
				if (count($y) < 3) continue;
				// variant 1:
				//$n = count($d[$conf]);
				for ($j=0; $j<count($y); $j++) {
					switch ($j) {
						//case 0: $n = $y[$j]; $d[$conf][$n] = array(); $d[$conf][$n]['fsoperation'] = 1; break;
						case 0: // variant1:
								//$d[$conf][$n] = array();
								//$d[$conf][$n]['id'] = $y[$j];
								//$d[$conf][$n]['fsoperation'] = 1;
								// variant 2:
								$n = 'conf'.$y[$j];
								$d[$conf][$n] = array();
								break; 
						case 3: $d[$conf][$n]['name'] = $y[$j]; break;
						case 4: $d[$conf][$n]['number'] = $y[$j]; break;
						case 5: $d[$conf][$n]['status'] = $y[$j]; break;
					}
				}
			}
		}
		return $d;
	}
}

class fsChannel extends fsData {
	protected $isFirstCall = true;
	public $defaultChanFunc = 'channels';

	public function loadData($param = null) {
		$out = $this->getData("api show ".(empty($param) ? $this->defaultChanFunc : $param)."\n\n");
		// Fill array d[uuid][fields from channel status]
		$d = array();
		$idx = array();
		$x = explode("\n", $out);
		for ($i=0; $i<count($x); $i++) {
			//$y = explode(",", $x[$i]);
			$y = fsChannel::parseChan($x[$i]);
			if (count($y) < 3) continue;
			for ($j=0; $j<count($y); $j++) {
				if ($i == 0) {
					$idx[$j] = $y[$j];
					if ($this->isFirstCall) $d['fields'][$j] = $y[$j];
				} else {
					if ($j == 0) {
						$d[$y[0]] = array();
					} else {
						$d[$y[0]][$idx[$j]] = $y[$j];
					}
				}
			}
		}
		if ($this->isFirstCall) $this->isFirstCall = false;
		return $d;
	}
	/*
	 * Special one channel parsing
	 */
	public static function parseChan($s) {
		$arr = array();
		$x = 0; // word count
		$w = ''; // last word
		$utf8marker = chr(128);
		$doparse = true;
		$i = 0;
		while(isset($s{$i})){
			if ($s{$i} >= $utf8marker) {
				$parsechar = substr($s, $i, 2);
				$i += 2;
			} else {
				$parsechar = $s{$i};
				$i++;
			}
			if ($doparse) {
				if ($parsechar == ',') {
					$arr[$x] = $w;
					$w = '';
					$x++;
				} else {
					if ($parsechar == '[') {
						$doparse = false;
						$w .= $parsechar;
					} else {
						$w .= $parsechar;
					}
				}
			} else {
				if ($parsechar == ']') {
					$w .= $parsechar;
					$doparse = true;
				} else {
					$w .= $parsechar;
				}
			}
		}
		return $arr;
	}
}
class fsChannelXML extends fsChannel {
	/*
	 * param: channels, calls, detailed_calls
	 */
	public function loadData($param = null) {
		$d = array();
		$out = $this->getData("api show ".(empty($param) ? $this->defaultChanFunc : $param)." as xml\n\n");
		if (strpos($out, '-USAGE:') !== false) return $d; // invalid chan func
		$xml = simplexml_load_string(str_replace('encoding="ISO-8859-1"', 'encoding="utf-8"', $out), 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);
		if (!$xml) sendErrorXML();

		if ($this->isFirstCall) {
			$child = $xml->children();
			$i = 0;
			$d['fields'] = array();
			foreach ($child->children() as $item) {
				$d['fields'][$i] = $item->getName();
				$i++;
			}
		}
		foreach ($xml->children() as $child) { //level: row
			$x = false;
			foreach ($child->children() as $item) { // next level: uuid, direction, etc.
				$s = $item->getName();
				if ($s == 'uuid') {
					$x = strval($item);
					$d[$x] = array();
				}
				if ((!$x) & ($s == 'call_uuid')) { // for api show calls
					$x = strval($item);
					$d[$x] = array();
					continue;
				}
				$d[$x][$s] = strval($item);
			}
		}
		if ($this->isFirstCall) $this->isFirstCall = false;
		return $d;
	}
}

class fsFreeTDM extends fsData {
	public $_names;

	/*
	 * Convert one line with statuses to an array of fields
	 */
	public static function lineToFields($s) {
		$x = explode("\n", $s);
		$a = array();
		for ($i=0; $i<count($x); $i++) {
			$n = strpos($x[$i], ':');
			$f = trim(substr($x[$i], 0, $n));
			$a[$f] = trim(substr($x[$i], $n+1));
		}
		return $a;
	}
	public function loadData($param = null) {
		$d = array();
		foreach ($this->_names as $k => $v) {
			$s = $this->cmdDump($v);
			if ($s === false) continue;
			$n = strpos($s, "\n");
			$s = substr($s, $n+1); // delete +OK from string
			$x = explode("\n\n", $s); // first explode - by chan's
			for ($i=0; $i<count($x); $i++) {
				$y = explode("\n", $x[$i]); // second explode - by fields in chan
				$n = strpos($y[0], ':');
				$span = trim(substr($y[0], $n+1)); // extract span id
				if (empty($span)) continue;
				$n = strpos($y[1], ':');
				$chan = trim(substr($y[1], $n+1)); // extract chan id
				$d[$span][$chan] = self::lineToFields($x[$i]);
			}
		}
		return $d;
	}
	/*
	 * If chan is null run full dump
	 */
	public function cmdDump($span, $chan = null) {
		if (is_null($chan)) {
			return $this->getData("api ftdm dump $span\n\n");
		} else {
			return $this->getData("api ftdm dump $span $chan\n\n");
		}
	}
	public function cmdTrace($filename, $span, $chan=false) {
		return $this->getData("api ftdm trace $filename $span ".($chan!==false ? $chan : '')."\n\n");
	}
	public function cmdStopTrace($span, $chan=false) {
		return $this->getData("api ftdm notrace $span ".($chan!==false ? $chan : '')."\n\n");
	}
	public function cmdStartSpan($span) {
		return $this->getData("api ftdm start $span\n\n");
	}
	public function cmdStopSpan($span) {
		return $this->getData("api ftdm stop $span\n\n");
	}
	public function cmdQ931pcap($onoff, $span, $filename='') { // pcapfilename without suffix
		return $this->getData("api ftdm q931_pcap $span ".($onoff ? 'on' : 'off').(empty($filename) ? '' : ' '.$filename)."\n\n");
	}
	public function cmdDTMF($onoff, $span, $chan=false) {
		return $this->getData("api ftdm dtmf ".($onoff ? 'on' : 'off')." $span ".($chan!==false ? $chan : '')."\n\n");
	}
	public function cmdGains($tx, $rx, $span, $chan=false) {
		return $this->getData("api ftdm gains $tx $rx $span ".($chan!==false ? $chan : '')."\n\n");
	}
}

//echo(str_repeat(' ', 2048));
// Clear buffer
while (@ob_end_flush()) {}
ob_implicit_flush(1);

$sock = new socketReader();
if ($sock->connect($FreeSWITCHserver, $FreeSWITCHport) < 0) {
    sendError('socket_connect() failed. Reason: ('.$result.') '.socket_strerror($result));
}
$out = $sock->pRead();

//Sending AUTH request
$sock->sendData("auth $FreeSWITCHpassword\n\n");
$out = $sock->pRead();
if (strpos($out, 'Reply-Text: -ERR') !== false) {
	sendError('Cannot connect to FreeSWITCH. May be wrong password in the file fspanel.php $FreeSWITCHpassword='.$FreeSWITCHpassword);
}

// Run only one command and return result
if (array_key_exists('cmdapi', $_GET)) {
	switch (getVar('cmdapi')) {
		case 'cmd_sofia_status': $s = "api sofia status\n\n"; break;
		case 'cmd_status': $s = "api status\n\n"; break;
		case 'cmd_version': $s = "api version\n\n"; break;
		case 'cmd_reloadxml': $s = "api reloadxml\n\n"; break;
		case 'cmd_reloadacl': $s = "api reloadacl\n\n"; break;
		case 'cmd_uuid_kill': $s = "api uuid_kill ".getVar('param')." hangup\n\n"; break;
		case 'cmd_uuid_hold': $s = "api uuid_hold ".getVar('param')."\n\n"; break;
		case 'cmd_uuid_unhold': $s = "api uuid_hold off ".getVar('param')."\n\n"; break;
		case 'cmd_uuid_park': $s = "api uuid_park ".getVar('param')."\n\n"; break;
		
		case 'cmd_conf_deaf': $s = "api conference ".getVar('param')." deaf ".getVar('param2')."\n\n"; break;
		case 'cmd_conf_undeaf': $s = "api conference ".getVar('param')." undeaf ".getVar('param2')."\n\n"; break;
		case 'cmd_conf_hup': $s = "api conference ".getVar('param')." hup ".getVar('param2')."\n\n"; break;
		case 'cmd_conf_kick': $s = "api conference ".getVar('param')." kick ".getVar('param2')."\n\n"; break;
		case 'cmd_conf_mute': $s = "api conference ".getVar('param')." mute ".getVar('param2')."\n\n"; break;
		case 'cmd_conf_transfer': $s = "api conference ".getVar('param')." transfer ".getVar('param2')." ".getVar('param3')."\n\n"; break;
		case 'cmd_conf_unmute': $s = "api conference ".getVar('param')." unmute ".getVar('param2')."\n\n"; break;
		case 'cmd_conf_vol_in': $s = "api conference ".getVar('param')." volume_in ".getVar('param2')." ".getVar('param3')."\n\n"; break;
		case 'cmd_conf_vol_out': $s = "api conference ".getVar('param')." volume_out ".getVar('param2')." ".getVar('param3')."\n\n"; break;
		case 'cmd_conf_energy': $s = "api conference ".getVar('param')." energy ".getVar('param2')." ".getVar('param3')."\n\n"; break;

		case 'cmd_profile_rescan': $s = "api sofia profile ".getVar('param')." rescan reloadxml\n\n"; break;
		case 'cmd_profile_restart': $s = "api sofia profile ".getVar('param')." restart reloadxml\n\n"; break;
		case 'cmd_profile_flush_ir': $s = "api sofia profile ".getVar('param')." flush_inbound_reg\n\n"; break;
		case 'cmd_profile_flush_irr': $s = "api sofia profile ".getVar('param')." flush_inbound_reg reboot\n\n"; break;
		case 'cmd_gateway_kill': $s = "api sofia profile ".getVar('param')." killgw ".getVar('param2')."\n\n"; break;

		case 'cmd_ftdm_start_span': $s = "api ftdm start ".getVar('param')."\n\n"; break;
		case 'cmd_ftdm_stop_span': $s = "api ftdm stop ".getVar('param')."\n\n"; break;
		case 'cmd_ftdm_dump': $s = "api ftdm dump ".getVar('param')." ".getVar('param2')."\n\n"; break;
	}
	$sock->sendData($s);
	$out = $sock->pRead();
	$c = $sock->getContentLen($out);
	if ($c === false) exit;
	print(str_replace("\n", '<br />', $sock->sRead($c)).'<br />');
	exit;
}

$useXML = function_exists('simplexml_load_string');
if ($disableXML) $useXML = false;
if ($useXML) libxml_use_internal_errors(true);

$useChan = false;
$useConf = false;
$useSofiaReg = false;
$useSofiaStatus = false;
$useFTDM = false;

$src = array();
//$src = array('exts'=>array()); // for debug
if (array_key_exists('data', $_POST)) {
	$src = json_decode($_POST['data'], true);
}
if (array_key_exists('channels', $src)) {
	$useChan = true;
	if ($useXML) {
		$fChan = new fsChannelXML($sock);
	} else {
		$fChan = new fsChannel($sock);
	}
	if (array_key_exists('chan_func', $src['channels'])) {
		$fChan->defaultChanFunc = $src['channels']['chan_func'];
		unset($src['channels']['chan_func']);
	}
	$fChan->data = $src['channels'];
}
if (array_key_exists('exts', $src) && (count($SofiaProfiles) > 0)) {
	$useSofiaReg = true;
	if ($useXML) {
		$fSofiaReg = new fsSofiaXML($sock);
	} else {
		$fSofiaReg = new fsSofia($sock);
	}
	$fSofiaReg->data = $src['exts'];
}
if (array_key_exists('ss', $src)) {
	$useSofiaStatus = true;
	if ($useXML) {
		$fSofiaStatus = new fsSofiaXML($sock);
	} else {
		$fSofiaStatus = new fsSofia($sock);
	}
	$fSofiaStatus->data = $src['ss'];
}
if (array_key_exists('confs', $src)) {
	$useConf = true;
	$fConf = new fsConf($sock);
	$fConf->data = $src['confs'];
	$fConf->filter = getVar('conf_filter');
}
if (array_key_exists('ftdms', $src) && (count($FreeTDMspans) > 0)) {
	$useFTDM = true;
	$fFTDM = new fsFreeTDM($sock);
	$fFTDM->_names = $FreeTDMspans;
	$fFTDM->data = $src['ftdms'];
}

$tmax = ini_get('max_execution_time') - (time()-$t) - 5;
//$tmax = 25; // for debug

if ($useChan || $useConf || $useSofiaReg || $useSofiaStatus || $useFTDM) {
do {
	$res = array('channels'=>array(), 'confs'=>array(), 'exts'=>array(), 'ftdms'=>array(), 'ss'=>array()); // Compare result array

	if ($useChan) {
		$x = $fChan->loadData();
		$fChan->extdata = &$res['channels'];
		$fChan->compare($fChan->data, $x);
	}
	if ($useConf) {
		$x = $fConf->loadData();
		$fConf->extdata = &$res['confs'];
		$fConf->compare($fConf->data, $x);
	}
	if ($useSofiaReg) {
		for ($i=0; $i<count($SofiaProfiles); $i++) {
			$x = $fSofiaReg->loadData($SofiaProfiles[$i]);
			$fSofiaReg->extdata = &$res['exts'];
			$fSofiaReg->compare($fSofiaReg->data, $x);
		}
	}
	if ($useSofiaStatus) {
		$x = $fSofiaStatus->loadDataStatus();
		$fSofiaStatus->extdata = &$res['ss'];
		$fSofiaStatus->compare($fSofiaStatus->data, $x);
	}
	if ($useFTDM) {
		$x = $fFTDM->loadData();
		$fFTDM->extdata = &$res['ftdms'];
		$fFTDM->compare($fFTDM->data, $x);
	}
	// No changes found?
	if ((count($res['channels'])==0) && (count($res['confs'])==0) && (count($res['exts'])==0) && (count($res['ftdms'])==0) && (count($res['ss'])==0)) {
		if ((time()-$t) >= $tmax) break;
		continue;
	}
	print('<script type="text/javascript">window.parent.comet.handleResponse(\' ');
	print(json_encode($res));
	print(' \');</script>');
	clearFlush();
	sleep(1);
} while ((time()-$t) < $tmax);
}

$sock->sendData("exit\n\n");
$sock->pRead();
$sock->disconnect();

// Restart script on client side!
print('<script type="text/javascript">window.parent.comet.restart();</script>');
clearFlush();
?>
