<?php

namespace MediaWiki\Extension\SubPageList3;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Parser\Parser;

class Hooks implements ParserFirstCallInitHook {

	/**
	 * @param Parser $parser
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setHook( 'splist', [ SubPageList3::class, 'renderSubpageList3' ] );
	}
}
