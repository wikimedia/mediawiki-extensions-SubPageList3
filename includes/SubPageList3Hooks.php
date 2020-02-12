<?php

class SubPageList3Hooks {

	/**
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setHook( 'splist', [ SubPageList3::class, 'renderSubpageList3' ] );
	}
}
