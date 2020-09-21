<?php
declare( strict_types = 1 );

namespace Wikimedia\Parsoid\Core;

use DOMElement;

/**
 * Data that's necessary for selective serialization, to be passed to the
 * library entrypoint.
 */
class SelserData {

	/** @var string */
	public $oldText;

	/** @var string */
	public $oldHTML;

	/**
	 * Body of DOM document corresponding to $oldHTML
	 * @var DOMElement
	 */
	public $oldDOM;

	/**
	 * Data that's necessary to perform selective serialization.
	 *
	 * @param string $oldText
	 * @param ?string $oldHTML
	 */
	public function __construct( string $oldText, ?string $oldHTML = null ) {
		$this->oldText = $oldText;
		$this->oldHTML = $oldHTML;
	}

}
