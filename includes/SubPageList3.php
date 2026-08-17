<?php

namespace MediaWiki\Extension\SubPageList3;

use LogicException;
use MediaWiki\Config\Config;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Title\Title;
use Wikimedia\Parsoid\DOM\DocumentFragment;
use Wikimedia\Parsoid\Ext\DOMUtils;
use Wikimedia\Parsoid\Ext\ParsoidExtensionAPI;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;

/**
 * SubPageList3 class
 */
class SubPageList3 {
	/**
	 * Default limit of descendants
	 */
	private const DESCENDANTS_LIMIT_DEFAULT = 200;

	/** Is this being processed with Parsoid? */
	private bool $withParsoid = false;
	private Parser|ParsoidExtensionAPI $parser;
	private PPFrame|bool $frame;
	private Title $title;
	private Title $ptitle;
	private string $namespace = '';
	/**
	 * token object
	 */
	private string $token = '*';
	private int $debug = 0;
	private array $errors = [];
	/**
	 * order type
	 * Can be:
	 * - asc
	 * - desc
	 */
	private string $order = 'asc';

	/**
	 * column that's used as order method
	 * Can be:
	 *  - title: alphabetic order of a page title
	 *  - lastedit: Timestamp numeric order of the last edit of a page
	 * @private
	 */
	private string $ordermethod = 'title';

	/**
	 * mode of the output
	 * Can be:
	 *  - unordered: UL list as output
	 *  - ordered: OL list as output
	 *  - bar: uses · as a delimiter producing a horizontal bar menu
	 */
	private string $mode = 'unordered';

	/**
	 * parent of the listed pages
	 * Can be:
	 *  - -1: the current page title
	 *  - string: title of the specific title
	 * e.g. if you are in Mainpage/ it will list all subpages of Mainpage/
	 * @var mixed parent of listed pages
	 */
	private $parent = -1;

	/**
	 * style of the path (title)
	 * Can be:
	 *  - full: normal, e.g. Mainpage/Entry/Sub
	 *  - notparent: the path without the $parent item, e.g. Entry/Sub
	 *  - no: no path, only the page title, e.g. Sub
	 * @var string style of the path (title)
	 * @see $parent
	 */
	private $showpath = 'no';

	/**
	 * whether to show next sublevel only, or all sublevels
	 * Can be:
	 *  - 0 / no / false
	 *  - 1 / yes / true
	 * @var mixed show one sublevel only
	 * @see $parent
	 */
	private $kidsonly = 0;

	/**
	 * whether to show parent as the top item
	 * Can be:
	 *  - 0 / no / false
	 *  - 1 / yes / true
	 * @var mixed show one sublevel only
	 * @see $parent
	 */
	private $showparent = 0;

	/**
	 * Text to show when parent has no subpages to list
	 * when null (by default) shows default message
	 */
	private ?string $nosubpages = null;
	private Config $config;

	/**
	 * Constructor function of the class
	 * @param Parser|ParsoidExtensionAPI $parser the parser object
	 * @param Config $config
	 * @param PPFrame|bool $frame
	 * @see SubpageList
	 */
	private function __construct(
		Parser|ParsoidExtensionAPI $parser, Config $config, PPFrame|bool $frame = false
	) {
		$this->parser = $parser;
		$this->frame = $frame;
		$this->config = $config;
		if ( $parser instanceof ParsoidExtensionAPI ) {
			$this->withParsoid = true;
			$this->title = Title::newFromLinkTarget( $parser->getPageConfig()->getLinkTarget() );
		} else {
			$this->withParsoid = false;
			$this->title = $parser->getTitle();
		}
	}

	/**
	 * Function called by the Hook, returns the wiki text
	 *
	 * @param string $input
	 * @param array $args
	 * @param Parser|ParsoidExtensionAPI $parser
	 * @param ?PPFrame $frame
	 * @return string|DocumentFragment
	 */
	public static function renderSubpageList3(
		$input, array $args, Parser|ParsoidExtensionAPI $parser, ?PPFrame $frame = null
	) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'SubPageList3' );
		$list = new SubpageList3( $parser, $config, $frame ?? false );
		$list->options( $args );

		# $parser->disableCache();
		return $list->render();
	}

	/**
	 * adds error to the $errors container
	 * but only if $debug is true or 1
	 * @param string $message the errors message
	 * @see $errors
	 * @see $debug
	 */
	private function error( $message ) {
		if ( $this->debug ) {
			$this->errors[] = "<strong>Error [Subpage List 3]:</strong> $message";
		}
	}

	/**
	 * returns all errors as a string
	 * @return string all errors separated by a newline
	 */
	private function geterrors() {
		return implode( "\n", $this->errors );
	}

	/**
	 * parse the options that the user has entered
	 * a bit long way, but because that it's easy to add alias
	 * @param array $options the options inserts by the user as array
	 * @see $debug
	 * @see $order
	 * @see $ordermethod
	 * @see $mode
	 * @see $parent
	 * @see $showpath
	 * @see $kidsonly
	 * @see $showparent
	 */
	private function options( $options ) {
		if ( isset( $options['debug'] ) ) {
			if ( in_array( $options['debug'], [ 'true', 1, '1' ], true ) ) {
				$this->debug = 1;
			} elseif ( in_array( $options['debug'], [ 'false', 0, '0' ], true ) ) {
				$this->debug = 0;
			} else {
				$this->error( wfMessage( 'spl3_debug', 'debug' )->escaped() );
			}
		}
		if ( isset( $options['sort'] ) ) {
			switch ( strtolower( $options['sort'] ) ) {
				case 'asc':
					$this->order = 'asc';
					break;
				case 'desc':
					$this->order = 'desc';
					break;
				default:
					$this->error( wfMessage( 'spl3_debug', 'sort' )->escaped() );
			}
		}
		if ( isset( $options['sortby'] ) ) {
			switch ( strtolower( $options['sortby'] ) ) {
				case 'title':
					$this->ordermethod = 'title';
					break;
				case 'lastedit':
					$this->ordermethod = 'lastedit';
					break;
				default:
					$this->error( wfMessage( 'spl3_debug', 'sortby' )->escaped() );
			}
		}
		if ( isset( $options['liststyle'] ) ) {
			switch ( strtolower( $options['liststyle'] ) ) {
				case 'ordered':
					$this->mode = 'ordered';
					$this->token = '#';
					break;
				case 'unordered':
					$this->mode = 'unordered';
					$this->token = '*';
					break;
				case 'bar':
					$this->mode = 'bar';
					$this->token = '&#160;· ';
					break;
				default:
					$this->error( wfMessage( 'spl3_debug', 'liststyle' )->escaped() );
			}
		}
		if ( isset( $options['parent'] ) ) {
			if ( intval( $options['parent'] ) == -1 ) {
				$this->parent = -1;
			} elseif ( is_string( $options['parent'] ) ) {
				$this->parent = $this->parse( $options['parent'] );
				if ( $this->withParsoid ) {
					// a41be6b added the ability to use wikitext like {{{1}}}
					// or {{ROOTPAGENAME}} to set parent title string.
					// So, convert the fragment to a text string.
					$this->parent = $this->parent->textContent;
				}
			} else {
				$this->error( wfMessage( 'spl3_debug', 'parent' )->escaped() );
			}
		}
		if ( isset( $options['showpath'] ) ) {
			$showPath = strtolower( $options['showpath'] );
			if ( $showPath === 'no' || $showPath === '0' || $showPath === 'false' ) {
				$this->showpath = 'no';
			} elseif ( $showPath === 'notparent' ) {
				$this->showpath = 'notparent';
			} elseif ( in_array( $showPath, [ 'full', 'yes', '1', 'true' ], true ) ) {
				$this->showpath = 'full';
			} else {
				$this->error( wfMessage( 'spl3_debug', 'showpath' )->escaped() );
			}
		}
		if ( isset( $options['kidsonly'] ) ) {
			if ( $options['kidsonly'] == 'true' || $options['kidsonly'] == 'yes'
				|| intval( $options['kidsonly'] ) == 1
			) {
				$this->kidsonly = 1;
			} elseif ( $options['kidsonly'] == 'false' || $options['kidsonly'] == 'no'
				|| intval( $options['kidsonly'] ) == 0
			) {
				$this->kidsonly = 0;
			} else {
				$this->error( wfMessage( 'spl3_debug', 'kidsonly' )->escaped() );
			}
		}
		if ( isset( $options['showparent'] ) ) {
			if ( $options['showparent'] == 'true' || $options['showparent'] == 'yes'
				|| intval( $options['showparent'] ) == 1
			) {
				$this->showparent = 1;
			} elseif ( $options['showparent'] == 'false' || $options['showparent'] == 'no'
				|| intval( $options['showparent'] ) == 0
			) {
				$this->showparent = 0;
			} else {
				$this->error( wfMessage( 'spl3_debug', 'showparent' )->escaped() );
			}
		}

		$this->nosubpages = $options['nosubpages'] ?? null;
	}

	/**
	 * produce output using this class
	 * @return string html output
	 */
	private function render() {
		$pages = $this->getTitles();
		$class = 'subpagelist';
		if ( $pages != null && count( $pages ) > 0 ) {
			$list = $this->makeList( $pages );
			$htmlOrDom = $this->parse( $list );
		} else {
			if ( $this->nosubpages !== null ) {
				$out = $this->nosubpages;
			} else {
				$plink = "[[" . $this->parent . "]]";
				$out = "''" . wfMessage( 'spl3_nosubpages', $plink )->text() . "''\n";
			}
			$htmlOrDom = $this->parse( $out );
			$class .= ' subpagelist-empty';
		}
		$errorHTML = $this->geterrors();
		if ( $this->withParsoid ) {
			$frag = $htmlOrDom;
			$div = $frag->ownerDocument->createElement( 'div' );
			$div->setAttribute( 'class', $class );
			DOMUtils::migrateChildren( $this->parser->htmlToDOM( $errorHTML ), $div );
			DOMUtils::migrateChildren( $frag, $div );
			$frag->insertBefore( $div, $frag->firstChild );
			return $frag;
		} else {
			return Html::rawElement( 'div', [ 'class' => $class ], $errorHTML . $htmlOrDom );
		}
	}

	/**
	 * return the page titles of the subpages in an array
	 * @return array|null all titles, null on failure
	 */
	private function getTitles() {
		if ( $this->parent !== -1 ) {
			$this->ptitle = Title::newFromText( $this->parent );
			$userFactory = MediaWikiServices::getInstance()->getUserFactory();
			// FIXME: Parsoid will excessively restrict access to some pages even if the user
			// has the rights. Parsoid currently doesn't have a mechanism to unredact
			// information during read view rendering in the OutputTransformPipeline.
			// Once implemented, this code here will likely change.
			$user = $this->withParsoid ? $userFactory->newAnonymous() :
					$userFactory->newFromUserIdentity( $this->parser->getUserIdentity() );
			// note that non-existent pages may nevertheless have valid subpages
			// on the other hand, not checking that the page exists can let input
			// through which causes database errors
			if (
				$this->ptitle instanceof Title &&
				$this->ptitle->exists() &&
				$user->definitelyCan( 'read', $this->ptitle )
			) {
				$parent = $this->ptitle->getDBkey();
				$this->parent = $parent;
				$this->namespace = $this->ptitle->getNsText();
				$nsi = $this->ptitle->getNamespace();
			} else {
				$this->error( wfMessage( 'spl3_debug', 'parent' )->escaped() );
				return null;
			}
		} else {
			$this->ptitle = $this->title;
			$parent = $this->title->getDBkey();
			$this->parent = $parent;
			$this->namespace = $this->title->getNsText();
			$nsi = $this->title->getNamespace();
		}

		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( [ 'page_namespace', 'page_title' ] )
			->from( 'page' )
			// don't let lists cross namespaces or include redirects
			->where( [
				'page_namespace' => $nsi,
				'page_is_redirect' => 0,
				$dbr->expr( 'page_title', IExpression::LIKE, new LikeValue( $parent . '/', $dbr->anyString() ) ),
			] )
			->caller( __METHOD__ );

		$order = strtoupper( $this->order );
		if ( $this->ordermethod == 'title' ) {
			$queryBuilder->orderBy( 'page_title', $order );
		} elseif ( $this->ordermethod == 'lastedit' ) {
			$queryBuilder->orderBy( 'page_touched', $order );
		}

		$res = $queryBuilder->fetchResultSet();

		$titles = [];
		foreach ( $res as $row ) {
			$title = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
			if ( $title ) {
				$titles[] = $title;
			}
		}

		return $titles;
	}

	/**
	 * create one list item
	 * cases:
	 *  - full: full, e.g. Mainpage/Entry/Sub
	 *  - notparent: the path without the $parent item, e.g. Entry/Sub
	 *  - no: no path, only the page title, e.g. Sub
	 * @param Title $title the title of a page
	 * @return string the prepared string
	 * @see $showpath
	 */
	private function makeListItem( $title ) {
		switch ( $this->showpath ) {
			case 'no':
				$linktitle = substr( strrchr( $title->getText(), "/" ), 1 );
				break;
			case 'notparent':
				$linktitle = substr( strstr( $title->getText(), "/" ), 1 );
				break;
			case 'full':
				$linktitle = $title->getText();
				break;
			default:
				throw new LogicException( "Can not happen" );
		}
		return ' [[' . $title->getPrefixedText() . '|' . $linktitle . ']]';
	}

	/**
	 * create whole list using makeListItem
	 * @param array $titles Array all page titles
	 * @return string the whole list
	 * @see SubPageList::makeListItem
	 */
	private function makeList( $titles ) {
		$descendantsLimitRaw = $this->config->get( 'SubPageListDescendantsLimit' );
		$descendantsLimit = is_int( $descendantsLimitRaw ) ? $descendantsLimitRaw : self::DESCENDANTS_LIMIT_DEFAULT;
		$c = 0;
		$list = [];
		# add parent item
		if ( $this->showparent ) {
			$pn = '[[' . $this->ptitle->getPrefixedText() . '|' . $this->ptitle->getText() . ']]';
			if ( $this->mode != 'bar' ) {
				$pn = $this->token . $pn;
			}
			$ss = trim( $pn );
			$list[] = $ss;
			// flag for bar token to be added on next item
			$c++;
		}
		# add descendants
		$parlv = substr_count( $this->ptitle->getPrefixedText(), '/' );
		foreach ( $titles as $title ) {
			$lv = substr_count( $title, '/' ) - $parlv;
			if ( $this->kidsonly != 1 || $lv < 2 ) {
				if ( $this->showparent ) {
					$lv++;
				}
				$ss = "";
				if ( $this->mode == 'bar' ) {
					if ( $c > 0 ) {
						$ss .= $this->token;
					}
				} else {
					for ( $i = 0; $i < $lv; $i++ ) {
						$ss .= $this->token;
					}
				}
				$ss .= $this->makeListItem( $title );
				// make sure we don't get any <pre></pre> tags
				$ss = trim( $ss );
				$list[] = $ss;
			}
			$c++;
			if ( $c > $descendantsLimit ) {
				break;
			}
		}
		$retval = '';
		if ( count( $list ) > 0 ) {
			$retval = implode( "\n", $list );
			if ( $this->mode == 'bar' ) {
				$retval = implode( "", $list );
			}
			// Workaround for bug where the first items */# in a list would remain unparsed
			$retval = "\n" . $retval;
		}

		return $retval;
	}

	/**
	 * Wrapper function parse, call the other functions
	 * @return string|DocumentFragment the parsed output
	 */
	private function parse( string $text ) {
		if ( $this->withParsoid ) {
			$opts = [
				'processInNewFrame' => true,
				'clearDSROffets' => true,
				'parseOpts' => [ 'context' => 'inline' ]
			];
			return $this->parser->wikitextToDOM( $text, $opts, true );
		} else {
			return $this->parser->recursiveTagParse( $text, $this->frame );
		}
	}
}
