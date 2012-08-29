<?php namespace AssetCompressor\Drivers\MinifyJS;

/**
 * Class JSCompress
 *
 * JS minifier and compressor based on the Google Closure API
 *
 * @package Minify
 * @author JonoB
 */
class JSCompress {

	private $_srcs = array();
	private $_mode = "SIMPLE_OPTIMIZATIONS";
	private $_warning_level = "DEFAULT";
	private $_compress = true;
	private $_pretty_print = false;
	private $_debug = false;
	private $_cache_dir = "";
	private $_code_url_prefix = "";

	private $_start_time = '';

	private $_out = array();

  	public function __construct()
  	{
  		$this->_start_time = microtime(true);
  		return $this;
  	}

	/**
	* Adds a source file to the list of files to compile.  Files will be
	* concatenated in the order they are added.
	*/
	public function add($file)
	{
		$this->_srcs[] = $file;
		return $this;
	}

	/**
	* Sets the URL prefix to use with the Closure Compiler service's code_url
	* parameter.
	*
	* By default PHP-Closure posts the scripts to the compiler service, however,
	* this is subject to a 200000-byte size limit for the whole post request.
	*
	* Using code_url tells the compiler service the URLs of the scripts to
	* fetch.  The file paths added in add() must therefore be relative to this
	* URL.
	*
	*  ->useCodeUrl('http://www.example.com/app/')
	*
	*/
	public function use_code_url($code_url_prefix)
	{
		$this->_code_url_prefix = $code_url_prefix;
		return $this;
	}

	/**
	* Pretty print the output.
	*/
	public function pretty_print($print = true)
	{
		$this->_pretty_print = $print;
		return $this;
	}

	/**
	* Turns on the debug info.
	*/
	public function debug($debug = true)
	{
		$this->_debug = $debug;
		return $this;
	}

	/**
	 * Sets the compilation mode
	 * @param string $mode
	 * @return class
	 */
	public function mode($mode)
	{
		$this->_mode = $mode;
	}

	/**
	* Sets the compilation mode to optimize whitespace only.
	*/
	public function whitespace_only()
	{
		$this->mode('WHITESPACE_ONLY');
		return $this;
	}

	/**
	* Sets the compilation mode to simple optimizations.
	*/
	public function simple_mode()
	{
		$this->mode('SIMPLE_OPTIMIZATIONS');
		return $this;
	}

	/**
	* Sets the compilation mode to advanced optimizations.
	*/
	public function advanced_mode()
	{
		$this->mode('ADVANCED_OPTIMIZATIONS');
		return $this;
	}

	public function set_warning($level)
	{
		$this->_warning_level = $level;
		return $this;
	}

	/**
	* Sets the warning level to QUIET.
	*/
	public function quiet_warnings()
	{
		$this->set_warning('QUIET');
		return $this;
	}

	/**
	* Sets the default warning level.
	*/
	public function default_warnings()
	{
		$this->set_warning('DEFAULT');
		return $this;
	}

	/**
	* Sets the warning level to VERBOSE.
	*/
	public function verbose_warnings()
	{
		$this->set_warning('VERBOSE');
		return $this;
	}

	public function compress()
	{
		// get the result from the closure compiler
		$closure = json_decode($this->make_request());

    	$result = '';

    	if ($this->_debug) {
	      	$result = "if(window.console&&window.console.log){\r\n";
	      	$result .= "window.console.log('Closure Compiler Stats:\\n";
	        $result .= "-----------------------\\n";
	        $result .= "Original Size: " . (isset($closure->statistics->originalSize)) ? $closure->statistics->originalSize : '' . "\\n";
	        $result .= "Original Gzip Size: " . (isset($closure->statistics->originalGzipSize)) ? $closure->statistics->originalGzipSize : '' . "\\n";
	        $result .= "Compressed Size: " . (isset($closure->statistics->compressedSize)) ? $closure->statistics->compressedSize : '' . "\\n";
	        $result .= "Compressed Gzip Size: " . (isset($closure->statistics->compressedGzipSize)) ? $closure->statistics->compressedGzipSize : '' . "\\n";
	        $result .= "Compile Time: " . (isset($closure->statistics->compileTime)) ? $closure->statistics->compileTime : '' . "\\n";
	       	$result .= "Generated: " . Date("Y/m/d H:i:s T") . "');\r\n";

	      	if (isset($closure->errors)) {
	      		$result .= $this->print_warnings($closure->errors, 'error');
	      	}
	      	if (isset($closure->warnings)) {
	      		$result .= $this->print_warnings($closure->warnings, 'warn');
	      	}

	      	$result .= "}\r\n\r\n";
	    }

	    $result .= $closure->compiledCode . "\r\n";

	    return $result;
	}

	private function print_warnings($warnings, $level = 'log')
	{
		$result = '';
		foreach ($warnings as $warning) {
			$desc = addslashes($warning["value"]);
			$type = $warning["attributes"]["type"];
			$lineno = $warning["attributes"]["lineno"];
			$charno = $warning["attributes"]["charno"];
			$line = addslashes($warning["attributes"]["line"]);
			$result .= "window.console.$level('$type: $desc\\nLine: $lineno\\nChar: $charno\\nLine: $line');\r\n";
		}
		return $result;
	}

	function make_request()
	{
		$data = $this->get_params();
		$referer = @$_SERVER["HTTP_REFERER"] or "";

		$fp = fsockopen("closure-compiler.appspot.com", 80) or die("Unable to open socket");;

		if ($fp) {
			fputs($fp, "POST /compile HTTP/1.1\r\n");
			fputs($fp, "Host: closure-compiler.appspot.com\r\n");
			fputs($fp, "Referer: $referer\r\n");
			fputs($fp, "Content-type: application/x-www-form-urlencoded\r\n");
			fputs($fp, "Content-length: ". strlen($data) ."\r\n");
			fputs($fp, "Connection: close\r\n\r\n");
			fputs($fp, $data);

			$result = "";
			while ( ! feof($fp)) {
				$result .= fgets($fp, 128);
			}

			fclose($fp);
		}

		$data = substr($result, (strpos($result, "\r\n\r\n")+4));
		if (strpos(strtolower($result), "transfer-encoding: chunked") !== FALSE) {
			$data = $this->unchunk($data);
		}

		return $data;
	}

	private function unchunk($data)
	{
		$fp = 0;
		$outData = "";
		while ($fp < strlen($data)) {
			$rawnum = substr($data, $fp, strpos(substr($data, $fp), "\r\n") + 2);
			$num = hexdec(trim($rawnum));
			$fp += strlen($rawnum);
			$chunk = substr($data, $fp, $num);
			$outData .= $chunk;
			$fp += strlen($chunk);
		}
		return $outData;
	}

	private function get_params()
	{
		$params = array();
		foreach ($this->get_param_list() as $key => $value) {
	  		$params[] = preg_replace("/_[0-9]$/", "", $key) . "=" . urlencode($value);
		}
		return implode("&", $params);
	}

	private function get_param_list()
	{
		$params = array();
		if ($this->_code_url_prefix) {
			// Send the URL to each source file instead of the raw source.
			$i = 0;
			foreach($this->_srcs as $file) {
				$params["code_url_$i"] = $this->_code_url_prefix . $file;
				$i++;
			}
		}
		else {
			$params["js_code"] = $this->read_sources();
		}

		$params['compilation_level'] = $this->_mode;
		$params['output_format'] = 'json';
		$params['warning_level'] = $this->_warning_level;
		$params['output_info_1'] = 'compiled_code';
		$params['output_info_2'] = 'statistics';
		$params['output_info_3'] = 'warnings';
		$params['output_info_4'] = 'errors';

		if ($this->_pretty_print) {
			$params["formatting"] = "pretty_print";
		}

		if ($this->_compress) {
			$params["use_closure_library"] = "true";
		}

		return $params;
	}

	private function read_sources()
	{
		$code = '';
		foreach ($this->_srcs as $src) {
	  		$code .= file_get_contents($src) . "\n\n";
		}
		return $code;
	}
}