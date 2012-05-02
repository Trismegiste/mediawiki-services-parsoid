/**
 * ContentEditable node for an image.
 * 
 * @class
 * @constructor
 * @extends {ve.ce.LeafNode}
 * @param model {ve.dm.ImageNode} Model to observe
 */
ve.ce.ImageNode = function( model ) {
	// Inheritance
	ve.ce.LeafNode.call( this, model, $( '<img>' ) );

	// Properties
	this.currentSource = model.getAttribute( 'html/src' );

	// Events
	this.model.addListenerMethod( 'update', this, 'onUpdate' );
};

/* Static Members */

/**
 * Node rules.
 * 
 * @see ve.ce.NodeFactory
 * @static
 * @member
 */
ve.ce.ImageNode.rules = {
	'canHaveChildren': false,
	'canHaveGrandchildren': false,
	'canBeSplit': false
};

/* Methods */

/**
 * Responds to model update events.
 * 
 * If the source changed since last update the image's src attribute will be updated accordingly.
 * 
 * @method
 */
ve.ce.ImageNode.prototype.onUpdate = function() {
	var source = this.model.getAttribute( 'html/src' );
	if ( source !== this.currentSource ) {
		this.currentSource = source;
		this.$.attr( 'src', source );
	}
};

/* Registration */

ve.ce.factory.register( 'image', ve.ce.ImageNode );

/* Inheritance */

ve.extendClass( ve.ce.ImageNode, ve.ce.LeafNode );
