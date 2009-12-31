/**
 * JavaScript for the BucketList FieldFrame field type.
 *
 * @package		BucketList
 * @author 		Stephen Lewis (http://eepro.co.uk/)
 * @copyright 	Copyright (c) 2009, Stephen Lewis
 * @link 		http://eepro.co.uk/bucketlist/
 */

/**
 * Called when a file is selected in the file browser.
 *
 * @access	public
 * @param 	string		filename	The filename (including full path).
 * @param	object 		$target		A jQuery object containing the target anchor element.
 */
function handleFileClick(filename, $target) {
	// Make a note of the selected filename.
	$target.parents('.eepro-co-uk').find(':hidden').val(filename);
	$target.parents('.eepro-co-uk').find('.selected').removeClass('selected');
	$target.parent().addClass('selected');
}


/**
 * Gets the ball rolling.
 */
$(document).ready(function() {
	
	/**
	 * We post any AJAX requests back to the current URL, to be handled by
	 * the sessions_start hook.
	 * 
	 * The advantages of doing it this way, are two-fold:
	 * 1. We know for certain that the FieldFrame extension will be instantiated
	 * for this page, so all the FF-dependent gumpf in the BucketList fieldtype
	 * will work as expected.
	 *
	 * 2. Everything will work in the CP, and in a SAEF. Spiffing.
	 */
	
	currentURL 	= document.location.href;
	ajaxURL		= currentURL + (currentURL.indexOf('?') === false) ? '?' : '';
	ajaxURL		+= 'ajax=y&addon_id=bucketlist';
	
	$.fn.bucketlist.defaults.ajaxScriptURL 		= ajaxURL;
	$.fn.bucketlist.defaults.callbackHandler	= handleFileClick;
	
	// Initialise non-matrix file trees.
	$('.ff-ft > .eepro-co-uk > .bucketlist-ui').each(function() {
		$(this).bucketlist({initialFile : $(this).parents('.eepro-co-uk').find('input:hidden').val()});
	});
	
	if (typeof $.fn.ffMatrix != 'undefined') {
		// Initialise matrix file trees. Also handles new table cells as they are created.
		$.fn.ffMatrix.onDisplayCell.bucketlist = function($td, $ffm) {
			$('.bucketlist-ui', $td).each(function() {
				$(this).bucketlist({initialFile : $(this).parents('.eepro-co-uk').find('input:hidden').val()});
			});
		}
	}
});