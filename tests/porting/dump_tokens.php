<?php

namespace Parsoid\Tests\Porting;

use Parsoid\Wt2Html\PegTokenizer;
use Parsoid\Utils\PHPUtils;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Parse the input wikitext and return parsed tokens
 * @param TokenizerMockEnv $env
 * @param string $input
 * @return array
 */
function parse( TokenizerMockEnv $env, string $input ): array {
	$tokenizer = new PegTokenizer( $env );
	$tokens = [];
	$tokenizer->tokenizeSync( $input, [
		'cb' => function ( $t ) use ( &$tokens ) {
			$tokens[] = $t;
		},
		'pegTokenizer' => $tokenizer,
		'pipelineOffset' => 0,
		'env' => $env,
		'startRule' => 'start'
	] );
	return $tokens;
}

$opts = [];
$env = new TokenizerMockEnv( [
	"siteConfig" => new TokenizerMockSiteConfig( $opts )
] );

if ( isset( $argv[1] ) ) {
	$inputFile = $argv[1];
} else {
	$inputFile = '-';
}
if ( $inputFile === '-' ) {
	$input = stream_get_contents( STDIN );
} else {
	$input = file_get_contents( $inputFile );
}
if ( $input === false ) {
	fwrite( STDERR, "Unable to open input file \"$inputFile\"\n" );
	exit( 1 );
}
$tokens = parse( $env, $input );

// Dump tokens with both byte offsets as well as JS offsets
// $file.php.jsoffset.tokens will be compared with $file.js.tokens
// and where they differ, it would be useful to look at the original
// tokens as emitted by the PHP tokenizer
if ( $inputFile !== '-' ) {
	$fp_byte = fopen( $inputFile . '.php.byteoffset.tokens', 'w' );
} else {
	$fp_byte = null;
}

foreach ( $tokens as $t ) {
	if ( $fp_byte ) {
		fwrite( $fp_byte, json_encode( $t ) . "\n" );
	}
	$offsets = [];
	if ( isset( $t->dataAttribs['tsr'] ) ) {
		$offsets[] = &$t->dataAttribs['tsr'][0];
		$offsets[] = &$t->dataAttribs['tsr'][1];
	}
}

PHPUtils::convertOffsets( $input, 'byte', 'ucs2', $offsets );

if ( $inputFile === '-' ) {
	$fp_ucs = STDOUT;
} else {
	$fp_ucs = fopen( $inputFile . '.php.jsoffset.tokens', 'w' );
}

foreach ( $tokens as $t ) {
	fwrite( $fp_ucs, json_encode( $t ) . "\n" );
}
