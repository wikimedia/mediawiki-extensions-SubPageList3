<?php

namespace MediaWiki\Extension\SubPageList3;

use Parser;

class Hooks {

	/**
	 * @param Parser $parser
	 * @throws \MWException
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setHook( 'splist', [ SubPageList3::class, 'renderSubpageList3' ] );
	}
}
