<?php
declare( strict_types = 1 );

namespace Parsoid\Html2Wt\DOMHandlers;

use DOMElement;
use DOMNode;
use Parsoid\Html2Wt\SerializerState;
use Parsoid\Utils\WTUtils;

class LinkHandler extends DOMHandler {

	public function __construct() {
		parent::__construct( false );
	}

	/** @inheritDoc */
	public function handle(
		DOMElement $node, SerializerState $state, bool $wrapperUnmodified = false
	): ?DOMElement {
		$state->serializer->linkHandler( $node );
		return null;
	}

	/** @inheritDoc */
	public function before( DOMElement $node, DOMNode $otherNode, SerializerState $state ): array {
		// sol-transparent link nodes are the only thing on their line.
		// But, don't force separators wrt to its parent (body, p, list, td, etc.)
		if ( $otherNode !== $node->parentNode
			&& WTUtils::isSolTransparentLink( $node ) && !WTUtils::isRedirectLink( $node )
			&& !WTUtils::isEncapsulationWrapper( $node )
		) {
			return [ 'min' => 1 ];
		} else {
			return [];
		}
	}

	/** @inheritDoc */
	public function after( DOMElement $node, DOMNode $otherNode, SerializerState $state ): array {
		// sol-transparent link nodes are the only thing on their line
		// But, don't force separators wrt to its parent (body, p, list, td, etc.)
		if ( $otherNode !== $node->parentNode
			&& WTUtils::isSolTransparentLink( $node ) && !WTUtils::isRedirectLink( $node )
			&& !WTUtils::isEncapsulationWrapper( $node )
		) {
			return [ 'min' => 1 ];
		} else {
			return [];
		}
	}

}
