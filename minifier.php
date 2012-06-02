<?php

//minifikace js

Interface Compiler {
	function compile(&$content);
}

Class GoogleCompiler implements Compiler
{

	CONST COMPILE_URL = 'http://closure-compiler.appspot.com/compile';

	function compile(&$content) {
		
		$params = array(
			"output_info=compiled_code",
			"compilation_level=WHITESPACE_ONLY",
			/*"output_format=json",
			"output_info=compiled_code",*/
			//"output_info=warnings",
			"output_info=errors",
			//"output_info=statistics",
			"js_code=".urlencode($content)
		);


		$c = curl_init (self::COMPILE_URL);
		curl_setopt ($c, CURLOPT_POST, true);
		curl_setopt ($c, CURLOPT_POSTFIELDS, implode("&", $params));
		curl_setopt ($c, CURLOPT_RETURNTRANSFER, true);
		return curl_exec ($c);   
	}
}

/** Remove spaces and comments from JavaScript code
* @param string code with commands terminated by semicolon
* @return string shrinked code
* @link http://vrana.github.com/JsShrink/
* @author Jakub Vrana, http://www.vrana.cz/
* @copyright 2012 Jakub Vrana
* @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
* @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License, version 2 (one or other)
*/
Class jsShrinkCompiler implements Compiler
{
	function compile(&$content) {
	    return preg_replace_callback('(
	        (?:
	            (^|[-+\([{}=,:;!%^&*|?~]|/(?![/*])|return|throw) # context before regexp
	            (?:\s|//[^\n]*+\n|/\*(?:[^*]|\*(?!/))*+\*/)* # optional space
	            (/(?![/*])(?:\\\\[^\n]|[^[\n/\\\\]|\[(?:\\\\[^\n]|[^]])++)+/) # regexp
	            |(^
	                |\'(?:\\\\.|[^\n\'\\\\])*\'
	                |"(?:\\\\.|[^\n"\\\\])*"
	                |([0-9A-Za-z_$]+)
	                |([-+]+)
	                |.
	            )
	        )(?:\s|//[^\n]*+\n|/\*(?:[^*]|\*(?!/))*+\*/)* # optional space
	    )sx', 'jsShrinkCompiler::jsShrinkCallback', "$content\n");
	}

	public static function jsShrinkCallback($match) {
	    static $last = '';
	    $match += array_fill(1, 5, null); // avoid E_NOTICE
	    list(, $context, $regexp, $result, $word, $operator) = $match;
	    if ($word != '') {
	        $result = ($last == 'word' ? "\n" : ($last == 'return' ? " " : "")) . $result;
	        $last = ($word == 'return' || $word == 'throw' || $word == 'break' ? 'return' : 'word');
	    } elseif ($operator) {
	        $result = ($last == $operator[0] ? "\n" : "") . $result;
	        $last = $operator[0];
	    } else {
	        if ($regexp) {
	            $result = $context . ($context == '/' ? "\n" : "") . $regexp;
	        }
	        $last = '';
	    }
	    return $result;
	}
}

Class Minifier {


	protected $mask = '';

	/** @var Compiler */
	protected $compiler;

	function __construct(Compiler $compiler, $mask) {
		if (! $mask || ! is_string($mask)) throw new Exception('Mask not passed');
		$this->mask = $mask;
		$this->compiler = $compiler;
	}

	public function minife() {
		return $this->joinFiles(
			$this->loadFiles(
				$this->getFiles()
			)
		);
	}

	protected function getFiles() {
		$files = glob($this->mask);
		if(count($files) > 50) throw new Exception("Too much files (".count(files).") was find for mask '{$this->mask}'");
		return $files;
	}

	protected function loadFiles(array $files) {
		$stack = array();
		$this->fails = array();

		foreach($files as $file) {
			$content = @file_get_contents($file);
			if ( ! is_string($content)) throw new Exception("File '$file' can't be loaded, check if file exists and there are read permission");
			
			$stack[$file] = $content;
		}
		return $stack;
	}


	protected function joinFiles(array $fileStack) {
		$joined = '/* COMPILED AT '.date('Y-m-d H:i:s') . " */\n\n";
		foreach($fileStack as $file => &$content) {
			$hash = md5($content);
			$modified = date('Y-m-d H:i:s', filemtime($file));
			$size = filesize($file);
			$start = microtime(TRUE);
			$minified = str_replace("\n", "\n\t\t", $this->compiler->compile($content));
			$time = microtime(TRUE) - $start;
			$joined .=
				"/*\n".
				"#<J<< JOINED FILE $file\n".
				"#HASH $hash\n".
				"#MODIFIED $modified\n".
				"#SIZE $size\n".
				"#COMPILE TIME $time\n".
				"*/\n".
				"\t!(function () {\n".
				"\t	$minified\n".
				"\t}()); //#END JOINED $hash >>J>\n\n\n";
		}
		return $joined;
	}
}

$minife = new Minifier(new GoogleCompiler, @$argv[1]);
echo $minife->minife();
