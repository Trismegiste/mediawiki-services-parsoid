<?php

/* Starting point for transformerTests.php
*/

/*
Token transform unit test system

Purpose:
 During the porting of Parsoid to PHP, we need a system to capture
 and replay Javascript Parsoid token handler behavior and performance
 so we can duplicate the functionality and verify adequate performance.

 The transformerTest.js program works in concert with Parsoid and special
 capabilities added to the TokenTransformationManager.js file which
 now has token transformer test generation capabilities that produce test
 files from existing wiki pages or any wikitext. The Parsoid generated tests
 contain the specific handler name chosen for generation and the pipeline
 that was associated with the transformation execution. The pipeline ID
 is used by transformTest.js to properly order the replaying of the
 transformers input and output sequencing for validation.

 Manually written tests are supported and use a slightly different format
 which more closely resembles parserTest.txt and allows the test writer
 to identify each test with a unique description and combine tests
 for different token handlers in the same file, though only one handlers
 code can be validated and performance timed.

Technical details:
 The test validator and handler runtime emulates the normal
 Parsoid token transform manager behavior and handles tests sequences that
 were generated by multiple pipelines and uses the pipeline ID to call
 the transformers in sorted execution order to deal with parsoids
 execution order not completing each pipelined sequence in order.
 The system utilizes the transformers initialization code to install handler
 functions in a generalized way and run the test without specific
 transformer bindings.

 To create a test from an existing wikitext page, run the following
 commands, for example:
 $ node bin/parse.js --genTest QuoteTransformer,quoteTestFile.txt
 --pageName 'skating' < /dev/null > /tmp/output

 For command line options and required parameters, type:
 $ node bin/transformerTest.js --help

 An example command line to validate and performance test the 'skating'
 wikipage created as a QuoteTransformer test:
 $ node bin/transformTests.js --log --QuoteTransformer --inputFile quoteTestFile.txt

 TokenStreamPatcher, BehaviorSwitchHandler and SanitizerHandler are
 implemented but may need further debugging and manual tests written.
 */

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/../tests/MockEnv.php';

use Parsoid\Tests\MockEnv;

use Parsoid\Utils\PHPUtils;
use Parsoid\Tokens\Token;
use Parsoid\Tokens\KV;
use Parsoid\Tokens\TagTk;
use Parsoid\Tokens\EndTagTk;
use Parsoid\Tokens\SelfclosingTagTk;
use Parsoid\Tokens\NlTk;
use Parsoid\Tokens\CommentTk;
use Parsoid\Tokens\EOFTk;

$wgCachedState = false;
$wgCachedTestLines = '';
$wgCachedPipeLines = '';
$wgCachedPipeLinesLength = [];

/**
 * Create key value set from an array
 *
 * @param array $a
 * @return array
 */
function wfKvsFromArray( $a ) {
	$kvs = [];
	foreach ( $a as $e ) {
		$kvs[] = new KV(
			$e["k"],
			$e["v"],
			isset( $e["srcOffsets"] ) ? $e["srcOffsets"] : null,
			isset( $e["ksrc"] ) ? $e["ksrc"] : null,
			isset( $e["vsrc"] ) ? $e["vsrc"] : null
		);
	};
	return $kvs;
}

/**
 * Write text to the console
 *
 * @param string $msg
 */
function wfLog( $msg ) {
	print $msg;
}

/**
 * Get a token from some HTML text
 *
 * @param string $line
 * @return object
 */
function wfGetToken( $line ) {
	$jsTk = json_decode( $line, true );
	if ( gettype( $jsTk ) === "string" ) {
		$token = $jsTk;
	} else {
		switch ( $jsTk['type'] ) {
			case "SelfclosingTagTk":
				$token = new SelfclosingTagTk( $jsTk['name'], wfKvsFromArray( $jsTk['attribs'] ),
					$jsTk['dataAttribs'] );
				break;
			case "TagTk":
				$token = new TagTk( $jsTk['name'], wfKvsFromArray( $jsTk['attribs'] ),
					 $jsTk['dataAttribs'] );
				break;
			case "EndTagTk":
				$token = new EndTagTk( $jsTk['name'], wfKvsFromArray( $jsTk['attribs'] ),
					 $jsTk['dataAttribs'] );
				break;
			case "NlTk":
				$token = new NlTk( isset( $jsTk['dataAttribs']['tsr'] ) ?
					 $jsTk['dataAttribs']['tsr'] : null, $jsTk['dataAttribs'] );
				break;
			case "EOFTk":
				$token = new EOFTk();
				break;
			case "CommentTk":
				$token = new CommentTk( $jsTk["value"], $jsTk['dataAttribs'] );
				break;
		}
	}

	return $token;
}

// Mock environment for token transformers
class TransformTests {
	public $t;
	public $env;

	/**
	 * Constructor
	 *
	 * @param object $env
	 * @param object $options
	 */
	public function __construct( $env, $options ) {
		$this->env = $env;
		$this->pipelineId = 0;
		$this->options = $options;
		$this->tokenTime = 0;
	}

	/**
	 * Process token
	 * Use the TokenTransformManager.js guts (extracted essential functionality)
	 * to dispatch each token to the registered token transform function
	 *
	 * @param object $transformer
	 * @param token $token
	 * @return object
	 */
	public function processToken( $transformer, $token ) {
		$startTime = PHPUtils::getStartHRTime();
		if ( $token instanceof Token ) {
			$tkType = $token->getType();
		} else {
			$tkType = "String";
		}

		if ( $tkType === "NlTk" ) {
			$res = $transformer->onNewline( $token );
		} elseif ( $tkType === "EOFTk" ) {
			$res = $transformer->onEnd( $token );
		} else {
			$res = $transformer->onTag( $token );
		}

		$modified = false;
		if ( $res !== $token &&
			( !isset( $res['tokens'] ) || count( $res['tokens'] ) !== 1 || $res['tokens'][0] !== $token )
		) {
			$modified = true;
		}

		if ( !$modified && ( !is_array( $res ) || !isset( $res['skip'] ) )
			&& $transformer->onAnyEnabled ) {
			$res = $transformer->onAny( $token );
		}

		$this->tokenTime += PHPUtils::getHRTimeDifferential( $startTime );
		return $res;
	}

	/**
	 * Process test file
	 *
	 * @param object $transformer
	 * @param object $commandLine
	 * @return number
	 */
	public function processTestFile( $transformer, $commandLine ) {
		global $wgCachedState;
		global $wgCachedTestLines;
		$numFailures = 0;

		if ( isset( $commandLine->timingMode ) ) {
			if ( $wgCachedState == false ) {
				$wgCachedState = true;
				$testFile = file_get_contents( $commandLine->inputFile );
				$testFile = mb_convert_encoding( $testFile, 'UTF-8',
					mb_detect_encoding( $testFile, 'UTF-8, ISO-8859-1', true ) );
				$testLines = explode( "\n", $testFile );
				$wgCachedTestLines = $testLines;
			} else {
				$testLines = $wgCachedTestLines;
			}
		} else {
			$testFile = file_get_contents( $commandLine->inputFile );
			$testFile = mb_convert_encoding( $testFile, 'UTF-8',
				mb_detect_encoding( $testFile, 'UTF-8, ISO-8859-1', true ) );
			$testLines = explode( "\n", $testFile );
		}

		$countTestLines = count( $testLines );
		for ( $index = 0; $index < $countTestLines; $index++ ) {
			$line = $testLines[$index];
			if ( mb_strlen( $line ) < 1 ) {
				continue;
			}
			switch ( $line[0] ) {
				case '#':	// comment line
				case ' ':	// blank character at start of line
				case '':		// empty line
				case ':':
					// $testEnabled = preg_replace('/^:\s*|\s*$/', '', $line) === $transformerName;
					break;
				case '!':	// start of test with name
					$testName = substr( $line, 2 );
					break;
				case '[':	// desired result json string for test result verification
					if ( isset( $result ) && count( $result['tokens'] ) !== 0 ) {
						$stringResult = PHPUtils::jsonEncode( $result['tokens'] );
						# print "SR  : $stringResult\n";
						# print "LINE: $line\n";
						$line = preg_replace( '/{}/', '[]', $line );
						$stringResult = preg_replace( '/{}/', '[]', $stringResult );
						if ( $stringResult === $line ) {
							if ( !isset( $commandLine->timingMode ) ) {
								wfLog( $testName . " ==> passed\n\n" );
							}
						} else {
							$numFailures++;
							wfLog( $testName . " ==> failed\n" );
							wfLog( "line to debug => " . $line . "\n" );
							wfLog( "result line ===> " . $stringResult . "\n" );
						}
					}
					$result = null;
					break;
				case '{':
				default:
					# print "PROCESSING $line\n";
					$token = wfGetToken( $line );
					$result = $this->processToken( $transformer, $token );
					break;
			}
		}
		return $numFailures;
	}

	/**
	 * Create emulation of process pipelines
	 * Because tokens are processed in pipelines which can execute out of
	 * order, the unit test system creates an array of arrays to hold
	 * the pipeline ID which was used to process each token.
	 * The processWikitextFile function uses the pipeline IDs to ensure
	 * that all token processing for each pipeline occurs in order to completion.
	 *
	 * @param object $lines
	 * @return array
	 */
	private static function createPipelines( $lines ) {
		$numberOfTextLines = count( $lines );
		$maxPipelineID = 0;
		$LineToPipeMap = [];
		$LineToPipeMap = array_pad( $LineToPipeMap, $numberOfTextLines, 0 );
		for ( $i = 0; $i < $numberOfTextLines; ++$i ) {
			preg_match( '/(\d+)/', substr( $lines[$i], 0, 4 ), $matches );
			if ( count( $matches ) > 0 ) {
				$pipe = $matches[0];
				if ( $maxPipelineID < $pipe ) {
					$maxPipelineID = $pipe;
				}
			} else {
				$pipe = NAN;
			}
			$LineToPipeMap[$i] = $pipe;
		}
		$pipelines = [];
		$pipelines = array_pad( $pipelines, $maxPipelineID + 1, [] );
		for ( $i = 0; $i < $numberOfTextLines; ++$i ) {
			$pipe = $LineToPipeMap[$i];
			if ( !is_nan( $pipe ) ) {
				$pipelines[$pipe][] = $i;
			}
		}
		return $pipelines;
	}

	/**
	 * Process wiki test file
	 * Use the TokenTransformManager.js guts (extracted essential functionality)
	 * to dispatch each token to the registered token transform function
	 *
	 * @param object $transformer
	 * @param object $commandLine
	 * @return number
	 */
	public function processWikitextFile( $transformer, $commandLine ) {
		global $wgCachedState;
		global $wgCachedTestLines;
		global $wgCachedPipeLines;
		global $wgCachedPipeLinesLength;
		$numFailures = 0;

		if ( isset( $commandLine->timingMode ) ) {
			if ( $wgCachedState == false ) {
				$wgCachedState = true;
				$testFile = file_get_contents( $commandLine->inputFile );
				$testFile = mb_convert_encoding( $testFile, 'UTF-8',
					mb_detect_encoding( $testFile, 'UTF-8, ISO-8859-1', true ) );
				$testLines = explode( "\n", $testFile );
				$pipeLines = self::createPipelines( $testLines );
				$numPipelines = count( $pipeLines );
				$wgCachedTestLines = $testLines;
				$wgCachedPipeLines = $pipeLines;
				$wgCachedPipeLinesLength = $numPipelines;
			} else {
				$testLines = $wgCachedTestLines;
				$pipeLines = $wgCachedPipeLines;
				$numPipelines = $wgCachedPipeLinesLength;
			}
		} else {
			$testFile = file_get_contents( $commandLine->inputFile );
			$testFile = mb_convert_encoding( $testFile, 'UTF-8',
				mb_detect_encoding( $testFile, 'UTF-8, ISO-8859-1', true ) );
			$testLines = explode( "\n", $testFile );
			$pipeLines = self::createPipelines( $testLines );
			$numPipelines = count( $pipeLines );
		}

		for ( $i = 0; $i < $numPipelines; $i++ ) {
			if ( !isset( $pipeLines[$i] ) ) {
				continue;
			}

			$transformer->manager->pipelineId = $i;
			$p = $pipeLines[$i];
			$pLen = count( $p );
			for ( $element = 0; $element < $pLen; $element++ ) {
				$line = substr( $testLines[$p[$element]], 36 );
				switch ( $line{0} ) {
					case '[':	// desired result json string for test result verification
						$stringResult = PHPUtils::jsonEncode( $result['tokens'] );
						# print "SR  : $stringResult\n";
						$line = preg_replace( '/{}/', '[]', $line );
						$stringResult = preg_replace( '/{}/', '[]', $stringResult );
						if ( $stringResult === $line ) {
							if ( !isset( $commandLine->timingMode ) ) {
								wfLog( "line " . ( $p[$element] + 1 ) . " ==> passed\n\n" );
							}
						} else {
							$numFailures++;
							wfLog( "line " . ( $p[$element] + 1 ) . " ==> failed\n" );
							wfLog( "line to debug => " . $line . "\n" );
							wfLog( "result line ===> " . $stringResult . "\n" );
						}
						$result = null;
						break;
					case '{':
					default:
						# print "PROCESSING $line\n";
						$token = wfGetToken( $line );
						$result = $this->processToken( $transformer, $token );
						break;
				}
			}
		}
		return $numFailures;
	}

	/**
	 * Process unit test file
	 *
	 * @param object $tokenTransformer
	 * @param object $commandLine
	 * @return number
	 */
	public function unitTest( $tokenTransformer, $commandLine ) {
		if ( !isset( $commandLine->timingMode ) ) {
			wfLog( "Starting stand alone unit test running file " .
				$commandLine->inputFile . "\n\n" );
		}
		$numFailures = $tokenTransformer->manager->processTestFile( $tokenTransformer, $commandLine );
		if ( !isset( $commandLine->timingMode ) ) {
			wfLog( "Ending stand alone unit test running file " .
				$commandLine->inputFile . "\n\n" );
		}
		return $numFailures;
	}

	/**
	 * Process wiki text test file
	 *
	 * @param object $tokenTransformer
	 * @param object $commandLine
	 * @return number
	 */
	public function wikitextTest( $tokenTransformer, $commandLine ) {
		if ( !isset( $commandLine->timingMode ) ) {
			wfLog( "Starting stand alone wikitext test running file " .
				$commandLine->inputFile . "\n\n" );
		}
		$numFailures = $tokenTransformer->manager->processWikitextFile( $tokenTransformer, $commandLine );
		if ( !isset( $commandLine->timingMode ) ) {
			wfLog( "Ending stand alone wikitext test running file " .
				$commandLine->inputFile . "\n\n" );
		}
		return $numFailures;
	}
}

/**
 * Select test type of unit test or wiki text test
 *
 * @param object $commandLine
 * @param object $manager
 * @param object $handler
 * @return number
 */
function wfSselectTestType( $commandLine, $manager, $handler ) {
	$iterator = 1;
	$numFailures = 0;
	if ( isset( $commandLine->timingMode ) ) {
		if ( isset( $commandLine->iterationCount ) ) {
			$iterator = $commandLine->iterationCount;
		} else {
			$iterator = 10000;  // defaults to 10000 iterations
		}
	}
	while ( $iterator-- ) {
		if ( isset( $commandLine->manual ) ) {
			$numFailures = $manager->unitTest( $handler, $commandLine );
		} else {
			$numFailures = $manager->wikitextTest( $handler, $commandLine );
		}
	}
	return $numFailures;
}

/**
 * ProcessArguments handles a subset of javascript yargs like processing for command line
 * parameters setting object elements to the key name. If no value follows the key,
 * it is set to true, otherwise it is set to the value. The key can be followed by a
 * space then value, or an equals symbol then the value. Parameters that are not
 * preceded with -- are stored in the element _array at their argv index as text.
 *  There is no security checking for the text being processed by the dangerous eval() function.
 *
 * @param number $argc
 * @param array $argv
 * @return object
 */
function wfProcessArguments( $argc, $argv ) {
	$opts = (object)[];
	$last = false;
	for ( $index = 1; $index < $argc; $index++ ) {
		$text = $argv[$index];
		if ( '--' === substr( $text, 0, 2 ) ) {
			$assignOffset = strpos( $text, '=', 3 );
			if ( $assignOffset === false ) {
				$key = substr( $text, 2 );
				$last = $key;
				eval( '$opts->' . $key . '=true;' );
			} else {
				$value = substr( $text, $assignOffset + 1 );
				$key = substr( $text, 2, $assignOffset - 2 );
				$last = false;
				eval( '$opts->' . $key . '=\'' . $value . '\';' );
			}
		} elseif ( $last === false ) {
				eval( '$opts->_array[' . ( $index - 1 ) . ']=\'' . $text . '\';' );
		} else {
				eval( '$opts->' . $last . '=\'' . $text . '\';' );
		}
	}
	return $opts;
}

/**
 * Run tests as specified by commmand line arguments
 *
 * @param number $argc
 * @param array $argv
 * @return number
 */
function wfRunTests( $argc, $argv ) {
	$numFailures = 0;

   $opts = wfProcessArguments( $argc, $argv );

	if ( isset( $opts->help ) ) {
		wfLog( "must specify [--manual] [--log] [--timingMode]" .
			" [--iterationCount=XXX] --TransformerName --inputFile /path/filename" );
		return;
	}

	if ( !isset( $opts->inputFile ) ) {
		wfLog( "must specify [--manual] [--log] --TransformerName" .
			" --inputFile /path/filename\n" );
		wfLog( "Run 'node bin/transformerTests.js --help' for more information\n" );
		return;
	}

	$mockEnv = new MockEnv( $opts );
	$manager = new TransformTests( $mockEnv, [] );

	if ( isset( $opts->timingMode ) ) {
		wfLog( "Timing Mode enabled, no console output expected till test completes\n" );
	}

	$startTime = PHPUtils::getStartHRTime();

	if ( isset( $opts->QuoteTransformer ) ) {
		$qt = new Parsoid\Wt2Html\TT\QuoteTransformer( $manager, function () {
  } );
		$numFailures = wfSselectTestType( $opts, $manager, $qt );
	} elseif ( isset( $opts->ParagraphWrapper ) ) {
		$pw = new Parsoid\Wt2Html\TT\ParagraphWrapper( $manager, function () {
  } );
		$numFailures = wfSselectTestType( $opts, $manager, $pw );
	}
	/*
	  else if ($opts->ListHandler) {
		var lh = new ListHandler(manager, {});
		wfSselectTestType(argv, manager, lh);
	} else if ($opts->PreHandler) {
		var ph = new PreHandler(manager, {});
		wfSselectTestType(argv, manager, ph);
	} else if ($opts->TokenStreamPatcher) {
		var tsp = new TokenStreamPatcher(manager, {});
		wfSselectTestType(argv, manager, tsp);
	} else if ($opts->BehaviorSwitchHandler) {
		var bsh = new BehaviorSwitchHandler(manager, {});
		wfSselectTestType(argv, manager, bsh);
	} else if ($opts->SanitizerHandler) {
		var sh = new SanitizerHandler(manager, {});
		wfSselectTestType(argv, manager, sh);
	} */ else {
		wfLog( 'No valid TransformerName was specified' );
		$numFailures++;
}

	$totalTime = PHPUtils::getHRTimeDifferential( $startTime );
	wfLog( 'Total transformer execution time = ' . $totalTime . " milliseconds\n" );
	wfLog( 'Total time processing tokens     = ' . round( $manager->tokenTime, 3 ) .
		" milliseconds\n" );
	if ( $numFailures ) {
		wfLog( 'Total failures: ' . $numFailures );
		exit( 1 );
	}
}

wfRunTests( $argc, $argv );