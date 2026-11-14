<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\SubPageList3;

use Wikimedia\Parsoid\DOM\DocumentFragment;
use Wikimedia\Parsoid\Ext\ExtensionTagHandler;
use Wikimedia\Parsoid\Ext\ParsoidExtensionAPI;

class ParsoidTagHandler extends ExtensionTagHandler {
	/** @inheritDoc */
	public function sourceToDom(
		ParsoidExtensionAPI $extApi, string $src, array $extArgs
	): DocumentFragment {
		return SubPageList3::renderSubpageList3( $src, $extApi->extArgsToArray( $extArgs ), $extApi );
	}
}
